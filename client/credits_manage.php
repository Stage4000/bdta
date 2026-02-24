<?php
/**
 * Credits Management Page
 * Unified interface for managing all client credits:
 *   - Legacy general credits (client_credits table)
 *   - Per-session-type package credits (client_package_credits table)
 * Admins can manually adjust both credit types with full audit logging.
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

// Check authentication
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get client ID from URL
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if (!$client_id) {
    $_SESSION['flash_message'] = "Invalid client ID.";
    $_SESSION['flash_type'] = "danger";
    header('Location: clients_list.php');
    exit;
}

// Get client info
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    $_SESSION['flash_message'] = "Client not found.";
    $_SESSION['flash_type'] = "danger";
    header('Location: clients_list.php');
    exit;
}

// Initialize client credits if not exists
$stmt = $conn->prepare("SELECT * FROM client_credits WHERE client_id = ?");
$stmt->execute([$client_id]);
$credits = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$credits) {
    $stmt = $conn->prepare("
        INSERT INTO client_credits (client_id, credit_balance, total_purchased, total_consumed, total_adjusted)
        VALUES (?, 0, 0, 0, 0)
    ");
    $stmt->execute([$client_id]);
    
    $stmt = $conn->prepare("SELECT * FROM client_credits WHERE client_id = ?");
    $stmt->execute([$client_id]);
    $credits = $stmt->fetch(PDO::FETCH_ASSOC);
}

$session_type_labels = [
    'group'        => ['label' => 'Group Class',     'badge' => 'secondary', 'icon' => 'fas fa-users'],
    'mini'         => ['label' => 'Mini Session',    'badge' => 'info',      'icon' => 'fas fa-stopwatch'],
    'private'      => ['label' => 'Private Session', 'badge' => 'primary',   'icon' => 'fas fa-user'],
    'field_rental' => ['label' => 'Field Rental',    'badge' => 'warning',   'icon' => 'fas fa-tree'],
];

// Internal name used to identify the system package that backs manual credit adjustments
define('MANUAL_CREDIT_PKG_NAME', '__manual_credit__');

/**
 * Get or create the special system package used for manual credit adjustments.
 * Returns the client_package_credits row ID for the given session type.
 */
