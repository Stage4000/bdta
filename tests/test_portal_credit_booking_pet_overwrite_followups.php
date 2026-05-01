#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetPortalPetOverwriteFollowupState(SafePDO $conn): void
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

function assertPortalPetOverwriteFollowup(bool $condition, string $message): void
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

resetPortalPetOverwriteFollowupState($conn);

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
        ->execute(['Portal Overwrite Followup ' . $suffix]);
    $appointment_type_id = (int) $conn->lastInsertId();

    $conn->prepare('INSERT INTO clients (name, email, phone, created_at, updated_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')
        ->execute(['Portal Followup Client ' . $suffix, 'portal-followup-' . $suffix . '@example.com', '555-0100']);
    $client_id = (int) $conn->lastInsertId();

    $pet_stmt = $conn->prepare("
        INSERT INTO pets (client_id, name, species, breed, is_active, created_at, updated_at)
        VALUES (?, ?, 'Dog', ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $pet_stmt->execute([$client_id, 'Alpha', 'Labrador']);
    $alpha_pet_id = (int) $conn->lastInsertId();
    $pet_stmt->execute([$client_id, 'Beta', 'Beagle']);
    $beta_pet_id = (int) $conn->lastInsertId();

    $conn->prepare('INSERT INTO form_templates (fields, form_type, is_active) VALUES (?, ?, 1)')
        ->execute([json_encode([
            ['label' => 'Pet 1 Breed', 'type' => 'text', 'profile_mapping' => 'pet_1.breed'],
            ['label' => 'Pet 2 Breed', 'type' => 'text', 'profile_mapping' => 'pet_2.breed'],
        ]), 'client_form']);
    $form_template_id = (int) $conn->lastInsertId();

    $_SESSION['portal_client_id'] = $client_id;
    $_SESSION['portal_client_name'] = 'Portal Followup Client ' . $suffix;
    $_SESSION['portal_client_email'] = 'portal-followup-' . $suffix . '@example.com';

    $declined_result = api_booking_create_booking($conn, [
        'client_name' => 'Portal Followup Client ' . $suffix,
        'client_email' => 'portal-followup-' . $suffix . '@example.com',
        'client_phone' => '555-0100',
        'service_type' => 'Portal Followup Decline',
        'appointment_type_id' => $appointment_type_id,
        'appointment_date' => '2026-06-20',
        'appointment_time' => '09:00',
        'location_type' => 'custom_address',
        'location_value' => '123 Portal Lane',
        'pet_ids' => [$beta_pet_id, $alpha_pet_id],
        'overwrite_profile' => false,
        'form_responses' => [
            $form_template_id => [
                0 => 'Portal Beta Clone',
                1 => 'Portal Alpha Clone',
            ],
        ],
    ]);

    assertPortalPetOverwriteFollowup(($declined_result['success'] ?? false) === true, 'Declining overwrite should still create the booking.');
    $declined_booking_id = safe_int($declined_result['booking_id'] ?? 0);
    assertPortalPetOverwriteFollowup($declined_booking_id > 0, 'Declined overwrite booking should return a booking ID.');

    $linked_pet_stmt = $conn->prepare('SELECT pet_id FROM appointment_pets WHERE booking_id = ? ORDER BY id ASC');
    $linked_pet_stmt->execute([$declined_booking_id]);
    $declined_booking_pet_ids = array_map('safe_int', $linked_pet_stmt->fetchAll(PDO::FETCH_COLUMN));
    assertPortalPetOverwriteFollowup(count($declined_booking_pet_ids) === 2, 'Declined overwrite booking should link two pets.');
    assertPortalPetOverwriteFollowup($declined_booking_pet_ids[0] !== $beta_pet_id, 'First selected portal pet should be cloned on decline.');
    assertPortalPetOverwriteFollowup($declined_booking_pet_ids[1] !== $alpha_pet_id, 'Second selected portal pet should be cloned on decline.');

    $breed_stmt = $conn->prepare('SELECT name, breed FROM pets WHERE id = ?');
    $breed_stmt->execute([$declined_booking_pet_ids[0]]);
    $first_clone = assoc_row($breed_stmt->fetch(PDO::FETCH_ASSOC));
    $breed_stmt->execute([$declined_booking_pet_ids[1]]);
    $second_clone = assoc_row($breed_stmt->fetch(PDO::FETCH_ASSOC));
    assertPortalPetOverwriteFollowup(array_string_value($first_clone, 'name') === 'Beta', 'The first cloned booking pet should preserve the first requested pet name.');
    assertPortalPetOverwriteFollowup(array_string_value($first_clone, 'breed') === 'Portal Beta Clone', 'The first cloned booking pet should use the first mapped breed.');
    assertPortalPetOverwriteFollowup(array_string_value($second_clone, 'name') === 'Alpha', 'The second cloned booking pet should preserve the second requested pet name.');
    assertPortalPetOverwriteFollowup(array_string_value($second_clone, 'breed') === 'Portal Alpha Clone', 'The second cloned booking pet should use the second mapped breed.');

    $breed_stmt->execute([$alpha_pet_id]);
    $alpha_original = assoc_row($breed_stmt->fetch(PDO::FETCH_ASSOC));
    $breed_stmt->execute([$beta_pet_id]);
    $beta_original = assoc_row($breed_stmt->fetch(PDO::FETCH_ASSOC));
    assertPortalPetOverwriteFollowup(array_string_value($alpha_original, 'breed') === 'Labrador', 'Original Alpha pet should remain unchanged after decline.');
    assertPortalPetOverwriteFollowup(array_string_value($beta_original, 'breed') === 'Beagle', 'Original Beta pet should remain unchanged after decline.');

    $confirmed_result = api_booking_create_booking($conn, [
        'client_name' => 'Portal Followup Client ' . $suffix,
        'client_email' => 'portal-followup-' . $suffix . '@example.com',
        'client_phone' => '555-0100',
        'service_type' => 'Portal Followup Confirm',
        'appointment_type_id' => $appointment_type_id,
        'appointment_date' => '2026-06-21',
        'appointment_time' => '10:00',
        'location_type' => 'custom_address',
        'location_value' => '123 Portal Lane',
        'pet_ids' => [$beta_pet_id, $alpha_pet_id],
        'overwrite_profile' => true,
        'form_responses' => [
            $form_template_id => [
                0 => 'Portal Beta Confirmed',
                1 => 'Portal Alpha Confirmed',
            ],
        ],
    ]);

    assertPortalPetOverwriteFollowup(($confirmed_result['success'] ?? false) === true, 'Confirming overwrite should still create the booking.');
    $breed_stmt->execute([$alpha_pet_id]);
    $alpha_updated = assoc_row($breed_stmt->fetch(PDO::FETCH_ASSOC));
    $breed_stmt->execute([$beta_pet_id]);
    $beta_updated = assoc_row($breed_stmt->fetch(PDO::FETCH_ASSOC));
    assertPortalPetOverwriteFollowup(array_string_value($beta_updated, 'breed') === 'Portal Beta Confirmed', 'First requested original pet should be updated when overwrite is confirmed.');
    assertPortalPetOverwriteFollowup(array_string_value($alpha_updated, 'breed') === 'Portal Alpha Confirmed', 'Second requested original pet should be updated when overwrite is confirmed.');

    assertPortalPetOverwriteFollowup(api_booking_parse_pet_profile_date('02/31/2026') === null, 'Invalid mapped pet DOB values should be rejected.');

    $portal_api = scalar_string(file_get_contents(dirname(__DIR__) . '/portal/api_book_credit.php'));
    assertPortalPetOverwriteFollowup(strpos($portal_api, '$booking_pet_ids = $pet_ids;') !== false, 'Portal mapping should use the validated and cloned booking pet IDs.');
    assertPortalPetOverwriteFollowup(strpos($portal_api, 'api_booking_order_verified_pet_ids($requested_pet_ids, $verified_pet_ids)') !== false, 'Portal pet verification should preserve the original request order.');
    assertPortalPetOverwriteFollowup(strpos($portal_api, 'api_booking_validate_pet_info_group_responses($conn, $data[\'form_responses\'])') !== false, 'Portal bookings should validate Pet Info Group responses before mapping them into pet updates.');
    assertPortalPetOverwriteFollowup(strpos($portal_api, 'api_booking_merge_pet_ids_with_profile_updates($conn, $client_id, $pet_ids, $pet_updates)') !== false, 'Portal bookings should merge new Pet Info Group pets into the selected pet ID list before linking the booking.');
    assertPortalPetOverwriteFollowup(strpos($portal_api, 'if (!array_key_exists($pet_index, $pet_ids) || safe_int($pet_ids[$pet_index]) <= 0)') !== false, 'Portal bookings should detect Pet Info Group indices that still need pet records created.');
    assertPortalPetOverwriteFollowup(strpos($portal_api, 'foreach ($pet_updates as $pet_index => $pet_profile)') !== false, 'Portal pet creation should preserve the original mapped pet indices.');
    assertPortalPetOverwriteFollowup(strpos($portal_api, '$created_pet_ids[$pet_index] = $existing_pet_id;') !== false, 'Portal pet creation should reuse existing pet IDs without mutating profiles before overwrite handling.');
    assertPortalPetOverwriteFollowup(strpos($portal_api, 'DateTime::getLastErrors()') !== false, 'Portal date parsing should reject values that produce warnings or errors.');

    echo "Portal credit booking pet overwrite follow-up test passed.\n";
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
