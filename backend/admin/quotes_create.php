<?php
/**
 * Create/Edit Quote
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

$quote_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit = $quote_id > 0;

// Get clients
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load quote if editing
$quote = null;
$items = [];
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM quotes WHERE id = ?");
    $stmt->execute([$quote_id]);
    $quote = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$quote) {
        $_SESSION['error'] = "Quote not found";
        header('Location: quotes_list.php');
        exit;
    }
    
    $items_stmt = $conn->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id");
    $items_stmt->execute([$quote_id]);
    $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id']);
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $expiration_date = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
    $notes = trim($_POST['notes']);
    
    // Parse line items
    $line_items = [];
    $total_amount = 0;
    
    if (isset($_POST['item_description']) && is_array($_POST['item_description'])) {
        for ($i = 0; $i < count($_POST['item_description']); $i++) {
            $desc = trim($_POST['item_description'][$i]);
            $qty = max(1, intval($_POST['item_quantity'][$i]));
            $price = floatval($_POST['item_price'][$i]);
            $amount = $qty * $price;
            
            if ($desc && $price > 0) {
                $line_items[] = [
                    'description' => $desc,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'amount' => $amount
                ];
                $total_amount += $amount;
            }
        }
    }
    
    if (count($line_items) == 0) {
        $_SESSION['error'] = "Please add at least one line item";
    } else {
        try {
            $conn->beginTransaction();
            
            if ($is_edit) {
                // Update quote
                $stmt = $conn->prepare("
                    UPDATE quotes SET 
                        client_id = ?, title = ?, description = ?, 
                        amount = ?, expiration_date = ?, notes = ?, 
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$client_id, $title, $description, $total_amount, $expiration_date, $notes, $quote_id]);
                
                // Delete old items
                $conn->prepare("DELETE FROM quote_items WHERE quote_id = ?")->execute([$quote_id]);
            } else {
                // Generate quote number
                $stmt = $conn->query("SELECT MAX(CAST(SUBSTR(quote_number, 5) AS INTEGER)) FROM quotes WHERE quote_number LIKE 'QT-%'");
                $last_num = $stmt->fetchColumn();
                $next_num = ($last_num ? $last_num + 1 : 1001);
                $quote_number = 'QT-' . $next_num;
                
                // Insert quote
                $stmt = $conn->prepare("
                    INSERT INTO quotes (quote_number, client_id, title, description, amount, expiration_date, notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'sent')
                ");
                $stmt->execute([$quote_number, $client_id, $title, $description, $total_amount, $expiration_date, $notes]);
                $quote_id = $conn->lastInsertId();
            }
            
            // Insert line items
            $stmt = $conn->prepare("
                INSERT INTO quote_items (quote_id, description, quantity, unit_price, amount)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($line_items as $item) {
                $stmt->execute([$quote_id, $item['description'], $item['quantity'], $item['unit_price'], $item['amount']]);
            }
            
            $conn->commit();
            $_SESSION['success'] = $is_edit ? "Quote updated successfully" : "Quote created successfully";
            header('Location: quotes_view.php?id=' . $quote_id);
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['error'] = "Error saving quote: " . $e->getMessage();
        }
    }
}

$page_title = $is_edit ? "Edit Quote" : "Create Quote";
include '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">
                <i class="bi bi-file-earmark-text me-2"></i>
                <?= $is_edit ? 'Edit Quote' : 'Create Quote' ?>
            </h1>
        </div>
        <div class="col-auto">
            <a href="quotes_list.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Quotes
            </a>
        </div>
    </div>

    <form method="POST" id="quoteForm">
        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Quote Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Client *</label>
                            <select name="client_id" class="form-select" required>
                                <option value="">Select Client...</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?= $client['id'] ?>" <?= $quote && $quote['client_id'] == $client['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($client['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Title *</label>
                            <input type="text" name="title" class="form-control" 
                                   value="<?= $quote ? htmlspecialchars($quote['title']) : '' ?>" 
                                   placeholder="e.g., Training Package - 6 Sessions" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" 
                                      placeholder="Optional description of the quote"><?= $quote ? htmlspecialchars($quote['description']) : '' ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Line Items -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Line Items</h5>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addLineItem()">
                            <i class="bi bi-plus-circle me-1"></i>Add Item
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="lineItemsContainer">
                            <?php if (count($items) > 0): ?>
                                <?php foreach ($items as $item): ?>
                                    <div class="line-item mb-3">
                                        <div class="row g-2">
                                            <div class="col-md-5">
                                                <input type="text" name="item_description[]" class="form-control" 
                                                       placeholder="Description" value="<?= htmlspecialchars($item['description']) ?>" required>
                                            </div>
                                            <div class="col-md-2">
                                                <input type="number" name="item_quantity[]" class="form-control" 
                                                       placeholder="Qty" value="<?= $item['quantity'] ?>" min="1" onchange="calculateTotal()" required>
                                            </div>
                                            <div class="col-md-3">
                                                <input type="number" name="item_price[]" class="form-control" 
                                                       placeholder="Price" value="<?= $item['unit_price'] ?>" step="0.01" min="0" onchange="calculateTotal()" required>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-outline-danger w-100" onclick="removeLineItem(this)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="line-item mb-3">
                                    <div class="row g-2">
                                        <div class="col-md-5">
                                            <input type="text" name="item_description[]" class="form-control" placeholder="Description" required>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="item_quantity[]" class="form-control" placeholder="Qty" value="1" min="1" onchange="calculateTotal()" required>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" name="item_price[]" class="form-control" placeholder="Price" step="0.01" min="0" onchange="calculateTotal()" required>
                                        </div>
                                        <div class="col-md-2">
                                            <button type="button" class="btn btn-outline-danger w-100" onclick="removeLineItem(this)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="text-end mt-3">
                            <strong>Total: $<span id="totalAmount">0.00</span></strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Options</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Expiration Date</label>
                            <input type="date" name="expiration_date" class="form-control" 
                                   value="<?= $quote ? htmlspecialchars($quote['expiration_date']) : '' ?>">
                            <small class="text-muted">Leave blank for no expiration</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Internal Notes</label>
                            <textarea name="notes" class="form-control" rows="4" 
                                      placeholder="Private notes (not shown to client)"><?= $quote ? htmlspecialchars($quote['notes']) : '' ?></textarea>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-save me-1"></i>
                    <?= $is_edit ? 'Update Quote' : 'Create Quote' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function addLineItem() {
    const container = document.getElementById('lineItemsContainer');
    const div = document.createElement('div');
    div.className = 'line-item mb-3';
    div.innerHTML = `
        <div class="row g-2">
            <div class="col-md-5">
                <input type="text" name="item_description[]" class="form-control" placeholder="Description" required>
            </div>
            <div class="col-md-2">
                <input type="number" name="item_quantity[]" class="form-control" placeholder="Qty" value="1" min="1" onchange="calculateTotal()" required>
            </div>
            <div class="col-md-3">
                <input type="number" name="item_price[]" class="form-control" placeholder="Price" step="0.01" min="0" onchange="calculateTotal()" required>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger w-100" onclick="removeLineItem(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(div);
}

function removeLineItem(btn) {
    if (document.querySelectorAll('.line-item').length > 1) {
        btn.closest('.line-item').remove();
        calculateTotal();
    } else {
        alert('Must have at least one line item');
    }
}

function calculateTotal() {
    let total = 0;
    const items = document.querySelectorAll('.line-item');
    items.forEach(item => {
        const qty = parseFloat(item.querySelector('input[name="item_quantity[]"]').value) || 0;
        const price = parseFloat(item.querySelector('input[name="item_price[]"]').value) || 0;
        total += qty * price;
    });
    document.getElementById('totalAmount').textContent = total.toFixed(2);
}

// Calculate total on page load
document.addEventListener('DOMContentLoaded', calculateTotal);
</script>

<?php include '../includes/footer.php'; ?>
