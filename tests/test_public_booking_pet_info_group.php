#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function assertPublicBookingPetInfoGroup(bool $condition, string $message): void
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
$conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL, phone TEXT, address TEXT, notes TEXT, created_at TEXT, updated_at TEXT)');
$conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, admin_user_id INTEGER, client_name TEXT, client_email TEXT NOT NULL, client_phone TEXT, service_type TEXT, appointment_date TEXT, appointment_time TEXT, notes TEXT, duration_minutes INTEGER, location TEXT, location_type TEXT, package_credit_id INTEGER, contract_accepted INTEGER, contract_accepted_at TEXT, contract_signature_name TEXT, contract_signature_font TEXT, status TEXT, google_event_id TEXT)');
$conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, is_active INTEGER DEFAULT 1, admin_user_id INTEGER, duration_minutes INTEGER DEFAULT 60, buffer_before_minutes INTEGER DEFAULT 0, buffer_after_minutes INTEGER DEFAULT 0, requires_admin_confirmation INTEGER DEFAULT 0, confirmation_template_id INTEGER, booking_request_template_id INTEGER, reminder_template_id INTEGER, cancellation_template_id INTEGER, is_mini_session INTEGER DEFAULT 0, mini_session_location TEXT, is_field_rental INTEGER DEFAULT 0, field_rental_location TEXT, is_group_class INTEGER DEFAULT 0, group_class_location TEXT, location_types TEXT, contract_template_id INTEGER, uses_resource INTEGER DEFAULT 0, resource_name TEXT, resource_capacity INTEGER DEFAULT 1, resource_allocation TEXT DEFAULT \'per_appointment\')');
$conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, name TEXT, species TEXT, breed TEXT, date_of_birth TEXT, age_years INTEGER, age_months INTEGER, source TEXT, ownership_length_years INTEGER, ownership_length_months INTEGER, spayed_neutered INTEGER DEFAULT 0, vaccines_current INTEGER DEFAULT 0, vaccine_notes TEXT, behavior_notes TEXT, medical_notes TEXT, training_notes TEXT, pet_sitting_notes TEXT, is_active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT)');
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

$database_reflection = new ReflectionClass(Database::class);
$shared_connection = $database_reflection->getProperty('sharedConnection');
$shared_connection->setAccessible(true);
$shared_connection->setValue(null, $conn);

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

