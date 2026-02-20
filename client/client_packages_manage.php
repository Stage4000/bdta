<?php
/**
 * Brook's Dog Training Academy - Client Package Management
 * Assign bundled packages to a client and view per-type credit breakdown
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
if (!$client_id) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Invalid client ID.'];
    header('Location: clients_list.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Client not found.'];
    header('Location: clients_list.php');
    exit;
}

$session_type_labels = [
    'group'        => ['label' => 'Group Class',     'badge' => 'secondary', 'icon' => 'fas fa-users'],
    'mini'         => ['label' => 'Mini Session',    'badge' => 'info',      'icon' => 'fas fa-stopwatch'],
    'private'      => ['label' => 'Private Session', 'badge' => 'primary',   'icon' => 'fas fa-user'],
    'field_rental' => ['label' => 'Field Rental',    'badge' => 'warning text-dark', 'icon' => 'fas fa-tree'],
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_package') {
        $package_id = (int)$_POST['package_id'];
        $notes      = trim($_POST['notes'] ?? '');

        $stmt = $conn->prepare("SELECT * FROM packages WHERE id = ? AND is_active = 1");
        $stmt->execute([$package_id]);
        $package = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$package) {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Package not found or inactive.'];
        } else {
            $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id = ?");
            $stmt->execute([$package_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($items)) {
                $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Package has no items configured.'];
            } else {
                try {
                    $conn->beginTransaction();

                    // Calculate expiry
                    $expires_at = null;
                    if ($package['expiration_days']) {
                        $expires_at = date('Y-m-d H:i:s', strtotime('+' . $package['expiration_days'] . ' days'));
                    }

                    // Create client_packages record
                    $stmt = $conn->prepare("
                        INSERT INTO client_packages
                            (client_id, package_id, package_name, expires_at, is_active, notes, created_by)
                        VALUES (?, ?, ?, ?, 1, ?, ?)
                    ");
                    $stmt->execute([$client_id, $package_id, $package['name'], $expires_at, $notes, $_SESSION['admin_id']]);
                    $cp_id = $conn->lastInsertId();

                    // Create per-type credit rows
                    $credit_stmt = $conn->prepare("
                        INSERT INTO client_package_credits
                            (client_package_id, client_id, session_type, total_credits, used_credits)
                        VALUES (?, ?, ?, ?, 0)
                    ");
                    foreach ($items as $item) {
                        $credit_stmt->execute([$cp_id, $client_id, $item['session_type'], $item['quantity']]);
                    }

                    // Log transaction for each item type
                    $tx_stmt = $conn->prepare("
                        INSERT INTO package_credit_transactions
                            (client_package_credit_id, client_id, session_type, transaction_type, amount, notes, created_by)
                        VALUES (?, ?, ?, 'purchase', ?, ?, ?)
                    ");
                    // Re-fetch credit rows to get IDs
                    $cred_stmt = $conn->prepare("SELECT * FROM client_package_credits WHERE client_package_id = ?");
                    $cred_stmt->execute([$cp_id]);
                    foreach ($cred_stmt->fetchAll(PDO::FETCH_ASSOC) as $cred) {
                        $tx_stmt->execute([
                            $cred['id'], $client_id, $cred['session_type'],
                            $cred['total_credits'],
                            "Package '{$package['name']}' purchased",
                            $_SESSION['admin_id']
                        ]);
                    }

                    $conn->commit();
                    $_SESSION['flash'] = ['type' => 'success', 'message' => "Package '{$package['name']}' assigned successfully!"];
                } catch (PDOException $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Error assigning package: ' . $e->getMessage()];
                }
            }
        }

        header("Location: client_packages_manage.php?client_id=$client_id");
        exit;
    }

    if ($action === 'deactivate_package') {
        $cp_id = (int)$_POST['client_package_id'];
        $conn->prepare("UPDATE client_packages SET is_active = 0 WHERE id = ? AND client_id = ?")->execute([$cp_id, $client_id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Package deactivated.'];
        header("Location: client_packages_manage.php?client_id=$client_id");
        exit;
    }
}

// Fetch active packages for dropdown
$stmt = $conn->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY name");
$available_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all package items for the dropdown packages
$pkg_ids = array_column($available_packages, 'id');
$pkg_items_map = [];
if (!empty($pkg_ids)) {
    $ph = implode(',', array_fill(0, count($pkg_ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id IN ($ph) ORDER BY session_type");
    $stmt->execute($pkg_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $pkg_items_map[$item['package_id']][] = $item;
    }
}

// Fetch client's purchased packages
$stmt = $conn->prepare("
    SELECT cp.*, p.expiration_days
    FROM client_packages cp
    JOIN packages p ON cp.package_id = p.id
    WHERE cp.client_id = ?
    ORDER BY cp.purchased_at DESC
");
$stmt->execute([$client_id]);
$client_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch credit rows for each client package
$cp_ids = array_column($client_packages, 'id');
$credits_map = [];
if (!empty($cp_ids)) {
    $ph = implode(',', array_fill(0, count($cp_ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM client_package_credits WHERE client_package_id IN ($ph) ORDER BY session_type");
    $stmt->execute($cp_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cred) {
        $credits_map[$cred['client_package_id']][] = $cred;
    }
}

// Build summary of remaining credits per session type across all active packages
$summary = [];
foreach ($client_packages as $cp) {
    if (!$cp['is_active']) continue;
    // Check expiry
    if ($cp['expires_at'] && strtotime($cp['expires_at']) < time()) continue;
    foreach ($credits_map[$cp['id']] ?? [] as $cred) {
        $type = $cred['session_type'];
        if (!isset($summary[$type])) $summary[$type] = ['remaining' => 0, 'total' => 0, 'used' => 0];
        $summary[$type]['total']     += $cred['total_credits'];
        $summary[$type]['used']      += $cred['used_credits'];
        $summary[$type]['remaining'] += ($cred['total_credits'] - $cred['used_credits']);
    }
}

$page_title = "Package Credits – " . $client['name'];
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-3">
        <a href="clients_edit.php?id=<?= $client_id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Client
        </a>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="fas fa-box-open me-2"></i>Package Credits</h2>
            <p class="text-muted">Client: <strong><?= htmlspecialchars($client['name']) ?></strong></p>
        </div>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash']['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <!-- Credit Summary -->
    <div class="row mb-4">
        <?php if (empty($summary)): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    No active package credits. Assign a package below to get started.
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($session_type_labels as $type => $meta): ?>
                <?php if (!isset($summary[$type])) continue; ?>
                <div class="col-md-3 col-sm-6 mb-3">
                    <div class="card text-center h-100">
                        <div class="card-body">
                            <i class="<?= $meta['icon'] ?> fa-2x mb-2 text-muted"></i>
                            <h6 class="card-title"><?= $meta['label'] ?></h6>
                            <div class="display-6 fw-bold <?= $summary[$type]['remaining'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $summary[$type]['remaining'] ?>
                            </div>
                            <small class="text-muted">remaining of <?= $summary[$type]['total'] ?></small>
                            <div class="progress mt-2" style="height:6px;">
                                <?php $pct = $summary[$type]['total'] > 0 ? round(($summary[$type]['used'] / $summary[$type]['total']) * 100) : 0; ?>
                                <div class="progress-bar bg-<?= $meta['badge'] ?>" style="width:<?= $pct ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Assign Package -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Assign Package</h5>
        </div>
        <div class="card-body">
            <?php if (empty($available_packages)): ?>
                <p class="text-muted">No active packages available. <a href="packages_list.php">Create packages first.</a></p>
            <?php else: ?>
                <form method="POST" class="row g-3 align-items-end">
                    <input type="hidden" name="action" value="assign_package">

                    <div class="col-md-5">
                        <label for="package_id" class="form-label">Package <span class="text-danger">*</span></label>
                        <select name="package_id" id="package_id" class="form-select" required onchange="showPackagePreview(this)">
                            <option value="">Select package...</option>
                            <?php foreach ($available_packages as $pkg): ?>
                                <option value="<?= $pkg['id'] ?>"
                                        data-price="<?= $pkg['price'] ?>"
                                        data-expiry="<?= $pkg['expiration_days'] ?? '' ?>">
                                    <?= htmlspecialchars($pkg['name']) ?>
                                    <?= $pkg['price'] > 0 ? ' – $' . number_format($pkg['price'], 2) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-5">
                        <label for="notes" class="form-label">Notes</label>
                        <input type="text" class="form-control" id="notes" name="notes"
                               placeholder="e.g., Paid by cash, Invoice #123">
                    </div>

                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-check-circle"></i> Assign
                        </button>
                    </div>

                    <!-- Package preview -->
                    <div class="col-12" id="package_preview" style="display:none;">
                        <div class="alert alert-info py-2 mb-0">
                            <strong>Package Contents:</strong>
                            <span id="preview_items"></span>
                            <span id="preview_expiry"></span>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Purchased Packages History -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Purchased Packages</h5>
        </div>
        <div class="card-body">
            <?php if (empty($client_packages)): ?>
                <p class="text-muted">No packages purchased yet.</p>
            <?php else: ?>
                <?php foreach ($client_packages as $cp): ?>
                    <?php
                    $is_expired = $cp['expires_at'] && strtotime($cp['expires_at']) < time();
                    $status_class = !$cp['is_active'] ? 'secondary' : ($is_expired ? 'danger' : 'success');
                    $status_label = !$cp['is_active'] ? 'Deactivated' : ($is_expired ? 'Expired' : 'Active');
                    ?>
                    <div class="card mb-3 <?= $cp['is_active'] && !$is_expired ? '' : 'opacity-75' ?>">
                        <div class="card-header d-flex justify-content-between align-items-center py-2">
                            <div>
                                <strong><?= htmlspecialchars($cp['package_name']) ?></strong>
                                <span class="badge bg-<?= $status_class ?> ms-2"><?= $status_label ?></span>
                                <small class="text-muted ms-2">
                                    Assigned: <?= date('M j, Y', strtotime($cp['purchased_at'])) ?>
                                    <?php if ($cp['expires_at']): ?>
                                        | Expires: <?= date('M j, Y', strtotime($cp['expires_at'])) ?>
                                    <?php endif; ?>
                                </small>
                            </div>
                            <?php if ($cp['is_active'] && !$is_expired): ?>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Deactivate this package? Remaining credits will be forfeited.')">
                                    <input type="hidden" name="action" value="deactivate_package">
                                    <input type="hidden" name="client_package_id" value="<?= $cp['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="card-body py-2">
                            <div class="row g-2">
                                <?php foreach ($credits_map[$cp['id']] ?? [] as $cred): ?>
                                    <?php
                                    $remaining = $cred['total_credits'] - $cred['used_credits'];
                                    $meta = $session_type_labels[$cred['session_type']] ?? ['label' => ucfirst($cred['session_type']), 'badge' => 'secondary', 'icon' => 'fas fa-circle'];
                                    $pct = $cred['total_credits'] > 0 ? round(($cred['used_credits'] / $cred['total_credits']) * 100) : 0;
                                    ?>
                                    <div class="col-md-3 col-sm-6">
                                        <div class="border rounded p-2 text-center">
                                            <small class="text-muted d-block"><i class="<?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?></small>
                                            <span class="fs-5 fw-bold <?= $remaining > 0 ? 'text-success' : 'text-danger' ?>">
                                                <?= $remaining ?>
                                            </span>
                                            <small class="text-muted"> / <?= $cred['total_credits'] ?></small>
                                            <div class="progress mt-1" style="height:4px;">
                                                <div class="progress-bar bg-<?= $meta['badge'] ?>" style="width:<?= $pct ?>%"></div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($cp['notes']): ?>
                                <small class="text-muted mt-2 d-block">Note: <?= htmlspecialchars($cp['notes']) ?></small>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const pkgItems = <?= json_encode($pkg_items_map) ?>;
const sessionTypeLabels = <?= json_encode(array_map(fn($m) => $m['label'], $session_type_labels)) ?>;

function showPackagePreview(select) {
    const pkgId = select.value;
    const preview = document.getElementById('package_preview');
    const previewItems = document.getElementById('preview_items');
    const previewExpiry = document.getElementById('preview_expiry');

    if (!pkgId || !pkgItems[pkgId]) {
        preview.style.display = 'none';
        return;
    }

    const items = pkgItems[pkgId];
    let html = '';
    items.forEach(function(item) {
        const label = sessionTypeLabels[item.session_type] || item.session_type;
        html += ' <strong>' + item.quantity + '× ' + label + '</strong>';
    });
    previewItems.innerHTML = html;

    const opt = select.options[select.selectedIndex];
    const expiry = opt.dataset.expiry;
    previewExpiry.textContent = expiry ? ' · Expires in ' + expiry + ' days after assignment.' : '';

    preview.style.display = 'block';
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
