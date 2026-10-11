<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/invoice_payment.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);
$installment_id = safe_int($_GET['installment_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

if ($invoice === [] || !bdta_invoice_is_payable($invoice)) {
    setFlashMessage('Invoice not found or cannot accept payment.', 'danger');
    redirect('invoices_list.php');
}

$installments_stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE invoice_id = ? ORDER BY installment_number");
$installments_stmt->execute([$id]);
$invoice_installments = assoc_rows($installments_stmt->fetchAll(PDO::FETCH_ASSOC));
$payment_summary = bdta_invoice_get_payment_summary($conn, $invoice, $invoice_installments);
$payment_summary_paid_total = safe_float($payment_summary['paid_total']);
$payment_summary_remaining_amount = safe_float($payment_summary['remaining_amount']);
$csrf_token_value = scalar_string($_SESSION['csrf_token'] ?? '');

// If paying a specific installment, load it
$installment = null;
if ($installment_id) {
    $stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE id = ? AND invoice_id = ?");
    $stmt->execute([$installment_id, $id]);
    $installment = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($installment === [] || array_string_value($installment, 'status') === 'paid') {
        setFlashMessage('Installment not found or already paid!', 'danger');
        redirect('invoices_view.php?id=' . $id);
    }
}

/**
 * Send a payment receipt for a fully-paid invoice and record the audit timestamp.
 */
function sendFullInvoiceReceipt(PDO $conn, int $invoice_id): void {
    $invoice_stmt = $conn->prepare(
        "SELECT i.*, c.name as client_name, c.email as client_email
         FROM invoices i JOIN clients c ON i.client_id = c.id WHERE i.id = ?"
    );
    $invoice_stmt->execute([$invoice_id]);
    $full_invoice = assoc_row($invoice_stmt->fetch(PDO::FETCH_ASSOC));
    if ($full_invoice === []) return;

    $items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $items_stmt->execute([$invoice_id]);
    $items = assoc_rows($items_stmt->fetchAll(PDO::FETCH_ASSOC));

    $email_service = new EmailService(null, $conn);
    $result = $email_service->sendPaymentReceipt($full_invoice, null, $items);
    if ($result['success']) {
        $conn->prepare("UPDATE invoices SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$invoice_id]);
    }
}

// Handle manual payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_csrf_token = scalar_string($_POST['csrf_token'] ?? '');
    $payment_method = trim(scalar_string($_POST['payment_method'] ?? ''));
    $payment_date   = trim(scalar_string($_POST['payment_date'] ?? date('Y-m-d')));
    $payment_amount = $installment
        ? round(safe_float($installment['amount'] ?? 0), 2)
        : round(safe_float($_POST['payment_amount'] ?? 0), 2);
    $payment_date_object = DateTime::createFromFormat('Y-m-d', $payment_date);
    $payment_date_errors = DateTime::getLastErrors();
    $payment_date_warning_count = 0;
    $payment_date_error_count = 0;
    if (is_array($payment_date_errors)) {
        $payment_date_warning_count = $payment_date_errors['warning_count'];
        $payment_date_error_count = $payment_date_errors['error_count'];
    }
    $payment_date_has_errors = $payment_date_warning_count > 0 || $payment_date_error_count > 0;
    $payment_date_is_valid = $payment_date_object instanceof DateTime
        && $payment_date_object->format('Y-m-d') === $payment_date
        && !$payment_date_has_errors;

    if ($submitted_csrf_token === '' || $csrf_token_value === '' || !hash_equals($csrf_token_value, $submitted_csrf_token)) {
        setFlashMessage('Invalid request.', 'danger');
    } elseif (!in_array($payment_method, ['cash', 'check', 'bank_transfer', 'other'], true)) {
        setFlashMessage('Invalid payment method!', 'danger');
    } elseif (!$payment_date_is_valid) {
        setFlashMessage('Please enter a valid payment date.', 'danger');
    } else {
        try {
            $result = bdta_invoice_record_manual_payment($conn, $id, $payment_amount, $payment_method, $payment_date,
                safe_int($_SESSION['admin_id'] ?? 0) ?: null, $installment_id);
            $invoice = $result['invoice'];
            $installment = $result['installment'];
            // Receipts are sent only after the payment and all credits commit.
            if ($installment !== null) {
                $client_stmt = $conn->prepare('SELECT name as client_name, email as client_email FROM clients WHERE id = ?');
                $client_stmt->execute([array_int_value($invoice, 'client_id')]);
                $client = assoc_row($client_stmt->fetch(PDO::FETCH_ASSOC));
                $email_service = new EmailService(null, $conn);
                $receipt_result = $email_service->sendPaymentReceipt(array_merge($invoice, $client), $installment);
                if ($receipt_result['success']) {
                    $conn->prepare('UPDATE invoice_installments SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$installment_id]);
                }
            }
            if (array_string_value($invoice, 'status') === 'paid') {
                sendFullInvoiceReceipt($conn, $id);
                setFlashMessage('Final payment recorded successfully! Invoice marked as paid and package credits applied.', 'success');
            } else {
                $updated_summary = bdta_invoice_get_payment_summary($conn, $invoice);
                setFlashMessage('Payment recorded. Remaining balance: $' . number_format($updated_summary['remaining_amount'], 2) . '.', 'success');
            }
        } catch (Throwable $e) {
            setFlashMessage('Unable to record payment: ' . $e->getMessage(), 'danger');
        }
        redirect('invoices_view.php?id=' . $id);
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">
                        <?php if ($installment): ?>
                            Record Installment Payment #<?= escape(array_string_value($installment, 'installment_number')) ?>
                        <?php else: ?>
                            Record Payment
                        <?php endif; ?>
                    </h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>Invoice:</strong> <?= escape(array_string_value($invoice, 'invoice_number')) ?><br>
                        <?php if ($installment): ?>
                            <strong>Installment #<?= escape(array_string_value($installment, 'installment_number')) ?> Amount:</strong>
                            $<?= number_format(safe_float($installment['amount'] ?? 0), 2) ?><br>
                            <strong>Due Date:</strong> <?= formatDate(array_string_value($installment, 'due_date')) ?>
                        <?php else: ?>
                            <strong>Invoice Total:</strong> $<?= number_format(safe_float($invoice['total_amount'] ?? 0), 2) ?><br>
                            <strong>Paid So Far:</strong> $<?= number_format($payment_summary_paid_total, 2) ?><br>
                            <strong>Remaining Balance:</strong> $<?= number_format($payment_summary_remaining_amount, 2) ?>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= escape($csrf_token_value) ?>">
                        <?php if (!$installment && empty($invoice_installments)): ?>
                        <div class="mb-3">
                            <label for="payment_amount" class="form-label">Payment Amount *</label>
                            <input type="number" class="form-control" id="payment_amount" name="payment_amount"
                                   min="0.01" max="<?= escape(number_format($payment_summary_remaining_amount, 2, '.', '')) ?>"
                                   step="0.01" value="<?= escape(number_format($payment_summary_remaining_amount, 2, '.', '')) ?>" required>
                            <div class="form-text">Enter any amount up to the remaining balance.</div>
                        </div>
                        <?php elseif (!$installment): ?>
                        <div class="alert alert-info">
                            Use the installment payment actions on the invoice page to record payments for this invoice.
                        </div>
                        <?php endif; ?>
                        <div class="mb-3">
                            <label for="payment_method" class="form-label">Payment Method *</label>
                            <select class="form-select" id="payment_method" name="payment_method" required>
                                <option value="">Select Payment Method</option>
                                <option value="cash">Cash</option>
                                <option value="check">Check</option>
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="payment_date" class="form-label">Payment Date *</label>
                            <input type="date" class="form-control" id="payment_date" name="payment_date"
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-triangle-exclamation"></i>
                            <strong>Note:</strong> This action cannot be undone.
                            <?php if (!$installment): ?>
                                Package credits will be applied automatically once the invoice is paid in full.
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> Confirm Payment Received
                            </button>
                            <a href="invoices_view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                    
                    <?php if (!$installment && empty($invoice_installments)): ?>
                    <hr class="my-4">
                    <h5>Online Payment (Stripe)</h5>
                    <p class="text-muted">For online credit card payments, integration with Stripe is available.</p>
                    <button class="btn btn-primary" id="stripePaymentBtn">
                        <i class="fas fa-credit-card"></i> Pay with Credit Card (Coming Soon)
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
<?php if (!$installment && empty($invoice_installments)): ?>
document.getElementById('stripePaymentBtn').addEventListener('click', function() {
    alert('Stripe integration requires configuration. See backend/BUSINESS_MANAGEMENT.md for setup instructions.');
});
<?php endif; ?>
</script>

<?php include '../backend/includes/footer.php'; ?>
