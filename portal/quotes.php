<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = portalClientId();
$db   = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT * FROM quotes WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$client_id]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Quotes';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Quotes</h2>

<?php if (empty($quotes)): ?>
    <div class="alert alert-info">No quotes on file.</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Quote #</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($quotes as $q): ?>
                <?php
                $status = strtolower($q['status'] ?? 'draft');
                $badge = match($status) {
                    'accepted'  => 'success',
                    'declined'  => 'danger',
                    'sent'      => 'primary',
                    'draft'     => 'secondary',
                    'expired'   => 'dark',
                    default     => 'secondary',
                };
                ?>
                <tr>
                    <td><?php echo escape($q['quote_number'] ?? '#' . $q['id']); ?></td>
                    <td><?php echo escape($q['created_at'] ?? ''); ?></td>
                    <td>$<?php echo number_format(floatval($q['amount'] ?? 0), 2); ?></td>
                    <td><span class="badge bg-<?php echo $badge; ?>"><?php echo escape(ucfirst($status)); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include '../portal/includes/footer.php'; ?>
