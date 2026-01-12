<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = intval($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice || $invoice['status'] === 'paid') {
    setFlashMessage('Invoice not found or already paid!', 'danger');
    redirect('invoices_list.php');
}

// Handle manual payment recording
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = trim($_POST['payment_method'] ?? '');
    $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
    
    if (in_array($payment_method, ['cash', 'check', 'bank_transfer', 'other'])) {
        $stmt = $conn->prepare("
            UPDATE invoices 
            SET status = 'paid', payment_method = ?, payment_date = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$payment_method, $payment_date, $id]);
        
        setFlashMessage('Payment recorded successfully!', 'success');
        redirect('invoices_view.php?id=' . $id);
    } else {
        setFlashMessage('Invalid payment method!', 'danger');
    }
}

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-6 offset-md-3">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">Record Payment</h4>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong>Invoice:</strong> <?= escape($invoice['invoice_number']) ?><br>
                        <strong>Amount:</strong> $<?= number_format($invoice['total_amount'], 2) ?>
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
                            <i class="bi bi-exclamation-triangle"></i> 
                            <strong>Note:</strong> This will mark the invoice as paid. This action cannot be undone.
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-circle"></i> Confirm Payment Received
                            </button>
                            <a href="invoices_view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                    
                    <hr class="my-4">
                    
                    <h5>Online Payment (Stripe)</h5>
                    <p class="text-muted">For online credit card payments, integration with Stripe is available.</p>
                    <button class="btn btn-primary" id="stripePaymentBtn">
                        <i class="bi bi-credit-card"></i> Pay with Credit Card (Coming Soon)
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('stripePaymentBtn').addEventListener('click', function() {
    alert('Stripe integration requires configuration. See backend/BUSINESS_MANAGEMENT.md for setup instructions.');
});
</script>

<?php include '../includes/footer.php'; ?>
