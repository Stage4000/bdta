#!/usr/bin/env php
<?php

function assertAdminRescheduleOverride(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$clients_view = file_get_contents(dirname(__DIR__) . '/client/clients_view.php');
$api_bookings = file_get_contents(dirname(__DIR__) . '/backend/public/api_bookings.php');

if ($clients_view === false || $api_bookings === false) {
    fwrite(STDERR, "Failed to load admin reschedule availability fixtures.\n");
    exit(1);
}

assertAdminRescheduleOverride(
    str_contains($clients_view, 'id="adminRescheduleRespectGoogleCalendar"'),
    'Admin reschedule modal should expose a Google Calendar availability toggle.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'Leave this unchecked to override Google Calendar conflicts.'),
    'Admin reschedule modal should explain how to override Google Calendar conflicts.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, "document.getElementById('adminRescheduleRespectGoogleCalendar').checked = false;"),
    'Admin reschedule modal should default to overriding Google Calendar conflicts when it opens.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, "&respect_google_calendar=' + encodeURIComponent(respectGoogleCalendar ? '1' : '0')"),
    'Admin reschedule availability requests should explicitly control Google Calendar filtering.'
);
assertAdminRescheduleOverride(
    str_contains($api_bookings, "array_key_exists('respect_google_calendar', \$_GET)"),
    'Booking availability API should accept an explicit Google Calendar filtering flag.'
);
assertAdminRescheduleOverride(
    str_contains($api_bookings, 'if ($respect_google_calendar && GoogleCalendarIntegration::isOAuthConfigured())'),
    'Booking availability API should only enforce Google Calendar conflicts when requested.'
);

echo "Admin reschedule Google Calendar override checks passed.\n";
