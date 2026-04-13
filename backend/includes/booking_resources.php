<?php

/**
 * @param array<string, mixed> $appointment_type
 * @return array{enabled: bool, name: string, capacity: int, allocation: string}
 */
function bdta_booking_resource_config(array $appointment_type): array {
    $enabled = array_int_value($appointment_type, 'uses_resource') === 1;
    $allocation = array_string_value($appointment_type, 'resource_allocation', 'per_appointment');
    if (!in_array($allocation, ['per_appointment', 'per_pet'], true)) {
        $allocation = 'per_appointment';
    }

    return [
        'enabled' => $enabled,
        'name' => array_string_value($appointment_type, 'resource_name'),
        'capacity' => max(1, array_int_value($appointment_type, 'resource_capacity', 1)),
        'allocation' => $allocation,
    ];
}

function bdta_booking_time_to_minutes(string $time): ?int {
    $time = substr(trim($time), 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        return null;
    }

    [$hours, $minutes] = array_map('intval', explode(':', $time));
    if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
        return null;
    }

    return ($hours * 60) + $minutes;
}

/**
 * @param array{enabled: bool, name: string, capacity: int, allocation: string} $resource_config
 */
function bdta_booking_resource_units(array $resource_config, int $pet_count): int {
    if ($resource_config['allocation'] === 'per_pet') {
        return max(1, $pet_count);
    }

    return 1;
}

/**
 * @param array{enabled: bool, name: string, capacity: int, allocation: string} $resource_config
 */
function bdta_booking_resource_capacity_available(array $resource_config, int $used_units, int $requested_units): bool {
    return $used_units + max(1, $requested_units) <= max(1, array_int_value($resource_config, 'capacity', 1));
}

/**
 * @param array{enabled: bool, name: string, capacity: int, allocation: string} $resource_config
 * @param array<string, mixed> $booking_row
 */
function bdta_booking_resource_units_for_booking(array $resource_config, array $booking_row): int {
    return bdta_booking_resource_units($resource_config, array_int_value($booking_row, 'pet_count'));
}

function bdta_booking_windows_overlap(
    int $proposed_start_minutes,
    int $proposed_duration_minutes,
    int $proposed_buffer_before_minutes,
    int $proposed_buffer_after_minutes,
    int $existing_start_minutes,
    int $existing_duration_minutes,
    int $existing_buffer_before_minutes,
    int $existing_buffer_after_minutes
): bool {
    $proposed_start = $proposed_start_minutes - max(0, $proposed_buffer_before_minutes);
    $proposed_end = $proposed_start_minutes + max(1, $proposed_duration_minutes) + max(0, $proposed_buffer_after_minutes);
    $existing_start = $existing_start_minutes - max(0, $existing_buffer_before_minutes);
    $existing_end = $existing_start_minutes + max(1, $existing_duration_minutes) + max(0, $existing_buffer_after_minutes);

    return $proposed_start < $existing_end && $existing_start < $proposed_end;
}

/**
 * @param list<array<string, mixed>> $existing_bookings
 * @param array{enabled: bool, name: string, capacity: int, allocation: string} $resource_config
 * @return array{exact_type_slot_count: int, has_overlap_conflict: bool, overlapping_resource_units: int}
 */
function bdta_booking_slot_usage_summary(
    array $existing_bookings,
    string $appointment_time,
    int $duration_minutes,
    int $buffer_before_minutes,
    int $buffer_after_minutes,
    array $resource_config = ['enabled' => false, 'name' => '', 'capacity' => 1, 'allocation' => 'per_appointment'],
    ?int $appointment_type_id = null
): array {
    $proposed_start_minutes = bdta_booking_time_to_minutes($appointment_time);
    if ($proposed_start_minutes === null) {
        return [
            'exact_type_slot_count' => 0,
            'has_overlap_conflict' => false,
            'overlapping_resource_units' => 0,
        ];
    }

    $exact_type_slot_count = 0;
    $has_overlap_conflict = false;
    $overlapping_resource_units = 0;
    $seen_windows = [];
    $exact_time = substr(trim($appointment_time), 0, 5);
    $resource_enabled = !empty($resource_config['enabled']);

    foreach ($existing_bookings as $booking_row) {
        $row_appointment_type_id = array_int_value($booking_row, 'appointment_type_id');
        $row_time = substr(array_string_value($booking_row, 'appointment_time'), 0, 5);

        if ($appointment_type_id !== null && $row_appointment_type_id === $appointment_type_id && $row_time === $exact_time) {
            $exact_type_slot_count++;
        }

        $existing_start_minutes = bdta_booking_time_to_minutes($row_time);
        if ($existing_start_minutes === null) {
            continue;
        }

        $existing_duration_minutes = max(1, array_int_value($booking_row, 'duration_minutes', 60));
        $existing_buffer_before_minutes = max(0, array_int_value($booking_row, 'b_buffer_before'));
        $existing_buffer_after_minutes = max(0, array_int_value($booking_row, 'b_buffer_after'));

        if (!bdta_booking_windows_overlap(
            $proposed_start_minutes,
            $duration_minutes,
            $buffer_before_minutes,
            $buffer_after_minutes,
            $existing_start_minutes,
            $existing_duration_minutes,
            $existing_buffer_before_minutes,
            $existing_buffer_after_minutes
        )) {
            continue;
        }

        if ($resource_enabled && ($appointment_type_id === null || $row_appointment_type_id === $appointment_type_id)) {
            $overlapping_resource_units += bdta_booking_resource_units_for_booking($resource_config, $booking_row);
        }

        $window_key = ($existing_start_minutes - $existing_buffer_before_minutes) . '-' . ($existing_start_minutes + $existing_duration_minutes + $existing_buffer_after_minutes);
        if (!isset($seen_windows[$window_key])) {
            $seen_windows[$window_key] = true;
            $has_overlap_conflict = true;
        }
    }

    return [
        'exact_type_slot_count' => $exact_type_slot_count,
        'has_overlap_conflict' => $has_overlap_conflict,
        'overlapping_resource_units' => $overlapping_resource_units,
    ];
}

/**
 * @param array{enabled: bool, name: string, capacity: int, allocation: string} $resource_config
 * @param list<array<string, mixed>> $existing_bookings
 */
function bdta_booking_resource_has_capacity(
    array $resource_config,
    array $existing_bookings,
    string $appointment_time,
    int $duration_minutes,
    int $buffer_before_minutes,
    int $buffer_after_minutes,
    int $requested_units,
    ?int $appointment_type_id = null
): bool {
    if (empty($resource_config['enabled'])) {
        return true;
    }

    $proposed_start_minutes = bdta_booking_time_to_minutes($appointment_time);
    if ($proposed_start_minutes === null) {
        return false;
    }
    $usage = bdta_booking_slot_usage_summary(
        $existing_bookings,
        $appointment_time,
        $duration_minutes,
        $buffer_before_minutes,
        $buffer_after_minutes,
        $resource_config,
        $appointment_type_id
    );

    return bdta_booking_resource_capacity_available($resource_config, $usage['overlapping_resource_units'], $requested_units);
}
