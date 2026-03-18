<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/workflow_helper.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();
$workflow_helper = new WorkflowHelper($conn);

$workflow_id = safe_int($_GET['workflow_id'] ?? 0);

// Get workflow details
$stmt = $conn->prepare("SELECT * FROM workflows WHERE id = ?");
$stmt->execute([$workflow_id]);
$workflow = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

if ($workflow === []) {
    $_SESSION['error'] = 'Workflow not found';
    header('Location: workflows_list.php');
    exit;
}
// Handle cancellation
if (isset($_GET['cancel']) && isset($_GET['enrollment_id'])) {
    $enrollment_id = safe_int($_GET['enrollment_id']);
    $workflow_helper->cancelEnrollment($enrollment_id);
    $_SESSION['success'] = 'Enrollment cancelled successfully';
    header('Location: workflows_enrollments.php?workflow_id=' . $workflow_id);
    exit;
}

// Get enrollments
$enrollments = $conn->prepare("
    SELECT we.*, c.name as client_name, c.email as client_email,
           au.username as enrolled_by_name
    FROM workflow_enrollments we
    JOIN clients c ON we.client_id = c.id
    LEFT JOIN admin_users au ON we.enrolled_by = au.id
    WHERE we.workflow_id = ?
    ORDER BY we.enrolled_at DESC
");
$enrollments->execute([$workflow_id]);
$all_enrollments = $enrollments->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="mb-4">
                <a href="workflows_list.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Workflows
                </a>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">
                        Enrollments: <?php echo escape(array_string_value($workflow, 'name')); ?>
                    </h2>
                    <?php if (array_string_value($workflow, 'description') !== ''): ?>
                        <p class="text-muted"><?php echo escape(array_string_value($workflow, 'description')); ?></p>
                    <?php endif; ?>
                </div>
                <a href="workflows_enroll.php?workflow_id=<?php echo $workflow_id; ?>" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Enroll More Clients
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo escape($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (empty($all_enrollments)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-circle-info"></i>
                    No clients enrolled yet. 
                    <a href="workflows_enroll.php?workflow_id=<?php echo $workflow_id; ?>">Enroll clients now</a>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Client</th>
                                        <th>Status</th>
                                        <th>Enrolled</th>
                                        <th>Progress</th>
                                        <th>Enrolled By</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_enrollments as $enrollment): ?>
                                        <?php
                                        // Get step execution progress
                                        $stmt = $conn->prepare("
                                            SELECT 
                                                COUNT(*) as total_steps,
                                                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_steps,
                                                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_steps,
                                                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_steps
                                            FROM workflow_step_executions
                                            WHERE enrollment_id = ?
                                        ");
                                        $stmt->execute([$enrollment['id']]);
                                        $progress = $stmt->fetch(PDO::FETCH_ASSOC);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo escape($enrollment['client_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo escape($enrollment['client_email']); ?></small>
                                            </td>
                                            <td>
                                                <?php
                                                $status_badges = [
                                                    'active' => 'bg-success',
                                                    'completed' => 'bg-info',
                                                    'cancelled' => 'bg-secondary'
                                                ];
                                                $badge_class = $status_badges[$enrollment['status']] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($enrollment['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo escape(formatDateTime(array_string_value($enrollment, 'enrolled_at'))); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php $progress_row = is_array($progress) ? $progress : []; ?>
                                                <?php $total_steps = array_int_value($progress_row, 'total_steps'); ?>
                                                <?php $completed_steps = array_int_value($progress_row, 'completed_steps'); ?>
                                                <?php $failed_steps = array_int_value($progress_row, 'failed_steps'); ?>
                                                <?php if ($total_steps > 0): ?>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar bg-success" role="progressbar" 
                                                             style="width: <?php echo ($completed_steps / $total_steps * 100); ?>%">
                                                            <?php echo $completed_steps; ?>/<?php echo $total_steps; ?>
                                                        </div>
                                                    </div>
                                                    <?php if ($failed_steps > 0): ?>
                                                        <small class="text-danger">
                                                            <?php echo $failed_steps; ?> failed
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted">No steps</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo escape(array_string_value($enrollment, 'enrolled_by_name', 'System')); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($enrollment['status'] === 'active'): ?>
                                                    <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                                        <a href="?workflow_id=<?php echo $workflow_id; ?>&cancel=1&enrollment_id=<?php echo $enrollment['id']; ?>" 
                                                           class="btn btn-sm btn-outline-danger table-action-btn"
                                                           onclick="return confirm('Are you sure you want to cancel this enrollment?')">
                                                            <i class="fas fa-stop"></i> Cancel
                                                        </a>
                                                    </div>
                                                    <div class="d-md-none table-action-dropdown">
                                                        <div class="dropdown">
                                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                                <i class="fas fa-ellipsis-v"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end">
                                                                <li>
                                                                    <a class="dropdown-item text-danger" href="?workflow_id=<?php echo $workflow_id; ?>&cancel=1&enrollment_id=<?php echo $enrollment['id']; ?>" onclick="return confirm('Are you sure you want to cancel this enrollment?')">
                                                                        <i class="fas fa-stop me-2"></i>Cancel
                                                                    </a>
                                                                </li>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Summary Stats -->
                <?php
                $active_count = count(array_filter($all_enrollments, fn($e) => $e['status'] === 'active'));
                $completed_count = count(array_filter($all_enrollments, fn($e) => $e['status'] === 'completed'));
                $cancelled_count = count(array_filter($all_enrollments, fn($e) => $e['status'] === 'cancelled'));
                ?>
                <div class="row mt-4">
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="mb-0"><?php echo count($all_enrollments); ?></h3>
                                <small class="text-muted">Total Enrollments</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="mb-0 text-success"><?php echo $active_count; ?></h3>
                                <small class="text-muted">Active</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="mb-0 text-info"><?php echo $completed_count; ?></h3>
                                <small class="text-muted">Completed</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h3 class="mb-0 text-secondary"><?php echo $cancelled_count; ?></h3>
                                <small class="text-muted">Cancelled</small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
