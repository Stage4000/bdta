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

// $api_result is set to a JSON string on the happy path.
// The shutdown function falls back to an empty-packages response if it is still null
// (e.g. because die() was called during DB init before we could set it).
$api_result = null;

register_shutdown_function(function () use (&$api_result) {
    ob_end_clean(); // Discard any non-JSON output (DB init errors, PHP notices, etc.)
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=120');
    }
    echo $api_result !== null ? $api_result : json_encode(['packages' => []]);
});

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // Fetch active packages that have a share token (purchasable via public link)
    $stmt = $conn->prepare("
        SELECT id, name, description, price, expiration_days, share_token
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
        $items_by_package[$item['package_id']][] = [
            'apt_type_name' => $item['apt_type_name'],
            'quantity'      => (int)$item['quantity'],
        ];
    }

    $base_url = getDynamicBaseUrl();

    $result = [];
    foreach ($packages as $pkg) {
        $result[] = [
            'id'              => (int)$pkg['id'],
            'name'            => $pkg['name'],
            'description'     => $pkg['description'],
            'price'           => (float)$pkg['price'],
            'expiration_days' => $pkg['expiration_days'] ? (int)$pkg['expiration_days'] : null,
            'items'           => $items_by_package[$pkg['id']] ?? [],
            'purchase_url'    => $base_url . '/client/package_detail.php?token=' . rawurlencode($pkg['share_token']),
        ];
    }

    $api_result = json_encode(['packages' => $result]);
} catch (Throwable $e) {
    $api_result = json_encode(['packages' => []]);
}

