<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle deletion
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
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
        <h2>Expense Tracking</h2>
        <a href="expenses_edit.php" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Add Expense
        </a>
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
                                <td><strong>$<?= number_format($expense['amount'], 2) ?></strong></td>
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
                                    <a href="expenses_edit.php?id=<?= $expense['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="?delete=<?= $expense['id'] ?>" class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Delete this expense?')">
                                        <i class="bi bi-trash"></i>
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

<?php include '../backend/includes/footer.php'; ?>
