<?php
/**
 * Auto-Migration Tool
 * Automatically migrates data from SQLite to MySQL
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

// Load settings
$db_settings = loadDatabaseSettings();

$mysql_host = $db_settings['db_host'] ?? 'localhost';
$mysql_port = $db_settings['db_port'] ?? '3306';
$mysql_db = $db_settings['db_name'] ?? 'bdta';
$mysql_user = $db_settings['db_user'] ?? 'root';
$mysql_pass = $db_settings['db_password'] ?? '';
$sqlite_path = $db_settings['sqlite_db_path'] ?? 'bdta.db';

// Validate MySQL configuration
if (empty($mysql_host) || empty($mysql_db) || empty($mysql_user)) {
    echo json_encode([
        'success' => false,
        'error' => 'MySQL not properly configured. Please configure MySQL settings first.'
    ]);
    exit;
}

try {
    // Step 1: Test MySQL connection
    $dsn = "mysql:host={$mysql_host};port={$mysql_port};charset=utf8mb4";
    $mysql_conn = new PDO($dsn, $mysql_user, $mysql_pass);
    $mysql_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 2: Create database if it doesn't exist
    $mysql_conn->exec("CREATE DATABASE IF NOT EXISTS `{$mysql_db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mysql_conn->exec("USE `{$mysql_db}`");
    
    // Disable foreign key checks during migration
    $mysql_conn->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Step 3: Connect to SQLite
    $sqlite_file = __DIR__ . '/../backend/' . $sqlite_path;
    if (!file_exists($sqlite_file)) {
        $mysql_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
        echo json_encode(['success' => false, 'error' => 'SQLite database not found']);
        exit;
    }
    
    $sqlite_conn = new PDO('sqlite:' . $sqlite_file);
    $sqlite_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Step 4: Get all tables from SQLite
    $stmt = $sqlite_conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $migrated_tables = 0;
    $migrated_rows = 0;
    $errors = [];
    
    // Step 5: Drop all tables first to avoid foreign key issues
    foreach ($tables as $table) {
        try {
            $mysql_conn->exec("DROP TABLE IF EXISTS `$table`");
        } catch (Exception $e) {
            // Ignore errors during drop
        }
    }
    
    // Step 6: Migrate schema for all tables
    foreach ($tables as $table) {
        try {
            // Get table schema from SQLite
            $schema_stmt = $sqlite_conn->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
            $create_sql = $schema_stmt->fetchColumn();
            
            if ($create_sql) {
                // Convert SQLite syntax to MySQL
                $mysql_sql = convertSQLiteToMySQL($create_sql);
                $mysql_conn->exec($mysql_sql);
            }
        } catch (Exception $e) {
            $errors[] = "Schema for table $table: " . $e->getMessage();
        }
    }
    
    // Step 7: Migrate data for all tables
    foreach ($tables as $table) {
        try {
            // Get data from SQLite
            $data_stmt = $sqlite_conn->query("SELECT * FROM $table");
            $rows = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                // Prepare insert statement
                $columns = array_keys($rows[0]);
                $placeholders = array_fill(0, count($columns), '?');
                
                $insert_sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $insert_stmt = $mysql_conn->prepare($insert_sql);
                
                // Insert each row
                foreach ($rows as $row) {
                    $insert_stmt->execute(array_values($row));
                    $migrated_rows++;
                }
            }
            
            $migrated_tables++;
            
        } catch (Exception $e) {
            $errors[] = "Data for table $table: " . $e->getMessage();
        }
    }
    
    // Re-enable foreign key checks
    $mysql_conn->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    echo json_encode([
        'success' => true,
        'migrated_tables' => $migrated_tables,
        'total_tables' => count($tables),
        'migrated_rows' => $migrated_rows,
        'errors' => $errors,
        'message' => "Successfully migrated {$migrated_tables} tables with {$migrated_rows} rows to MySQL"
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Convert SQLite CREATE TABLE syntax to MySQL
 */
function convertSQLiteToMySQL(string $sql): string {
    // Replace INTEGER PRIMARY KEY AUTOINCREMENT with INT AUTO_INCREMENT PRIMARY KEY
    $sql = preg_replace(
        '/INTEGER PRIMARY KEY AUTOINCREMENT/i',
        'INT AUTO_INCREMENT PRIMARY KEY',
        $sql
    );
    
    // Replace INTEGER with INT
    $sql = preg_replace('/\bINTEGER\b/i', 'INT', $sql);
    
    // Replace TEXT fields that should be VARCHAR for indexes
    $sql = preg_replace(
        '/(\w+)\s+TEXT\s+(UNIQUE|NOT NULL)/i',
        '$1 VARCHAR(255) $2',
        $sql
    );
    
    // Add backticks around table name for MySQL
    $sql = preg_replace('/CREATE TABLE (\w+)/i', 'CREATE TABLE IF NOT EXISTS `$1`', $sql);
    
    // Add ENGINE=InnoDB at the end
    if (!preg_match('/ENGINE\s*=/i', $sql)) {
        $sql = rtrim($sql, ';') . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
    
    return $sql;
}
