<?php
/**
 * List clients for assignment/selection
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

/**
 * @return never
 */
function respondWithError(string $logMessage, int $status = 500, string $publicMessage = 'Failed to load clients'): never {
    http_response_code($status);
    error_log($logMessage);
    try {
        echo json_encode([
            'success' => false,
            'error' => $publicMessage,
        ], JSON_THROW_ON_ERROR);
    } catch (JsonException $je) {
        error_log('clients.php: failed to encode error response: ' . $je->getMessage());
        echo '{"success":false,"error":"Failed to load clients"}';
    }
    exit;
}

if (!isLoggedIn()) {
    respondWithError('clients.php: unauthorized access', 401, 'Unauthorized access');
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
        respondWithError('clients.php: failed to encode clients response: ' . $e->getMessage(), 500, 'Failed to load clients');
    }
} catch (PDOException $e) {
    respondWithError('clients.php: failed to load clients: ' . $e->getMessage());
} catch (Throwable $e) {
    respondWithError('clients.php: unexpected error: ' . $e->getMessage());
}
