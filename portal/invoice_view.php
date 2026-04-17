<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/invoice_status.php';
requirePortalLogin();

$client_id = portalClientId();
$db   = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect(PORTAL_URL . 'invoices.php');
}

// Fetch invoice — client ownership enforced
$stmt = $conn->prepare("
    SELECT i.*, c.name as client_name, c.email as client_email, c.phone as client_phone
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
    WHERE i.id = ? AND i.client_id = ?
");
$stmt->execute([$id, $client_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    redirect(PORTAL_URL . 'invoices.php');
}

// Fetch line items
$stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch installments
$stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE invoice_id = ? ORDER BY installment_number");
$stmt->execute([$id]);
$installments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$refunded_total = bdta_invoice_get_refunded_total($conn, $id);
$refunds = bdta_invoice_get_refunds($conn, $id);
$payment_summary = bdta_invoice_get_payment_summary($conn, $invoice, $installments);
$payments = bdta_invoice_get_payments($conn, $id);
$net_amount = bdta_invoice_get_net_amount($invoice, $refunded_total);
$status  = strtolower($invoice['status'] ?? 'draft');
$color   = bdta_invoice_status_color($status);

$page_title = 'Invoice ' . escape($invoice['invoice_number']);
include '../portal/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0"><i class="fas fa-file-invoice me-2"></i><?php echo escape($invoice['invoice_number']); ?></h2>
    <div>
        <a href="invoices.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Invoices</a>
        <button onclick="window.print()" class="btn btn-outline-primary btn-sm ms-1"><i class="fas fa-print me-1"></i>Print</button>
        <?php
        require_once '../backend/includes/stripe_config.php';
        if (bdta_invoice_is_payable($invoice) && isStripeEnabled()):
            // Use token-based checkout if available (no re-login on page redirect)
            $checkout_url = !empty($invoice['pay_token'])
                ? 'invoice_checkout.php?token=' . urlencode($invoice['pay_token'])
                : 'invoice_checkout.php?id=' . $id;
        ?>
        <a href="<?php echo $checkout_url; ?>" class="btn btn-success btn-sm ms-1">
            <i class="fas fa-credit-card me-1"></i>Pay $<?php echo number_format(safe_float($payment_summary['remaining_amount'] ?? 0), 2); ?> with Credit Card
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4" id="invoice-printable">
    <div class="card-body">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-sm-6">
                <h5 class="fw-bold">Brook's Dog Training Academy</h5>
                <p class="text-muted mb-0">Sebring, Florida</p>
            </div>
            <div class="col-sm-6 text-sm-end">
                <h4 class="fw-bold"><?php echo escape($invoice['invoice_number']); ?></h4>
                <span class="badge bg-<?php echo $color; ?> fs-6"><?php echo strtoupper($status); ?></span>
            </div>
        </div>

        <!-- Bill To / Dates -->
        <div class="row mb-4">
            <div class="col-sm-6">
                <p class="text-muted mb-1 small fw-semibold text-uppercase">Bill To</p>
                <p class="mb-0">
                    <?php echo escape($invoice['client_name']); ?><br>
                    <?php echo escape($invoice['client_email']); ?>
                    <?php if (!empty($invoice['client_phone'])): ?>
                        <br><?php echo escape($invoice['client_phone']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                <p class="mb-1"><span class="text-muted">Issue Date:</span> <strong><?php echo formatDate($invoice['issue_date']); ?></strong></p>
                <p class="mb-1"><span class="text-muted">Due Date:</span> <strong><?php echo formatDate($invoice['due_date']); ?></strong></p>
                <?php if (!empty($invoice['payment_date'])): ?>
                    <p class="mb-1"><span class="text-muted">Paid Date:</span> <strong><?php echo formatDate($invoice['payment_date']); ?></strong></p>
                <?php endif; ?>
                <?php if ($refunds !== []): ?>
                    <p class="mb-1"><span class="text-muted">Refunded:</span> <strong>$<?php echo number_format($refunded_total, 2); ?></strong></p>
                <?php endif; ?>
            </div>
        </div>

        <hr>

        <!-- Line Items -->
        <div class="table-responsive mb-4">
            <table class="table">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4" class="text-muted text-center">No line items.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo escape($item['description']); ?></td>
                            <td class="text-end"><?php echo number_format(floatval($item['quantity']), 2); ?></td>
                            <td class="text-end">$<?php echo number_format(floatval($item['rate']), 2); ?></td>
                            <td class="text-end">$<?php echo number_format(floatval($item['amount']), 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Totals -->
        <div class="row">
            <div class="col-sm-6">
                <?php if (!empty($invoice['notes'])): ?>
                    <p class="text-muted small fw-semibold text-uppercase mb-1">Notes</p>
                    <p><?php echo escape($invoice['notes']); ?></p>
                <?php endif; ?>
            </div>
            <div class="col-sm-6">
                <table class="table table-sm">
                    <tr>
                        <td class="text-end"><strong>Subtotal:</strong></td>
                        <td class="text-end">$<?php echo number_format(floatval($invoice['subtotal']), 2); ?></td>
                    </tr>
                    <?php if (floatval($invoice['tax_rate'] ?? 0) > 0): ?>
                        <tr>
                            <td class="text-end"><strong>Tax (<?php echo escape($invoice['tax_rate']); ?>%):</strong></td>
                            <td class="text-end">$<?php echo number_format(floatval($invoice['tax_amount']), 2); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($refunds !== []): ?>
                        <tr class="table-warning">
                            <td class="text-end"><strong>Refunded:</strong></td>
                            <td class="text-end">-$<?php echo number_format($refunded_total, 2); ?></td>
                        </tr>
                        <tr class="table-info">
                            <td class="text-end"><strong>Net Collected:</strong></td>
                            <td class="text-end">$<?php echo number_format($net_amount, 2); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="table-primary">
                        <td class="text-end"><strong>Total:</strong></td>
                        <td class="text-end"><strong>$<?php echo number_format(floatval($invoice['total_amount']), 2); ?></strong></td>
                    </tr>
                    <?php if (safe_float($payment_summary['paid_total'] ?? 0) > 0): ?>
                        <tr class="table-success">
                            <td class="text-end"><strong>Total Paid:</strong></td>
                            <td class="text-end">$<?php echo number_format(safe_float($payment_summary['paid_total'] ?? 0), 2); ?></td>
                        </tr>
                        <tr class="<?php echo safe_float($payment_summary['remaining_amount'] ?? 0) > 0 ? 'table-warning' : 'table-success'; ?>">
                            <td class="text-end"><strong>Remaining Balance:</strong></td>
                            <td class="text-end">$<?php echo number_format(safe_float($payment_summary['remaining_amount'] ?? 0), 2); ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if (safe_float($payment_summary['paid_total'] ?? 0) > 0): ?>
            <div class="alert alert-<?php echo safe_float($payment_summary['remaining_amount'] ?? 0) > 0 ? 'info' : 'success'; ?> mt-2 mb-0">
                <i class="fas fa-check-circle me-1"></i>
                <strong><?php echo safe_float($payment_summary['remaining_amount'] ?? 0) > 0 ? 'Payments Recorded:' : 'Payment Received:'; ?></strong>
                $<?php echo number_format(safe_float($payment_summary['paid_total'] ?? 0), 2); ?>
                <?php if (!empty($invoice['payment_method'])): ?>
                    via <?php echo escape(ucwords(str_replace('_', ' ', $invoice['payment_method']))); ?>
                <?php endif; ?>
                <?php if (safe_float($payment_summary['remaining_amount'] ?? 0) > 0): ?>
                    <br><small>Remaining balance: $<?php echo number_format(safe_float($payment_summary['remaining_amount'] ?? 0), 2); ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($payments !== []): ?>
            <div class="alert alert-light border mt-2 mb-0">
                <strong>Payment History:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($payments as $payment): ?>
                        <li>
                            $<?php echo number_format(safe_float($payment['amount'] ?? 0), 2); ?>
                            on <?php echo formatDate(array_string_value($payment, 'payment_date')); ?>
                            via <?php echo escape(ucwords(str_replace('_', ' ', array_string_value($payment, 'payment_method', 'other')))); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($invoice['void_reason'])): ?>
            <div class="alert alert-dark mt-2 mb-0">
                <strong>Void Reason:</strong> <?php echo escape($invoice['void_reason']); ?>
            </div>
        <?php endif; ?>
        <?php if ($refunds !== []): ?>
            <div class="alert alert-warning mt-2 mb-0">
                <strong>Refund History:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($refunds as $refund): ?>
                        <li>
                            $<?php echo number_format(safe_float($refund['amount'] ?? 0), 2); ?>
                            on <?php echo formatDate(array_string_value($refund, 'refund_date')); ?>
                            via <?php echo escape(ucwords(str_replace('_', ' ', array_string_value($refund, 'refund_method', 'other')))); ?>
                            <?php if (!empty($refund['notes'])): ?>
                                — <?php echo escape(array_string_value($refund, 'notes')); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Installments -->
<?php if (!empty($installments)): ?>
    <?php
    $paid_count  = count(array_filter($installments, fn($i) => $i['status'] === 'paid'));
    $total_count = count($installments);
    $paid_amount = array_sum(array_column(array_filter($installments, fn($i) => $i['status'] === 'paid'), 'amount'));
    ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="fas fa-calendar-check me-2"></i>Installment Schedule</strong>
        <span class="badge bg-<?php echo $paid_count === $total_count ? 'success' : 'warning'; ?>">
            <?php echo $paid_count; ?>/<?php echo $total_count; ?> Paid
        </span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Amount</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Paid Date</th>
                        <th>Method</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($installments as $inst): ?>
                    <?php
                    $is_overdue = $inst['status'] === 'unpaid' && $inst['due_date'] < date('Y-m-d');
                    $row_class  = $inst['status'] === 'paid' ? 'table-success' : ($is_overdue ? 'table-danger' : '');
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><?php echo intval($inst['installment_number']); ?></td>
                        <td><strong>$<?php echo number_format(floatval($inst['amount']), 2); ?></strong></td>
                        <td>
                            <?php echo formatDate($inst['due_date']); ?>
                            <?php if ($is_overdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
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
                        <td><?php echo $inst['payment_date'] ? formatDate($inst['payment_date']) : '—'; ?></td>
                        <td><?php echo $inst['payment_method'] ? escape(ucwords(str_replace('_', ' ', $inst['payment_method']))) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="2"><strong>Total Paid: $<?php echo number_format($paid_amount, 2); ?></strong></td>
                        <td colspan="4">Remaining: <strong>$<?php echo number_format(floatval($invoice['total_amount']) - $paid_amount, 2); ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    .portal-sidebar, nav, .btn, .breadcrumb { display: none !important; }
    #invoice-printable { border: none !important; box-shadow: none !important; }
}
</style>

<?php include '../portal/includes/footer.php'; ?>
