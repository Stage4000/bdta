#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/public_access_links.php';
require_once dirname(__DIR__) . '/backend/includes/database.php';

$database_source = file_get_contents(dirname(__DIR__) . '/backend/includes/database.php');
if (!is_string($database_source) || $database_source === '') {
    throw new RuntimeException('Unable to read backend/includes/database.php');
}

$reflection = new ReflectionClass(Database::class);
$length_constant = $reflection->getReflectionConstant('PUBLIC_ACCESS_TOKEN_LENGTH');
$definition_method = $reflection->getMethod('bookingIcalTokenColumnDefinition');

if (!$length_constant instanceof ReflectionClassConstant || !$definition_method instanceof ReflectionMethod) {
    throw new RuntimeException('Unable to inspect bookings iCal token schema helpers.');
}

$configured_length = $length_constant->getValue();
$definition_method->setAccessible(true);
$expected_definition = $definition_method->invoke($reflection->newInstanceWithoutConstructor());

if (!is_int($configured_length) || $configured_length !== BDTA_PUBLIC_ACCESS_TOKEN_LENGTH) {
    throw new RuntimeException('Database bookings token length should match the generated public access token length.');
}

if (
    !is_string($expected_definition)
    || !str_starts_with($expected_definition, 'ical_token VARCHAR(')
    || !str_ends_with($expected_definition, (string) BDTA_PUBLIC_ACCESS_TOKEN_LENGTH . ')')
) {
    throw new RuntimeException('Bookings ical_token column definition helper should use the expected bounded VARCHAR length.');
}

$bookings_create_start = strpos($database_source, 'CREATE TABLE IF NOT EXISTS bookings (');
if ($bookings_create_start === false) {
    throw new RuntimeException('Unable to locate bookings CREATE TABLE SQL.');
}

$bookings_create_end = strpos($database_source, '");', $bookings_create_start);
if ($bookings_create_end === false) {
    throw new RuntimeException('Unable to isolate bookings CREATE TABLE SQL.');
}

$bookings_create_sql = substr($database_source, $bookings_create_start, $bookings_create_end - $bookings_create_start);
if (!is_string($bookings_create_sql) || !str_contains($bookings_create_sql, '$this->bookingIcalTokenColumnDefinition()')) {
    throw new RuntimeException('Bookings table creation should use the shared ical_token column definition helper.');
}

if (!str_contains($database_source, 'ALTER TABLE bookings ADD COLUMN " . $this->bookingIcalTokenColumnDefinition()')) {
    throw new RuntimeException('Bookings migration should add ical_token using the shared bounded VARCHAR helper.');
}

if (!str_contains($database_source, '$this->ensureBookingIcalTokenIndex()')) {
    throw new RuntimeException('Bookings schema setup should use the shared iCal token index helper.');
}

if (!str_contains($database_source, "if (!in_array('ical_token', \$booking_column_names, true))")) {
    throw new RuntimeException('Bookings iCal token index helper should confirm the column exists before indexing it.');
}

if (!str_contains($database_source, "!\$this->indexExists('bookings', 'idx_bookings_ical_token')")) {
    throw new RuntimeException('Bookings iCal token index creation should only run after the schema confirms the column exists.');
}

echo "Booking iCal token migration test passed.\n";
