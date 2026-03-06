<?php
/**
 * Brook's Dog Training Academy - Public Packages API
 * Returns active packages with their items for the public front-end.
 * No authentication required – only active packages with a share token are exposed.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';

header('Content-Type: application/json');
header('Cache-Control: public, max-age=120'); // cache 2 minutes

$db  = new Database();
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
    echo json_encode(['packages' => []]);
    exit;
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

echo json_encode(['packages' => $result]);
