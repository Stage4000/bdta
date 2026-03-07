<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

// Portal content
$content = $conn->query("SELECT * FROM portal_content WHERE id = 1")->fetch(PDO::FETCH_ASSOC);

// Open invoices count
$stmt = $conn->prepare("SELECT COUNT(*) FROM invoices WHERE client_id = ? AND status NOT IN ('paid','cancelled','void')");
$stmt->execute([$client_id]);
$open_invoices = $stmt->fetchColumn();

// Upcoming appointments count
$stmt = $conn->prepare("SELECT COUNT(*) FROM bookings WHERE client_email = (SELECT email FROM clients WHERE id = ?) AND appointment_date >= CURDATE()");
$stmt->execute([$client_id]);
$upcoming_appointments = $stmt->fetchColumn();

// Total available credits across all appointment types
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(cpc.total_credits - cpc.used_credits), 0)
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    WHERE cpc.client_id = ?
      AND cp.is_active = 1
      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
");
$stmt->execute([$client_id]);
$credit_balance = intval($stmt->fetchColumn());
$pkg_credits = $credit_balance;

// Pending agreements (unsigned contracts)
$stmt = $conn->prepare("SELECT COUNT(*) FROM contracts WHERE client_id = ? AND status = 'pending'");
$stmt->execute([$client_id]);
$pending_agreements = $stmt->fetchColumn();

// Recent activity
$stmt = $conn->prepare("SELECT * FROM client_activity_log WHERE client_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$client_id]);
$recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Dashboard';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Welcome, <?php echo escape($_SESSION['portal_client_name']); ?></h2>

<?php if (!empty($content['notice_html'])): ?>
<div class="alert alert-warning mb-4">
    <?php echo $content['notice_html']; ?>
</div>
<?php endif; ?>

<?php if (!empty($content['content_html'])): ?>
<div class="mb-4">
    <?php echo $content['content_html']; ?>
</div>
<?php endif; ?>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white" style="background-color:#9a0073;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo intval($open_invoices); ?></div>
                        <div>Open Invoices</div>
                    </div>
                    <i class="fas fa-file-invoice fa-2x opacity-75"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0">
                <a href="invoices.php" class="text-white text-decoration-none small">View all &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white" style="background-color:#0a9a9c;">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo intval($upcoming_appointments); ?></div>
                        <div>Upcoming Appts</div>
                    </div>
                    <i class="fas fa-calendar-check fa-2x opacity-75"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0">
                <a href="appointments.php" class="text-white text-decoration-none small">View all &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white bg-success">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo $credit_balance; ?></div>
                        <div>Credit Balance</div>
                    </div>
                    <i class="fas fa-coins fa-2x opacity-75"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0">
                <a href="credits.php" class="text-white text-decoration-none small">View details &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card text-white bg-warning">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fs-2 fw-bold"><?php echo intval($pending_agreements); ?></div>
                        <div>Pending Agreements</div>
                    </div>
                    <i class="fas fa-file-contract fa-2x opacity-75"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0">
                <a href="agreements.php" class="text-white text-decoration-none small">View all &rarr;</a>
            </div>
        </div>
    </div>
</div>

<!-- Quick links -->
<div class="row g-3 mb-4">
    <?php
    $links = [
        ['href' => 'invoices.php',     'icon' => 'fa-file-invoice',         'label' => 'Invoices'],
        ['href' => 'appointments.php', 'icon' => 'fa-calendar-check',       'label' => 'Appointments'],
        ['href' => 'credits.php',      'icon' => 'fa-coins',                'label' => 'Credits'],
        ['href' => 'agreements.php',   'icon' => 'fa-file-contract',        'label' => 'Agreements'],
        ['href' => 'quotes.php',       'icon' => 'fa-file-invoice-dollar',  'label' => 'Quotes'],
        ['href' => 'pets.php',         'icon' => 'fa-dog',                  'label' => 'Pets'],
        ['href' => 'profile.php',      'icon' => 'fa-user',                 'label' => 'Profile'],
        ['href' => 'activity.php',     'icon' => 'fa-list-ul',              'label' => 'Activity Log'],
    ];
    foreach ($links as $link):
    ?>
    <div class="col-6 col-md-3">
        <a href="<?php echo escape($link['href']); ?>" class="card text-decoration-none text-center p-3 h-100" style="color:#9a0073; border:1px solid #dee2e6;">
            <i class="fas <?php echo escape($link['icon']); ?> fa-2x mb-2"></i>
            <div><?php echo escape($link['label']); ?></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Recent activity -->
<?php if (!empty($recent_activity)): ?>
<div class="card">
    <div class="card-header"><strong>Recent Activity</strong></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th>Date</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($recent_activity as $row): ?>
                <tr>
                    <td><?php echo escape($row['created_at']); ?></td>
                    <td><?php echo escape($row['action']); ?></td>
                    <td><?php echo escape($row['description']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-end">
        <a href="activity.php" class="small">View all activity &rarr;</a>
    </div>
</div>
<?php endif; ?>

<?php include '../portal/includes/footer.php'; ?>
