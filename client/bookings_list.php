<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id']) && isset($_POST['status'])) {
    $booking_id = $_POST['booking_id'];
    $status = $_POST['status'];
    
    $stmt = $conn->prepare("UPDATE bookings SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$status, $booking_id]);
    
    setFlashMessage("Booking status updated to $status.", 'success');
    redirect('bookings_list.php');
}

// Handle deletion
if (isset($_GET['delete'])) {
    $booking_id = $_GET['delete'];
    $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    
    setFlashMessage('Booking deleted.', 'info');
    redirect('bookings_list.php');
}

$stmt = $conn->query("SELECT * FROM bookings ORDER BY appointment_date DESC, appointment_time DESC");
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Bookings';
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <h2 class="mb-4"><i class="bi bi-calendar-check me-2"></i>Bookings Management</h2>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Client</th>
                            <th>Contact</th>
                            <th>Service</th>
                            <th>Date & Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bookings) > 0): ?>
                            <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td><?php echo $booking['id']; ?></td>
                                <td><?php echo escape($booking['client_name']); ?></td>
                                <td>
                                    <small>
                                        <?php echo escape($booking['client_email']); ?><br>
                                        <?php if ($booking['client_phone']): ?>
                                            <?php echo escape($booking['client_phone']); ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td><?php echo escape($booking['service_type']); ?></td>
                                <td>
                                    <?php echo escape($booking['appointment_date']); ?><br>
                                    <small><?php echo escape($booking['appointment_time']); ?></small>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                            <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                            <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <a href="?delete=<?php echo $booking['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this booking?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted">No bookings yet</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
