<?php
/**
 * Brook's Dog Training Academy - Add/Edit Bundled Package
 * Define a package template with per-session-type credit allocations
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/package_checkout.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);
$is_edit = $id > 0;

// Handle delete
if ($is_edit && isset($_GET['delete'])) {
    // Prevent deletion if any client has purchased this package
    $stmt = $conn->prepare("SELECT COUNT(*) FROM client_packages WHERE package_id = ?");
    $stmt->execute([$id]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Cannot delete: clients have already purchased this package.'];
    } else {
        $conn->prepare("DELETE FROM package_items WHERE package_id = ?")->execute([$id]);
        $conn->prepare("DELETE FROM packages WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Package deleted successfully.'];
    }
    header('Location: packages_list.php');
    exit;
}

// Load existing package
$package = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM packages WHERE id = ?");
    $stmt->execute([$id]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$package) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Package not found.'];
        header('Location: packages_list.php');
        exit;
    }
    // Ensure the package has a share token
    if (empty($package['share_token'])) {
        $new_token = bin2hex(random_bytes(16));
        $conn->prepare("UPDATE packages SET share_token=? WHERE id=?")->execute([$new_token, $id]);
        $package['share_token'] = $new_token;
    }
}

// Map existing items by appointment_type_id for pre-fill on edit
/** @var array<int, int> $existing_items */
$existing_items = [];
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id = ?");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $existing_items[array_int_value($item, 'appointment_type_id')] = array_int_value($item, 'quantity');
    }
}

