<?php
/**
 * Database Export Tool
 * Exports database as SQL statements for migration
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

$format = $_GET['format'] ?? 'sql';

// Get current database info
$db = new Database();
$conn = $db->getConnection();
$current_db_type = $db->getDatabaseType();

if ($format !== 'sql') {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Only SQL format is currently supported']);
    exit;
}

if ($current_db_type !== 'sqlite') {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Export currently only supports SQLite databases. Use backup for MySQL.']);
    exit;
}

// Generate export filename with timestamp
$export_filename = 'bdta_export_' . date('Y-m-d_H-i-s') . '.sql';

// Set headers for download
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $export_filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

// Start output
echo "-- Brook's Dog Training Academy Database Export\n";
echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
echo "-- Database Type: SQLite\n";
echo "-- Export Format: SQL (MySQL Compatible)\n";
echo "\n";
echo "-- Note: This export has been converted to MySQL-compatible syntax\n";
echo "-- Review the MYSQL_MIGRATION.md guide for import instructions\n";
echo "\n\n";

try {
    // Get all tables
    $tables_stmt = $conn->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
    $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "-- Found " . count($tables) . " tables\n\n";
    
    foreach ($tables as $table) {
        echo "-- ============================================\n";
        echo "-- Table: $table\n";
        echo "-- ============================================\n\n";
        
        // Get table schema
        $schema_stmt = $conn->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
        $create_sql = $schema_stmt->fetchColumn();
        
        if ($create_sql) {
            // Convert SQLite syntax to MySQL
        $mysql_sql = convertSQLiteToMySQL(scalar_string($create_sql));
            echo "$mysql_sql;\n\n";
        }
        
        // Get table data
        $data_stmt = $conn->query("SELECT * FROM $table");
        $rows = $data_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($rows) > 0) {
            echo "-- Data for table: $table (" . count($rows) . " rows)\n";
            
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $values = array_values($row);
                
                // Escape values
                $escaped_values = array_map(function($val) use ($conn) {
                    if ($val === null) {
                        return 'NULL';
                    }
                    return $conn->quote($val);
                }, $values);
                
                echo "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (";
                echo implode(', ', $escaped_values);
                echo ");\n";
            }
            echo "\n";
        } else {
            echo "-- No data in table: $table\n\n";
        }
    }
    
    echo "-- Export completed successfully\n";
    
} catch (Exception $e) {
    echo "\n-- ERROR: " . $e->getMessage() . "\n";
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
    ) ?? $sql;
    
    // Replace INTEGER with INT
    $sql = preg_replace('/\bINTEGER\b/i', 'INT', $sql) ?? $sql;
    
    // Replace TEXT fields that should be VARCHAR
    // Keep TEXT for long content fields, convert to VARCHAR for shorter fields
    $sql = preg_replace(
        '/(\w+)\s+TEXT\s+(UNIQUE|NOT NULL|DEFAULT)/i',
        '$1 VARCHAR(255) $2',
        $sql
    ) ?? $sql;
    
    // Add backticks around table and column names for MySQL
    $sql = preg_replace('/CREATE TABLE (\w+)/i', 'CREATE TABLE IF NOT EXISTS `$1`', $sql) ?? $sql;
    
    // Replace TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    $sql = str_replace('CURRENT_TIMESTAMP', 'CURRENT_TIMESTAMP', $sql);
    
    return $sql;
}
