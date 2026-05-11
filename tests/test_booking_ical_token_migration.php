#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/public_access_links.php';

$database_source = file_get_contents(dirname(__DIR__) . '/backend/includes/database.php');
if (!is_string($database_source) || $database_source === '') {
    throw new RuntimeException('Unable to read backend/includes/database.php');
}

$expected_definition = 'ical_token VARCHAR(' . BDTA_PUBLIC_ACCESS_TOKEN_LENGTH . ')';

if (!str_contains($database_source, $expected_definition)) {
    throw new RuntimeException('Bookings ical_token column should use a bounded VARCHAR length that matches generated token length.');
}

if (!str_contains($database_source, 'ALTER TABLE bookings ADD COLUMN ' . $expected_definition)) {
    throw new RuntimeException('Bookings migration should add ical_token using the bounded VARCHAR definition.');
}

if (!str_contains($database_source, "if (in_array('ical_token', \$booking_column_names, true) && !\$this->indexExists('bookings', 'idx_bookings_ical_token'))")) {
    throw new RuntimeException('Bookings iCal token index creation should only run after the schema confirms the column exists.');
}

echo "Booking iCal token migration test passed.\n";
