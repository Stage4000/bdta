#!/usr/bin/env php
<?php

function assert_admin_booking_ui(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$bookings_create = file_get_contents(dirname(__DIR__) . '/client/bookings_create.php');

if ($bookings_create === false) {
    fwrite(STDERR, "Failed to read client/bookings_create.php\n");
    exit(1);
}

assert_admin_booking_ui(
    str_contains($bookings_create, 'id="showAvailabilityBtn"'),
    'Admin booking form should expose a Show Availability button.'
);
assert_admin_booking_ui(
    str_contains($bookings_create, 'id="availabilityTimesGrid"'),
    'Admin booking form should render a container for suggested available times.'
);
assert_admin_booking_ui(
    str_contains($bookings_create, "fetch('/backend/public/api_bookings.php?date='"),
    'Admin booking availability should call the shared booking availability endpoint.'
);
assert_admin_booking_ui(
    str_contains($bookings_create, "'&appointment_type_id=' + encodeURIComponent(appointmentTypeId)"),
    'Admin booking availability requests should include the selected appointment type id.'
);
assert_admin_booking_ui(
    str_contains($bookings_create, 'encodeURIComponent(date)'),
    'Admin booking availability requests should include the selected date.'
);
assert_admin_booking_ui(
    str_contains($bookings_create, "bookingTimeInput.value = time;"),
    'Clicking an available time should populate the booking time field.'
);
assert_admin_booking_ui(
    str_contains($bookings_create, 'Connected Google Calendar availability was checked.'),
    'Admin booking availability UI should mention when connected Google Calendar data was included.'
);

echo "Admin booking availability UI checks passed.\n";
