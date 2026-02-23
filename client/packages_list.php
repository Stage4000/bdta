<?php
/**
 * Brook's Dog Training Academy - Bundled Packages List
 * Manage package templates that bundle credits across appointment types
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

$limit_clause = $db->buildLimitClause($per_page, $offset);
$stmt = $conn->prepare("
    SELECT * FROM packages
    ORDER BY is_active DESC, name ASC" . $limit_clause . "
");
$stmt->execute();
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = $conn->query("SELECT COUNT(*) FROM packages")->fetchColumn();
$total_pages = ceil($total / $per_page);

// Fetch items for each package
$package_ids = array_column($packages, 'id');
$items_by_package = [];
if (!empty($package_ids)) {
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM package_items WHERE package_id IN ($placeholders) ORDER BY session_type");
    $stmt->execute($package_ids);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $items_by_package[$item['package_id']][] = $item;
    }
}

// Fetch link analytics per package
$link_stats = [];
if (!empty($package_ids)) {
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
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

$session_type_labels = [
    'group'        => ['label' => 'Group Class',    'badge' => 'secondary'],
    'mini'         => ['label' => 'Mini Session',   'badge' => 'info'],
    'private'      => ['label' => 'Private Session','badge' => 'primary'],
    'field_rental' => ['label' => 'Field Rental',   'badge' => 'warning text-dark'],
];

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
        <div class="alert alert-<?= $_SESSION['flash']['type'] ?> alert-dismissible fade show">
            <?= htmlspecialchars($_SESSION['flash']['message']) ?>
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
                                <th>Status</th>
                                <th>Link Stats</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($packages as $pkg): ?>
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
                                                <?php $meta = $session_type_labels[$item['session_type']] ?? ['label' => ucfirst($item['session_type']), 'badge' => 'secondary']; ?>
                                                <span class="badge bg-<?= $meta['badge'] ?> me-1">
                                                    <?= $item['quantity'] ?>× <?= $meta['label'] ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $pkg['price'] > 0 ? '$' . number_format($pkg['price'], 2) : '<span class="text-muted">—</span>' ?></td>
                                    <td>
                                        <?php if ($pkg['expiration_days']): ?>
                                            <?= $pkg['expiration_days'] ?> days
                                        <?php else: ?>
                                            <span class="text-muted">Never</span>
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
                                        <a href="packages_edit.php?id=<?= $pkg['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-pencil"></i>
                                        </a>
                                        <?php if (!empty($pkg['share_token'])): ?>
                                        <button type="button" class="btn btn-sm btn-outline-success"
                                                title="Copy shareable link"
                                                onclick="copyLink(<?= htmlspecialchars(json_encode($share_url)) ?>, this)">
                                            <i class="fas fa-share-nodes"></i>
                                        </button>
                                        <?php endif; ?>
                                        <a href="packages_edit.php?id=<?= $pkg['id'] ?>&delete=1"
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('Delete this package? This cannot be undone if clients have purchased it.')"
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
        btn.innerHTML = '<i class="fas fa-check"></i>';
        btn.classList.replace('btn-outline-success', 'btn-success');
        setTimeout(function() {
            btn.innerHTML = orig;
            btn.classList.replace('btn-success', 'btn-outline-success');
        }, 2000);
    }).catch(function() {
        prompt('Copy this link:', url);
    });
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
