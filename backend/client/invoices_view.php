<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT i.*, c.name as client_name, c.email as client_email, c.phone as client_phone
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    setFlashMessage('Invoice not found!', 'danger');
    redirect('invoices_list.php');
}

// Fetch invoice items
$items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items_stmt->execute([$id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Invoice: <?= escape($invoice['invoice_number']) ?></h2>
                <div>
                    <?php if ($invoice['status'] !== 'paid'): ?>
                        <a href="invoices_payment.php?id=<?= $id ?>" class="btn btn-success">
                            <i class="bi bi-credit-card"></i> Record Payment
                        </a>
                    <?php endif; ?>
                    <a href="invoices_list.php" class="btn btn-secondary">Back to List</a>
                </div>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <!-- Invoice Header -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h4>Brook's Dog Training Academy</h4>
                            <p>
                                Sebring, Florida<br>
                                Highlands County
                            </p>
                        </div>
                        <div class="col-md-6 text-end">
                            <h3><?= escape($invoice['invoice_number']) ?></h3>
                            <p>
                                <strong>Status:</strong> 
                                <?php
                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'paid' => 'success', 'overdue' => 'danger'];
                                $color = $colors[$invoice['status']] ?? 'secondary';
                                ?>
                                <span class="badge bg-<?= $color ?>"><?= strtoupper($invoice['status']) ?></span>
                            </p>
                        </div>
                    </div>
                    
                    <!-- Bill To / Dates -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <strong>Bill To:</strong><br>
                            <?= escape($invoice['client_name']) ?><br>
                            <?= escape($invoice['client_email']) ?><br>
                            <?= escape($invoice['client_phone'] ?? '') ?>
                        </div>
                        <div class="col-md-6 text-end">
                            <strong>Issue Date:</strong> <?= formatDate($invoice['issue_date']) ?><br>
                            <strong>Due Date:</strong> <?= formatDate($invoice['due_date']) ?>
                            <?php if ($invoice['payment_date']): ?>
                                <br><strong>Paid Date:</strong> <?= formatDate($invoice['payment_date']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Line Items -->
                    <div class="table-responsive mb-4">
                        <table class="table">
                            <thead class="table-light">
                                <tr>
                                    <th>Description</th>
                                    <th class="text-end">Quantity</th>
                                    <th class="text-end">Rate</th>
                                    <th class="text-end">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?= escape($item['description']) ?></td>
                                        <td class="text-end"><?= number_format($item['quantity'], 2) ?></td>
                                        <td class="text-end">$<?= number_format($item['rate'], 2) ?></td>
                                        <td class="text-end">$<?= number_format($item['amount'], 2) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Totals -->
                    <div class="row">
                        <div class="col-md-6">
                            <?php if ($invoice['notes']): ?>
                                <strong>Notes:</strong><br>
                                <p><?= escape($invoice['notes']) ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td class="text-end"><strong>Subtotal:</strong></td>
                                    <td class="text-end">$<?= number_format($invoice['subtotal'], 2) ?></td>
                                </tr>
                                <?php if ($invoice['tax_rate'] > 0): ?>
                                    <tr>
                                        <td class="text-end"><strong>Tax (<?= $invoice['tax_rate'] ?>%):</strong></td>
                                        <td class="text-end">$<?= number_format($invoice['tax_amount'], 2) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="table-primary">
                                    <td class="text-end"><strong>TOTAL:</strong></td>
                                    <td class="text-end"><strong>$<?= number_format($invoice['total_amount'], 2) ?></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($invoice['payment_method']): ?>
                        <div class="alert alert-success mt-3">
                            <strong>Payment Received:</strong> $<?= number_format($invoice['total_amount'], 2) ?> via <?= escape(ucwords($invoice['payment_method'])) ?>
                            <?php if ($invoice['stripe_payment_intent_id']): ?>
                                <br><small>Stripe Payment ID: <?= escape($invoice['stripe_payment_intent_id']) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
