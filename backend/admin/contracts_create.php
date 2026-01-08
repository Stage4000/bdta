<?php
require_once '../includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$clients_stmt = $conn->query("SELECT id, name, email FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

$templates_stmt = $conn->query("SELECT id, name FROM contract_templates WHERE is_active = 1 ORDER BY name");
$templates = $templates_stmt->fetchAll(PDO::FETCH_ASSOC);

// Load template if specified
$template_id = isset($_GET['template_id']) ? intval($_GET['template_id']) : 0;
$selected_template = null;
if ($template_id) {
    $stmt = $conn->prepare("SELECT * FROM contract_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $selected_template = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = intval($_POST['client_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $contract_text = trim($_POST['contract_text'] ?? '');
    $effective_date = trim($_POST['effective_date'] ?? '');
    
    if ($client_id && $title && $contract_text) {
        // Get client info for variable substitution
        $client_stmt = $conn->prepare("SELECT name, email FROM clients WHERE id = ?");
        $client_stmt->execute([$client_id]);
        $client = $client_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Replace variables
        $contract_text = str_replace('{{client_name}}', $client['name'], $contract_text);
        $contract_text = str_replace('{{client_email}}', $client['email'], $contract_text);
        $contract_text = str_replace('{{date}}', date('F j, Y'), $contract_text);
        
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
            <h2 class="mb-4">
                <i class="bi bi-file-earmark-check me-2"></i>
                Create Contract
            </h2>
            
            <div class="card">
                <div class="card-body">
                    <form method="POST" id="contractForm">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Client *</label>
                                <select class="form-select" name="client_id" id="client_id" required>
                                    <option value="">Select Client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= $client['id'] ?>" data-email="<?= escape($client['email']) ?>">
                                            <?= escape($client['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Template</label>
                                <select class="form-select" id="template_select">
                                    <option value="">Select Template...</option>
                                    <?php foreach ($templates as $tpl): ?>
                                        <option value="<?= $tpl['id'] ?>" <?= $template_id == $tpl['id'] ? 'selected' : '' ?>>
                                            <?= escape($tpl['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Effective Date</label>
                                <input type="date" class="form-control" name="effective_date" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Contract Title *</label>
                            <input type="text" class="form-control" name="title" id="contract_title" 
                                   value="<?= $selected_template ? escape($selected_template['name']) : '' ?>"
                                   placeholder="e.g., Dog Training Service Agreement" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Contract Text *</label>
                            <p class="small text-muted">
                                Variables: {{client_name}}, {{client_email}}, {{date}}, {{service_type}}
                            </p>
                            <textarea class="form-control font-monospace" name="contract_text" id="contract_text" rows="20" required 
                                placeholder="Enter contract terms and conditions..."><?= $selected_template ? escape($selected_template['template_text']) : '' ?></textarea>
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

<script>
// Load template via AJAX when selected
document.getElementById('template_select').addEventListener('change', function() {
    const templateId = this.value;
    if (!templateId) return;
    
    fetch(`contract_templates_get.php?id=${templateId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('contract_title').value = data.template.name;
                document.getElementById('contract_text').value = data.template.template_text;
            }
        });
});
</script>

<?php include '../includes/footer.php'; ?>

