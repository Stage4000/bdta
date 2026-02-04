<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$workflow_id = isset($_GET['workflow_id']) ? (int)$_GET['workflow_id'] : 0;
$step_id = isset($_GET['step_id']) ? (int)$_GET['step_id'] : 0;
$is_edit = $step_id > 0;

// Get workflow details
$stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
$stmt->execute([$workflow_id]);
$workflow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$workflow) {
    $_SESSION['error'] = 'Workflow not found';
    header('Location: workflows_list.php');
    exit;
}

// Get step if editing
$step = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM workflow_steps WHERE id = ? AND workflow_id = ?");
    $stmt->execute([$step_id, $workflow_id]);
    $step = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$step) {
        $_SESSION['error'] = 'Step not found';
        header('Location: workflows_steps.php?workflow_id=' . $workflow_id);
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $step_name = trim($_POST['step_name']);
    $email_subject = trim($_POST['email_subject']);
    $email_body_html = trim($_POST['email_body_html']);
    $email_body_text = trim($_POST['email_body_text']);
    $delay_type = $_POST['delay_type'];
    $delay_value = trim($_POST['delay_value']);
    $scheduled_date = $_POST['scheduled_date'] ?? null;
    $attach_contract_id = !empty($_POST['attach_contract_id']) ? (int)$_POST['attach_contract_id'] : null;
    $attach_form_id = !empty($_POST['attach_form_id']) ? (int)$_POST['attach_form_id'] : null;
    $include_appointment_link = isset($_POST['include_appointment_link']) ? 1 : 0;
    $appointment_type_id = !empty($_POST['appointment_type_id']) ? (int)$_POST['appointment_type_id'] : null;
    
    if (empty($step_name) || empty($email_subject) || empty($email_body_html)) {
        $error = 'Step name, email subject, and email body are required';
    } else {
        // Get next step order
        if (!$is_edit) {
            $stmt = $conn->prepare("SELECT MAX(step_order) as max_order FROM workflow_steps WHERE workflow_id = ?");
            $stmt->execute([$workflow_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $step_order = ($result['max_order'] ?? 0) + 1;
        } else {
            $step_order = $step['step_order'];
        }
        
        if ($is_edit) {
            // Update existing step
            $stmt = $conn->prepare("
                UPDATE workflow_steps 
                SET step_name = ?, email_subject = ?, email_body_html = ?, email_body_text = ?,
                    delay_type = ?, delay_value = ?, scheduled_date = ?,
                    attach_contract_id = ?, attach_form_id = ?,
                    include_appointment_link = ?, appointment_type_id = ?,
                    updated_at = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $step_name, $email_subject, $email_body_html, $email_body_text,
                $delay_type, $delay_value, $scheduled_date,
                $attach_contract_id, $attach_form_id,
                $include_appointment_link, $appointment_type_id,
                date('Y-m-d H:i:s'), $step_id
            ]);
            $_SESSION['success'] = 'Step updated successfully';
        } else {
            // Create new step
            $stmt = $conn->prepare("
                INSERT INTO workflow_steps (
                    workflow_id, step_order, step_name, email_subject, email_body_html, email_body_text,
                    delay_type, delay_value, scheduled_date,
                    attach_contract_id, attach_form_id,
                    include_appointment_link, appointment_type_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $workflow_id, $step_order, $step_name, $email_subject, $email_body_html, $email_body_text,
                $delay_type, $delay_value, $scheduled_date,
                $attach_contract_id, $attach_form_id,
                $include_appointment_link, $appointment_type_id
            ]);
            $_SESSION['success'] = 'Step created successfully';
        }
        
        header('Location: workflows_steps.php?workflow_id=' . $workflow_id);
        exit;
    }
}

// Get options for dropdowns
$contract_templates = $conn->query("SELECT id, name FROM contract_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$form_templates = $conn->query("SELECT id, name FROM form_templates ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$appointment_types = $conn->query("SELECT id, name FROM appointment_types WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-lg-10 offset-lg-1">
            <div class="mb-4">
                <a href="workflows_steps.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Steps
                </a>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0">
                        <?php echo $is_edit ? 'Edit Step' : 'Add New Step'; ?> 
                        - <?php echo htmlspecialchars($workflow['name']); ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <!-- Step Name -->
                        <div class="mb-3">
                            <label for="step_name" class="form-label">Step Name *</label>
                            <input type="text" class="form-control" id="step_name" name="step_name" 
                                   value="<?php echo htmlspecialchars($step['step_name'] ?? ''); ?>" 
                                   required>
                            <small class="form-text text-muted">
                                Internal name for this step (e.g., "Welcome Email", "Day 3 Follow-up")
                            </small>
                        </div>

                        <!-- Email Subject -->
                        <div class="mb-3">
                            <label for="email_subject" class="form-label">Email Subject *</label>
                            <input type="text" class="form-control" id="email_subject" name="email_subject" 
                                   value="<?php echo htmlspecialchars($step['email_subject'] ?? ''); ?>" 
                                   required>
                            <small class="form-text text-muted">
                                Available placeholders: {client_name}, {workflow_name}, {step_name}
                            </small>
                        </div>

                        <!-- Email Body HTML -->
                        <div class="mb-3">
                            <label for="email_body_html" class="form-label">Email Body (HTML) *</label>
                            <textarea class="form-control" id="email_body_html" name="email_body_html" 
                                      rows="10" required><?php echo htmlspecialchars($step['email_body_html'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">
                                HTML email content. Placeholders: {client_name}, {workflow_name}, {step_name}
                            </small>
                        </div>

                        <!-- Email Body Text -->
                        <div class="mb-3">
                            <label for="email_body_text" class="form-label">Email Body (Plain Text)</label>
                            <textarea class="form-control" id="email_body_text" name="email_body_text" 
                                      rows="6"><?php echo htmlspecialchars($step['email_body_text'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">
                                Plain text version (optional, will auto-generate from HTML if left blank)
                            </small>
                        </div>

                        <!-- Delay Type -->
                        <div class="mb-3">
                            <label for="delay_type" class="form-label">When to Send *</label>
                            <select class="form-select" id="delay_type" name="delay_type" required>
                                <option value="immediate" <?php echo ($step['delay_type'] ?? '') === 'immediate' ? 'selected' : ''; ?>>
                                    Immediately upon enrollment
                                </option>
                                <option value="after_enrollment" <?php echo ($step['delay_type'] ?? '') === 'after_enrollment' ? 'selected' : ''; ?>>
                                    After enrollment (specify delay)
                                </option>
                                <option value="after_previous" <?php echo ($step['delay_type'] ?? '') === 'after_previous' ? 'selected' : ''; ?>>
                                    After previous step (specify delay)
                                </option>
                                <option value="specific_date" <?php echo ($step['delay_type'] ?? '') === 'specific_date' ? 'selected' : ''; ?>>
                                    On a specific date
                                </option>
                            </select>
                        </div>

                        <!-- Delay Value -->
                        <div class="mb-3" id="delay_value_group">
                            <label for="delay_value" class="form-label">Delay</label>
                            <input type="text" class="form-control" id="delay_value" name="delay_value" 
                                   value="<?php echo htmlspecialchars($step['delay_value'] ?? ''); ?>"
                                   placeholder="e.g., 3 days, 2 hours, 30 minutes">
                            <small class="form-text text-muted">
                                Examples: "3 days", "2 hours", "30 minutes", "1 week"
                            </small>
                        </div>

                        <!-- Scheduled Date -->
                        <div class="mb-3" id="scheduled_date_group" style="display: none;">
                            <label for="scheduled_date" class="form-label">Scheduled Date</label>
                            <input type="datetime-local" class="form-control" id="scheduled_date" name="scheduled_date" 
                                   value="<?php echo isset($step['scheduled_date']) ? date('Y-m-d\TH:i', strtotime($step['scheduled_date'])) : ''; ?>">
                        </div>

                        <hr class="my-4">

                        <!-- Attachments Section -->
                        <h5 class="mb-3">Attachments & Links</h5>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="attach_contract_id" class="form-label">Attach Contract Template</label>
                                <select class="form-select" id="attach_contract_id" name="attach_contract_id">
                                    <option value="">None</option>
                                    <?php foreach ($contract_templates as $template): ?>
                                        <option value="<?php echo $template['id']; ?>" 
                                                <?php echo ($step['attach_contract_id'] ?? 0) == $template['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($template['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="attach_form_id" class="form-label">Attach Form Template</label>
                                <select class="form-select" id="attach_form_id" name="attach_form_id">
                                    <option value="">None</option>
                                    <?php foreach ($form_templates as $template): ?>
                                        <option value="<?php echo $template['id']; ?>"
                                                <?php echo ($step['attach_form_id'] ?? 0) == $template['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($template['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="include_appointment_link" 
                                   name="include_appointment_link" value="1"
                                   <?php echo ($step['include_appointment_link'] ?? 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="include_appointment_link">
                                Include appointment booking link
                            </label>
                        </div>

                        <div class="mb-3" id="appointment_type_group" style="<?php echo ($step['include_appointment_link'] ?? 0) ? '' : 'display:none;'; ?>">
                            <label for="appointment_type_id" class="form-label">Appointment Type</label>
                            <select class="form-select" id="appointment_type_id" name="appointment_type_id">
                                <option value="">Any</option>
                                <?php foreach ($appointment_types as $apt_type): ?>
                                    <option value="<?php echo $apt_type['id']; ?>"
                                            <?php echo ($step['appointment_type_id'] ?? 0) == $apt_type['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($apt_type['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" name="save" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <?php echo $is_edit ? 'Update Step' : 'Create Step'; ?>
                            </button>
                            <a href="workflows_steps.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-outline-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Show/hide delay fields based on delay type
document.getElementById('delay_type')?.addEventListener('change', function() {
    const delayValue = document.getElementById('delay_value_group');
    const scheduledDate = document.getElementById('scheduled_date_group');
    
    if (this.value === 'specific_date') {
        delayValue.style.display = 'none';
        scheduledDate.style.display = 'block';
    } else if (this.value === 'immediate') {
        delayValue.style.display = 'none';
        scheduledDate.style.display = 'none';
    } else {
        delayValue.style.display = 'block';
        scheduledDate.style.display = 'none';
    }
});

// Show/hide appointment type based on checkbox
document.getElementById('include_appointment_link')?.addEventListener('change', function() {
    document.getElementById('appointment_type_group').style.display = this.checked ? 'block' : 'none';
});

// Trigger initial state
document.getElementById('delay_type')?.dispatchEvent(new Event('change'));
</script>

<?php include '../backend/includes/footer.php'; ?>
