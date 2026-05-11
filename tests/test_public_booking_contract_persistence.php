#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetPublicBookingContractPersistenceState(SafePDO $conn): void
{
    $database_reflection = new ReflectionClass(Database::class);
    $shared_connection = $database_reflection->getProperty('sharedConnection');
    $shared_connection->setAccessible(true);
    $shared_connection->setValue(null, $conn);

    require_once dirname(__DIR__) . '/backend/includes/settings.php';

    $settings_reflection = new ReflectionClass(Settings::class);
    $db_property = $settings_reflection->getProperty('db');
    $db_property->setAccessible(true);
    $db_property->setValue(null, null);

    $cache_property = $settings_reflection->getProperty('cache');
    $cache_property->setAccessible(true);
    $cache_property->setValue(null, []);
}

function assertPublicBookingContractPersistence(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
$conn->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
$conn->exec('
    CREATE TABLE clients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT,
        address TEXT,
        notes TEXT,
        created_at TEXT,
        updated_at TEXT
    )
');
$conn->exec('CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT, email TEXT, phone TEXT, is_primary INTEGER DEFAULT 0)');
$conn->exec('
    CREATE TABLE bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER,
        appointment_type_id INTEGER,
        admin_user_id INTEGER,
        client_name TEXT,
        client_email TEXT NOT NULL,
        client_phone TEXT,
        service_type TEXT,
        appointment_date TEXT,
        appointment_time TEXT,
        notes TEXT,
        duration_minutes INTEGER,
        location TEXT,
        location_type TEXT,
        package_credit_id INTEGER,
        contract_accepted INTEGER,
        contract_accepted_at TEXT,
        contract_signature_name TEXT,
        contract_signature_font TEXT,
        status TEXT,
        google_event_id TEXT
    )
');
$conn->exec('
    CREATE TABLE appointment_types (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        is_active INTEGER DEFAULT 1,
        admin_user_id INTEGER,
        duration_minutes INTEGER DEFAULT 60,
        buffer_before_minutes INTEGER DEFAULT 0,
        buffer_after_minutes INTEGER DEFAULT 0,
        requires_admin_confirmation INTEGER DEFAULT 0,
        confirmation_template_id INTEGER,
        booking_request_template_id INTEGER,
        reminder_template_id INTEGER,
        cancellation_template_id INTEGER,
        is_mini_session INTEGER DEFAULT 0,
        mini_session_location TEXT,
        is_field_rental INTEGER DEFAULT 0,
        field_rental_location TEXT,
        is_group_class INTEGER DEFAULT 0,
        group_class_location TEXT,
        location_types TEXT,
        contract_template_id INTEGER,
        uses_resource INTEGER DEFAULT 0,
        resource_name TEXT,
        resource_capacity INTEGER DEFAULT 1,
        resource_allocation TEXT DEFAULT \'per_appointment\'
    )
');
$conn->exec('
    CREATE TABLE contract_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        template_text TEXT NOT NULL,
        service_type TEXT,
        renewal_period_months INTEGER DEFAULT 12,
        is_active INTEGER DEFAULT 1,
        created_at TEXT,
        updated_at TEXT
    )
');
$conn->exec('
    CREATE TABLE contracts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_number TEXT UNIQUE NOT NULL,
        client_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        contract_text TEXT NOT NULL,
        status TEXT DEFAULT \'draft\',
        access_token TEXT,
        created_date TEXT NOT NULL,
        effective_date TEXT,
        expiration_date TEXT,
        signed_date TEXT,
        signature_data TEXT,
        signature_typed_name TEXT,
        signature_font TEXT,
        ip_address TEXT,
        sent_at TEXT,
        last_reminder_sent TEXT,
        created_at TEXT,
        updated_at TEXT
    )
');
$conn->exec('
    CREATE TABLE contract_signature_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id INTEGER NOT NULL,
        event_type TEXT NOT NULL,
        details TEXT,
        ip_address TEXT,
        user_agent TEXT,
        created_at TEXT
    )
');
$conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, name TEXT, species TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$conn->exec('CREATE TABLE appointment_pets (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, pet_id INTEGER, created_at TEXT)');
$conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, fields TEXT, form_type TEXT, is_active INTEGER)');
$conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, booking_id INTEGER, responses TEXT, status TEXT, submitted_at TEXT)');
$conn->exec('CREATE TABLE workflow_triggers (id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER, trigger_type TEXT, appointment_type_id INTEGER, form_template_id INTEGER, is_active INTEGER)');
$conn->exec('CREATE TABLE client_package_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, client_package_id INTEGER, total_credits INTEGER, used_credits INTEGER)');
$conn->exec('CREATE TABLE client_packages (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER, expires_at TEXT)');
$conn->exec('CREATE TABLE package_credit_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_package_credit_id INTEGER, client_id INTEGER, appointment_type_id INTEGER, transaction_type TEXT, amount INTEGER, booking_id INTEGER, notes TEXT, created_by INTEGER)');
$conn->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT, recipient_id INTEGER, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, url TEXT, is_read INTEGER DEFAULT 0, read_at TEXT, deleted_at TEXT, created_at TEXT)');
$conn->exec('
    CREATE TABLE email_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        template_type TEXT NOT NULL,
        subject TEXT NOT NULL,
        body_html TEXT NOT NULL,
        body_text TEXT,
        variables TEXT,
        is_active INTEGER DEFAULT 1
    )
