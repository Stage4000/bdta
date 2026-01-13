<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = intval($_GET['id'] ?? 0);

// Handle delete action
if (isset($_POST['delete_contract'])) {
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
    $new_status = $_POST['new_status'];
    $allowed_statuses = ['draft', 'sent', 'signed', 'expired'];
    if (in_array($new_status, $allowed_statuses)) {
        $stmt = $conn->prepare("UPDATE contracts SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$new_status, $id]);
        setFlashMessage('Contract status updated successfully!', 'success');
        header('Location: contracts_view.php?id=' . $id);
        exit;
    }
}

$stmt = $conn->prepare("
    SELECT co.*, c.name as client_name, c.email as client_email
    FROM contracts co
    JOIN clients c ON co.client_id = c.id
    WHERE co.id = ?
");
$stmt->execute([$id]);
$contract = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$contract) {
    setFlashMessage('Contract not found!', 'danger');
    redirect('contracts_list.php');
}

// Generate public link
require_once '../backend/includes/settings.php';
$base_url = Settings::get('base_url', 'http://localhost:8000');
$public_link = $base_url . '/backend/public/contract.php?id=' . $id;

include '../backend/includes/header.php';
?>

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
                <h2>
                <i class="bi bi-file-earmark-check me-2"></i>
                Contract: <?= escape($contract['contract_number']) ?>
            </h2>
                <div>
                    <?php if ($contract['status'] === 'draft'): ?>
                        <a href="contracts_create.php?id=<?= $id ?>" class="btn btn-primary me-2">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this contract?')">
                            <button type="submit" name="delete_contract" class="btn btn-danger me-2">
                                <i class="bi bi-trash"></i> Delete
                            </button>
                        </form>
                    <?php endif; ?>
                    <a href="contracts_list.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to List
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
                            
                            <?php if ($contract['signature_data']): ?>
                                <hr>
                                <h5>Signature</h5>
                                <img src="<?= escape($contract['signature_data']) ?>" alt="Signature" class="border p-2" style="max-width: 400px;">
                                <p class="text-muted small mt-2">
                                    Signed on <?= formatDate($contract['signed_date']) ?> from IP: <?= escape($contract['ip_address']) ?>
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
                                    <i class="bi bi-check"></i> Update Status
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
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
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
