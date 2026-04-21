#!/usr/bin/env php
<?php

$clients_view_path = dirname(__DIR__) . '/client/clients_view.php';
$clients_view = file_get_contents($clients_view_path);

if ($clients_view === false) {
    fwrite(STDERR, "Unable to read client view fixture.\n");
    exit(1);
}

$google_calendar_reference_offset = strpos($clients_view, 'GoogleCalendarIntegration::');
if ($google_calendar_reference_offset === false) {
    fwrite(STDERR, "Client view should reference Google Calendar integration helpers.\n");
    exit(1);
}

$tokens = token_get_all($clients_view);
$current_offset = 0;
$loads_google_calendar = false;

for ($index = 0, $token_count = count($tokens); $index < $token_count; $index++) {
    $token = $tokens[$index];
    $token_text = is_array($token) ? $token[1] : $token;
    $token_offset = $current_offset;
    $current_offset += strlen($token_text);

    if ($token_offset > $google_calendar_reference_offset) {
        break;
    }

    if (!is_array($token) || !in_array($token[0], [T_REQUIRE, T_REQUIRE_ONCE], true)) {
        continue;
    }

    $require_statement = $token_text;
    while (++$index < $token_count) {
        $statement_token = $tokens[$index];
        $statement_text = is_array($statement_token) ? $statement_token[1] : $statement_token;
        $require_statement .= $statement_text;
        $current_offset += strlen($statement_text);

        if ($statement_text === ';') {
            break;
        }
    }

    if (preg_match("/['\"][^'\"]*google_calendar\.php['\"]/i", $require_statement)) {
        $loads_google_calendar = true;
        break;
    }
}

if (!$loads_google_calendar) {
    fwrite(STDERR, "Client view must require google_calendar.php before using GoogleCalendarIntegration.\n");
    exit(1);
}

echo "Client view Google Calendar dependency check passed.\n";
