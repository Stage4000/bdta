<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);

$stmt = $conn->prepare("
    SELECT co.*, c.name as client_name, c.email as client_email
    FROM contracts co
    JOIN clients c ON co.client_id = c.id
    WHERE co.id = ?
");
$stmt->execute([$id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($contract)) {
    setFlashMessage('Contract not found!', 'danger');
    redirect('contracts_list.php');
}

require_once '../backend/public/includes/public_contract_access.php';

$new_access_token = bdta_generate_contract_access_token();
$token_stmt = $conn->prepare("
    UPDATE contracts
    SET access_token = COALESCE(NULLIF(access_token, ''), ?),
        updated_at = CASE
            WHEN NULLIF(access_token, '') IS NULL THEN CURRENT_TIMESTAMP
            ELSE updated_at
        END
    WHERE id = ?
");
$token_stmt->execute([$new_access_token, $id]);
$refresh_stmt = $conn->prepare("
    SELECT co.*, c.name as client_name, c.email as client_email
    FROM contracts co
    JOIN clients c ON co.client_id = c.id
    WHERE co.id = ?
");
$refresh_stmt->execute([$id]);
$refreshed_contract = $refresh_stmt->fetch(PDO::FETCH_ASSOC);
if (is_array($refreshed_contract)) {
    $contract = $refreshed_contract;
}

// Handle delete action
if (isset($_POST['delete_contract'])) {
    $csrf_token = scalar_string($_POST['csrf_token'] ?? '');
    if ($csrf_token === '' || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), $csrf_token)) {
        setFlashMessage('Invalid request.', 'danger');
        header('Location: contracts_view.php?id=' . $id);
        exit;
    }
    if ($contract['status'] === 'draft') {
        $stmt = $conn->prepare("DELETE FROM contracts WHERE id = ?");
        $stmt->execute([$id]);
        setFlashMessage('Contract deleted successfully!', 'success');
        redirect('contracts_list.php');
    } else {
        setFlashMessage('Only draft contracts can be deleted!', 'danger');
    }
}

// Handle status change
if (isset($_POST['change_status'])) {
    $csrf_token = scalar_string($_POST['csrf_token'] ?? '');
    if ($csrf_token === '' || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), $csrf_token)) {
        setFlashMessage('Invalid request.', 'danger');
        header('Location: contracts_view.php?id=' . $id);
        exit;
    }
    $new_status = scalar_string($_POST['new_status'] ?? '');
    $allowed_statuses = ['draft', 'sent', 'signed', 'expired'];
    if (in_array($new_status, $allowed_statuses, true)) {
        $stmt = $conn->prepare("UPDATE contracts SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        setFlashMessage('Contract status updated successfully!', 'success');
        header('Location: contracts_view.php?id=' . $id);
        exit;
    }
}

// Generate the shareable client link and lazily provision a token for older contracts.
$public_link = bdta_get_public_contract_url($conn, $id, $contract['access_token'] ?? null);

// Fetch audit log for this contract
$log_stmt = $conn->prepare("
    SELECT * FROM contract_signature_log
    WHERE contract_id = ?
    ORDER BY created_at ASC
");
$log_stmt->execute([$id]);
$sig_log = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

// Font labels for display
$font_labels = [
    'font-dancing'     => 'Dancing Script',
    'font-pacifico'    => 'Pacifico',
    'font-satisfy'     => 'Satisfy',
    'font-great-vibes' => 'Great Vibes',
    'font-allura'      => 'Allura',
];

include '../backend/includes/header.php';
?>
<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Pacifico&family=Satisfy&family=Great+Vibes&family=Allura&display=swap" rel="stylesheet">
<style>
.font-dancing     { font-family: 'Dancing Script', cursive; }
.font-pacifico    { font-family: 'Pacifico', cursive; }
.font-satisfy     { font-family: 'Satisfy', cursive; }
.font-great-vibes { font-family: 'Great Vibes', cursive; }
.font-allura      { font-family: 'Allura', cursive; }
.signed-sig { font-size: 2.2rem; color: #1a1a2e; border-bottom: 2px solid #495057; display: inline-block; padding-bottom: .2rem; }
</style>

<div class="container-fluid mt-4">
    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-file-circle-check me-2"></i>Contract: <?= escape($contract['contract_number']) ?></h2>
                <div>
                    <?php if ($contract['status'] === 'draft'): ?>
                        <a href="contracts_create.php?id=<?= $id ?>" class="btn btn-primary me-2">
                            <i class="fas fa-pencil"></i> Edit
                        </a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this contract?')">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                            <button type="submit" name="delete_contract" class="btn btn-danger me-2">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="contracts_list.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <strong>Client:</strong> <a href="clients_view.php?id=<?= $contract['client_id'] ?>"><?= escape($contract['client_name']) ?></a><br>
                                    <strong>Status:</strong> 
                                    <?php
                                    $colors = ['draft' => 'secondary', 'sent' => 'info', 'signed' => 'success', 'expired' => 'danger'];
                                    $color = $colors[$contract['status']] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?= $color ?>"><?= strtoupper($contract['status']) ?></span>
                                </div>
                                <div class="col-md-6 text-end">
                                    <strong>Created:</strong> <?= formatDate($contract['created_date']) ?><br>
                                    <?php if ($contract['signed_date']): ?>
                                        <strong>Signed:</strong> <?= formatDate($contract['signed_date']) ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <hr>
                            
                            <h4><?= escape($contract['title']) ?></h4>
                            
                            <?php if ($contract['description']): ?>
                                <p class="text-muted"><?= escape($contract['description']) ?></p>
                            <?php endif; ?>
                            
                            <div class="mt-4 contract-content"><?= $contract['contract_text'] ?></div>
                            
                            <?php if ($contract['signature_typed_name']): ?>
                                <hr>
                                <h5>Electronic Signature</h5>
                                <div class="signed-sig <?= escape($contract['signature_font'] ?? 'font-dancing') ?>">
                                    <?= escape($contract['signature_typed_name']) ?>
                                </div>
                                <p class="text-muted small mt-2">
                                    Style: <?= escape($font_labels[array_string_value($contract, 'signature_font')] ?? array_string_value($contract, 'signature_font')) ?><br>
                                    Signed on <?= escape(formatDateTime($contract['signed_date'], 'F j, Y \a\t g:i A')) ?>
                                    &mdash; IP: <?= escape($contract['ip_address']) ?>
                                </p>
                            <?php elseif ($contract['signature_data']): ?>
                                <hr>
                                <h5>Signature</h5>
                                <img src="<?= escape($contract['signature_data']) ?>" alt="Signature" class="border p-2" style="max-width: 400px;">
                                <p class="text-muted small mt-2">
                                    Signed on <?= escape(formatDateTime($contract['signed_date'], 'F j, Y \a\t g:i A')) ?> from IP: <?= escape($contract['ip_address']) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <!-- Status Management -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0">Status Management</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                                <div class="mb-3">
                                    <label class="form-label">Change Status</label>
                                    <select name="new_status" class="form-select">
                                        <option value="draft" <?= $contract['status'] == 'draft' ? 'selected' : '' ?>>Draft</option>
                                        <option value="sent" <?= $contract['status'] == 'sent' ? 'selected' : '' ?>>Sent</option>
                                        <option value="signed" <?= $contract['status'] == 'signed' ? 'selected' : '' ?>>Signed</option>
                                        <option value="expired" <?= $contract['status'] == 'expired' ? 'selected' : '' ?>>Expired</option>
                                    </select>
                                </div>
                                <button type="submit" name="change_status" class="btn btn-primary btn-sm w-100">
                                    <i class="fas fa-check"></i> Update Status
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Share Link -->
                    <?php if ($contract['status'] != 'draft'): ?>
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Share Contract</h5>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted">Send this link to the client to view and sign the contract:</p>
                            <div class="input-group">
                                <input type="text" class="form-control form-control-sm" id="publicLink" 
                                       value="<?= escape($public_link) ?>" readonly>
                                <button class="btn btn-outline-secondary btn-sm" type="button" onclick="copyLink()">
                                    <i class="fas fa-clipboard"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Signature Audit Log -->
                    <?php if (!empty($sig_log)): ?>
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list-check me-1"></i>Signature Audit Log</h5>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($sig_log as $entry): ?>
                                <li class="list-group-item small">
                                    <div class="fw-semibold text-capitalize"><?= escape($entry['event_type']) ?></div>
                                    <div><?= escape($entry['details']) ?></div>
                                    <div class="text-muted">
                                        <?= escape(formatDateTime($entry['created_at'], 'M j, Y g:i A')) ?><br>
                                        IP: <?= escape($entry['ip_address']) ?>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const input = document.getElementById('publicLink');
    input.select();
    document.execCommand('copy');
    alert('Link copied to clipboard!');
}
</script>

<?php include '../backend/includes/footer.php'; ?>
