<?php
require_once '../portal/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

// Legacy credits
$stmt = $conn->prepare("SELECT * FROM client_credits WHERE client_id = ?");
$stmt->execute([$client_id]);
$legacy = $stmt->fetch(PDO::FETCH_ASSOC);

// Package credits joined with client_packages and matched appointment type for booking
$stmt = $conn->prepare("
    SELECT cpc.*, cp.package_name, cp.purchased_at, cp.expires_at, cp.is_active as pkg_active,
           at.unique_link as appt_unique_link
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    LEFT JOIN appointment_types at ON at.name = cpc.session_type AND at.is_active = 1
    WHERE cpc.client_id = ?
    ORDER BY cp.purchased_at DESC
");
$stmt->execute([$client_id]);
$pkg_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Credits';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Credits</h2>

<!-- Legacy session credits -->
<div class="card mb-4">
    <div class="card-header"><strong>Session Credits</strong></div>
    <div class="card-body">
        <?php if ($legacy): ?>
        <div class="row g-3">
            <div class="col-sm-6 col-md-3">
                <div class="text-muted small">Balance</div>
                <div class="fs-4 fw-bold text-success"><?php echo intval($legacy['credit_balance']); ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <div class="text-muted small">Total Purchased</div>
                <div class="fs-4"><?php echo intval($legacy['total_purchased']); ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <div class="text-muted small">Total Used</div>
                <div class="fs-4"><?php echo intval($legacy['total_consumed']); ?></div>
            </div>
            <?php if ($legacy['credits_expire'] && $legacy['expiration_days']): ?>
            <div class="col-sm-6 col-md-3">
                <div class="text-muted small">Expires After</div>
                <div class="fs-4"><?php echo intval($legacy['expiration_days']); ?> days</div>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
            <p class="text-muted mb-0">No session credits on file.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Package credits -->
<div class="card">
    <div class="card-header"><strong>Package Credits</strong></div>
    <?php if (empty($pkg_credits)): ?>
    <div class="card-body"><p class="text-muted mb-0">No package credits on file.</p></div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Package</th>
                    <th>Session Type</th>
                    <th>Remaining</th>
                    <th>Total</th>
                    <th>Used</th>
                    <th>Expires</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pkg_credits as $pc): ?>
                <?php $remaining = intval($pc['total_credits']) - intval($pc['used_credits']); ?>
                <tr>
                    <td><?php echo escape($pc['package_name']); ?></td>
                    <td><?php echo escape($pc['session_type']); ?></td>
                    <td><strong class="<?php echo $remaining > 0 ? 'text-success' : 'text-muted'; ?>"><?php echo $remaining; ?></strong></td>
                    <td><?php echo intval($pc['total_credits']); ?></td>
                    <td><?php echo intval($pc['used_credits']); ?></td>
                    <td><?php echo $pc['expires_at'] ? escape($pc['expires_at']) : '&mdash;'; ?></td>
                    <td>
                        <?php if ($pc['pkg_active']): ?>
                            <span class="badge bg-success">Active</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($remaining > 0 && $pc['pkg_active'] && !empty($pc['appt_unique_link'])): ?>
                            <a href="/backend/public/book.php?link=<?php echo escape($pc['appt_unique_link']); ?>"
                               class="btn btn-sm btn-primary" target="_blank">
                                <i class="fas fa-calendar-plus me-1"></i>Book
                            </a>
                        <?php elseif ($remaining > 0 && $pc['pkg_active']): ?>
                            <a href="appointments.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-calendar-plus me-1"></i>Book
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include '../portal/includes/footer.php'; ?>
