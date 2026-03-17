<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/invoice_time_entries.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$preset_client_id = safe_int($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$requested_time_entry_ids = bdta_parse_time_entry_ids($_GET['time_entry_ids'] ?? ($_POST['item_time_entry_id'] ?? []));
$requested_time_entries = bdta_get_invoiceable_time_entries($conn, $requested_time_entry_ids, $preset_client_id);
$issue_date_value = scalar_string($_POST['issue_date'] ?? date('Y-m-d'));
$due_date_value = scalar_string($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
$tax_rate_value = scalar_string($_POST['tax_rate'] ?? '0');
$notes_value = trim(scalar_string($_POST['notes'] ?? ''));
$use_installments_checked = !empty($_POST['use_installments']);
$installment_count_value = max(2, safe_int($_POST['installment_count'] ?? 2));
$installment_interval_value_form = max(1, safe_int($_POST['installment_interval_value'] ?? 1));
$installment_interval_type_value = in_array($_POST['installment_interval_type'] ?? '', ['days', 'weeks', 'months'], true)
    ? scalar_string($_POST['installment_interval_type'])
    : 'months';

$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load active packages for the package selector
$packages_stmt = $conn->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY name");
$packages = $packages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load active appointment types for the appointment type selector
$appt_types_stmt = $conn->query("SELECT id, name, default_amount FROM appointment_types WHERE is_active = 1 ORDER BY name");
$appt_types = $appt_types_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = scalar_string($_POST['csrf_token'] ?? '');
    if ($csrf_token === '' || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), $csrf_token)) {
        setFlashMessage('Invalid request.', 'danger');
    } else {
        $client_id = safe_int($_POST['client_id'] ?? 0);
        $issue_date = scalar_string($_POST['issue_date'] ?? date('Y-m-d'));
        $due_date = scalar_string($_POST['due_date'] ?? date('Y-m-d', strtotime('+30 days')));
        $tax_rate = safe_float($_POST['tax_rate'] ?? 0);
        $notes = trim(scalar_string($_POST['notes'] ?? ''));

        // Installment configuration
        $use_installments = !empty($_POST['use_installments']);
        $installment_count = max(2, safe_int($_POST['installment_count'] ?? 2));
        $installment_interval_value = max(1, safe_int($_POST['installment_interval_value'] ?? 1));
        $installment_interval_type = in_array($_POST['installment_interval_type'] ?? '', ['days', 'weeks', 'months'], true)
            ? scalar_string($_POST['installment_interval_type'])
            : 'months';

        // Calculate totals from posted items
        $subtotal = 0;
        $items = [];
        $posted_item_desc = $_POST['item_desc'] ?? [];
        $posted_item_qty = $_POST['item_qty'] ?? [];
        $posted_item_rate = $_POST['item_rate'] ?? [];
        $posted_item_package_id = $_POST['item_package_id'] ?? [];
        $posted_item_appointment_type_id = $_POST['item_appointment_type_id'] ?? [];
        $posted_item_time_entry_id = $_POST['item_time_entry_id'] ?? [];
        if (!is_array($posted_item_desc)) {
            $posted_item_desc = [];
        }
        if (!is_array($posted_item_qty)) {
            $posted_item_qty = [];
        }
        if (!is_array($posted_item_rate)) {
            $posted_item_rate = [];
        }
        if (!is_array($posted_item_package_id)) {
            $posted_item_package_id = [];
        }
        if (!is_array($posted_item_appointment_type_id)) {
            $posted_item_appointment_type_id = [];
        }
        if (!is_array($posted_item_time_entry_id)) {
            $posted_item_time_entry_id = [];
        }

        $valid_time_entries = bdta_get_invoiceable_time_entries(
            $conn,
            bdta_parse_time_entry_ids($posted_item_time_entry_id),
            $client_id
        );
        $valid_time_entry_ids = [];
        foreach ($valid_time_entries as $valid_time_entry) {
            $valid_time_entry_ids[safe_int($valid_time_entry['id'] ?? 0)] = true;
        }

        $time_entry_ids_to_mark = [];
        $invalid_time_entry_ids = [];

        if ($posted_item_desc !== []) {
            foreach ($posted_item_desc as $index => $desc) {
                $description = scalar_string($desc);
                if ($description !== '') {
                    $qty = safe_float($posted_item_qty[$index] ?? 1);
                    $rate = safe_float($posted_item_rate[$index] ?? 0);
                    $amount = $qty * $rate;
                    $subtotal += $amount;

                    $item_type = 'custom';
                    $reference_id = null;
                    if (!empty($posted_item_package_id[$index])) {
                        $item_type = 'package';
                        $reference_id = safe_int($posted_item_package_id[$index]);
                    } elseif (!empty($posted_item_appointment_type_id[$index])) {
                        $item_type = 'appointment_type';
                        $reference_id = safe_int($posted_item_appointment_type_id[$index]);
                    } elseif (!empty($posted_item_time_entry_id[$index])) {
                        $time_entry_id = safe_int($posted_item_time_entry_id[$index]);
                    if (isset($valid_time_entry_ids[$time_entry_id])) {
                        $item_type = 'time_entry';
                        $reference_id = $time_entry_id;
                        $time_entry_ids_to_mark[$time_entry_id] = $time_entry_id;
                    } else {
                        $invalid_time_entry_ids[$time_entry_id] = $time_entry_id;
                    }
                }

                    $items[] = [
                        'description' => $description,
                        'quantity'    => $qty,
                        'rate'        => $rate,
                        'amount'      => $amount,
                        'item_type'   => $item_type,
                        'reference_id'=> $reference_id,
                    ];
                }
            }
        }

        $tax_amount = $subtotal * ($tax_rate / 100);
        $total_amount = $subtotal + $tax_amount;

        if ($invalid_time_entry_ids !== []) {
            setFlashMessage('One or more selected time entries are no longer invoiceable. Please refresh and try again.', 'danger');
        } elseif ($client_id && !empty($items)) {
            $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
            $invoice_id = null;

            try {
                $conn->beginTransaction();

                // Insert invoice
                $stmt = $conn->prepare("
                    INSERT INTO invoices (invoice_number, client_id, issue_date, due_date, subtotal, tax_rate, tax_amount, total_amount, notes, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
                ");
                $stmt->execute([$invoice_number, $client_id, $issue_date, $due_date, $subtotal, $tax_rate, $tax_amount, $total_amount, $notes]);
                $invoice_id = $conn->lastInsertId();

                // Insert invoice items
                $item_stmt = $conn->prepare("
                    INSERT INTO invoice_items (invoice_id, item_type, reference_id, description, quantity, rate, amount)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($items as $item) {
                    $item_stmt->execute([$invoice_id, $item['item_type'], $item['reference_id'], $item['description'], $item['quantity'], $item['rate'], $item['amount']]);
                }

                bdta_mark_time_entries_invoiced($conn, array_values($time_entry_ids_to_mark), $client_id);

                // Insert installments if enabled
                if ($use_installments) {
                    $inst_amount = round($total_amount / $installment_count, 2);
                    // Correct for rounding on last installment
                    $last_amount = $total_amount - ($inst_amount * ($installment_count - 1));
                    $inst_stmt = $conn->prepare("
                        INSERT INTO invoice_installments (invoice_id, installment_number, amount, due_date, status)
                        VALUES (?, ?, ?, ?, 'unpaid')
                    ");
                    for ($i = 1; $i <= $installment_count; $i++) {
                        $interval = (($i - 1) * $installment_interval_value) . ' ' . $installment_interval_type;
                        $inst_due = date('Y-m-d', safe_timestamp(strtotime($due_date . ' +' . $interval)));
                        $amt = ($i === $installment_count) ? $last_amount : $inst_amount;
                        $inst_stmt->execute([$invoice_id, $i, $amt, $inst_due]);
                    }
                }

                $conn->commit();
            } catch (Throwable $e) {
                $invoice_id = null;
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                setFlashMessage('Error creating invoice: ' . $e->getMessage(), 'danger');
            }

            if ($invoice_id !== null) {
                setFlashMessage('Invoice created successfully!', 'success');
                redirect('invoices_view.php?id=' . $invoice_id);
            }
        } elseif ($client_id === 0 || $items === []) {
            setFlashMessage('Please select a client and add at least one invoice item.', 'danger');
        }
    }
}

$form_items = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_item_desc = is_array($_POST['item_desc'] ?? null) ? $_POST['item_desc'] : [];
    foreach ($posted_item_desc as $index => $description) {
        $form_items[] = [
            'description' => scalar_string($description),
            'quantity' => scalar_string($_POST['item_qty'][$index] ?? '1'),
            'rate' => scalar_string($_POST['item_rate'][$index] ?? ''),
            'package_id' => scalar_string($_POST['item_package_id'][$index] ?? ''),
            'appointment_type_id' => scalar_string($_POST['item_appointment_type_id'][$index] ?? ''),
            'time_entry_id' => scalar_string($_POST['item_time_entry_id'][$index] ?? ''),
        ];
    }
} elseif ($requested_time_entries !== []) {
    foreach ($requested_time_entries as $time_entry) {
        $hours = number_format(safe_float($time_entry['duration_minutes'] ?? 0) / 60, 2, '.', '');
        $form_items[] = [
            'description' => bdta_build_time_entry_invoice_description($time_entry),
            'quantity' => $hours,
            'rate' => number_format(safe_float($time_entry['hourly_rate'] ?? 0), 2, '.', ''),
            'package_id' => '',
            'appointment_type_id' => '',
            'time_entry_id' => (string) safe_int($time_entry['id'] ?? 0),
        ];
    }
}

if ($form_items === []) {
    $form_items[] = [
        'description' => '',
        'quantity' => '1',
        'rate' => '',
        'package_id' => '',
        'appointment_type_id' => '',
        'time_entry_id' => '',
    ];
}

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <h2 class="mb-4"><i class="fas fa-file-invoice me-2"></i>Create Invoice</h2>
            
            <div class="card">
                <div class="card-body">
                    <form method="POST" id="invoiceForm">
                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Client *</label>
                                <select class="form-select" name="client_id" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>" <?= $preset_client_id === safe_int($client['id']) ? 'selected' : '' ?>><?= escape($client['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Issue Date *</label>
                                <input type="date" class="form-control" name="issue_date" value="<?= escape($issue_date_value) ?>" required>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Due Date *</label>
                                <input type="date" class="form-control" name="due_date" id="dueDate" value="<?= escape($due_date_value) ?>" required>
                            </div>
                        </div>

                        <?php if ($requested_time_entries !== []): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-file-invoice-dollar me-1"></i>
                            Invoice will include <?= count($requested_time_entries) ?> selected billable time entr<?= count($requested_time_entries) === 1 ? 'y' : 'ies' ?>.
                        </div>
                        <?php endif; ?>

                        <!-- Package Selector -->
                        <?php if (!empty($packages)): ?>
                        <div class="mb-3">
                            <label class="form-label">Add Package</label>
                            <div class="input-group">
                                <select class="form-select" id="packageSelector">
                                    <option value="">— Select a package to add —</option>
                                    <?php foreach ($packages as $pkg): ?>
                                        <option value="<?= $pkg['id'] ?>"
                                                data-name="<?= escape($pkg['name']) ?>"
                                                data-price="<?= safe_float($pkg['price']) ?>">
                                            <?= escape($pkg['name']) ?> — $<?= number_format(safe_float($pkg['price']), 2) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" id="addPackageItem">
                                    <i class="fas fa-plus"></i> Add to Invoice
                                </button>
                            </div>
                            <small class="text-muted">Package credits will be automatically applied to the client when this invoice is paid.</small>
                        </div>
                        <?php endif; ?>

                        <!-- Appointment Type Selector -->
                        <?php if (!empty($appt_types)): ?>
                        <div class="mb-3">
                            <label class="form-label">Add Appointment Type</label>
                            <div class="input-group">
                                <select class="form-select" id="apptTypeSelector">
                                    <option value="">— Select an appointment type to add —</option>
                                    <?php foreach ($appt_types as $at): ?>
                                        <option value="<?= $at['id'] ?>"
                                                data-name="<?= escape($at['name']) ?>"
                                                data-price="<?= floatval($at['default_amount'] ?? 0) ?>">
                                            <?= escape($at['name']) ?><?= safe_float($at['default_amount']) > 0 ? ' — $' . number_format(safe_float($at['default_amount']), 2) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary" id="addApptTypeItem">
                                    <i class="fas fa-plus"></i> Add to Invoice
                                </button>
                            </div>
                            <small class="text-muted">Individual appointment type billed at its default rate.</small>
                        </div>
                        <?php endif; ?>
                        
                        <h5 class="mt-4 mb-3">Line Items</h5>
                        <div id="lineItems">
                            <?php foreach ($form_items as $form_item): ?>
                            <div class="row mb-2 line-item">
                                <div class="col-md-5">
                                    <input type="text" class="form-control" name="item_desc[]" placeholder="Description" value="<?= escape($form_item['description']) ?>" required>
                                    <input type="hidden" name="item_package_id[]" value="<?= escape($form_item['package_id']) ?>">
                                    <input type="hidden" name="item_appointment_type_id[]" value="<?= escape($form_item['appointment_type_id']) ?>">
                                    <input type="hidden" name="item_time_entry_id[]" value="<?= escape($form_item['time_entry_id']) ?>">
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control item-qty" name="item_qty[]" placeholder="Qty" value="<?= escape($form_item['quantity']) ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" step="0.01" class="form-control item-rate" name="item_rate[]" placeholder="Rate" value="<?= escape($form_item['rate']) ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <input type="text" class="form-control item-amount" placeholder="Amount" readonly>
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-danger btn-sm remove-item">×</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <button type="button" class="btn btn-secondary btn-sm mb-3" id="addItem">
                            <i class="fas fa-plus"></i> Add Line Item
                        </button>
                        
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <textarea class="form-control" name="notes" rows="3"><?= escape($notes_value) ?></textarea>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-2">
                                    <label class="form-label">Tax Rate (%)</label>
                                    <input type="number" step="0.01" class="form-control" name="tax_rate" id="taxRate" value="<?= escape($tax_rate_value) ?>">
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

                        <!-- Installment Plan -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" name="use_installments" id="useInstallments" value="1" <?= $use_installments_checked ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-semibold" for="useInstallments">
                                        <i class="fas fa-calendar-check me-1"></i> Enable Installment Payments
                                    </label>
                                </div>
                            </div>
                            <div class="card-body <?= $use_installments_checked ? '' : 'd-none' ?>" id="installmentOptions">
                                <div class="row g-3 align-items-end">
                                    <div class="col-md-3">
                                        <label class="form-label">Number of Installments</label>
                                        <input type="number" class="form-control" name="installment_count" id="installmentCount" value="<?= $installment_count_value ?>" min="2" max="60">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Interval</label>
                                        <input type="number" class="form-control" name="installment_interval_value" id="installmentIntervalValue" value="<?= $installment_interval_value_form ?>" min="1" max="365">
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Interval Type</label>
                                        <select class="form-select" name="installment_interval_type" id="installmentIntervalType">
                                            <option value="months" <?= $installment_interval_type_value === 'months' ? 'selected' : '' ?>>Month(s)</option>
                                            <option value="weeks" <?= $installment_interval_type_value === 'weeks' ? 'selected' : '' ?>>Week(s)</option>
                                            <option value="days" <?= $installment_interval_type_value === 'days' ? 'selected' : '' ?>>Day(s)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="button" class="btn btn-outline-secondary w-100" id="previewInstallments">
                                            <i class="fas fa-eye"></i> Preview Schedule
                                        </button>
                                    </div>
                                </div>
                                <div id="installmentPreview" class="mt-3"></div>
                            </div>
                        </div>
                        
                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-floppy-disk"></i> Create Invoice
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
        newItem.querySelectorAll('input').forEach(input => {
            if (input.type === 'hidden' || input.classList.contains('item-amount')) {
                input.value = '';
            } else {
                input.value = input.placeholder === 'Qty' ? '1' : '';
            }
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

    // Package selector
    const packageSelector = document.getElementById('packageSelector');
    const addPackageBtn = document.getElementById('addPackageItem');
    if (addPackageBtn) {
        addPackageBtn.addEventListener('click', function() {
            const opt = packageSelector.options[packageSelector.selectedIndex];
            if (!opt.value) return;
            const name = opt.dataset.name;
            const price = parseFloat(opt.dataset.price) || 0;
            const pkgId = opt.value;

            const newItem = lineItems.firstElementChild.cloneNode(true);
            newItem.querySelector('input[name="item_desc[]"]').value = name;
            newItem.querySelector('.item-qty').value = '1';
            newItem.querySelector('.item-rate').value = price.toFixed(2);
            newItem.querySelector('input[name="item_package_id[]"]').value = pkgId;
            newItem.querySelector('input[name="item_appointment_type_id[]"]').value = '';
            newItem.querySelector('input[name="item_time_entry_id[]"]').value = '';
            lineItems.appendChild(newItem);
            packageSelector.selectedIndex = 0;
            calculateTotals();
        });
    }

    // Appointment type selector
    const apptTypeSelector = document.getElementById('apptTypeSelector');
    const addApptTypeBtn = document.getElementById('addApptTypeItem');
    if (addApptTypeBtn) {
        addApptTypeBtn.addEventListener('click', function() {
            const opt = apptTypeSelector.options[apptTypeSelector.selectedIndex];
            if (!opt.value) return;
            const name = opt.dataset.name;
            const price = parseFloat(opt.dataset.price) || 0;
            const atId = opt.value;

            const newItem = lineItems.firstElementChild.cloneNode(true);
            newItem.querySelector('input[name="item_desc[]"]').value = name;
            newItem.querySelector('.item-qty').value = '1';
            newItem.querySelector('.item-rate').value = price.toFixed(2);
            newItem.querySelector('input[name="item_package_id[]"]').value = '';
            newItem.querySelector('input[name="item_appointment_type_id[]"]').value = atId;
            newItem.querySelector('input[name="item_time_entry_id[]"]').value = '';
            lineItems.appendChild(newItem);
            apptTypeSelector.selectedIndex = 0;
            calculateTotals();
        });
    }

    // Installments toggle
    const useInstallmentsCheck = document.getElementById('useInstallments');
    const installmentOptions = document.getElementById('installmentOptions');
    useInstallmentsCheck.addEventListener('change', function() {
        installmentOptions.classList.toggle('d-none', !this.checked);
    });

    // Installment preview
    document.getElementById('previewInstallments').addEventListener('click', function() {
        const count = parseInt(document.getElementById('installmentCount').value) || 2;
        const intervalVal = parseInt(document.getElementById('installmentIntervalValue').value) || 1;
        const intervalType = document.getElementById('installmentIntervalType').value;
        const dueDate = document.getElementById('dueDate').value;
        const totalText = document.getElementById('totalDisplay').textContent.replace('$', '');
        const total = parseFloat(totalText) || 0;

        if (!dueDate || total <= 0) {
            document.getElementById('installmentPreview').innerHTML = '<p class="text-warning">Please set a due date and invoice total first.</p>';
            return;
        }

        const instAmount = Math.round((total / count) * 100) / 100;
        let rows = '<table class="table table-sm table-bordered mt-2"><thead class="table-light"><tr><th>#</th><th>Amount</th><th>Due Date</th></tr></thead><tbody>';
        let runningTotal = 0;
        for (let i = 1; i <= count; i++) {
            const date = new Date(dueDate);
            const offset = (i - 1) * intervalVal;
            if (intervalType === 'months') date.setMonth(date.getMonth() + offset);
            else if (intervalType === 'weeks') date.setDate(date.getDate() + offset * 7);
            else date.setDate(date.getDate() + offset);
            const amt = (i === count) ? Math.round((total - runningTotal) * 100) / 100 : instAmount;
            runningTotal += amt;
            rows += `<tr><td>${i}</td><td>$${amt.toFixed(2)}</td><td>${date.toLocaleDateString('en-US', {year:'numeric',month:'short',day:'numeric'})}</td></tr>`;
        }
        rows += '</tbody></table>';
        document.getElementById('installmentPreview').innerHTML = rows;
    });

    calculateTotals();
});
</script>

<?php include '../backend/includes/footer.php'; ?>
