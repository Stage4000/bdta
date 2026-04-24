#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function resetMultiAdminAvailabilityState(SafePDO $conn): void
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

function assertMultiAdminAvailability(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function createMultiAdminAvailabilityConnection(): SafePDO
{
    $conn = new SafePDO('sqlite::memory:');
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

    $conn->exec('CREATE TABLE settings (setting_key TEXT PRIMARY KEY, setting_value TEXT, setting_type TEXT)');
    $conn->exec('CREATE TABLE app_sessions (id TEXT PRIMARY KEY, data BLOB, timestamp INTEGER, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE admin_users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT, email TEXT)');
    $conn->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT NOT NULL, phone TEXT, address TEXT, notes TEXT, created_at TEXT, updated_at TEXT)');
    $conn->exec('CREATE TABLE client_contacts (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL, name TEXT, email TEXT, phone TEXT, is_primary INTEGER DEFAULT 0)');
    $conn->exec('CREATE TABLE bookings (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, appointment_type_id INTEGER, admin_user_id INTEGER, client_name TEXT, client_email TEXT NOT NULL, client_phone TEXT, service_type TEXT, appointment_date TEXT, appointment_time TEXT, notes TEXT, duration_minutes INTEGER, location TEXT, location_type TEXT, package_credit_id INTEGER, contract_accepted INTEGER, contract_accepted_at TEXT, contract_signature_name TEXT, contract_signature_font TEXT, status TEXT, google_event_id TEXT)');
    $conn->exec('CREATE TABLE appointment_types (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, is_active INTEGER DEFAULT 1, admin_user_id INTEGER, duration_minutes INTEGER DEFAULT 60, buffer_before_minutes INTEGER DEFAULT 0, buffer_after_minutes INTEGER DEFAULT 0, available_days TEXT, available_start_time TEXT, available_end_time TEXT, time_slot_interval INTEGER DEFAULT 60, schedule_type TEXT DEFAULT \'recurring\', specific_date TEXT, specific_dates TEXT, per_day_schedule TEXT, is_group_class INTEGER DEFAULT 0, max_participants INTEGER DEFAULT 1, is_mini_session INTEGER DEFAULT 0, mini_session_location TEXT, is_field_rental INTEGER DEFAULT 0, field_rental_location TEXT, group_class_location TEXT, location_types TEXT, contract_template_id INTEGER, confirmation_template_id INTEGER, booking_request_template_id INTEGER, reminder_template_id INTEGER, cancellation_template_id INTEGER, requires_admin_confirmation INTEGER DEFAULT 0, uses_resource INTEGER DEFAULT 0, resource_name TEXT, resource_capacity INTEGER DEFAULT 1, resource_allocation TEXT DEFAULT \'per_appointment\')');
    $conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, name TEXT, species TEXT, is_active INTEGER, created_at TEXT, updated_at TEXT)');
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
        'google_oauth_client_id' => '',
        'google_oauth_client_secret' => '',
    ] as $key => $value) {
        $insert_setting->execute([$key, $value, 'text']);
    }

    return $conn;
}

/**
 * @return array{admin_one_type_id: int, admin_two_type_id: int}
 */
