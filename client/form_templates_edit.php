<?php
/**
 * Form Template Edit/Create Page
 * Create or edit form templates with dynamic form builder
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

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
                $allowed_mappings = [
                    '', 'client.name', 'client.email', 'client.phone', 'client.address',
                    'pet_1.name', 'pet_1.species', 'pet_1.breed', 'pet_1.date_of_birth',
                    'pet_1.source', 'pet_1.spayed_neutered', 'pet_1.vaccines_current',
                    'pet_1.vaccine_notes', 'pet_1.behavior_notes', 'pet_1.medical_notes', 'pet_1.training_notes',
                    'pet_2.name', 'pet_2.species', 'pet_2.breed', 'pet_2.date_of_birth',
                    'pet_2.source', 'pet_2.spayed_neutered', 'pet_2.vaccines_current',
                    'pet_2.vaccine_notes', 'pet_2.behavior_notes', 'pet_2.medical_notes', 'pet_2.training_notes',
                    'pet_3.name', 'pet_3.species', 'pet_3.breed', 'pet_3.date_of_birth',
                    'pet_3.source', 'pet_3.spayed_neutered', 'pet_3.vaccines_current',
                    'pet_3.vaccine_notes', 'pet_3.behavior_notes', 'pet_3.medical_notes', 'pet_3.training_notes',
                    'booking.notes',
                ];
                $raw_mapping = $_POST['field_mapping'][$index] ?? '';
                $field = [
                    'label' => trim($label),
                    'type' => $_POST['field_type'][$index] ?? 'text',
                    'placeholder' => trim($_POST['field_placeholder'][$index] ?? ''),
                    'description' => trim($_POST['field_description'][$index] ?? ''),
                    'required' => isset($_POST['field_required'][$index]) ? 1 : 0,
                    'profile_mapping' => in_array($raw_mapping, $allowed_mappings) ? $raw_mapping : '',
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

require_once '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <h2>
                <i class="fas fa-file-lines me-2"></i>
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
                                <option value="booking_form" <?php echo $form_type == 'booking_form' ? 'selected' : ''; ?>>Booking Intake Form</option>
                                <option value="client_form" <?php echo $form_type == 'client_form' ? 'selected' : ''; ?>>Client Form</option>
                                <option value="session_note" <?php echo $form_type == 'session_note' ? 'selected' : ''; ?>>Session Note</option>
                                <option value="behavior_assessment" <?php echo $form_type == 'behavior_assessment' ? 'selected' : ''; ?>>Behavior Assessment</option>
                                <option value="training_plan" <?php echo $form_type == 'training_plan' ? 'selected' : ''; ?>>Training Plan</option>
                            </select>
                            <div class="form-text">Use <strong>Booking Intake Form</strong> to customize the public booking page fields (name, email, phone, pet name, notes). Configure the active form under Settings → Booking.</div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Form Fields</h5>
                        <button type="button" class="btn btn-sm btn-primary" onclick="addField()">
                            <i class="fas fa-circle-plus"></i> Add Field
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="fieldsContainer">
                            <?php if (empty($fields)): ?>
                            <div class="text-muted text-center py-3 fields-empty-msg">
                                No fields added yet. Click "Add Field" to start building your form.
                            </div>
                            <?php else: ?>
                            <?php foreach ($fields as $index => $field): ?>
                            <div class="field-item border rounded p-3 mb-3">
                                <div class="field-item-header d-flex align-items-center pb-2 mb-3 border-bottom">
                                    <i class="fas fa-grip-vertical drag-handle text-muted me-2 fs-5" style="cursor:grab" title="Drag to reorder"></i>
                                    <span class="small text-muted">Field <?php echo $index + 1; ?></span>
                                    <div class="ms-auto d-flex gap-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary move-up-btn" onclick="moveField(this, -1)" title="Move Up" aria-label="Move field up"><i class="fas fa-arrow-up"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary move-down-btn" onclick="moveField(this, 1)" title="Move Down" aria-label="Move field down"><i class="fas fa-arrow-down"></i></button>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="form-label">Label *</label>
                                        <input type="text" name="field_label[]" class="form-control" 
                                               value="<?php echo htmlspecialchars($field['label']); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Type *</label>
                                        <select name="field_type[]" class="form-select field-type-select" onchange="toggleOptions(this)">
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
                                    <div class="col-md-2 d-flex flex-column align-items-start justify-content-end">
                                        <div class="form-check">
                                            <input type="checkbox" name="field_required[<?php echo $index; ?>]" 
                                                   class="form-check-input" <?php echo ($field['required'] ?? 0) ? 'checked' : ''; ?>>
                                            <label class="form-check-label">Required</label>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-danger mt-1" onclick="removeField(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="row mt-2">
                                    <div class="col-md-8">
                                        <label class="form-label">Description <small class="text-muted">(optional — shown to clients below the field)</small></label>
                                        <textarea name="field_description[]" class="form-control" rows="2" placeholder="Add a brief description or instructions for this field..."><?php echo htmlspecialchars($field['description'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">
                                            Map to Profile
                                            <small class="text-muted d-block">Auto-update profile on submit</small>
                                        </label>
                                        <?php $cur_mapping = $field['profile_mapping'] ?? ''; ?>
                                        <select name="field_mapping[<?php echo $index; ?>]" class="form-select form-select-sm">
                                            <option value="">— None —</option>
                                            <optgroup label="Client Profile">
                                                <option value="client.name" <?php echo $cur_mapping === 'client.name' ? 'selected' : ''; ?>>Client: Name</option>
                                                <option value="client.email" <?php echo $cur_mapping === 'client.email' ? 'selected' : ''; ?>>Client: Email</option>
                                                <option value="client.phone" <?php echo $cur_mapping === 'client.phone' ? 'selected' : ''; ?>>Client: Phone</option>
                                                <option value="client.address" <?php echo $cur_mapping === 'client.address' ? 'selected' : ''; ?>>Client: Address</option>
                                            </optgroup>
                                            <?php for ($p = 1; $p <= 3; $p++): ?>
                                            <optgroup label="Pet <?php echo $p; ?> Profile">
                                                <option value="pet_<?php echo $p; ?>.name" <?php echo $cur_mapping === "pet_{$p}.name" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Name</option>
                                                <option value="pet_<?php echo $p; ?>.species" <?php echo $cur_mapping === "pet_{$p}.species" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Species</option>
                                                <option value="pet_<?php echo $p; ?>.breed" <?php echo $cur_mapping === "pet_{$p}.breed" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Breed</option>
                                                <option value="pet_<?php echo $p; ?>.date_of_birth" <?php echo $cur_mapping === "pet_{$p}.date_of_birth" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Date of Birth</option>
                                                <option value="pet_<?php echo $p; ?>.source" <?php echo $cur_mapping === "pet_{$p}.source" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Source</option>
                                                <option value="pet_<?php echo $p; ?>.spayed_neutered" <?php echo $cur_mapping === "pet_{$p}.spayed_neutered" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Spayed/Neutered</option>
                                                <option value="pet_<?php echo $p; ?>.vaccines_current" <?php echo $cur_mapping === "pet_{$p}.vaccines_current" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Vaccines Current</option>
                                                <option value="pet_<?php echo $p; ?>.vaccine_notes" <?php echo $cur_mapping === "pet_{$p}.vaccine_notes" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Vaccine Notes</option>
                                                <option value="pet_<?php echo $p; ?>.behavior_notes" <?php echo $cur_mapping === "pet_{$p}.behavior_notes" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Behavior Notes</option>
                                                <option value="pet_<?php echo $p; ?>.medical_notes" <?php echo $cur_mapping === "pet_{$p}.medical_notes" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Medical Notes</option>
                                                <option value="pet_<?php echo $p; ?>.training_notes" <?php echo $cur_mapping === "pet_{$p}.training_notes" ? 'selected' : ''; ?>>Pet <?php echo $p; ?>: Training Notes</option>
                                            </optgroup>
                                            <?php endfor; ?>
                                            <optgroup label="Booking">
                                                <option value="booking.notes" <?php echo $cur_mapping === 'booking.notes' ? 'selected' : ''; ?>>Booking: Notes</option>
                                            </optgroup>
                                        </select>
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
                        <i class="fas fa-floppy-disk me-1"></i> Save Template
                    </button>
                    <a href="form_templates_list.php" class="btn btn-secondary">
                        <i class="fas fa-circle-xmark me-1"></i> Cancel
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js" integrity="sha384-OLBgp1GsljhM2TJ+sbHjaiH9txEUvgdDTAzHv2P24donTt6/529l+9Ua0vFImLlb" crossorigin="anonymous"></script>
<script>
let fieldIndex = <?php echo count($fields); ?>;

function addField() {
    const container = document.getElementById('fieldsContainer');
    const firstMsg = container.querySelector('.fields-empty-msg');
    if (firstMsg) firstMsg.remove();

    const fieldHtml = `
        <div class="field-item border rounded p-3 mb-3">
            <div class="field-item-header d-flex align-items-center pb-2 mb-3 border-bottom">
                <i class="fas fa-grip-vertical drag-handle text-muted me-2 fs-5" style="cursor:grab" title="Drag to reorder"></i>
                <span class="small text-muted">New Field</span>
                <div class="ms-auto d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary move-up-btn" onclick="moveField(this, -1)" title="Move Up" aria-label="Move field up"><i class="fas fa-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary move-down-btn" onclick="moveField(this, 1)" title="Move Down" aria-label="Move field down"><i class="fas fa-arrow-down"></i></button>
                </div>
            </div>
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
                <div class="col-md-2 d-flex flex-column align-items-start justify-content-end">
                    <div class="form-check">
                        <input type="checkbox" name="field_required[${fieldIndex}]" class="form-check-input">
                        <label class="form-check-label">Required</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-danger mt-1" onclick="removeField(this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-8">
                    <label class="form-label">Description <small class="text-muted">(optional — shown to clients below the field)</small></label>
                    <textarea name="field_description[]" class="form-control" rows="2" placeholder="Add a brief description or instructions for this field..."></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">
                        Map to Profile
                        <small class="text-muted d-block">Auto-update profile on submit</small>
                    </label>
                    <select name="field_mapping[${fieldIndex}]" class="form-select form-select-sm">
                        <option value="">— None —</option>
                        <optgroup label="Client Profile">
                            <option value="client.name">Client: Name</option>
                            <option value="client.email">Client: Email</option>
                            <option value="client.phone">Client: Phone</option>
                            <option value="client.address">Client: Address</option>
                        </optgroup>
                        <optgroup label="Pet 1 Profile">
                            <option value="pet_1.name">Pet 1: Name</option>
                            <option value="pet_1.species">Pet 1: Species</option>
                            <option value="pet_1.breed">Pet 1: Breed</option>
                            <option value="pet_1.date_of_birth">Pet 1: Date of Birth</option>
                            <option value="pet_1.source">Pet 1: Source</option>
                            <option value="pet_1.spayed_neutered">Pet 1: Spayed/Neutered</option>
                            <option value="pet_1.vaccines_current">Pet 1: Vaccines Current</option>
                            <option value="pet_1.vaccine_notes">Pet 1: Vaccine Notes</option>
                            <option value="pet_1.behavior_notes">Pet 1: Behavior Notes</option>
                            <option value="pet_1.medical_notes">Pet 1: Medical Notes</option>
                            <option value="pet_1.training_notes">Pet 1: Training Notes</option>
                        </optgroup>
                        <optgroup label="Pet 2 Profile">
                            <option value="pet_2.name">Pet 2: Name</option>
                            <option value="pet_2.species">Pet 2: Species</option>
                            <option value="pet_2.breed">Pet 2: Breed</option>
                            <option value="pet_2.date_of_birth">Pet 2: Date of Birth</option>
                            <option value="pet_2.source">Pet 2: Source</option>
                            <option value="pet_2.spayed_neutered">Pet 2: Spayed/Neutered</option>
                            <option value="pet_2.vaccines_current">Pet 2: Vaccines Current</option>
                            <option value="pet_2.vaccine_notes">Pet 2: Vaccine Notes</option>
                            <option value="pet_2.behavior_notes">Pet 2: Behavior Notes</option>
                            <option value="pet_2.medical_notes">Pet 2: Medical Notes</option>
                            <option value="pet_2.training_notes">Pet 2: Training Notes</option>
                        </optgroup>
                        <optgroup label="Pet 3 Profile">
                            <option value="pet_3.name">Pet 3: Name</option>
                            <option value="pet_3.species">Pet 3: Species</option>
                            <option value="pet_3.breed">Pet 3: Breed</option>
                            <option value="pet_3.date_of_birth">Pet 3: Date of Birth</option>
                            <option value="pet_3.source">Pet 3: Source</option>
                            <option value="pet_3.spayed_neutered">Pet 3: Spayed/Neutered</option>
                            <option value="pet_3.vaccines_current">Pet 3: Vaccines Current</option>
                            <option value="pet_3.vaccine_notes">Pet 3: Vaccine Notes</option>
                            <option value="pet_3.behavior_notes">Pet 3: Behavior Notes</option>
                            <option value="pet_3.medical_notes">Pet 3: Medical Notes</option>
                            <option value="pet_3.training_notes">Pet 3: Training Notes</option>
                        </optgroup>
                        <optgroup label="Booking">
                            <option value="booking.notes">Booking: Notes</option>
                        </optgroup>
                    </select>
                </div>
            </div>
            <textarea name="field_options[]" class="d-none"></textarea>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', fieldHtml);
    fieldIndex++;
    updateMoveButtons();
}

function removeField(btn) {
    btn.closest('.field-item').remove();
    updateMoveButtons();
    const container = document.getElementById('fieldsContainer');
    if (!container.querySelector('.field-item')) {
        container.innerHTML = '<div class="text-muted text-center py-3 fields-empty-msg">No fields added yet. Click "Add Field" to start building your form.</div>';
    }
}

function moveField(btn, direction) {
    const field = btn.closest('.field-item');
    const container = document.getElementById('fieldsContainer');
    const fields = Array.from(container.querySelectorAll('.field-item'));
    const index = fields.indexOf(field);

    if (direction === -1 && index > 0) {
        container.insertBefore(field, fields[index - 1]);
    } else if (direction === 1 && index < fields.length - 1) {
        container.insertBefore(fields[index + 1], field);
    }
    updateMoveButtons();
}

function updateMoveButtons() {
    const fields = document.querySelectorAll('#fieldsContainer .field-item');
    const total = fields.length;
    fields.forEach(function(field, index) {
        const upBtn = field.querySelector('.move-up-btn');
        const downBtn = field.querySelector('.move-down-btn');
        if (upBtn) upBtn.disabled = (total === 1 || index === 0);
        if (downBtn) downBtn.disabled = (total === 1 || index === total - 1);
    });
}

function reindexFields() {
    const fields = document.querySelectorAll('#fieldsContainer .field-item');
    fields.forEach(function(field, newIndex) {
        const reqCheckbox = field.querySelector('input.form-check-input[name^="field_required"]');
        if (reqCheckbox) {
            reqCheckbox.name = 'field_required[' + newIndex + ']';
        }
        const mappingSelect = field.querySelector('select[name^="field_mapping"]');
        if (mappingSelect) {
            mappingSelect.name = 'field_mapping[' + newIndex + ']';
        }
    });
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
            // Insert before the hidden textarea (always last child)
            optionsTextarea.insertAdjacentHTML('beforebegin', optionsHtml);
            // Copy value from hidden textarea
            const newTextarea = fieldItem.querySelector('textarea[name="field_options_temp"]');
            newTextarea.value = optionsTextarea.value;
            newTextarea.name = 'field_options[]';
            optionsTextarea.classList.add('d-none');
        }
    } else {
        if (optionsContainer) {
            // Save value before removing
            const visibleTextarea = optionsContainer.querySelector('textarea');
            if (visibleTextarea) {
                optionsTextarea.value = visibleTextarea.value;
            }
            optionsContainer.remove();
        }
        optionsTextarea.classList.add('d-none');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Initialize options visibility for existing fields
    document.querySelectorAll('.field-type-select').forEach(function(select) {
        toggleOptions(select);
    });

    // Initialize move button states
    updateMoveButtons();

    // Initialize drag-and-drop reordering
    Sortable.create(document.getElementById('fieldsContainer'), {
        handle: '.drag-handle',
        animation: 150,
        ghostClass: 'sortable-ghost',
        onEnd: function() {
            updateMoveButtons();
        }
    });

    // Renumber field_required and field_mapping names before submit
    document.getElementById('templateForm').addEventListener('submit', function() {
        reindexFields();
    });
});
</script>

<style>
.sortable-ghost {
    opacity: 0.4;
}
.drag-handle:active {
    cursor: grabbing;
}
</style>

<?php require_once '../backend/includes/footer.php'; ?>
