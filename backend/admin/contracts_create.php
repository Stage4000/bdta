<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $contract_text = trim($_POST['contract_text'] ?? '');
    $effective_date = trim($_POST['effective_date'] ?? '');
    
    if ($client_id && $title && $contract_text) {
        $contract_number = 'CON-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        $stmt = $conn->prepare("
            INSERT INTO contracts (contract_number, client_id, title, contract_text, created_date, effective_date, status) 
            VALUES (?, ?, ?, ?, DATE('now'), ?, 'draft')
        ");
        $stmt->execute([$contract_number, $client_id, $title, $contract_text, $effective_date]);
        setFlashMessage('Contract created successfully!', 'success');
        redirect('contracts_list.php');
    }
}

include '../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <h2 class="mb-4">Create Contract</h2>
            
            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Client *</label>
                                <select class="form-select" name="client_id" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>"><?= escape($client['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Effective Date</label>
                                <input type="date" class="form-control" name="effective_date" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Contract Title *</label>
                            <input type="text" class="form-control" name="title" placeholder="e.g., Dog Training Service Agreement" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Contract Text *</label>
                            <textarea class="form-control" name="contract_text" rows="15" required
                                placeholder="Enter contract terms and conditions..."></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Create Contract
                        </button>
                        <a href="contracts_list.php" class="btn btn-secondary">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
