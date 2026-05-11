#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetInvoiceReminderState(SafePDO $conn): void
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

function assertInvoiceReminder(bool $condition, string $message): void
{
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
    CREATE TABLE invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL,
        client_id INTEGER NOT NULL,
        due_date TEXT,
        total_amount REAL DEFAULT 0,
        status TEXT,
        pay_token TEXT,
        last_reminder_sent TEXT
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
    'base_url' => 'https://example.com',
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

$conn->prepare('INSERT INTO clients (id, name, email) VALUES (?, ?, ?)')->execute([1, 'Reminder Client', 'reminder@example.com']);
$conn->prepare('
    INSERT INTO invoices (invoice_number, client_id, due_date, total_amount, status, pay_token, last_reminder_sent)
    VALUES (?, ?, ?, ?, ?, NULL, NULL)
')->execute([
    'INV-REM-100',
    1,
    date('Y-m-d', strtotime('-2 days')),
    87.50,
    'sent',
]);
$invoice_id = (int) $conn->lastInsertId();

resetInvoiceReminderState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/cron/tasks/invoice_reminder.php';
require_once dirname(__DIR__) . '/backend/includes/email_service.php';

$task = new InvoiceReminderTask($conn);
$result = $task->execute();

assertInvoiceReminder($result['success'] === true, 'Expected invoice reminder task execution to complete.');
assertInvoiceReminder($result['items_processed'] === 0, 'Expected reminder sending to fail without an SMTP host.');

$invoice_row = $conn->query('SELECT pay_token, last_reminder_sent FROM invoices WHERE id = ' . $invoice_id)->fetch(PDO::FETCH_ASSOC);
assertInvoiceReminder(is_array($invoice_row), 'Expected invoice row to remain available after reminder execution.');

$pay_token = trim((string) ($invoice_row['pay_token'] ?? ''));
assertInvoiceReminder($pay_token !== '', 'Expected invoice reminders to ensure a guest pay token exists.');
assertInvoiceReminder(($invoice_row['last_reminder_sent'] ?? null) === null, 'Expected failed reminder sends to leave last_reminder_sent unset.');

$logged_email = $conn->query('SELECT mail_type, body_html, body_text FROM client_emails ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assertInvoiceReminder(is_array($logged_email), 'Expected the reminder email attempt to be logged.');
assertInvoiceReminder(($logged_email['mail_type'] ?? '') === EmailService::MAIL_TYPE_INVOICE_REMINDER, 'Expected the logged email type to be invoice_reminder.');

$guest_invoice_url = 'https://example.com/portal/invoice_pay.php?token=' . $pay_token;
assertInvoiceReminder(
    str_contains((string) ($logged_email['body_html'] ?? ''), $guest_invoice_url),
    'Expected the reminder HTML body to link to the guest invoice page.'
);
assertInvoiceReminder(
    str_contains((string) ($logged_email['body_text'] ?? ''), $guest_invoice_url),
    'Expected the reminder text body to link to the guest invoice page.'
);
assertInvoiceReminder(
    !str_contains((string) ($logged_email['body_text'] ?? ''), '/client/invoices_view.php?id='),
    'Invoice reminders should not link clients to the staff invoice view.'
);

resetInvoiceReminderState($conn);

echo "Invoice reminder test passed.\n";
