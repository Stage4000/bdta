#!/usr/bin/env php
<?php

function bdta_assert_contains(string $haystack, string $needle, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message);
    }
}

$bookingsListPath = dirname(__DIR__) . '/client/bookings_list.php';
$bookingsList = file_get_contents($bookingsListPath);

if (!is_string($bookingsList) || $bookingsList === '') {
    throw new RuntimeException('Unable to read the bookings list page fixture.');
}

bdta_assert_contains(
    $bookingsList,
    "\$view_filter = in_array(\$requested_view_filter, ['upcoming', 'past', 'custom'], true)",
    'Bookings list should validate the upcoming, past, and custom filter modes.'
);
bdta_assert_contains(
    $bookingsList,
    "\$sort_preference === 'default'",
    'Bookings list should support default sort behavior derived from the selected filter.'
);
bdta_assert_contains(
    $bookingsList,
    "\$order_by_sql = [",
    'Bookings list should build ordering from a whitelisted SQL map.'
);
bdta_assert_contains(
    $bookingsList,
    'name="start_date"',
    'Bookings list should expose a custom start date filter.'
);
bdta_assert_contains(
    $bookingsList,
    'name="end_date"',
    'Bookings list should expose a custom end date filter.'
);
bdta_assert_contains(
    $bookingsList,
    'name="sort"',
    'Bookings list should expose a sort selector.'
);
bdta_assert_contains(
    $bookingsList,
    'clients_view.php?id=<?php echo safe_int($booking[\'client_id\']); ?>',
    'Bookings list should link each client name to the client profile when a client id is available.'
);
bdta_assert_contains(
    $bookingsList,
    'booking-table-card',
    'Bookings list should apply the refreshed table card styling.'
);
bdta_assert_contains(
    $bookingsList,
    'No bookings found for the selected filters.',
    'Bookings list should show a filter-aware empty state.'
);

echo "Bookings list filter UI checks passed.\n";
