#!/usr/bin/env php
<?php

const BDTA_GOOGLE_CALENDAR_REQUIRE_PATTERN = '/\brequire(?:_once)?\b[^\r\n;]*[\'"][^\'"]*google_calendar\.php[\'"][^\r\n;]*;/i';

$clients_view_path = dirname(__DIR__) . '/client/clients_view.php';
$clients_view = file_get_contents($clients_view_path);

if ($clients_view === false) {
    fwrite(STDERR, "Unable to read client view fixture.\n");
    exit(1);
}

if (!preg_match(BDTA_GOOGLE_CALENDAR_REQUIRE_PATTERN, $clients_view, $matches, PREG_OFFSET_CAPTURE)) {
    fwrite(STDERR, "Client view must load the Google Calendar integration before using it.\n");
    exit(1);
}

$google_calendar_reference_offset = strpos($clients_view, 'GoogleCalendarIntegration::');
if ($google_calendar_reference_offset === false) {
    fwrite(STDERR, "Client view should reference Google Calendar integration helpers.\n");
    exit(1);
}

$google_calendar_require_match = $matches[0];
$google_calendar_require_offset = (int) $google_calendar_require_match[1];
if ($google_calendar_require_offset > $google_calendar_reference_offset) {
    fwrite(STDERR, "Client view must require google_calendar.php before using GoogleCalendarIntegration.\n");
    exit(1);
}

echo "Client view Google Calendar dependency check passed.\n";
