<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Check if editing existing contract
$contract_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$contract = null;

if ($contract_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM contracts WHERE id = ?");
    $stmt->execute([$contract_id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        setFlashMessage('Contract not found!', 'danger');
        redirect('contracts_list.php');
    }
    
    // Only allow editing draft contracts
    if ($contract['status'] !== 'draft') {
        setFlashMessage('Only draft contracts can be edited!', 'danger');
        redirect('contracts_view.php?id=' . $contract_id);
    }
}

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
    $description = trim($_POST['description'] ?? '');
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
        
        if ($contract_id > 0) {
            // Update existing contract
            $stmt = $conn->prepare("
                UPDATE contracts 
                SET client_id = ?, title = ?, description = ?, contract_text = ?, effective_date = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([$client_id, $title, $description, $contract_text, $effective_date, $contract_id]);
            setFlashMessage('Contract updated successfully!', 'success');
            redirect('contracts_view.php?id=' . $contract_id);
        } else {
            // Create new contract
            $contract_number = 'CON-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            $stmt = $conn->prepare("
                INSERT INTO contracts (contract_number, client_id, title, description, contract_text, created_date, effective_date, status) 
                VALUES (?, ?, ?, ?, ?, CURRENT_DATE, ?, 'draft')
            ");
            $stmt->execute([$contract_number, $client_id, $title, $description, $contract_text, $effective_date]);
            setFlashMessage('Contract created successfully!', 'success');
            redirect('contracts_list.php');
        }
    }
}

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-10 offset-md-1">
            <h2 class="mb-4">
                <i class="bi bi-file-earmark-check me-2"></i>
                <?= $contract_id > 0 ? 'Edit Contract' : 'Create Contract' ?>
            </h2>
            
            <?php
            $flash = getFlashMessage();
            if ($flash):
            ?>
                <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
                    <?= escape($flash['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Contract Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="client_id" class="form-label">Client *</label>
                                <select class="form-select" id="client_id" name="client_id" required>
                                    <option value="">Select Client...</option>
                                    <?php foreach ($clients as $client_opt): ?>
                                        <option value="<?= $client_opt['id'] ?>" 
                                            <?= ($contract['client_id'] ?? 0) == $client_opt['id'] ? 'selected' : '' ?>>
                                            <?= escape($client_opt['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label for="template_id" class="form-label">Load from Template</label>
                                <select class="form-select" id="template_id" onchange="if(this.value) window.location.href='?template_id='+this.value+(<?= $contract_id ?> > 0 ? '&id=<?= $contract_id ?>' : '')">
                                    <option value="">Select Template...</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>" <?= $template_id == $template['id'] ? 'selected' : '' ?>>
                                            <?= escape($template['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="title" class="form-label">Contract Title *</label>
                            <input type="text" class="form-control" id="title" name="title" 
                                   value="<?= escape($contract['title'] ?? $selected_template['name'] ?? '') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" 
                                   value="<?= escape($contract['description'] ?? $selected_template['description'] ?? '') ?>" 
                                   placeholder="Brief description of this contract">
                        </div>
                        
                        <div class="mb-3">
                            <label for="effective_date" class="form-label">Effective Date</label>
                            <input type="date" class="form-control" id="effective_date" name="effective_date" 
                                   value="<?= $contract['effective_date'] ?? date('Y-m-d') ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label for="contract_text" class="form-label">Contract Text *</label>
                            <textarea class="form-control" id="contract_text" name="contract_text" rows="20" required><?= escape($contract['contract_text'] ?? $selected_template['template_text'] ?? '') ?></textarea>
                            <small class="form-text text-muted">
                                Available variables: {{client_name}}, {{client_email}}, {{date}}
                            </small>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex justify-content-between mb-4">
                    <a href="contracts_list.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-save"></i> <?= $contract_id > 0 ? 'Update' : 'Create' ?> Contract
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- CKEditor 5 Rich Text Editor (Self-Hosted, GPL License) -->
<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css" />
<script type="module">
import {
    ClassicEditor,
    Essentials,
    Bold,
    Italic,
    Underline,
    Strikethrough,
    Paragraph,
    Heading,
    Link,
    List,
    Table,
    TableToolbar,
    Alignment,
    SourceEditing,
    GeneralHtmlSupport
} from 'js/ckeditor5/ckeditor5.js';

// Initialize CKEditor 5 for contract text editor (document preset)
ClassicEditor
    .create(document.querySelector('#contract_text'), {
        licenseKey: 'GPL',
        plugins: [
            Essentials, Bold, Italic, Underline, Strikethrough,
            Paragraph, Heading, Link, List, Table, TableToolbar,
            Alignment, SourceEditing, GeneralHtmlSupport
        ],
        toolbar: [
            'undo', 'redo', '|',
            'heading', '|',
            'bold', 'italic', 'underline', 'strikethrough', '|',
            'link', 'insertTable', '|',
            'bulletedList', 'numberedList', '|',
            'alignment', '|',
            'sourceEditing'
        ],
        heading: {
            options: [
                { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                { model: 'heading1', view: 'h1', title: 'Heading 1', class: 'ck-heading_heading1' },
                { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
            ]
        },
        table: {
            contentToolbar: ['tableColumn', 'tableRow', 'mergeTableCells']
        },
        htmlSupport: {
            allow: [
                {
                    name: /.*/,
                    attributes: true,
                    classes: true,
                    styles: true
                }
            ]
        }
    })
    .then(editor => {
        window.contractEditor = editor;
        // Sync with textarea on change
        editor.model.document.on('change:data', () => {
            document.querySelector('#contract_text').value = editor.getData();
        });
    })
    .catch(error => {
        console.error('CKEditor initialization error:', error);
    });
</script>
<script>
// Load template via AJAX when selected
document.getElementById('template_select')?.addEventListener('change', function() {
    const templateId = this.value;
    if (!templateId) return;
    
    fetch(`contract_templates_get.php?id=${templateId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('contract_title').value = data.template.name;
                if (window.contractEditor) {
                    window.contractEditor.setData(data.template.template_text);
                } else {
                    document.getElementById('contract_text').value = data.template.template_text;
                }
            }
        });
});
</script>

<?php include '../backend/includes/footer.php'; ?>