function getOrCreateManualCreditRow(PDO $conn, int $client_id, string $session_type, int $admin_id): int {
    // Get or create the system-level "Manual Credit Adjustment" package
    $stmt = $conn->prepare("SELECT id FROM packages WHERE name = ? LIMIT 1");
    $stmt->execute([MANUAL_CREDIT_PKG_NAME]);
    $manual_pkg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manual_pkg) {
        $conn->prepare("
            INSERT INTO packages (name, description, price, is_active)
            VALUES (?, 'System record for manual credit adjustments', 0, 0)
        ")->execute([MANUAL_CREDIT_PKG_NAME]);
        $manual_pkg_id = (int)$conn->lastInsertId();
    } else {
        $manual_pkg_id = (int)$manual_pkg['id'];
    }

    // Get or create the client_packages record for this client + manual package
    $stmt = $conn->prepare("SELECT id FROM client_packages WHERE client_id = ? AND package_id = ? LIMIT 1");
    $stmt->execute([$client_id, $manual_pkg_id]);
    $manual_cp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$manual_cp) {
        $conn->prepare("
            INSERT INTO client_packages (client_id, package_id, package_name, is_active, notes, created_by)
            VALUES (?, ?, 'Manual Credits', 1, 'System record for manual credit adjustments', ?)
        ")->execute([$client_id, $manual_pkg_id, $admin_id]);
        $manual_cp_id = (int)$conn->lastInsertId();
    } else {
        $manual_cp_id = (int)$manual_cp['id'];
    }

    // Get or create the client_package_credits row for this session type
    $stmt = $conn->prepare("SELECT id FROM client_package_credits WHERE client_package_id = ? AND session_type = ? LIMIT 1");
    $stmt->execute([$manual_cp_id, $session_type]);
    $cpc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cpc) {
        $conn->prepare("
            INSERT INTO client_package_credits (client_package_id, client_id, session_type, total_credits, used_credits)
            VALUES (?, ?, ?, 0, 0)
        ")->execute([$manual_cp_id, $client_id, $session_type]);
        return (int)$conn->lastInsertId();
    }

    return (int)$cpc['id'];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'assign_package') {
            $package_id = (int)$_POST['package_id'];
            $pkg_notes  = trim($_POST['pkg_assign_notes'] ?? '');

            $stmt = $conn->prepare("SELECT * FROM packages WHERE id = ? AND is_active = 1");
            $stmt->execute([$package_id]);
            $package = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                $_SESSION['flash_message'] = "Package not found or inactive.";
                $_SESSION['flash_type'] = "danger";
            } else {
                $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id = ?");
                $stmt->execute([$package_id]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($items)) {
                    $_SESSION['flash_message'] = "Package has no items configured.";
                    $_SESSION['flash_type'] = "danger";
                } else {
                    try {
                        $conn->beginTransaction();

                        $expires_at = null;
                        if ($package['expiration_days']) {
                            $expires_at = date('Y-m-d H:i:s', strtotime('+' . $package['expiration_days'] . ' days'));
                        }

                        $stmt = $conn->prepare("
                            INSERT INTO client_packages
                                (client_id, package_id, package_name, expires_at, is_active, notes, created_by)
                            VALUES (?, ?, ?, ?, 1, ?, ?)
                        ");
                        $stmt->execute([$client_id, $package_id, $package['name'], $expires_at, $pkg_notes, $_SESSION['admin_id']]);
                        $cp_id = $conn->lastInsertId();

                        $credit_stmt = $conn->prepare("
                            INSERT INTO client_package_credits
                                (client_package_id, client_id, session_type, total_credits, used_credits)
                            VALUES (?, ?, ?, ?, 0)
                        ");
                        foreach ($items as $item) {
                            $credit_stmt->execute([$cp_id, $client_id, $item['session_type'], $item['quantity']]);
                        }

                        $tx_stmt = $conn->prepare("
                            INSERT INTO package_credit_transactions
                                (client_package_credit_id, client_id, session_type, transaction_type, amount, notes, created_by)
                            VALUES (?, ?, ?, 'purchase', ?, ?, ?)
                        ");
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
                        $_SESSION['flash_message'] = "Package '{$package['name']}' assigned successfully!";
                        $_SESSION['flash_type'] = "success";
                    } catch (PDOException $e) {
                        if ($conn->inTransaction()) $conn->rollBack();
                        $_SESSION['flash_message'] = "Error assigning package: " . $e->getMessage();
                        $_SESSION['flash_type'] = "danger";
                    }
                }
            }

            header("Location: credits_manage.php?client_id=$client_id");
            exit;
        }
        elseif ($_POST['action'] === 'deactivate_package') {
            $cp_id = (int)$_POST['client_package_id'];
            $conn->prepare("UPDATE client_packages SET is_active = 0 WHERE id = ? AND client_id = ?")->execute([$cp_id, $client_id]);
            $_SESSION['flash_message'] = "Package deactivated.";
            $_SESSION['flash_type'] = "success";
            header("Location: credits_manage.php?client_id=$client_id");
            exit;
        }
        elseif ($_POST['action'] === 'update_config') {
            // Update credit configuration
            $credits_expire = isset($_POST['credits_expire']) ? 1 : 0;
            $expiration_days = !empty($_POST['expiration_days']) ? (int)$_POST['expiration_days'] : null;
            
            $stmt = $conn->prepare("
                UPDATE client_credits 
                SET credits_expire = ?, expiration_days = ?, updated_at = CURRENT_TIMESTAMP
                WHERE client_id = ?
            ");
            $stmt->execute([$credits_expire, $expiration_days, $client_id]);
            
            $_SESSION['flash_message'] = "Credit configuration updated successfully!";
            $_SESSION['flash_type'] = "success";
            header("Location: credits_manage.php?client_id=$client_id");
            exit;
        }
        elseif ($_POST['action'] === 'adjust_credits') {
            // Manual credit adjustment
            $amount = (int)$_POST['amount'];
            $notes = trim($_POST['notes']);
            
            if ($amount == 0) {
                $_SESSION['flash_message'] = "Amount cannot be zero.";
                $_SESSION['flash_type'] = "danger";
            } elseif (empty($notes)) {
                $_SESSION['flash_message'] = "Notes are required for adjustments.";
                $_SESSION['flash_type'] = "danger";
            } else {
                $balance_before = $credits['credit_balance'];
                $balance_after = $balance_before + $amount;
                
                if ($balance_after < 0) {
                    $_SESSION['flash_message'] = "Cannot adjust credits below zero.";
                    $_SESSION['flash_type'] = "danger";
                } else {
                    // Update balance
                    $stmt = $conn->prepare("
                        UPDATE client_credits 
                        SET credit_balance = ?, 
                            total_adjusted = total_adjusted + ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE client_id = ?
                    ");
                    $stmt->execute([$balance_after, $amount, $client_id]);
                    
                    // Record transaction
                    $stmt = $conn->prepare("
                        INSERT INTO credit_transactions 
                        (client_id, transaction_type, amount, balance_before, balance_after, notes, created_by)
                        VALUES (?, 'adjustment', ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $client_id,
                        $amount,
                        $balance_before,
                        $balance_after,
                        $notes,
                        $_SESSION['admin_id']
                    ]);
                    
                    $_SESSION['flash_message'] = "Credit adjustment applied successfully!";
                    $_SESSION['flash_type'] = "success";
                }
            }
            
            header("Location: credits_manage.php?client_id=$client_id");
            exit;
        }
        elseif ($_POST['action'] === 'adjust_package_credits') {
            // Manual per-session-type package credit adjustment
            $session_type = $_POST['session_type'] ?? '';
            $amount = (int)$_POST['pkg_amount'];
            $notes = trim($_POST['pkg_notes']);

            $valid_types = ['group', 'mini', 'private', 'field_rental'];
            if (!in_array($session_type, $valid_types)) {
                $_SESSION['flash_message'] = "Invalid session type.";
                $_SESSION['flash_type'] = "danger";
            } elseif ($amount == 0) {
                $_SESSION['flash_message'] = "Amount cannot be zero.";
                $_SESSION['flash_type'] = "danger";
            } elseif (empty($notes)) {
                $_SESSION['flash_message'] = "Notes are required for adjustments.";
                $_SESSION['flash_type'] = "danger";
            } else {
                try {
                    $conn->beginTransaction();

                    $cpc_id = getOrCreateManualCreditRow($conn, $client_id, $session_type, $_SESSION['admin_id']);

                    // Fetch current credit row
                    $stmt = $conn->prepare("SELECT total_credits, used_credits FROM client_package_credits WHERE id = ?");
                    $stmt->execute([$cpc_id]);
                    $cpc = $stmt->fetch(PDO::FETCH_ASSOC);
                    $remaining = (int)$cpc['total_credits'] - (int)$cpc['used_credits'];

                    if ($amount < 0 && ($remaining + $amount) < 0) {
                        $conn->rollBack();
                        $_SESSION['flash_message'] = "Cannot adjust package credits below zero (remaining: $remaining).";
                        $_SESSION['flash_type'] = "danger";
                    } else {
                        // Positive amount: increase total_credits; negative: decrease total_credits
                        $conn->prepare("
                            UPDATE client_package_credits
                            SET total_credits = total_credits + ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ")->execute([$amount, $cpc_id]);

                        // Audit log
                        $conn->prepare("
                            INSERT INTO package_credit_transactions
                                (client_package_credit_id, client_id, session_type, transaction_type, amount, notes, created_by)
                            VALUES (?, ?, ?, 'adjustment', ?, ?, ?)
                        ")->execute([$cpc_id, $client_id, $session_type, $amount, $notes, $_SESSION['admin_id']]);

                        $conn->commit();
                        $type_label = $session_type_labels[$session_type]['label'] ?? $session_type;
                        $_SESSION['flash_message'] = "Package credit adjustment applied for {$type_label}!";
                        $_SESSION['flash_type'] = "success";
                    }
                } catch (PDOException $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    $_SESSION['flash_message'] = "Error adjusting package credits: " . $e->getMessage();
                    $_SESSION['flash_type'] = "danger";
                }
            }

            header("Location: credits_manage.php?client_id=$client_id");
            exit;
        }
    }
}

// Refresh credits data after any updates
$stmt = $conn->prepare("SELECT * FROM client_credits WHERE client_id = ?");
$stmt->execute([$client_id]);
$credits = $stmt->fetch(PDO::FETCH_ASSOC);

// Get unified transaction history (legacy + package credits) with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Count combined transactions
$stmt = $conn->prepare("
    SELECT (
        SELECT COUNT(*) FROM credit_transactions WHERE client_id = ?
    ) + (
        SELECT COUNT(*) FROM package_credit_transactions WHERE client_id = ?
    )
");
$stmt->execute([$client_id, $client_id]);
$total_transactions = (int)$stmt->fetchColumn();
$total_pages = ceil($total_transactions / $per_page);

// Build LIMIT clause that works with both MySQL and SQLite
$limit_clause = $db->buildLimitClause($per_page, $offset);
$stmt = $conn->prepare("
    SELECT ct.created_at, ct.transaction_type, ct.amount, ct.balance_before, ct.balance_after,
           ct.notes, au.username AS admin_username, ct.booking_id,
           NULL AS session_type, 'legacy' AS source
    FROM credit_transactions ct
    LEFT JOIN admin_users au ON ct.created_by = au.id
    WHERE ct.client_id = ?
    UNION ALL
    SELECT pct.created_at, pct.transaction_type, pct.amount, NULL AS balance_before, NULL AS balance_after,
           pct.notes, au.username AS admin_username, pct.booking_id,
           pct.session_type, 'package' AS source
    FROM package_credit_transactions pct
    LEFT JOIN admin_users au ON pct.created_by = au.id
    WHERE pct.client_id = ?
    ORDER BY created_at DESC" . $limit_clause . "
");
$stmt->execute([$client_id, $client_id]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch package credits summary for this client
$stmt = $conn->prepare("
    SELECT cpc.session_type,
           SUM(cpc.total_credits) AS total,
           SUM(cpc.used_credits)  AS used,
           SUM(cpc.total_credits - cpc.used_credits) AS remaining
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    JOIN packages p ON cp.package_id = p.id
    WHERE cpc.client_id = ?
      AND cp.is_active = 1
      AND p.name != ?
      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
    GROUP BY cpc.session_type
");
$stmt->execute([$client_id, MANUAL_CREDIT_PKG_NAME]);
$pkg_credit_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch available packages for assign dropdown
$stmt = $conn->query("SELECT * FROM packages WHERE is_active = 1 ORDER BY name");
$available_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch items for each available package (for the preview)
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

// Fetch client's purchased packages (exclude internal manual-credit package)
$stmt = $conn->prepare("
    SELECT cp.*, p.expiration_days
    FROM client_packages cp
    JOIN packages p ON cp.package_id = p.id
    WHERE cp.client_id = ?
      AND p.name != ?
    ORDER BY cp.purchased_at DESC
");
$stmt->execute([$client_id, MANUAL_CREDIT_PKG_NAME]);
$client_packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch credit rows for each client package
$cp_ids = array_column($client_packages, 'id');
$pkg_credits_map = [];
if (!empty($cp_ids)) {
    $ph = implode(',', array_fill(0, count($cp_ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM client_package_credits WHERE client_package_id IN ($ph) ORDER BY session_type");
    $stmt->execute($cp_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $cred) {
        $pkg_credits_map[$cred['client_package_id']][] = $cred;
    }
}

require_once '../backend/includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-wallet me-2"></i>Credit &amp; Package Management</h2>
                    <p class="text-muted">
                        Client: <strong><?php echo htmlspecialchars($client['name']); ?></strong>
                        <a href="clients_edit.php?id=<?php echo $client_id; ?>" class="btn btn-sm btn-outline-secondary ms-2">
                            <i class="fas fa-arrow-left"></i> Back to Client
                        </a>
                    </p>
                </div>
            </div>

            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['flash_type']; ?> alert-dismissible fade show">
                    <?php 
                    echo htmlspecialchars($_SESSION['flash_message']);
                    unset($_SESSION['flash_message']);
                    unset($_SESSION['flash_type']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Current Balance Card -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-wallet"></i> Current Balance</h5>
                        </div>
                        <div class="card-body">
                            <div class="display-4 mb-3">
                                <?php echo $credits['credit_balance']; ?> 
                                <small class="text-muted fs-5">credits</small>
                            </div>
                            
                            <div class="row text-center">
                                <div class="col">
                                    <div class="text-muted">Purchased</div>
                                    <div class="fs-5"><?php echo $credits['total_purchased']; ?></div>
                                </div>
                                <div class="col">
                                    <div class="text-muted">Consumed</div>
                                    <div class="fs-5"><?php echo $credits['total_consumed']; ?></div>
                                </div>
                                <div class="col">
                                    <div class="text-muted">Adjusted</div>
                                    <div class="fs-5"><?php echo $credits['total_adjusted']; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <!-- Credit Configuration -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-gear"></i> Configuration</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="update_config">
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="credits_expire" id="credits_expire" 
                                               <?php echo $credits['credits_expire'] ? 'checked' : ''; ?>
                                               onchange="document.getElementById('expiration_days_group').style.display = this.checked ? 'block' : 'none';">
                                        <label class="form-check-label" for="credits_expire">
                                            <strong>Credits Expire</strong>
                                        </label>
                                    </div>
                                    <small class="text-muted">If enabled, credits will expire after a specified number of days</small>
                                </div>

                                <div class="mb-3" id="expiration_days_group" style="display: <?php echo $credits['credits_expire'] ? 'block' : 'none'; ?>;">
                                    <label for="expiration_days" class="form-label">Expiration Days</label>
                                    <input type="number" class="form-control" id="expiration_days" name="expiration_days" 
                                           value="<?php echo $credits['expiration_days']; ?>" min="1" max="365">
                                    <small class="text-muted">Number of days until credits expire</small>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-check-circle"></i> Save Configuration
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Manual Adjustment Card (Legacy Credits) -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-plus-slash-minus"></i> Manual Adjustment – General Credits</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="adjust_credits">
                        
                        <div class="col-md-3">
                            <label for="amount" class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="amount" name="amount" required>
                            <small class="text-muted">Positive to add, negative to subtract</small>
                        </div>
                        
                        <div class="col-md-7">
                            <label for="notes" class="form-label">Notes <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="notes" name="notes" required 
                                   placeholder="Reason for adjustment (e.g., Package purchase, Refund, Correction)">
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-check2"></i> Apply
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Manual Package Credit Adjustment Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-layer-group me-1"></i> Manual Adjustment – Package Credits by Session Type</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">Directly add or subtract per-session-type credits without assigning a full package. All changes are audit-logged.</p>
                    <form method="POST" class="row g-3">
                        <input type="hidden" name="action" value="adjust_package_credits">

                        <div class="col-md-3">
                            <label for="session_type" class="form-label">Session Type <span class="text-danger">*</span></label>
                            <select name="session_type" id="session_type" class="form-select" required>
                                <option value="">Select type…</option>
                                <?php foreach ($session_type_labels as $type => $meta): ?>
                                    <option value="<?= htmlspecialchars($type) ?>">
                                        <?= htmlspecialchars($meta['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-2">
                            <label for="pkg_amount" class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="pkg_amount" name="pkg_amount" required>
                            <small class="text-muted">+ add, − subtract</small>
                        </div>

                        <div class="col-md-5">
                            <label for="pkg_notes" class="form-label">Notes <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="pkg_notes" name="pkg_notes" required
                                   placeholder="Reason (e.g., Makeup session, Refund, Correction)">
                        </div>

                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-check-circle"></i> Apply
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Package Credits Breakdown (Active) -->
            <?php if (!empty($pkg_credit_summary)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-box-open me-2"></i>Package Credits (Active)</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($pkg_credit_summary as $row): ?>
                            <?php $meta = $session_type_labels[$row['session_type']] ?? ['label' => ucfirst($row['session_type']), 'badge' => 'secondary', 'icon' => 'fas fa-circle']; ?>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="card text-center h-100 border-<?= $meta['badge'] ?>">
                                    <div class="card-body">
                                        <i class="<?= $meta['icon'] ?> fa-2x mb-2 text-muted"></i>
                                        <h6><?= $meta['label'] ?></h6>
                                        <div class="display-6 fw-bold <?= $row['remaining'] > 0 ? 'text-success' : 'text-danger' ?>">
                                            <?= $row['remaining'] ?>
                                        </div>
                                        <small class="text-muted">of <?= $row['total'] ?> remaining</small>
                                        <?php if ($row['total'] > 0): ?>
                                            <div class="progress mt-2" style="height:6px;">
                                                <div class="progress-bar bg-<?= $meta['badge'] ?>"
                                                     style="width:<?= round(($row['used'] / $row['total']) * 100) ?>%"></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

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
                                <label for="assign_package_id" class="form-label">Package <span class="text-danger">*</span></label>
                                <select name="package_id" id="assign_package_id" class="form-select" required onchange="showPackagePreview(this)">
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
                                <label for="pkg_assign_notes" class="form-label">Notes</label>
                                <input type="text" class="form-control" id="pkg_assign_notes" name="pkg_assign_notes"
                                       placeholder="e.g., Paid by cash, Invoice #123">
                            </div>

                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-check-circle"></i> Assign
                                </button>
                            </div>

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
            <div class="card mb-4">
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
                                        <?php foreach ($pkg_credits_map[$cp['id']] ?? [] as $cred): ?>
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

            <!-- Transaction History -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clock-rotate-left"></i> Unified Transaction History</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($transactions)): ?>
                        <p class="text-muted">No transactions yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Source</th>
                                        <th>Type</th>
                                        <th>Session Type</th>
                                        <th>Amount</th>
                                        <th>Balance</th>
                                        <th>Notes / Booking</th>
                                        <th>Admin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
                                            <td>
                                                <?php if ($transaction['source'] === 'package'): ?>
                                                    <span class="badge bg-success">Package</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">General</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $type_badges = [
                                                    'purchase' => 'success',
                                                    'consume' => 'warning',
                                                    'adjustment' => 'info',
                                                    'expiration' => 'danger'
                                                ];
                                                $badge_class = $type_badges[$transaction['transaction_type']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($transaction['transaction_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($transaction['session_type']): ?>
                                                    <?php $stmeta = $session_type_labels[$transaction['session_type']] ?? ['label' => ucfirst($transaction['session_type']), 'badge' => 'secondary']; ?>
                                                    <span class="badge bg-<?= $stmeta['badge'] ?>"><?= htmlspecialchars($stmeta['label']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="<?php echo $transaction['amount'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $transaction['amount'] > 0 ? '+' : ''; ?><?php echo $transaction['amount']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($transaction['balance_before'] !== null): ?>
                                                    <small class="text-muted"><?php echo $transaction['balance_before']; ?> → </small>
                                                    <strong><?php echo $transaction['balance_after']; ?></strong>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($transaction['booking_id']): ?>
                                                    <a href="bookings_list.php?id=<?php echo $transaction['booking_id']; ?>">
                                                        Booking #<?php echo $transaction['booking_id']; ?>
                                                    </a>
                                                <?php elseif ($transaction['notes']): ?>
                                                    <?php echo htmlspecialchars($transaction['notes']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $transaction['admin_username'] ? htmlspecialchars($transaction['admin_username']) : '-'; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <nav>
                                <ul class="pagination justify-content-center">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?client_id=<?php echo $client_id; ?>&page=<?php echo $i; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>

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
        html += ' <strong>' + item.quantity + '&times; ' + label + '</strong>';
    });
    previewItems.innerHTML = html;

    const opt = select.options[select.selectedIndex];
    const expiry = opt.dataset.expiry;
    previewExpiry.textContent = expiry ? ' · Expires in ' + expiry + ' days after assignment.' : '';

    preview.style.display = 'block';
}
</script>
