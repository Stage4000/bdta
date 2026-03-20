<?php
/**
 * Form Templates List Page
 * Display and manage form templates
 */

require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/form_types.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle flash messages
$message = '';
$message_type = '';
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'];
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_message_type']);
}

// Get filter
$type_filter = scalar_string($_GET['type'] ?? 'all');

// Pagination
$page = max(1, safe_int($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count and templates
$limit_clause = $db->buildLimitClause($per_page, $offset);

if ($type_filter === 'client') {
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM form_templates WHERE is_internal = 0");
    $count_stmt->execute();
    $total_templates = safe_int($count_stmt->fetchColumn());

    // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-callable.tainted-callable, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- fixed filter and safe_int()-bounded LIMIT/OFFSET literals via $db->buildLimitClause().
    $stmt = $conn->prepare("
        SELECT ft.*, at.name as appointment_type_name
        FROM form_templates ft
        LEFT JOIN appointment_types at ON ft.appointment_type_id = at.id
        WHERE ft.is_internal = 0
        ORDER BY ft.created_at DESC" . $limit_clause);
} elseif ($type_filter === 'internal') {
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM form_templates WHERE is_internal = 1");
    $count_stmt->execute();
    $total_templates = safe_int($count_stmt->fetchColumn());

    // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-callable.tainted-callable, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- fixed filter and safe_int()-bounded LIMIT/OFFSET literals via $db->buildLimitClause().
    $stmt = $conn->prepare("
        SELECT ft.*, at.name as appointment_type_name
        FROM form_templates ft
        LEFT JOIN appointment_types at ON ft.appointment_type_id = at.id
        WHERE ft.is_internal = 1
        ORDER BY ft.created_at DESC" . $limit_clause);
} elseif ($type_filter !== 'all') {
    $count_stmt = $conn->prepare("SELECT COUNT(*) FROM form_templates WHERE form_type = :form_type");
    $count_stmt->bindParam(':form_type', $type_filter);
    $count_stmt->execute();
    $total_templates = safe_int($count_stmt->fetchColumn());

    // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-callable.tainted-callable, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- bound form_type and safe_int()-bounded LIMIT/OFFSET literals via $db->buildLimitClause().
    $stmt = $conn->prepare("
        SELECT ft.*, at.name as appointment_type_name
        FROM form_templates ft
        LEFT JOIN appointment_types at ON ft.appointment_type_id = at.id
        WHERE ft.form_type = :form_type
        ORDER BY ft.created_at DESC" . $limit_clause);
    $stmt->bindParam(':form_type', $type_filter);
} else {
    $count_stmt = $conn->query("SELECT COUNT(*) FROM form_templates");
    $total_templates = safe_int($count_stmt->fetchColumn());

    // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-callable.tainted-callable, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- no user-controlled SQL fragments and safe_int()-bounded LIMIT/OFFSET literals via $db->buildLimitClause().
    $stmt = $conn->prepare("
        SELECT ft.*, at.name as appointment_type_name
        FROM form_templates ft
        LEFT JOIN appointment_types at ON ft.appointment_type_id = at.id
        ORDER BY ft.created_at DESC" . $limit_clause);
}

$total_pages = ceil($total_templates / $per_page);
$stmt->execute();
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="fas fa-file-lines me-2"></i>Form Templates</h2>
                <a href="form_templates_edit.php" class="btn btn-primary">
                    <i class="fas fa-circle-plus me-1"></i> Add New Template
                </a>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?php echo escape($message_type); ?> alert-dismissible fade show" role="alert">
        <?php echo escape($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs">
                <li class="nav-item">
                    <a class="nav-link <?php echo $type_filter == 'all' ? 'active' : ''; ?>" href="?type=all">
                        All Templates
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $type_filter == 'client' ? 'active' : ''; ?>" href="?type=client">
                        Client Forms
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $type_filter == 'internal' ? 'active' : ''; ?>" href="?type=internal">
                        Internal Forms
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body">
            <?php if (count($templates) > 0): ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Fields</th>
                            <th>Required</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $template): 
                            $fields = json_decode($template['fields'], true);
                            $field_count = is_array($fields) ? count($fields) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($template['name']); ?></strong>
                                <?php if ($template['description']): ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($template['description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars(bdta_get_form_type_label(array_string_value($template, 'form_type'))); ?>
                                <?php if ($template['is_internal']): ?>
                                <br><span class="badge bg-warning">Internal</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $field_count; ?> fields</td>
                            <td>
                                <?php if ($template['required_frequency']): ?>
                                    <?php echo ucfirst(str_replace('_', ' ', $template['required_frequency'])); ?>
                                    <?php if ($template['appointment_type_name']): ?>
                                    <br><small class="text-muted">For: <?php echo escape($template['appointment_type_name']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">Optional</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($template['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo escape(formatDate(array_string_value($template, 'created_at'), 'M j, Y')); ?></td>
                             <td>
                                 <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                    <?php if (bdta_normalize_form_type(array_string_value($template, 'form_type')) === 'survey_form'): ?>
                                    <a href="form_survey_results.php?template_id=<?php echo $template['id']; ?>" class="btn btn-sm btn-outline-info table-action-btn" title="Results">
                                        <i class="fas fa-chart-simple"></i>
                                    </a>
                                    <?php endif; ?>
                                     <a href="form_templates_edit.php?id=<?php echo $template['id']; ?>" class="btn btn-sm btn-primary table-action-btn" title="Edit">
                                         <i class="fas fa-pencil"></i>
                                     </a>
                                     <form method="POST" action="form_templates_duplicate.php" class="d-inline">
                                        <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo escape($_SESSION['csrf_token'] ?? ''); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary table-action-btn" title="Duplicate">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </form>
                                    <a href="form_templates_delete.php?id=<?php echo $template['id']; ?>" class="btn btn-sm btn-danger table-action-btn" 
                                       onclick="return confirm('Are you sure you want to delete this template?');"
                                       title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </a>
                                </div>
                                <div class="d-md-none table-action-dropdown">
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="fas fa-ellipsis-v"></i>
                                         </button>
                                         <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if (bdta_normalize_form_type(array_string_value($template, 'form_type')) === 'survey_form'): ?>
                                            <li>
                                                <a class="dropdown-item" href="form_survey_results.php?template_id=<?php echo $template['id']; ?>">
                                                    <i class="fas fa-chart-simple me-2 text-info"></i>Results
                                                </a>
                                            </li>
                                            <?php endif; ?>
                                             <li>
                                                 <a class="dropdown-item" href="form_templates_edit.php?id=<?php echo $template['id']; ?>">
                                                     <i class="fas fa-pencil me-2 text-primary"></i>Edit
                                                 </a>
                                             </li>
                                            <li>
                                                <form method="POST" action="form_templates_duplicate.php">
                                                    <input type="hidden" name="id" value="<?php echo (int) $template['id']; ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo escape($_SESSION['csrf_token'] ?? ''); ?>">
                                                    <button type="submit" class="dropdown-item w-100 text-start border-0 bg-transparent">
                                                        <i class="fas fa-copy me-2 text-secondary"></i>Duplicate
                                                    </button>
                                                </form>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger" href="form_templates_delete.php?id=<?php echo $template['id']; ?>" onclick="return confirm('Are you sure you want to delete this template?');">
                                                    <i class="fas fa-trash me-2"></i>Delete
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

            <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation">
                <ul class="pagination justify-content-center">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&type=<?php echo scalar_string($type_filter); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>

            <?php else: ?>
            <div class="text-center py-5">
                <i class="fas fa-file-lines" style="font-size: 4rem; color: #ccc;"></i>
                <h4 class="mt-3">No Form Templates Found</h4>
                <p class="text-muted">Create your first form template to get started.</p>
                <a href="form_templates_edit.php" class="btn btn-primary mt-2">
                    <i class="fas fa-circle-plus me-1"></i> Create Template
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
