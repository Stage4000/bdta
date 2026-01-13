<?php
/**
 * Create/Edit Contract Template
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

$db = new Database();
$conn = $db->getConnection();

$template_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit = $template_id > 0;

// Load template if editing
$template = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM contract_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        $_SESSION['error'] = "Template not found";
        header('Location: contract_templates_list.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $template_text = trim($_POST['template_text']);
    $service_type = trim($_POST['service_type']);
    $renewal_period_months = max(1, intval($_POST['renewal_period_months']));
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name) || empty($template_text)) {
        $_SESSION['error'] = "Name and template text are required";
    } else {
        try {
            if ($is_edit) {
                $stmt = $conn->prepare("
                    UPDATE contract_templates SET 
                        name = ?, description = ?, template_text = ?, 
                        service_type = ?, renewal_period_months = ?, is_active = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$name, $description, $template_text, $service_type, $renewal_period_months, $is_active, $template_id]);
                $_SESSION['success'] = "Template updated successfully";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO contract_templates (name, description, template_text, service_type, renewal_period_months, is_active)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $description, $template_text, $service_type, $renewal_period_months, $is_active]);
                $_SESSION['success'] = "Template created successfully";
                $template_id = $conn->lastInsertId();
            }
            
            header('Location: contract_templates_list.php');
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error saving template: " . $e->getMessage();
        }
    }
}

$page_title = $is_edit ? "Edit Contract Template" : "Create Contract Template";
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h1 class="h3">
                <i class="bi bi-file-earmark-medical me-2"></i>
                <?= $is_edit ? 'Edit Contract Template' : 'Create Contract Template' ?>
            </h1>
        </div>
        <div class="col-auto">
            <a href="contract_templates_list.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Back to Templates
            </a>
        </div>
    </div>

    <form method="POST">
        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Template Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Template Name *</label>
                            <input type="text" name="name" class="form-control" 
                                   value="<?= $template ? htmlspecialchars($template['name']) : '' ?>" 
                                   placeholder="e.g., Training Services Contract" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" 
                                      placeholder="Brief description of when to use this template"><?= $template ? htmlspecialchars($template['description']) : '' ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Contract Text *</label>
                            <p class="small text-muted">
                                Available variables: {{client_name}}, {{client_email}}, {{date}}, {{service_type}}
                            </p>
                            <textarea name="template_text" id="template_text" class="form-control" rows="20" 
                                      placeholder="Enter contract text here..." required><?= $template ? htmlspecialchars($template['template_text']) : '' ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Settings</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Service Type</label>
                            <input type="text" name="service_type" class="form-control" 
                                   value="<?= $template ? htmlspecialchars($template['service_type']) : '' ?>" 
                                   placeholder="e.g., Training, Boarding">
                            <small class="text-muted">For organizing templates</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Renewal Period</label>
                            <div class="input-group">
                                <input type="number" name="renewal_period_months" class="form-control" 
                                       value="<?= $template ? $template['renewal_period_months'] : '12' ?>" 
                                       min="1" max="120">
                                <span class="input-group-text">months</span>
                            </div>
                            <small class="text-muted">Contracts typically renewed yearly per service type</small>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                   <?= !$template || $template['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active Template
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-save me-1"></i>
                    <?= $is_edit ? 'Update Template' : 'Create Template' ?>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- TinyMCE Rich Text Editor (Self-Hosted) -->
<script src="node_modules/tinymce/tinymce.min.js"></script>
<script>
tinymce.init({
    selector: '#template_text',
    height: 500,
    menubar: false,
    plugins: [
        'lists', 'link', 'charmap', 'preview', 'searchreplace', 'code',
        'fullscreen', 'table', 'help', 'wordcount'
    ],
    toolbar: 'undo redo | formatselect | bold italic underline | ' +
             'bullist numlist | alignleft aligncenter alignright | ' +
             'removeformat | help',
    content_style: 'body { font-family: Helvetica, Arial, sans-serif; font-size: 14pt; }',
    formats: {
        h1: { block: 'h1', styles: { fontSize: '24pt', fontWeight: 'bold' } },
        h2: { block: 'h2', styles: { fontSize: '20pt', fontWeight: 'bold' } },
        h3: { block: 'h3', styles: { fontSize: '16pt', fontWeight: 'bold' } }
    },
    block_formats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3',
    setup: function(editor) {
        editor.on('init', function() {
            // Ensure form validation works with TinyMCE
            editor.on('change', function() {
                tinymce.triggerSave();
            });
        });
    }
});
</script>

<?php include '../backend/includes/footer.php'; ?>
