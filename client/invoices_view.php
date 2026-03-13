<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);
$stmt = $conn->prepare("
    SELECT i.*, c.name as client_name, c.email as client_email, c.phone as client_phone
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
    WHERE i.id = ?
");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($invoice)) {
    setFlashMessage('Invoice not found!', 'danger');
    redirect('invoices_list.php');
}

// Fetch invoice items (used for the view and for sending receipts)
$items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items_stmt->execute([$id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle "Send Receipt" POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_receipt'])) {
    if (array_string_value($invoice, 'status') !== 'paid') {
        setFlashMessage('Cannot send receipt: invoice is not fully paid.', 'danger');
    } else {
        $email_service = new EmailService(null, $conn);
        $result = $email_service->sendPaymentReceipt($invoice, null, $items);

        if ($result['success']) {
            $conn->prepare("UPDATE invoices SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$id]);
            setFlashMessage('Payment receipt sent to ' . escape($invoice['client_email']) . '.', 'success');
        } else {
            setFlashMessage('Failed to send receipt: ' . $result['message'], 'danger');
        }
    }
    redirect('invoices_view.php?id=' . $id);
}

// Handle "Send Invoice" POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_invoice'])) {
    if (array_string_value($invoice, 'status') === 'paid') {
        setFlashMessage('Invoice is already paid. Use "Send Receipt" instead.', 'warning');
    } else {
        // Ensure a secure payment token exists for the guest pay link
        if (empty($invoice['pay_token'])) {
            $invoice['pay_token'] = bin2hex(random_bytes(32));
            $conn->prepare("UPDATE invoices SET pay_token = ? WHERE id = ?")->execute([$invoice['pay_token'], $id]);
        }

        $email_service = new EmailService(null, $conn);
        $result = $email_service->sendInvoiceEmail($invoice, $items);

        if ($result['success']) {
            $conn->prepare("UPDATE invoices SET invoice_sent_at = CURRENT_TIMESTAMP, status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END WHERE id = ?")->execute([$id]);
            setFlashMessage('Invoice sent to ' . escape($invoice['client_email']) . '.', 'success');
        } else {
            setFlashMessage('Failed to send invoice: ' . $result['message'], 'danger');
        }
    }
    redirect('invoices_view.php?id=' . $id);
}

// Fetch installments
$inst_stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE invoice_id = ? ORDER BY installment_number");
$inst_stmt->execute([$id]);
$installments = $inst_stmt->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-file-invoice me-2"></i>Invoice: <?= escape($invoice['invoice_number']) ?></h2>
                <div>
                    <?php if (array_string_value($invoice, 'status') !== 'paid'): ?>
                        <?php if (empty($installments)): ?>
                            <a href="invoices_payment.php?id=<?= $id ?>" class="btn btn-success">
                                <i class="fas fa-credit-card"></i> Record Payment
                            </a>
                        <?php endif; ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="send_invoice" value="1">
                            <button type="submit" class="btn btn-primary"
                                    title="<?= !empty($invoice['invoice_sent_at']) ? 'Last sent: ' . escape($invoice['invoice_sent_at']) : 'Invoice not yet sent' ?>">
                                <i class="fas fa-paper-plane"></i>
                                <?= !empty($invoice['invoice_sent_at']) ? 'Resend Invoice' : 'Send Invoice' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if (array_string_value($invoice, 'status') === 'paid'): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="send_receipt" value="1">
                            <button type="submit" class="btn btn-outline-success"
                                    title="<?= $invoice['receipt_sent_at'] ? 'Last sent: ' . escape($invoice['receipt_sent_at']) : 'No receipt sent yet' ?>">
                                <i class="fas fa-receipt"></i>
                                <?= $invoice['receipt_sent_at'] ? 'Resend Receipt' : 'Send Receipt' ?>
                            </button>
                        </form>
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
                                 $color = $colors[array_string_value($invoice, 'status')] ?? 'secondary';
                                 ?>
                                <span class="badge bg-<?= $color ?>"><?= strtoupper(array_string_value($invoice, 'status')) ?></span>
                            </p>
                            <?php if (!empty($invoice['invoice_sent_at'])): ?>
                                <p><small><i class="fas fa-paper-plane"></i> Invoice sent: <?= escape($invoice['invoice_sent_at']) ?></small></p>
                            <?php endif; ?>
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
                                        <td>
                                            <?= escape($item['description']) ?>
                                            <?php if ($item['item_type'] === 'package'): ?>
                                                <span class="badge bg-info ms-1"><i class="fas fa-box-open"></i> Package</span>
                                            <?php elseif ($item['item_type'] === 'appointment_type'): ?>
                                                <span class="badge bg-primary ms-1"><i class="fas fa-calendar-check"></i> Appointment</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= number_format(safe_float($item['quantity']), 2) ?></td>
                                        <td class="text-end">$<?= number_format(safe_float($item['rate']), 2) ?></td>
                                        <td class="text-end">$<?= number_format(safe_float($item['amount']), 2) ?></td>
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
                                    <td class="text-end">$<?= number_format(safe_float($invoice['subtotal']), 2) ?></td>
                                </tr>
                                <?php if (safe_float($invoice['tax_rate']) > 0): ?>
                                    <tr>
                                        <td class="text-end"><strong>Tax (<?= safe_float($invoice['tax_rate']) ?>%):</strong></td>
                                        <td class="text-end">$<?= number_format(safe_float($invoice['tax_amount']), 2) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <tr class="table-primary">
                                    <td class="text-end"><strong>TOTAL:</strong></td>
                                    <td class="text-end"><strong>$<?= number_format(safe_float($invoice['total_amount']), 2) ?></strong></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($invoice['payment_method']): ?>
                        <div class="alert alert-success mt-3">
                            <strong>Payment Received:</strong> $<?= number_format(safe_float($invoice['total_amount']), 2) ?> via <?= escape(ucwords(array_string_value($invoice, 'payment_method'))) ?>
                            <?php if ($invoice['stripe_payment_intent_id']): ?>
                                <br><small>Stripe Payment ID: <?= escape($invoice['stripe_payment_intent_id']) ?></small>
                            <?php endif; ?>
                            <?php if ($invoice['receipt_sent_at']): ?>
                                <br><small><i class="fas fa-receipt"></i> Receipt sent: <?= escape($invoice['receipt_sent_at']) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Installments Section -->
            <?php if (!empty($installments)): ?>
            <div class="card mt-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Installment Schedule</h5>
                    <?php
                    $paid_count = count(array_filter($installments, fn($i) => $i['status'] === 'paid'));
                    $total_count = count($installments);
                    $paid_amount = array_sum(array_column(array_filter($installments, fn($i) => $i['status'] === 'paid'), 'amount'));
                    ?>
                    <span class="badge bg-<?= $paid_count === $total_count ? 'success' : 'warning' ?>">
                        <?= $paid_count ?>/<?= $total_count ?> Paid
                    </span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Amount</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Paid Date</th>
                                    <th>Method</th>
                                    <?php if ($invoice['status'] !== 'paid'): ?>
                                        <th>Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($installments as $inst): ?>
                                    <?php
                                    $is_overdue = $inst['status'] === 'unpaid' && $inst['due_date'] < date('Y-m-d');
                                    $row_class = $inst['status'] === 'paid' ? 'table-success' : ($is_overdue ? 'table-danger' : '');
                                    ?>
                                    <tr class="<?= $row_class ?>">
                                        <td><?= $inst['installment_number'] ?></td>
                                     <td><strong>$<?= number_format(safe_float($inst['amount']), 2) ?></strong></td>
                                        <td>
                                            <?= formatDate($inst['due_date']) ?>
                                            <?php if ($is_overdue): ?>
                                                <span class="badge bg-danger ms-1">Overdue</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($inst['status'] === 'paid'): ?>
                                                <span class="badge bg-success">Paid</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $inst['payment_date'] ? formatDate($inst['payment_date']) : '—' ?></td>
                                        <td><?= $inst['payment_method'] ? escape(ucwords(str_replace('_', ' ', $inst['payment_method']))) : '—' ?></td>
                                     <?php if (array_string_value($invoice, 'status') !== 'paid'): ?>
                                            <td>
                                                <?php if ($inst['status'] === 'unpaid'): ?>
                                                    <a href="invoices_payment.php?id=<?= $id ?>&installment_id=<?= $inst['id'] ?>"
                                                       class="btn btn-sm btn-success">
                                                        <i class="fas fa-check"></i> Pay
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td><strong>Total Paid</strong></td>
                                     <td><strong>$<?= number_format((float) $paid_amount, 2) ?></strong></td>
                                     <td colspan="<?= array_string_value($invoice, 'status') !== 'paid' ? 5 : 4 ?>">
                                         Remaining: <strong>$<?= number_format(safe_float($invoice['total_amount']) - (float) $paid_amount, 2) ?></strong>
                                     </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
