<?php
require_once '../portal/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

// Contracts
$stmt = $conn->prepare("
    SELECT c.*, ct.name as template_name
    FROM contracts c
    LEFT JOIN contract_templates ct ON c.template_id = ct.id
    WHERE c.client_id = ?
    ORDER BY c.created_at DESC
");
$stmt->execute([$client_id]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Form submissions
$stmt = $conn->prepare("
    SELECT fs.*, ft.title as form_title
    FROM form_submissions fs
    LEFT JOIN form_templates ft ON fs.template_id = ft.id
    WHERE fs.client_id = ?
    ORDER BY fs.created_at DESC
");
$stmt->execute([$client_id]);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Agreements';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Agreements &amp; Forms</h2>

<!-- Contracts -->
<div class="card mb-4">
    <div class="card-header"><strong>Contracts</strong></div>
    <?php if (empty($contracts)): ?>
    <div class="card-body"><p class="text-muted mb-0">No contracts on file.</p></div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Name</th><th>Template</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($contracts as $c): ?>
                <?php
                $status = strtolower($c['status'] ?? 'pending');
                $badge = match($status) {
                    'signed'    => 'success',
                    'pending'   => 'warning',
                    'cancelled' => 'dark',
                    default     => 'secondary',
                };
                ?>
                <tr>
                    <td><?php echo escape($c['name'] ?? ''); ?></td>
                    <td><?php echo escape($c['template_name'] ?? ''); ?></td>
                    <td><span class="badge bg-<?php echo $badge; ?>"><?php echo escape(ucfirst($status)); ?></span></td>
                    <td><?php echo escape($c['created_at'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Form submissions -->
<div class="card">
    <div class="card-header"><strong>Form Submissions</strong></div>
    <?php if (empty($submissions)): ?>
    <div class="card-body"><p class="text-muted mb-0">No form submissions on file.</p></div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead><tr><th>Form</th><th>Submitted</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($submissions as $fs): ?>
                <tr>
                    <td><?php echo escape($fs['form_title'] ?? 'Unknown Form'); ?></td>
                    <td><?php echo escape($fs['created_at'] ?? ''); ?></td>
                    <td><?php echo escape($fs['status'] ?? ''); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include '../portal/includes/footer.php'; ?>
