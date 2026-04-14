<?php
/**
 * Brook's Dog Training Academy - Public Packages API
 * Returns active packages with their items for the public front-end.
 * No authentication required – only active packages with a share token are exposed.
 */

// Buffer ALL output so that die() messages from database initialisation never
// leak into the response body as non-JSON text.
ob_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/bullet_points.php';

/**
 * @return array<string, mixed>
 */
function public_package_row(mixed $row): array {
    return assoc_row($row);
}

// Default to an empty-packages response so shutdown still returns valid JSON
// if die() is called during DB init before the happy-path assignment runs.
$api_result = json_encode(['packages' => []]);

register_shutdown_function(function () use (&$api_result) {
    ob_end_clean(); // Discard any non-JSON output (DB init errors, PHP notices, etc.)
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=120');
    }
    echo $api_result;
});

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // Fetch active packages that have a share token (purchasable via public link)
    $stmt = $conn->prepare("
        SELECT id, name, description, bullet_points, price, expiration_days, share_token
        FROM packages
        WHERE is_active = 1 AND share_token IS NOT NULL AND share_token != ''
        ORDER BY name ASC
    ");
    $stmt->execute();
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($packages)) {
        $api_result = json_encode(['packages' => []]);
        return;
    }

    // Fetch items for each package
    $package_ids  = array_column($packages, 'id');
    $placeholders = implode(',', array_fill(0, count($package_ids), '?'));
    // Placeholder count is derived from trusted server-side package IDs and values stay bound.
    // nosemgrep
    $items_stmt   = $conn->prepare("
        SELECT pi.package_id, pi.quantity, at.name AS apt_type_name
        FROM package_items pi
        JOIN appointment_types at ON pi.appointment_type_id = at.id
        WHERE pi.package_id IN ($placeholders)
        ORDER BY at.name
    ");
    $items_stmt->execute($package_ids);
    $items_by_package = [];
    foreach ($items_stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $item_row = public_package_row($item);
        $package_id = array_int_value($item_row, 'package_id');
        $items_by_package[$package_id][] = [
            'apt_type_name' => array_string_value($item_row, 'apt_type_name'),
            'quantity'      => array_int_value($item_row, 'quantity'),
        ];
    }

    $base_url = getDynamicBaseUrl();

    $result = [];
    foreach ($packages as $pkg) {
        $package_row = public_package_row($pkg);
        $package_id = array_int_value($package_row, 'id');
        $share_token = array_string_value($package_row, 'share_token');
        $expiration_days = array_string_value($package_row, 'expiration_days');
        $result[] = [
            'id'              => $package_id,
            'name'            => array_string_value($package_row, 'name'),
            'description'     => array_string_value($package_row, 'description'),
            'bullet_points'   => bdta_parse_bullet_points(array_string_value($package_row, 'bullet_points')),
            'price'           => safe_float($package_row['price'] ?? 0),
            'expiration_days' => $expiration_days !== '' ? safe_int($expiration_days) : null,
            'items'           => $items_by_package[$package_id] ?? [],
            'purchase_url'    => $base_url . '/client/package_detail.php?token=' . rawurlencode($share_token),
        ];
    }

    $api_result = json_encode(['packages' => $result]);
} catch (Throwable $e) {
    $api_result = json_encode(['packages' => []]);
}
