<?php
/**
 * List clients for assignment/selection
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->query("
        SELECT id, name, email
        FROM clients
        ORDER BY name ASC
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'clients' => $clients,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load clients',
    ]);
}
