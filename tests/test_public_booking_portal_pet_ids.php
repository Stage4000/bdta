#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetPublicBookingPortalPetState(SafePDO $conn): void
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

function assertPortalPetBooking(bool $condition, string $message): void
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
$conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT NOT NULL, phone TEXT, address TEXT, notes TEXT, created_at TEXT, updated_at TEXT)');
$conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, admin_user_id INTEGER, client_name TEXT, client_email TEXT NOT NULL, client_phone TEXT, service_type TEXT, appointment_date TEXT, appointment_time TEXT, notes TEXT, duration_minutes INTEGER, location TEXT, location_type TEXT, package_credit_id INTEGER, contract_accepted INTEGER, contract_accepted_at TEXT, contract_signature_name TEXT, contract_signature_font TEXT, status TEXT, google_event_id TEXT)');
$conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, is_active INTEGER DEFAULT 1, admin_user_id INTEGER, duration_minutes INTEGER DEFAULT 60, buffer_before_minutes INTEGER DEFAULT 0, buffer_after_minutes INTEGER DEFAULT 0, is_mini_session INTEGER DEFAULT 0, mini_session_location TEXT, is_field_rental INTEGER DEFAULT 0, field_rental_location TEXT, is_group_class INTEGER DEFAULT 0, group_class_location TEXT, location_types TEXT, contract_template_id INTEGER, confirmation_template_id INTEGER, booking_request_template_id INTEGER, reminder_template_id INTEGER, cancellation_template_id INTEGER, requires_admin_confirmation INTEGER DEFAULT 0, uses_resource INTEGER DEFAULT 0, resource_name TEXT, resource_capacity INTEGER DEFAULT 1, resource_allocation TEXT DEFAULT \'per_appointment\')');
$conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, name TEXT, species TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$conn->exec('CREATE TABLE appointment_pets (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, pet_id INTEGER, created_at TEXT)');
$conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, fields TEXT, form_type TEXT, is_active INTEGER)');
$conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, booking_id INTEGER, responses TEXT, status TEXT, submitted_at TEXT)');
$conn->exec('CREATE TABLE workflow_triggers (id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER, trigger_type TEXT, appointment_type_id INTEGER, form_template_id INTEGER, is_active INTEGER)');
$conn->exec('CREATE TABLE client_package_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, client_package_id INTEGER, total_credits INTEGER, used_credits INTEGER)');
$conn->exec('CREATE TABLE client_packages (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER, expires_at TEXT)');
$conn->exec('CREATE TABLE package_credit_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_package_credit_id INTEGER, client_id INTEGER, appointment_type_id INTEGER, transaction_type TEXT, amount INTEGER, booking_id INTEGER, notes TEXT, created_by INTEGER)');
$conn->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, template_type TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT NOT NULL, body_text TEXT, variables TEXT, is_active INTEGER DEFAULT 1)');
$conn->exec('CREATE TABLE client_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, direction TEXT NOT NULL, status TEXT NOT NULL, message_id TEXT, from_email TEXT NOT NULL, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, template_id INTEGER, mail_type TEXT, scheduled_at TEXT, sent_at TEXT, delivered_at TEXT, failed_at TEXT, error_message TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
$conn->exec('CREATE TABLE unmatched_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id TEXT, from_email TEXT NOT NULL, from_name TEXT, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, received_at TEXT, direction TEXT DEFAULT \'incoming\', is_assigned INTEGER DEFAULT 0, assigned_to_client_id INTEGER, assigned_at TEXT, assigned_by INTEGER, is_archived INTEGER DEFAULT 0, archived_at TEXT, created_at TEXT)');

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

resetPublicBookingPortalPetState($conn);

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

$original_portal_session = [
    'portal_client_id' => $_SESSION['portal_client_id'] ?? null,
    'portal_client_name' => $_SESSION['portal_client_name'] ?? null,
    'portal_client_email' => $_SESSION['portal_client_email'] ?? null,
];

try {
    $suffix = bin2hex(random_bytes(4));

    $conn->prepare('INSERT INTO appointment_types (name, is_active) VALUES (?, 1)')
        ->execute(['Portal Pet Selection ' . $suffix]);
    $appointment_type_id = (int) $conn->lastInsertId();

    $client_stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, created_at, updated_at)
        VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $client_stmt->execute([
        'Portal Pet Owner ' . $suffix,
        'portal-owner-' . $suffix . '@example.com',
        '555-0100',
    ]);
    $portal_client_id = (int) $conn->lastInsertId();

    $client_stmt->execute([
        'Other Client ' . $suffix,
        'portal-other-' . $suffix . '@example.com',
        '555-0200',
    ]);
    $other_client_id = (int) $conn->lastInsertId();

    $pet_stmt = $conn->prepare("
        INSERT INTO pets (client_id, name, species, is_active, created_at, updated_at)
        VALUES (?, ?, 'Dog', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");

    $portal_pet_ids = [];
    for ($i = 1; $i <= 105; $i++) {
        $pet_stmt->execute([$portal_client_id, 'Portal Pet ' . $suffix . ' #' . $i]);
        $portal_pet_ids[] = (int) $conn->lastInsertId();
    }

    $pet_stmt->execute([$other_client_id, 'Other Pet ' . $suffix]);
    $other_pet_id = (int) $conn->lastInsertId();

    $_SESSION['portal_client_id'] = $portal_client_id;
    $_SESSION['portal_client_name'] = 'Portal Pet Owner ' . $suffix;
    $_SESSION['portal_client_email'] = 'portal-owner-' . $suffix . '@example.com';

    $capped_result = api_booking_create_booking($conn, [
        'client_name' => 'Portal Pet Owner ' . $suffix,
        'client_email' => 'portal-owner-' . $suffix . '@example.com',
        'client_phone' => '555-0100',
        'service_type' => 'Portal Pet Count',
        'appointment_type_id' => $appointment_type_id,
        'appointment_date' => '2026-06-10',
        'appointment_time' => '09:00',
        'location_type' => 'custom_address',
        'location_value' => '123 Portal Lane',
        'pet_ids' => array_merge([0, -4], $portal_pet_ids),
    ]);

    assertPortalPetBooking(($capped_result['success'] ?? false) === true, 'Portal bookings with selected pet IDs should succeed.');
    $capped_booking_id = safe_int($capped_result['booking_id'] ?? 0);
    assertPortalPetBooking($capped_booking_id > 0, 'Capped portal pet booking should return a booking ID.');

    $linked_pet_stmt = $conn->prepare("
        SELECT pet_id
        FROM appointment_pets
        WHERE booking_id = ?
        ORDER BY pet_id ASC
    ");
    $linked_pet_stmt->execute([$capped_booking_id]);
    $capped_linked_pet_ids = array_map('safe_int', $linked_pet_stmt->fetchAll(PDO::FETCH_COLUMN));

    assertPortalPetBooking(count($capped_linked_pet_ids) === 100, 'Portal pet ID normalization should cap selected pets at 100.');
    assertPortalPetBooking(
        $capped_linked_pet_ids === array_slice($portal_pet_ids, 0, 100),
        'Portal pet ID normalization should keep the first 100 owned positive pet IDs in order.'
    );

    $ownership_result = api_booking_create_booking($conn, [
        'client_name' => 'Portal Pet Owner ' . $suffix,
        'client_email' => 'portal-owner-' . $suffix . '@example.com',
        'client_phone' => '555-0100',
        'service_type' => 'Portal Pet Ownership',
        'appointment_type_id' => $appointment_type_id,
        'appointment_date' => '2026-06-11',
        'appointment_time' => '10:00',
        'location_type' => 'custom_address',
        'location_value' => '123 Portal Lane',
        'pet_ids' => [$other_pet_id, $portal_pet_ids[0], $portal_pet_ids[0], 0],
    ]);

    assertPortalPetBooking(($ownership_result['success'] ?? false) === true, 'Portal bookings with mixed owned and unowned pet IDs should still succeed.');
    $ownership_booking_id = safe_int($ownership_result['booking_id'] ?? 0);
    assertPortalPetBooking($ownership_booking_id > 0, 'Ownership-filtered portal pet booking should return a booking ID.');

    $linked_pet_stmt->execute([$ownership_booking_id]);
    $ownership_linked_pet_ids = array_map('safe_int', $linked_pet_stmt->fetchAll(PDO::FETCH_COLUMN));

    assertPortalPetBooking(
        $ownership_linked_pet_ids === [$portal_pet_ids[0]],
        'Portal bookings should ignore unowned, duplicate, and invalid pet IDs.'
    );

    echo "Public booking portal pet ID checks passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
} finally {
    $_SESSION['portal_client_id'] = $original_portal_session['portal_client_id'];
    $_SESSION['portal_client_name'] = $original_portal_session['portal_client_name'];
    $_SESSION['portal_client_email'] = $original_portal_session['portal_client_email'];

    if ($original_portal_session['portal_client_id'] === null) {
        unset($_SESSION['portal_client_id']);
    }
    if ($original_portal_session['portal_client_name'] === null) {
        unset($_SESSION['portal_client_name']);
    }
    if ($original_portal_session['portal_client_email'] === null) {
        unset($_SESSION['portal_client_email']);
    }
}
