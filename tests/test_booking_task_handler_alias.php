#!/usr/bin/env php
<?php
/**
 * Verify legacy booking cron tasks still resolve to the booking reminder handler.
 */

$base_dir = dirname(__DIR__) . '/backend/includes';
$config_file = $base_dir . '/config.php';
$database_file = $base_dir . '/database.php';
$handler_file = dirname(__DIR__) . '/backend/cron/tasks/booking.php';

if (!file_exists($config_file)) {
    throw new RuntimeException('Application config file is missing.');
}

if (!file_exists($database_file)) {
    throw new RuntimeException('Application database bootstrap file is missing.');
}

if (!file_exists($handler_file)) {
    throw new RuntimeException('Legacy booking task handler file is missing.');
}

require_once $config_file;
require_once $database_file;
require_once $handler_file;

echo "=== Booking Task Handler Alias Test ===\n\n";

if (!class_exists('BookingReminderTask')) {
    throw new RuntimeException('BookingReminderTask class was not loaded by the legacy handler.');
}

if (!class_exists('BookingTask')) {
    throw new RuntimeException('Legacy BookingTask class is missing.');
}

if (!in_array('BookingReminderTask', class_parents('BookingTask') ?: [], true)) {
    throw new RuntimeException('BookingTask should extend BookingReminderTask.');
}

$reflection = new ReflectionClass('BookingTask');
$constructor = $reflection->getConstructor();

if ($constructor === null) {
    throw new RuntimeException('BookingTask constructor is missing.');
}

if ($constructor->getNumberOfParameters() !== 1) {
    throw new RuntimeException('BookingTask constructor should accept the cron database connection.');
}

echo "✓ Legacy booking handler file exists: {$handler_file}\n";
echo "✓ Legacy booking handler loads successfully\n";
echo "✓ Legacy booking task class resolves to BookingReminderTask\n";
echo "\nAll booking handler alias tests passed!\n";
