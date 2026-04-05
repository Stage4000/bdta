#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetEmailServiceClientLoggingState(SafePDO $conn): void {
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

function assertEmailServiceClientLogging(bool $condition, string $message): void {
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
] as $key => $value) {
    $insert->execute([$key, $value, 'text']);
}

$conn->prepare('INSERT INTO clients (id, name, email) VALUES (?, ?, ?)')->execute([123, 'Logging Test Client', 'client@example.com']);
$conn->prepare('INSERT INTO client_contacts (client_id, name, email, phone, is_primary) VALUES (?, ?, ?, ?, ?)')->execute([123, 'Alternate Contact', 'alt-contact@example.com', '555-0100', 1]);
$conn->prepare('INSERT INTO bookings (client_id, client_email) VALUES (?, ?)')->execute([123, 'legacy-main-contact@example.com']);

resetEmailServiceClientLoggingState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/includes/email_service.php';

$exit_code = 0;

try {
    $email_service = new EmailService();
    $result = $email_service->sendGenericEmail(
        'client@example.com',
        'Automated client logging regression',
        '<p>Hello automated client log</p>',
        'Hello automated client log',
        EmailService::MAIL_TYPE_WORKFLOW,
        123
    );

    assertEmailServiceClientLogging($result['success'] === false, 'Expected the email send to fail without an SMTP host.');

    // Use mixed-case recipient text to verify client lookup is case-insensitive.
    $result = $email_service->routeMail(
        EmailService::MAIL_TYPE_WORKFLOW,
        'CLIENT@example.com',
        'Workflow email resolved by recipient',
        '<p>Hello workflow fallback</p>',
        'Hello workflow fallback'
    );
    assertEmailServiceClientLogging($result['success'] === false, 'Expected workflow fallback email send to fail without an SMTP host.');

    $result = $email_service->routeMail(
        EmailService::MAIL_TYPE_BOOKING_CONFIRMATION,
        'client@example.com',
        'Booking confirmation resolved by recipient',
        '<p>Hello confirmation fallback</p>',
        'Hello confirmation fallback'
    );
    assertEmailServiceClientLogging($result['success'] === false, 'Expected booking confirmation fallback email send to fail without an SMTP host.');

    $result = $email_service->routeMail(
        EmailService::MAIL_TYPE_BOOKING_CANCELLATION,
        'client@example.com',
        'Booking cancellation resolved by recipient',
        '<p>Hello cancellation fallback</p>',
        'Hello cancellation fallback'
    );
    assertEmailServiceClientLogging($result['success'] === false, 'Expected booking cancellation fallback email send to fail without an SMTP host.');

    // Use mixed-case recipient text to verify contact-email lookup is case-insensitive.
    $result = $email_service->routeMail(
        EmailService::MAIL_TYPE_BOOKING_CONFIRMATION,
        'ALT-CONTACT@example.com',
        'Booking confirmation resolved by contact recipient',
        '<p>Hello contact fallback</p>',
        'Hello contact fallback'
    );
    assertEmailServiceClientLogging($result['success'] === false, 'Expected contact-recipient fallback email send to fail without an SMTP host.');

    $result = $email_service->routeMail(
        EmailService::MAIL_TYPE_GENERIC,
        'client@example.com',
        'Generic automated email without explicit history lookup',
        '<p>Hello generic no opt-in</p>',
        'Hello generic no opt-in'
    );
    assertEmailServiceClientLogging($result['success'] === false, 'Expected generic email without opt-in to still fail without an SMTP host.');

    $result = $email_service->routeMail(
        EmailService::MAIL_TYPE_GENERIC,
        'client@example.com',
        'Generic automated email resolved by recipient',
        '<p>Hello generic fallback</p>',
        'Hello generic fallback',
        ['allow_history_recipient_lookup' => true]
    );
    assertEmailServiceClientLogging($result['success'] === false, 'Expected generic recipient fallback email send to fail without an SMTP host.');

    $result = $email_service->routeMail(
        EmailService::MAIL_TYPE_GENERIC,
        'Legacy Main Contact <legacy-main-contact@example.com>',
        'Generic automated email resolved by booking snapshot recipient',
        '<p>Hello legacy booking fallback</p>',
        'Hello legacy booking fallback',
        ['allow_history_recipient_lookup' => true]
    );
    assertEmailServiceClientLogging($result['success'] === false, 'Expected booking-snapshot recipient fallback email send to fail without an SMTP host.');

    $logged_emails = $conn->query('SELECT client_id, status, to_email, subject, mail_type, error_message FROM client_emails ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    assertEmailServiceClientLogging(count($logged_emails) === 7, 'Expected all automated email attempts to be recorded in client_emails.');

    foreach ($logged_emails as $logged_email) {
        assertEmailServiceClientLogging((int) ($logged_email['client_id'] ?? 0) === 123, 'Expected logged automated email to keep the client_id.');
        assertEmailServiceClientLogging(($logged_email['status'] ?? '') === 'failed', 'Expected failed SMTP attempt to be marked failed in client_emails.');
        assertEmailServiceClientLogging(in_array(strtolower((string) ($logged_email['to_email'] ?? '')), ['client@example.com', 'alt-contact@example.com', 'legacy main contact <legacy-main-contact@example.com>'], true), 'Expected logged automated email to keep the destination address.');
        assertEmailServiceClientLogging(str_contains((string) ($logged_email['error_message'] ?? ''), 'SMTP host is not configured'), 'Expected logged automated email to store the delivery error.');
    }

    assertEmailServiceClientLogging(($logged_emails[0]['subject'] ?? '') === 'Automated client logging regression', 'Expected explicit client_id workflow log row to be recorded first.');
    assertEmailServiceClientLogging(($logged_emails[0]['mail_type'] ?? '') === EmailService::MAIL_TYPE_WORKFLOW, 'Expected explicit workflow email to keep the workflow mail type.');
    assertEmailServiceClientLogging(($logged_emails[1]['mail_type'] ?? '') === EmailService::MAIL_TYPE_WORKFLOW, 'Expected workflow fallback email to keep the workflow mail type.');
    assertEmailServiceClientLogging(($logged_emails[2]['mail_type'] ?? '') === EmailService::MAIL_TYPE_BOOKING_CONFIRMATION, 'Expected booking confirmation fallback email to keep the confirmation mail type.');
    assertEmailServiceClientLogging(($logged_emails[3]['mail_type'] ?? '') === EmailService::MAIL_TYPE_BOOKING_CANCELLATION, 'Expected booking cancellation fallback email to keep the cancellation mail type.');
    assertEmailServiceClientLogging(($logged_emails[4]['mail_type'] ?? '') === EmailService::MAIL_TYPE_BOOKING_CONFIRMATION, 'Expected contact-recipient fallback email to keep the confirmation mail type.');
    assertEmailServiceClientLogging(($logged_emails[4]['to_email'] ?? '') === 'ALT-CONTACT@example.com', 'Expected contact-recipient fallback email to preserve the original recipient casing in the log row.');
    assertEmailServiceClientLogging(($logged_emails[5]['mail_type'] ?? '') === EmailService::MAIL_TYPE_GENERIC, 'Expected generic automated fallback email to keep the generic mail type.');
    assertEmailServiceClientLogging(($logged_emails[5]['subject'] ?? '') === 'Generic automated email resolved by recipient', 'Expected generic automated fallback email to be logged.');
    assertEmailServiceClientLogging(($logged_emails[6]['mail_type'] ?? '') === EmailService::MAIL_TYPE_GENERIC, 'Expected booking-snapshot fallback email to keep the generic mail type.');
    assertEmailServiceClientLogging(($logged_emails[6]['subject'] ?? '') === 'Generic automated email resolved by booking snapshot recipient', 'Expected booking-snapshot fallback email to be logged.');
    assertEmailServiceClientLogging(($logged_emails[6]['to_email'] ?? '') === 'Legacy Main Contact <legacy-main-contact@example.com>', 'Expected booking-snapshot fallback email to preserve the original recipient string in the log row.');
    assertEmailServiceClientLogging(!in_array('Generic automated email without explicit history lookup', array_column($logged_emails, 'subject'), true), 'Expected generic email without explicit history lookup opt-in to stay out of client_emails.');

    echo "Email service automated client logging test passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    resetEmailServiceClientLoggingState($conn);
}

exit($exit_code);
