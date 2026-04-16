#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetInvoiceEmailTemplateState(SafePDO $conn): void {
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

function assertInvoiceEmailTemplate(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);
$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
$conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT, email TEXT)');
$conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY, invoice_template_id INTEGER)');
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
    'stripe_enabled' => '0',
] as $key => $value) {
    $insert->execute([$key, $value, 'text']);
}

$conn->prepare('INSERT INTO clients (id, name, email) VALUES (?, ?, ?)')->execute([123, 'Invoice Client', 'client@example.com']);

$template_insert = $conn->prepare('
    INSERT INTO email_templates (name, template_type, subject, body_html, body_text, variables, is_active)
    VALUES (?, ?, ?, ?, ?, ?, 1)
');
$template_insert->execute([
    'Default Invoice',
    'invoice',
    'Default invoice {{invoice_number}}',
    '<p>Default due {{due_date}}</p><p><a href="{{invoice_link}}">View invoice</a></p><p><a href="{{pay_invoice_link}}">Pay invoice</a></p>',
    'Default due {{due_date}} {{invoice_link}} {{pay_invoice_link}}',
    'invoice_number,due_date,invoice_link,pay_invoice_link',
]);
$default_template_id = (int) $conn->lastInsertId();

$template_insert->execute([
    'Override Invoice',
    'invoice',
    'Override invoice {{invoice_number}}',
    '<p>Override amount {{amount_due}}</p><p>{{invoice_items_html}}</p>',
    'Override amount {{amount_due}} {{invoice_items_text}}',
    'invoice_number,amount_due,invoice_items_html,invoice_items_text',
]);
$override_template_id = (int) $conn->lastInsertId();

$insert->execute(['default_invoice_template_id', (string) $default_template_id, 'number']);

$conn->prepare('INSERT INTO appointment_types (id, invoice_template_id) VALUES (?, ?)')->execute([5, $override_template_id]);
$conn->prepare('INSERT INTO appointment_types (id, invoice_template_id) VALUES (?, NULL)')->execute([6]);

resetInvoiceEmailTemplateState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/includes/email_service.php';

$email_service = new EmailService('https://example.com', $conn);
$invoice = [
    'id' => 77,
    'client_id' => 123,
    'client_name' => 'Invoice Client',
    'client_email' => 'client@example.com',
    'invoice_number' => 'INV-1001',
    'issue_date' => '2026-05-01',
    'due_date' => '2026-05-15',
    'total_amount' => 125.50,
    'status' => 'draft',
    'pay_token' => 'abc123',
];

$result = $email_service->sendInvoiceEmail($invoice, [[
    'item_type' => 'appointment_type',
    'reference_id' => 5,
    'description' => 'Training Evaluation',
    'quantity' => 1,
    'rate' => 125.50,
    'amount' => 125.50,
]]);
assertInvoiceEmailTemplate($result['success'] === false, 'Expected appointment-type override invoice email send to fail without an SMTP host.');

$result = $email_service->sendInvoiceEmail($invoice, [[
    'item_type' => 'appointment_type',
    'reference_id' => 6,
    'description' => 'Follow-up Session',
    'quantity' => 1,
    'rate' => 125.50,
    'amount' => 125.50,
]]);
assertInvoiceEmailTemplate($result['success'] === false, 'Expected default invoice email send to fail without an SMTP host.');

$logged_emails = $conn->query('SELECT subject, mail_type, body_text FROM client_emails ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
assertInvoiceEmailTemplate(count($logged_emails) === 2, 'Expected both invoice emails to be logged.');
assertInvoiceEmailTemplate(($logged_emails[0]['subject'] ?? '') === 'Override invoice INV-1001', 'Expected appointment-type invoice template override to win.');
assertInvoiceEmailTemplate(($logged_emails[1]['subject'] ?? '') === 'Default invoice INV-1001', 'Expected system default invoice template to be used when no override exists.');
assertInvoiceEmailTemplate(($logged_emails[0]['mail_type'] ?? '') === EmailService::MAIL_TYPE_INVOICE, 'Expected invoice mail type to be preserved for override template sends.');
assertInvoiceEmailTemplate(($logged_emails[1]['mail_type'] ?? '') === EmailService::MAIL_TYPE_INVOICE, 'Expected invoice mail type to be preserved for default template sends.');
assertInvoiceEmailTemplate(str_contains((string) ($logged_emails[0]['body_text'] ?? ''), 'Override amount 125.50'), 'Expected invoice override variables to render in the body.');
assertInvoiceEmailTemplate(str_contains((string) ($logged_emails[0]['body_text'] ?? ''), 'Training Evaluation'), 'Expected invoice line item text to render in the override body.');
assertInvoiceEmailTemplate(str_contains((string) ($logged_emails[1]['body_text'] ?? ''), 'https://example.com/portal/invoice_pay.php?token=abc123'), 'Expected invoice_link to render to the guest invoice view URL.');
assertInvoiceEmailTemplate(str_contains((string) ($logged_emails[1]['body_text'] ?? ''), 'https://example.com/portal/invoice_checkout.php?token=abc123'), 'Expected pay_invoice_link to render to the direct checkout URL.');

resetInvoiceEmailTemplateState($conn);

echo "Invoice email template test passed.\n";
