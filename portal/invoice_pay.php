<?php
/**
 * Guest invoice payment page — no portal login required.
 * Accessible via a secure token included in the invoice email.
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/stripe_config.php';
require_once '../backend/includes/invoice_status.php';

$db   = new Database();
$conn = $db->getConnection();

$token = trim(scalar_string($_GET['token'] ?? ''));
if (empty($token)) {
    http_response_code(404);
    die('Invoice not found.');
}

// Fetch invoice by pay_token (no client session required)
$stmt = $conn->prepare("
    SELECT i.*, c.name as client_name, c.email as client_email, c.phone as client_phone
    FROM invoices i
    JOIN clients c ON i.client_id = c.id
    WHERE i.pay_token = ?
");
$stmt->execute([$token]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    die('Invoice not found.');
}

// Fetch line items
$items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
$items_stmt->execute([$invoice['id']]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch installments (display only)
$inst_stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE invoice_id = ? ORDER BY installment_number");
$inst_stmt->execute([$invoice['id']]);
$installments = $inst_stmt->fetchAll(PDO::FETCH_ASSOC);

$refunded_total = bdta_invoice_get_refunded_total($conn, safe_int($invoice['id'] ?? 0));
$refunds = bdta_invoice_get_refunds($conn, safe_int($invoice['id'] ?? 0));
$net_amount = bdta_invoice_get_net_amount($invoice, $refunded_total);
$status = strtolower($invoice['status'] ?? 'draft');
$color  = bdta_invoice_status_color($status);

$business_name = Settings::get('site_name', "Brook's Dog Training Academy");
$page_title    = 'Invoice ' . escape($invoice['invoice_number']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?php echo $page_title; ?> — <?php echo escape($business_name); ?></title>
<!-- Dark mode: respect saved user preference, fall back to system preference -->
<script>
    (function () {
        'use strict';
        var saved = localStorage.getItem('bdta-theme');
        var theme = saved ? saved : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
    }());
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
body { background: #f8f9fa; }
.invoice-wrapper { max-width: 760px; margin: 40px auto; padding: 0 16px 60px; }
[data-bs-theme="dark"] body { background: #0f172a; }
[data-bs-theme="dark"] .invoice-wrapper { color: #e5e7eb; }
[data-bs-theme="dark"] .btn-outline-secondary {
    border-color: #cbd5e1;
    color: #f8fafc;
}
[data-bs-theme="dark"] .btn-outline-secondary:hover,
[data-bs-theme="dark"] .btn-outline-secondary:focus {
    background-color: #f8fafc;
    border-color: #f8fafc;
    color: #111827;
}
[data-bs-theme="dark"] .table-primary {
    --bs-table-bg: rgba(154, 0, 115, 0.25);
    --bs-table-striped-bg: rgba(154, 0, 115, 0.3);
    --bs-table-active-bg: rgba(154, 0, 115, 0.35);
    --bs-table-hover-bg: rgba(154, 0, 115, 0.3);
    --bs-table-color: #f5d0fe;
    color: #f5d0fe;
}
[data-bs-theme="dark"] thead.table-light {
    --bs-table-bg: #1f2937;
    --bs-table-striped-bg: #273449;
    --bs-table-active-bg: #2d3b52;
    --bs-table-hover-bg: #273449;
    --bs-table-border-color: #374151;
    --bs-table-color: #e5e7eb;
    color: #e5e7eb;
}
[data-bs-theme="dark"] thead.table-light th {
    background-color: #1f2937;
    border-bottom-color: #374151;
    color: #e5e7eb;
}
@media print {
    .no-print { display: none !important; }
    body,
    [data-bs-theme="dark"] body {
        background: white !important;
        color: #000 !important;
    }
    [data-bs-theme="dark"] .invoice-wrapper {
        color: #000 !important;
    }
    .card { border: none !important; box-shadow: none !important; }
}
</style>
</head>
<body>
<div class="invoice-wrapper">

    <?php
    // Flash message support — session is already started by config.php
    $flash = getFlashMessage();
    if ($flash): ?>
        <div class="alert alert-<?php echo escape($flash['type']); ?> alert-dismissible fade show no-print">
            <?php echo escape($flash['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 no-print">
        <h5 class="mb-0 text-muted"><?php echo escape($business_name); ?></h5>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-print me-1"></i>Print
        </button>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-4">
            <!-- Header -->
            <div class="row mb-4">
                <div class="col-sm-6">
                    <h5 class="fw-bold mb-1"><?php echo escape($business_name); ?></h5>
                    <p class="text-muted mb-0 small">Sebring, Florida</p>
                </div>
                <div class="col-sm-6 text-sm-end mt-3 mt-sm-0">
                    <h4 class="fw-bold mb-1"><?php echo escape($invoice['invoice_number']); ?></h4>
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
                    <?php if (!empty($invoice['issue_date'])): ?>
                        <p class="mb-1"><span class="text-muted">Issue Date:</span> <strong><?php echo escape($invoice['issue_date']); ?></strong></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['due_date'])): ?>
                        <p class="mb-1"><span class="text-muted">Due Date:</span> <strong><?php echo escape($invoice['due_date']); ?></strong></p>
                    <?php endif; ?>
                    <?php if (!empty($invoice['payment_date'])): ?>
                        <p class="mb-1"><span class="text-muted">Paid Date:</span> <strong><?php echo escape($invoice['payment_date']); ?></strong></p>
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
                    </table>
                </div>
            </div>

            <?php if (!empty($invoice['payment_method'])): ?>
                <div class="alert alert-success mt-2 mb-0">
                    <i class="fas fa-check-circle me-1"></i>
                    <strong>Payment Received:</strong> $<?php echo number_format(floatval($invoice['total_amount']), 2); ?>
                    via <?php echo escape(ucwords(str_replace('_', ' ', $invoice['payment_method']))); ?>
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
                                on <?php echo escape(array_string_value($refund, 'refund_date')); ?>
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

    <?php if (bdta_invoice_is_payable($invoice) && isStripeEnabled()): ?>
    <div class="text-center my-4 no-print">
        <a href="invoice_checkout.php?token=<?php echo urlencode($token); ?>"
           class="btn btn-success btn-lg px-5">
            <i class="fas fa-credit-card me-2"></i>Pay $<?php echo number_format(floatval($invoice['total_amount']), 2); ?> with Credit Card
        </a>
        <p class="text-muted small mt-2"><i class="fas fa-lock me-1"></i>Secure payment powered by Stripe</p>
    </div>
    <?php endif; ?>

    <!-- Installments -->
    <?php if (!empty($installments)): ?>
        <?php
        $paid_count  = count(array_filter($installments, fn($i) => $i['status'] === 'paid'));
        $total_count = count($installments);
        $paid_amount = array_sum(array_column(array_filter($installments, fn($i) => $i['status'] === 'paid'), 'amount'));
        ?>
    <div class="card mt-3">
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
                                <?php echo escape($inst['due_date']); ?>
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
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Dark mode toggle (floating) -->
<button id="darkModeToggle" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3 no-print" style="z-index:1100;" title="Toggle dark mode" aria-label="Toggle dark mode">
    <i class="fas fa-moon" id="darkModeIcon"></i>
</button>
<script>
(function () {
    'use strict';
    function updateIcon() {
        var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        var icon = document.getElementById('darkModeIcon');
        if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }
    updateIcon();
    var btn = document.getElementById('darkModeToggle');
    if (btn) {
        btn.addEventListener('click', function () {
            var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('bdta-theme', next);
            updateIcon();
        });
    }
}());
</script>
<?php
require_once __DIR__ . '/../backend/includes/tawk_to.php';
bdta_render_tawk_to_widget();
?>
</body>
</html>
