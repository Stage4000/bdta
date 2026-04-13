<?php
/**
 * Test Database Connection
 * AJAX endpoint to verify the configured MySQL connection.
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/env_loader.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/admin_users.php';

requireLogin();

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();
if (!bdta_current_admin_can_manage_api_key_settings($conn, $_SESSION)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'tests' => [],
        'error' => 'You do not have permission to access database tools.',
    ], JSON_PRETTY_PRINT);
    exit;
}

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
    $db_type = strtolower(trim(EnvLoader::get('DB_TYPE', 'mysql')));
    if ($db_type !== '' && $db_type !== 'mysql') {
        throw new RuntimeException('SQLite support has been removed. Configure DB_TYPE=mysql or omit DB_TYPE.');
    }

    if (trim($mysql_host) === '' || trim($mysql_db) === '' || trim($mysql_user) === '') {
        throw new RuntimeException('Create a `.env` file from `.env.example` and set DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD for your MySQL database.');
    }

    $dsn = "mysql:host={$mysql_host};port={$mysql_port};dbname={$mysql_db};charset=utf8mb4";
    $conn = new SafePDO($dsn, $mysql_user, $mysql_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

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
