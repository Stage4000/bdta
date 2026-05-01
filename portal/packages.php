<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/database.php';
require_once '../backend/includes/bullet_points.php';
requirePortalLogin();

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("
    SELECT id, name, description, bullet_points, price, expiration_days, share_token
    FROM packages
    WHERE is_active = 1
      AND portal_available = 1
      AND share_token IS NOT NULL
      AND share_token != ''
    ORDER BY name ASC
");
$packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

$items_by_package = [];
$package_ids = array_map(static fn (array $package_row): int => safe_int($package_row['id'] ?? 0), $packages);
if ($package_ids !== []) {
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    // Placeholder count is generated from trusted package IDs and values remain parameterized.
    // nosemgrep
    $items_stmt = $conn->prepare("
        SELECT pi.package_id, pi.quantity, at.name AS appointment_type_name
        FROM package_items pi
        JOIN appointment_types at ON pi.appointment_type_id = at.id
        WHERE pi.package_id IN ($placeholders)
        ORDER BY at.name
    ");
    $items_stmt->execute($package_ids);
    foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item_row) {
        $package_id = safe_int($item_row['package_id'] ?? 0);
        $items_by_package[$package_id][] = $item_row;
    }
}

$page_title = 'Packages';
include '../portal/includes/header.php';
?>

<h2 class="mb-4">Packages</h2>

<div class="card">
    <div class="card-header"><strong><i class="fas fa-box-open me-2"></i>Available Packages</strong></div>
    <div class="card-body">
        <?php if ($packages === []): ?>
            <div class="alert alert-info mb-0">There are no packages available for purchase right now. Please check back later or contact us for help.</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($packages as $package): ?>
                    <?php
                    $package_id = safe_int($package['id'] ?? 0);
                    $package_price = safe_float($package['price'] ?? 0);
                    $package_bullets = bdta_parse_bullet_points(array_string_value($package, 'bullet_points'));
                    $purchase_url = '/client/package_detail.php?token=' . rawurlencode(array_string_value($package, 'share_token'));
                    ?>
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                                    <div>
                                        <h5 class="card-title mb-1"><?php echo escape(array_string_value($package, 'name')); ?></h5>
                                        <?php if (array_string_value($package, 'description') !== ''): ?>
                                            <p class="text-muted mb-0"><?php echo escape(array_string_value($package, 'description')); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-bold fs-5"><?php echo $package_price > 0 ? '$' . number_format($package_price, 2) : 'Contact Us'; ?></div>
                                        <div class="small text-muted">
                                            <?php if (array_string_value($package, 'expiration_days') !== ''): ?>
                                                <?php $expiration_days = safe_int($package['expiration_days'] ?? 0); ?>
                                                <?php echo $expiration_days . ' day' . ($expiration_days === 1 ? '' : 's') . ' expiration'; ?>
                                            <?php else: ?>
                                                No expiration
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($package_bullets !== []): ?>
                                    <ul class="mb-3">
                                        <?php foreach ($package_bullets as $bullet_point): ?>
                                            <li><?php echo escape($bullet_point); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>

                                <?php $package_items = $items_by_package[$package_id] ?? []; ?>
                                <div class="mb-3">
                                    <div class="small fw-semibold text-uppercase text-muted mb-2">Includes</div>
                                    <?php if ($package_items === []): ?>
                                        <div class="text-muted small">Package details coming soon.</div>
                                    <?php else: ?>
                                        <?php foreach ($package_items as $item_row): ?>
                                            <span class="badge bg-primary me-1 mb-1">
                                                <?php echo safe_int($item_row['quantity'] ?? 0); ?>× <?php echo escape(array_string_value($item_row, 'appointment_type_name')); ?>
                                            </span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-auto">
                                    <a href="<?php echo escape($purchase_url); ?>" class="btn btn-primary">
                                        <i class="fas fa-shopping-cart me-1"></i> View &amp; Purchase
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../portal/includes/footer.php'; ?>
