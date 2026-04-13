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

function bdta_booking_resource_units(array $resource_config, int $pet_count): int {
    if (($resource_config['allocation'] ?? 'per_appointment') === 'per_pet') {
        return max(1, $pet_count);
    }

    return 1;
}

/**
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

    $used_units = 0;
    foreach ($existing_bookings as $booking_row) {
        if ($appointment_type_id !== null && array_int_value($booking_row, 'appointment_type_id') !== $appointment_type_id) {
            continue;
        }

        $existing_start_minutes = bdta_booking_time_to_minutes(array_string_value($booking_row, 'appointment_time'));
        if ($existing_start_minutes === null) {
            continue;
        }

        if (!bdta_booking_windows_overlap(
            $proposed_start_minutes,
            $duration_minutes,
            $buffer_before_minutes,
            $buffer_after_minutes,
            $existing_start_minutes,
            array_int_value($booking_row, 'duration_minutes', 60),
            array_int_value($booking_row, 'b_buffer_before'),
            array_int_value($booking_row, 'b_buffer_after')
        )) {
            continue;
        }

        $used_units += bdta_booking_resource_units_for_booking($resource_config, $booking_row);
        if ($used_units + $requested_units > max(1, array_int_value($resource_config, 'capacity', 1))) {
            return false;
        }
    }

    return true;
}
