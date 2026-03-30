<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/invoice_status.php';
require_once '../backend/includes/stripe_config.php';
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

$refunded_total = bdta_invoice_get_refunded_total($conn, $id);
$refunds = bdta_invoice_get_refunds($conn, $id);
$net_amount = bdta_invoice_get_net_amount($invoice, $refunded_total);
$can_pay_invoice = bdta_invoice_is_payable($invoice);
$can_void_invoice = bdta_invoice_can_void($invoice);
$can_refund_invoice = bdta_invoice_can_refund($invoice, $refunded_total);

// Handle "Send Receipt" POST action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_receipt'])) {
    if (!in_array(array_string_value($invoice, 'status'), ['paid', 'refunded'], true)) {
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
    if (!$can_pay_invoice) {
        setFlashMessage('Only unpaid invoices can be sent.', 'warning');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_invoice'])) {
    if (!$can_void_invoice) {
        setFlashMessage('Only unpaid invoices can be voided.', 'danger');
    } else {
        try {
            bdta_void_invoice($conn, $id, trim(scalar_string($_POST['void_reason'] ?? '')));
            setFlashMessage('Invoice voided successfully.', 'success');
        } catch (Throwable $e) {
            setFlashMessage('Unable to void invoice: ' . $e->getMessage(), 'danger');
        }
    }

    redirect('invoices_view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_invoice'])) {
    $refund_amount = safe_float($_POST['refund_amount'] ?? 0);
    $refund_date = scalar_string($_POST['refund_date'] ?? date('Y-m-d'));
    $refund_note = trim(scalar_string($_POST['refund_note'] ?? ''));
    $refund_method = array_string_value($invoice, 'payment_method', 'other');

    [$refund_year, $refund_month, $refund_day] = array_pad(array_map('intval', explode('-', $refund_date)), 3, 0);
    if (!checkdate($refund_month, $refund_day, $refund_year)) {
        setFlashMessage('Please enter a valid refund date.', 'danger');
    } elseif (!$can_refund_invoice) {
        setFlashMessage('This invoice cannot be refunded.', 'danger');
    } else {
        $stripe_refund_id = null;
        $payment_intent_id = array_string_value($invoice, 'stripe_payment_intent_id');
        if ($payment_intent_id !== '') {
            $stripe_refund = createStripeRefund($payment_intent_id, $refund_amount, [
                'invoice_id' => $id,
                'invoice_number' => array_string_value($invoice, 'invoice_number'),
            ]);

            if (!($stripe_refund['success'] ?? false)) {
                setFlashMessage('Stripe refund failed: ' . array_string_value($stripe_refund, 'error', 'Unknown error'), 'danger');
                redirect('invoices_view.php?id=' . $id);
            }

            $stripe_refund_id = scalar_string($stripe_refund['refund_id'] ?? '');
        }

        try {
            $refund_result = bdta_record_invoice_refund(
                $conn,
                $id,
                $refund_amount,
                $refund_date,
                $refund_method,
                $refund_note,
                $stripe_refund_id
            );

            $remaining_amount = safe_float($refund_result['remaining_amount']);
            $status_message = scalar_string($refund_result['status']) === 'refunded'
                ? 'Invoice refunded in full.'
                : 'Refund recorded successfully. Remaining paid balance: $' . number_format($remaining_amount, 2);
            setFlashMessage($status_message, 'success');
        } catch (Throwable $e) {
            setFlashMessage('Unable to record refund: ' . $e->getMessage(), 'danger');
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
                        <?php if ($can_pay_invoice): ?>
                            <?php if (empty($installments)): ?>
                                <a href="invoices_payment.php?id=<?= $id ?>" class="btn btn-success">
                                    <i class="fas fa-credit-card"></i> Record Payment
                                </a>
                            <?php endif; ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="send_invoice" value="1">
                            <button type="submit" class="btn btn-primary"
                                    title="<?= !empty($invoice['invoice_sent_at']) ? 'Last sent: ' . escape(formatDateTime($invoice['invoice_sent_at'])) : 'Invoice not yet sent' ?>">
                                <i class="fas fa-paper-plane"></i>
                                <?= !empty($invoice['invoice_sent_at']) ? 'Resend Invoice' : 'Send Invoice' ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    <?php if ($can_void_invoice): ?>
                        <button class="btn btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#voidInvoiceForm" aria-expanded="false" aria-controls="voidInvoiceForm">
                            <i class="fas fa-ban"></i> Void Invoice
                        </button>
                    <?php endif; ?>
                    <?php if ($can_refund_invoice): ?>
                        <button class="btn btn-outline-warning" type="button" data-bs-toggle="collapse" data-bs-target="#refundInvoiceForm" aria-expanded="false" aria-controls="refundInvoiceForm">
                            <i class="fas fa-rotate-left"></i> Record Refund
                        </button>
                    <?php endif; ?>
                    <?php if (in_array(array_string_value($invoice, 'status'), ['paid', 'refunded'], true)): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="send_receipt" value="1">
                            <button type="submit" class="btn btn-outline-success"
                                    title="<?= $invoice['receipt_sent_at'] ? 'Last sent: ' . escape(formatDateTime($invoice['receipt_sent_at'])) : 'No receipt sent yet' ?>">
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
                                <?php $color = bdta_invoice_status_color(array_string_value($invoice, 'status')); ?>
                                <span class="badge bg-<?= $color ?>"><?= strtoupper(array_string_value($invoice, 'status')) ?></span>
                            </p>
                            <?php if (!empty($invoice['invoice_sent_at'])): ?>
                                <p><small><i class="fas fa-paper-plane"></i> Invoice sent: <?= escape(formatDateTime($invoice['invoice_sent_at'])) ?></small></p>
                            <?php endif; ?>
                            <?php if (!empty($invoice['voided_at'])): ?>
                                <p><small><i class="fas fa-ban"></i> Voided: <?= escape(formatDateTime($invoice['voided_at'])) ?></small></p>
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
                            <?php if ($refunds !== []): ?>
                                <br><strong>Refunded:</strong> $<?= number_format($refunded_total, 2) ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="collapse mb-4" id="voidInvoiceForm">
                        <div class="card border-dark">
                            <div class="card-header bg-dark text-white">Void Invoice</div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="void_invoice" value="1">
                                    <div class="mb-3">
                                        <label class="form-label">Reason for voiding</label>
                                        <textarea class="form-control" name="void_reason" rows="3" placeholder="Optional note explaining why this invoice is being voided"></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-dark">
                                        <i class="fas fa-ban"></i> Confirm Void
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="collapse mb-4" id="refundInvoiceForm">
                        <div class="card border-warning">
                            <div class="card-header bg-warning-subtle">Record Refund</div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="refund_invoice" value="1">
                                    <div class="row">
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Refund Amount</label>
                                            <input type="number" class="form-control" name="refund_amount" min="0.01" max="<?= escape(number_format($net_amount, 2, '.', '')) ?>" step="0.01" value="<?= escape(number_format($net_amount, 2, '.', '')) ?>" required>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Refund Date</label>
                                            <input type="date" class="form-control" name="refund_date" value="<?= escape(date('Y-m-d')) ?>" required>
                                        </div>
                                        <div class="col-md-4 mb-3">
                                            <label class="form-label">Refund Method</label>
                                            <input type="text" class="form-control" value="<?= escape(ucwords(str_replace('_', ' ', array_string_value($invoice, 'payment_method', 'other')))) ?>" disabled>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Refund note</label>
                                        <textarea class="form-control" name="refund_note" rows="3" placeholder="Optional note explaining the refund"></textarea>
                                    </div>
                                    <button type="submit" class="btn btn-warning">
                                        <i class="fas fa-rotate-left"></i> Confirm Refund
                                    </button>
                                </form>
                            </div>
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
                                             <?php elseif ($item['item_type'] === 'time_entry'): ?>
                                                 <span class="badge bg-secondary ms-1"><i class="fas fa-stopwatch"></i> Time Entry</span>
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
                                <?php if ($refunds !== []): ?>
                                    <tr class="table-warning">
                                        <td class="text-end"><strong>Refunded:</strong></td>
                                        <td class="text-end">-$<?= number_format($refunded_total, 2) ?></td>
                                    </tr>
                                    <tr class="table-info">
                                        <td class="text-end"><strong>Net Collected:</strong></td>
                                        <td class="text-end">$<?= number_format($net_amount, 2) ?></td>
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
                                <br><small><i class="fas fa-receipt"></i> Receipt sent: <?= escape(formatDateTime($invoice['receipt_sent_at'])) ?></small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($invoice['void_reason'])): ?>
                        <div class="alert alert-dark mt-3 mb-0">
                            <strong>Void Reason:</strong> <?= escape($invoice['void_reason']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($refunds !== []): ?>
                        <div class="alert alert-warning mt-3 mb-0">
                            <strong>Refund History:</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($refunds as $refund): ?>
                                    <li>
                                        $<?= number_format(safe_float($refund['amount'] ?? 0), 2) ?>
                                        on <?= formatDate(array_string_value($refund, 'refund_date')) ?>
                                        via <?= escape(ucwords(str_replace('_', ' ', array_string_value($refund, 'refund_method', 'other')))) ?>
                                        <?php if (!empty($refund['notes'])): ?>
                                            — <?= escape(array_string_value($refund, 'notes')) ?>
                                        <?php endif; ?>
                                        <?php if (!empty($refund['stripe_refund_id'])): ?>
                                            <br><small>Stripe Refund ID: <?= escape(array_string_value($refund, 'stripe_refund_id')) ?></small>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
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
                                    <?php if ($can_pay_invoice): ?>
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
                                            <?php elseif ($inst['status'] === 'cancelled'): ?>
                                                <span class="badge bg-secondary">Cancelled</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark">Unpaid</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= $inst['payment_date'] ? formatDate($inst['payment_date']) : '—' ?></td>
                                        <td><?= $inst['payment_method'] ? escape(ucwords(str_replace('_', ' ', $inst['payment_method']))) : '—' ?></td>
                                     <?php if ($can_pay_invoice): ?>
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
                                     <td colspan="<?= $can_pay_invoice ? 5 : 4 ?>">
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
