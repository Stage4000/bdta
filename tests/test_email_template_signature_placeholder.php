#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetEmailTemplateSignatureState(SafePDO $conn): void {
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

function assertEmailTemplateSignature(bool $condition, string $message): void {
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
    CREATE TABLE email_signature_templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT,
        html_content TEXT NOT NULL,
        is_default INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        max_image_width INTEGER DEFAULT 600,
        max_image_height INTEGER DEFAULT 200,
        created_by INTEGER,
        created_at TEXT,
        updated_at TEXT
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

$conn->prepare('INSERT INTO clients (id, name, email) VALUES (?, ?, ?)')->execute([123, 'Signature Client', 'client@example.com']);

$template_insert = $conn->prepare('
    INSERT INTO email_templates (name, template_type, subject, body_html, body_text, variables, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 1)
');
$template_insert->execute([
    'Booking Request With Signature',
    'booking_request',
    'Request for {{client_name}}',
    '<p>Hello {{client_name}},</p><p>Please review your booking details.</p><p>{{signature}}</p><p>{{signature}}</p><p>{{signature:2}}</p>',
    "Hello {{client_name}},\n\n{{signature}}\n\n{{signature}}\n\n{{signature:2}}",
    'client_name',
]);
$default_template_id = (int) $conn->lastInsertId();

$conn->prepare('
    INSERT INTO email_signature_templates (name, description, html_content, is_default, is_active)
    VALUES (?, ?, ?, 1, 1)
')->execute([
    'Default Signature',
    'Primary signature',
    '<p><strong>BDTA Team</strong><br>help@example.com</p>',
]);
$conn->prepare('
    INSERT INTO email_signature_templates (name, description, html_content, is_default, is_active)
    VALUES (?, ?, ?, 0, 1)
')->execute([
    'Specific Signature',
    'Secondary signature',
    '<p><strong>BDTA Billing</strong><br>billing@example.com</p>',
]);

$insert->execute(['default_booking_request_template_id', (string) $default_template_id, 'number']);
$conn->prepare('INSERT INTO appointment_types (id, booking_request_template_id) VALUES (?, NULL)')->execute([6]);

resetEmailTemplateSignatureState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/includes/email_service.php';

$email_service = new EmailService('https://example.com', $conn);
$booking = [
    'id' => 77,
    'client_id' => 123,
    'appointment_type_id' => 6,
    'client_name' => 'Signature Client',
    'client_email' => 'client@example.com',
    'appointment_date' => '2026-05-01',
    'appointment_time' => '14:00:00',
    'service_type' => 'Training Evaluation',
    'duration_minutes' => 60,
    'location_type' => 'custom_address',
    'location' => '123 Main St',
];

$result = $email_service->sendBookingRequest($booking);
assertEmailTemplateSignature($result['success'] === false, 'Expected booking request email send to fail without an SMTP host.');

$logged_email = $conn->query('SELECT subject, body_html, body_text FROM client_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assertEmailTemplateSignature(is_array($logged_email), 'Expected booking request email to be logged.');
assertEmailTemplateSignature(($logged_email['subject'] ?? '') === 'Request for Signature Client', 'Expected booking request template variables to still render.');
assertEmailTemplateSignature(str_contains((string) ($logged_email['body_html'] ?? ''), 'BDTA Team'), 'Expected HTML email body to include the rendered default signature.');
assertEmailTemplateSignature(substr_count((string) ($logged_email['body_html'] ?? ''), 'BDTA Team') === 2, 'Expected duplicate HTML signature placeholders to reuse the same rendered default signature.');
assertEmailTemplateSignature(str_contains((string) ($logged_email['body_html'] ?? ''), 'BDTA Billing'), 'Expected HTML email body to include the explicitly requested signature.');
assertEmailTemplateSignature(!str_contains((string) ($logged_email['body_html'] ?? ''), '{{signature}}'), 'Expected HTML email body to remove the signature placeholder.');
assertEmailTemplateSignature(str_contains((string) ($logged_email['body_text'] ?? ''), 'BDTA Team'), 'Expected plain-text email body to include the rendered signature text.');
assertEmailTemplateSignature(substr_count((string) ($logged_email['body_text'] ?? ''), 'BDTA Team') === 2, 'Expected duplicate plain-text signature placeholders to reuse the same rendered default signature.');
assertEmailTemplateSignature(str_contains((string) ($logged_email['body_text'] ?? ''), 'BDTA Billing'), 'Expected plain-text email body to include the explicitly requested signature.');
assertEmailTemplateSignature(!str_contains((string) ($logged_email['body_text'] ?? ''), '{{signature}}'), 'Expected plain-text email body to remove the signature placeholder.');

resetEmailTemplateSignatureState($conn);

echo "Email template signature placeholder test passed.\n";
