<?php
/**
 * Brook's Dog Training Academy - Bundled Packages List
 * Manage package templates that bundle credits across appointment types
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/package_contracts.php';
require_once __DIR__ . '/../backend/includes/package_checkout.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Pagination
$page = max(1, safe_int($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$limit_clause = $db->buildLimitClause($per_page, $offset);
// Pagination clause is built from safe_int()-bounded integers only.
// nosemgrep
$stmt = $conn->prepare("
    SELECT p.*
    FROM packages p
    ORDER BY p.is_active DESC, p.name ASC" . $limit_clause . "
");
$stmt->execute();
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = safe_int($conn->query("SELECT COUNT(*) FROM packages")->fetchColumn());
$total_pages = ceil($total / $per_page);

// Fetch items for each package (join appointment_types for name)
$package_ids = array_column($packages, 'id');
$items_by_package = [];
if (!empty($package_ids)) {
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    // Placeholder count is generated from trusted package IDs and the values remain parameterized.
    // nosemgrep
    $stmt = $conn->prepare("
        SELECT pi.*, at.name AS apt_type_name
        FROM package_items pi
        JOIN appointment_types at ON pi.appointment_type_id = at.id
        WHERE pi.package_id IN ($placeholders)
        ORDER BY at.name
    ");
    $stmt->execute($package_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items_by_package[$item['package_id']][] = $item;
    }
}

$contracts_by_package = bdta_get_package_contract_summaries($conn, $package_ids);

$attached_form_names_by_id = [];
$attached_form_ids = array_values(array_unique(array_filter(array_map(static fn (array $package_row): int => safe_int($package_row['form_template_id'] ?? 0), $packages))));
if ($attached_form_ids !== []) {
    $placeholders = implode(',', array_fill(0, count($attached_form_ids), '?'));
    // Placeholder count is generated from trusted form IDs and the values remain parameterized.
    // nosemgrep
    $stmt = $conn->prepare("
        SELECT id, name, form_type, is_active, COALESCE(is_internal, 0) AS is_internal
        FROM form_templates
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($attached_form_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $form_row) {
        if (!bdta_package_form_is_checkout_eligible($form_row)) {
            continue;
        }
        $attached_form_names_by_id[safe_int($form_row['id'] ?? 0)] = array_string_value($form_row, 'name');
    }
}

// Fetch link analytics per package
$link_stats = [];
if (!empty($package_ids)) {
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    // Placeholder count is generated from trusted package IDs and the values remain parameterized.
    // nosemgrep
    $stmt = $conn->prepare("
        SELECT package_id,
               COUNT(*) AS views,
               SUM(purchased) AS purchases
        FROM package_link_views
        WHERE package_id IN ($placeholders)
        GROUP BY package_id
    ");
    $stmt->execute($package_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $link_stats[$row['package_id']] = $row;
    }
}

$page_title = "Bundled Packages";
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-0"><i class="fas fa-box-open me-2"></i>Bundled Packages</h2>
            <p class="text-muted">Define packages that bundle credits across multiple appointment types</p>
        </div>
        <a href="packages_edit.php" class="btn btn-primary">
            <i class="fas fa-circle-plus"></i> Add New Package
        </a>
    </div>

    <?php if (isset($_SESSION['flash'])): ?>
        <?php $flash = is_array($_SESSION['flash']) ? $_SESSION['flash'] : []; ?>
        <?php $flash_type = array_string_value($flash, 'type', 'info'); ?>
        <?php if (!in_array($flash_type, ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'light', 'dark'], true)) $flash_type = 'info'; ?>
        <div class="alert alert-<?= htmlspecialchars($flash_type) ?> alert-dismissible fade show">
            <?= htmlspecialchars(array_string_value($flash, 'message', '')) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($packages)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-box-open display-1 text-muted"></i>
                    <p class="text-muted mt-3">No packages defined yet</p>
                    <a href="packages_edit.php" class="btn btn-primary">Create Your First Package</a>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Contents</th>
                                <th>Price</th>
                                <th>Expiration</th>
                                <th>Requirements</th>
                                <th>Status</th>
                                <th>Link Stats</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($packages as $pkg): ?>
                                <?php $price = safe_float($pkg['price'] ?? 0); ?>
                                <?php $share_url = getDynamicBaseUrl() . '/client/package_detail.php?token=' . ($pkg['share_token'] ?? ''); ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($pkg['name']) ?></strong>
                                        <?php if ($pkg['description']): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($pkg['description']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $items = $items_by_package[$pkg['id']] ?? []; ?>
                                        <?php if (empty($items)): ?>
                                            <span class="text-muted">No items</span>
                                        <?php else: ?>
                                            <?php foreach ($items as $item): ?>
                                                <span class="badge bg-primary me-1">
                                                    <?= $item['quantity'] ?>× <?= htmlspecialchars($item['apt_type_name']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $price > 0 ? '$' . number_format($price, 2) : '<span class="text-muted">—</span>' ?></td>
                                     <td>
                                         <?php if ($pkg['expiration_days']): ?>
                                             <?= $pkg['expiration_days'] ?> days
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
                                         <?php endif; ?>
                                     </td>
                                     <td>
                                          <?php $pkg_contracts = $contracts_by_package[$pkg['id']] ?? []; ?>
                                           <?php $attached_form_name = $attached_form_names_by_id[safe_int($pkg['form_template_id'] ?? 0)] ?? ''; ?>
                                           <?php if (empty($pkg_contracts) && $attached_form_name === ''): ?>
                                              <span class="text-muted">No checkout requirements</span>
                                          <?php else: ?>
                                              <?php if ($attached_form_name !== ''): ?>
                                                  <div class="small mb-2">
                                                      <span class="badge text-bg-info">Form</span>
                                                      <div class="text-muted mt-1"><?= escape($attached_form_name) ?></div>
                                                  </div>
                                              <?php endif; ?>
                                              <?php foreach ($pkg_contracts as $contract): ?>
                                                  <div class="small mb-2">
                                                      <span class="badge text-bg-warning"><?= escape($contract['name']) ?></span>
                                                     <div class="text-muted mt-1"><?= escape(implode(', ', $contract['appointment_types'])) ?></div>
                                                 </div>
                                             <?php endforeach; ?>
                                         <?php endif; ?>
                                     </td>
                                     <td>
                                         <?php if ($pkg['is_active']): ?>
                                             <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $stats = $link_stats[$pkg['id']] ?? ['views' => 0, 'purchases' => 0]; ?>
                                        <small>
                                            <i class="fas fa-eye text-muted"></i> <?= (int)$stats['views'] ?>
                                            &nbsp;
                                            <i class="fas fa-shopping-cart text-success"></i> <?= (int)$stats['purchases'] ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                            <a href="packages_edit.php?id=<?= $pkg['id'] ?>" class="btn btn-sm btn-outline-primary table-action-btn" title="Edit">
                                                <i class="fas fa-pencil"></i>
                                            </a>
                                            <?php if (!empty($pkg['share_token'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success table-action-btn"
                                                    title="Copy shareable link"
                                                    onclick="copyLink(<?= htmlspecialchars(scalar_string(json_encode($share_url))) ?>, this)">
                                                <i class="fas fa-share-nodes"></i>
                                            </button>
                                            <?php endif; ?>
                                            <form method="POST" action="packages_edit.php?id=<?= $pkg['id'] ?>" class="d-inline">
                                                <input type="hidden" name="delete_package" value="1">
                                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                                                <button type="submit"
                                                        class="btn btn-sm btn-outline-danger table-action-btn"
                                                        onclick="return confirm('Delete this package? This cannot be undone if clients have purchased it.')"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        <div class="d-md-none table-action-dropdown">
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu dropdown-menu-end">
                                                    <li>
                                                        <a class="dropdown-item" href="packages_edit.php?id=<?= $pkg['id'] ?>">
                                                            <i class="fas fa-pencil me-2 text-primary"></i>Edit
                                                        </a>
                                                    </li>
                                                    <?php if (!empty($pkg['share_token'])): ?>
                                                    <li>
                                                        <button type="button" class="dropdown-item w-100 text-start border-0 bg-transparent" onclick="copyLink(<?= htmlspecialchars(scalar_string(json_encode($share_url))) ?>, this)">
                                                            <i class="fas fa-share-nodes me-2 text-success"></i>Copy Shareable Link
                                                        </button>
                                                    </li>
                                                    <?php endif; ?>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li>
                                                        <form method="POST" action="packages_edit.php?id=<?= $pkg['id'] ?>">
                                                            <input type="hidden" name="delete_package" value="1">
                                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                                                            <button type="submit" class="dropdown-item w-100 text-start border-0 bg-transparent text-danger" onclick="return confirm('Delete this package? This cannot be undone if clients have purchased it.')">
                                                                <i class="fas fa-trash me-2"></i>Delete
                                                            </button>
                                                        </form>
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
                    <nav class="mt-3">
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
function copyLink(url, btn) {
    navigator.clipboard.writeText(url).then(function() {
        const orig = btn.innerHTML;
        const origClassName = btn.className;
        btn.innerHTML = '<i class="fas fa-check"></i>';
        if (btn.classList.contains('btn')) {
            btn.classList.replace('btn-outline-success', 'btn-success');
        }
        setTimeout(function() {
            btn.innerHTML = orig;
            btn.className = origClassName;
        }, 2000);
    }).catch(function() {
        prompt('Copy this link:', url);
    });
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
