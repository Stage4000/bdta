<?php
/**
 * Form Template Edit/Create Page
 * Create or edit form templates with dynamic form builder
 */

require_once '../includes/config.php';
require_once '../includes/database.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$template_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$is_edit = $template_id !== null;

// Initialize variables
$name = '';
$description = '';
$form_type = 'client_form';
$fields = [];
$required_frequency = '';
$appointment_type_id = null;
$is_internal = 0;
$is_active = 1;

// If editing, load template
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM form_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($template) {
        $name = $template['name'];
        $description = $template['description'];
        $form_type = $template['form_type'];
        $fields = json_decode($template['fields'], true) ?: [];
        $required_frequency = $template['required_frequency'];
        $appointment_type_id = $template['appointment_type_id'];
        $is_internal = $template['is_internal'];
        $is_active = $template['is_active'];
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $form_type = $_POST['form_type'];
    $is_internal = isset($_POST['is_internal']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $required_frequency = $_POST['required_frequency'] ?? null;
    $appointment_type_id = !empty($_POST['appointment_type_id']) ? (int)$_POST['appointment_type_id'] : null;
    
    // Build fields array from POST data
    $fields = [];
    if (isset($_POST['field_label']) && is_array($_POST['field_label'])) {
        foreach ($_POST['field_label'] as $index => $label) {
            if (!empty(trim($label))) {
                $field = [
                    'label' => trim($label),
                    'type' => $_POST['field_type'][$index] ?? 'text',
                    'placeholder' => trim($_POST['field_placeholder'][$index] ?? ''),
                    'required' => isset($_POST['field_required'][$index]) ? 1 : 0
                ];
                
                // Add options for select, radio, checkbox
                if (in_array($field['type'], ['select', 'radio', 'checkbox'])) {
                    $options_str = trim($_POST['field_options'][$index] ?? '');
                    $field['options'] = array_filter(array_map('trim', explode("\n", $options_str)));
                }
                
                $fields[] = $field;
            }
        }
    }
    
    $fields_json = json_encode($fields);
    
    try {
        if ($is_edit) {
            // Update
            $stmt = $conn->prepare("
                UPDATE form_templates 
                SET name = ?, description = ?, form_type = ?, fields = ?,
                    required_frequency = ?, appointment_type_id = ?, 
                    is_internal = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $description, $form_type, $fields_json,
                $required_frequency, $appointment_type_id,
                $is_internal, $is_active, $template_id
            ]);
            
            $_SESSION['flash_message'] = "Form template updated successfully!";
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO form_templates 
                (name, description, form_type, fields, required_frequency, 
                 appointment_type_id, is_internal, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $description, $form_type, $fields_json,
                $required_frequency, $appointment_type_id,
                $is_internal, $is_active
            ]);
            
            $_SESSION['flash_message'] = "Form template created successfully!";
        }
        
        $_SESSION['flash_message_type'] = 'success';
        header("Location: form_templates_list.php");
        exit;
        
    } catch (PDOException $e) {
        $error = "Error saving template: " . $e->getMessage();
    }
}

