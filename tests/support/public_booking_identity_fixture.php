<?php
/** Disposable loopback router for the real public booking controller. */
if (PHP_SAPI !== 'cli-server' || getenv('BDTA_BOOKING_IDENTITY_TEST') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!in_array($path, ['/health', '/identity', '/state', '/fault', '/portal/login.php', '/backend/public/api_bookings.php'], true)) {
    http_response_code(404);
    exit;
}
if ($path === '/health') { echo 'ready'; return; }
define('BDTA_TEST_MODE', true);
require_once dirname(__DIR__, 2) . '/backend/includes/settings.php';
Settings::seedCacheForTesting([
    'timezone' => 'UTC', 'base_url' => 'http://127.0.0.1',
    'email_service' => 'smtp', 'smtp_host' => '', 'smtp_username' => '', 'smtp_password' => '',
    'email_from_address' => 'bookings@example.invalid', 'email_from_name' => 'Synthetic Booking',
    'google_calendar_enabled' => false, 'turnstile_site_key' => '', 'turnstile_secret_key' => '',
]);
$db_file = session_save_path() . '/fixture.sqlite';
$is_new = !is_file($db_file);
$conn = new SafePDO('sqlite:' . $db_file);
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
if ($is_new) {
    $conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
    $conn->exec('CREATE TABLE email_signature_templates (id INTEGER, is_default INTEGER, is_active INTEGER)');
    $conn->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
    $conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT, address TEXT, notes TEXT, is_archived INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
    $conn->exec('ALTER TABLE clients ADD COLUMN password_hash TEXT');
    $conn->exec('ALTER TABLE clients ADD COLUMN is_admin INTEGER DEFAULT 0');
    $conn->exec('ALTER TABLE clients ADD COLUMN last_login TEXT');
    $conn->exec('CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT, email TEXT, phone TEXT, is_primary INTEGER DEFAULT 0)');
    $conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, admin_user_id INTEGER, client_name TEXT, client_email TEXT NOT NULL, client_phone TEXT, service_type TEXT, appointment_date TEXT, appointment_time TEXT, notes TEXT, duration_minutes INTEGER, location TEXT, location_type TEXT, package_credit_id INTEGER, contract_accepted INTEGER, contract_accepted_at TEXT, contract_signature_name TEXT, contract_signature_font TEXT, status TEXT, google_event_id TEXT, ical_token TEXT)');
    $conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, is_active INTEGER DEFAULT 1, admin_user_id INTEGER, duration_minutes INTEGER DEFAULT 60, buffer_before_minutes INTEGER DEFAULT 0, buffer_after_minutes INTEGER DEFAULT 0, requires_admin_confirmation INTEGER DEFAULT 0, confirmation_template_id INTEGER, booking_request_template_id INTEGER, reminder_template_id INTEGER, cancellation_template_id INTEGER, is_mini_session INTEGER DEFAULT 0, mini_session_location TEXT, is_field_rental INTEGER DEFAULT 0, field_rental_location TEXT, is_group_class INTEGER DEFAULT 0, group_class_location TEXT, location_types TEXT, contract_template_id INTEGER, uses_resource INTEGER DEFAULT 0, resource_name TEXT, resource_capacity INTEGER DEFAULT 1, resource_allocation TEXT DEFAULT \'per_appointment\')');
    $conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, name TEXT, species TEXT, breed TEXT, date_of_birth TEXT, source TEXT, spayed_neutered INTEGER DEFAULT 0, vaccines_current INTEGER DEFAULT 0, vaccine_notes TEXT, behavior_notes TEXT, medical_notes TEXT, training_notes TEXT, pet_sitting_notes TEXT, is_active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE appointment_pets (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, pet_id INTEGER, created_at TEXT)');
    $conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, fields TEXT, form_type TEXT, is_active INTEGER, required_frequency TEXT)');
    $conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, booking_id INTEGER, pet_id INTEGER, responses TEXT, status TEXT, submitted_at TEXT)');
    $conn->exec('CREATE TABLE workflow_triggers (id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER, trigger_type TEXT, appointment_type_id INTEGER, form_template_id INTEGER, is_active INTEGER)');
    $conn->exec('CREATE TABLE client_package_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, client_package_id INTEGER, total_credits INTEGER, used_credits INTEGER, updated_at TEXT)');
    $conn->exec('CREATE TABLE client_packages (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER, expires_at TEXT, package_name TEXT)');
    $conn->exec('CREATE TABLE package_credit_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_package_credit_id INTEGER, client_id INTEGER, appointment_type_id INTEGER, transaction_type TEXT, amount INTEGER, booking_id INTEGER, notes TEXT, created_by INTEGER)');
    $conn->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT, recipient_id INTEGER, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, url TEXT, is_read INTEGER DEFAULT 0, read_at TEXT, deleted_at TEXT, created_at TEXT)');
    $conn->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, template_type TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT NOT NULL, body_text TEXT, variables TEXT, is_active INTEGER DEFAULT 1)');
    $conn->exec('CREATE TABLE client_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, direction TEXT NOT NULL, status TEXT NOT NULL, message_id TEXT, from_email TEXT NOT NULL, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, template_id INTEGER, mail_type TEXT, scheduled_at TEXT, sent_at TEXT, delivered_at TEXT, failed_at TEXT, error_message TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE unmatched_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id TEXT, from_email TEXT NOT NULL, from_name TEXT, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, received_at TEXT, direction TEXT DEFAULT \'incoming\', is_assigned INTEGER DEFAULT 0, assigned_to_client_id INTEGER, assigned_at TEXT, assigned_by INTEGER, is_archived INTEGER DEFAULT 0, archived_at TEXT, created_at TEXT)');
    $conn->exec('CREATE TABLE client_activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, action TEXT, description TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

    $stmt = $conn->prepare('INSERT INTO clients (id, name, email, phone, address, is_archived) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ([1 => 'alice', 2 => 'bob', 3 => 'archived', 4 => 'alice'] as $id => $name) {
        $stmt->execute([$id, ucfirst($name), $name . '@example.invalid', '555-0100',
            $id === 4 ? 'Duplicate-email owner venue' : 'Synthetic original venue', $id === 3 ? 1 : 0]);
    }
    $conn->prepare('UPDATE clients SET password_hash = ? WHERE id IN (1, 2)')
        ->execute([password_hash('synthetic-booking-password', PASSWORD_DEFAULT)]);
    $conn->exec("INSERT INTO appointment_types (id, name, location_types) VALUES (7, 'Synthetic Training', '[\"client_address\",\"custom_address\"]')");
    $conn->exec("INSERT INTO client_packages VALUES (1, 1, NULL, 'Synthetic Package')");
    $conn->exec('INSERT INTO client_package_credits VALUES (1, 1, 7, 1, 10, 0, NULL)');
    $conn->exec("INSERT INTO pets (id, client_id, name, breed, is_active) VALUES (1, 1, 'Buddy', 'Original breed', 1), (2, 2, 'Other', 'Other breed', 1)");
    $stmt = $conn->prepare("INSERT INTO form_templates (id, fields, form_type, is_active) VALUES (?, ?, ?, 1)");
    $stmt->execute([8, json_encode([
        ['label' => 'Name', 'type' => 'text', 'profile_mapping' => 'client.name'],
        ['label' => 'Address', 'type' => 'text', 'profile_mapping' => 'client.address'],
        ['label' => 'Breed', 'type' => 'text', 'profile_mapping' => 'pet_1.breed'],
    ]), 'client_form']);
    $stmt->execute([9, json_encode([
        ['label' => 'Name', 'type' => 'text', 'profile_mapping' => 'client.name'],
        ['label' => 'Email', 'type' => 'text', 'profile_mapping' => 'client.email'],
    ]), 'booking_form']);
    $stmt->execute([10, json_encode([
        ['label' => 'First email', 'type' => 'text', 'profile_mapping' => 'client.email'],
        ['label' => 'Second email', 'type' => 'text', 'profile_mapping' => 'client.email'],
    ]), 'client_form']);
}
(new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);
// Start test file sessions before config.php can register the production DB session handler.
session_start();
if ($path === '/identity') {
    $_SESSION = [];
    $id = safe_int($_GET['id'] ?? 0);
    if ($id !== 0) { $_SESSION['portal_client_id'] = $id; }
    // Session email and posted identity hints are deliberately untrusted.
    $_SESSION['portal_client_email'] = 'alice@example.invalid';
    if (isset($_GET['admin'])) { $_SESSION['admin_id'] = 1; }
    echo 'ready';
    return;
}
if ($path === '/state') {
    echo json_encode([
        'clients' => $conn->query('SELECT id, name, email, address FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'credits' => $conn->query('SELECT used_credits FROM client_package_credits')->fetchAll(PDO::FETCH_ASSOC),
        'pets' => $conn->query('SELECT id, client_id, name, breed FROM pets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'bookings' => $conn->query('SELECT id, client_id, location, package_credit_id, status FROM bookings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        'links' => $conn->query('SELECT booking_id, pet_id FROM appointment_pets ORDER BY booking_id, pet_id')->fetchAll(PDO::FETCH_ASSOC),
        'transactions' => safe_int($conn->query('SELECT COUNT(*) FROM package_credit_transactions')->fetchColumn()),
        'forms' => safe_int($conn->query('SELECT COUNT(*) FROM form_submissions')->fetchColumn()),
        'notifications' => safe_int($conn->query('SELECT COUNT(*) FROM notifications')->fetchColumn()),
        'activity' => safe_int($conn->query('SELECT COUNT(*) FROM client_activity_log')->fetchColumn()),
    ]);
    return;
}
if ($path === '/fault') {
    if (($_GET['enabled'] ?? '') === '1') {
        $conn->exec("CREATE TRIGGER fail_booking BEFORE INSERT ON bookings BEGIN SELECT RAISE(ABORT, 'synthetic booking failure'); END");
    } else {
        $conn->exec('DROP TRIGGER IF EXISTS fail_booking');
    }
    echo 'ready';
    return;
}
if ($path === '/portal/login.php') {
    chdir(dirname(__DIR__, 2) . '/portal');
    require dirname(__DIR__, 2) . '/portal/login.php';
    return;
}
chdir(dirname(__DIR__, 2) . '/backend/public');
require dirname(__DIR__, 2) . '/backend/public/api_bookings.php';
