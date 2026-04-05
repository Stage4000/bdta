#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/time_tracker_helper.php';

function assertTimeTrackerHelper(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $normalized = bdta_normalize_active_timer([
        'start_time' => 1712280000,
        'client_id' => 12,
        'service_type' => 'Training Session',
        'description' => ' Working on leash skills ',
    ]);
    assertTimeTrackerHelper($normalized !== null, 'Expected valid active timers to normalize.');
    assertTimeTrackerHelper($normalized['start_time'] === 1712280000, 'Expected numeric start times to be preserved.');
    assertTimeTrackerHelper($normalized['client_id'] === 12, 'Expected numeric client ids to be preserved.');
    assertTimeTrackerHelper($normalized['service_type'] === 'Training Session', 'Expected service types to be trimmed.');
    assertTimeTrackerHelper($normalized['description'] === 'Working on leash skills', 'Expected descriptions to be trimmed.');

    $from_json = bdta_normalize_active_timer(json_encode([
        'start_time' => 1712283600,
        'client_id' => 9,
        'service_type' => 'Consultation',
        'description' => '',
    ]));
    assertTimeTrackerHelper($from_json !== null && $from_json['client_id'] === 9, 'Expected JSON timer payloads to normalize.');

    assertTimeTrackerHelper(
        bdta_normalize_active_timer([
            'start_time' => 1712283600,
            'client_id' => 0,
            'service_type' => 'Consultation',
        ]) === null,
        'Expected timers without a valid client id to be rejected.'
    );
    assertTimeTrackerHelper(
        bdta_normalize_active_timer([
            'start_time' => 0,
            'client_id' => 5,
            'service_type' => 'Consultation',
        ]) === null,
        'Expected timers without a valid start time to be rejected.'
    );
    assertTimeTrackerHelper(
        bdta_normalize_active_timer([
            'start_time' => 1712283600,
            'client_id' => 5,
            'service_type' => '',
        ]) === null,
        'Expected timers without a service type to be rejected.'
    );

    $status_payload = bdta_active_timer_status_payload([
        'start_time' => 1712280000,
        'client_id' => 12,
        'service_type' => 'Training Session',
        'description' => 'Leash work',
    ], 1712280900);
    assertTimeTrackerHelper($status_payload['active'] === true, 'Expected status payloads to mark the timer active.');
    assertTimeTrackerHelper($status_payload['elapsed'] === 900, 'Expected status payloads to expose elapsed seconds.');
    assertTimeTrackerHelper($status_payload['timer']['description'] === 'Leash work', 'Expected status payloads to include the full timer metadata.');
    assertTimeTrackerHelper(
        bdta_active_timer_has_valid_start_time($status_payload['timer'], 1712280900) === true,
        'Expected current timers to pass future-time validation.'
    );
    assertTimeTrackerHelper(
        bdta_active_timer_has_valid_start_time($status_payload['timer'], 1712279500) === false,
        'Expected timers too far in the future to fail validation.'
    );

    echo "Time tracker helper tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
