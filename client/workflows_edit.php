<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$workflow_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$is_edit = $workflow_id > 0;

// Get workflow if editing
$workflow = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
    $stmt->execute([$workflow_id]);
    $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$workflow) {
        $_SESSION['error'] = 'Workflow not found';
        header('Location: workflows_list.php');
        exit;
    }
}

// Handle workflow save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        $error = 'Workflow name is required';
    } else {
        if ($is_edit) {
            // Update existing workflow
            $stmt = $conn->prepare("
                UPDATE workflows 
                SET name = ?, description = ?, is_active = ?, updated_at = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $description, $is_active, date('Y-m-d H:i:s'), $workflow_id]);
            $_SESSION['success'] = 'Workflow updated successfully';
        } else {
            // Create new workflow
            $stmt = $conn->prepare("
                INSERT INTO workflows (name, description, is_active)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$name, $description, $is_active]);
            $workflow_id = $conn->lastInsertId();
            $_SESSION['success'] = 'Workflow created successfully';
        }
        
        header('Location: workflows_steps.php?workflow_id=' . $workflow_id);
        exit;
    }
}

// Handle adding a trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trigger']) && $is_edit) {
    $trigger_type = $_POST['trigger_type'] ?? '';
    $appointment_type_id = !empty($_POST['trigger_appointment_type_id']) ? (int)$_POST['trigger_appointment_type_id'] : null;
    $form_template_id = !empty($_POST['trigger_form_template_id']) ? (int)$_POST['trigger_form_template_id'] : null;

    $valid = false;
    if ($trigger_type === 'appointment_booking' && $appointment_type_id) {
        $valid = true;
    } elseif ($trigger_type === 'form_submission' && $form_template_id) {
        $valid = true;
    }

    if ($valid) {
        // Avoid duplicate triggers
        if ($trigger_type === 'appointment_booking') {
            $check = $conn->prepare("
                SELECT id FROM workflow_triggers
                WHERE workflow_id = ? AND trigger_type = ? AND appointment_type_id = ?
            ");
            $check->execute([$workflow_id, $trigger_type, $appointment_type_id]);
        } else {
            $check = $conn->prepare("
                SELECT id FROM workflow_triggers
                WHERE workflow_id = ? AND trigger_type = ? AND form_template_id = ?
            ");
            $check->execute([$workflow_id, $trigger_type, $form_template_id]);
        }
        if (!$check->fetch()) {
            $stmt = $conn->prepare("
                INSERT INTO workflow_triggers (workflow_id, trigger_type, appointment_type_id, form_template_id, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([$workflow_id, $trigger_type, $appointment_type_id, $form_template_id]);
            $_SESSION['success'] = 'Trigger added successfully';
        } else {
            $_SESSION['error'] = 'This trigger already exists for this workflow';
        }
    } else {
        $_SESSION['error'] = 'Please select a valid trigger type and target';
    }

    header('Location: workflows_edit.php?id=' . $workflow_id . '#triggers');
    exit;
}

// Handle deleting a trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_trigger']) && $is_edit) {
    $trigger_id = (int)($_POST['trigger_id'] ?? 0);
    if ($trigger_id) {
        $stmt = $conn->prepare("DELETE FROM workflow_triggers WHERE id = ? AND workflow_id = ?");
        $stmt->execute([$trigger_id, $workflow_id]);
        $_SESSION['success'] = 'Trigger removed successfully';
    }
    header('Location: workflows_edit.php?id=' . $workflow_id . '#triggers');
    exit;
}

// Load triggers and available options when editing
$triggers = [];
$appointment_types = [];
$form_templates = [];
if ($is_edit) {
    $stmt = $conn->prepare("
        SELECT wt.*, 
               at.name as appointment_type_name,
               ft.name as form_template_name
        FROM workflow_triggers wt
        LEFT JOIN appointment_types at ON wt.appointment_type_id = at.id
        LEFT JOIN form_templates ft ON wt.form_template_id = ft.id
        WHERE wt.workflow_id = ?
        ORDER BY wt.created_at
    ");
    $stmt->execute([$workflow_id]);
    $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $appointment_types = $conn->query("SELECT id, name FROM appointment_types WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $form_templates = $conn->query("SELECT id, name FROM form_templates WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
}

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-8 offset-md-2">
            <div class="mb-4 d-flex justify-content-between align-items-center">
                <a href="workflows_list.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Workflows
                </a>
                <?php if ($is_edit): ?>
                    <a href="workflows_steps.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-primary">
                        <i class="fas fa-list-ol"></i> Manage Steps
                    </a>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="mb-0">
                        <?php echo $is_edit ? 'Edit Workflow' : 'Create New Workflow'; ?>
                    </h3>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3">
                            <label for="name" class="form-label">Workflow Name *</label>
                            <input type="text" class="form-control" id="name" name="name" 
                                   value="<?php echo htmlspecialchars($workflow['name'] ?? ''); ?>" 
                                   required>
                            <small class="form-text text-muted">
                                Give your workflow a descriptive name (e.g., "New Client Welcome Series")
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" 
                                      rows="3"><?php echo htmlspecialchars($workflow['description'] ?? ''); ?></textarea>
                            <small class="form-text text-muted">
                                Describe the purpose of this workflow
                            </small>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" 
                                   <?php echo (!$is_edit || $workflow['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">
                                Active (workflow will process enrollments)
                            </label>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="save" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                <?php echo $is_edit ? 'Update Workflow' : 'Create Workflow'; ?>
                            </button>
                            <a href="workflows_list.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($is_edit): ?>
                <!-- Auto-Enrollment Triggers -->
                <div class="card mt-4" id="triggers">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-bolt me-2"></i>Auto-Enrollment Triggers
                            <span class="badge bg-secondary ms-2"><?php echo count($triggers); ?></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">
                            <i class="fas fa-info-circle me-1"></i>
                            Clients will be automatically enrolled in this workflow when they book a specified appointment type or complete a specified form.
                        </p>

                        <?php if (isset($_SESSION['success'])): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if (empty($triggers)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-circle-info me-1"></i>
                                No auto-enrollment triggers configured. Add one below to automatically enroll clients.
                            </div>
                        <?php else: ?>
                            <table class="table table-sm table-bordered mb-4">
                                <thead class="table-light">
                                    <tr>
                                        <th>Trigger Type</th>
                                        <th>Target</th>
                                        <th>Status</th>
                                        <th style="width:80px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($triggers as $trigger): ?>
                                        <tr>
                                            <td>
                                                <?php if ($trigger['trigger_type'] === 'appointment_booking'): ?>
                                                    <i class="fas fa-calendar-check text-primary me-1"></i> Appointment Booking
                                                <?php else: ?>
                                                    <i class="fas fa-file-alt text-success me-1"></i> Form Submission
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($trigger['trigger_type'] === 'appointment_booking'): ?>
                                                    <?php echo htmlspecialchars($trigger['appointment_type_name'] ?? '(deleted)'); ?>
                                                <?php else: ?>
                                                    <?php echo htmlspecialchars($trigger['form_template_name'] ?? '(deleted)'); ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($trigger['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <form method="POST">
                                                    <input type="hidden" name="trigger_id" value="<?php echo (int)$trigger['id']; ?>">
                                                    <button type="submit" name="delete_trigger" class="btn btn-sm btn-outline-danger"
                                                            onclick="return confirm('Remove this trigger?')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                        <!-- Add Trigger Form -->
                        <h6 class="mb-3">Add New Trigger</h6>
                        <form method="POST" class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label">Trigger Type</label>
                                <select class="form-select" name="trigger_type" id="triggerType" required>
                                    <option value="">— Select type —</option>
                                    <option value="appointment_booking">Appointment Booking</option>
                                    <option value="form_submission">Form Submission</option>
                                </select>
                            </div>
                            <div class="col-md-5" id="aptTypeGroup" style="display:none">
                                <label class="form-label">Appointment Type</label>
                                <select class="form-select" name="trigger_appointment_type_id">
                                    <option value="">— Select appointment type —</option>
                                    <?php foreach ($appointment_types as $at): ?>
                                        <option value="<?php echo (int)$at['id']; ?>"><?php echo htmlspecialchars($at['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5" id="formTplGroup" style="display:none">
                                <label class="form-label">Form Template</label>
                                <select class="form-select" name="trigger_form_template_id">
                                    <option value="">— Select form template —</option>
                                    <?php foreach ($form_templates as $ft): ?>
                                        <option value="<?php echo (int)$ft['id']; ?>"><?php echo htmlspecialchars($ft['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" name="add_trigger" class="btn btn-success w-100">
                                    <i class="fas fa-plus me-1"></i> Add Trigger
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Workflow Steps -->
                <div class="card mt-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Workflow Steps</h5>
                        <div class="d-flex gap-2">
                            <a href="workflows_steps_edit.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus"></i> Add Step
                            </a>
                            <a href="workflows_steps.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-list-ol"></i> View All Steps
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <p>After saving your workflow details:</p>
                        <ol>
                            <li>
                                <strong>Add Steps:</strong> 
                                <a href="workflows_steps.php?workflow_id=<?php echo $workflow_id; ?>">
                                    Configure the email sequence
                                </a>
                            </li>
                            <li>
                                <strong>Set Triggers:</strong> 
                                <a href="#triggers">Configure automatic enrollment based on appointments or forms</a>
                            </li>
                            <li>
                                <strong>Enroll Clients:</strong> 
                                <a href="workflows_enroll.php?workflow_id=<?php echo $workflow_id; ?>">
                                    Manually add clients to this workflow
                                </a>
                            </li>
                        </ol>
                    </div>
                </div>
            <?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var typeSelect = document.getElementById('triggerType');
    var aptGroup   = document.getElementById('aptTypeGroup');
    var formGroup  = document.getElementById('formTplGroup');

    if (typeSelect) {
        typeSelect.addEventListener('change', function () {
            aptGroup.style.display  = this.value === 'appointment_booking' ? '' : 'none';
            formGroup.style.display = this.value === 'form_submission'     ? '' : 'none';
        });
    }
});
</script>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
