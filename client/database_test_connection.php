<?php
/**
 * Test Database Connection
 * AJAX endpoint to verify the configured MySQL connection.
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/env_loader.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

header('Content-Type: application/json');

EnvLoader::load();

$mysql_host = EnvLoader::get('DB_HOST', 'localhost');
$mysql_port = EnvLoader::get('DB_PORT', '3306');
$mysql_db = EnvLoader::get('DB_NAME', 'bdta');
$mysql_user = EnvLoader::get('DB_USER', '');
$mysql_pass = EnvLoader::get('DB_PASSWORD', '');

$response = [
    'success' => true,
    'current_type' => 'mysql',
    'tests' => [],
];

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    $table_count = $stmt->fetchColumn();

    $response['tests'][] = [
        'type' => 'mysql',
        'status' => 'success',
        'message' => 'MySQL connection successful',
        'details' => [
            'host' => $mysql_host,
            'database' => $mysql_db,
            'user' => $mysql_user,
            'port' => $mysql_port,
            'tables' => $table_count,
            'active' => true,
        ],
    ];
} catch (Throwable $e) {
    $response['success'] = false;
    $response['tests'][] = [
        'type' => 'mysql',
        'status' => 'error',
        'message' => 'MySQL connection failed',
        'details' => [
            'host' => $mysql_host,
            'database' => $mysql_db,
            'user' => $mysql_user,
            'port' => $mysql_port,
            'error' => $e->getMessage(),
        ],
    ];
}

echo json_encode($response, JSON_PRETTY_PRINT);
