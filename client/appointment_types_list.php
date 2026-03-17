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

// Get base URL for building booking links dynamically from current request
$base_url = getDynamicBaseUrl();

// Pagination
$page = safe_int($_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get appointment types
// nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-callable.tainted-callable, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- safe int-cast LIMIT/OFFSET via $db->buildLimitClause().
$stmt = $conn->prepare("
    SELECT * FROM appointment_types
    ORDER BY is_active DESC, name ASC" . $db->buildLimitClause($per_page, $offset)
);
$stmt->execute();
$types = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$total = safe_int($conn->query("SELECT COUNT(*) FROM appointment_types")->fetchColumn());
$total_pages = (int) ceil($total / $per_page);

$page_title = "Appointment Types";
include __DIR__ . '/../backend/includes/header.php';
?>

<style>
    .appointment-type-description-preview {
        display: block;
        display: -webkit-box;
        line-height: 1.4;
        max-width: 16rem;
        max-height: 2.8em;
        overflow: hidden;
        white-space: normal;
        overflow-wrap: anywhere;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }
</style>

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
        <?php $flash = is_array($_SESSION['flash']) ? $_SESSION['flash'] : []; ?>
        <div class="alert alert-<?= htmlspecialchars(array_string_value($flash, 'type', 'info')) ?> alert-dismissible fade show">
            <?= htmlspecialchars(array_string_value($flash, 'message')) ?>
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
                                <th>Schedule</th>
                                <th>Buffers</th>
                                <th>Advance Booking</th>
                                <th>Requirements</th>
                                <th>Behavior</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types as $type): ?>
                                <?php
                                $type_name = array_string_value($type, 'name');
                                $type_description = array_string_value($type, 'description');
                                $type_duration = array_int_value($type, 'duration_minutes', 60);
                                $schedule_type = array_string_value($type, 'schedule_type', 'recurring');
                                $buffer_before = array_int_value($type, 'buffer_before_minutes');
                                $buffer_after = array_int_value($type, 'buffer_after_minutes');
                                $advance_min_days = array_int_value($type, 'advance_booking_min_days');
                                $advance_max_days = array_int_value($type, 'advance_booking_max_days', 90);
                                $requires_forms = array_int_value($type, 'requires_forms') === 1;
                                $requires_contract = array_int_value($type, 'requires_contract') === 1;
                                $auto_invoice = array_int_value($type, 'auto_invoice') === 1;
                                $default_amount = safe_float($type['default_amount'] ?? 0);
                                $consumes_credits = array_int_value($type, 'consumes_credits') === 1;
                                $credit_count = array_int_value($type, 'credit_count', 1);
                                $is_group_class = array_int_value($type, 'is_group_class') === 1;
                                $max_participants = array_int_value($type, 'max_participants', 1);
                                $is_mini_session = array_int_value($type, 'is_mini_session') === 1;
                                $is_field_rental = array_int_value($type, 'is_field_rental') === 1;
                                $is_active = array_int_value($type, 'is_active') === 1;
                                $unique_link = array_string_value($type, 'unique_link');
                                $type_id = array_int_value($type, 'id');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($type_name) ?></strong>
                                        <?php if ($type_description !== ''): ?>
                                            <br><small class="text-muted appointment-type-description-preview" title="<?= htmlspecialchars($type_description) ?>"><?= htmlspecialchars($type_description) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= $type_duration ?> min
                                    </td>
                                    <td>
                                        <?php 
                                        if ($schedule_type === 'specific_date'):
                                            // Check for multi-date format first
                                            $list_specific_dates = [];
                                            $specific_dates_json = array_string_value($type, 'specific_dates');
                                            if ($specific_dates_json !== '') {
                                                $list_specific_dates = decode_json_assoc_list($specific_dates_json);
                                            }
                                            $specific_date = array_string_value($type, 'specific_date');
                                            if ($list_specific_dates === [] && $specific_date !== '') {
                                                $list_specific_dates = [['date' => $specific_date]];
                                            }
                                        ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-calendar-day"></i> Specific Date<?= count($list_specific_dates) > 1 ? 's' : '' ?>
                                            </span><br>
                                            <?php foreach ($list_specific_dates as $sd_entry): ?>
                                            <small><?= date('M j, Y', safe_timestamp(strtotime(array_string_value($sd_entry, 'date')))) ?></small><br>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark">
                                                <i class="fas fa-calendar-week"></i> Recurring
                                            </span><br>
                                            <small>
                                                <?php
                                                $day_names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                                $available_days = decode_json_assoc(array_string_value($type, 'available_days'));
                                                if ($available_days !== []) {
                                                    $selected_day_names = array_map(
                                                        static fn($d): string => $day_names[safe_int($d)] ?? '',
                                                        $available_days
                                                    );
                                                    $selected_day_names = array_values(array_filter($selected_day_names, static fn(string $day): bool => $day !== ''));
                                                    echo implode(', ', $selected_day_names);
                                                } else {
                                                    echo 'All days';
                                                }
                                                ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            Before: <?= $buffer_before ?> min<br>
                                            After: <?= $buffer_after ?> min
                                        </small>
                                    </td>
                                    <td>
                                        <small>
                                            Min: <?= $advance_min_days ?> days<br>
                                            Max: <?= $advance_max_days ?> days
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($requires_forms): ?>
                                            <span class="badge bg-info text-dark">Forms Required</span><br>
                                        <?php endif; ?>
                                        <?php if ($requires_contract): ?>
                                            <span class="badge bg-warning text-dark">Contract Required</span>
                                        <?php endif; ?>
                                        <?php if (!$requires_forms && !$requires_contract): ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small>
                                            <?php if ($auto_invoice): ?>
                                                <span class="badge bg-success">Auto-Invoice<?= $default_amount > 0 ? ' ($' . number_format($default_amount, 2) . ')' : '' ?></span><br>
                                            <?php endif; ?>
                                            <?php if ($consumes_credits): ?>
                                                <span class="badge bg-primary">Uses <?= $credit_count ?> Credit(s)</span><br>
                                            <?php endif; ?>
                                            <?php if ($is_group_class): ?>
                                                <span class="badge bg-secondary">Group Class (Max <?= $max_participants ?>)</span><br>
                                            <?php endif; ?>
                                            <?php if ($is_mini_session): ?>
                                                <span class="badge bg-info">
                                                    <i class="fas fa-location-dot"></i> Mini Sessions
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($is_field_rental): ?>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-tree"></i> Field Rental
                                                </span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($is_active): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="table-action-buttons">
                                            <?php if ($unique_link !== ''): ?>
                                                <button class="btn btn-sm btn-outline-info table-action-btn"
                                                        onclick="copyLink('<?= htmlspecialchars($base_url . '/backend/public/book.php?link=' . $unique_link) ?>', this)"
                                                        title="Copy booking link"
                                                        aria-label="Copy booking link">
                                                    <i class="fas fa-link"></i>
                                                </button>
                                            <?php endif; ?>
                                            <a href="appointment_types_edit.php?id=<?= $type_id ?>" 
                                               class="btn btn-sm btn-outline-primary table-action-btn" title="Edit" aria-label="Edit">
                                                <i class="fas fa-pencil"></i>
                                            </a>
                                            <form method="POST" action="appointment_types_duplicate.php" class="d-inline">
                                                <input type="hidden" name="id" value="<?= $type_id ?>">
                                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary table-action-btn" title="Duplicate" aria-label="Duplicate">
                                                    <i class="fas fa-copy"></i>
                                                </button>
                                            </form>
                                            <a href="appointment_types_delete.php?id=<?= $type_id ?>" 
                                               class="btn btn-sm btn-outline-danger table-action-btn" 
                                               onclick="return confirm('Are you sure you want to delete this appointment type?')"
                                               title="Delete"
                                               aria-label="Delete">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
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
        button.innerHTML = '<i class="fas fa-check"></i>';
        button.classList.remove('btn-outline-primary');
        button.classList.add('btn-success');
        
        setTimeout(function() {
            button.innerHTML = originalHTML;
            button.classList.remove('btn-success');
            button.classList.add('btn-outline-primary');
        }, 2000);
    }).catch(function(err) {
        // Fallback: show a simple prompt with the link
        prompt('Copy this link:', link);
    });
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
