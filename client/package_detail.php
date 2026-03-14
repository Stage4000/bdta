<?php
/**
 * Brook's Dog Training Academy - Public Package Detail & Purchase Page
 * Accessible without login via a unique share token.
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/tawk_to.php';

$db = new Database();
$conn = $db->getConnection();

$token = trim(scalar_string($_GET['token'] ?? ''));
if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) {
    http_response_code(404);
    $page_title = 'Package Not Found';
    include __DIR__ . '/../backend/includes/header.php';
    echo '<div class="container py-5 text-center"><h2>Package not found.</h2><p class="text-muted">The link you followed may be invalid or expired.</p></div>';
    include __DIR__ . '/../backend/includes/footer.php';
    exit;
}

$stmt = $conn->prepare("SELECT * FROM packages WHERE share_token = ? AND is_active = 1");
$stmt->execute([$token]);
$package = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$package) {
    http_response_code(404);
    $page_title = 'Package Not Found';
    include __DIR__ . '/../backend/includes/header.php';
    echo '<div class="container py-5 text-center"><h2>Package not found.</h2><p class="text-muted">This package may no longer be available.</p></div>';
    include __DIR__ . '/../backend/includes/footer.php';
    exit;
}

// Load package items with appointment type name
$stmt = $conn->prepare("
    SELECT pi.*, at.name AS apt_type_name
    FROM package_items pi
    JOIN appointment_types at ON pi.appointment_type_id = at.id
    WHERE pi.package_id = ?
    ORDER BY at.name
");
$stmt->execute([$package['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Record page view (analytics)
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr(scalar_string($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
$ref = substr(scalar_string($_SERVER['HTTP_REFERER'] ?? ''), 0, 512);
try {
    $conn->prepare("INSERT INTO package_link_views (package_id, ip_address, user_agent, referrer) VALUES (?, ?, ?, ?)")
         ->execute([$package['id'], $ip, $ua, $ref]);
    $view_id = $conn->lastInsertId();
} catch (PDOException $e) {
    $view_id = null;
}

$success = false;
$error   = null;
$package_price = safe_float($package['price'] ?? 0);

// Handle purchase form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purchase') {
    $buyer_name  = trim(scalar_string($_POST['buyer_name'] ?? ''));
    $buyer_email = trim(scalar_string($_POST['buyer_email'] ?? ''));
    $notes       = trim(scalar_string($_POST['notes'] ?? ''));

    if ($buyer_name === '' || $buyer_email === '') {
        $error = 'Please enter your name and email address.';
    } elseif (!filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($items)) {
        $error = 'This package has no credits configured. Please contact us.';
    } else {
        try {
            $conn->beginTransaction();

            // Find or create client by email
            $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
            $stmt->execute([$buyer_email]);
            $existing_client = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing_client) {
                $client_id = $existing_client['id'];
            } else {
                $stmt = $conn->prepare("INSERT INTO clients (name, email) VALUES (?, ?)");
                $stmt->execute([$buyer_name, $buyer_email]);
                $client_id = $conn->lastInsertId();
            }

            // Calculate expiry
            $expires_at = null;
            if ($package['expiration_days']) {
                $expires_at = date('Y-m-d H:i:s', safe_timestamp(strtotime('+' . $package['expiration_days'] . ' days')));
            }

            // Create client_packages record
            $note_text = $notes !== '' ? $notes : 'Self-serve purchase via shareable link';
            $stmt = $conn->prepare("
                INSERT INTO client_packages
                    (client_id, package_id, package_name, expires_at, is_active, notes, created_by)
                VALUES (?, ?, ?, ?, 1, ?, NULL)
            ");
            $stmt->execute([$client_id, $package['id'], $package['name'], $expires_at, $note_text]);
            $cp_id = $conn->lastInsertId();

            // Create per-type credit rows
            $credit_stmt = $conn->prepare("
                INSERT INTO client_package_credits
                    (client_package_id, client_id, appointment_type_id, total_credits, used_credits)
                VALUES (?, ?, ?, ?, 0)
            ");
            foreach ($items as $item) {
                $credit_stmt->execute([$cp_id, $client_id, $item['appointment_type_id'], $item['quantity']]);
            }

            // Log transactions
            $cred_stmt = $conn->prepare("SELECT * FROM client_package_credits WHERE client_package_id = ?");
            $cred_stmt->execute([$cp_id]);
            $tx_stmt = $conn->prepare("
                INSERT INTO package_credit_transactions
                    (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, notes, created_by)
                VALUES (?, ?, ?, 'purchase', ?, ?, NULL)
            ");
            foreach ($cred_stmt->fetchAll(PDO::FETCH_ASSOC) as $cred) {
                $tx_stmt->execute([
                    $cred['id'], $client_id, $cred['appointment_type_id'],
                    $cred['total_credits'],
                    "Package '{$package['name']}' purchased via shareable link",
                ]);
            }

            // Mark this view as a purchase in analytics
            if ($view_id) {
                $conn->prepare("UPDATE package_link_views SET purchased=1, client_id=? WHERE id=?")
                     ->execute([$client_id, $view_id]);
            }

            $conn->commit();
            $success = true;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = 'An error occurred while processing your purchase. Please try again or contact us.';
        }
    }
}

$page_title = htmlspecialchars($package['name']) . ' – Package Details';
// Don't include the admin sidebar header for public pages; build a minimal one
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($package['name']) ?> – Brook's Dog Training Academy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: #f8f9fa; }
        .brand-purple { color: #9a0073; }
        .btn-brand {
            background-color: #9a0073;
            border-color: #9a0073;
            color: #fff;
        }
        .btn-brand:hover {
            background-color: #7a005a;
            border-color: #7a005a;
            color: #fff;
        }
        .hero-banner {
            background: linear-gradient(135deg, #9a0073 0%, #7a005a 100%);
            color: #fff;
            padding: 2.5rem 0 2rem;
        }
        .credit-card {
            border: 2px solid #dee2e6;
            border-radius: 0.75rem;
            padding: 1.25rem;
            text-align: center;
            transition: border-color 0.2s;
        }
        .credit-card:hover { border-color: #9a0073; }
    </style>
</head>
<body>

<!-- Hero Banner -->
<div class="hero-banner">
    <div class="container">
        <div class="d-flex align-items-center gap-3 mb-2">
            <i class="fas fa-dog fa-2x opacity-75"></i>
            <span class="fs-5 fw-semibold opacity-75">Brook's Dog Training Academy</span>
        </div>
        <h1 class="display-5 fw-bold mb-1"><?= htmlspecialchars($package['name']) ?></h1>
        <?php if ($package['description']): ?>
            <p class="lead opacity-90 mb-0"><?= htmlspecialchars($package['description']) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="container py-5">
    <?php if ($success): ?>
        <!-- Purchase success -->
        <div class="row justify-content-center">
            <div class="col-md-7">
                <div class="card border-success shadow-sm">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-circle-check fa-4x text-success mb-3"></i>
                        <h3 class="text-success">Purchase Confirmed!</h3>
                        <p class="text-muted">Thank you! Your credits for <strong><?= htmlspecialchars($package['name']) ?></strong> have been issued. We'll be in touch shortly.</p>
                        <hr>
                        <h6 class="mb-3">Credits Issued:</h6>
                        <div class="row g-2 justify-content-center">
                            <?php foreach ($items as $item): ?>
                            <div class="col-6 col-md-4">
                                <div class="credit-card">
                                    <i class="fas fa-calendar-check fa-lg text-muted mb-1"></i>
                                    <div class="fs-3 fw-bold brand-purple"><?= $item['quantity'] ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($item['apt_type_name']) ?></small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <!-- Package details -->
            <div class="col-lg-7">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0"><i class="fas fa-box-open me-2 brand-purple"></i>What's Included</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($items)): ?>
                            <p class="text-muted">No items configured for this package.</p>
                        <?php else: ?>
                            <div class="row g-3">
                                <?php foreach ($items as $item): ?>
                                <div class="col-6 col-md-4">
                                    <div class="credit-card">
                                        <i class="fas fa-calendar-check fa-2x text-muted mb-2"></i>
                                        <div class="fs-2 fw-bold brand-purple"><?= $item['quantity'] ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($item['apt_type_name']) ?></div>
                                        <span class="badge bg-primary mt-1"><?= $item['quantity'] ?> credit<?= $item['quantity'] != 1 ? 's' : '' ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="row g-3 text-center">
                            <div class="col-6">
                                <div class="fs-4 fw-bold brand-purple">
                                    <?= $package_price > 0 ? '$' . number_format($package_price, 2) : 'Contact Us' ?>
                                </div>
                                <small class="text-muted">Package Price</small>
                            </div>
                            <div class="col-6">
                                <div class="fs-4 fw-bold text-secondary">
                                    <?= $package['expiration_days'] ? $package['expiration_days'] . ' days' : 'Never' ?>
                                </div>
                                <small class="text-muted">Credits Expire</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Purchase form -->
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0"><i class="fas fa-shopping-cart me-2 brand-purple"></i>Purchase This Package</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <form method="POST" novalidate>
                            <input type="hidden" name="action" value="purchase">

                            <div class="mb-3">
                                <label for="buyer_name" class="form-label">Your Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="buyer_name" name="buyer_name"
                                       value="<?= htmlspecialchars(scalar_string($_POST['buyer_name'] ?? '')) ?>"
                                       placeholder="Jane Smith" required>
                            </div>

                            <div class="mb-3">
                                <label for="buyer_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="buyer_email" name="buyer_email"
                                       value="<?= htmlspecialchars(scalar_string($_POST['buyer_email'] ?? '')) ?>"
                                       placeholder="you@example.com" required>
                                <div class="form-text">We'll use this to look up or create your account.</div>
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes <small class="text-muted">(optional)</small></label>
                                <textarea class="form-control" id="notes" name="notes" rows="2"
                                           placeholder="Any questions or special requests?"><?= htmlspecialchars(scalar_string($_POST['notes'] ?? '')) ?></textarea>
                            </div>

                            <div class="alert alert-info py-2 small">
                                <i class="fas fa-info-circle me-1"></i>
                                On purchase, the following credits will be issued to your account:
                                <ul class="mb-0 mt-1">
                                    <?php foreach ($items as $item): ?>
                                    <li><strong><?= $item['quantity'] ?>× <?= htmlspecialchars($item['apt_type_name']) ?></strong> credit<?= $item['quantity'] != 1 ? 's' : '' ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-brand btn-lg">
                                    <i class="fas fa-check-circle me-2"></i>
                                    Purchase<?= $package_price > 0 ? ' – $' . number_format($package_price, 2) : '' ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<footer class="py-4 text-center text-muted small border-top mt-5">
    <div class="container">
        <span>Brook's Dog Training Academy</span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php bdta_render_tawk_to_widget(); ?>
</body>
</html>
