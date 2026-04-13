#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetPublicBookingConfirmationState(SafePDO $conn): void {
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

function assertPublicBookingConfirmation(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array<string, mixed> $result
 * @return array{google_calendar: string, ical_download: string}
 */
function public_booking_calendar_links(array $result): array {
    $calendar_links = $result['calendar_links'] ?? null;
    if (!is_array($calendar_links)) {
        return [
            'google_calendar' => '',
            'ical_download' => '',
        ];
    }

    return [
        'google_calendar' => scalar_string($calendar_links['google_calendar'] ?? ''),
        'ical_download' => scalar_string($calendar_links['ical_download'] ?? ''),
    ];
}

function public_booking_status(PDOStatement $status_stmt, int $booking_id): string {
    $status_stmt->execute([$booking_id]);
    return scalar_string($status_stmt->fetchColumn());
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
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
        resource_allocation TEXT DEFAULT 'per_appointment'
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

$type_insert = $conn->prepare('INSERT INTO appointment_types (name, is_active, requires_admin_confirmation) VALUES (?, 1, ?)');
$type_insert->execute(['Pending Approval Type', 1]);
$pending_type_id = (int) $conn->lastInsertId();
$type_insert->execute(['Immediate Confirmation Type', 0]);
$confirmed_type_id = (int) $conn->lastInsertId();

resetPublicBookingConfirmationState($conn);

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

$pending_result = api_booking_create_booking($conn, [
    'client_name' => 'Pending Client',
    'client_email' => 'pending@example.com',
    'client_phone' => '555-1000',
    'service_type' => 'Pending Service',
    'appointment_type_id' => $pending_type_id,
    'appointment_date' => '2026-05-10',
    'appointment_time' => '11:00',
    'location_type' => 'custom_address',
    'location_value' => '123 Pending St',
]);

assertPublicBookingConfirmation(($pending_result['success'] ?? false) === true, 'Expected pending-approval booking to succeed.');
assertPublicBookingConfirmation(($pending_result['booking_status'] ?? '') === 'pending', 'Expected pending-approval booking to return booking_status=pending.');
$pending_calendar_links = public_booking_calendar_links($pending_result);
assertPublicBookingConfirmation($pending_calendar_links['google_calendar'] === '', 'Expected pending-approval booking to omit Google Calendar links.');
assertPublicBookingConfirmation($pending_calendar_links['ical_download'] === '', 'Expected pending-approval booking to omit iCal links.');
$pending_booking_id = safe_int($pending_result['booking_id'] ?? 0);
assertPublicBookingConfirmation($pending_booking_id > 0, 'Expected pending-approval booking to return a booking id.');
$status_stmt = $conn->prepare('SELECT status FROM bookings WHERE id = ?');
assertPublicBookingConfirmation(public_booking_status($status_stmt, $pending_booking_id) === 'pending', 'Expected pending-approval booking to persist with pending status.');

$confirmed_result = api_booking_create_booking($conn, [
    'client_name' => 'Confirmed Client',
    'client_email' => 'confirmed@example.com',
    'client_phone' => '555-2000',
    'service_type' => 'Confirmed Service',
    'appointment_type_id' => $confirmed_type_id,
    'appointment_date' => '2026-05-11',
    'appointment_time' => '15:30',
    'location_type' => 'custom_address',
    'location_value' => '456 Confirmed Ave',
]);

assertPublicBookingConfirmation(($confirmed_result['success'] ?? false) === true, 'Expected immediately-confirmed booking to succeed.');
assertPublicBookingConfirmation(($confirmed_result['booking_status'] ?? '') === 'confirmed', 'Expected immediately-confirmed booking to return booking_status=confirmed.');
$confirmed_calendar_links = public_booking_calendar_links($confirmed_result);
assertPublicBookingConfirmation($confirmed_calendar_links['ical_download'] !== '', 'Expected immediately-confirmed booking to include an iCal link.');
$confirmed_booking_id = safe_int($confirmed_result['booking_id'] ?? 0);
assertPublicBookingConfirmation($confirmed_booking_id > 0, 'Expected immediately-confirmed booking to return a booking id.');
assertPublicBookingConfirmation(public_booking_status($status_stmt, $confirmed_booking_id) === 'confirmed', 'Expected immediately-confirmed booking to persist with confirmed status.');

resetPublicBookingConfirmationState($conn);

echo "Public booking confirmation requirement test passed.\n";
