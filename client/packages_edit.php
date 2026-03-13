<?php
/**
 * Brook's Dog Training Academy - Add/Edit Bundled Package
 * Define a package template with per-session-type credit allocations
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

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

$stmt = $conn->query("SELECT id, name FROM appointment_types WHERE is_active = 1 ORDER BY name");
$appointment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Map existing items by appointment_type_id for pre-fill on edit
$existing_items = [];
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id = ?");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $existing_items[(string) array_int_value($item, 'appointment_type_id')] = array_int_value($item, 'quantity');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name            = trim(scalar_string($_POST['name'] ?? ''));
    $description     = trim(scalar_string($_POST['description'] ?? ''));
    $price           = safe_float($_POST['price'] ?? 0);
    $expiration_days = !empty($_POST['expiration_days']) ? safe_int($_POST['expiration_days']) : null;
    $is_active       = isset($_POST['is_active']) ? 1 : 0;

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

    if (empty($errors)) {
        try {
            $conn->beginTransaction();

            if ($is_edit) {
                $stmt = $conn->prepare("
                    UPDATE packages SET name=?, description=?, price=?, expiration_days=?, is_active=?,
                    updated_at=CURRENT_TIMESTAMP WHERE id=?
                ");
                $stmt->execute([$name, $description, $price, $expiration_days, $is_active, $id]);
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
                    INSERT INTO packages (name, description, price, expiration_days, is_active, share_token)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $price, $expiration_days, $is_active, $share_token]);
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
                <div class="alert alert-danger"><?= $error ?></div>
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
                </div>

                <h6 class="border-bottom pb-2 mb-3">Appointment-Type Credit Allocations</h6>
                <p class="text-muted">Set the number of credits each appointment type contributes to this package. Leave at 0 to exclude a type.</p>
                <?php if (empty($appointment_types)): ?>
                    <div class="alert alert-warning">No active appointment types found. <a href="appointment_types_list.php">Create appointment types first.</a></div>
                <?php else: ?>
                <div class="row g-3 mb-4">
                    <?php foreach ($appointment_types as $apt):
                        $apt_id = array_int_value($apt, 'id');
                        $qty = $existing_items[(string) $apt_id] ?? safe_int($_POST['qty_' . $apt_id] ?? 0);
                    ?>
                    <div class="col-md-3 col-sm-6">
                        <div class="card h-100 <?= $qty > 0 ? 'border-primary' : '' ?>" id="card_<?= $apt_id ?>">
                            <div class="card-body text-center">
                                <i class="fas fa-calendar-check fa-2x mb-2 text-muted"></i>
                                <h6 class="card-title"><?= htmlspecialchars(array_string_value($apt, 'name')) ?></h6>
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
