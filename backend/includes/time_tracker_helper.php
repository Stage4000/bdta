<?php

/**
 * @return array{start_time: int, client_id: int, service_type: string, description: string}|null
 */
function bdta_normalize_active_timer(mixed $timer): ?array
{
    if (is_string($timer)) {
        $decoded = json_decode($timer, true);
        $timer = is_array($decoded) ? $decoded : null;
    }

    if (!is_array($timer)) {
        return null;
    }

    $start_time = bdta_time_tracker_int($timer['start_time'] ?? 0);
    $client_id = bdta_time_tracker_int($timer['client_id'] ?? 0);
    $service_type = bdta_time_tracker_string($timer['service_type'] ?? '');
    $description = bdta_time_tracker_string($timer['description'] ?? '');

    if ($start_time <= 0 || $client_id <= 0 || $service_type === '') {
        return null;
    }

    return [
        'start_time' => $start_time,
        'client_id' => $client_id,
        'service_type' => $service_type,
        'description' => $description,
    ];
}

/**
 * @param array{start_time: int, client_id: int, service_type: string, description: string} $timer
 * @return array{active: true, start_time: int, elapsed: int, timer: array{start_time: int, client_id: int, service_type: string, description: string}}
 */
function bdta_active_timer_status_payload(array $timer, ?int $now = null): array
{
    $current_time = $now ?? time();
    $elapsed = max(0, $current_time - $timer['start_time']);

    return [
        'active' => true,
        'start_time' => $timer['start_time'],
        'elapsed' => $elapsed,
        'timer' => $timer,
    ];
}

function bdta_time_tracker_int(mixed $value): int
{
    return is_numeric($value) ? (int) $value : 0;
}

function bdta_time_tracker_string(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return trim((string) $value);
}
