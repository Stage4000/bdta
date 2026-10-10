<?php
/** Loopback-only router for the reminder login regression; never an application endpoint. */
if (PHP_SAPI !== 'cli-server' || getenv('BDTA_PORTAL_REMINDER_TEST') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path === '/health') {
    echo 'ready';
    return;
}
if (is_string($path) && str_starts_with($path, '/assets/')) {
    return false;
}
if (!in_array($path, ['/guard', '/portal/login.php', '/portal/appointments.php'], true)) {
    http_response_code(404);
    exit;
}

define('BDTA_TEST_MODE', true);
require_once dirname(__DIR__, 2) . '/backend/includes/settings.php';
Settings::seedCacheForTesting(['timezone' => 'UTC']);
$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$conn->exec('CREATE TABLE settings (setting_key TEXT, setting_value TEXT, setting_type TEXT)');
$conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT, password_hash TEXT, is_admin INTEGER, is_archived INTEGER, last_login TEXT)');
$stmt = $conn->prepare('INSERT INTO clients VALUES (1, ?, ?, ?, 0, 0, NULL)');
$stmt->execute(['Reminder Fixture', 'reminder@example.invalid', password_hash('synthetic-reminder-password', PASSWORD_DEFAULT)]);
$conn->exec('CREATE TABLE client_activity_log (client_id INTEGER, action TEXT, description TEXT, ip_address TEXT)');
$conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY, name TEXT, cancellation_notice_hours INTEGER, portal_available INTEGER, advance_booking_min_days INTEGER, is_active INTEGER)');
$conn->exec("INSERT INTO appointment_types VALUES (7, 'Synthetic Training', 24, 0, 1, 1)");
$conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY, client_id INTEGER, client_email TEXT, appointment_type_id INTEGER, appointment_date TEXT, appointment_time TEXT, status TEXT, service_type TEXT, notes TEXT)');
$stmt = $conn->prepare("INSERT INTO bookings VALUES (?, ?, ?, 7, ?, '18:00:00', ?, 'Synthetic Training', '')");
$future = gmdate('Y-m-d', time() + 7 * 86400);
$stmt->execute([42, 1, 'reminder@example.invalid', $future, 'confirmed']);
$stmt->execute([43, 1, 'reminder@example.invalid', '2000-01-01', 'confirmed']);
$stmt->execute([44, 1, 'reminder@example.invalid', $future, 'cancelled']);
$stmt->execute([45, 2, 'other@example.invalid', $future, 'confirmed']);
$conn->exec('CREATE TABLE client_package_credits (appointment_type_id INTEGER, client_package_id INTEGER, client_id INTEGER, total_credits INTEGER, used_credits INTEGER)');
$conn->exec('CREATE TABLE client_packages (id INTEGER, is_active INTEGER, expires_at TEXT)');
$conn->exec('CREATE TABLE notifications (id INTEGER, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, url TEXT, is_read INTEGER, created_at TEXT, audience TEXT, recipient_id INTEGER, deleted_at TEXT)');
$conn->exec('CREATE TABLE invoices (id INTEGER, client_id INTEGER, invoice_number TEXT, status TEXT, due_date TEXT, total_amount REAL, created_at TEXT)');
$conn->exec('CREATE TABLE quotes (id INTEGER, client_id INTEGER, quote_number TEXT, title TEXT, access_token TEXT, created_at TEXT, status TEXT)');
$conn->exec('CREATE TABLE contracts (id INTEGER, client_id INTEGER, contract_number TEXT, title TEXT, access_token TEXT, created_at TEXT, status TEXT)');
(new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);
session_start();
require_once dirname(__DIR__, 2) . '/backend/includes/config.php';

if ($path === '/guard') {
    // Exercise malformed request targets without asking an HTTP client to follow them.
    $_SERVER['REQUEST_URI'] = scalar_string($_GET['target'] ?? '');
    requirePortalLogin();
    echo 'authenticated';
    return;
}
chdir(dirname(__DIR__, 2) . '/portal');
if ($path === '/portal/login.php') {
    require dirname(__DIR__, 2) . '/portal/login.php';
} else {
    require dirname(__DIR__, 2) . '/portal/appointments.php';
}
