#!/usr/bin/env php
<?php

function assertPhpstanRegression(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$google_calendar = file_get_contents(dirname(__DIR__) . '/backend/includes/google_calendar.php');
$moxie = file_get_contents(dirname(__DIR__) . '/backend/includes/moxie.php');

if ($google_calendar === false || $moxie === false) {
    fwrite(STDERR, "Failed to load PHPStan regression fixtures.\n");
    exit(1);
}

assertPhpstanRegression(
    str_contains($google_calendar, "\$http_error = \$http_error_response['error'];"),
    'Google Calendar refresh handling should read the guaranteed http error shape directly for PHPStan.'
);
assertPhpstanRegression(
    !str_contains($google_calendar, "\$http_error_response['error'] ?? []"),
    'Google Calendar refresh handling should not null-coalesce a guaranteed error payload.'
);
assertPhpstanRegression(
    !str_contains($google_calendar, "is_array(\$http_error)"),
    'Google Calendar refresh handling should not re-check a statically known error array.'
);
assertPhpstanRegression(
    preg_match('/\\$response\\s*=\\s*\\[\\];\\s*while\\s*\\(true\\)/s', $moxie) === 1,
    'Moxie client pagination should initialize $response before the retry loop so PHPStan can prove it is defined.'
);

echo "PHPStan level 9 regression checks passed.\n";