try {
    $suffix = bin2hex(random_bytes(4));

    $conn->prepare('INSERT INTO appointment_types (name, is_active) VALUES (?, 1)')
        ->execute(['Pet Info Group Type ' . $suffix]);
    $appointment_type_id = (int) $conn->lastInsertId();

    $conn->prepare('INSERT INTO form_templates (fields, form_type, is_active) VALUES (?, ?, 1)')
        ->execute([json_encode([
            [
                'label' => 'How many pets should we prepare for?',
                'type' => 'pet_info_group',
                'pet_info_group_include_species' => 1,
                'pet_info_group_species_dog_only' => 1,
                'pet_info_group_default_species' => 'Dog',
            ],
        ]), 'client_form']);
    $form_template_id = (int) $conn->lastInsertId();

    $result = api_booking_create_booking($conn, [
        'client_name' => 'Pet Group Owner ' . $suffix,
        'client_email' => 'pet-group-' . $suffix . '@example.com',
        'client_phone' => '555-0100',
        'service_type' => 'Pet Info Group Booking',
        'appointment_type_id' => $appointment_type_id,
        'appointment_date' => '2026-07-12',
        'appointment_time' => '09:00',
        'location_type' => 'custom_address',
        'location_value' => '123 Pet Group Lane',
        'form_responses' => [
            $form_template_id => [
                0 => [
                    [
                        'name' => 'Nova',
                        'age_or_dob' => '2 years 6 months',
                        'breed' => 'Black merle mix',
                        'vaccines_current' => 'yes',
                        'spayed_neutered' => 'yes',
                        'source' => 'Rescue',
                        'ownership_length' => '1 year 3 months',
                    ],
                    [
                        'name' => 'Milo',
                        'age_or_dob' => '2021-05-04',
                        'breed' => 'Orange tabby',
                        'vaccines_current' => 'no',
                        'spayed_neutered' => 'no',
                        'source' => 'Friend',
                        'ownership_length' => '6 months',
                    ],
                ],
            ],
        ],
    ]);

    assertPublicBookingPetInfoGroup(($result['success'] ?? false) === true, 'Expected pet info group bookings to succeed.');
    $booking_id = safe_int($result['booking_id'] ?? 0);
    assertPublicBookingPetInfoGroup($booking_id > 0, 'Expected the booking API to return a booking ID.');

    $pet_link_stmt = $conn->prepare('SELECT pet_id FROM appointment_pets WHERE booking_id = ? ORDER BY id ASC');
    $pet_link_stmt->execute([$booking_id]);
    $booking_pet_ids = array_map('safe_int', $pet_link_stmt->fetchAll(PDO::FETCH_COLUMN));
    assertPublicBookingPetInfoGroup(count($booking_pet_ids) === 2, 'Expected the booking to link two newly-created pet profiles.');

    $pet_stmt = $conn->prepare('SELECT name, species, breed, date_of_birth, age_years, age_months, source, ownership_length_years, ownership_length_months, spayed_neutered, vaccines_current FROM pets WHERE id = ?');
    $pet_stmt->execute([$booking_pet_ids[0]]);
    $first_pet = assoc_row($pet_stmt->fetch(PDO::FETCH_ASSOC));
    $pet_stmt->execute([$booking_pet_ids[1]]);
    $second_pet = assoc_row($pet_stmt->fetch(PDO::FETCH_ASSOC));

    assertPublicBookingPetInfoGroup(array_string_value($first_pet, 'name') === 'Nova', 'Expected the first pet profile to preserve the submitted pet name.');
    assertPublicBookingPetInfoGroup(array_string_value($first_pet, 'species') === 'Dog', 'Expected dog-only pet groups to default the species to Dog.');
    assertPublicBookingPetInfoGroup(array_int_value($first_pet, 'age_years') === 2, 'Expected age text to map to age_years.');
    assertPublicBookingPetInfoGroup(array_int_value($first_pet, 'age_months') === 6, 'Expected age text to map to age_months.');
    assertPublicBookingPetInfoGroup(array_int_value($first_pet, 'ownership_length_years') === 1, 'Expected ownership text to map to ownership_length_years.');
    assertPublicBookingPetInfoGroup(array_int_value($first_pet, 'ownership_length_months') === 3, 'Expected ownership text to map to ownership_length_months.');
    assertPublicBookingPetInfoGroup(array_int_value($first_pet, 'vaccines_current') === 1, 'Expected vaccine status to map to the pet profile boolean.');
    assertPublicBookingPetInfoGroup(array_int_value($first_pet, 'spayed_neutered') === 1, 'Expected spay/neuter status to map to the pet profile boolean.');

    assertPublicBookingPetInfoGroup(array_string_value($second_pet, 'name') === 'Milo', 'Expected the second pet profile to preserve the submitted pet name.');
    assertPublicBookingPetInfoGroup(array_string_value($second_pet, 'date_of_birth') === '2021-05-04', 'Expected DOB text to map to date_of_birth.');
    assertPublicBookingPetInfoGroup(array_int_value($second_pet, 'ownership_length_years') === 0, 'Expected month-only ownership values not to fabricate ownership years.');
    assertPublicBookingPetInfoGroup(array_int_value($second_pet, 'ownership_length_months') === 6, 'Expected month-only ownership values to map to ownership_length_months.');
    assertPublicBookingPetInfoGroup(array_int_value($second_pet, 'vaccines_current') === 0, 'Expected negative vaccine status to map to 0.');

    $conn->prepare('INSERT INTO appointment_types (name, is_active) VALUES (?, 1)')
        ->execute(['Pet Info Group Optional Species ' . $suffix]);
    $optional_species_type_id = (int) $conn->lastInsertId();

    $conn->prepare('INSERT INTO form_templates (fields, form_type, is_active) VALUES (?, ?, 1)')
        ->execute([json_encode([
            [
                'label' => 'Tell us about your pets',
                'type' => 'pet_info_group',
            ],
        ]), 'client_form']);
    $optional_species_template_id = (int) $conn->lastInsertId();

    $optional_species_result = api_booking_create_booking($conn, [
        'client_name' => 'No Species Owner ' . $suffix,
        'client_email' => 'pet-group-no-species-' . $suffix . '@example.com',
        'client_phone' => '555-0101',
        'service_type' => 'Pet Info Group No Species Booking',
        'appointment_type_id' => $optional_species_type_id,
        'appointment_date' => '2026-07-13',
        'appointment_time' => '11:00',
        'location_type' => 'custom_address',
        'location_value' => '456 Optional Species Lane',
        'form_responses' => [
            $optional_species_template_id => [
                0 => [
                    [
                        'name' => 'Pixel',
                        'age_or_dob' => '1 year',
                        'breed' => 'Mixed breed',
                        'vaccines_current' => 'yes',
                        'spayed_neutered' => 'yes',
                        'source' => 'Breeder',
                        'ownership_length' => '8 months',
                    ],
                ],
            ],
        ],
    ]);

    assertPublicBookingPetInfoGroup(($optional_species_result['success'] ?? false) === true, 'Expected pet info group bookings without species collection to succeed.');
    $optional_booking_id = safe_int($optional_species_result['booking_id'] ?? 0);
    assertPublicBookingPetInfoGroup($optional_booking_id > 0, 'Expected the second booking API call to return a booking ID.');

    $pet_link_stmt->execute([$optional_booking_id]);
    $optional_booking_pet_ids = array_map('safe_int', $pet_link_stmt->fetchAll(PDO::FETCH_COLUMN));
    assertPublicBookingPetInfoGroup(count($optional_booking_pet_ids) === 1, 'Expected the booking without species collection to create one pet profile.');

    $pet_stmt->execute([$optional_booking_pet_ids[0]]);
    $optional_species_pet = assoc_row($pet_stmt->fetch(PDO::FETCH_ASSOC));
    assertPublicBookingPetInfoGroup(array_string_value($optional_species_pet, 'name') === 'Pixel', 'Expected the optional-species booking to preserve the submitted pet name.');
    assertPublicBookingPetInfoGroup(array_string_value($optional_species_pet, 'species') === '', 'Expected optional-species bookings not to force a Dog species.');

    echo "Public booking pet info group test passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
