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
    str_contains($clients_view, 'id="adminRescheduleTime"'),
    'Admin reschedule modal should expose a manual time input.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'Enter any future date and time, or turn on availability suggestions below.'),
    'Admin reschedule modal should explain that manual override is available by default.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, "document.getElementById('adminRescheduleRespectGoogleCalendar').checked = false;"),
    'Admin reschedule modal should default to manual override mode when it opens.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, "document.getElementById('adminRescheduleTime').value = '';"),
    'Admin reschedule modal should clear any manual time override when it opens.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'Show availability using website rules and connected Google Calendar'),
    'Admin reschedule modal should offer an explicit availability-view toggle.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, "if (!showAvailability) {"),
    'Admin reschedule modal should avoid loading constrained availability unless the toggle is enabled.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, "'&respect_google_calendar=1'"),
    'Admin reschedule availability requests should opt into Google Calendar filtering when showing actual availability.'
);
assertAdminRescheduleOverride(
    !str_contains($clients_view, 'Website scheduling rules always apply.'),
    'Admin reschedule modal should no longer claim website scheduling rules always apply.'
);

$reschedule_block_start = strpos($clients_view, "if (\$booking_action === 'reschedule') {");
$reschedule_block_end = strpos($clients_view, "setFlashMessage('Booking rescheduled.', 'success');");
if ($reschedule_block_start === false || $reschedule_block_end === false || $reschedule_block_end <= $reschedule_block_start) {
    fwrite(STDERR, "Expected to locate the admin reschedule handler in client view.\n");
    exit(1);
}

$reschedule_block = substr($clients_view, $reschedule_block_start, $reschedule_block_end - $reschedule_block_start);

assertAdminRescheduleOverride(
    !str_contains($reschedule_block, 'advance_booking_min_days'),
    'Admin reschedule handler should no longer enforce minimum advance-booking rules.'
);
assertAdminRescheduleOverride(
    !str_contains($reschedule_block, 'advance_booking_max_days'),
    'Admin reschedule handler should no longer enforce maximum advance-booking rules.'
);
assertAdminRescheduleOverride(
    !str_contains($reschedule_block, 'That time slot is no longer available. Please choose another.'),
    'Admin reschedule handler should no longer reject manual overrides because of slot conflicts.'
);
assertAdminRescheduleOverride(
    str_contains($reschedule_block, 'The new appointment time must be in the future.'),
    'Admin reschedule handler should still require the rescheduled time to be in the future.'
);
assertAdminRescheduleOverride(
    str_contains($api_bookings, 'if (!is_scalar($value)) {'),
    'Booking availability API should ignore non-scalar Google Calendar filtering inputs safely.'
);
assertAdminRescheduleOverride(
    str_contains($api_bookings, "array_key_exists('respect_google_calendar', \$input)"),
    'Booking availability API should keep the explicit Google Calendar filtering flag for the optional availability view.'
);
assertAdminRescheduleOverride(
    str_contains($api_bookings, '$respect_google_calendar = api_booking_should_respect_google_calendar($_GET);'),
    'Booking availability API should normalize Google Calendar filtering through one shared helper.'
);
assertAdminRescheduleOverride(
    substr_count($api_bookings, 'if ($respect_google_calendar && GoogleCalendarIntegration::isOAuthConfigured())') >= 2,
    'Booking availability API should only enforce Google Calendar conflicts when the optional availability view requests it in both availability endpoints.'
);
assertAdminRescheduleOverride(
    str_contains($api_bookings, 'getFreeBusyRange('),
    'Available-dates availability should still support Google Calendar range checks.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'function adminRescheduleSelectionIsFuture(date, time) {'),
    'Admin reschedule UI should validate future date/time selections before enabling submit.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'adminRescheduleSelectionIsFuture(date, time)'),
    'Admin reschedule submit state should depend on the future date/time validation helper.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'function getAdminRescheduleCurrentDateTimeParts() {'),
    'Admin reschedule UI should derive current date/time from one shared helper.'
);
assertAdminRescheduleOverride(
    !str_contains($clients_view, "currentDate.toISOString().split('T')[0]"),
    'Admin reschedule UI should not derive the minimum date from UTC ISO timestamps.'
);
assertAdminRescheduleOverride(
    !str_contains($clients_view, 'let adminRescheduleTime = null;'),
    'Admin reschedule UI should not keep redundant manual time state.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'new AbortController()'),
    'Admin reschedule availability should cancel older requests.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, 'requestId !== adminRescheduleAvailabilityRequestSequence'),
    'Admin reschedule availability should ignore stale responses from older requests.'
);
assertAdminRescheduleOverride(
    str_contains($clients_view, "if (error.name === 'AbortError') {"),
    'Admin reschedule availability should ignore aborted request errors.'
);

echo "Admin reschedule Google Calendar override checks passed.\n";
