#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$source = file_get_contents(dirname(__DIR__) . '/backend/includes/google_calendar.php');
if ($source === false) {
    fwrite(STDERR, "Unable to read backend/includes/google_calendar.php\n");
    exit(1);
}

$preserves_existing_refresh_token =
    str_contains($source, '$stored_refresh_token = self::rowString($row, \'refresh_token\');')
    && str_contains($source, 'if ($refresh_token === \'\' && $stored_refresh_token !== \'\') {')
    && str_contains($source, '$refresh_token = $stored_refresh_token;');

if (!$preserves_existing_refresh_token) {
    fwrite(STDERR, "Expected saveOAuthToken() to preserve an existing refresh token when Google omits refresh_token on re-auth.\n");
    exit(1);
}

echo "Google Calendar OAuth refresh-token preservation regression test passed.\n";
