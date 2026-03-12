<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = intval($_GET['id'] ?? 0);
$installment_id = intval($_GET['installment_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice || $invoice['status'] === 'paid') {
    setFlashMessage('Invoice not found or already paid!', 'danger');
    redirect('invoices_list.php');
}

// If paying a specific installment, load it
$installment = null;
if ($installment_id) {
    $stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE id = ? AND invoice_id = ?");
    $stmt->execute([$installment_id, $id]);
    $installment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$installment || $installment['status'] === 'paid') {
        setFlashMessage('Installment not found or already paid!', 'danger');
        redirect('invoices_view.php?id=' . $id);
    }
}

/**
 * Send a payment receipt for a fully-paid invoice and record the audit timestamp.
 */
function sendFullInvoiceReceipt($conn, $invoice_id) {
    $invoice_stmt = $conn->prepare(
        "SELECT i.*, c.name as client_name, c.email as client_email
         FROM invoices i JOIN clients c ON i.client_id = c.id WHERE i.id = ?"
    );
    $invoice_stmt->execute([$invoice_id]);
    $full_invoice = $invoice_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$full_invoice) return;

    $items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ?");
    $items_stmt->execute([$invoice_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    $email_service = new EmailService(null, $conn);
    $result = $email_service->sendPaymentReceipt($full_invoice, null, $items);
    if ($result['success']) {
        $conn->prepare("UPDATE invoices SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$invoice_id]);
    }
}

/**
 * Apply package credits to a client for all package items on an invoice.
 */
function applyPackageCredits($conn, $invoice_id, $client_id, $admin_id) {
    $items_stmt = $conn->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? AND item_type = 'package' AND reference_id IS NOT NULL");
    $items_stmt->execute([$invoice_id]);
    $package_items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($package_items as $item) {
        $pkg_id = $item['reference_id'];
        $qty    = max(1, intval($item['quantity']));

        $stmt = $conn->prepare("SELECT * FROM packages WHERE id = ? AND is_active = 1");
        $stmt->execute([$pkg_id]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$package) continue;

        $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id = ?");
        $stmt->execute([$pkg_id]);
        $pkg_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($pkg_items)) continue;

        // For each unit quantity on the invoice line, assign one package instance
        for ($q = 0; $q < $qty; $q++) {
            $expires_at = null;
            if ($package['expiration_days']) {
                $expires_at = date('Y-m-d H:i:s', strtotime('+' . $package['expiration_days'] . ' days'));
            }

            $stmt = $conn->prepare("
                INSERT INTO client_packages
                    (client_id, package_id, package_name, expires_at, is_active, notes, created_by)
                VALUES (?, ?, ?, ?, 1, ?, ?)
            ");
            $stmt->execute([$client_id, $pkg_id, $package['name'], $expires_at,
                'Auto-applied from invoice payment', $admin_id]);
            $cp_id = $conn->lastInsertId();

            $credit_stmt = $conn->prepare("
                INSERT INTO client_package_credits
                    (client_package_id, client_id, appointment_type_id, total_credits, used_credits)
                VALUES (?, ?, ?, ?, 0)
            ");
            foreach ($pkg_items as $pi) {
                $credit_stmt->execute([$cp_id, $client_id, $pi['appointment_type_id'], $pi['quantity']]);
            }

            // Audit trail
            $cred_stmt = $conn->prepare("SELECT * FROM client_package_credits WHERE client_package_id = ?");
            $cred_stmt->execute([$cp_id]);
            $tx_stmt = $conn->prepare("
                INSERT INTO package_credit_transactions
                    (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, notes, created_by)
                VALUES (?, ?, ?, 'purchase', ?, ?, ?)
            ");
            foreach ($cred_stmt->fetchAll(PDO::FETCH_ASSOC) as $cred) {
                $tx_stmt->execute([
                    $cred['id'], $client_id, $cred['appointment_type_id'],
                    $cred['total_credits'],
                    "Package '{$package['name']}' from invoice payment",
                    $admin_id
                ]);
            }
        }
    }
}

// Handle manual payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = trim($_POST['payment_method'] ?? '');
    $payment_date   = trim($_POST['payment_date'] ?? date('Y-m-d'));

    if (!in_array($payment_method, ['cash', 'check', 'bank_transfer', 'other'])) {
        setFlashMessage('Invalid payment method!', 'danger');
    } elseif ($installment) {
        // Pay a single installment
        $conn->prepare("
            UPDATE invoice_installments
            SET status = 'paid', payment_method = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$payment_method, $payment_date, $installment_id]);

        // Reload installment to get fresh data for the receipt
        $stmt = $conn->prepare("SELECT * FROM invoice_installments WHERE id = ?");
        $stmt->execute([$installment_id]);
        $installment = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fetch client info for receipt
        $client_stmt = $conn->prepare("SELECT name as client_name, email as client_email FROM clients WHERE id = ?");
        $client_stmt->execute([$invoice['client_id']]);
        $client = $client_stmt->fetch(PDO::FETCH_ASSOC);
        $invoice_for_receipt = array_merge($invoice, $client ?: []);

        // Send installment receipt
        $email_service = new EmailService(null, $conn);
        $receipt_result = $email_service->sendPaymentReceipt($invoice_for_receipt, $installment);
        if ($receipt_result['success']) {
            $conn->prepare("UPDATE invoice_installments SET receipt_sent_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$installment_id]);
        }

        // Check if all installments are now paid
        $unpaid = $conn->prepare("SELECT COUNT(*) FROM invoice_installments WHERE invoice_id = ? AND status = 'unpaid'");
        $unpaid->execute([$id]);
        if ($unpaid->fetchColumn() === '0') {
            // All installments paid — mark invoice paid and apply credits
            $conn->prepare("
                UPDATE invoices SET status = 'paid', payment_method = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$payment_method, $payment_date, $id]);
            applyPackageCredits($conn, $id, $invoice['client_id'], $_SESSION['admin_id']);

            // Send final invoice receipt and update audit timestamp
            sendFullInvoiceReceipt($conn, $id);

            setFlashMessage('Final installment paid! Invoice marked as paid and package credits applied.', 'success');
        } else {
            setFlashMessage('Installment #' . $installment['installment_number'] . ' recorded as paid.', 'success');
        }
        redirect('invoices_view.php?id=' . $id);
    } else {
        // Pay full invoice
        $conn->prepare("
            UPDATE invoices
            SET status = 'paid', payment_method = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$payment_method, $payment_date, $id]);

        // Mark all pending installments as paid
        $conn->prepare("
            UPDATE invoice_installments
            SET status = 'paid', payment_method = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE invoice_id = ? AND status = 'unpaid'
        ")->execute([$payment_method, $payment_date, $id]);

        // Auto-apply package credits
        applyPackageCredits($conn, $id, $invoice['client_id'], $_SESSION['admin_id']);

        // Send payment receipt
        sendFullInvoiceReceipt($conn, $id);

        setFlashMessage('Payment recorded successfully! Package credits applied.', 'success');
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
                            Record Installment Payment #<?= $installment['installment_number'] ?>
                        <?php else: ?>
                            Record Payment
                        <?php endif; ?>
                    </h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>Invoice:</strong> <?= escape($invoice['invoice_number']) ?><br>
                        <?php if ($installment): ?>
                            <strong>Installment #<?= $installment['installment_number'] ?> Amount:</strong>
                            $<?= number_format($installment['amount'], 2) ?><br>
                            <strong>Due Date:</strong> <?= formatDate($installment['due_date']) ?>
                        <?php else: ?>
                            <strong>Amount:</strong> $<?= number_format($invoice['total_amount'], 2) ?>
                        <?php endif; ?>
                    </div>
                    
                    <form method="POST">
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
                                Any package credits on this invoice will be automatically applied to the client.
                            <?php endif; ?>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-check-circle"></i> Confirm Payment Received
                            </button>
                            <a href="invoices_view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                    
                    <?php if (!$installment): ?>
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
<?php if (!$installment): ?>
document.getElementById('stripePaymentBtn').addEventListener('click', function() {
    alert('Stripe integration requires configuration. See backend/BUSINESS_MANAGEMENT.md for setup instructions.');
});
<?php endif; ?>
</script>

<?php include '../backend/includes/footer.php'; ?>
