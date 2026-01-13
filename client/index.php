<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get statistics
$total_posts = $conn->query("SELECT COUNT(*) FROM blog_posts")->fetchColumn();
$published_posts = $conn->query("SELECT COUNT(*) FROM blog_posts WHERE published = 1")->fetchColumn();
$total_bookings = $conn->query("SELECT COUNT(*) FROM bookings")->fetchColumn();
$pending_bookings = $conn->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn();

// Get recent bookings
$stmt = $conn->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 10");
$recent_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Dashboard';
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <h2 class="mb-4"><i class="fas fa-gauge me-2"></i>Dashboard</h2>
    
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <i class="fas fa-file-lines fs-1 text-primary"></i>
                    <h3 class="mt-2"><?php echo $total_posts; ?></h3>
                    <p class="text-muted mb-0">Total Posts</p>
                    <small class="text-success"><?php echo $published_posts; ?> published</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center">
                <div class="card-body">
                    <i class="fas fa-calendar-check fs-1 text-success"></i>
                    <h3 class="mt-2"><?php echo $total_bookings; ?></h3>
                    <p class="text-muted mb-0">Total Bookings</p>
                    <small class="text-warning"><?php echo $pending_bookings; ?> pending</small>
                </div>
            </div>
        </div>
    </div>
    
    <h4 class="mb-3">Recent Bookings</h4>
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Client</th>
                            <th>Service</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($recent_bookings) > 0): ?>
                            <?php foreach ($recent_bookings as $booking): ?>
                            <tr>
                                <td><?php echo $booking['id']; ?></td>
                                <td><?php echo escape($booking['client_name']); ?></td>
                                <td><?php echo escape($booking['service_type']); ?></td>
                                <td><?php echo escape($booking['appointment_date']); ?></td>
                                <td><?php echo escape($booking['appointment_time']); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $booking['status'] === 'pending' ? 'warning' : ($booking['status'] === 'confirmed' ? 'success' : 'secondary'); ?>">
                                        <?php echo escape($booking['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">No bookings yet</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