');
$conn->exec('
    CREATE TABLE client_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        direction TEXT NOT NULL,
        status TEXT NOT NULL,
        message_id TEXT,
        from_email TEXT NOT NULL,
        to_email TEXT NOT NULL,
        subject TEXT NOT NULL,
        body_html TEXT,
        body_text TEXT,
        template_id INTEGER,
        mail_type TEXT,
        scheduled_at TEXT,
        sent_at TEXT,
        delivered_at TEXT,
        failed_at TEXT,
        error_message TEXT,
        created_by INTEGER,
        created_at TEXT,
        updated_at TEXT
    )
');
$conn->exec('
    CREATE TABLE unmatched_emails (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id TEXT,
        from_email TEXT NOT NULL,
        from_name TEXT,
        to_email TEXT NOT NULL,
        subject TEXT NOT NULL,
        body_html TEXT,
        body_text TEXT,
        received_at TEXT,
        direction TEXT DEFAULT \'incoming\',
        is_assigned INTEGER DEFAULT 0,
        assigned_to_client_id INTEGER,
        assigned_at TEXT,
        assigned_by INTEGER,
        is_archived INTEGER DEFAULT 0,
        archived_at TEXT,
        created_at TEXT
    )
');

$insert_setting = $conn->prepare('INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)');
foreach ([
    'timezone' => 'UTC',
    'enable_email_signatures' => '0',
    'smtp_debug' => '0',
    'email_from_address' => 'bookings@example.com',
    'email_from_name' => 'BDTA Test',
    'email_service' => 'smtp',
    'smtp_host' => '',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_port' => '587',
    'smtp_encryption' => 'tls',
    'site_name' => 'BDTA Test',
    'business_email' => 'help@example.com',
] as $key => $value) {
    $insert_setting->execute([$key, $value, 'text']);
}

$conn->prepare('
    INSERT INTO contract_templates (name, description, template_text, renewal_period_months, is_active)
    VALUES (?, ?, ?, 12, 1)
')->execute([
    'Behavior Agreement',
    'Required agreement before first session',
    '<p>Hello {{client_name}} ({{client_email}}), this agreement applies as of {{date}}.</p>',
]);
$contract_template_id = (int) $conn->lastInsertId();

$conn->prepare('
    INSERT INTO appointment_types (name, description, is_active, requires_admin_confirmation, contract_template_id)
    VALUES (?, ?, 1, 0, ?)
')->execute([
    'Intro Session',
    'Contract-backed consultation',
    $contract_template_id,
]);
$appointment_type_id = (int) $conn->lastInsertId();

resetPublicBookingContractPersistenceState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$original_request_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'CLI';
$original_cwd = getcwd();
chdir(dirname(__DIR__) . '/backend/public');
require_once dirname(__DIR__) . '/backend/public/api_bookings.php';
if ($original_cwd !== false) {
    chdir($original_cwd);
}
if ($original_request_method === null) {
    unset($_SERVER['REQUEST_METHOD']);
} else {
    $_SERVER['REQUEST_METHOD'] = $original_request_method;
}

