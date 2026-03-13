<?php
/**
 * Create/Edit Quote
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/email_service.php';

$db = new Database();
$conn = $db->getConnection();

$quote_id = safe_int($_GET['id'] ?? 0);
$is_edit = $quote_id > 0;

// Get clients
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load active packages for the package selector
$packages_stmt = $conn->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY name");
$packages = $packages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load active appointment types for the appointment type selector
$appt_types_stmt = $conn->query("SELECT id, name, default_amount FROM appointment_types WHERE is_active = 1 ORDER BY name");
$appt_types = $appt_types_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    $client_id = safe_int($_POST['client_id'] ?? 0);
    $title = trim(scalar_string($_POST['title'] ?? ''));
    $description = trim(scalar_string($_POST['description'] ?? ''));
    $expiration_date = !empty($_POST['expiration_date']) ? scalar_string($_POST['expiration_date']) : null;
    $notes = trim(scalar_string($_POST['notes'] ?? ''));
    
    // Parse line items
    $line_items = [];
    $total_amount = 0;
    
    if (isset($_POST['item_description']) && is_array($_POST['item_description'])) {
        for ($i = 0; $i < count($_POST['item_description']); $i++) {
            $desc = trim(scalar_string($_POST['item_description'][$i] ?? ''));
            $qty = max(1, safe_int($_POST['item_quantity'][$i] ?? 0));
            $price = safe_float($_POST['item_price'][$i] ?? 0);
            $amount = $qty * $price;
            
            if ($desc && $price > 0) {
                $item_type = 'custom';
                $reference_id = null;
                if (!empty($_POST['item_package_id'][$i])) {
                    $item_type = 'package';
                    $reference_id = safe_int($_POST['item_package_id'][$i]);
                } elseif (!empty($_POST['item_appointment_type_id'][$i])) {
                    $item_type = 'appointment_type';
                    $reference_id = safe_int($_POST['item_appointment_type_id'][$i]);
                }

                $line_items[] = [
                    'description' => $desc,
                    'quantity' => $qty,
                    'unit_price' => $price,
                    'amount' => $amount,
                    'item_type' => $item_type,
                    'reference_id' => $reference_id,
                ];
                $total_amount += $amount;
            }
        }
    }
    
    if (count($line_items) == 0) {
        setFlashMessage("Please add at least one line item with a description and a price greater than zero", 'error');
    } else {
        $saved_quote_id = null;

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
                $stmt = $conn->query("SELECT MAX(CAST(SUBSTR(quote_number, 4) AS INTEGER)) FROM quotes WHERE quote_number LIKE 'QT-%'");
                $last_num = safe_int($stmt->fetchColumn());
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
                INSERT INTO quote_items (quote_id, description, quantity, unit_price, amount, item_type, reference_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($line_items as $item) {
                $stmt->execute([$quote_id, $item['description'], $item['quantity'], $item['unit_price'], $item['amount'], $item['item_type'], $item['reference_id']]);
            }
            
            $conn->commit();
            $saved_quote_id = $quote_id;
            
        } catch (Exception $e) {
            try {
                $conn->rollBack();
            } catch (Exception $re) {
                // rollBack() may throw if beginTransaction() itself failed — safe to ignore
            }
            setFlashMessage("Error saving quote: " . $e->getMessage(), 'error');
        }

        if ($saved_quote_id !== null) {
            // DB transaction succeeded — send email outside the transaction so
            // any email failure cannot trigger a rollback on an already-committed quote.
            if (!$is_edit) {
                try {
                    $email_stmt = $conn->prepare("
                        SELECT q.*, c.name as client_name, c.email as client_email
                        FROM quotes q
                        INNER JOIN clients c ON q.client_id = c.id
                        WHERE q.id = ?
                    ");
                    $email_stmt->execute([$saved_quote_id]);
                    $new_quote = $email_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($new_quote && !empty($new_quote['client_email'])) {
                        $email_items_stmt = $conn->prepare("SELECT * FROM quote_items WHERE quote_id = ? ORDER BY id");
                        $email_items_stmt->execute([$saved_quote_id]);
                        $email_items = $email_items_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $email_service = new EmailService(null, $conn);
                        $email_service->sendQuoteEmail($new_quote, $email_items);
                    }
                } catch (Exception $e) {
                    error_log("Failed to send quote email for quote #{$saved_quote_id}: " . $e->getMessage());
                }
            }
            
            setFlashMessage($is_edit ? "Quote updated successfully" : "Quote created successfully", 'success');
            header('Location: quotes_view.php?id=' . $saved_quote_id);
            exit;
        }
    }
}

$page_title = $is_edit ? "Edit Quote" : "Create Quote";
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2>
                <i class="fas fa-file-invoice me-2"></i>
                <?= $is_edit ? 'Edit Quote' : 'Create Quote' ?>
            </h2>
        </div>
        <div class="col-auto">
            <a href="quotes_list.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Quotes
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

                <!-- Package & Appointment Type Selectors -->
                <?php if (!empty($packages) || !empty($appt_types)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Quick Add</h5>
                    </div>
                    <div class="card-body">
                        <!-- Package Selector -->
                        <?php if (!empty($packages)): ?>
                        <div class="mb-3">
                            <label class="form-label">Add Package</label>
                            <div class="input-group">
                                <select class="form-select" id="packageSelector">
                                    <option value="">— Select a package to add —</option>
                                    <?php foreach ($packages as $pkg): ?>
                                        <option value="<?= $pkg['id'] ?>"
                                                data-name="<?= htmlspecialchars($pkg['name']) ?>"
                                                data-price="<?= safe_float($pkg['price'] ?? 0) ?>">
                                            <?= htmlspecialchars($pkg['name']) ?> — $<?= number_format(safe_float($pkg['price'] ?? 0), 2) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" id="addPackageItem">
                                    <i class="fas fa-plus"></i> Add to Quote
                                </button>
                            </div>
                            <small class="text-muted">Package credits will be automatically applied to the client when this quote is converted to a paid invoice.</small>
                        </div>
                        <?php endif; ?>

                        <!-- Appointment Type Selector -->
                        <?php if (!empty($appt_types)): ?>
                        <div class="mb-0">
                            <label class="form-label">Add Appointment Type</label>
                            <div class="input-group">
                                <select class="form-select" id="apptTypeSelector">
                                    <option value="">— Select an appointment type to add —</option>
                                    <?php foreach ($appt_types as $at): ?>
                                        <option value="<?= $at['id'] ?>"
                                                data-name="<?= htmlspecialchars($at['name']) ?>"
                                                data-price="<?= floatval($at['default_amount'] ?? 0) ?>">
                                            <?= htmlspecialchars($at['name']) ?><?= safe_float($at['default_amount'] ?? 0) > 0 ? ' — $' . number_format(safe_float($at['default_amount'] ?? 0), 2) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" id="addApptTypeItem">
                                    <i class="fas fa-plus"></i> Add to Quote
                                </button>
                            </div>
                            <small class="text-muted">Individual appointment type billed at its default rate.</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Line Items</h5>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addLineItem()">
                            <i class="fas fa-circle-plus me-1"></i>Add Item
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
                                                <input type="hidden" name="item_package_id[]" value="<?= ($item['item_type'] ?? '') === 'package' && $item['reference_id'] ? intval($item['reference_id']) : '' ?>">
                                                <input type="hidden" name="item_appointment_type_id[]" value="<?= ($item['item_type'] ?? '') === 'appointment_type' && $item['reference_id'] ? intval($item['reference_id']) : '' ?>">
                                            </div>
                                            <div class="col-md-2">
                                                <input type="number" name="item_quantity[]" class="form-control" 
                                                       placeholder="Qty" value="<?= $item['quantity'] ?>" min="1" onchange="calculateTotal()" required>
                                            </div>
                                            <div class="col-md-2">
                                                <input type="number" name="item_price[]" class="form-control item-price" 
                                                       placeholder="Price" value="<?= $item['unit_price'] ?>" step="0.01" min="0.01" onchange="calculateTotal()" required>
                                            </div>
                                            <div class="col-md-2">
                                                <input type="text" class="form-control item-amount" placeholder="Amount" readonly>
                                            </div>
                                            <div class="col-md-1">
                                                <button type="button" class="btn btn-outline-danger w-100" onclick="removeLineItem(this)">
                                                    <i class="fas fa-trash"></i>
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
                                            <input type="hidden" name="item_package_id[]" value="">
                                            <input type="hidden" name="item_appointment_type_id[]" value="">
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="item_quantity[]" class="form-control" placeholder="Qty" value="1" min="1" onchange="calculateTotal()" required>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="number" name="item_price[]" class="form-control item-price" placeholder="Price" step="0.01" min="0.01" onchange="calculateTotal()" required>
                                        </div>
                                        <div class="col-md-2">
                                            <input type="text" class="form-control item-amount" placeholder="Amount" readonly>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-outline-danger w-100" onclick="removeLineItem(this)">
                                                <i class="fas fa-trash"></i>
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
                    <i class="fas fa-floppy-disk me-1"></i>
                    <?= $is_edit ? 'Update Quote' : 'Create Quote' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function escapeHtml(str) {
    const div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
}

function addLineItem(name, qty, price, pkgId, apptTypeId) {
    const container = document.getElementById('lineItemsContainer');
    const div = document.createElement('div');
    div.className = 'line-item mb-3';
    div.innerHTML = `
        <div class="row g-2">
            <div class="col-md-5">
                <input type="text" name="item_description[]" class="form-control" placeholder="Description" value="${escapeHtml(name)}" required>
                <input type="hidden" name="item_package_id[]" value="${escapeHtml(pkgId)}">
                <input type="hidden" name="item_appointment_type_id[]" value="${escapeHtml(apptTypeId)}">
            </div>
            <div class="col-md-2">
                <input type="number" name="item_quantity[]" class="form-control" placeholder="Qty" value="${qty || 1}" min="1" onchange="calculateTotal()" required>
            </div>
            <div class="col-md-2">
                <input type="number" name="item_price[]" class="form-control item-price" placeholder="Price" value="${price != null ? parseFloat(price).toFixed(2) : ''}" step="0.01" min="0.01" onchange="calculateTotal()" required>
            </div>
            <div class="col-md-2">
                <input type="text" class="form-control item-amount" placeholder="Amount" readonly>
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-outline-danger w-100" onclick="removeLineItem(this)">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(div);
    calculateTotal();
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
        const amount = qty * price;
        const amountField = item.querySelector('.item-amount');
        if (amountField) amountField.value = '$' + amount.toFixed(2);
        total += amount;
    });
    document.getElementById('totalAmount').textContent = total.toFixed(2);
}

document.addEventListener('DOMContentLoaded', function() {
    calculateTotal();

    // Package selector
    const packageSelector = document.getElementById('packageSelector');
    const addPackageBtn = document.getElementById('addPackageItem');
    if (addPackageBtn) {
        addPackageBtn.addEventListener('click', function() {
            const opt = packageSelector.options[packageSelector.selectedIndex];
            if (!opt.value) return;
            addLineItem(opt.dataset.name, 1, opt.dataset.price, opt.value, '');
            packageSelector.selectedIndex = 0;
        });
    }

    // Appointment type selector
    const apptTypeSelector = document.getElementById('apptTypeSelector');
    const addApptTypeBtn = document.getElementById('addApptTypeItem');
    if (addApptTypeBtn) {
        addApptTypeBtn.addEventListener('click', function() {
            const opt = apptTypeSelector.options[apptTypeSelector.selectedIndex];
            if (!opt.value) return;
            addLineItem(opt.dataset.name, 1, opt.dataset.price, '', opt.value);
            apptTypeSelector.selectedIndex = 0;
        });
    }

    // Recalculate on input changes in existing line items
    document.getElementById('lineItemsContainer').addEventListener('input', calculateTotal);
});
</script>

<?php include '../backend/includes/footer.php'; ?>
