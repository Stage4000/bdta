<?php
/**
 * Contract Templates List
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';

// Check if user is logged in
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Filters
$service_type_filter = trim(scalar_string($_GET['service_type'] ?? ''));
$service_type_label = 'Service Type';

// Service type options for filter dropdown
$service_types_stmt = $conn->prepare("
    SELECT DISTINCT service_type 
    FROM contract_templates 
    WHERE service_type IS NOT NULL AND service_type <> '' 
    ORDER BY service_type
");
$service_types_stmt->execute();
$service_types = $service_types_stmt->fetchAll(PDO::FETCH_COLUMN);

// Pagination
$page = max(1, safe_int($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build filters/params
$count_params = [];
$select_params = [];

// Get total count
if ($service_type_filter !== '') {
    $count_sql = 'SELECT COUNT(*) FROM contract_templates WHERE service_type = :service_type';
    $count_params[':service_type'] = $service_type_filter;
    $select_params[':service_type'] = $service_type_filter;
} else {
    $count_sql = 'SELECT COUNT(*) FROM contract_templates';
}
$count_stmt = $conn->prepare($count_sql);
foreach ($count_params as $name => $value) {
    $count_stmt->bindValue($name, $value);
}
$count_stmt->execute();
$total = safe_int($count_stmt->fetchColumn());
$total_pages = ceil($total / $per_page);

// Get templates
// Build limit/offset clause (MySQL cannot reliably parameterize LIMIT/OFFSET)
$limit_clause = $db->buildLimitClause($per_page, $offset);

if ($service_type_filter !== '') {
    // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- LIMIT/OFFSET literals are validated integers produced by buildLimitClause
    $select_sql = "
        SELECT * FROM contract_templates
        WHERE service_type = :service_type
        ORDER BY 
            is_active DESC,
            CASE WHEN service_type IS NULL OR service_type = '' THEN 1 ELSE 0 END,
            service_type,
            name" . $limit_clause . "
    ";
} else {
    // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- LIMIT/OFFSET literals are validated integers produced by buildLimitClause
    $select_sql = "
        SELECT * FROM contract_templates
        ORDER BY 
            is_active DESC,
            CASE WHEN service_type IS NULL OR service_type = '' THEN 1 ELSE 0 END,
            service_type,
            name" . $limit_clause . "
    ";
}
// nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string,php.lang.security.injection.tainted-callable,php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query -- Semgrep flags this PDO prepare due to concatenated LIMIT/OFFSET literals (validated ints from buildLimitClause); query text is otherwise static and parameters are bound
$stmt = $conn->prepare($select_sql);
foreach ($select_params as $name => $value) {
    $stmt->bindValue($name, $value);
}
$stmt->execute();
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Contract Templates";
include '../backend/includes/header.php';
?>

<div class="container-fluid py-4">

    <?php $flash = getFlashMessage(); if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
        <?= escape($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row mb-4">
        <div class="col">
            <h2><i class="fas fa-file-medical me-2"></i>Contract Templates</h2>
            <p class="text-muted">Reusable contract templates for different service types</p>
        </div>
        <div class="col-auto">
            <a href="contract_templates_edit.php" class="btn btn-primary">
                <i class="fas fa-circle-plus me-1"></i>Create Template
            </a>
        </div>
    </div>

    <form method="get" class="row g-3 align-items-end mb-4">
        <div class="col-sm-6 col-md-4 col-lg-3">
            <label for="service_type" class="form-label mb-1"><?= escape($service_type_label) ?></label>
            <select id="service_type" name="service_type" class="form-select">
                <?php
                // Determine if the current filter value exists in the available service types
                $has_current_service_type = ($service_type_filter !== '' && in_array($service_type_filter, $service_types, true));
                ?>
                <option value="">All service types</option>
                <?php if ($service_type_filter !== '' && !$has_current_service_type): ?>
                    <option value="<?= escape($service_type_filter) ?>" selected>
                        <?= escape($service_type_filter) ?> (no longer available)
                    </option>
                <?php endif; ?>
                <?php foreach ($service_types as $type): ?>
                    <option value="<?= escape($type) ?>" <?= $service_type_filter === $type ? 'selected' : '' ?>>
                        <?= escape($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-md-4 col-lg-3">
            <button type="submit" class="btn btn-outline-primary">
                <i class="fas fa-filter me-1"></i>Filter
            </button>
            <?php if ($service_type_filter !== ''): ?>
                <a href="contract_templates_list.php" class="btn btn-link text-decoration-none ms-2">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (count($templates) > 0): ?>
        <?php 
            $previousServiceType = null;
            $uncategorizedLabel = 'Uncategorized';
            $serviceTypeHeading = $service_type_label;
        ?>
        <div class="row">
            <?php foreach ($templates as $template): ?>
                <?php 
                    $sanitizedServiceType = scalar_string($template['service_type'] ?? '');
                    $hasServiceType = $sanitizedServiceType !== '';
                    // sanitized grouping key; escaped later for display
                    $serviceTypeKey = $hasServiceType ? $sanitizedServiceType : $uncategorizedLabel;
                    $serviceLabel = escape($serviceTypeKey);
                    if ($serviceTypeKey !== $previousServiceType):
                        $previousServiceType = $serviceTypeKey;
                ?>
                    <div class="col-12 mb-2">
                        <div class="d-flex align-items-center">
                            <span class="text-uppercase text-muted small fw-semibold"><?= escape($serviceTypeHeading) ?>:</span>
                            <h6 class="mb-0 ms-2"><?= $serviceLabel ?></h6>
                        </div>
                        <hr class="mt-2 mb-3">
                    </div>
                <?php endif; ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0"><?= htmlspecialchars($template['name']) ?></h5>
                                <span class="badge <?= $template['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $template['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            
                            <?php if ($template['description']): ?>
                                <p class="card-text text-muted small"><?= htmlspecialchars($template['description']) ?></p>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <?php if ($hasServiceType): ?>
                                    <span class="badge bg-info me-2"><?= escape($sanitizedServiceType) ?></span>
                                <?php endif; ?>
                                <span class="badge bg-secondary">Renews: <?= $template['renewal_period_months'] ?> months</span>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent">
                            <div class="d-flex flex-wrap gap-2">
                                <a href="contracts_create.php?template_id=<?= (int) $template['id'] ?>" class="btn btn-sm btn-success flex-fill">
                                    <i class="fas fa-circle-plus me-1"></i>Use Template
                                </a>
                                <a href="contract_templates_edit.php?id=<?= (int) $template['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
                                    <i class="fas fa-pencil me-1"></i>Edit
                                </a>
                                <form method="POST" action="contract_templates_duplicate.php" class="flex-fill">
                                    <input type="hidden" name="id" value="<?= (int) $template['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary w-100">
                                        <i class="fas fa-copy me-1"></i>Duplicate
                                    </button>
                                </form>
                                <a href="contract_templates_delete.php?id=<?= (int) $template['id'] ?>"
                                   class="btn btn-sm btn-outline-danger flex-fill"
                                   onclick="return confirm('Are you sure you want to delete this contract template? This action cannot be undone.');">
                                    <i class="fas fa-trash me-1"></i>Delete
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php
                        $paginationBaseParams = [];
                        if ($service_type_filter !== '') {
                            $paginationBaseParams['service_type'] = $service_type_filter;
                        }
                    ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php 
                            $page_query = array_merge($paginationBaseParams, ['page' => $i]);
                            $page_href = '?' . http_build_query($page_query);
                        ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="<?= escape($page_href) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        <?php endif; ?>

    <?php else: ?>
        <div class="alert alert-info">
            <i class="fas fa-circle-info me-2"></i>
            No contract templates found<?= $service_type_filter !== '' ? ' for the selected service type' : '' ?>. <a href="contract_templates_edit.php">Create your first template</a>
            <?php if ($service_type_filter !== ''): ?>
                or <a href="contract_templates_list.php">reset filters</a>.
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include '../backend/includes/footer.php'; ?>
