<?php
/**
 * Form Template Edit/Create Page
 * Create or edit form templates with dynamic form builder
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/form_types.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

$template_id = isset($_GET['id']) ? safe_int($_GET['id']) : null;
$is_edit = $template_id !== null;

// Initialize variables
$name = '';
$description = '';
$form_type = 'client_form';
/** @var list<array<string, mixed>> $fields */
$fields = [];
$required_frequency = '';
$appointment_type_id = null;
$is_internal = 0;
$is_active = 1;
if (!$is_edit && scalar_string($_GET['access'] ?? '') === 'internal') {
    $is_internal = 1;
}

// If editing, load template
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM form_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($template) {
        $name = array_string_value($template, 'name');
        $description = array_string_value($template, 'description');
        $form_type = bdta_normalize_form_type(array_string_value($template, 'form_type', 'client_form'));
        $fields = decode_json_assoc_list(array_string_value($template, 'fields'));
        $required_frequency = array_string_value($template, 'required_frequency');
        $appointment_type_id = array_int_value($template, 'appointment_type_id');
        $is_internal = bdta_form_type_forced_internal($form_type) === 1
            ? 1
            : array_int_value($template, 'is_internal');
        $is_active = array_int_value($template, 'is_active');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token'] ?? ''), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'danger');
        header('Location: form_templates_edit.php' . ($is_edit ? '?id=' . $template_id : ''));
        exit;
    }
    $name = trim(scalar_string($_POST['name'] ?? ''));
    $description = trim(scalar_string($_POST['description'] ?? ''));
    $form_type = bdta_normalize_form_type(scalar_string($_POST['form_type'] ?? 'client_form'));
    $is_internal = bdta_form_type_forced_internal($form_type) === 1
        ? 1
        : safe_int($_POST['is_internal'] ?? $is_internal);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $required_frequency = scalar_string($_POST['required_frequency'] ?? '');
    $appointment_type_id = !empty($_POST['appointment_type_id']) ? safe_int($_POST['appointment_type_id']) : null;
    
    // Build fields array from POST data
    $fields = [];
    $field_types = is_array($_POST['field_type'] ?? null) ? $_POST['field_type'] : [];
    $field_placeholders = is_array($_POST['field_placeholder'] ?? null) ? $_POST['field_placeholder'] : [];
    $field_descriptions = is_array($_POST['field_description'] ?? null) ? $_POST['field_description'] : [];
    $field_required = is_array($_POST['field_required'] ?? null) ? $_POST['field_required'] : [];
    $field_options = is_array($_POST['field_options'] ?? null) ? $_POST['field_options'] : [];
    $field_mappings = is_array($_POST['field_mapping'] ?? null) ? $_POST['field_mapping'] : [];
    if (isset($_POST['field_label']) && is_array($_POST['field_label'])) {
        foreach ($_POST['field_label'] as $index => $label_value) {
            $label = scalar_string($label_value);
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
                $raw_mapping = scalar_string($field_mappings[$index] ?? '');
                $field = [
                    'label' => trim($label),
                    'type' => scalar_string($field_types[$index] ?? 'text'),
                    'placeholder' => trim(scalar_string($field_placeholders[$index] ?? '')),
                    'description' => trim(scalar_string($field_descriptions[$index] ?? '')),
                    'required' => array_key_exists($index, $field_required) ? 1 : 0,
                    'profile_mapping' => in_array($raw_mapping, $allowed_mappings, true) ? $raw_mapping : '',
                ];
                
                // Add options for select, radio, checkbox
                if (in_array(array_string_value($field, 'type'), ['select', 'radio', 'checkbox'], true)) {
                    $options_str = trim(scalar_string($field_options[$index] ?? ''));
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
$appointment_types = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
$form_type_options = bdta_get_form_type_options();
$form_access_state = bdta_get_form_template_access_state($form_type, $is_internal);
$effective_is_internal = $form_access_state['effective_internal'];
$form_access_label = $form_access_state['label'];
$form_access_help = $form_access_state['help'];
$show_direct_link_card = $is_edit && bdta_form_type_allows_direct_link($form_type);
$direct_link_is_public = bdta_form_type_allows_public_submission($form_type) && !$effective_is_internal;
$json_encode_for_html = static function (array $value, string $fallback = '{}'): string {
    $json = json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $json === false ? $fallback : $json;
};
$form_type_js_meta = [];
foreach ($form_type_options as $type_key => $definition) {
    $client_access_state = bdta_get_form_template_access_state($type_key, 0);
    $internal_access_state = bdta_get_form_template_access_state($type_key, 1);
    $form_type_js_meta[$type_key] = [
        'description' => scalar_string($definition['description'] ?? ''),
        'forceInternal' => $client_access_state['forced_internal'],
        'clientLabel' => $client_access_state['label'],
        'clientHelp' => $client_access_state['help'],
        'clientToggleHelp' => $client_access_state['toggle_help'],
        'internalLabel' => $internal_access_state['label'],
        'internalHelp' => $internal_access_state['help'],
        'internalToggleHelp' => $internal_access_state['toggle_help'],
    ];
}
$form_type_js_meta_json = $json_encode_for_html($form_type_js_meta);
$default_form_access = [
    'description' => '',
    'forceInternal' => false,
    'clientLabel' => 'Client facing',
    'clientHelp' => 'This template can be completed by clients, either during booking or via a shared link.',
    'clientToggleHelp' => 'Leave this off to allow clients to complete the form.',
    'internalLabel' => 'Admin only',
    'internalHelp' => 'This template currently requires an admin/staff login to complete.',
    'internalToggleHelp' => 'This template will only be available to admin/staff users.',
];
$default_form_access_json = $json_encode_for_html($default_form_access);

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
        <?php echo htmlspecialchars(scalar_string($error)); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <form method="POST" id="templateForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(scalar_string($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="is_internal" value="<?= (int) $is_internal ?>">
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
                            <select name="form_type" id="form_type" class="form-select" required>
                                <?php foreach ($form_type_options as $type_key => $type_option): ?>
                                <option value="<?php echo htmlspecialchars($type_key); ?>" <?php echo $form_type === $type_key ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(scalar_string($type_option['label'] ?? $type_key)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text" id="form_type_description"><?php echo htmlspecialchars(bdta_get_form_type_description($form_type)); ?></div>
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
                            <?php
                                $field_label = array_string_value($field, 'label');
                                $field_type = array_string_value($field, 'type');
                                $field_placeholder = array_string_value($field, 'placeholder');
                                $field_required = array_int_value($field, 'required');
                                $field_description = array_string_value($field, 'description');
                                $cur_mapping = array_string_value($field, 'profile_mapping');
                                $field_options = isset($field['options']) && is_array($field['options']) ? array_map('scalar_string', $field['options']) : [];
                            ?>
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
                                               value="<?php echo htmlspecialchars($field_label); ?>" required>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Type *</label>
                                        <select name="field_type[]" class="form-select field-type-select" onchange="toggleOptions(this)">
                                            <option value="text" <?php echo $field_type == 'text' ? 'selected' : ''; ?>>Text</option>
                                            <option value="textarea" <?php echo $field_type == 'textarea' ? 'selected' : ''; ?>>Textarea</option>
                                            <option value="select" <?php echo $field_type == 'select' ? 'selected' : ''; ?>>Select</option>
                                            <option value="checkbox" <?php echo $field_type == 'checkbox' ? 'selected' : ''; ?>>Checkbox</option>
                                            <option value="radio" <?php echo $field_type == 'radio' ? 'selected' : ''; ?>>Radio</option>
                                            <option value="file" <?php echo $field_type == 'file' ? 'selected' : ''; ?>>File</option>
                                            <option value="date" <?php echo $field_type == 'date' ? 'selected' : ''; ?>>Date</option>
                                            <option value="email" <?php echo $field_type == 'email' ? 'selected' : ''; ?>>Email</option>
                                            <option value="phone" <?php echo $field_type == 'phone' ? 'selected' : ''; ?>>Phone</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label">Placeholder</label>
                                        <input type="text" name="field_placeholder[]" class="form-control" 
                                               value="<?php echo htmlspecialchars($field_placeholder); ?>">
                                    </div>
                                    <div class="col-md-2 d-flex flex-column align-items-start justify-content-end">
                                        <div class="form-check">
                                            <input type="checkbox" name="field_required[<?php echo $index; ?>]" 
                                                   class="form-check-input" <?php echo $field_required ? 'checked' : ''; ?>>
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
                                        <textarea name="field_description[]" class="form-control" rows="2" placeholder="Add a brief description or instructions for this field..."><?php echo htmlspecialchars($field_description); ?></textarea>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">
                                            Map to Profile
                                            <small class="text-muted d-block">Auto-update profile on submit</small>
                                        </label>
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
                                <?php if (in_array($field_type, ['select', 'radio', 'checkbox'], true)): ?>
                                <div class="row mt-2 field-options-container">
                                    <div class="col-12">
                                        <label class="form-label">Options (one per line)</label>
                                        <textarea name="field_options[]" class="form-control" rows="3"><?php 
                                            if ($field_options !== []) {
                                                echo htmlspecialchars(implode("\n", $field_options));
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
                                <option value="<?php echo array_int_value($type, 'id'); ?>" <?php echo $appointment_type_id == array_int_value($type, 'id') ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(array_string_value($type, 'name')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Use this when the form belongs to a booking or appointment-specific workflow.</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Access</label>
                            <div class="form-check form-switch mb-2">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    id="is_internal_toggle"
                                    <?php echo $form_access_state['effective_internal'] ? 'checked' : ''; ?>
                                    <?php echo !$form_access_state['can_toggle_internal'] ? 'disabled' : ''; ?>
                                >
                                <label class="form-check-label" for="is_internal_toggle">Internal use only</label>
                            </div>
                            <small class="form-text text-muted d-block mb-2" id="is_internal_toggle_help"><?php echo htmlspecialchars($form_access_state['toggle_help']); ?></small>
                            <div class="border rounded px-3 py-2 bg-light">
                                <div class="fw-semibold" id="form_access_label"><?php echo htmlspecialchars($form_access_label); ?></div>
                                <small class="text-muted d-block" id="form_access_help"><?php echo htmlspecialchars($form_access_help); ?></small>
                            </div>
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

                <?php if ($show_direct_link_card): ?>
                    <?php if ($is_active): ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?php echo $direct_link_is_public ? 'Shareable Form Link' : 'Direct Form Link'; ?></h5>
                        <span class="badge bg-secondary"><?php echo $direct_link_is_public ? 'Client facing' : 'Admin only'; ?></span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-2">
                            <?php if (!$direct_link_is_public): ?>
                                Open this link while logged in as an admin/staff user to complete the internal form.
                            <?php else: ?>
                                Share this link so the form can be completed without requiring an admin/staff login.
                            <?php endif; ?>
                        </p>
                        <?php $share_url = getDynamicBaseUrl() . '/backend/public/form.php?template_id=' . (int) $template_id; ?>
                        <div class="input-group">
                            <input type="text" class="form-control" id="form_share_link" value="<?= htmlspecialchars($share_url) ?>" readonly>
                            <button class="btn btn-outline-secondary" type="button" onclick="copyFormShareLink()">
                                <i class="fas fa-copy"></i> Copy
                            </button>
                            <a href="<?= htmlspecialchars($share_url) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary">
                                <i class="fas fa-up-right-from-square"></i> Open
                            </a>
                        </div>
                        <div id="form_share_status" class="form-text text-success visually-hidden mt-1">Link copied!</div>
                    </div>
                </div>
                    <?php else: ?>
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><?php echo $direct_link_is_public ? 'Shareable Form Link' : 'Direct Form Link'; ?></h5>
                        <span class="badge bg-secondary">Unavailable</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-0">
                            <?php if (!$direct_link_is_public): ?>
                                This form is not currently active. Internal forms can only be accessed while active.
                            <?php else: ?>
                                This form is not currently active, so its link is unavailable for sharing.
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                    <?php endif; ?>
                <?php endif; ?>

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
const formTypeMeta = <?= $form_type_js_meta_json ?>;
const defaultFormAccess = <?= $default_form_access_json ?>;

function syncFormTypeDetails() {
    const formTypeSelect = document.getElementById('form_type');
    const isInternalInput = document.querySelector('input[name="is_internal"]');
    const isInternalToggle = document.getElementById('is_internal_toggle');
    const description = document.getElementById('form_type_description');
    const accessLabel = document.getElementById('form_access_label');
    const accessHelp = document.getElementById('form_access_help');
    const toggleHelp = document.getElementById('is_internal_toggle_help');

    if (!formTypeSelect || !description || !accessLabel || !accessHelp || !toggleHelp) {
        return;
    }

    const meta = formTypeMeta[formTypeSelect.value] || defaultFormAccess;
    const isRequestedInternal = isInternalInput && isInternalInput.value === '1';
    const isInternal = !!meta.forceInternal || isRequestedInternal;

    description.textContent = meta.description || '';
    accessLabel.textContent = isInternal ? (meta.internalLabel || 'Admin only') : (meta.clientLabel || 'Client facing');
    accessHelp.textContent = isInternal
        ? (meta.internalHelp || 'This template currently requires an admin/staff login to complete.')
        : (meta.clientHelp || '');

    if (meta.forceInternal) {
        toggleHelp.textContent = 'This form type is always internal and cannot be shared with clients.';
    } else if (isRequestedInternal) {
        toggleHelp.textContent = meta.internalToggleHelp || 'This template will only be available to admin/staff users.';
    } else {
        toggleHelp.textContent = meta.clientToggleHelp || 'Leave this off to allow clients to complete the form.';
    }

    if (isInternalToggle) {
        isInternalToggle.checked = !!meta.forceInternal || isRequestedInternal;
        isInternalToggle.disabled = !!meta.forceInternal;
    }
}

document.getElementById('form_type')?.addEventListener('change', syncFormTypeDetails);
document.getElementById('is_internal_toggle')?.addEventListener('change', function () {
    const isInternalInput = document.querySelector('input[name="is_internal"]');
    if (isInternalInput) {
        isInternalInput.value = this.checked ? '1' : '0';
    }
    syncFormTypeDetails();
});
syncFormTypeDetails();

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
    let optionsContainer = fieldItem.querySelector('.field-options-container');
    let optionsTextarea = fieldItem.querySelector('textarea[name="field_options[]"]');
    const isOptionType = ['select', 'radio', 'checkbox'].includes(select.value);

    // Ensure we always have a single textarea to reuse when toggling
    if (!optionsTextarea) {
        optionsTextarea = document.createElement('textarea');
        optionsTextarea.name = 'field_options[]';
        optionsTextarea.classList.add('d-none');
        fieldItem.appendChild(optionsTextarea);
    }

    if (isOptionType) {
        if (!optionsContainer) {
            optionsContainer = document.createElement('div');
            optionsContainer.className = 'row mt-2 field-options-container';

            const optionsCol = document.createElement('div');
            optionsCol.className = 'col-12';
            optionsCol.innerHTML = '<label class="form-label">Options (one per line)</label>';

            optionsTextarea.classList.remove('d-none');
            optionsTextarea.classList.add('form-control');
            if (!optionsTextarea.hasAttribute('rows')) {
                optionsTextarea.rows = 3;
            }

            optionsCol.appendChild(optionsTextarea);
            optionsContainer.appendChild(optionsCol);
            fieldItem.appendChild(optionsContainer);
        } else {
            // Ensure the textarea lives inside the options container
            let optionsCol = optionsContainer.querySelector('.col-12');
            if (!optionsCol) {
                optionsCol = document.createElement('div');
                optionsCol.className = 'col-12';
                optionsCol.innerHTML = '<label class="form-label">Options (one per line)</label>';
                optionsContainer.appendChild(optionsCol);
            }
            if (!optionsCol.contains(optionsTextarea)) {
                optionsCol.appendChild(optionsTextarea);
            }
            if (!optionsTextarea.hasAttribute('rows')) {
                optionsTextarea.rows = 3;
            }
            optionsTextarea.classList.add('form-control');
            optionsTextarea.classList.remove('d-none');
            optionsContainer.classList.remove('d-none');
        }
    } else {
        if (optionsContainer) {
            optionsContainer.classList.add('d-none');
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

function copyFormShareLink() {
    const input = document.getElementById('form_share_link');
    if (!input) return;

    const link = input.value;
    const status = document.getElementById('form_share_status');

    const showStatus = () => {
        if (!status) return;
        status.classList.remove('visually-hidden');
        status.classList.add('d-block');
        setTimeout(() => {
            status.classList.add('visually-hidden');
            status.classList.remove('d-block');
        }, 2000);
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(link).then(showStatus).catch(() => {
            input.select();
            document.execCommand('copy');
            showStatus();
        });
    } else {
        input.select();
        document.execCommand('copy');
        showStatus();
    }
}
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
