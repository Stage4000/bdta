#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetRegisteredAddressBookingState(SafePDO $conn): void
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

function assertRegisteredAddressBooking(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$can_run_runtime_api_checks = in_array('sqlite', PDO::getAvailableDrivers(), true);

if ($can_run_runtime_api_checks) {
    $conn = new SafePDO('sqlite::memory:');
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

    $conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
    $conn->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT)');
    $conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT, address TEXT, notes TEXT, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT, email TEXT, phone TEXT, is_primary INTEGER DEFAULT 0)');
    $conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, admin_user_id INTEGER, client_name TEXT, client_email TEXT NOT NULL, client_phone TEXT, service_type TEXT, appointment_date TEXT, appointment_time TEXT, notes TEXT, duration_minutes INTEGER, location TEXT, location_type TEXT, package_credit_id INTEGER, contract_accepted INTEGER, contract_accepted_at TEXT, contract_signature_name TEXT, contract_signature_font TEXT, status TEXT, google_event_id TEXT)');
    $conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, is_active INTEGER DEFAULT 1, admin_user_id INTEGER, duration_minutes INTEGER DEFAULT 60, buffer_before_minutes INTEGER DEFAULT 0, buffer_after_minutes INTEGER DEFAULT 0, requires_admin_confirmation INTEGER DEFAULT 0, confirmation_template_id INTEGER, booking_request_template_id INTEGER, reminder_template_id INTEGER, cancellation_template_id INTEGER, is_mini_session INTEGER DEFAULT 0, mini_session_location TEXT, is_field_rental INTEGER DEFAULT 0, field_rental_location TEXT, is_group_class INTEGER DEFAULT 0, group_class_location TEXT, location_types TEXT, contract_template_id INTEGER, uses_resource INTEGER DEFAULT 0, resource_name TEXT, resource_capacity INTEGER DEFAULT 1, resource_allocation TEXT DEFAULT \'per_appointment\')');
    $conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, name TEXT, species TEXT, breed TEXT, date_of_birth TEXT, source TEXT, spayed_neutered INTEGER DEFAULT 0, vaccines_current INTEGER DEFAULT 0, vaccine_notes TEXT, behavior_notes TEXT, medical_notes TEXT, training_notes TEXT, pet_sitting_notes TEXT, is_active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE appointment_pets (id INTEGER PRIMARY KEY AUTOINCREMENT, booking_id INTEGER, pet_id INTEGER, created_at TEXT)');
    $conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, fields TEXT, form_type TEXT, is_active INTEGER)');
    $conn->exec('CREATE TABLE form_submissions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, template_id INTEGER, booking_id INTEGER, responses TEXT, status TEXT, submitted_at TEXT)');
    $conn->exec('CREATE TABLE workflow_triggers (id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_id INTEGER, trigger_type TEXT, appointment_type_id INTEGER, form_template_id INTEGER, is_active INTEGER)');
    $conn->exec('CREATE TABLE client_package_credits (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, client_package_id INTEGER, total_credits INTEGER, used_credits INTEGER)');
    $conn->exec('CREATE TABLE client_packages (id INTEGER PRIMARY KEY AUTOINCREMENT, is_active INTEGER, expires_at TEXT)');
    $conn->exec('CREATE TABLE package_credit_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, client_package_credit_id INTEGER, client_id INTEGER, appointment_type_id INTEGER, transaction_type TEXT, amount INTEGER, booking_id INTEGER, notes TEXT, created_by INTEGER)');
    $conn->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, audience TEXT, recipient_id INTEGER, entity_type TEXT, entity_id INTEGER, title TEXT, message TEXT, url TEXT, is_read INTEGER DEFAULT 0, read_at TEXT, deleted_at TEXT, created_at TEXT)');
    $conn->exec('CREATE TABLE email_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, template_type TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT NOT NULL, body_text TEXT, variables TEXT, is_active INTEGER DEFAULT 1)');
    $conn->exec('CREATE TABLE client_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, direction TEXT NOT NULL, status TEXT NOT NULL, message_id TEXT, from_email TEXT NOT NULL, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, template_id INTEGER, mail_type TEXT, scheduled_at TEXT, sent_at TEXT, delivered_at TEXT, failed_at TEXT, error_message TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE unmatched_emails (id INTEGER PRIMARY KEY AUTOINCREMENT, message_id TEXT, from_email TEXT NOT NULL, from_name TEXT, to_email TEXT NOT NULL, subject TEXT NOT NULL, body_html TEXT, body_text TEXT, received_at TEXT, direction TEXT DEFAULT \'incoming\', is_assigned INTEGER DEFAULT 0, assigned_to_client_id INTEGER, assigned_at TEXT, assigned_by INTEGER, is_archived INTEGER DEFAULT 0, archived_at TEXT, created_at TEXT)');
    $conn->exec('CREATE TABLE client_activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, action TEXT, description TEXT, ip_address TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');

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

    $conn->prepare('INSERT INTO appointment_types (name, is_active, location_types) VALUES (?, 1, ?)')
        ->execute(['Registered Address Visit', json_encode(['client_address'])]);
    $appointment_type_id = (int) $conn->lastInsertId();

    resetRegisteredAddressBookingState($conn);

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
}

