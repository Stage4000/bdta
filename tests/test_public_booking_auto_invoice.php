#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetPublicBookingAutoInvoiceState(SafePDO $conn): void {
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

function assertPublicBookingAutoInvoice(bool $condition, string $message): void {
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
        invoice_template_id INTEGER,
        auto_invoice INTEGER DEFAULT 0,
        invoice_due_days INTEGER DEFAULT 7,
        default_amount REAL DEFAULT 0,
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
    CREATE TABLE invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_number TEXT NOT NULL,
        client_id INTEGER NOT NULL,
        issue_date TEXT,
        due_date TEXT,
        subtotal REAL DEFAULT 0,
        tax_rate REAL DEFAULT 0,
        tax_amount REAL DEFAULT 0,
        total_amount REAL DEFAULT 0,
        notes TEXT,
        status TEXT,
        pay_token TEXT,
        invoice_sent_at TEXT
    )
');
$conn->exec('
    CREATE TABLE invoice_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        item_type TEXT,
        reference_id INTEGER,
        description TEXT,
        quantity REAL DEFAULT 1,
        rate REAL DEFAULT 0,
        amount REAL DEFAULT 0
    )
');
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
    'stripe_enabled' => '0',
] as $key => $value) {
    $insert_setting->execute([$key, $value, 'text']);
}

$conn->prepare('
    INSERT INTO appointment_types (
        name, description, is_active, requires_admin_confirmation, auto_invoice, invoice_due_days, default_amount
    ) VALUES (?, ?, 1, 0, 1, 5, 125.50)
')->execute([
    'Auto Invoice Session',
    'Detailed session description',
]);
$appointment_type_id = (int) $conn->lastInsertId();

resetPublicBookingAutoInvoiceState($conn);

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
    'client_name' => 'Invoice Client',
    'client_email' => 'invoice-client@example.com',
    'client_phone' => '555-3000',
    'service_type' => 'Auto Invoice Session',
    'appointment_type_id' => $appointment_type_id,
    'appointment_date' => '2026-05-20',
    'appointment_time' => '10:30',
    'location_type' => 'custom_address',
    'location_value' => '789 Training Way',
]);

assertPublicBookingAutoInvoice(($result['success'] ?? false) === true, 'Expected auto-invoice booking to succeed.');
$invoice = $conn->query('SELECT invoice_number, due_date, total_amount, pay_token, status, invoice_sent_at FROM invoices ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assertPublicBookingAutoInvoice(is_array($invoice), 'Expected an invoice row to be created for auto-invoice bookings.');
assertPublicBookingAutoInvoice(($invoice['due_date'] ?? '') === '2026-05-25', 'Expected invoice due date to be offset from the appointment date.');
assertPublicBookingAutoInvoice(abs((float) ($invoice['total_amount'] ?? 0) - 125.50) < 0.0001, 'Expected invoice total amount to match the appointment type default amount.');
assertPublicBookingAutoInvoice(trim((string) ($invoice['pay_token'] ?? '')) !== '', 'Expected auto-generated invoices to include a guest payment token.');
assertPublicBookingAutoInvoice(($invoice['status'] ?? '') === 'draft', 'Expected failed invoice email sends to leave the invoice in draft status.');
assertPublicBookingAutoInvoice(($invoice['invoice_sent_at'] ?? null) === null, 'Expected failed invoice email sends to leave invoice_sent_at unset.');

$invoice_item = $conn->query('SELECT description, amount FROM invoice_items ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
assertPublicBookingAutoInvoice(is_array($invoice_item), 'Expected an invoice item row to be created.');
assertPublicBookingAutoInvoice(($invoice_item['description'] ?? '') === 'Auto Invoice Session — Detailed session description', 'Expected the invoice item description to include the appointment type name and description.');
assertPublicBookingAutoInvoice(abs((float) ($invoice_item['amount'] ?? 0) - 125.50) < 0.0001, 'Expected the invoice item amount to match the appointment type default amount.');

$mail_rows = $conn->query('SELECT mail_type, subject, body_text FROM client_emails ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
assertPublicBookingAutoInvoice(count($mail_rows) === 2, 'Expected both the booking confirmation and invoice email attempts to be logged.');
assertPublicBookingAutoInvoice(($mail_rows[1]['mail_type'] ?? '') === EmailService::MAIL_TYPE_INVOICE, 'Expected the second logged email to be the invoice email.');
assertPublicBookingAutoInvoice(str_contains((string) ($mail_rows[1]['body_text'] ?? ''), 'Auto Invoice Session — Detailed session description'), 'Expected the invoice email body to include the appointment type description.');

$conn->prepare("
    INSERT INTO invoices (invoice_number, client_id, issue_date, due_date, total_amount, status, pay_token)
    VALUES ('INV-SUCCESS', 1, '2026-05-20', '2026-05-25', 125.50, 'draft', 'tok-success')
")->execute();
$sent_invoice_id = (int) $conn->lastInsertId();
api_booking_mark_invoice_sent($conn, $sent_invoice_id);
$sent_invoice_stmt = $conn->prepare('SELECT status, invoice_sent_at FROM invoices WHERE id = ?');
$sent_invoice_stmt->execute([$sent_invoice_id]);
$sent_invoice = $sent_invoice_stmt->fetch(PDO::FETCH_ASSOC);
assertPublicBookingAutoInvoice(($sent_invoice['status'] ?? '') === 'sent', 'Expected successful invoice sends to mark draft invoices as sent.');
assertPublicBookingAutoInvoice(trim((string) ($sent_invoice['invoice_sent_at'] ?? '')) !== '', 'Expected successful invoice sends to store invoice_sent_at.');

resetPublicBookingAutoInvoiceState($conn);

echo "Public booking auto-invoice test passed.\n";
