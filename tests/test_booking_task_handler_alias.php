#!/usr/bin/env php
<?php
/**
 * Verify legacy booking cron tasks still resolve to the booking reminder handler.
 */

$handler_file = dirname(__DIR__) . '/backend/cron/tasks/booking.php';

if (!file_exists($handler_file)) {
    throw new RuntimeException('Legacy booking task handler file is missing.');
}

$handler_contents = file_get_contents($handler_file);

if ($handler_contents === false) {
    throw new RuntimeException('Failed to read legacy booking task handler file.');
}

echo "=== Booking Task Handler Alias Test ===\n\n";

if (!str_contains($handler_contents, "require_once __DIR__ . '/booking_reminder.php';")) {
    throw new RuntimeException('Legacy booking task handler must load booking_reminder.php.');
}

if (!str_contains($handler_contents, 'class BookingTask extends BookingReminderTask')) {
    throw new RuntimeException('Legacy BookingTask class should extend BookingReminderTask.');
}

echo "✓ Legacy booking handler file exists: {$handler_file}\n";
echo "✓ Legacy booking task class resolves to BookingReminderTask\n";
echo "\nAll booking handler alias tests passed!\n";
