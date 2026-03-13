<?php
require_once '../backend/includes/config.php';
requirePortalLogin();

$client_id = portalClientId();
$db   = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT * FROM client_activity_log WHERE client_id = ? ORDER BY created_at DESC LIMIT 100");
$stmt->execute([$client_id]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Activity Log';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Activity Log</h2>

<?php if (empty($logs)): ?>
    <div class="alert alert-info">No activity recorded yet.</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Date / Time</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo escape($log['created_at']); ?></td>
                    <td><?php echo escape($log['action']); ?></td>
                    <td><?php echo escape($log['description']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include '../portal/includes/footer.php'; ?>
