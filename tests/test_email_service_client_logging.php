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

    $logged_email = $conn->query('SELECT client_id, status, to_email, subject, mail_type, error_message FROM client_emails')->fetch(PDO::FETCH_ASSOC);
    assertEmailServiceClientLogging(is_array($logged_email), 'Expected automated email attempt to be recorded in client_emails.');
    assertEmailServiceClientLogging((int) ($logged_email['client_id'] ?? 0) === 123, 'Expected logged automated email to keep the client_id.');
    assertEmailServiceClientLogging(($logged_email['status'] ?? '') === 'failed', 'Expected failed SMTP attempt to be marked failed in client_emails.');
    assertEmailServiceClientLogging(($logged_email['to_email'] ?? '') === 'client@example.com', 'Expected logged automated email to keep the destination address.');
    assertEmailServiceClientLogging(($logged_email['subject'] ?? '') === 'Automated client logging regression', 'Expected logged automated email to keep the subject.');
    assertEmailServiceClientLogging(($logged_email['mail_type'] ?? '') === EmailService::MAIL_TYPE_WORKFLOW, 'Expected logged automated email to keep the workflow mail type.');
    assertEmailServiceClientLogging(str_contains((string) ($logged_email['error_message'] ?? ''), 'SMTP host is not configured'), 'Expected logged automated email to store the delivery error.');

    echo "Email service automated client logging test passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    resetEmailServiceClientLoggingState($conn);
}

exit($exit_code);
