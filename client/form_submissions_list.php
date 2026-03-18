<?php
require_once '../backend/includes/config.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Get filter parameters
$client_id = safe_int($_GET['client_id'] ?? 0);
$template_id = safe_int($_GET['template_id'] ?? 0);
$status = scalar_string($_GET['status'] ?? '');

// Pagination
$page = max(1, safe_int($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where = [];
$params = [];

if ($client_id > 0) {
    $where[] = "fs.client_id = ?";
    $params[] = $client_id;
}

if ($template_id > 0) {
    $where[] = "fs.template_id = ?";
    $params[] = $template_id;
}

if (!empty($status)) {
    $where[] = "fs.status = ?";
    $params[] = $status;
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get total count
$count_query = "SELECT COUNT(*) FROM form_submissions fs $where_sql";
// where_sql is composed only from fixed SQL fragments while values stay parameterized in $params.
// nosemgrep
$count_stmt = $conn->prepare($count_query);
if (!empty($params)) {
    $count_stmt->execute($params);
} else {
    $count_stmt->execute();
}
$total = safe_int($count_stmt->fetchColumn());
$total_pages = ceil($total / $per_page);

// Get submissions
$query = "SELECT fs.*, 
          c.name as client_name,
          ft.name as form_name,
          ft.form_type,
          CONCAT(b.appointment_date, ' ', b.appointment_time) as appointment_datetime,
          au.username as submitted_by_name,
          au2.username as reviewed_by_name
          FROM form_submissions fs
          LEFT JOIN clients c ON fs.client_id = c.id
          LEFT JOIN form_templates ft ON fs.template_id = ft.id
          LEFT JOIN bookings b ON fs.booking_id = b.id
          LEFT JOIN admin_users au ON fs.submitted_by = au.id
          LEFT JOIN admin_users au2 ON fs.reviewed_by = au2.id
          $where_sql
          ORDER BY fs.submitted_at DESC";

// Build LIMIT clause that works with both MySQL and SQLite
$limit_clause = $db->buildLimitClause($per_page, $offset);
$query .= $limit_clause;

// where_sql is composed only from fixed SQL fragments while values stay parameterized in $params.
// nosemgrep
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->execute($params);
} else {
    $stmt->execute();
}
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get clients for filter
$clients_stmt = $conn->query("SELECT id, name FROM clients ORDER BY name");
$clients = $clients_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get templates for filter
$templates_stmt = $conn->query("SELECT id, name FROM form_templates WHERE is_active = 1 ORDER BY name");
$templates = $templates_stmt->fetchAll(PDO::FETCH_ASSOC);

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-file-circle-check"></i> Form Submissions</h2>
    </div>

    <!-- Filters -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Client</label>
                    <select name="client_id" class="form-select" data-searchable-select="client" data-search-placeholder="Search clients...">
                        <option value="">All Clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>" <?= $client_id == $client['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($client['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Form Template</label>
                    <select name="template_id" class="form-select">
                        <option value="">All Forms</option>
                        <?php foreach ($templates as $template): ?>
                            <option value="<?= $template['id'] ?>" <?= $template_id == $template['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($template['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="draft" <?= $status == 'draft' ? 'selected' : '' ?>>Draft</option>
                        <option value="submitted" <?= $status == 'submitted' ? 'selected' : '' ?>>Submitted</option>
                        <option value="reviewed" <?= $status == 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">Filter</button>
                    <a href="form_submissions_list.php" class="btn btn-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($submissions)): ?>
        <div class="alert alert-info">
            <i class="fas fa-circle-info"></i> No form submissions found.
        </div>
    <?php else: ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Form</th>
                            <th>Client</th>
                            <th>Type</th>
                            <th>Submitted</th>
                            <th>Status</th>
                            <th>Reviewed By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $sub): ?>
                            <?php /** @var array<string, mixed> $sub */ ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars(array_string_value($sub, 'form_name')) ?></strong>
                                    <?php if (array_int_value($sub, 'booking_id') !== 0): ?>
                                        <br><small class="text-muted">
                                            <i class="fas fa-calendar"></i> <?= htmlspecialchars(array_string_value($sub, 'appointment_datetime')) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="clients_view.php?id=<?= array_int_value($sub, 'client_id') ?>">
                                        <?= htmlspecialchars(array_string_value($sub, 'client_name')) ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $type_badges = [
                                        'client_form' => 'bg-primary',
                                        'session_note' => 'bg-info',
                                        'behavior_assessment' => 'bg-warning',
                                        'training_plan' => 'bg-success'
                                    ];
                                    $form_type = array_string_value($sub, 'form_type');
                                    $badge = $type_badges[$form_type] ?? 'bg-secondary';
                                    ?>
                                    <span class="badge <?= $badge ?>">
                                        <?= ucwords(str_replace('_', ' ', $form_type)) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= escape(formatDateTime(array_string_value($sub, 'submitted_at'))) ?>
                                    <?php if (array_string_value($sub, 'submitted_by_name') !== ''): ?>
                                        <br><small class="text-muted">by <?= htmlspecialchars(array_string_value($sub, 'submitted_by_name')) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_badges = [
                                        'draft' => 'bg-secondary',
                                        'submitted' => 'bg-warning text-dark',
                                        'reviewed' => 'bg-success'
                                    ];
                                    $submission_status = array_string_value($sub, 'status');
                                    $status_badge = $status_badges[$submission_status] ?? 'bg-secondary';
                                    ?>
                                    <span class="badge <?= $status_badge ?>">
                                        <?= ucfirst($submission_status) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (array_string_value($sub, 'reviewed_by_name') !== ''): ?>
                                        <?= htmlspecialchars(array_string_value($sub, 'reviewed_by_name')) ?>
                                        <br><small class="text-muted"><?= escape(formatDate(array_string_value($sub, 'reviewed_at'), 'M j, Y')) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                        <a href="form_submissions_view.php?id=<?= array_int_value($sub, 'id') ?>" class="btn btn-sm btn-outline-primary table-action-btn" title="View Submission" aria-label="View Submission">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                    <div class="d-md-none table-action-dropdown">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <a class="dropdown-item" href="form_submissions_view.php?id=<?= array_int_value($sub, 'id') ?>">
                                                        <i class="fas fa-eye me-2 text-primary"></i>View Submission
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&client_id=<?= $client_id ?>&template_id=<?= $template_id ?>&status=<?= urlencode($status) ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&client_id=<?= $client_id ?>&template_id=<?= $template_id ?>&status=<?= urlencode($status) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&client_id=<?= $client_id ?>&template_id=<?= $template_id ?>&status=<?= urlencode($status) ?>">Next</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include '../backend/includes/footer.php'; ?>
