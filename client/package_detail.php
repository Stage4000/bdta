<?php
/**
 * Brook's Dog Training Academy - Public Package Detail & Purchase Page
 * Accessible without login via a unique share token.
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/package_contracts.php';
require_once __DIR__ . '/../backend/includes/package_checkout.php';
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
$package_contracts = bdta_get_package_contract_summary($conn, array_int_value($package, 'id'));
$attached_form = bdta_get_package_attached_form($conn, safe_int($package['form_template_id'] ?? 0));
$attached_form_posted_values = [];

// Record page view (analytics)
$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr(scalar_string($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);
$ref = substr(scalar_string($_SERVER['HTTP_REFERER'] ?? ''), 0, 512);
try {
    $conn->prepare("INSERT INTO package_link_views (package_id, ip_address, user_agent, referrer) VALUES (?, ?, ?, ?)")
         ->execute([$package['id'], $ip, $ua, $ref]);
    $last_insert_id = $conn->lastInsertId();
    $view_id = $last_insert_id === false ? null : safe_int($last_insert_id);
} catch (PDOException $e) {
    $view_id = null;
}

$success = false;
$error   = null;
$info_message = null;
$package_price = safe_float($package['price'] ?? 0);
$payment_required = $package_price > 0;
$session_id = trim(scalar_string($_GET['session_id'] ?? ''));
$purchase_status = trim(scalar_string($_GET['purchase'] ?? ''));

if (!isset($_SESSION['pending_package_purchases']) || !is_array($_SESSION['pending_package_purchases'])) {
    $_SESSION['pending_package_purchases'] = [];
}
if (!isset($_SESSION['package_purchase_success']) || !is_array($_SESSION['package_purchase_success'])) {
    $_SESSION['package_purchase_success'] = [];
}

/** @var array<string, mixed>|null $pending_purchase_prefill */
$pending_purchase_prefill = $_SESSION['pending_package_purchases'][$token] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && is_array($pending_purchase_prefill) && is_array($pending_purchase_prefill['form_responses'] ?? null)) {
    $attached_form_posted_values = $pending_purchase_prefill['form_responses'];
}

if ($purchase_status === 'success' && !empty($_SESSION['package_purchase_success'][$token])) {
    $success = true;
    unset($_SESSION['package_purchase_success'][$token]);
}

if (!$success && $session_id !== '') {
    $existing_purchase_stmt = $conn->prepare("
        SELECT id
        FROM client_packages
        WHERE package_id = ? AND stripe_checkout_session_id = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $existing_purchase_stmt->execute([$package['id'], $session_id]);
    $existing_purchase = $existing_purchase_stmt->fetch(PDO::FETCH_ASSOC);

    if (is_array($existing_purchase)) {
        $_SESSION['package_purchase_success'][$token] = 1;
        header('Location: package_detail.php?token=' . urlencode($token) . '&purchase=success');
        exit;
    }

    /** @var array<string, mixed>|null $pending_purchase */
    $pending_purchase = $_SESSION['pending_package_purchases'][$token] ?? null;
    if (!is_array($pending_purchase) || safe_int($pending_purchase['package_id'] ?? 0) !== safe_int($package['id'] ?? 0)) {
        $error = 'We could not recover your checkout details to finish this purchase. Please try again or contact us if your card was charged.';
    } else {
        require_once __DIR__ . '/../backend/includes/stripe_config.php';

        if (!isStripeEnabled()) {
            $error = 'Online payments are not currently available. Please contact us to complete this purchase.';
        } else {
            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($session_id));
            if ($ch === false) {
                error_log('Package Stripe session retrieval curl_init failed');
                $error = 'Could not verify your payment. If you were charged, please contact us.';
            } else {
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD => scalar_string(STRIPE_SECRET_KEY) . ':',
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30,
                ]);
                $response = curl_exec($ch);
                if ($response === false) {
                    $curl_error = curl_error($ch);
                    curl_close($ch);
                    error_log("Package Stripe session retrieval curl failed: $curl_error");
                    $error = 'Could not verify your payment. If you were charged, please contact us.';
                } else {
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $session = decode_json_assoc(scalar_string($response));

                    if ($http_code !== 200 || array_string_value($session, 'id') === '') {
                        error_log("Package Stripe session retrieval failed for session $session_id (HTTP $http_code)");
                        $error = 'Could not verify your payment. If you were charged, please contact us.';
                    } elseif (array_string_value($session, 'payment_status') !== 'paid') {
                        $info_message = 'Payment was not completed. You can review the package details and try again below.';
                    } elseif (safe_int($session['amount_total'] ?? 0) !== (int) round($package_price * 100)) {
                        $error = 'The payment amount did not match this package. Please contact us if you were charged.';
                    } elseif (safe_int(is_array($session['metadata'] ?? null) ? $session['metadata']['package_id'] ?? 0 : 0) !== safe_int($package['id'] ?? 0)) {
                        $error = 'The payment confirmation did not match this package. Please contact us if you were charged.';
                    } else {
                        try {
                            bdta_finalize_package_purchase(
                                $conn,
                                $package,
                                $items,
                                scalar_string($pending_purchase['buyer_name'] ?? ''),
                                scalar_string($pending_purchase['buyer_email'] ?? ''),
                                scalar_string($pending_purchase['buyer_phone'] ?? ''),
                                scalar_string($pending_purchase['notes'] ?? ''),
                                $attached_form,
                                is_array($pending_purchase['form_responses'] ?? null) ? $pending_purchase['form_responses'] : [],
                                safe_int($pending_purchase['view_id'] ?? 0),
                                'credit_card',
                                $session_id
                            );
                            unset($_SESSION['pending_package_purchases'][$token]);
                            $_SESSION['package_purchase_success'][$token] = 1;
                            header('Location: package_detail.php?token=' . urlencode($token) . '&purchase=success');
                            exit;
                        } catch (Throwable $e) {
                            error_log('Package purchase finalization failed for token ' . $token . ': ' . $e->getMessage());
                            $error = 'Your payment was received, but we could not finish issuing the package automatically. Please contact us so we can help right away.';
                        }
                    }
                }
            }
        }
    }
}