if ($is_edit && !empty($existing_items)) {
    $stmt = $conn->prepare("
        SELECT at.id, at.name, at.is_active, at.contract_template_id, ct.name AS contract_template_name
        FROM appointment_types at
        LEFT JOIN package_items pi ON pi.appointment_type_id = at.id AND pi.package_id = ?
        LEFT JOIN contract_templates ct ON at.contract_template_id = ct.id AND ct.is_active = 1
        WHERE at.is_active = 1 OR pi.package_id IS NOT NULL
        ORDER BY at.name
    ");
    $stmt->execute([$id]);
} else {
    $stmt = $conn->query("
        SELECT at.id, at.name, at.is_active, at.contract_template_id, ct.name AS contract_template_name
        FROM appointment_types at
        LEFT JOIN contract_templates ct ON at.contract_template_id = ct.id AND ct.is_active = 1
        WHERE at.is_active = 1
        ORDER BY at.name
    ");
}
$appointment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

$package_form_template_id = $is_edit ? safe_int($package['form_template_id'] ?? 0) : 0;
$package_form_options = bdta_get_package_checkout_form_options($conn, $package_form_template_id);
$available_package_forms = $package_form_options['forms'];
$selected_package_form = $package_form_options['selected_form'];
$selected_package_form_is_valid = $package_form_options['selected_form_is_valid'];

/**
 * @param list<array<string, mixed>> $appointment_types_rows
 * @param array<int, int> $selected_items
 * @return list<array{id: int, name: string, appointment_types: list<string>}>
 */
function package_edit_contract_summary(array $appointment_types_rows, array $selected_items): array {
    /** @var array<int, array{id: int, name: string, appointment_types: list<string>}> $grouped */
    $grouped = [];
    foreach ($appointment_types_rows as $appointment_type) {
        $appointment_type_id = array_int_value($appointment_type, 'id');
        if (($selected_items[$appointment_type_id] ?? 0) <= 0) {
            continue;
        }

        $contract_template_id = array_int_value($appointment_type, 'contract_template_id');
        $contract_template_name = array_string_value($appointment_type, 'contract_template_name');
        if ($contract_template_id <= 0 || $contract_template_name === '') {
            continue;
        }

        if (!isset($grouped[$contract_template_id])) {
            $grouped[$contract_template_id] = [
                'id' => $contract_template_id,
                'name' => $contract_template_name,
                'appointment_types' => [],
            ];
        }

        $appointment_type_name = array_string_value($appointment_type, 'name');
        if (!in_array($appointment_type_name, $grouped[$contract_template_id]['appointment_types'], true)) {
            $grouped[$contract_template_id]['appointment_types'][] = $appointment_type_name;
        }
    }

    return array_values($grouped);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name            = trim(scalar_string($_POST['name'] ?? ''));
    $description     = trim(scalar_string($_POST['description'] ?? ''));
    $price           = safe_float($_POST['price'] ?? 0);
    $expiration_days = !empty($_POST['expiration_days']) ? safe_int($_POST['expiration_days']) : null;
    $is_active       = isset($_POST['is_active']) ? 1 : 0;
    $form_template_id_value = safe_int($_POST['form_template_id'] ?? 0);
    $form_template_id = $form_template_id_value > 0 ? $form_template_id_value : null;

    // Validate
    $errors = [];
    if ($name === '') {
        $errors[] = 'Package name is required.';
    }

    // Build items: only appointment types with quantity > 0
    $items = [];
    foreach ($appointment_types as $apt) {
        $apt_id = array_int_value($apt, 'id');
        $qty = safe_int($_POST['qty_' . $apt_id] ?? 0);
        if ($qty > 0) {
            $items[$apt_id] = $qty;
        }
    }
    if (empty($items)) {
        $errors[] = 'At least one appointment type must have a quantity greater than zero.';
    }
    if ($form_template_id !== null) {
        $form_is_allowed = false;
        foreach ($available_package_forms as $available_package_form) {
            if (safe_int($available_package_form['id'] ?? 0) === $form_template_id) {
                $form_is_allowed = true;
                break;
            }
        }
        if (!$form_is_allowed) {
            $errors[] = 'Please choose a valid client-facing form template.';
        }
    }

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            if ($is_edit) {
                $stmt = $conn->prepare("
                    UPDATE packages SET name=?, description=?, price=?, expiration_days=?, is_active=?, form_template_id=?,
                    updated_at=CURRENT_TIMESTAMP WHERE id=?
                ");
                $stmt->execute([$name, $description, $price, $expiration_days, $is_active, $form_template_id, $id]);
                // Replace items
                $conn->prepare("DELETE FROM package_items WHERE package_id = ?")->execute([$id]);
                // Regenerate share token if requested
                if (isset($_POST['regenerate_token'])) {
                    $new_token = bin2hex(random_bytes(16));
                    $conn->prepare("UPDATE packages SET share_token=? WHERE id=?")->execute([$new_token, $id]);
                }
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Package updated successfully!'];
            } else {
                $share_token = bin2hex(random_bytes(16));
                $stmt = $conn->prepare("
                    INSERT INTO packages (name, description, price, expiration_days, is_active, share_token, form_template_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $price, $expiration_days, $is_active, $share_token, $form_template_id]);
                $id = $conn->lastInsertId();
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Package created successfully!'];
            }

            // Insert items
            $item_stmt = $conn->prepare("INSERT INTO package_items (package_id, appointment_type_id, quantity) VALUES (?, ?, ?)");
            foreach ($items as $apt_type_id => $qty) {
                $item_stmt->execute([$id, $apt_type_id, $qty]);
            }

            $conn->commit();
            header('Location: packages_list.php');
            exit;
        } catch (PDOException $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = 'Error saving package: ' . $e->getMessage();
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

$selected_items_for_preview = $_SERVER['REQUEST_METHOD'] === 'POST' ? $items : $existing_items;
$package_contracts_preview = package_edit_contract_summary($appointment_types, $selected_items_for_preview);

$page_title = $is_edit ? 'Edit Package' : 'Add Package';
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="packages_list.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Packages
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><?= $is_edit ? 'Edit' : 'Add' ?> Bundled Package</h5>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= scalar_string($error) ?></div>
            <?php endif; ?>
            <?php if ($is_edit && $package_form_template_id > 0 && !$selected_package_form_is_valid): ?>
                <div class="alert alert-warning">
                    The previously attached checkout form
                    <strong><?= escape(array_string_value($selected_package_form ?? [], 'name', 'Unknown form')) ?></strong>
                    is no longer eligible for public package checkout and will not be shown to buyers. Choose a client-facing active form or clear the selection.
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <h6 class="border-bottom pb-2 mb-3">Package Details</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Package Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="<?= htmlspecialchars($package['name'] ?? '') ?>" required>
                        <div class="form-text">e.g., "Starter Bundle", "Premium Pack"</div>
                    </div>
                    <div class="col-md-3">
                        <label for="price" class="form-label">Price ($)</label>
                        <input type="number" class="form-control" id="price" name="price"
                               value="<?= $package['price'] ?? '0.00' ?>" min="0" step="0.01">
                        <div class="form-text">Sale price of this package</div>
                    </div>
                    <div class="col-md-3">
                        <label for="expiration_days" class="form-label">Expiration (days)</label>
                        <input type="number" class="form-control" id="expiration_days" name="expiration_days"
                               value="<?= $package['expiration_days'] ?? '' ?>" min="1" placeholder="Leave blank = never">
                        <div class="form-text">Credits expire N days after purchase</div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($package['description'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label for="form_template_id" class="form-label">Attached Checkout Form</label>
                        <select class="form-select" id="form_template_id" name="form_template_id">
                            <option value="">— None —</option>
                            <?php foreach ($available_package_forms as $available_package_form): ?>
                                <?php $available_form_id = safe_int($available_package_form['id'] ?? 0); ?>
                                <option value="<?= $available_form_id ?>"
                                    <?= safe_int($_POST['form_template_id'] ?? ($package['form_template_id'] ?? 0)) === $available_form_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(array_string_value($available_package_form, 'name')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Optionally require any active client-facing form during package checkout. Admin-only forms are excluded.</div>
                        <?php if ($available_package_forms === []): ?>
                            <div class="form-text text-warning mt-1">
                                No eligible client-facing forms are available yet.
                                <a href="form_templates_edit.php">Create a form template</a> and choose a client-facing form type.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Appointment-Type Credit Allocations</h6>
                <p class="text-muted">Set the number of credits each appointment type contributes to this package. Leave at 0 to exclude a type.</p>
                <?php if (empty($appointment_types)): ?>
                    <div class="alert alert-warning">No active appointment types found. <a href="appointment_types_list.php">Create appointment types first.</a></div>
                <?php else: ?>
                <div class="row g-3 mb-4">
                    <?php
                        $is_post = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
                        foreach ($appointment_types as $apt):
                            $apt_id = array_int_value($apt, 'id');
                            $qty = $is_post ? safe_int($_POST['qty_' . $apt_id] ?? 0) : ($existing_items[$apt_id] ?? 0);
                    ?>
                    <div class="col-md-3 col-sm-6">
                            <div class="card h-100 <?= $qty > 0 ? 'border-primary' : '' ?>" id="card_<?= $apt_id ?>">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-check fa-2x mb-2 text-muted"></i>
                                <h6 class="card-title"><?= htmlspecialchars(array_string_value($apt, 'name')) ?></h6>
                                <?php if (!array_int_value($apt, 'is_active', 1)): ?>
                                    <div class="small text-muted mb-2">(inactive, still included in this package)</div>
                                <?php endif; ?>
                                <?php if (array_int_value($apt, 'contract_template_id') > 0 && array_string_value($apt, 'contract_template_name') !== ''): ?>
                                    <div class="small text-warning-emphasis mb-2">
                                        <i class="fas fa-file-signature me-1"></i><?= escape(array_string_value($apt, 'contract_template_name')) ?>
                                    </div>
                                <?php endif; ?>
                                <input type="number" class="form-control form-control-lg text-center"
                                       id="qty_<?= $apt_id ?>" name="qty_<?= $apt_id ?>"
                                       value="<?= $qty ?>" min="0" max="100"
                                       onchange="highlightCard('<?= $apt_id ?>', this.value)">
                                <div class="form-text">credits</div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <h6 class="border-bottom pb-2 mb-3">Contract Disclosure Preview</h6>
                <?php if (empty($package_contracts_preview)): ?>
                    <div class="alert alert-light border mb-4">No required appointment-type contracts are currently included in this package selection.</div>
                <?php else: ?>
                    <div class="alert alert-warning mb-4">
                        Clients will see these contract requirements before purchase, grouped by unique contract template.
                        <?php foreach ($package_contracts_preview as $contract_preview): ?>
                            <div class="mt-2">
                                <strong><?= escape($contract_preview['name']) ?></strong>
                                <span class="text-muted">— <?= escape(implode(', ', $contract_preview['appointment_types'])) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <h6 class="border-bottom pb-2 mb-3">Status</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?= !isset($package) || !empty($package['is_active']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                            <div class="form-text">Only active packages can be assigned to clients</div>
                        </div>
                    </div>
                </div>

                <?php if ($is_edit && !empty($package['share_token'])): ?>
                <h6 class="border-bottom pb-2 mb-3">Shareable Link</h6>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <p class="text-muted mb-2">Share this link with clients so they can view the package details and purchase it directly.</p>
                        <?php $share_url = getDynamicBaseUrl() . '/client/package_detail.php?token=' . $package['share_token']; ?>
                        <div class="input-group">
                            <input type="text" class="form-control" id="share_link_input" value="<?= htmlspecialchars($share_url) ?>" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyShareLink()">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                            <a href="<?= htmlspecialchars($share_url) ?>" target="_blank" class="btn btn-outline-primary">
                                <i class="fas fa-external-link-alt"></i> Preview
                            </a>
                        </div>
                        <div class="mt-2">
                            <button type="submit" name="regenerate_token" value="1" class="btn btn-sm btn-outline-warning"
                                    onclick="return confirm('Regenerate the shareable link? The old link will stop working.')">
                                <i class="fas fa-rotate"></i> Regenerate Link
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> <?= $is_edit ? 'Update' : 'Create' ?> Package
                    </button>
                    <a href="packages_list.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function highlightCard(type, value) {
    const card = document.getElementById('card_' + type);
    if (parseInt(value) > 0) {
        card.classList.add('border-primary');
    } else {
        card.classList.remove('border-primary');
    }
}

function copyShareLink() {
    const input = document.getElementById('share_link_input');
    navigator.clipboard.writeText(input.value).then(function() {
        const btn = input.nextElementSibling;
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.classList.replace('btn-outline-secondary', 'btn-success');
        setTimeout(function() {
            btn.innerHTML = orig;
            btn.classList.replace('btn-success', 'btn-outline-secondary');
        }, 2000);
    }).catch(function() {
        prompt('Copy this link:', input.value);
    });
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
