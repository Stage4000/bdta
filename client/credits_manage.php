<?php
/**
 * Credits Management Page
 * Manage client session credits, view history, make adjustments
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'update_config') {
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
    }
}

// Refresh credits data after any updates
$stmt = $conn->prepare("SELECT * FROM client_credits WHERE client_id = ?");
$stmt->execute([$client_id]);
$credits = $stmt->fetch(PDO::FETCH_ASSOC);

// Get transaction history with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$stmt = $conn->prepare("SELECT COUNT(*) FROM credit_transactions WHERE client_id = ?");
$stmt->execute([$client_id]);
$total_transactions = $stmt->fetchColumn();
$total_pages = ceil($total_transactions / $per_page);

$stmt = $conn->prepare("
    SELECT ct.*, au.username as admin_username, b.appointment_date
    FROM credit_transactions ct
    LEFT JOIN admin_users au ON ct.created_by = au.id
    LEFT JOIN bookings b ON ct.booking_id = b.id
    WHERE ct.client_id = ?
    ORDER BY ct.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute([$client_id, $per_page, $offset]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../backend/includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="fas fa-users me-2"></i>Credit Management</h2>
                    <p class="text-muted">
                        Client: <strong><?php echo htmlspecialchars($client['name']); ?></strong>
                        <a href="clients_edit.php?id=<?php echo $client_id; ?>" class="btn btn-sm btn-outline-primary ms-2">
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

            <!-- Manual Adjustment Card -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-plus-slash-minus"></i> Manual Adjustment</h5>
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

            <!-- Transaction History -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-clock-rotate-left"></i> Transaction History</h5>
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
                                        <th>Type</th>
                                        <th>Amount</th>
                                        <th>Balance</th>
                                        <th>Notes</th>
                                        <th>Admin</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transactions as $transaction): ?>
                                        <tr>
                                            <td><?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></td>
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
                                                <span class="<?php echo $transaction['amount'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo $transaction['amount'] > 0 ? '+' : ''; ?><?php echo $transaction['amount']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted"><?php echo $transaction['balance_before']; ?> → </small>
                                                <strong><?php echo $transaction['balance_after']; ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($transaction['booking_id']): ?>
                                                    <a href="bookings_list.php?id=<?php echo $transaction['booking_id']; ?>">
                                                        Booking #<?php echo $transaction['booking_id']; ?>
                                                        <?php if ($transaction['appointment_date']): ?>
                                                            (<?php echo date('M j', strtotime($transaction['appointment_date'])); ?>)
                                                        <?php endif; ?>
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
