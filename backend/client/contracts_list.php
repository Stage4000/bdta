<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("
    SELECT co.*, c.name as client_name 
    FROM contracts co
    JOIN clients c ON co.client_id = c.id
    ORDER BY co.created_at DESC
");
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Contract Management</h2>
        <a href="contracts_create.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Create Contract
        </a>
    </div>

    <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Contract #</th>
                            <th>Client</th>
                            <th>Title</th>
                            <th>Created</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($contracts)): ?>
                            <tr><td colspan="6" class="text-center py-4">
                                <p class="text-muted">No contracts found.</p>
                            </td></tr>
                        <?php else: foreach ($contracts as $contract): ?>
                            <tr>
                                <td><strong><?= escape($contract['contract_number']) ?></strong></td>
                                <td><?= escape($contract['client_name']) ?></td>
                                <td><?= escape($contract['title']) ?></td>
                                <td><?= formatDate($contract['created_date']) ?></td>
                                <td>
                                    <?php
                                    $colors = ['draft' => 'secondary', 'sent' => 'info', 'signed' => 'success', 'expired' => 'danger'];
                                    $color = $colors[$contract['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= strtoupper($contract['status']) ?></span>
                                </td>
                                <td>
                                    <a href="contracts_view.php?id=<?= $contract['id'] ?>" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
