#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetPackagePurchaseInvoiceState(SafePDO $conn): void
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

function assertPackagePurchaseInvoice(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn = new SafePDO('sqlite::memory:');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

$conn->exec('CREATE TABLE app_sessions (id TEXT PRIMARY KEY, data BLOB, timestamp INTEGER)');
$conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
$conn->exec('
    CREATE TABLE clients (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT,
        created_at TEXT,
        updated_at TEXT
    )
');
$conn->exec('CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT, email TEXT, phone TEXT, is_primary INTEGER DEFAULT 0)');
$conn->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
$conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, client_email TEXT)');
$conn->exec('CREATE TABLE packages (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT, price REAL DEFAULT 0, expiration_days INTEGER DEFAULT 0)');
$conn->exec('CREATE TABLE package_items (id INTEGER PRIMARY KEY AUTOINCREMENT, package_id INTEGER NOT NULL, appointment_type_id INTEGER NOT NULL, quantity INTEGER NOT NULL)');
$conn->exec('
    CREATE TABLE client_packages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id INTEGER NOT NULL,
        package_id INTEGER NOT NULL,
        package_name TEXT NOT NULL,
        expires_at TEXT,
        is_active INTEGER DEFAULT 1,
        notes TEXT,
        created_by INTEGER,
        payment_method TEXT,
        stripe_checkout_session_id TEXT
    )
');
$conn->exec('
    CREATE TABLE client_package_credits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_package_id INTEGER NOT NULL,
        client_id INTEGER NOT NULL,
        appointment_type_id INTEGER NOT NULL,
        total_credits INTEGER NOT NULL,
        used_credits INTEGER NOT NULL DEFAULT 0
    )
');
$conn->exec('
    CREATE TABLE package_credit_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_package_credit_id INTEGER NOT NULL,
        client_id INTEGER NOT NULL,
        appointment_type_id INTEGER NOT NULL,
        transaction_type TEXT NOT NULL,
        amount INTEGER NOT NULL,
        booking_id INTEGER,
        notes TEXT,
        created_by INTEGER
    )
');
$conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, description TEXT, fields TEXT, form_type TEXT, is_active INTEGER DEFAULT 1, is_internal INTEGER DEFAULT 0, required_frequency TEXT, appointment_type_id INTEGER)');
$conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, responses TEXT, status TEXT, submitted_at TEXT)');
$conn->exec('CREATE TABLE workflow_triggers (id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER, trigger_type TEXT, appointment_type_id INTEGER, form_template_id INTEGER, is_active INTEGER)');
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
        invoice_sent_at TEXT,
        payment_method TEXT,
        payment_date TEXT,
        stripe_payment_intent_id TEXT,
        receipt_sent_at TEXT,
        updated_at TEXT
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
    CREATE TABLE invoice_payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        payment_date TEXT NOT NULL,
        payment_method TEXT,
        stripe_payment_intent_id TEXT,
        notes TEXT
    )
');
$conn->exec('
    CREATE TABLE invoice_installments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        installment_number INTEGER,
        amount REAL NOT NULL,
        due_date TEXT,
        status TEXT,
        payment_date TEXT,
        payment_method TEXT,
        receipt_sent_at TEXT
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

$conn->prepare('INSERT INTO packages (name, description, price, expiration_days) VALUES (?, ?, ?, ?)')
    ->execute(['Premium Package', 'Five training sessions', 299.00, 90]);
$package_id = (int) $conn->lastInsertId();

$conn->prepare('INSERT INTO package_items (package_id, appointment_type_id, quantity) VALUES (?, ?, ?)')
    ->execute([$package_id, 42, 5]);

resetPackagePurchaseInvoiceState($conn);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__) . '/backend/includes/package_checkout.php';
require_once dirname(__DIR__) . '/backend/includes/email_service.php';

$result = bdta_finalize_package_purchase(
    $conn,
    [
        'id' => $package_id,
        'name' => 'Premium Package',
        'description' => 'Five training sessions',
        'price' => 299.00,
        'expiration_days' => 90,
    ],
    [[
        'appointment_type_id' => 42,
        'quantity' => 5,
    ]],
    'Package Buyer',
    'buyer@example.com',
    '555-0101',
    '',
    null,
    [],
    null,
    'credit_card',
    'cs_pkg_test_123'
);

