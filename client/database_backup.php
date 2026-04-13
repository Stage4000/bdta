<?php
/**
 * Database Backup Tool
 * Downloads a MySQL dump of the configured database.
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';
require_once __DIR__ . '/../backend/includes/admin_users.php';

requireLogin();

$db = new Database();
$conn = $db->getConnection();
if (!bdta_current_admin_can_manage_api_key_settings($conn, $_SESSION)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'You do not have permission to access database tools.']);
    exit;
}

$action = $_GET['action'] ?? 'backup_mysql';
if ($action !== 'backup_mysql' && $action !== 'backup') {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid action specified']);
    exit;
}

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$database = getenv('DB_NAME') ?: 'bdta';
$username = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: '';

if ($username === '') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'MySQL credentials are not configured. Set DB_USER and DB_PASSWORD in .env first.']);
    exit;
}

$backup_filename = 'bdta_mysql_backup_' . date('Y-m-d_H-i-s') . '.sql';
$temp_file = tempnam(sys_get_temp_dir(), 'bdta-backup-');
if ($temp_file === false) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unable to create a temporary backup file.']);
    exit;
}
if (!chmod($temp_file, 0600)) {
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- temp_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($temp_file);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unable to set permissions on the temporary backup file.']);
    exit;
}

$defaults_file = tempnam(sys_get_temp_dir(), 'bdta-mysql-');
if ($defaults_file === false) {
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- temp_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($temp_file);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unable to create a temporary MySQL config file.']);
    exit;
}

$escape_mysql_option = static function (string $value): string {
    return '"' . addcslashes(str_replace(["\r", "\n"], '', $value), "\\\"") . '"';
};

$mysql_password_raw = str_replace(["\r", "\n"], '', $password);
$mysql_host = $escape_mysql_option($host);
$mysql_port = $escape_mysql_option($port);
$mysql_user = $escape_mysql_option($username);
$mysql_password = $escape_mysql_option($password);
$mysql_database = str_replace(["\r", "\n"], '', $database);

$defaults_contents = "[client]\n"
    . "host={$mysql_host}\n"
    . "port={$mysql_port}\n"
    . "user={$mysql_user}\n";
if ($mysql_password_raw !== '') {
    $defaults_contents .= "password={$mysql_password}\n";
}

if (file_put_contents($defaults_file, $defaults_contents) === false) {
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- temp_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($temp_file);
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- defaults_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($defaults_file);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unable to write a temporary MySQL config file.']);
    exit;
}

if (!chmod($defaults_file, 0600)) {
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- temp_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($temp_file);
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- defaults_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($defaults_file);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unable to secure a temporary MySQL config file.']);
    exit;
}

$command = [
    'mysqldump',
    '--defaults-extra-file=' . $defaults_file,
    '--result-file=' . $temp_file,
    $mysql_database,
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

// nosemgrep: php.lang.security.exec-use.exec-use -- proc_open receives a fixed argv array with bypass_shell enabled, credentials come from a temp defaults file, and the dump writes to a server-generated temp file.
$process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
if (!is_resource($process)) {
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- temp_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($temp_file);
    // nosemgrep: php.lang.security.unlink-use.unlink-use -- defaults_file is a server-generated tempnam() path under sys_get_temp_dir().
    unlink($defaults_file);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'MySQL backup failed. Ensure mysqldump is installed.']);
    exit;
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1], 65536);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2], 65536);
fclose($pipes[2]);
$return_var = proc_close($process);
// nosemgrep: php.lang.security.unlink-use.unlink-use -- defaults_file is a server-generated tempnam() path under sys_get_temp_dir().
unlink($defaults_file);
$output = array_filter([trim($stderr), trim($stdout)]);

if ($return_var !== 0 || !file_exists($temp_file)) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'MySQL backup failed. Ensure mysqldump is installed.',
        'details' => implode("\n", $output),
    ]);
    if (file_exists($temp_file)) {
        // nosemgrep: php.lang.security.unlink-use.unlink-use -- temp_file is a server-generated path under sys_get_temp_dir().
        unlink($temp_file);
    }
    exit;
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $backup_filename . '"');
header('Content-Length: ' . filesize($temp_file));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: public');

readfile($temp_file);
// nosemgrep: php.lang.security.unlink-use.unlink-use -- temp_file is a server-generated path under sys_get_temp_dir().
unlink($temp_file);
exit;