$result = api_booking_create_booking($conn, [
    'client_name' => 'Signed Client',
    'client_email' => 'signed@example.com',
    'client_phone' => '555-3000',
    'service_type' => 'Intro Session',
    'appointment_type_id' => $appointment_type_id,
    'appointment_date' => '2026-05-12',
    'appointment_time' => '10:15',
    'location_type' => 'custom_address',
    'location_value' => '789 Contract Way',
    'contract_typed_name' => 'Signed Client',
    'contract_signature_font' => 'font-pacifico',
]);

assertPublicBookingContractPersistence(($result['success'] ?? false) === true, 'Expected contract-backed booking to succeed.');
$booking_id = safe_int($result['booking_id'] ?? 0);
assertPublicBookingContractPersistence($booking_id > 0, 'Expected contract-backed booking to return a booking id.');

$booking_stmt = $conn->prepare('
    SELECT contract_accepted, contract_signature_name, contract_signature_font
    FROM bookings
    WHERE id = ?
');
$booking_stmt->execute([$booking_id]);
$booking_row = assoc_row($booking_stmt->fetch(PDO::FETCH_ASSOC));
assertPublicBookingContractPersistence(array_int_value($booking_row, 'contract_accepted') === 1, 'Expected booking row to record accepted contract state.');
assertPublicBookingContractPersistence(array_string_value($booking_row, 'contract_signature_name') === 'Signed Client', 'Expected booking row to store the typed contract signature name.');
assertPublicBookingContractPersistence(array_string_value($booking_row, 'contract_signature_font') === 'font-pacifico', 'Expected booking row to store the selected contract signature font.');

$contract_stmt = $conn->prepare('
    SELECT client_id, title, description, contract_text, status, signature_typed_name, signature_font, signed_date, access_token
    FROM contracts
    WHERE client_id = (SELECT client_id FROM bookings WHERE id = ?)
');
$contract_stmt->execute([$booking_id]);
$contract_row = assoc_row($contract_stmt->fetch(PDO::FETCH_ASSOC));

assertPublicBookingContractPersistence($contract_row !== [], 'Expected booking-time contract signing to create a contracts row.');
assertPublicBookingContractPersistence(array_string_value($contract_row, 'title') === 'Behavior Agreement', 'Expected persisted contract to use the template title.');
assertPublicBookingContractPersistence(array_string_value($contract_row, 'description') === 'Required agreement before first session', 'Expected persisted contract to carry over the template description.');
assertPublicBookingContractPersistence(array_string_value($contract_row, 'status') === 'signed', 'Expected persisted contract to be saved as signed.');
assertPublicBookingContractPersistence(array_string_value($contract_row, 'signature_typed_name') === 'Signed Client', 'Expected persisted contract to save the typed signature name.');
assertPublicBookingContractPersistence(array_string_value($contract_row, 'signature_font') === 'font-pacifico', 'Expected persisted contract to save the selected signature font.');
assertPublicBookingContractPersistence(array_string_value($contract_row, 'signed_date') !== '', 'Expected persisted contract to save the signed date.');
assertPublicBookingContractPersistence(array_string_value($contract_row, 'access_token') !== '', 'Expected persisted contract to receive a public access token.');
assertPublicBookingContractPersistence(
    str_contains(array_string_value($contract_row, 'contract_text'), 'Signed Client')
        && str_contains(array_string_value($contract_row, 'contract_text'), 'signed@example.com'),
    'Expected persisted contract text to resolve template variables with the booking client data.'
);

$log_stmt = $conn->query('SELECT event_type, details FROM contract_signature_log ORDER BY id DESC LIMIT 1');
$log_row = assoc_row($log_stmt->fetch(PDO::FETCH_ASSOC));
assertPublicBookingContractPersistence($log_row !== [], 'Expected booking-time contract signing to create a contract signature log entry.');
assertPublicBookingContractPersistence(array_string_value($log_row, 'event_type') === 'signed', 'Expected contract signature log to record a signed event.');
assertPublicBookingContractPersistence(
    str_contains(array_string_value($log_row, 'details'), 'Signed Client')
        && str_contains(array_string_value($log_row, 'details'), 'font-pacifico'),
    'Expected contract signature log details to describe the typed signature and font.'
);

resetPublicBookingContractPersistenceState($conn);

echo "Public booking contract persistence test passed.\n";
