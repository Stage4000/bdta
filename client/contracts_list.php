<?php
require_once '../backend/includes/config.php';
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

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-file-circle-check me-2"></i>Contract Management</h2>
        <a href="contracts_create.php" class="btn btn-primary">
            <i class="fas fa-circle-plus"></i> Create Contract
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
                                    <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                        <a href="contracts_view.php?id=<?= $contract['id'] ?>" class="btn btn-sm btn-outline-info table-action-btn" title="View Contract" aria-label="View Contract">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                    <div class="d-md-none table-action-dropdown">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="contracts_view.php?id=<?= $contract['id'] ?>">
                                                        <i class="fas fa-eye me-2 text-info"></i>View Contract
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
