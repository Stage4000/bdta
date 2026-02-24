<?php
require_once '../portal/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT * FROM invoices WHERE client_id = ? ORDER BY issue_date DESC");
$stmt->execute([$client_id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Invoices';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Invoices</h2>

<?php if (empty($invoices)): ?>
    <div class="alert alert-info">You have no invoices on file.</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th>Date</th>
                    <th>Due Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($invoices as $inv): ?>
                <?php
                $status = strtolower($inv['status'] ?? 'draft');
                $badge = match($status) {
                    'paid'       => 'success',
                    'overdue'    => 'danger',
                    'sent'       => 'primary',
                    'draft'      => 'secondary',
                    'cancelled'  => 'dark',
                    'void'       => 'dark',
                    default      => 'secondary',
                };
                ?>
                <tr>
                    <td><?php echo escape($inv['invoice_number'] ?? '#' . $inv['id']); ?></td>
                    <td><?php echo escape($inv['issue_date'] ?? ''); ?></td>
                    <td><?php echo escape($inv['due_date'] ?? ''); ?></td>
                    <td>$<?php echo number_format(floatval($inv['total_amount'] ?? 0), 2); ?></td>
                    <td><span class="badge bg-<?php echo $badge; ?>"><?php echo escape(ucfirst($status)); ?></span></td>
                    <td><a href="invoice_view.php?id=<?php echo intval($inv['id']); ?>" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include '../portal/includes/footer.php'; ?>
