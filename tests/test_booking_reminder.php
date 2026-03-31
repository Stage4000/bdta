#!/usr/bin/env php
<?php
/**
 * Verify booking reminders use portable appointment datetime SQL in both rule and legacy modes.
 */

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/config.php';
require_once dirname(__DIR__) . '/backend/includes/settings.php';
require_once dirname(__DIR__) . '/backend/cron/tasks/booking_reminder.php';

const RULE_BOOKING_OFFSET_HOURS = 2;
const LEGACY_BOOKING_OFFSET_HOURS = 25;

function assertBookingReminderTest(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new Database();
$conn = $db->getConnection();
$unique_suffix = uniqid('booking_reminder_', false);

$cleanup = [
    'client_id' => null,
    'appointment_type_id' => null,
    'rule_id' => null,
    'booking_ids' => [],
];

/** @var array<string, scalar|null> $original_settings */
$original_settings = [
    'email_service' => Settings::get('email_service', 'mail'),
    'smtp_host' => Settings::get('smtp_host', ''),
    'smtp_port' => Settings::get('smtp_port', 587),
    'smtp_encryption' => Settings::get('smtp_encryption', 'tls'),
    'smtp_username' => Settings::get('smtp_username', ''),
    'smtp_password' => Settings::get('smtp_password', ''),
];

$exit_code = 0;

try {
    Settings::set('email_service', 'smtp');
    Settings::set('smtp_host', '');
    Settings::set('smtp_port', '587');
    Settings::set('smtp_encryption', 'tls');
    Settings::set('smtp_username', '');
    Settings::set('smtp_password', '');

    $client_email = "booking-reminder.{$unique_suffix}@example.com";

    $conn->prepare("INSERT INTO clients (name, email, phone, notes) VALUES (?, ?, ?, ?)")
        ->execute([
            'Booking Reminder Client ' . $unique_suffix,
            $client_email,
            '555-1212',
            'Booking reminder regression test',
        ]);
    $cleanup['client_id'] = (int) $conn->lastInsertId();

    $conn->prepare("INSERT INTO appointment_types (name, duration_minutes, is_active, unique_link) VALUES (?, 60, 1, ?)")
        ->execute([
            'Booking Reminder Session ' . $unique_suffix,
            'booking-reminder-' . $unique_suffix,
        ]);
    $cleanup['appointment_type_id'] = (int) $conn->lastInsertId();

    $conn->prepare("
        INSERT INTO booking_reminder_rules (appointment_type_id, name, hours_before, template_id, is_active)
        VALUES (?, ?, 1, NULL, 1)
    ")->execute([
        $cleanup['appointment_type_id'],
        'One Hour Reminder ' . $unique_suffix,
    ]);
    $cleanup['rule_id'] = (int) $conn->lastInsertId();

    $rule_booking_datetime = (new DateTimeImmutable('now'))
        ->modify('+' . RULE_BOOKING_OFFSET_HOURS . ' hours')
        ->format('Y-m-d H:i:s');
    [$rule_booking_date, $rule_booking_time] = explode(' ', $rule_booking_datetime, 2);

    $legacy_booking_datetime = (new DateTimeImmutable('now'))
        ->modify('+' . LEGACY_BOOKING_OFFSET_HOURS . ' hours')
        ->format('Y-m-d H:i:s');
    [$legacy_booking_date, $legacy_booking_time] = explode(' ', $legacy_booking_datetime, 2);

    $insert_booking = $conn->prepare("
        INSERT INTO bookings (
            client_id, appointment_type_id, client_name, client_email, client_phone,
            service_type, appointment_date, appointment_time, status, reminder_sent
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 0)
    ");

    $insert_booking->execute([
        $cleanup['client_id'],
        $cleanup['appointment_type_id'],
        'Booking Reminder Client ' . $unique_suffix,
        $client_email,
        '555-1212',
        'Booking Reminder Session',
        $rule_booking_date,
        $rule_booking_time,
    ]);
    $rule_booking_id = (int) $conn->lastInsertId();
    $cleanup['booking_ids'][] = $rule_booking_id;

    $insert_booking->execute([
        $cleanup['client_id'],
        null,
        'Booking Reminder Client ' . $unique_suffix,
        $client_email,
        '555-1212',
        'Legacy Booking Reminder Session',
        $legacy_booking_date,
        $legacy_booking_time,
    ]);
    $legacy_booking_id = (int) $conn->lastInsertId();
    $cleanup['booking_ids'][] = $legacy_booking_id;

    $task = new BookingReminderTask($conn);
    $rule_result = $task->execute();

    assertBookingReminderTest($rule_result['success'] === true, 'Rule-based booking reminder task did not report success.');
    assertBookingReminderTest($rule_result['items_processed'] === 0, 'Expected zero successful sends when SMTP host is blank.');

    $rule_error_text = implode("\n", $rule_result['errors']);

    assertBookingReminderTest(
        str_contains($rule_error_text, '#' . (string) $rule_booking_id),
        'Expected rule-based reminder processing to reach the matching booking.'
    );

    $reminder_lookup = $conn->prepare("SELECT COUNT(*) FROM booking_reminders_sent WHERE booking_id = ?");
    $reminder_lookup->execute([$rule_booking_id]);
    assertBookingReminderTest((int) $reminder_lookup->fetchColumn() === 0, 'Failed reminder attempts must not be recorded as sent.');

    $legacy_method = (new ReflectionClass(BookingReminderTask::class))->getMethod('executeLegacy');
    $legacy_method->setAccessible(true);
    /** @var array{success: bool, items_processed: int, message: string, errors: list<string>} $legacy_result */
    $legacy_result = $legacy_method->invoke($task);

    assertBookingReminderTest($legacy_result['success'] === true, 'Legacy booking reminder task did not report success.');
    assertBookingReminderTest($legacy_result['items_processed'] === 0, 'Expected zero successful legacy sends when SMTP host is blank.');

    $legacy_error_text = implode("\n", $legacy_result['errors']);

    assertBookingReminderTest(
        str_contains($legacy_error_text, '#' . (string) $legacy_booking_id),
        'Expected legacy reminder processing to reach the legacy booking.'
    );

    $booking_lookup = $conn->prepare("SELECT reminder_sent FROM bookings WHERE id = ?");
    $booking_lookup->execute([$legacy_booking_id]);
    $legacy_booking = $booking_lookup->fetch(PDO::FETCH_ASSOC);
    assertBookingReminderTest(is_array($legacy_booking), 'Failed to reload legacy booking row.');
    assertBookingReminderTest((int) ($legacy_booking['reminder_sent'] ?? 0) === 0, 'Failed legacy reminder attempts must not mark the booking as sent.');

    echo "Booking reminder regression test passed.\n";
} catch (Throwable $e) {
    $exit_code = 1;
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
} finally {
    foreach ($original_settings as $key => $value) {
        Settings::set($key, $value);
    }

    if ($cleanup['booking_ids'] !== []) {
        $delete_sent = $conn->prepare("DELETE FROM booking_reminders_sent WHERE booking_id = ?");
        $delete_booking = $conn->prepare("DELETE FROM bookings WHERE id = ?");

        foreach ($cleanup['booking_ids'] as $booking_id) {
            $delete_sent->execute([$booking_id]);
            $delete_booking->execute([$booking_id]);
        }
    }

    if (is_int($cleanup['rule_id']) && $cleanup['rule_id'] > 0) {
        $conn->prepare("DELETE FROM booking_reminder_rules WHERE id = ?")->execute([$cleanup['rule_id']]);
    }

    if (is_int($cleanup['appointment_type_id']) && $cleanup['appointment_type_id'] > 0) {
        $conn->prepare("DELETE FROM appointment_types WHERE id = ?")->execute([$cleanup['appointment_type_id']]);
    }

    if (is_int($cleanup['client_id']) && $cleanup['client_id'] > 0) {
        $conn->prepare("DELETE FROM clients WHERE id = ?")->execute([$cleanup['client_id']]);
    }
}

exit($exit_code);