try {
    if ($can_run_runtime_api_checks) {
        $new_client_result = api_booking_create_booking($conn, [
            'client_name' => 'New Address Client',
            'client_email' => 'new-address@example.com',
            'client_phone' => '555-1000',
            'service_type' => 'Registered Address Visit',
            'appointment_type_id' => $appointment_type_id,
            'appointment_date' => '2026-06-01',
            'appointment_time' => '09:00',
            'location_type' => 'client_address',
            'client_address' => '101 New Client Way',
        ]);

        assertRegisteredAddressBooking(($new_client_result['success'] ?? false) === true, 'New clients should be able to book a registered-address appointment when they provide an address.');

        $client_stmt = $conn->prepare('SELECT id, address FROM clients WHERE email = ?');
        $client_stmt->execute(['new-address@example.com']);
        $new_client = assoc_row($client_stmt->fetch(PDO::FETCH_ASSOC));
        assertRegisteredAddressBooking(array_string_value($new_client, 'address') === '101 New Client Way', 'New client address should be saved onto the client profile.');

        $booking_stmt = $conn->prepare('SELECT location FROM bookings WHERE client_email = ? ORDER BY id DESC LIMIT 1');
        $booking_stmt->execute(['new-address@example.com']);
        assertRegisteredAddressBooking(scalar_string($booking_stmt->fetchColumn()) === '101 New Client Way', 'New client booking location should use the submitted registered address.');

        $conn->prepare('INSERT INTO clients (name, email, phone, address, created_at, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
            ->execute(['Stored Address Client', 'stored-address@example.com', '555-2000', '12 Existing Street']);

        $overwrite_result = api_booking_create_booking($conn, [
            'client_name' => 'Stored Address Client',
            'client_email' => 'stored-address@example.com',
            'client_phone' => '555-2000',
            'service_type' => 'Registered Address Visit',
            'appointment_type_id' => $appointment_type_id,
            'appointment_date' => '2026-06-02',
            'appointment_time' => '10:00',
            'location_type' => 'client_address',
            'client_address' => '34 Updated Road',
            'overwrite_profile' => true,
        ]);

        assertRegisteredAddressBooking(($overwrite_result['success'] ?? false) === true, 'Existing clients should be able to confirm an updated registered address.');
        $client_stmt->execute(['stored-address@example.com']);
        $updated_client = assoc_row($client_stmt->fetch(PDO::FETCH_ASSOC));
        assertRegisteredAddressBooking(array_string_value($updated_client, 'address') === '34 Updated Road', 'Confirmed registered-address edits should update the client profile.');
        $booking_stmt->execute(['stored-address@example.com']);
        assertRegisteredAddressBooking(scalar_string($booking_stmt->fetchColumn()) === '34 Updated Road', 'Confirmed registered-address edits should be used for the booking location.');

        $conn->prepare('INSERT INTO clients (name, email, phone, address, created_at, updated_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
            ->execute(['Keep Existing Client', 'keep-existing@example.com', '555-3000', '56 Keep Lane']);

        $keep_existing_result = api_booking_create_booking($conn, [
            'client_name' => 'Keep Existing Client',
            'client_email' => 'keep-existing@example.com',
            'client_phone' => '555-3000',
            'service_type' => 'Registered Address Visit',
            'appointment_type_id' => $appointment_type_id,
            'appointment_date' => '2026-06-03',
            'appointment_time' => '11:00',
            'location_type' => 'client_address',
            'client_address' => '78 Ignored Avenue',
            'overwrite_profile' => false,
        ]);

        assertRegisteredAddressBooking(($keep_existing_result['success'] ?? false) === true, 'Existing clients should still be able to keep the saved address when booking.');
        $client_stmt->execute(['keep-existing@example.com']);
        $kept_client = assoc_row($client_stmt->fetch(PDO::FETCH_ASSOC));
        assertRegisteredAddressBooking(array_string_value($kept_client, 'address') === '56 Keep Lane', 'Declining an address change should preserve the saved client address.');
        $booking_stmt->execute(['keep-existing@example.com']);
        assertRegisteredAddressBooking(scalar_string($booking_stmt->fetchColumn()) === '56 Keep Lane', 'Declining an address change should keep using the saved client address for the booking.');

        $conn->prepare('INSERT INTO clients (name, email, phone, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
            ->execute(['Missing Address Client', 'missing-address@example.com', '555-4000']);

        $missing_address_result = api_booking_create_booking($conn, [
            'client_name' => 'Missing Address Client',
            'client_email' => 'missing-address@example.com',
            'client_phone' => '555-4000',
            'service_type' => 'Registered Address Visit',
            'appointment_type_id' => $appointment_type_id,
            'appointment_date' => '2026-06-04',
            'appointment_time' => '12:00',
            'location_type' => 'client_address',
            'client_address' => '90 Captured Circle',
        ]);

        assertRegisteredAddressBooking(($missing_address_result['success'] ?? false) === true, 'Existing clients without an address should be able to provide one during booking.');
        $client_stmt->execute(['missing-address@example.com']);
        $missing_address_client = assoc_row($client_stmt->fetch(PDO::FETCH_ASSOC));
        assertRegisteredAddressBooking(array_string_value($missing_address_client, 'address') === '90 Captured Circle', 'Provided registered addresses should be saved for existing clients who were missing one.');
    }

    $public_book_source = scalar_string(file_get_contents(dirname(__DIR__) . '/backend/public/book.php'));
    assertRegisteredAddressBooking(strpos($public_book_source, 'publicRegisteredAddressInput') !== false, 'Public booking page should render a dedicated registered-address field.');
    assertRegisteredAddressBooking(strpos($public_book_source, 'publicEditRegisteredAddressBtn') !== false, 'Public booking confirmation should offer a direct way to edit the registered address.');
    assertRegisteredAddressBooking(strpos($public_book_source, 'syncPublicRegisteredAddressUI') !== false, 'Public booking page should contain dedicated registered-address UI state handling.');

    $portal_book_source = scalar_string(file_get_contents(dirname(__DIR__) . '/portal/book_credit.php'));
    assertRegisteredAddressBooking(strpos($portal_book_source, 'registeredAddressInput') !== false, 'Portal credit booking page should render a dedicated registered-address field.');
    assertRegisteredAddressBooking(strpos($portal_book_source, 'editRegisteredAddressBtn') !== false, 'Portal credit booking confirmation should offer a direct way to edit the registered address.');
    assertRegisteredAddressBooking(strpos($portal_book_source, 'syncRegisteredAddressUI') !== false, 'Portal credit booking page should contain dedicated registered-address UI state handling.');

    $portal_api_source = scalar_string(file_get_contents(dirname(__DIR__) . '/portal/api_book_credit.php'));
    assertRegisteredAddressBooking(strpos($portal_api_source, '$submitted_client_address = trim(scalar_string($data[\'client_address\'] ?? \'\'));') !== false, 'Portal credit booking API should accept a submitted registered address from the booking flow.');
    assertRegisteredAddressBooking(strpos($portal_api_source, '$overwrite_profile = filter_var($data[\'overwrite_profile\'] ?? false, FILTER_VALIDATE_BOOLEAN);') !== false, 'Portal credit booking API should interpret the overwrite-profile flag for registered-address updates.');

    echo "Registered address booking regression test passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
