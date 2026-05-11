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
$definition_constant = $reflection->getReflectionConstant('BOOKING_ICAL_TOKEN_COLUMN_DEFINITION');

if (!$length_constant instanceof ReflectionClassConstant || !$definition_constant instanceof ReflectionClassConstant) {
    throw new RuntimeException('Unable to inspect bookings iCal token schema constants.');
}

$configured_length = $length_constant->getValue();
$expected_definition = $definition_constant->getValue();

if (!is_int($configured_length) || $configured_length !== BDTA_PUBLIC_ACCESS_TOKEN_LENGTH) {
    throw new RuntimeException('Database bookings token length should match the generated public access token length.');
}

if (!is_string($expected_definition) || $expected_definition !== 'ical_token VARCHAR(' . BDTA_PUBLIC_ACCESS_TOKEN_LENGTH . ')') {
    throw new RuntimeException('Bookings ical_token column definition constant should use the expected bounded VARCHAR length.');
}

if (!str_contains($database_source, '" . self::BOOKING_ICAL_TOKEN_COLUMN_DEFINITION . "')) {
    throw new RuntimeException('Bookings table creation should use the shared ical_token column definition constant.');
}

if (!str_contains($database_source, 'ALTER TABLE bookings ADD COLUMN " . self::BOOKING_ICAL_TOKEN_COLUMN_DEFINITION')) {
    throw new RuntimeException('Bookings migration should add ical_token using the shared bounded VARCHAR definition.');
}

if (!str_contains($database_source, "unset(\$this->tableColumnsCache['bookings']);")) {
    throw new RuntimeException('Bookings migration should refresh the cached schema after adding ical_token.');
}

if (!str_contains($database_source, "in_array('ical_token', \$booking_column_names, true)")) {
    throw new RuntimeException('Bookings migration should confirm the ical_token column exists before indexing it.');
}

if (!str_contains($database_source, "!\$this->indexExists('bookings', 'idx_bookings_ical_token')")) {
    throw new RuntimeException('Bookings iCal token index creation should only run after the schema confirms the column exists.');
}

echo "Booking iCal token migration test passed.\n";
