<?php
/**
 * Test Database Connection (MySQL only)
 * AJAX endpoint to verify database connectivity
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

header('Content-Type: application/json');

$response = [
    'success' => true,
    'current_type' => 'mysql',
    'tests' => [],
];

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $table_count = (int) $stmt->fetchColumn();

    $response['tests'][] = [
        'type' => 'mysql',
        'status' => 'success',
        'message' => 'MySQL connection successful',
        'details' => [
            'tables' => $table_count,
            'active' => true,
        ],
    ];
} catch (Exception $e) {
    $response['success'] = false;
    error_log('MySQL connection test failed: ' . $e->getMessage());
    $response['tests'][] = [
        'type' => 'mysql',
        'status' => 'error',
        'message' => 'MySQL connection failed',
        'details' => [
            'error' => 'Unable to connect. Check server logs for details.',
        ],
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
