<?php
/**
 * List clients for assignment/selection
 */
require_once __DIR__ . '/../includes/config.php';

header('Content-Type: application/json');

/**
 * @return never
 */
function respondWithError(string $logMessage, int $status = 500, string $publicMessage = 'An error occurred'): never {
    http_response_code($status);
    $sanitizedLog = preg_replace('/[\r\n]+/', ' ', $logMessage);
    error_log('clients.php error (status ' . $status . '): ' . $sanitizedLog);
    try {
        echo json_encode([
            'success' => false,
            'error' => $publicMessage,
        ], JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $sanitizedMessage = preg_replace('/[\r\n]+/', ' ', $e->getMessage());
        error_log('clients.php: failed to encode error response: ' . $sanitizedMessage);
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
         WHERE COALESCE(is_archived, 0) = 0
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
    $sanitizedPdoMessage = preg_replace('/[\r\n]+/', ' ', $e->getMessage());
    respondWithError('clients.php: failed to load clients: ' . $sanitizedPdoMessage, 500, 'Failed to load clients');
} catch (Throwable $e) {
    respondWithError('clients.php: unexpected error: ' . $e->getMessage());
}
