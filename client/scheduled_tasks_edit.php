<?php
/**
 * Create/Edit Scheduled Task
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

$task_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$is_edit = $task_id > 0;

// Load task if editing
$task = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM scheduled_tasks WHERE id = ?");
    $stmt->execute([$task_id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$task) {
        $_SESSION['error'] = "Task not found";
        header('Location: scheduled_tasks_list.php');
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $task_name = trim($_POST['task_name']);
    $task_type = trim($_POST['task_type']);
    $schedule_type = trim($_POST['schedule_type']);
    $schedule_value = trim($_POST['schedule_value']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($task_name) || empty($task_type) || empty($schedule_type)) {
        $_SESSION['error'] = "Task name, type, and schedule type are required";
    } else {
        try {
            if ($is_edit) {
                $stmt = $conn->prepare("
                    UPDATE scheduled_tasks SET 
                        task_name = ?, task_type = ?, schedule_type = ?, 
                        schedule_value = ?, is_active = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$task_name, $task_type, $schedule_type, $schedule_value, $is_active, $task_id]);
                $_SESSION['success'] = "Task updated successfully";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO scheduled_tasks (task_name, task_type, schedule_type, schedule_value, is_active)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$task_name, $task_type, $schedule_type, $schedule_value, $is_active]);
                $_SESSION['success'] = "Task created successfully";
            }
            
            header('Location: scheduled_tasks_list.php');
            exit;
            
        } catch (Exception $e) {
            $_SESSION['error'] = "Error saving task: " . $e->getMessage();
        }
    }
}

$page_title = $is_edit ? "Edit Scheduled Task" : "Create Scheduled Task";
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <h2><i class="fas fa-clock me-2"></i><?= $is_edit ? 'Edit Scheduled Task' : 'Create Scheduled Task' ?></h2>
        </div>
        <div class="col-auto">
            <a href="scheduled_tasks_list.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i>Back to Tasks
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="row">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Task Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Task Name *</label>
                            <input type="text" name="task_name" class="form-control" 
                                   value="<?= $task ? htmlspecialchars($task['task_name']) : '' ?>" 
                                   placeholder="e.g., Send Daily Report Emails" required>
                            <small class="text-muted">Descriptive name for this scheduled task</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Task Type *</label>
                            <select name="task_type" class="form-select" required>
                                <option value="">Select task type...</option>
                                <option value="email" <?= ($task && $task['task_type'] === 'email') ? 'selected' : '' ?>>Email Task</option>
                                <option value="reminder" <?= ($task && $task['task_type'] === 'reminder') ? 'selected' : '' ?>>Reminder Task</option>
                                <option value="booking" <?= ($task && $task['task_type'] === 'booking') ? 'selected' : '' ?>>Booking Task</option>
                                <option value="report" <?= ($task && $task['task_type'] === 'report') ? 'selected' : '' ?>>Report Generation</option>
                                <option value="cleanup" <?= ($task && $task['task_type'] === 'cleanup') ? 'selected' : '' ?>>Data Cleanup</option>
                                <option value="notification" <?= ($task && $task['task_type'] === 'notification') ? 'selected' : '' ?>>Notification Task</option>
                                <option value="workflow" <?= ($task && $task['task_type'] === 'workflow') ? 'selected' : '' ?>>Workflow Execution</option>
                                <option value="other" <?= ($task && $task['task_type'] === 'other') ? 'selected' : '' ?>>Other</option>
                            </select>
                            <small class="text-muted">The category of automated task</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Schedule Type *</label>
                            <select name="schedule_type" class="form-select" id="schedule_type" required>
                                <option value="">Select schedule type...</option>
                                <option value="hourly" <?= ($task && $task['schedule_type'] === 'hourly') ? 'selected' : '' ?>>Hourly</option>
                                <option value="daily" <?= ($task && $task['schedule_type'] === 'daily') ? 'selected' : '' ?>>Daily</option>
                                <option value="weekly" <?= ($task && $task['schedule_type'] === 'weekly') ? 'selected' : '' ?>>Weekly</option>
                                <option value="monthly" <?= ($task && $task['schedule_type'] === 'monthly') ? 'selected' : '' ?>>Monthly</option>
                                <option value="custom" <?= ($task && $task['schedule_type'] === 'custom') ? 'selected' : '' ?>>Custom (cron)</option>
                            </select>
                            <small class="text-muted">How often this task should run</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Schedule Value</label>
                            <input type="text" name="schedule_value" class="form-control" 
                                   value="<?= $task ? htmlspecialchars($task['schedule_value']) : '' ?>" 
                                   placeholder="e.g., 09:00, Monday, 1st, */5 * * * *">
                            <small class="text-muted">
                                Optional: Specify time (HH:MM), day name, day of month, or cron expression
                            </small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Task Status</h5>
                    </div>
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" 
                                   <?= !$task || $task['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active Task
                            </label>
                            <div class="form-text">
                                Only active tasks will be executed by the scheduler
                            </div>
                        </div>

                        <?php if ($task && $task['last_run']): ?>
                            <hr>
                            <div class="mb-2">
                                <strong>Last Run:</strong><br>
                                <small class="text-muted">
                                    <?= date('M j, Y g:i A', strtotime($task['last_run'])) ?>
                                </small>
                            </div>
                        <?php endif; ?>

                        <?php if ($task && $task['next_run']): ?>
                            <div class="mb-2">
                                <strong>Next Run:</strong><br>
                                <small class="text-muted">
                                    <?= date('M j, Y g:i A', strtotime($task['next_run'])) ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-floppy-disk me-1"></i>
                    <?= $is_edit ? 'Update Task' : 'Create Task' ?>
                </button>

                <?php if ($is_edit): ?>
                    <a href="scheduled_tasks_logs.php?task_id=<?= $task_id ?>" class="btn btn-outline-info w-100 mt-2">
                        <i class="fas fa-list me-1"></i>View Task Logs
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<script>
// Show helpful hints based on schedule type selection
document.getElementById('schedule_type').addEventListener('change', function() {
    const scheduleValue = document.querySelector('input[name="schedule_value"]');
    const hint = scheduleValue.nextElementSibling;
    
    switch(this.value) {
        case 'hourly':
            hint.textContent = 'Optional: Specify minute (0-59), e.g., "0" for top of the hour';
            break;
        case 'daily':
            hint.textContent = 'Optional: Specify time in HH:MM format, e.g., "09:00" for 9 AM';
            break;
        case 'weekly':
            hint.textContent = 'Optional: Specify day name and time, e.g., "Monday 09:00"';
            break;
        case 'monthly':
            hint.textContent = 'Optional: Specify day of month and time, e.g., "1st 09:00" or "15 14:30"';
            break;
        case 'custom':
            hint.textContent = 'Enter cron expression, e.g., "*/5 * * * *" for every 5 minutes';
            break;
        default:
            hint.textContent = 'Optional: Specify time (HH:MM), day name, day of month, or cron expression';
    }
});
</script>

<?php include '../backend/includes/footer.php'; ?>
