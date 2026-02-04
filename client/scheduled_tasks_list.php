<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get all scheduled tasks
$tasks = $conn->query("SELECT * FROM scheduled_tasks ORDER BY is_active DESC, task_name")->fetchAll(PDO::FETCH_ASSOC);

// Get recent task logs
$recent_logs = $conn->query("
    SELECT * FROM task_logs 
    ORDER BY executed_at DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0"><i class="fas fa-clock me-2"></i>Scheduled Tasks</h2>
                <a href="scheduled_tasks_edit.php" class="btn btn-primary">
                    <i class="fas fa-circle-plus"></i> New Task
                </a>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Scheduled Tasks -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Active Tasks</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($tasks)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-circle-info"></i>
                            No scheduled tasks configured. Create your first task to automate operations.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Task Name</th>
                                        <th>Type</th>
                                        <th>Schedule</th>
                                        <th>Last Run</th>
                                        <th>Next Run</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tasks as $task): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($task['task_name']); ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo htmlspecialchars($task['task_type']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $schedule = ucfirst($task['schedule_type']);
                                                if ($task['schedule_value']) {
                                                    $schedule .= " ({$task['schedule_value']})";
                                                }
                                                echo htmlspecialchars($schedule);
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($task['last_run']): ?>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, Y g:i A', strtotime($task['last_run'])); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">Never</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($task['next_run']): ?>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, Y g:i A', strtotime($task['next_run'])); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($task['is_active']): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="scheduled_tasks_edit.php?id=<?php echo $task['id']; ?>" 
                                                       class="btn btn-outline-primary" title="Edit">
                                                        <i class="fas fa-pencil"></i>
                                                    </a>
                                                    <a href="scheduled_tasks_logs.php?task_id=<?php echo $task['id']; ?>" 
                                                       class="btn btn-outline-info" title="View Logs">
                                                        <i class="fas fa-list"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Task Execution Logs -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Recent Task Executions</h5>
                    <a href="scheduled_tasks_logs.php" class="btn btn-sm btn-outline-primary">
                        View All Logs
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($recent_logs)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-circle-info"></i>
                            No task execution logs yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Executed At</th>
                                        <th>Task Name</th>
                                        <th>Status</th>
                                        <th>Items</th>
                                        <th>Time</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($recent_logs, 0, 10) as $log): ?>
                                        <tr>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M j, g:i A', strtotime($log['executed_at'])); ?>
                                                </small>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['task_name']); ?></td>
                                            <td>
                                                <?php if ($log['status'] === 'success'): ?>
                                                    <span class="badge bg-success">Success</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Error</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo $log['items_processed']; ?></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo number_format($log['execution_time'], 2); ?>s
                                                </small>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($log['message']); ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- CRON Setup Instructions -->
            <div class="card mt-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>CRON Job Setup</h5>
                </div>
                <div class="card-body">
                    <p>To enable automated task execution, you need to set up a CRON job on your server.</p>
                    
                    <?php $base_path = dirname(dirname(__DIR__)); ?>
                    <h6>Add this line to your crontab:</h6>
                    <pre class="bg-light p-3 rounded"><code>*/15 * * * * php <?php echo $base_path; ?>/backend/cron/cron.php >> <?php echo $base_path; ?>/logs/cron.log 2>&1</code></pre>
                    
                    <p class="mb-0">
                        <small class="text-muted">
                            This runs the CRON job every 15 minutes. See 
                            <a href="../backend/CRON_SETUP.md" target="_blank">CRON_SETUP.md</a> 
                            for detailed setup instructions.
                        </small>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
