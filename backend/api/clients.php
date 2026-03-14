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
function respond_with_error(string $log_message): never {
    http_response_code(500);
    error_log($log_message);
    try {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to load clients',
        ], JSON_THROW_ON_ERROR);
    } catch (JsonException $je) {
        error_log('clients.php: failed to encode error response: ' . $je->getMessage());
        echo '{"success":false,"error":"Failed to load clients"}';
    }
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

    try {
        echo json_encode([
            'success' => true,
            'clients' => $clients,
        ], JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        respond_with_error('clients.php: failed to encode clients response: ' . $e->getMessage());
    }
} catch (PDOException $e) {
    respond_with_error('clients.php: failed to load clients: ' . $e->getMessage());
} catch (Throwable $e) {
    respond_with_error('clients.php: unexpected error: ' . $e->getMessage());
}