function seedMultiAdminAvailabilityFixture(SafePDO $conn): array
{
    $conn->exec("INSERT INTO admin_users (id, username, email) VALUES (1, 'alpha', 'alpha@example.com')");
    $conn->exec("INSERT INTO admin_users (id, username, email) VALUES (2, 'beta', 'beta@example.com')");

    $type_insert = $conn->prepare("
        INSERT INTO appointment_types (
            name, is_active, admin_user_id, duration_minutes,
            available_days, available_start_time, available_end_time, time_slot_interval, schedule_type
        ) VALUES (?, 1, ?, 60, '[0,1,2,3,4,5,6]', '09:00', '12:00', 60, 'recurring')
    ");
    $type_insert->execute(['Admin One Session', 1]);
    $admin_one_type_id = (int) $conn->lastInsertId();
    $type_insert->execute(['Admin Two Session', 2]);
    $admin_two_type_id = (int) $conn->lastInsertId();

    $conn->prepare("
        INSERT INTO bookings (
            appointment_type_id, admin_user_id, client_name, client_email, service_type,
            appointment_date, appointment_time, duration_minutes, location_type, status
        ) VALUES (?, ?, ?, ?, ?, '2026-06-01', '10:00', 60, 'custom_address', 'confirmed')
    ")->execute([$admin_one_type_id, 1, 'Booked Client', 'booked@example.com', 'Admin One Session']);

    return [
        'admin_one_type_id' => $admin_one_type_id,
        'admin_two_type_id' => $admin_two_type_id,
    ];
}

/**
 * @return array{date: string, available_slots: list<string>, google_calendar_checked: bool}
 */
function runAvailabilityScenario(SafePDO $conn, int $appointment_type_id, string $date): array
{
    $appointment_type_stmt = $conn->prepare("
        SELECT available_start_time, available_end_time, time_slot_interval,
               duration_minutes, buffer_before_minutes, buffer_after_minutes, admin_user_id
        FROM appointment_types
        WHERE id = ? AND is_active = 1
    ");
    $appointment_type_stmt->execute([$appointment_type_id]);
    $appointment_type = api_booking_db_row($appointment_type_stmt->fetch(PDO::FETCH_ASSOC));
    assertMultiAdminAvailability($appointment_type !== [], 'Expected appointment type to be retrievable for availability checks.');

    $available_start_time = array_string_value($appointment_type, 'available_start_time', '09:00');
    $available_end_time = array_string_value($appointment_type, 'available_end_time', '17:00');
    $time_slot_interval = array_int_value($appointment_type, 'time_slot_interval', 30);
    $slot_duration = array_int_value($appointment_type, 'duration_minutes', 60);
    $buffer_before = max(0, array_int_value($appointment_type, 'buffer_before_minutes', 0));
    $buffer_after = max(0, array_int_value($appointment_type, 'buffer_after_minutes', 0));
    $appointment_type_admin_user_id = array_int_value($appointment_type, 'admin_user_id');

    $stmt = $conn->prepare("
        SELECT b.appointment_time, b.duration_minutes, b.appointment_type_id,
               COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before,
               COALESCE(at.buffer_after_minutes, 0) AS b_buffer_after,
               COALESCE(apc.pet_count, 0) AS pet_count,
               COALESCE(b.admin_user_id, at.admin_user_id, 0) AS schedule_admin_user_id
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        LEFT JOIN (
            SELECT booking_id, COUNT(*) AS pet_count
            FROM appointment_pets
            GROUP BY booking_id
        ) apc ON apc.booking_id = b.id
        WHERE b.appointment_date = ? AND b.status != 'cancelled'
    ");
    $stmt->execute([$date]);
    $existing_bookings = api_booking_filter_schedule_rows(
        api_booking_assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC)),
        $appointment_type_admin_user_id
    );
    $reserved_mini_session_rows = function_exists('api_booking_reserved_mini_session_rows')
        ? api_booking_reserved_mini_session_rows($conn, $date, $date, $appointment_type_id, $appointment_type_admin_user_id)
        : [];

    $start_parts = explode(':', $available_start_time);
    $end_parts = explode(':', $available_end_time);
    assertMultiAdminAvailability(count($start_parts) === 2 && count($end_parts) === 2, 'Expected fixture availability window to include start and end times.');

    $start_time_minutes = (int) $start_parts[0] * 60 + (int) $start_parts[1];
    $end_time_minutes = (int) $end_parts[0] * 60 + (int) $end_parts[1];
    $disabled_resource_config = ['enabled' => false, 'name' => '', 'capacity' => 1, 'allocation' => 'per_appointment'];

    $available_slots = [];
    for ($minutes = $start_time_minutes; $minutes < $end_time_minutes; $minutes += $time_slot_interval) {
        $time_slot = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        $slot_usage = bdta_booking_slot_usage_summary(
            $existing_bookings,
            $time_slot,
            $slot_duration,
            $buffer_before,
            $buffer_after,
            $disabled_resource_config,
            $appointment_type_id
        );
        $mini_session_reserved = function_exists('api_booking_slot_conflicts_with_rows')
            ? api_booking_slot_conflicts_with_rows($reserved_mini_session_rows, $time_slot, $slot_duration, $buffer_before, $buffer_after)
            : false;
        if (!$slot_usage['has_overlap_conflict'] && !$mini_session_reserved) {
            $available_slots[] = $time_slot;
        }
    }

    return [
        'date' => $date,
        'available_slots' => $available_slots,
        'google_calendar_checked' => false,
    ];
}

$conn = createMultiAdminAvailabilityConnection();
$fixture = seedMultiAdminAvailabilityFixture($conn);
resetMultiAdminAvailabilityState($conn);

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

$created_result = api_booking_create_booking($conn, [
    'client_name' => 'Second Admin Client',
    'client_email' => 'second-admin@example.com',
    'client_phone' => '555-2222',
    'service_type' => 'Admin Two Session',
    'appointment_type_id' => $fixture['admin_two_type_id'],
    'appointment_date' => '2026-06-02',
    'appointment_time' => '09:00',
    'location_type' => 'custom_address',
    'location_value' => '2 Trainer Way',
]);
assertMultiAdminAvailability(($created_result['success'] ?? false) === true, 'Expected assigned-admin booking creation to succeed.');

$booking_stmt = $conn->prepare('SELECT * FROM bookings WHERE id = ?');
$booking_stmt->execute([safe_int($created_result['booking_id'] ?? null)]);
$created_booking = $booking_stmt->fetch(PDO::FETCH_ASSOC);
assertMultiAdminAvailability(is_array($created_booking), 'Expected created booking row to be retrievable.');
assertMultiAdminAvailability((int) ($created_booking['admin_user_id'] ?? 0) === 2, 'Expected bookings to inherit the assigned admin from their appointment type.');
assertMultiAdminAvailability(
    GoogleCalendarIntegration::getBookingAdminUserId(is_array($created_booking) ? $created_booking : []) === 2,
    'Expected Google Calendar targeting to resolve the booking\'s assigned admin.'
);

$admin_one_payload = runAvailabilityScenario($conn, $fixture['admin_one_type_id'], '2026-06-01');
$admin_two_payload = runAvailabilityScenario($conn, $fixture['admin_two_type_id'], '2026-06-01');

$admin_one_slots = $admin_one_payload['available_slots'];
$admin_two_slots = $admin_two_payload['available_slots'];

assertMultiAdminAvailability(!in_array('10:00', $admin_one_slots, true), 'Expected an admin\'s own booking to block that admin\'s schedule.');
assertMultiAdminAvailability(in_array('10:00', $admin_two_slots, true), 'Expected one admin\'s booking to remain available for a different assigned admin.');

$mini_session_type_stmt = $conn->prepare("
    INSERT INTO appointment_types (
        name, is_active, admin_user_id, duration_minutes, available_days,
        available_start_time, available_end_time, time_slot_interval,
        schedule_type, specific_date, is_mini_session, mini_session_location
    ) VALUES (?, 1, ?, 60, '[0,1,2,3,4,5,6]', '10:00', '12:00', 60, 'specific_date', '2026-06-03', 1, ?)
");
$mini_session_type_stmt->execute(['Mini Session Event', 1, 'Dog Park']);
$mini_session_type_id = (int) $conn->lastInsertId();

$mini_blocked_admin_one_payload = runAvailabilityScenario($conn, $fixture['admin_one_type_id'], '2026-06-03');
$mini_blocked_admin_two_payload = runAvailabilityScenario($conn, $fixture['admin_two_type_id'], '2026-06-03');
$mini_session_payload = runAvailabilityScenario($conn, $mini_session_type_id, '2026-06-03');

assertMultiAdminAvailability(!in_array('10:00', $mini_blocked_admin_one_payload['available_slots'], true), 'Expected Mini Sessions windows to block other appointment types for the same assigned admin.');
assertMultiAdminAvailability(!in_array('11:00', $mini_blocked_admin_one_payload['available_slots'], true), 'Expected the entire Mini Sessions time range to stay reserved from other appointment types.');
assertMultiAdminAvailability(in_array('10:00', $mini_blocked_admin_two_payload['available_slots'], true), 'Expected Mini Sessions windows to leave other assigned admins unaffected.');
assertMultiAdminAvailability(in_array('10:00', $mini_session_payload['available_slots'], true), 'Expected the Mini Sessions appointment type to keep its own reserved slot available.');

$mini_session_conflict = api_booking_create_booking($conn, [
    'client_name' => 'Mini Conflict Client',
    'client_email' => 'mini-conflict@example.com',
    'client_phone' => '555-3333',
    'service_type' => 'Admin One Session',
    'appointment_type_id' => $fixture['admin_one_type_id'],
    'appointment_date' => '2026-06-03',
    'appointment_time' => '10:00',
    'location_type' => 'custom_address',
    'location_value' => '1 Trainer Way',
]);
assertMultiAdminAvailability(isset($mini_session_conflict['error']), 'Expected non-mini bookings in a Mini Sessions window to be rejected.');
assertMultiAdminAvailability(str_contains(scalar_string($mini_session_conflict['error'] ?? ''), 'Mini Sessions'), 'Expected the Mini Sessions conflict message to explain the reserved window.');

$mini_session_booking = api_booking_create_booking($conn, [
    'client_name' => 'Mini Session Client',
    'client_email' => 'mini-session@example.com',
    'client_phone' => '555-4444',
    'service_type' => 'Mini Session Event',
    'appointment_type_id' => $mini_session_type_id,
    'appointment_date' => '2026-06-03',
    'appointment_time' => '10:00',
]);
assertMultiAdminAvailability(($mini_session_booking['success'] ?? false) === true, 'Expected the Mini Sessions appointment type to remain bookable within its reserved window.');

$create_type_stmt = $conn->prepare('
    INSERT INTO appointment_types (name, duration_minutes, admin_user_id, is_active)
    VALUES (?, ?, ?, 1)
');
$create_type_stmt->execute(['Order Check Session', 75, 1]);
$created_type_id = (int) $conn->lastInsertId();
$type_stmt = $conn->prepare('SELECT duration_minutes, admin_user_id FROM appointment_types WHERE id = ?');
$type_stmt->execute([$created_type_id]);
$created_type = $type_stmt->fetch(PDO::FETCH_ASSOC);
assertMultiAdminAvailability(is_array($created_type), 'Expected directly created appointment type row to be retrievable.');
assertMultiAdminAvailability((int) ($created_type['duration_minutes'] ?? 0) === 75, 'Expected appointment type duration_minutes to persist correctly.');
assertMultiAdminAvailability((int) ($created_type['admin_user_id'] ?? 0) === 1, 'Expected appointment type admin_user_id to persist correctly.');

$appointment_types_edit = file_get_contents(dirname(__DIR__) . '/client/appointment_types_edit.php');
$appointment_types_list = file_get_contents(dirname(__DIR__) . '/client/appointment_types_list.php');
assertMultiAdminAvailability(is_string($appointment_types_edit) && str_contains($appointment_types_edit, 'Assigned Admin'), 'Expected appointment type edit screen to expose assigned admin selection.');
assertMultiAdminAvailability(is_string($appointment_types_list) && str_contains($appointment_types_list, 'Assigned Admin'), 'Expected appointment type list screen to display assigned admin information.');

echo "Multi-admin booking availability tests passed.\n";
