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
        'service_type' => ' Training Session ',
        'description' => ' Working on leash skills ',
    ]);
    if ($normalized === null) {
        throw new RuntimeException('Expected valid active timers to normalize.');
    }
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
    if ($from_json === null) {
        throw new RuntimeException('Expected JSON timer payloads to normalize.');
    }
    assertTimeTrackerHelper($from_json['client_id'] === 9, 'Expected JSON timer payloads to normalize.');
    assertTimeTrackerHelper(
        bdta_active_timer_storage_key('admin', 7) === 'bdtaActiveTimer:admin:7',
        'Expected active timer storage keys to stay consistent across admin pages.'
    );
    assertTimeTrackerHelper(
        bdta_active_timer_storage_key('  ', -4) === 'bdtaActiveTimer:admin:0',
        'Expected active timer storage keys to normalize blank user types and negative ids.'
    );

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
    assertTimeTrackerHelper($status_payload['active'], 'Expected status payloads to mark the timer active.');
    assertTimeTrackerHelper($status_payload['elapsed'] === 900, 'Expected status payloads to expose elapsed seconds.');
    assertTimeTrackerHelper($status_payload['timer']['description'] === 'Leash work', 'Expected status payloads to include the full timer metadata.');
    assertTimeTrackerHelper(
        bdta_active_timer_has_valid_start_time($status_payload['timer'], 1712280900),
        'Expected current timers to pass future-time validation.'
    );
    assertTimeTrackerHelper(
        !bdta_active_timer_has_valid_start_time($status_payload['timer'], 1712279500),
        'Expected timers too far in the future to fail validation.'
    );
    $validated_timer = bdta_normalize_valid_active_timer([
        'start_time' => 1712280900,
        'client_id' => 14,
        'service_type' => 'Board and Train',
        'description' => 'Follow-up',
    ], 1712280900);
    if ($validated_timer === null) {
        throw new RuntimeException('Expected valid timers to survive normalization and future-time validation.');
    }
    assertTimeTrackerHelper($validated_timer['client_id'] === 14, 'Expected validated timers to preserve timer metadata.');
    assertTimeTrackerHelper(
        bdta_normalize_valid_active_timer([
            'start_time' => 1712281301,
            'client_id' => 14,
            'service_type' => 'Board and Train',
            'description' => 'Follow-up',
        ], 1712280900) === null,
        'Expected validated timers to reject start times beyond the allowed future tolerance.'
    );

    echo "Time tracker helper tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
