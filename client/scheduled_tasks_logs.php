<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filter by task ID if provided
$task_id = isset($_GET['task_id']) ? (int)$_GET['task_id'] : null;

// Get task logs
if ($task_id) {
    $stmt = $conn->prepare("
        SELECT * FROM task_logs 
        WHERE task_id = ?
        ORDER BY executed_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$task_id, $per_page, $offset]);
    
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM task_logs WHERE task_id = ?");
    $count_stmt->execute([$task_id]);
} else {
    $stmt = $conn->prepare("
        SELECT * FROM task_logs 
        ORDER BY executed_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$per_page, $offset]);
    
    $count_stmt = $conn->query("SELECT COUNT(*) FROM task_logs");
}

$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0">
                    <i class="fas fa-list me-2"></i>Task Execution Logs
                    <?php if ($task_id): ?>
                        <span class="badge bg-secondary">Filtered</span>
                    <?php endif; ?>
                </h2>
                <div>
                    <?php if ($task_id): ?>
                        <a href="scheduled_tasks_logs.php" class="btn btn-secondary">
                            <i class="fas fa-xmark"></i> Clear Filter
                        </a>
                    <?php endif; ?>
                    <a href="scheduled_tasks_list.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Tasks
                    </a>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <?php if (empty($logs)): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-circle-info"></i>
                            No task execution logs found.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Executed At</th>
                                        <th>Task Name</th>
                                        <th>Status</th>
                                        <th>Items Processed</th>
                                        <th>Execution Time</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr class="<?php echo $log['status'] === 'error' ? 'table-danger' : ''; ?>">
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo date('M j, Y g:i:s A', strtotime($log['executed_at'])); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($log['task_name']); ?></strong>
                                            </td>
                                            <td>
                                                <?php if ($log['status'] === 'success'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check"></i> Success
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">
                                                        <i class="fas fa-xmark"></i> Error
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary">
                                                    <?php echo $log['items_processed']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo number_format($log['execution_time'], 3); ?>s
                                                </small>
                                            </td>
                                            <td>
                                                <small>
                                                    <?php echo htmlspecialchars($log['message']); ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?><?php echo $task_id ? '&task_id=' . $task_id : ''; ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Summary Statistics -->
            <?php
            // Get summary statistics
            $stats_query = "
                SELECT 
                    COUNT(*) as total_executions,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
                    SUM(items_processed) as total_items,
                    AVG(execution_time) as avg_time
                FROM task_logs
            ";
            if ($task_id) {
                $stats_query .= " WHERE task_id = ?";
                $stats_stmt = $conn->prepare($stats_query);
                $stats_stmt->execute([$task_id]);
            } else {
                $stats_stmt = $conn->query($stats_query);
            }
            $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
            ?>

            <div class="row mt-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="mb-0"><?php echo number_format($stats['total_executions']); ?></h3>
                            <small class="text-muted">Total Executions</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="mb-0 text-success"><?php echo number_format($stats['successful']); ?></h3>
                            <small class="text-muted">Successful</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="mb-0 text-danger"><?php echo number_format($stats['errors']); ?></h3>
                            <small class="text-muted">Errors</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="mb-0"><?php echo number_format($stats['total_items']); ?></h3>
                            <small class="text-muted">Items Processed</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