// Handle purchase form submission
if (!$success && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'purchase') {
    $submitted_csrf_token = scalar_string($_POST['csrf_token'] ?? '');
    $buyer_name  = trim(scalar_string($_POST['buyer_name'] ?? ''));
    $buyer_email = trim(scalar_string($_POST['buyer_email'] ?? ''));
    $buyer_phone = trim(scalar_string($_POST['buyer_phone'] ?? ''));
    $notes       = trim(scalar_string($_POST['notes'] ?? ''));
    $attached_form_id = safe_int($attached_form['id'] ?? 0);
    $posted_package_form_values = $_POST['package_form'][$attached_form_id] ?? null;
    $attached_form_posted_values = is_array($posted_package_form_values)
        ? $posted_package_form_values
        : [];
    $form_validation = bdta_validate_package_form_submission($attached_form, $attached_form_posted_values);

    if (!hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), $submitted_csrf_token)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif ($buyer_name === '' || $buyer_email === '') {
        $error = 'Please enter your name and email address.';
    } elseif (!filter_var($buyer_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($items)) {
        $error = 'This package has no credits configured. Please contact us.';
    } elseif (!bdta_package_purchase_acknowledged($_POST, $package_contracts)) {
        $error = 'Please review and acknowledge the required contract terms before purchasing this package.';
    } elseif (!empty($form_validation['errors'])) {
        $error = implode(' ', $form_validation['errors']);
    } else {
        if ($payment_required) {
            require_once __DIR__ . '/../backend/includes/stripe_config.php';

            if (!isStripeEnabled()) {
                $error = 'Online payments are not currently available. Please contact us to complete this purchase.';
            } else {
                $amount_cents = (int) round($package_price * 100, 0);
                if ($amount_cents < 50) {
                    $error = 'This package amount is too low for online card checkout. Please contact us to complete the purchase.';
                } else {
                    $_SESSION['pending_package_purchases'][$token] = [
                        'package_id' => safe_int($package['id'] ?? 0),
                        'buyer_name' => $buyer_name,
                        'buyer_email' => strtolower($buyer_email),
                        'buyer_phone' => $buyer_phone,
                        'notes' => $notes,
                        'form_responses' => $form_validation['responses'],
                        'view_id' => $view_id,
                    ];

                    $base_url = getDynamicBaseUrl();
                    $success_url = $base_url . '/client/package_detail.php?token=' . urlencode($token) . '&session_id={CHECKOUT_SESSION_ID}';
                    $cancel_url = $base_url . '/client/package_detail.php?token=' . urlencode($token);

                    $post_data = http_build_query([
                        'mode' => 'payment',
                        'success_url' => $success_url,
                        'cancel_url' => $cancel_url,
                        'customer_email' => strtolower($buyer_email),
                        'line_items[0][quantity]' => 1,
                        'line_items[0][price_data][currency]' => STRIPE_CURRENCY,
                        'line_items[0][price_data][unit_amount]' => $amount_cents,
                        'line_items[0][price_data][product_data][name]' => scalar_string($package['name'] ?? 'Package Purchase'),
                        'line_items[0][price_data][product_data][description]' => 'Package purchase for ' . scalar_string($package['name'] ?? ''),
                        'metadata[package_id]' => safe_int($package['id'] ?? 0),
                        'metadata[package_token]' => $token,
                        'payment_intent_data[metadata][package_id]' => safe_int($package['id'] ?? 0),
                        'payment_intent_data[metadata][package_token]' => $token,
                        'payment_intent_data[description]' => scalar_string($package['name'] ?? 'Package Purchase') . ' — ' . $buyer_name,
                    ]);

                    $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
                    if ($ch === false) {
                        error_log('Package Stripe checkout session creation curl_init failed');
                        $error = 'Could not initiate online payment. Please try again or contact us.';
                    } else {
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_POST => true,
                            CURLOPT_POSTFIELDS => $post_data,
                            CURLOPT_USERPWD => scalar_string(STRIPE_SECRET_KEY) . ':',
                            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                            CURLOPT_CONNECTTIMEOUT => 10,
                            CURLOPT_TIMEOUT => 30,
                        ]);
                        $response = curl_exec($ch);
                        if ($response === false) {
                            $curl_error = curl_error($ch);
                            curl_close($ch);
                            error_log("Package Stripe checkout session creation failed (curl): $curl_error");
                            $error = 'Could not initiate online payment. Please try again or contact us.';
                        } else {
                            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            $session = decode_json_assoc(scalar_string($response));
                            if ($http_code !== 200 || array_string_value($session, 'url') === '') {
                                $session_error = is_array($session['error'] ?? null) ? $session['error'] : [];
                                error_log(
                                    'Package Stripe Checkout Session creation failed [' .
                                    array_string_value($session_error, 'type', 'unknown') . '/' .
                                    array_string_value($session_error, 'code') . ']: ' .
                                    array_string_value($session_error, 'message', 'Unknown error') .
                                    " (HTTP $http_code)"
                                );
                                $error = 'Could not initiate online payment. Please try again or contact us.';
                            } else {
                                header('Location: ' . array_string_value($session, 'url'));
                                exit;
                            }
                        }
                    }
                }
            }
        } else {
            try {
                bdta_finalize_package_purchase(
                    $conn,
                    $package,
                    $items,
                    $buyer_name,
                    $buyer_email,
                    $buyer_phone,
                    $notes,
                    $attached_form,
                    $form_validation['responses'],
                    $view_id !== null ? safe_int($view_id) : null,
                    'offline'
                );
                $_SESSION['package_purchase_success'][$token] = 1;
                header('Location: package_detail.php?token=' . urlencode($token) . '&purchase=success');
                exit;
            } catch (Throwable $e) {
                error_log('Package purchase failed: ' . $e->getMessage());
                $error = 'An error occurred while processing your purchase. Please try again or contact us.';
            }
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
                        <p class="text-muted mb-2">Thank you! Your credits for <strong><?= htmlspecialchars($package['name']) ?></strong> have been issued.</p>
                        <p class="small text-muted mb-4">To use them, sign in to the client portal and book the appointment types included in this package. If you do not have a portal password yet, use the Forgot Password option with the same email address you purchased with to get started.</p>
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
                        <div class="d-flex flex-column flex-sm-row justify-content-center gap-2 mt-4">
                            <a href="<?= htmlspecialchars(getDynamicBaseUrl() . '/portal/login.php') ?>" class="btn btn-brand">
                                <i class="fas fa-right-to-bracket me-2"></i>Go to Client Portal
                            </a>
                            <a href="<?= htmlspecialchars(getDynamicBaseUrl()) ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-house me-2"></i>Return to Main Website
                            </a>
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

                <?php if (!empty($package_contracts)): ?>
                <div class="card shadow-sm mt-4 border-warning">
                    <div class="card-header bg-warning-subtle border-bottom border-warning-subtle">
                        <h5 class="mb-0"><i class="fas fa-file-signature me-2 text-warning-emphasis"></i>Contracts Required Before Booking</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning mb-4">
                            Review these terms before purchase. You will still be asked to accept the applicable contract when you later book the covered appointment type.
                        </div>
                        <?php foreach ($package_contracts as $contract): ?>
                            <div class="border rounded p-3 mb-3">
                                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start mb-3">
                                    <div>
                                        <h6 class="mb-1"><?= escape($contract['name']) ?></h6>
                                        <div class="small text-muted">
                                            Applies to:
                                            <?php foreach ($contract['appointment_types'] as $appointment_type_name): ?>
                                                <span class="badge text-bg-light border me-1"><?= escape($appointment_type_name) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <span class="badge text-bg-warning">Renews every <?= (int)$contract['renewal_period_months'] ?> month<?= (int)$contract['renewal_period_months'] === 1 ? '' : 's' ?></span>
                                </div>
                                <div class="border rounded p-3 bg-white" style="max-height: 220px; overflow-y: auto; font-size: 0.9rem;"><?= $contract['template_text'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Purchase form -->
            <div class="col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-header bg-white border-bottom">
                        <h5 class="mb-0"><i class="fas fa-shopping-cart me-2 brand-purple"></i>Purchase This Package</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($info_message): ?>
                            <div class="alert alert-warning"><?= htmlspecialchars($info_message) ?></div>
                        <?php endif; ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                        <?php endif; ?>
                        <form method="POST" novalidate>
                            <input type="hidden" name="action" value="purchase">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? '')) ?>">

                            <div class="mb-3">
                                <label for="buyer_name" class="form-label">Your Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="buyer_name" name="buyer_name"
                                       value="<?= htmlspecialchars(scalar_string($_POST['buyer_name'] ?? ($pending_purchase_prefill['buyer_name'] ?? ''))) ?>"
                                       placeholder="Jane Smith" required>
                            </div>

                            <div class="mb-3">
                                <label for="buyer_email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="buyer_email" name="buyer_email"
                                       value="<?= htmlspecialchars(scalar_string($_POST['buyer_email'] ?? ($pending_purchase_prefill['buyer_email'] ?? ''))) ?>"
                                       placeholder="you@example.com" required>
                                <div class="form-text">We'll use this to look up or create your account.</div>
                            </div>

                            <div class="mb-3">
                                <label for="buyer_phone" class="form-label">Phone Number</label>
                                <input type="tel" class="form-control" id="buyer_phone" name="buyer_phone"
                                       value="<?= htmlspecialchars(scalar_string($_POST['buyer_phone'] ?? ($pending_purchase_prefill['buyer_phone'] ?? ''))) ?>"
                                       placeholder="(555) 123-4567">
                            </div>

                            <div class="mb-3">
                                <label for="notes" class="form-label">Notes <small class="text-muted">(optional)</small></label>
                                <textarea class="form-control" id="notes" name="notes" rows="2"
                                            placeholder="Any questions or special requests?"><?= htmlspecialchars(scalar_string($_POST['notes'] ?? ($pending_purchase_prefill['notes'] ?? ''))) ?></textarea>
                            </div>

                            <div class="alert alert-info py-2 small">
                                <i class="fas fa-info-circle me-1"></i>
                                On purchase, the following credits will be issued to your account:
                                 <ul class="mb-0 mt-1">
                                     <?php foreach ($items as $item): ?>
                                     <li><strong><?= $item['quantity'] ?>× <?= htmlspecialchars($item['apt_type_name']) ?></strong> credit<?= $item['quantity'] != 1 ? 's' : '' ?></li>
                                     <?php endforeach; ?>
                                 </ul>
                                 <?php if ($payment_required): ?>
                                     <div class="mt-2">A secure card payment is required to complete this purchase.</div>
                                 <?php endif; ?>
                             </div>

                            <?php if ($attached_form): ?>
                            <?php $attached_form_id = safe_int($attached_form['id'] ?? 0); ?>
                            <?php $attached_form_fields = bdta_package_checkout_fields($attached_form['fields'] ?? []); ?>
                            <div class="border rounded p-3 mb-3">
                                <h6 class="mb-1"><i class="fas fa-file-lines me-2 brand-purple"></i><?= escape(array_string_value($attached_form, 'name')) ?></h6>
                                <?php if (array_string_value($attached_form, 'description') !== ''): ?>
                                    <p class="text-muted small mb-3"><?= escape(array_string_value($attached_form, 'description')) ?></p>
                                <?php else: ?>
                                    <p class="text-muted small mb-3">Please complete this form as part of your checkout.</p>
                                <?php endif; ?>
                                <?php foreach ($attached_form_fields as $field_index => $field): ?>
                                    <?php
                                    $field_label = array_string_value($field, 'label', 'Field ' . ($field_index + 1));
                                    $field_description = array_string_value($field, 'description');
                                    $field_type = array_string_value($field, 'type', 'text');
                                    $field_options = is_array($field['options'] ?? null) ? $field['options'] : [];
                                    $field_placeholder = array_string_value($field, 'placeholder');
                                    $field_required = !empty($field['required']);
                                    $posted_field_values = [];
                                    if (is_array($_POST['package_form'] ?? null) && is_array($_POST['package_form'][$attached_form_id] ?? null)) {
                                        $posted_field_values = $_POST['package_form'][$attached_form_id];
                                    }
                                    $field_value = $attached_form_posted_values[$field_index] ?? ($posted_field_values[$field_index] ?? null);
                                    ?>
                                    <div class="mb-3">
                                        <label class="form-label">
                                            <?= escape($field_label) ?>
                                            <?php if ($field_required): ?><span class="text-danger">*</span><?php endif; ?>
                                        </label>
                                        <?php if ($field_description !== ''): ?>
                                            <div class="form-text text-muted mb-1"><?= escape($field_description) ?></div>
                                        <?php endif; ?>
                                        <?php if ($field_type === 'textarea'): ?>
                                            <textarea class="form-control"
                                                      name="package_form[<?= $attached_form_id ?>][<?= $field_index ?>]"
                                                      placeholder="<?= escape($field_placeholder) ?>"
                                                      <?= $field_required ? 'required' : '' ?>><?= htmlspecialchars(is_scalar($field_value) ? scalar_string($field_value) : '') ?></textarea>
                                        <?php elseif ($field_type === 'select'): ?>
                                            <select class="form-select"
                                                    name="package_form[<?= $attached_form_id ?>][<?= $field_index ?>]"
                                                    <?= $field_required ? 'required' : '' ?>>
                                                <option value="">— Select —</option>
                                                <?php foreach ($field_options as $option): ?>
                                                    <?php $option_value = scalar_string($option); ?>
                                                    <option value="<?= escape($option_value) ?>" <?= scalar_string($field_value ?? '') === $option_value ? 'selected' : '' ?>>
                                                        <?= escape($option_value) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($field_type === 'radio'): ?>
                                            <?php foreach ($field_options as $option_index => $option): ?>
                                                <?php $option_value = scalar_string($option); ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio"
                                                           id="package_form_<?= $attached_form_id ?>_<?= $field_index ?>_<?= $option_index ?>"
                                                           name="package_form[<?= $attached_form_id ?>][<?= $field_index ?>]"
                                                           value="<?= escape($option_value) ?>"
                                                           <?= scalar_string($field_value ?? '') === $option_value ? 'checked' : '' ?>
                                                           <?= $field_required && $option_index === 0 ? 'required' : '' ?>>
                                                    <label class="form-check-label" for="package_form_<?= $attached_form_id ?>_<?= $field_index ?>_<?= $option_index ?>">
                                                        <?= escape($option_value) ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php elseif ($field_type === 'checkbox'): ?>
                                            <?php $selected_values = is_array($field_value) ? array_map('scalar_string', $field_value) : []; ?>
                                            <?php foreach ($field_options as $option_index => $option): ?>
                                                <?php $option_value = scalar_string($option); ?>
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox"
                                                           id="package_form_<?= $attached_form_id ?>_<?= $field_index ?>_<?= $option_index ?>"
                                                           name="package_form[<?= $attached_form_id ?>][<?= $field_index ?>][]"
                                                           value="<?= escape($option_value) ?>"
                                                           <?= in_array($option_value, $selected_values, true) ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="package_form_<?= $attached_form_id ?>_<?= $field_index ?>_<?= $option_index ?>">
                                                        <?= escape($option_value) ?>
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <input type="<?= escape($field_type) ?>"
                                                   class="form-control"
                                                   name="package_form[<?= $attached_form_id ?>][<?= $field_index ?>]"
                                                   value="<?= htmlspecialchars(is_scalar($field_value) ? scalar_string($field_value) : '') ?>"
                                                   placeholder="<?= escape($field_placeholder) ?>"
                                                   <?= $field_required ? 'required' : '' ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($package_contracts)): ?>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox"
                                       name="contract_disclosure_acknowledged" id="contractDisclosureAcknowledged"
                                       value="1" <?= !empty($_POST['contract_disclosure_acknowledged']) ? 'checked' : '' ?> required>
                                <label class="form-check-label small" for="contractDisclosureAcknowledged">
                                    I have reviewed the package contract terms shown on this page and understand that the listed appointment types require those terms to be accepted before booking.
                                </label>
                            </div>
                            <?php endif; ?>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-brand btn-lg">
                                    <i class="fas <?= $payment_required ? 'fa-credit-card' : 'fa-check-circle' ?> me-2"></i>
                                    <?= $payment_required ? 'Continue to Payment' : 'Purchase' ?><?= $package_price > 0 ? ' – $' . number_format($package_price, 2) : '' ?>
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
