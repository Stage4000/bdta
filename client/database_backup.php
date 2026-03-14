<?php
/**
 * Database Backup Tool
 * Handles database backup operations for both SQLite and MySQL
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

requireLogin();

$action = $_GET['action'] ?? '';

// Get current database info
$db = new Database();
$conn = $db->getConnection();
$current_db_type = $db->getDatabaseType();

switch ($action) {
    case 'backup_sqlite':
        if ($current_db_type !== 'sqlite') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Current database is not SQLite']);
            exit;
        }
        
        $sqlite_file = __DIR__ . '/../backend/bdta.db';
        if (!file_exists($sqlite_file)) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'SQLite database file not found']);
            exit;
        }
        
        // Generate backup filename with timestamp
        $backup_filename = 'bdta_backup_' . date('Y-m-d_H-i-s') . '.db';
        
        // Set headers for download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backup_filename . '"');
        header('Content-Length: ' . filesize($sqlite_file));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');
        
        // Output file
        readfile($sqlite_file);
        exit;
        
    case 'backup_mysql':
        if ($current_db_type !== 'mysql') {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Current database is not MySQL']);
            exit;
        }
        
        // Get MySQL connection details
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_NAME') ?: 'bdta';
        $username = getenv('DB_USER') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: '';
        
        // Generate backup filename with timestamp
        $backup_filename = 'bdta_mysql_backup_' . date('Y-m-d_H-i-s') . '.sql';
        $temp_file = sys_get_temp_dir() . '/' . $backup_filename;
        
        // Use mysqldump to create backup
        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s %s > %s 2>&1',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            $password ? '--password=' . escapeshellarg($password) : '',
            escapeshellarg($database),
            escapeshellarg($temp_file)
        );
        
        // Command arguments are shell-escaped and the temp file path is generated server-side.
        // nosemgrep
        exec($command, $output, $return_var);
        
        if ($return_var !== 0 || !file_exists($temp_file)) {
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'MySQL backup failed. Ensure mysqldump is installed.',
                'details' => implode("\n", $output)
            ]);
            if (file_exists($temp_file)) {
                // temp_file is a server-generated path under sys_get_temp_dir().
                // nosemgrep
                unlink($temp_file);
            }
            exit;
        }
        
        // Set headers for download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $backup_filename . '"');
        header('Content-Length: ' . filesize($temp_file));
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: public');
        
        // Output file and delete temp file
        readfile($temp_file);
        // temp_file is a server-generated path under sys_get_temp_dir().
        // nosemgrep
        unlink($temp_file);
        exit;
        
    default:
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid action specified']);
        exit;
}
