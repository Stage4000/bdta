<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $issue_date = $_POST['issue_date'] ?? date('Y-m-d');
    $due_date = $_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days'));
    $tax_rate = floatval($_POST['tax_rate'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    
    // Calculate totals from posted items
    $subtotal = 0;
    $items = [];
    
    if (isset($_POST['item_desc'])) {
        foreach ($_POST['item_desc'] as $index => $desc) {
            if (!empty($desc)) {
                $qty = floatval($_POST['item_qty'][$index] ?? 1);
                $rate = floatval($_POST['item_rate'][$index] ?? 0);
                $amount = $qty * $rate;
                $subtotal += $amount;
                
                $items[] = [
                    'description' => $desc,
                    'quantity' => $qty,
                    'rate' => $rate,
                    'amount' => $amount
                ];
            }
        }
    }
    
    $tax_amount = $subtotal * ($tax_rate / 100);
    $total_amount = $subtotal + $tax_amount;
    
    if ($client_id && !empty($items)) {
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Insert invoice
        $stmt = $conn->prepare("
            INSERT INTO invoices (invoice_number, client_id, issue_date, due_date, subtotal, tax_rate, tax_amount, total_amount, notes, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ");
        $stmt->execute([$invoice_number, $client_id, $issue_date, $due_date, $subtotal, $tax_rate, $tax_amount, $total_amount, $notes]);
        $invoice_id = $conn->lastInsertId();
        
        // Insert invoice items
        $item_stmt = $conn->prepare("
            INSERT INTO invoice_items (invoice_id, item_type, description, quantity, rate, amount) 
            VALUES (?, 'custom', ?, ?, ?, ?)
        ");
        
        foreach ($items as $item) {
            $item_stmt->execute([$invoice_id, $item['description'], $item['quantity'], $item['rate'], $item['amount']]);
        }
        
        setFlashMessage('Invoice created successfully!', 'success');
        redirect('invoices_view.php?id=' . $invoice_id);
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <h2 class="mb-4"><i class="bi bi-file-earmark-text me-2"></i>Create Invoice</h2>
            
            <div class="card">
                <div class="card-body">
                    <form method="POST" id="invoiceForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Client *</label>
                                <select class="form-select" name="client_id" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>"><?= escape($client['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Issue Date *</label>
                                <input type="date" class="form-control" name="issue_date" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Due Date *</label>
                                <input type="date" class="form-control" name="due_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>" required>
                            </div>
                        </div>
                        
                        <h5 class="mt-4 mb-3">Line Items</h5>
                        <div id="lineItems">
                            <div class="row mb-2 line-item">
                                <div class="col-md-5">
                                    <input type="text" class="form-control" name="item_desc[]" placeholder="Description" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control item-qty" name="item_qty[]" placeholder="Qty" value="1" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control item-rate" name="item_rate[]" placeholder="Rate" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control item-amount" placeholder="Amount" readonly>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger btn-sm remove-item">×</button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-secondary btn-sm mb-3" id="addItem">
                            <i class="bi bi-plus"></i> Add Line Item
                        </button>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="3"></textarea>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">Tax Rate (%)</label>
                                    <input type="number" step="0.01" class="form-control" name="tax_rate" id="taxRate" value="0">
                                </div>
                                <div class="card">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Subtotal:</span>
                                            <strong id="subtotalDisplay">$0.00</strong>
                                        </div>
                                        <div class="d-flex justify-content-between mb-2">
                                            <span>Tax:</span>
                                            <strong id="taxDisplay">$0.00</strong>
                                        </div>
                                        <hr>
                                        <div class="d-flex justify-content-between">
                                            <strong>Total:</strong>
                                            <strong id="totalDisplay">$0.00</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Create Invoice
                            </button>
                            <a href="invoices_list.php" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const lineItems = document.getElementById('lineItems');
    const addItemBtn = document.getElementById('addItem');
    const taxRateInput = document.getElementById('taxRate');
    
    function calculateTotals() {
        let subtotal = 0;
        document.querySelectorAll('.line-item').forEach(item => {
            const qty = parseFloat(item.querySelector('.item-qty').value) || 0;
            const rate = parseFloat(item.querySelector('.item-rate').value) || 0;
            const amount = qty * rate;
            item.querySelector('.item-amount').value = '$' + amount.toFixed(2);
            subtotal += amount;
        });
        
        const taxRate = parseFloat(taxRateInput.value) || 0;
        const taxAmount = subtotal * (taxRate / 100);
        const total = subtotal + taxAmount;
        
        document.getElementById('subtotalDisplay').textContent = '$' + subtotal.toFixed(2);
        document.getElementById('taxDisplay').textContent = '$' + taxAmount.toFixed(2);
        document.getElementById('totalDisplay').textContent = '$' + total.toFixed(2);
    }
    
    addItemBtn.addEventListener('click', function() {
        const newItem = lineItems.firstElementChild.cloneNode(true);
        newItem.querySelectorAll('input[type="text"], input[type="number"]').forEach(input => {
            if (!input.classList.contains('item-amount')) input.value = input.placeholder === 'Qty' ? '1' : '';
        });
        lineItems.appendChild(newItem);
        calculateTotals();
    });
    
    lineItems.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-item')) {
            if (lineItems.children.length > 1) {
                e.target.closest('.line-item').remove();
                calculateTotals();
            }
        }
    });
    
    lineItems.addEventListener('input', calculateTotals);
    taxRateInput.addEventListener('input', calculateTotals);
});
</script>

<?php include '../backend/includes/footer.php'; ?>
