<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();
$can_modify_accounting = !bdta_session_admin_is_accountant($_SESSION);

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!$can_modify_accounting) {
        setFlashMessage('Your accountant account has read-only expense access.', 'danger');
        redirect('expenses_list.php');
    }
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'danger');
        redirect('expenses_list.php');
    }
    $id = safe_int($_POST['delete_id']);
    $stmt = $conn->prepare("DELETE FROM expenses WHERE id = ?");
    $stmt->execute([$id]);
    setFlashMessage('Expense deleted successfully!', 'success');
    redirect('expenses_list.php');
}

// Fetch expenses
$stmt = $conn->query("
    SELECT e.*, c.name as client_name 
    FROM expenses e
    LEFT JOIN clients c ON e.client_id = c.id
    ORDER BY e.expense_date DESC
");
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total = array_sum(array_column($expenses, 'amount'));
$billable = array_sum(array_filter(array_map(function($e) { 
    return $e['billable'] ? $e['amount'] : 0; 
}, $expenses)));

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-receipt me-2"></i>Expense Tracking</h2>
        <?php if ($can_modify_accounting): ?>
            <a href="expenses_edit.php" class="btn btn-primary">
                <i class="fas fa-circle-plus"></i> Add Expense
            </a>
        <?php endif; ?>
    </div>

    <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card text-white bg-danger">
                <div class="card-body">
                    <h6 class="card-title">Total Expenses</h6>
                    <h3>$<?= number_format($total, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card text-white bg-warning">
                <div class="card-body">
                    <h6 class="card-title">Billable Expenses</h6>
                    <h3>$<?= number_format($billable, 2) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expenses)): ?>
                            <tr><td colspan="7" class="text-center py-4">
                                <p class="text-muted">No expenses found.</p>
                            </td></tr>
                        <?php else: foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?= formatDate($expense['expense_date']) ?></td>
                                <td><?= escape($expense['category']) ?></td>
                                <td><?= escape($expense['description']) ?></td>
                                <td><?= escape($expense['client_name'] ?? 'General') ?></td>
                                <td><strong>$<?= number_format(safe_float($expense['amount'] ?? 0), 2) ?></strong></td>
                                <td>
                                    <?php if ($expense['invoiced']): ?>
                                        <span class="badge bg-secondary">Invoiced</span>
                                    <?php elseif ($expense['billable']): ?>
                                        <span class="badge bg-success">Billable</span>
                                    <?php else: ?>
                                        <span class="badge bg-info">Non-Billable</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($can_modify_accounting): ?>
                                        <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                            <a href="expenses_edit.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-outline-primary table-action-btn" title="Edit">
                                                <i class="fas fa-pencil"></i>
                                            </a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this expense?')">
                                                <input type="hidden" name="delete_id" value="<?= $expense['id'] ?>">
                                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger table-action-btn" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        <div class="d-md-none table-action-dropdown">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <a class="dropdown-item" href="expenses_edit.php?id=<?= $expense['id'] ?>">
                                                            <i class="fas fa-pencil me-2 text-primary"></i>Edit
                                                        </a>
                                                    </li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="post" onsubmit="return confirm('Delete this expense?')">
                                                            <input type="hidden" name="delete_id" value="<?= $expense['id'] ?>">
                                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                            <button type="submit" class="dropdown-item text-danger w-100 text-start border-0 bg-transparent">
                                                                <i class="fas fa-trash me-2"></i>Delete
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">Read only</span>
                                    <?php endif; ?>
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
