<?php
/**
 * Public contact form API endpoint.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/public_contact_form.php';

header('Content-Type: application/json');

if (scalar_string($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = decode_json_assoc(file_get_contents('php://input'));

$db = new Database();
$conn = $db->getConnection();

try {
    $result = bdta_handle_public_contact_submission($conn, $data);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    error_log('Public contact submission failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Unable to process your message right now.']);
}
