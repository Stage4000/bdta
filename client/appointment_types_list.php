<?php
/**
 * Brook's Dog Training Academy - Appointment Types List
 * Manage appointment types with configurable rules and behaviors
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Get base URL for building booking links
$base_url_stmt = $conn->query("SELECT setting_value FROM settings WHERE setting_key = 'base_url'");
$base_url = $base_url_stmt->fetchColumn();
if (!$base_url) {
    $base_url = 'http://localhost:8000';
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get appointment types
$stmt = $conn->prepare("
    SELECT * FROM appointment_types 
    ORDER BY is_active DESC, name ASC
    LIMIT ? OFFSET ?
");
$stmt->execute([$per_page, $offset]);
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$total = $conn->query("SELECT COUNT(*) FROM appointment_types")->fetchColumn();
$total_pages = ceil($total / $per_page);

$page_title = "Appointment Types";
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="fas fa-calendar-plus me-2"></i>Appointment Types</h2>
            <p class="text-muted">Configure appointment types with rules and behaviors</p>
        </div>
        <a href="appointment_types_edit.php" class="btn btn-primary">
            <i class="fas fa-circle-plus"></i> Add New Type
        </a>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash']['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($types)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-calendar-check display-1 text-muted"></i>
                    <p class="text-muted mt-3">No appointment types found</p>
                    <a href="appointment_types_edit.php" class="btn btn-primary">Add Your First Type</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Duration</th>
                                <th>Buffers</th>
                                <th>Advance Booking</th>
                                <th>Requirements</th>
                                <th>Behavior</th>
                                <th>Status</th>
                                <th>Booking Link</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $type): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($type['name']) ?></strong>
                                        <?php if ($type['description']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($type['description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $type['duration_minutes'] ?> min
                                    </td>
                                    <td>
                                        <small>
                                            Before: <?= $type['buffer_before_minutes'] ?> min<br>
                                            After: <?= $type['buffer_after_minutes'] ?> min
                                        </small>
                                    </td>
                                    <td>
                                        <small>
                                            Min: <?= $type['advance_booking_min_days'] ?> days<br>
                                            Max: <?= $type['advance_booking_max_days'] ?> days
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($type['requires_forms']): ?>
                                            <span class="badge bg-info text-dark">Forms Required</span><br>
                                        <?php endif; ?>
                                        <?php if ($type['requires_contract']): ?>
                                            <span class="badge bg-warning text-dark">Contract Required</span>
                                        <?php endif; ?>
                                        <?php if (!$type['requires_forms'] && !$type['requires_contract']): ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?php if ($type['auto_invoice']): ?>
                                                <span class="badge bg-success">Auto-Invoice</span><br>
                                            <?php endif; ?>
                                            <?php if ($type['consumes_credits']): ?>
                                                <span class="badge bg-primary">Uses <?= $type['credit_count'] ?> Credit(s)</span><br>
                                            <?php endif; ?>
                                            <?php if ($type['is_group_class']): ?>
                                                <span class="badge bg-secondary">Group Class (Max <?= $type['max_participants'] ?>)</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($type['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($type['unique_link'])): ?>
                                            <button class="btn btn-sm btn-outline-primary" 
                                                    onclick="copyLink('<?= htmlspecialchars($base_url . '/backend/public/book.php?link=' . $type['unique_link']) ?>', this)"
                                                    title="Copy booking link">
                                                <i class="fas fa-link"></i> Copy Link
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">No link</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="appointment_types_edit.php?id=<?= $type['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-pencil"></i>
                                        </a>
                                        <a href="appointment_types_delete.php?id=<?= $type['id'] ?>" 
                                           class="btn btn-sm btn-outline-danger" 
                                           onclick="return confirm('Are you sure you want to delete this appointment type?')"
                                           title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Page navigation" class="mt-3">
                        <ul class="pagination justify-content-center">
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyLink(link, button) {
    navigator.clipboard.writeText(link).then(function() {
        // Show success feedback
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        button.classList.remove('btn-outline-primary');
        button.classList.add('btn-success');
        
        setTimeout(function() {
            button.innerHTML = originalHTML;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-primary');
        }, 2000);
    }).catch(function(err) {
        alert('Failed to copy link. Please copy it manually: ' + link);
    });
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
