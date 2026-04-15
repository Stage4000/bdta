#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void
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

bdta_assert(
    str_contains($bookings_create, 'id="showAvailabilityBtn"'),
    'Admin booking form should expose a Show Availability button.'
);
bdta_assert(
    str_contains($bookings_create, 'id="availabilityTimesGrid"'),
    'Admin booking form should render a container for suggested available times.'
);
bdta_assert(
    str_contains($bookings_create, "fetch('/backend/public/api_bookings.php?date=' + encodeURIComponent(date) + '&appointment_type_id=' + encodeURIComponent(appointmentTypeId))"),
    'Admin booking availability should reuse the shared booking availability API.'
);
bdta_assert(
    str_contains($bookings_create, "bookingTimeInput.value = time;"),
    'Clicking an available time should populate the booking time field.'
);
bdta_assert(
    str_contains($bookings_create, 'Connected Google Calendar availability was checked.'),
    'Admin booking availability UI should mention when connected Google Calendar data was included.'
);

echo "Admin booking availability UI checks passed.\n";
