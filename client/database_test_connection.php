<?php
/**
 * Test Database Connection
 * AJAX endpoint to test database connectivity
 */

require_once __DIR__ . '/../backend/includes/config.php';

requireLogin();

header('Content-Type: application/json');

// Helper function to load database settings
/**
 * @return array<string, string>
 */
function loadDatabaseSettings(): array {
    // Load from .env file instead of database to avoid circular dependency
    require_once __DIR__ . '/../backend/includes/env_loader.php';
    EnvLoader::load();
    
    return [
        'db_host' => EnvLoader::get('DB_HOST', 'localhost'),
        'db_port' => EnvLoader::get('DB_PORT', '3306'),
        'db_name' => EnvLoader::get('DB_NAME', 'bdta'),
        'db_user' => EnvLoader::get('DB_USER', 'root'),
        'db_password' => EnvLoader::get('DB_PASSWORD', ''),
        'sqlite_db_path' => EnvLoader::get('SQLITE_DB_PATH', 'bdta.db')
    ];
}

// Load database settings from settings table
$db_settings = loadDatabaseSettings();

// Use settings from .env
$mysql_host = $db_settings['db_host'] ?? 'localhost';
$mysql_port = $db_settings['db_port'] ?? '3306';
$mysql_db = $db_settings['db_name'] ?? 'bdta';
$mysql_user = $db_settings['db_user'] ?? 'root';
$mysql_pass = $db_settings['db_password'] ?? '';
$sqlite_path = $db_settings['sqlite_db_path'] ?? 'bdta.db';

// Get current database info
require_once __DIR__ . '/../backend/includes/database.php';
$db = new Database();
$current_db_type = $db->getDatabaseType();

$response = [
    'success' => true,
    'current_type' => $current_db_type,
    'tests' => []
];

// Test current connection
try {
    $conn = $db->getConnection();
    
    if ($current_db_type === 'sqlite') {
        $stmt = $conn->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
    } else {
        $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    }
    $table_count = $stmt->fetchColumn();
    
    $response['tests'][] = [
        'type' => $current_db_type,
        'status' => 'success',
        'message' => 'Connected successfully',
        'details' => [
            'tables' => $table_count,
            'active' => true
        ]
    ];
} catch (Exception $e) {
    $response['tests'][] = [
        'type' => $current_db_type,
        'status' => 'error',
        'message' => 'Connection failed',
        'details' => [
            'error' => $e->getMessage()
        ]
    ];
}

// Get current database info
require_once __DIR__ . '/../backend/includes/database.php';
$db = new Database();
$current_db_type = $db->getDatabaseType();

$response = [
    'success' => true,
    'current_type' => $current_db_type,
    'tests' => []
];

// Test current connection
try {
    $conn = $db->getConnection();
    
    if ($current_db_type === 'sqlite') {
        $stmt = $conn->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
    } else {
        $stmt = $conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
    }
    $table_count = $stmt->fetchColumn();
    
    $response['tests'][] = [
        'type' => $current_db_type,
        'status' => 'success',
        'message' => 'Connected successfully',
        'details' => [
            'tables' => $table_count,
            'active' => true
        ]
    ];
} catch (Exception $e) {
    $response['tests'][] = [
        'type' => $current_db_type,
        'status' => 'error',
        'message' => 'Connection failed',
        'details' => [
            'error' => $e->getMessage()
        ]
    ];
}

// Test MySQL if not current
if ($current_db_type !== 'mysql') {
    if ($mysql_host && $mysql_db) {
        try {
            $dsn = "mysql:host={$mysql_host};port={$mysql_port};dbname={$mysql_db};charset=utf8mb4";
            $test_conn = new PDO($dsn, $mysql_user, $mysql_pass);
            $test_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $test_conn->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()");
            $table_count = $stmt->fetchColumn();
            
            $response['tests'][] = [
                'type' => 'mysql',
                'status' => 'success',
                'message' => 'MySQL connection successful',
                'details' => [
                    'host' => $mysql_host,
                    'database' => $mysql_db,
                    'user' => $mysql_user,
                    'tables' => $table_count,
                    'active' => false
                ]
            ];
        } catch (Exception $e) {
            $response['tests'][] = [
                'type' => 'mysql',
                'status' => 'error',
                'message' => 'MySQL connection failed',
                'details' => [
                    'host' => $mysql_host,
                    'database' => $mysql_db,
                    'user' => $mysql_user,
                    'error' => $e->getMessage()
                ]
            ];
        }
    } else {
        $response['tests'][] = [
            'type' => 'mysql',
            'status' => 'warning',
            'message' => 'MySQL not configured',
            'details' => [
                'note' => 'Configure MySQL in Database Settings to test'
            ]
        ];
    }
}

// Test SQLite if not current
if ($current_db_type !== 'sqlite') {
    $sqlite_file = __DIR__ . '/../backend/' . $sqlite_path;
    
    if (file_exists($sqlite_file)) {
        try {
            $test_conn = new PDO('sqlite:' . $sqlite_file);
            $test_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            $stmt = $test_conn->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table'");
            $table_count = $stmt->fetchColumn();
            
            $response['tests'][] = [
                'type' => 'sqlite',
                'status' => 'success',
                'message' => 'SQLite connection successful',
                'details' => [
                    'file' => $sqlite_path,
                    'tables' => $table_count,
                    'active' => false
                ]
            ];
        } catch (Exception $e) {
            $response['tests'][] = [
                'type' => 'sqlite',
                'status' => 'error',
                'message' => 'SQLite connection failed',
                'details' => [
                    'file' => $sqlite_path,
                    'error' => $e->getMessage()
                ]
            ];
        }
    } else {
        $response['tests'][] = [
            'type' => 'sqlite',
            'status' => 'warning',
            'message' => 'SQLite database not found',
            'details' => [
                'file' => $sqlite_path,
                'note' => 'Database will be created on first use'
            ]
        ];
    }
}

echo json_encode($response, JSON_PRETTY_PRINT);
