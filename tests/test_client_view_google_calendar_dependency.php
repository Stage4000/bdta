#!/usr/bin/env php
<?php

$clients_view_path = dirname(__DIR__) . '/client/clients_view.php';
$clients_view = file_get_contents($clients_view_path);

if ($clients_view === false) {
    fwrite(STDERR, "Unable to read client view fixture.\n");
    exit(1);
}

if (!str_contains($clients_view, "require_once '../backend/includes/google_calendar.php';")) {
    fwrite(STDERR, "Client view must load the Google Calendar integration before using it.\n");
    exit(1);
}

if (!str_contains($clients_view, 'GoogleCalendarIntegration::deleteEventForBooking')
    || !str_contains($clients_view, 'GoogleCalendarIntegration::updateEventForBooking')) {
    fwrite(STDERR, "Client view should continue to use Google Calendar booking sync helpers.\n");
    exit(1);
}

echo "Client view Google Calendar dependency check passed.\n";
