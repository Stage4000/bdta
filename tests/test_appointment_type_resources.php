#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetAppointmentTypeResourceState(SafePDO $conn): void {
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

function assertAppointmentTypeResource(bool $condition, string $message): void {
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

resetAppointmentTypeResourceState($conn);

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

$type_insert = $conn->prepare('INSERT INTO appointment_types (name, is_active, duration_minutes, uses_resource, resource_name, resource_capacity, resource_allocation) VALUES (?, 1, 60, 1, ?, ?, ?)');
$type_insert->execute(['Boarding', 'Kennel space', 2, 'per_pet']);
$boarding_type_id = (int) $conn->lastInsertId();

$first_boarding = api_booking_create_booking($conn, [
    'client_name' => 'Boarding Client One',
    'client_email' => 'boarding-one@example.com',
    'client_phone' => '555-1000',
    'service_type' => 'Boarding',
    'appointment_type_id' => $boarding_type_id,
    'appointment_date' => '2026-06-01',
    'appointment_time' => '09:00',
    'location_type' => 'custom_address',
    'location_value' => '123 Kennel Way',
    'dog_names' => 'Alpha, Bravo',
]);
assertAppointmentTypeResource(($first_boarding['success'] ?? false) === true, 'Expected first per-pet resource booking to succeed.');

$second_boarding = api_booking_create_booking($conn, [
    'client_name' => 'Boarding Client Two',
    'client_email' => 'boarding-two@example.com',
    'client_phone' => '555-2000',
    'service_type' => 'Boarding',
    'appointment_type_id' => $boarding_type_id,
    'appointment_date' => '2026-06-01',
    'appointment_time' => '09:00',
    'location_type' => 'custom_address',
    'location_value' => '123 Kennel Way',
    'dog_names' => 'Charlie',
]);
assertAppointmentTypeResource(isset($second_boarding['error']), 'Expected over-capacity per-pet resource booking to fail.');
assertAppointmentTypeResource(str_contains(scalar_string($second_boarding['error'] ?? ''), 'Kennel space'), 'Expected per-pet resource error to mention the configured resource name.');

$type_insert->execute(['Daycare Suite', 'Suite', 2, 'per_appointment']);
$suite_type_id = (int) $conn->lastInsertId();

$suite_one = api_booking_create_booking($conn, [
    'client_name' => 'Suite Client One',
    'client_email' => 'suite-one@example.com',
    'client_phone' => '555-3000',
    'service_type' => 'Daycare Suite',
    'appointment_type_id' => $suite_type_id,
    'appointment_date' => '2026-06-02',
    'appointment_time' => '10:00',
    'location_type' => 'custom_address',
    'location_value' => '500 Suite St',
]);
$suite_two = api_booking_create_booking($conn, [
    'client_name' => 'Suite Client Two',
    'client_email' => 'suite-two@example.com',
    'client_phone' => '555-4000',
    'service_type' => 'Daycare Suite',
    'appointment_type_id' => $suite_type_id,
    'appointment_date' => '2026-06-02',
    'appointment_time' => '10:00',
    'location_type' => 'custom_address',
    'location_value' => '500 Suite St',
]);
$suite_three = api_booking_create_booking($conn, [
    'client_name' => 'Suite Client Three',
    'client_email' => 'suite-three@example.com',
    'client_phone' => '555-5000',
    'service_type' => 'Daycare Suite',
    'appointment_type_id' => $suite_type_id,
    'appointment_date' => '2026-06-02',
    'appointment_time' => '10:00',
    'location_type' => 'custom_address',
    'location_value' => '500 Suite St',
]);
assertAppointmentTypeResource(($suite_one['success'] ?? false) === true, 'Expected first per-appointment resource booking to succeed.');
assertAppointmentTypeResource(($suite_two['success'] ?? false) === true, 'Expected second per-appointment resource booking to succeed until capacity is reached.');
assertAppointmentTypeResource(isset($suite_three['error']), 'Expected third per-appointment resource booking to fail after capacity is reached.');

$appointment_types_edit = file_get_contents(dirname(__DIR__) . '/client/appointment_types_edit.php');
$appointment_types_list = file_get_contents(dirname(__DIR__) . '/client/appointment_types_list.php');
assertAppointmentTypeResource(is_string($appointment_types_edit) && str_contains($appointment_types_edit, 'Shared Resource Usage'), 'Expected appointment type edit screen to expose shared resource settings.');
assertAppointmentTypeResource(is_string($appointment_types_list) && str_contains($appointment_types_list, 'Allocated per pet'), 'Expected appointment type list screen to expose resource allocation details.');

echo "Appointment type resource support tests passed.\n";
