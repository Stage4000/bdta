<?php
require_once '../portal/includes/config.php';
requirePortalLogin();

$client_id = intval($_SESSION['portal_client_id']);
$db   = new Database();
$conn = $db->getConnection();

// Get client email
$stmt = $conn->prepare("SELECT email FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_email = $stmt->fetchColumn();

// Upcoming appointments
$stmt = $conn->prepare("SELECT * FROM bookings WHERE client_email = ? AND appointment_date >= CURDATE() ORDER BY appointment_date ASC, appointment_time ASC");
$stmt->execute([$client_email]);
$upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Past appointments
$stmt = $conn->prepare("SELECT * FROM bookings WHERE client_email = ? AND appointment_date < CURDATE() ORDER BY appointment_date DESC, appointment_time DESC");
$stmt->execute([$client_email]);
$past = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Bookable appointment types (portal_available = 1)
$stmt = $conn->query("SELECT * FROM appointment_types WHERE portal_available = 1 AND is_active = 1 ORDER BY name");
$bookable_types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Also include appointment types matching client's package session types
$stmt = $conn->prepare("SELECT DISTINCT session_type FROM client_package_credits WHERE client_id = ? AND (total_credits - used_credits) > 0");
$stmt->execute([$client_id]);
$session_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

$extra_types = [];
if (!empty($session_types)) {
    $placeholders = implode(',', array_fill(0, count($session_types), '?'));
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE is_active = 1 AND name IN ($placeholders) AND (portal_available = 0 OR portal_available IS NULL) ORDER BY name");
    $stmt->execute($session_types);
    $extra_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Appointments';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Appointments</h2>

<!-- Book New Appointment -->
<?php if (!empty($bookable_types) || !empty($extra_types)): ?>
<div class="card mb-4">
    <div class="card-header"><strong><i class="fas fa-calendar-plus me-2"></i>Book New Appointment</strong></div>
    <div class="card-body">
        <div class="row g-2">
        <?php foreach (array_merge($bookable_types, $extra_types) as $atype): ?>
            <div class="col-md-4">
                <div class="card h-100">
                    <div class="card-body">
                        <h6><?php echo escape($atype['name']); ?></h6>
                        <?php if (!empty($atype['description'])): ?>
                            <p class="text-muted small mb-2"><?php echo escape($atype['description']); ?></p>
                        <?php endif; ?>
                        <a href="/backend/public/book.php?link=<?php echo escape($atype['unique_link']); ?>" class="btn btn-sm btn-primary" target="_blank">
                            Book Now
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="appointmentTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#upcoming">
            Upcoming <span class="badge bg-secondary"><?php echo count($upcoming); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#past">
            Past <span class="badge bg-secondary"><?php echo count($past); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="upcoming">
        <?php if (empty($upcoming)): ?>
            <div class="alert alert-info">No upcoming appointments.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Status</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($upcoming as $b): ?>
                    <tr>
                        <td><?php echo escape($b['appointment_date'] ?? ''); ?></td>
                        <td><?php echo escape($b['appointment_time'] ?? ''); ?></td>
                        <td><?php echo escape($b['service_type'] ?? ''); ?></td>
                        <td><?php echo escape($b['status'] ?? ''); ?></td>
                        <td><?php echo escape($b['notes'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="tab-pane fade" id="past">
        <?php if (empty($past)): ?>
            <div class="alert alert-info">No past appointments.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>Date</th><th>Time</th><th>Type</th><th>Status</th><th>Notes</th></tr></thead>
                <tbody>
                <?php foreach ($past as $b): ?>
                    <tr>
                        <td><?php echo escape($b['appointment_date'] ?? ''); ?></td>
                        <td><?php echo escape($b['appointment_time'] ?? ''); ?></td>
                        <td><?php echo escape($b['service_type'] ?? ''); ?></td>
                        <td><?php echo escape($b['status'] ?? ''); ?></td>
                        <td><?php echo escape($b['notes'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../portal/includes/footer.php'; ?>
