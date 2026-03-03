<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// --- Filter parameters ---
$filter_client_id    = isset($_GET['client_id'])    ? (int)$_GET['client_id']               : 0;
$filter_status       = isset($_GET['status'])       ? trim($_GET['status'])                  : '';
$filter_template_type = isset($_GET['template_type']) ? trim($_GET['template_type'])         : '';
$filter_date_from    = isset($_GET['date_from'])    ? trim($_GET['date_from'])               : '';
$filter_date_to      = isset($_GET['date_to'])      ? trim($_GET['date_to'])                 : '';
$search              = isset($_GET['search'])       ? trim($_GET['search'])                  : '';
$page                = max(1, (int)($_GET['page'] ?? 1));
$per_page            = 25;
$offset              = ($page - 1) * $per_page;

// Fetch detail view for a single email
$view_id = isset($_GET['view_id']) ? (int)$_GET['view_id'] : 0;
$email_detail = null;
if ($view_id) {
    $stmt = $conn->prepare("
        SELECT ce.*, c.name AS client_name, c.email AS client_email_addr,
               et.name AS template_name
        FROM client_emails ce
        LEFT JOIN clients c ON ce.client_id = c.id
        LEFT JOIN email_templates et ON ce.template_id = et.id
        WHERE ce.id = ?
    ");
    $stmt->execute([$view_id]);
    $email_detail = $stmt->fetch(PDO::FETCH_ASSOC);
}

// --- Build query ---
$where_parts  = [];
$bind_params  = [];

if ($filter_client_id > 0) {
    $where_parts[] = "ce.client_id = ?";
    $bind_params[] = $filter_client_id;
}
if ($filter_status !== '') {
    $where_parts[] = "ce.status = ?";
    $bind_params[] = $filter_status;
}
if ($filter_template_type !== '') {
    $where_parts[] = "ce.template_type = ?";
    $bind_params[] = $filter_template_type;
}
if ($filter_date_from !== '') {
    $where_parts[] = "DATE(ce.created_at) >= ?";
    $bind_params[] = $filter_date_from;
}
if ($filter_date_to !== '') {
    $where_parts[] = "DATE(ce.created_at) <= ?";
    $bind_params[] = $filter_date_to;
}
if ($search !== '') {
    $where_parts[] = "(ce.subject LIKE ? OR ce.to_email LIKE ? OR ce.from_email LIKE ? OR c.name LIKE ?)";
    $like = '%' . $search . '%';
    $bind_params[] = $like;
    $bind_params[] = $like;
    $bind_params[] = $like;
    $bind_params[] = $like;
}

$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// Total count for pagination
$count_stmt = $conn->prepare("
    SELECT COUNT(*) FROM client_emails ce
    LEFT JOIN clients c ON ce.client_id = c.id
    $where_sql
");
$count_stmt->execute($bind_params);
$total_records = (int)$count_stmt->fetchColumn();
$total_pages   = max(1, (int)ceil($total_records / $per_page));

// Fetch emails
$limit_clause = $db->buildLimitClause($per_page, $offset);
$stmt = $conn->prepare("
    SELECT ce.*, c.name AS client_name,
           et.name AS template_name
    FROM client_emails ce
    LEFT JOIN clients c ON ce.client_id = c.id
    LEFT JOIN email_templates et ON ce.template_id = et.id
    $where_sql
    ORDER BY ce.created_at DESC
    $limit_clause
");
$stmt->execute($bind_params);
$emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$stats_stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'sent'      THEN 1 ELSE 0 END) AS sent,
        SUM(CASE WHEN status = 'failed'    THEN 1 ELSE 0 END) AS failed,
        SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
        SUM(CASE WHEN status = 'sending'   THEN 1 ELSE 0 END) AS sending
    FROM client_emails ce
    LEFT JOIN clients c ON ce.client_id = c.id
    $where_sql
");
$stats_stmt->execute($bind_params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Client list for filter dropdown (only clients that have email logs)
$clients = $conn->query("
    SELECT DISTINCT c.id, c.name
    FROM clients c
    INNER JOIN client_emails ce ON ce.client_id = c.id
    ORDER BY c.name
")->fetchAll(PDO::FETCH_ASSOC);

// Distinct template types for filter dropdown
$template_types = $conn->query("
    SELECT DISTINCT template_type FROM client_emails
    WHERE template_type IS NOT NULL AND template_type != ''
    ORDER BY template_type
")->fetchAll(PDO::FETCH_COLUMN);

$page_title = 'Email Logs';
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="mb-0"><i class="fas fa-envelope-open-text me-2"></i>Email Logs</h2>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($email_detail): ?>
            <!-- Detail view -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-envelope me-2"></i>Email Detail #<?php echo $email_detail['id']; ?></h5>
                    <a href="email_logs_list.php?<?php echo http_build_query(array_filter([
                        'client_id'     => $filter_client_id ?: null,
                        'status'        => $filter_status,
                        'template_type' => $filter_template_type,
                        'date_from'     => $filter_date_from,
                        'date_to'       => $filter_date_to,
                        'search'        => $search,
                        'page'          => $page,
                    ])); ?>" class="btn btn-sm btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i> Back to Logs
                    </a>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr><th style="width:160px">Client</th><td><?php echo htmlspecialchars($email_detail['client_name'] ?? 'N/A'); ?></td></tr>
                                <tr><th>From</th><td><?php echo htmlspecialchars($email_detail['from_email']); ?></td></tr>
                                <tr><th>To</th><td><?php echo htmlspecialchars($email_detail['to_email']); ?></td></tr>
                                <tr><th>Subject</th><td><?php echo htmlspecialchars($email_detail['subject']); ?></td></tr>
                                <tr><th>Template Type</th><td>
                                    <?php if ($email_detail['template_type']): ?>
                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($email_detail['template_type']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td></tr>
                                <tr><th>Template</th><td><?php echo htmlspecialchars($email_detail['template_name'] ?? '—'); ?></td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless">
                                <tr><th style="width:160px">Status</th><td>
                                    <?php
                                    $s = $email_detail['status'];
                                    $badge = match($s) {
                                        'sent'      => 'success',
                                        'failed'    => 'danger',
                                        'scheduled' => 'info',
                                        'sending'   => 'warning',
                                        default     => 'secondary',
                                    };
                                    ?>
                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst(htmlspecialchars($s)); ?></span>
                                </td></tr>
                                <tr><th>Delivery Attempts</th><td><?php echo (int)($email_detail['delivery_attempts'] ?? 0); ?></td></tr>
                                <tr><th>Scheduled At</th><td><?php echo $email_detail['scheduled_at'] ? htmlspecialchars($email_detail['scheduled_at']) : '—'; ?></td></tr>
                                <tr><th>Sent At</th><td><?php echo $email_detail['sent_at'] ? htmlspecialchars($email_detail['sent_at']) : '—'; ?></td></tr>
                                <tr><th>Delivered At</th><td><?php echo $email_detail['delivered_at'] ? htmlspecialchars($email_detail['delivered_at']) : '—'; ?></td></tr>
                                <tr><th>Failed At</th><td><?php echo $email_detail['failed_at'] ? htmlspecialchars($email_detail['failed_at']) : '—'; ?></td></tr>
                                <tr><th>Created At</th><td><?php echo htmlspecialchars($email_detail['created_at']); ?></td></tr>
                            </table>
                        </div>
                    </div>
                    <?php if ($email_detail['error_message']): ?>
                    <div class="alert alert-danger mt-2">
                        <strong><i class="fas fa-circle-exclamation me-1"></i>Error:</strong>
                        <?php echo htmlspecialchars($email_detail['error_message']); ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($email_detail['body_html']): ?>
                    <div class="mt-3">
                        <h6>Email Body (HTML Preview)</h6>
                        <div class="border rounded p-3 bg-white" style="max-height:400px;overflow:auto;">
                            <iframe srcdoc="<?php echo htmlspecialchars($email_detail['body_html']); ?>"
                                    style="width:100%;min-height:300px;border:none;" sandbox=""></iframe>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>

            <!-- Summary stats -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="card text-center">
                        <div class="card-body py-3">
                            <div class="fs-4 fw-bold"><?php echo number_format($stats['total']); ?></div>
                            <div class="text-muted small">Total Emails</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center border-success">
                        <div class="card-body py-3">
                            <div class="fs-4 fw-bold text-success"><?php echo number_format($stats['sent']); ?></div>
                            <div class="text-muted small">Sent</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center border-danger">
                        <div class="card-body py-3">
                            <div class="fs-4 fw-bold text-danger"><?php echo number_format($stats['failed']); ?></div>
                            <div class="text-muted small">Failed</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card text-center border-info">
                        <div class="card-body py-3">
                            <div class="fs-4 fw-bold text-info"><?php echo number_format($stats['scheduled']); ?></div>
                            <div class="text-muted small">Scheduled</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small">Client</label>
                            <select name="client_id" class="form-select form-select-sm">
                                <option value="">All Clients</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo $client['id']; ?>" <?php echo $filter_client_id == $client['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($client['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <label class="form-label small">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <option value="">All Statuses</option>
                                <?php foreach (['sent', 'failed', 'scheduled', 'sending', 'pending'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php echo $filter_status === $s ? 'selected' : ''; ?>>
                                        <?php echo ucfirst($s); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <label class="form-label small">Email Type</label>
                            <select name="template_type" class="form-select form-select-sm">
                                <option value="">All Types</option>
                                <?php foreach ($template_types as $tt): ?>
                                    <option value="<?php echo htmlspecialchars($tt); ?>" <?php echo $filter_template_type === $tt ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tt))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <label class="form-label small">Date From</label>
                            <input type="date" name="date_from" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>
                        <div class="col-sm-6 col-md-2">
                            <label class="form-label small">Date To</label>
                            <input type="date" name="date_to" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>
                        <div class="col-sm-6 col-md-3">
                            <label class="form-label small">Search</label>
                            <input type="text" name="search" class="form-control form-control-sm"
                                   placeholder="Subject, email address, client…"
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-search me-1"></i> Filter
                            </button>
                            <a href="email_logs_list.php" class="btn btn-outline-secondary btn-sm ms-1">
                                <i class="fas fa-rotate-left me-1"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Email table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        Email Log
                        <small class="text-muted fw-normal ms-2">(<?php echo number_format($total_records); ?> record<?php echo $total_records !== 1 ? 's' : ''; ?>)</small>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($emails)): ?>
                        <div class="p-4 text-center text-muted">
                            <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                            No email logs found matching your filters.
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Client</th>
                                    <th>To</th>
                                    <th>Subject</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Attempts</th>
                                    <th>Sent At</th>
                                    <th>Created</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($emails as $email): ?>
                                <tr>
                                    <td class="text-muted small">#<?php echo $email['id']; ?></td>
                                    <td>
                                        <?php if ($email['client_name']): ?>
                                            <a href="clients_view.php?id=<?php echo $email['client_id']; ?>">
                                                <?php echo htmlspecialchars($email['client_name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?php echo htmlspecialchars($email['to_email']); ?></small></td>
                                    <td>
                                        <span title="<?php echo htmlspecialchars($email['subject']); ?>">
                                            <?php echo htmlspecialchars(mb_strimwidth($email['subject'], 0, 55, '…')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($email['template_type']): ?>
                                            <span class="badge bg-secondary">
                                                <?php echo htmlspecialchars(str_replace('_', ' ', $email['template_type'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $s = $email['status'];
                                        $badge = match($s) {
                                            'sent'      => 'success',
                                            'failed'    => 'danger',
                                            'scheduled' => 'info',
                                            'sending'   => 'warning',
                                            default     => 'secondary',
                                        };
                                        ?>
                                        <span class="badge bg-<?php echo $badge; ?>"><?php echo ucfirst(htmlspecialchars($s)); ?></span>
                                        <?php if ($s === 'failed' && $email['error_message']): ?>
                                            <i class="fas fa-circle-exclamation text-danger ms-1"
                                               title="<?php echo htmlspecialchars(mb_strimwidth($email['error_message'], 0, 120, '…')); ?>"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center"><?php echo (int)($email['delivery_attempts'] ?? 0); ?></td>
                                    <td>
                                        <small class="text-muted">
                                            <?php if ($email['sent_at']): ?>
                                                <?php
                                                $dt = new DateTime($email['sent_at'], new DateTimeZone('UTC'));
                                                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                                                echo $dt->format('M j, Y g:i A');
                                                ?>
                                            <?php else: ?>—<?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?php
                                            $dt = new DateTime($email['created_at'], new DateTimeZone('UTC'));
                                            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                                            echo $dt->format('M j, Y g:i A');
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <a href="email_logs_list.php?view_id=<?php echo $email['id']; ?>&<?php echo http_build_query(array_filter([
                                            'client_id'     => $filter_client_id ?: null,
                                            'status'        => $filter_status,
                                            'template_type' => $filter_template_type,
                                            'date_from'     => $filter_date_from,
                                            'date_to'       => $filter_date_to,
                                            'search'        => $search,
                                            'page'          => $page,
                                        ])); ?>"
                                           class="btn btn-sm btn-outline-primary" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                        <small class="text-muted">
                            Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_records)); ?>
                            of <?php echo number_format($total_records); ?> records
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <?php
                                $query_base = array_filter([
                                    'client_id'     => $filter_client_id ?: null,
                                    'status'        => $filter_status,
                                    'template_type' => $filter_template_type,
                                    'date_from'     => $filter_date_from,
                                    'date_to'       => $filter_date_to,
                                    'search'        => $search,
                                ]);
                                $prev_page = $page - 1;
                                $next_page = $page + 1;
                                ?>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query($query_base + ['page' => $prev_page]); ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($total_pages, $page + 2);
                                for ($p = $start; $p <= $end; $p++): ?>
                                <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query($query_base + ['page' => $p]); ?>">
                                        <?php echo $p; ?>
                                    </a>
                                </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query($query_base + ['page' => $next_page]); ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include '../backend/includes/footer.php'; ?>
