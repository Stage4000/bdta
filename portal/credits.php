<?php
require_once '../portal/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

// Package credits with appointment type name
$stmt = $conn->prepare("
    SELECT cpc.*, cp.package_name, cp.purchased_at, cp.expires_at, cp.is_active as pkg_active,
           at.id as appt_type_id, at.name as apt_type_name, at.unique_link as appt_unique_link
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    LEFT JOIN appointment_types at ON at.id = cpc.appointment_type_id AND at.is_active = 1
    WHERE cpc.client_id = ?
    ORDER BY cp.purchased_at DESC
");
$stmt->execute([$client_id]);
$pkg_credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Credits';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Credits</h2>

<!-- Package credits -->
<div class="card">
    <div class="card-header"><strong>Credits by Appointment Type</strong></div>
    <?php if (empty($pkg_credits)): ?>
    <div class="card-body"><p class="text-muted mb-0">No credits on file.</p></div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Package</th>
                    <th>Appointment Type</th>
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
                    <td><?php echo escape($pc['apt_type_name'] ?? '—'); ?></td>
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
                        <?php
                        if ($remaining > 0 && $pc['pkg_active']):
                            // Build portal booking URL (uses credit-aware flow)
                            if (!empty($pc['appt_unique_link'])) {
                                $book_url = '/portal/book_credit.php?link=' . urlencode($pc['appt_unique_link']);
                            } elseif (!empty($pc['appt_type_id'])) {
                                $book_url = '/portal/book_credit.php?type=' . intval($pc['appt_type_id']);
                            } else {
                                $book_url = null;
                            }
                        ?>
                            <?php if ($book_url): ?>
                                <a href="<?php echo escape($book_url); ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-calendar-plus me-1"></i>Book
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">Contact us to book</span>
                            <?php endif; ?>
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
