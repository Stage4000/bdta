#!/usr/bin/env php
<?php

function assertGoogleCalendarOAuthGuidance(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$google_calendar = file_get_contents(dirname(__DIR__) . '/backend/includes/google_calendar.php');
$settings_page = file_get_contents(dirname(__DIR__) . '/client/settings.php');
$calendar_guide = file_get_contents(dirname(__DIR__) . '/backend/CALENDAR_INTEGRATION.md');

if ($google_calendar === false || $settings_page === false || $calendar_guide === false) {
    fwrite(STDERR, "Failed to load Google Calendar OAuth guidance fixtures.\n");
    exit(1);
}

assertGoogleCalendarOAuthGuidance(
    str_contains($google_calendar, 'invalid_grant'),
    'Google Calendar OAuth refresh handling should recognize invalid_grant failures explicitly.'
);
assertGoogleCalendarOAuthGuidance(
    str_contains($google_calendar, 'Testing')
        && str_contains($google_calendar, '7 days')
        && str_contains($google_calendar, 'Production'),
    'Google Calendar OAuth refresh handling should explain the 7-day Testing-mode refresh-token expiry and the Production fix.'
);
assertGoogleCalendarOAuthGuidance(
    str_contains($settings_page, 'Testing')
        && str_contains($settings_page, '7 days')
        && str_contains($settings_page, 'Production'),
    'Calendar settings should warn admins that Google Testing mode issues refresh tokens that expire after 7 days.'
);
assertGoogleCalendarOAuthGuidance(
    str_contains($calendar_guide, 'Testing')
        && str_contains($calendar_guide, '7 days')
        && str_contains($calendar_guide, 'Production')
        && str_contains($calendar_guide, 'reconnect'),
    'Calendar integration guide should document the Testing-mode expiry and instruct admins to publish to Production and reconnect.'
);

echo "Google Calendar OAuth expiry guidance checks passed.\n";