// Get appointment types for dropdown
$stmt = $conn->query("SELECT id, name FROM appointment_types WHERE is_active = 1 ORDER BY name");
$appointment_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2>
                <i class="bi bi-file-text me-2"></i>
                <?php echo $is_edit ? 'Edit' : 'Create'; ?> Form Template
            </h2>
        </div>
    </div>

    <?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="templateForm">
        <div class="row">
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Basic Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Template Name *</label>
                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($name); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"><?php echo htmlspecialchars($description); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Form Type *</label>
                            <select name="form_type" class="form-select" required>
                                <option value="client_form" <?php echo $form_type == 'client_form' ? 'selected' : ''; ?>>Client Form</option>
                                <option value="session_note" <?php echo $form_type == 'session_note' ? 'selected' : ''; ?>>Session Note</option>
                                <option value="behavior_assessment" <?php echo $form_type == 'behavior_assessment' ? 'selected' : ''; ?>>Behavior Assessment</option>
                                <option value="training_plan" <?php echo $form_type == 'training_plan' ? 'selected' : ''; ?>>Training Plan</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Form Fields</h5>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addField()">
                            <i class="bi bi-plus-circle"></i> Add Field
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="fieldsContainer">
                            <?php if (empty($fields)): ?>
                            <div class="text-muted text-center py-3">
                                No fields added yet. Click "Add Field" to start building your form.
                            </div>
                            <?php else: ?>
                            <?php foreach ($fields as $index => $field): ?>
                            <div class="field-item border rounded p-3 mb-3">
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="form-label">Label *</label>
                                        <input type="text" name="field_label[]" class="form-control" 
                                               value="<?php echo htmlspecialchars($field['label']); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Type *</label>
                                        <select name="field_type[]" class="form-select field-type-select">
                                            <option value="text" <?php echo $field['type'] == 'text' ? 'selected' : ''; ?>>Text</option>
                                            <option value="textarea" <?php echo $field['type'] == 'textarea' ? 'selected' : ''; ?>>Textarea</option>
                                            <option value="select" <?php echo $field['type'] == 'select' ? 'selected' : ''; ?>>Select</option>
                                            <option value="checkbox" <?php echo $field['type'] == 'checkbox' ? 'selected' : ''; ?>>Checkbox</option>
                                            <option value="radio" <?php echo $field['type'] == 'radio' ? 'selected' : ''; ?>>Radio</option>
                                            <option value="file" <?php echo $field['type'] == 'file' ? 'selected' : ''; ?>>File</option>
                                            <option value="date" <?php echo $field['type'] == 'date' ? 'selected' : ''; ?>>Date</option>
                                            <option value="email" <?php echo $field['type'] == 'email' ? 'selected' : ''; ?>>Email</option>
                                            <option value="phone" <?php echo $field['type'] == 'phone' ? 'selected' : ''; ?>>Phone</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Placeholder</label>
                                        <input type="text" name="field_placeholder[]" class="form-control" 
                                               value="<?php echo htmlspecialchars($field['placeholder'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <div class="form-check">
                                            <input type="checkbox" name="field_required[<?php echo $index; ?>]" 
                                                   class="form-check-input" <?php echo ($field['required'] ?? 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Required</label>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-danger ms-2" onclick="removeField(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php if (in_array($field['type'], ['select', 'radio', 'checkbox'])): ?>
                                <div class="row mt-2 field-options-container">
                                    <div class="col-12">
                                        <label class="form-label">Options (one per line)</label>
                                        <textarea name="field_options[]" class="form-control" rows="3"><?php 
                                            if (isset($field['options']) && is_array($field['options'])) {
                                                echo htmlspecialchars(implode("\n", $field['options']));
                                            }
                                        ?></textarea>
                                    </div>
                                </div>
                                <?php else: ?>
                                <textarea name="field_options[]" class="d-none"></textarea>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Configuration</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Required Frequency</label>
                            <select name="required_frequency" class="form-select">
                                <option value="">Optional</option>
                                <option value="once" <?php echo $required_frequency == 'once' ? 'selected' : ''; ?>>Once (ever)</option>
                                <option value="yearly" <?php echo $required_frequency == 'yearly' ? 'selected' : ''; ?>>Once per year</option>
                                <option value="per_appointment" <?php echo $required_frequency == 'per_appointment' ? 'selected' : ''; ?>>Per appointment type</option>
                            </select>
                            <small class="form-text text-muted">When should clients complete this form?</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Appointment Type (optional)</label>
                            <select name="appointment_type_id" class="form-select">
                                <option value="">All appointment types</option>
                                <?php foreach ($appointment_types as $type): ?>
                                <option value="<?php echo $type['id']; ?>" <?php echo $appointment_type_id == $type['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Link to a specific appointment type</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_internal" class="form-check-input" 
                                       id="is_internal" <?php echo $is_internal ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_internal">
                                    Internal Form
                                </label>
                            </div>
                            <small class="form-text text-muted">Admin-only forms (not for clients)</small>
                        </div>
                        
                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" class="form-check-input" 
                                       id="is_active" <?php echo $is_active ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active">
                                    Active
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Save Template
                    </button>
                    <a href="form_templates_list.php" class="btn btn-secondary">
                        <i class="bi bi-x-circle me-1"></i> Cancel
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
let fieldIndex = <?php echo count($fields); ?>;

function addField() {
    const container = document.getElementById('fieldsContainer');
    const firstMsg = container.querySelector('.text-muted');
    if (firstMsg) firstMsg.remove();
    
    const fieldHtml = `
        <div class="field-item border rounded p-3 mb-3">
            <div class="row">
                <div class="col-md-4">
                    <label class="form-label">Label *</label>
                    <input type="text" name="field_label[]" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Type *</label>
                    <select name="field_type[]" class="form-select field-type-select" onchange="toggleOptions(this)">
                        <option value="text">Text</option>
                        <option value="textarea">Textarea</option>
                        <option value="select">Select</option>
                        <option value="checkbox">Checkbox</option>
                        <option value="radio">Radio</option>
                        <option value="file">File</option>
                        <option value="date">Date</option>
                        <option value="email">Email</option>
                        <option value="phone">Phone</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Placeholder</label>
                    <input type="text" name="field_placeholder[]" class="form-control">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" name="field_required[${fieldIndex}]" class="form-check-input">
                        <label class="form-check-label">Required</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger ms-2" onclick="removeField(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <textarea name="field_options[]" class="d-none"></textarea>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', fieldHtml);
    fieldIndex++;
}

function removeField(btn) {
    btn.closest('.field-item').remove();
}

function toggleOptions(select) {
    const fieldItem = select.closest('.field-item');
    const optionsContainer = fieldItem.querySelector('.field-options-container');
    const optionsTextarea = fieldItem.querySelector('textarea[name="field_options[]"]');
    
    if (['select', 'radio', 'checkbox'].includes(select.value)) {
        if (!optionsContainer) {
            const optionsHtml = `
                <div class="row mt-2 field-options-container">
                    <div class="col-12">
                        <label class="form-label">Options (one per line)</label>
                        <textarea name="field_options_temp" class="form-control" rows="3"></textarea>
                    </div>
                </div>
            `;
            fieldItem.querySelector('.row').insertAdjacentHTML('afterend', optionsHtml);
            // Copy value from hidden textarea
            const newTextarea = fieldItem.querySelector('textarea[name="field_options_temp"]');
            newTextarea.value = optionsTextarea.value;
            newTextarea.name = 'field_options[]';
        }
        optionsTextarea.classList.add('d-none');
    } else {
        if (optionsContainer) {
            // Save value before removing
            const visibleTextarea = optionsContainer.querySelector('textarea');
            if (visibleTextarea) {
                optionsTextarea.value = visibleTextarea.value;
            }
            optionsContainer.remove();
        }
        optionsTextarea.classList.remove('d-none');
    }
}

// Initialize options toggle on page load
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.field-type-select').forEach(function(select) {
        toggleOptions(select);
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
