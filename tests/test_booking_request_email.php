#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetBookingRequestEmailState(SafePDO $conn): void {
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

function assertBookingRequestEmail(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
$conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$conn->exec('CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT, email TEXT, phone TEXT, is_primary INTEGER DEFAULT 0)');
$conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, client_email TEXT NOT NULL)');
$conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY, booking_request_template_id INTEGER)');
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

$insert = $conn->prepare('INSERT INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, ?)');
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
    $insert->execute([$key, $value, 'text']);
}

$conn->prepare('INSERT INTO clients (id, name, email) VALUES (?, ?, ?)')->execute([123, 'Pending Client', 'client@example.com']);

$template_insert = $conn->prepare('
    INSERT INTO email_templates (name, template_type, subject, body_html, body_text, variables, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 1)
');
$template_insert->execute([
    'Default Booking Request',
    'booking_request',
    'Default request for {{client_name}}',
    '<p>Default body for {{client_name}}</p>',
    'Default body for {{client_name}}',
    'client_name',
]);
$default_template_id = (int) $conn->lastInsertId();

$template_insert->execute([
    'Override Booking Request',
    'booking_request',
    'Override request for {{client_name}}',
    '<p>Override body for {{client_name}}</p>',
    'Override body for {{client_name}}',
    'client_name',
]);
$override_template_id = (int) $conn->lastInsertId();

$insert->execute(['default_booking_request_template_id', (string) $default_template_id, 'number']);

$conn->prepare('INSERT INTO appointment_types (id, booking_request_template_id) VALUES (?, ?)')->execute([5, $override_template_id]);
$conn->prepare('INSERT INTO appointment_types (id, booking_request_template_id) VALUES (?, NULL)')->execute([6]);

resetBookingRequestEmailState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/includes/email_service.php';

$email_service = new EmailService('https://example.com', $conn);
$booking = [
    'id' => 77,
    'client_id' => 123,
    'appointment_type_id' => 5,
    'client_name' => 'Pending Client',
    'client_email' => 'client@example.com',
    'appointment_date' => '2026-05-01',
    'appointment_time' => '14:00:00',
    'service_type' => 'Training Evaluation',
    'duration_minutes' => 60,
    'location_type' => 'custom_address',
    'location' => '123 Main St',
];

$result = $email_service->sendBookingRequest($booking);
assertBookingRequestEmail($result['success'] === false, 'Expected override booking request email send to fail without an SMTP host.');

$booking['appointment_type_id'] = 6;
$result = $email_service->sendBookingRequest($booking);
assertBookingRequestEmail($result['success'] === false, 'Expected default booking request email send to fail without an SMTP host.');

$logged_emails = $conn->query('SELECT subject, mail_type, body_text FROM client_emails ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
assertBookingRequestEmail(count($logged_emails) === 2, 'Expected both booking request emails to be logged.');
assertBookingRequestEmail(($logged_emails[0]['subject'] ?? '') === 'Override request for Pending Client', 'Expected appointment-type override booking request template to win.');
assertBookingRequestEmail(($logged_emails[1]['subject'] ?? '') === 'Default request for Pending Client', 'Expected system default booking request template to be used when no override exists.');
assertBookingRequestEmail(($logged_emails[0]['mail_type'] ?? '') === EmailService::MAIL_TYPE_BOOKING_REQUEST, 'Expected booking request mail type to be preserved for override template sends.');
assertBookingRequestEmail(($logged_emails[1]['mail_type'] ?? '') === EmailService::MAIL_TYPE_BOOKING_REQUEST, 'Expected booking request mail type to be preserved for default template sends.');
assertBookingRequestEmail(str_contains((string) ($logged_emails[0]['body_text'] ?? ''), 'Override body for Pending Client'), 'Expected override booking request template variables to render in the body.');
assertBookingRequestEmail(str_contains((string) ($logged_emails[1]['body_text'] ?? ''), 'Default body for Pending Client'), 'Expected default booking request template variables to render in the body.');

resetBookingRequestEmailState($conn);

echo "Booking request email template test passed.\n";
