<?php
/**
 * List clients for assignment/selection
 */
require_once __DIR__ . '/../includes/config.php';
requireLogin();

header('Content-Type: application/json');

/**
 * @return never
 */
function respond_client_error(string $log_message): never {
    http_response_code(500);
    error_log($log_message);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load clients',
    ]);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT id, name, email
        FROM clients
        ORDER BY name ASC
    ");
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'clients' => $clients,
    ], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    respond_client_error('clients.php: failed to load clients: ' . $e->getMessage());
} catch (Throwable $e) {
    respond_client_error('clients.php: unexpected error: ' . $e->getMessage());
}