assertPackagePurchaseInvoice(($result['client_package_id'] ?? 0) > 0, 'Expected package purchase to complete successfully.');

$invoice = $conn->query('SELECT invoice_number, total_amount, status, payment_method, payment_date, receipt_sent_at FROM invoices ORDER BY id DESC LIMIT 1')
    ->fetch(PDO::FETCH_ASSOC);
assertPackagePurchaseInvoice(is_array($invoice), 'Expected package checkout to create an invoice.');
assertPackagePurchaseInvoice(abs((float) ($invoice['total_amount'] ?? 0) - 299.00) < 0.0001, 'Expected package invoice total to match the package price.');
assertPackagePurchaseInvoice(($invoice['status'] ?? '') === 'paid', 'Expected Stripe-paid package invoices to be marked paid.');
assertPackagePurchaseInvoice(($invoice['payment_method'] ?? '') === 'credit_card', 'Expected paid package invoices to capture the credit card payment method.');
assertPackagePurchaseInvoice(trim((string) ($invoice['payment_date'] ?? '')) !== '', 'Expected paid package invoices to record a payment date.');
assertPackagePurchaseInvoice(trim((string) ($invoice['receipt_sent_at'] ?? '')) === '', 'Expected failed receipt sends to leave receipt_sent_at unset.');

$invoice_item = $conn->query('SELECT item_type, reference_id, description, amount FROM invoice_items ORDER BY id DESC LIMIT 1')
    ->fetch(PDO::FETCH_ASSOC);
assertPackagePurchaseInvoice(is_array($invoice_item), 'Expected the package invoice to include a line item.');
assertPackagePurchaseInvoice(($invoice_item['item_type'] ?? '') === 'package', 'Expected package invoices to identify package line items.');
assertPackagePurchaseInvoice((int) ($invoice_item['reference_id'] ?? 0) === $package_id, 'Expected the invoice line item to reference the purchased package.');
assertPackagePurchaseInvoice(str_contains((string) ($invoice_item['description'] ?? ''), 'Premium Package'), 'Expected the package invoice line item to include the package name.');
assertPackagePurchaseInvoice(abs((float) ($invoice_item['amount'] ?? 0) - 299.00) < 0.0001, 'Expected the package invoice line item amount to match the package price.');

$invoice_payment = $conn->query('SELECT amount, payment_method, stripe_payment_intent_id, notes FROM invoice_payments ORDER BY id DESC LIMIT 1')
    ->fetch(PDO::FETCH_ASSOC);
assertPackagePurchaseInvoice(is_array($invoice_payment), 'Expected Stripe-paid package checkouts to record an invoice payment row.');
assertPackagePurchaseInvoice(abs((float) ($invoice_payment['amount'] ?? 0) - 299.00) < 0.0001, 'Expected the recorded invoice payment amount to match the package price.');
assertPackagePurchaseInvoice(($invoice_payment['payment_method'] ?? '') === 'credit_card', 'Expected the recorded package payment to use the credit card method.');
assertPackagePurchaseInvoice(str_contains((string) ($invoice_payment['notes'] ?? ''), 'cs_pkg_test_123'), 'Expected the recorded package payment notes to include the Stripe checkout session id.');

$mail_rows = $conn->query('SELECT mail_type, subject, body_text FROM client_emails ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
assertPackagePurchaseInvoice(count($mail_rows) === 1, 'Expected a paid package purchase to attempt exactly one email.');
assertPackagePurchaseInvoice(($mail_rows[0]['mail_type'] ?? '') === EmailService::MAIL_TYPE_PAYMENT_RECEIPT, 'Expected a paid package purchase to send a payment receipt.');
assertPackagePurchaseInvoice(str_contains((string) ($mail_rows[0]['body_text'] ?? ''), 'Premium Package'), 'Expected the package receipt email to mention the purchased package.');

resetPackagePurchaseInvoiceState($conn);

echo "Package purchase invoice test passed.\n";
