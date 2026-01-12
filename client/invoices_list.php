<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$client_filter = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;

// Fetch clients
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch invoices
$sql = "
    SELECT i.*, c.name as client_name 
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
";
$params = [];

if ($client_filter > 0) {
    $sql .= " WHERE i.client_id = ?";
    $params[] = $client_filter;
}

$sql .= " ORDER BY i.issue_date DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Invoice Management</h2>
        <a href="invoices_create.php<?= $client_filter > 0 ? "?client_id=$client_filter" : '' ?>" class="btn btn-primary">
            <i class="bi bi-plus-circle"></i> Create Invoice
        </a>
    </div>

    <?php $flash = getFlashMessage(); if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Filter by Client</label>
                    <select class="form-select" name="client_id" onchange="this.form.submit()">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>" <?= $client_filter == $client['id'] ? 'selected' : '' ?>>
                                <?= escape($client['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($client_filter > 0): ?>
                    <div class="col-md-2">
                        <label class="form-label">&nbsp;</label>
                        <a href="invoices_list.php" class="btn btn-secondary d-block">Clear</a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Client</th>
                            <th>Issue Date</th>
                            <th>Due Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr><td colspan="7" class="text-center py-4">
                                <p class="text-muted">No invoices found.</p>
                            </td></tr>
                        <?php else: foreach ($invoices as $invoice): ?>
                            <tr>
                                <td><strong><?= escape($invoice['invoice_number']) ?></strong></td>
                                <td><?= escape($invoice['client_name']) ?></td>
                                <td><?= formatDate($invoice['issue_date']) ?></td>
                                <td><?= formatDate($invoice['due_date']) ?></td>
                                <td><strong>$<?= number_format($invoice['total_amount'], 2) ?></strong></td>
                                <td>
                                    <?php
                                    $status_colors = [
                                        'draft' => 'secondary',
                                        'sent' => 'info',
                                        'paid' => 'success',
                                        'overdue' => 'danger',
                                        'cancelled' => 'dark'
                                    ];
                                    $color = $status_colors[$invoice['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= strtoupper($invoice['status']) ?></span>
                                </td>
                                <td>
                                    <a href="invoices_view.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-outline-info">
                                        <i class="bi bi-eye"></i> View
                                    </a>
                                    <?php if ($invoice['status'] === 'draft'): ?>
                                        <a href="invoices_create.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-pencil"></i> Edit
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($invoice['status'] !== 'paid'): ?>
                                        <a href="invoices_payment.php?id=<?= $invoice['id'] ?>" class="btn btn-sm btn-outline-success">
                                            <i class="bi bi-credit-card"></i> Pay
                                        </a>
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
