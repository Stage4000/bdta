<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$id = intval($_GET['id'] ?? 0);
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

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Contract: <?= escape($contract['contract_number']) ?></h2>
                <a href="contracts_list.php" class="btn btn-secondary">Back to List</a>
            </div>
            
            <div class="card">
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Client:</strong> <?= escape($contract['client_name']) ?><br>
                            <strong>Status:</strong> <span class="badge bg-info"><?= strtoupper($contract['status']) ?></span>
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
                    
                    <div class="mt-4" style="white-space: pre-wrap;"><?= escape($contract['contract_text']) ?></div>
                    
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
    </div>
</div>

<?php include '../includes/footer.php'; ?>
