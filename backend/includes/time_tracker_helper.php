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
 * @return array{start_time: int, client_id: int, service_type: string, description: string}|null
 */
function bdta_normalize_valid_active_timer(mixed $timer, ?int $now = null, int $future_tolerance_seconds = 300): ?array
{
    $normalized_timer = bdta_normalize_active_timer($timer);
    if ($normalized_timer === null) {
        return null;
    }

    return bdta_active_timer_has_valid_start_time($normalized_timer, $now, $future_tolerance_seconds)
        ? $normalized_timer
        : null;
}

function bdta_active_timer_storage_key(mixed $user_type, mixed $user_id): string
{
    $normalized_user_type = bdta_time_tracker_string($user_type);
    if ($normalized_user_type === '') {
        $normalized_user_type = 'admin';
    }

    return sprintf('bdtaActiveTimer:%s:%d', $normalized_user_type, max(0, bdta_time_tracker_int($user_id)));
}

/**
 * @param array{start_time: int, client_id: int, service_type: string, description: string} $timer
 * @return array{
 *   active: true,
 *   start_time: int,
 *   elapsed: int,
 *   timer: array{start_time: int, client_id: int, service_type: string, description: string}
 * }
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

/**
 * @param array{start_time: int, client_id: int, service_type: string, description: string} $timer
 */
function bdta_active_timer_has_valid_start_time(array $timer, ?int $now = null, int $future_tolerance_seconds = 300): bool
{
    $current_time = $now ?? time();
    return $timer['start_time'] <= ($current_time + max(0, $future_tolerance_seconds));
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
