<?php
/** Shared scheduling rules used by public availability and booking mutations. */
require_once __DIR__ . '/booking_resources.php';
require_once __DIR__ . '/google_calendar.php';

/**
 * @return array<string, mixed>
 */
function api_booking_db_row(mixed $row): array {
    return assoc_row($row);
}

/**
 * @return list<array<string, mixed>>
 */
function api_booking_assoc_rows(mixed $value): array {
    if (is_string($value)) {
        return decode_json_assoc_list($value);
    }
    if (!is_array($value)) {
        return [];
    }
    return assoc_rows($value);
}

/**
 * @return array<int|string, array<string, mixed>>
 */
function api_booking_assoc_map(mixed $value): array {
    $decoded = is_string($value) ? decode_json_assoc($value) : assoc_row($value);
    $rows = [];
    foreach ($decoded as $key => $item) {
        if (is_array($item)) {
            $rows[(string)$key] = assoc_row($item);
        }
    }
    return $rows;
}

/**
 * @return list<int>
 */
function api_booking_int_list(mixed $value): array {
    if (!is_array($value)) {
        return [];
    }

    $ints = [];
    foreach ($value as $item) {
        $ints[] = safe_int($item);
    }
    return $ints;
}

/**
 * @return list<string>
 */
function api_booking_string_list(mixed $value): array {
    if (!is_array($value)) {
        return [];
    }

    $strings = [];
    foreach ($value as $item) {
        if (is_scalar($item) || $item === null) {
            $strings[] = scalar_string($item);
        }
    }
    return $strings;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function api_booking_filter_schedule_rows(array $rows, int $admin_user_id): array {
    $normalized_rows = api_booking_assoc_rows($rows);
    if ($admin_user_id <= 0) {
        return $normalized_rows;
    }

    return array_values(array_filter(
        $normalized_rows,
        static function (array $row) use ($admin_user_id): bool {
            $schedule_admin_user_id = array_int_value($row, 'schedule_admin_user_id');
            // Legacy/shared bookings may not have an assigned admin yet. Treat those
            // rows as conflicts for every admin-specific availability check so older
            // bookings still block time and cannot be double-booked.
            return $schedule_admin_user_id === 0 || $schedule_admin_user_id === $admin_user_id;
        }
    ));
}

/**
 * @return list<string>
 */
function api_booking_table_columns(SafePDO $conn, string $table_name): array {
    switch ($table_name) {
        case 'appointment_types':
            $stmt = $conn->query('SELECT * FROM appointment_types LIMIT 0');
            break;
        case 'pets':
            $stmt = $conn->query('SELECT * FROM pets LIMIT 0');
            break;
        default:
            throw new RuntimeException('Unsupported table lookup requested.');
    }

    $columns = [];
    for ($index = 0, $count = $stmt->columnCount(); $index < $count; $index++) {
        $column_meta = $stmt->getColumnMeta($index);
        $column_name = scalar_string($column_meta['name'] ?? '');
        if ($column_name !== '') {
            $columns[] = $column_name;
        }
    }

    return $columns;
}

/**
 * @param list<array<string, mixed>> $rows
 * @param list<array<string, mixed>> $rows_to_append
 */
function api_booking_append_rows(array &$rows, array $rows_to_append): void {
    foreach ($rows_to_append as $row_to_append) {
        $rows[] = $row_to_append;
    }
}

/**
 * @param array<string, mixed> $appointment_type
 * @param list<array<string, mixed>> $custom_slot_configs
 * @return list<array<string, mixed>>
 */
function api_booking_reserved_rows_for_schedule_date(
    array $appointment_type,
    string $date,
    string $day_start,
    string $day_end,
    array $custom_slot_configs = []
): array {
    /** @var list<array<string, mixed>> $reserved_rows */
    $reserved_rows = [];
    $appointment_type_id = array_int_value($appointment_type, 'id');
    $schedule_admin_user_id = array_int_value($appointment_type, 'admin_user_id');
    $default_duration = max(1, array_int_value($appointment_type, 'duration_minutes', 60));
    $buffer_before_minutes = max(0, array_int_value($appointment_type, 'buffer_before_minutes', 0));
    $buffer_after_minutes = max(0, array_int_value($appointment_type, 'buffer_after_minutes', 0));

    if ($custom_slot_configs !== []) {
        foreach (api_booking_assoc_rows($custom_slot_configs) as $slot_config) {
            $slot_type = array_string_value($slot_config, 'type', 'point');
            if ($slot_type === 'range') {
                $slot_start = array_string_value($slot_config, 'start');
                $slot_end = array_string_value($slot_config, 'end');
                $range_start_minutes = bdta_booking_time_to_minutes($slot_start);
                $range_end_minutes = bdta_booking_time_to_minutes($slot_end);
                if ($range_start_minutes === null || $range_end_minutes === null || $range_end_minutes <= $range_start_minutes) {
                    continue;
                }

                $reserved_rows[] = [
                    'appointment_date' => $date,
                    'appointment_time' => substr($slot_start, 0, 5),
                    'duration_minutes' => $range_end_minutes - $range_start_minutes,
                    'appointment_type_id' => $appointment_type_id,
                    'b_buffer_before' => $buffer_before_minutes,
                    'b_buffer_after' => $buffer_after_minutes,
                    'pet_count' => 0,
                    'schedule_admin_user_id' => $schedule_admin_user_id,
                ];
                continue;
            }

            $slot_time = array_string_value($slot_config, 'time');
            if (bdta_booking_time_to_minutes($slot_time) === null) {
                continue;
            }

            $reserved_rows[] = [
                'appointment_date' => $date,
                'appointment_time' => substr($slot_time, 0, 5),
                'duration_minutes' => $default_duration,
                'appointment_type_id' => $appointment_type_id,
                'b_buffer_before' => $buffer_before_minutes,
                'b_buffer_after' => $buffer_after_minutes,
                'pet_count' => 0,
                'schedule_admin_user_id' => $schedule_admin_user_id,
            ];
        }

        return $reserved_rows;
    }

    $window_start_minutes = bdta_booking_time_to_minutes($day_start);
    $window_end_minutes = bdta_booking_time_to_minutes($day_end);
    if ($window_start_minutes === null || $window_end_minutes === null || $window_end_minutes <= $window_start_minutes) {
        return [];
    }

    return [[
        'appointment_date' => $date,
        'appointment_time' => substr($day_start, 0, 5),
        'duration_minutes' => $window_end_minutes - $window_start_minutes,
        'appointment_type_id' => $appointment_type_id,
        'b_buffer_before' => $buffer_before_minutes,
        'b_buffer_after' => $buffer_after_minutes,
        'pet_count' => 0,
        'schedule_admin_user_id' => $schedule_admin_user_id,
    ]];
}

/**
 * @return list<array<string, mixed>>
 */
function api_booking_reserved_schedule_rows(
    SafePDO $conn,
    string $from_date,
    string $to_date,
    int $appointment_type_id,
    int $target_admin_user_id
): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date) || $to_date < $from_date) {
        return [];
    }

    $appointment_type_columns = api_booking_table_columns($conn, 'appointment_types');
    $select_map = [
        'id' => 'id',
        'admin_user_id' => in_array('admin_user_id', $appointment_type_columns, true) ? 'admin_user_id' : '0 AS admin_user_id',
        'available_days' => in_array('available_days', $appointment_type_columns, true) ? 'available_days' : "NULL AS available_days",
        'available_start_time' => in_array('available_start_time', $appointment_type_columns, true) ? 'available_start_time' : "'09:00' AS available_start_time",
        'available_end_time' => in_array('available_end_time', $appointment_type_columns, true) ? 'available_end_time' : "'17:00' AS available_end_time",
        'schedule_type' => in_array('schedule_type', $appointment_type_columns, true) ? 'schedule_type' : "'recurring' AS schedule_type",
        'specific_date' => in_array('specific_date', $appointment_type_columns, true) ? 'specific_date' : "NULL AS specific_date",
        'specific_dates' => in_array('specific_dates', $appointment_type_columns, true) ? 'specific_dates' : "NULL AS specific_dates",
        'per_day_schedule' => in_array('per_day_schedule', $appointment_type_columns, true) ? 'per_day_schedule' : "NULL AS per_day_schedule",
        'duration_minutes' => in_array('duration_minutes', $appointment_type_columns, true) ? 'duration_minutes' : '60 AS duration_minutes',
        'buffer_before_minutes' => in_array('buffer_before_minutes', $appointment_type_columns, true) ? 'buffer_before_minutes' : '0 AS buffer_before_minutes',
        'buffer_after_minutes' => in_array('buffer_after_minutes', $appointment_type_columns, true) ? 'buffer_after_minutes' : '0 AS buffer_after_minutes',
    ];
    $has_mini_session_column = in_array('is_mini_session', $appointment_type_columns, true);
    $has_schedule_type_column = in_array('schedule_type', $appointment_type_columns, true);
    $where_clauses = ['is_active = 1', 'id != ?'];
    $params = [$appointment_type_id];
    if ($has_mini_session_column && $has_schedule_type_column) {
        $where_clauses[] = "(is_mini_session = 1 OR schedule_type = 'specific_date')";
    } elseif ($has_mini_session_column) {
        $where_clauses[] = 'is_mini_session = 1';
    } elseif ($has_schedule_type_column) {
        $where_clauses[] = "schedule_type = 'specific_date'";
    } else {
        return [];
    }
    if ($target_admin_user_id > 0 && in_array('admin_user_id', $appointment_type_columns, true)) {
        $where_clauses[] = '(COALESCE(admin_user_id, 0) = 0 OR admin_user_id = ?)';
        $params[] = $target_admin_user_id;
    }

    $stmt = $conn->prepare("
        SELECT " . implode(', ', $select_map) . "
        FROM appointment_types
        WHERE " . implode(' AND ', $where_clauses)
    );
    $stmt->execute($params);
    $reserved_types = api_booking_assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($reserved_types === []) {
        return [];
    }

    /** @var list<array<string, mixed>> $reserved_rows */
    $reserved_rows = [];
    foreach ($reserved_types as $reserved_type) {
        $schedule_type = array_string_value($reserved_type, 'schedule_type', 'recurring');
        if ($schedule_type === 'specific_date') {
            $specific_dates = api_booking_assoc_rows(array_string_value($reserved_type, 'specific_dates'));
            foreach ($specific_dates as $specific_date_entry) {
                $specific_date = array_string_value($specific_date_entry, 'date');
                if ($specific_date === '' || $specific_date < $from_date || $specific_date > $to_date) {
                    continue;
                }

                api_booking_append_rows(
                    $reserved_rows,
                    api_booking_reserved_rows_for_schedule_date(
                        $reserved_type,
                        $specific_date,
                        array_string_value($reserved_type, 'available_start_time', '09:00'),
                        array_string_value($reserved_type, 'available_end_time', '17:00'),
                        api_booking_assoc_rows($specific_date_entry['timeslots'] ?? [])
                    )
                );
            }

            if ($specific_dates === []) {
                $legacy_specific_date = array_string_value($reserved_type, 'specific_date');
                if ($legacy_specific_date !== '' && $legacy_specific_date >= $from_date && $legacy_specific_date <= $to_date) {
                    api_booking_append_rows(
                        $reserved_rows,
                        api_booking_reserved_rows_for_schedule_date(
                            $reserved_type,
                            $legacy_specific_date,
                            array_string_value($reserved_type, 'available_start_time', '09:00'),
                            array_string_value($reserved_type, 'available_end_time', '17:00')
                        )
                    );
                }
            }

            continue;
        }

        $available_days = api_booking_int_list(decode_json_assoc(array_string_value($reserved_type, 'available_days', '[0,1,2,3,4,5,6]')));
        if ($available_days === []) {
            $available_days = [0, 1, 2, 3, 4, 5, 6];
        }
        $per_day_schedule = api_booking_assoc_map(array_string_value($reserved_type, 'per_day_schedule'));

        $current_date = new DateTime($from_date);
        $end_date = new DateTime($to_date);
        while ($current_date <= $end_date) {
            $check_date = $current_date->format('Y-m-d');
            $day_of_week = (int) $current_date->format('w');
            if (!in_array($day_of_week, $available_days, true)) {
                $current_date->modify('+1 day');
                continue;
            }

            $day_start = array_string_value($reserved_type, 'available_start_time', '09:00');
            $day_end = array_string_value($reserved_type, 'available_end_time', '17:00');
            $day_config_key = (string) $day_of_week;
            if (array_key_exists($day_config_key, $per_day_schedule)) {
                $day_config = $per_day_schedule[$day_config_key];
                $override_start = array_string_value($day_config, 'start');
                $override_end = array_string_value($day_config, 'end');
                if ($override_start !== '' && $override_end !== '' && $override_start < $override_end) {
                    $day_start = $override_start;
                    $day_end = $override_end;
                }
            }

            api_booking_append_rows(
                $reserved_rows,
                api_booking_reserved_rows_for_schedule_date($reserved_type, $check_date, $day_start, $day_end)
            );
            $current_date->modify('+1 day');
        }
    }

    return $reserved_rows;
}

/**
 * @param list<array<string, mixed>> $rows
 */
function api_booking_slot_conflicts_with_rows(
    array $rows,
    string $appointment_time,
    int $duration_minutes,
    int $buffer_before_minutes,
    int $buffer_after_minutes
): bool {
    $proposed_start_minutes = bdta_booking_time_to_minutes($appointment_time);
    if ($proposed_start_minutes === null) {
        return false;
    }

    foreach (api_booking_assoc_rows($rows) as $row) {
        $existing_start_minutes = bdta_booking_time_to_minutes(array_string_value($row, 'appointment_time'));
        if ($existing_start_minutes === null) {
            continue;
        }

        if (bdta_booking_windows_overlap(
            $proposed_start_minutes,
            $duration_minutes,
            $buffer_before_minutes,
            $buffer_after_minutes,
            $existing_start_minutes,
            max(1, array_int_value($row, 'duration_minutes', 60)),
            max(0, array_int_value($row, 'b_buffer_before', 0)),
            max(0, array_int_value($row, 'b_buffer_after', 0))
        )) {
            return true;
        }
    }

    return false;
}

/** Serialize website schedule checks and writes on the existing MySQL database. */
final class BookingScheduleLock {
    private ?string $name = null;
    public function __construct(private SafePDO $conn) {
        // SQLite is used only by disposable regression fixtures; production requires MySQL.
        if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return;
        }
        $database = scalar_string($conn->query('SELECT DATABASE()')->fetchColumn());
        if ($database === '') {
            throw new RuntimeException('Booking availability could not be checked. Please try again.');
        }
        $name = 'bdta_booking_schedule_' . sha1($database);
        $stmt = $conn->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([$name]);
        if (safe_int($stmt->fetchColumn()) !== 1) {
            throw new RuntimeException('Booking availability is being updated. Please try again.');
        }
        $this->name = $name;
    }
    public function release(): void {
        if ($this->name === null) { return; }
        $stmt = $this->conn->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$this->name]);
        $this->name = null;
    }
    public function __destruct() {
        // An early endpoint exit also closes the connection and releases MySQL locks.
        try { $this->release(); } catch (Throwable $e) { error_log('Booking schedule lock release failed.'); }
    }
}

/**
 * Expand the same configured recurring/per-day/specific-date slot grid used by the UI.
 * @param array<string, mixed> $appointment_type
 * @return list<string>
 */
function bdta_booking_candidate_slots(array $appointment_type, string $date): array {
    $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    if ($parsed_date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $parsed_date->format('Y-m-d') !== $date) {
        return [];
    }
    $today = new DateTimeImmutable('today');
    $min_days = max(0, array_int_value($appointment_type, 'advance_booking_min_days'));
    $max_days = max(1, array_int_value($appointment_type, 'advance_booking_max_days', 365));
    if ($parsed_date < $today->modify('+' . $min_days . ' days') || $parsed_date > $today->modify('+' . $max_days . ' days')) {
        return [];
    }
    $schedule_type = array_string_value($appointment_type, 'schedule_type', 'recurring');
    $custom_slots = [];
    $start = array_string_value($appointment_type, 'available_start_time', '09:00');
    $end = array_string_value($appointment_type, 'available_end_time', '17:00');
    if ($schedule_type === 'specific_date') {
        $specific_dates = api_booking_assoc_rows(array_string_value($appointment_type, 'specific_dates'));
        if ($specific_dates !== []) {
            $matched = false;
            foreach ($specific_dates as $entry) {
                if (array_string_value($entry, 'date') === $date) {
                    $custom_slots = api_booking_assoc_rows($entry['timeslots'] ?? []);
                    $matched = true;
                    break;
                }
            }
            if (!$matched) { return []; }
        } elseif (array_string_value($appointment_type, 'specific_date') !== $date) {
            return [];
        }
    } elseif ($schedule_type === 'recurring') {
        $days = api_booking_int_list(decode_json_assoc(array_string_value($appointment_type, 'available_days')));
        if ($days === []) { $days = [0, 1, 2, 3, 4, 5, 6]; }
        $day = (int) $parsed_date->format('w');
        if (!in_array($day, $days, true)) { return []; }
        $per_day = api_booking_assoc_map(array_string_value($appointment_type, 'per_day_schedule'));
        $override = $per_day[(string) $day] ?? [];
        $override_start = array_string_value($override, 'start');
        $override_end = array_string_value($override, 'end');
        if ($override_start !== '' && $override_end !== '' && $override_start < $override_end) {
            $start = $override_start;
            $end = $override_end;
        }
    } else {
        return [];
    }
    $interval = max(1, array_int_value($appointment_type, 'time_slot_interval', 30));
    $minutes = [];
    if ($custom_slots !== []) {
        foreach ($custom_slots as $config) {
            if (array_string_value($config, 'type', 'point') === 'point') {
                $point = bdta_booking_time_to_minutes(array_string_value($config, 'time'));
                if ($point !== null) { $minutes[] = $point; }
            } elseif (array_string_value($config, 'type') === 'range') {
                $range_start = bdta_booking_time_to_minutes(array_string_value($config, 'start'));
                $range_end = bdta_booking_time_to_minutes(array_string_value($config, 'end'));
                if ($range_start !== null && $range_end !== null) {
                    for ($minute = $range_start; $minute < $range_end; $minute += $interval) { $minutes[] = $minute; }
                }
            }
        }
    } else {
        $range_start = bdta_booking_time_to_minutes($start);
        $range_end = bdta_booking_time_to_minutes($end);
        if ($range_start !== null && $range_end !== null) {
            for ($minute = $range_start; $minute < $range_end; $minute += $interval) { $minutes[] = $minute; }
        }
    }
    $minutes = array_values(array_unique($minutes));
    sort($minutes);
    $slots = [];
    foreach ($minutes as $minute) {
        $slot = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
        if (safe_timestamp(strtotime($date . ' ' . $slot)) > time()) { $slots[] = $slot; }
    }
    return $slots;
}

/**
 * @param array<int, array{start: string, end: string}> $busy_periods
 */
function bdta_booking_slot_passes_calendar(string $date, string $time, int $duration, int $before, int $after, array $busy_periods): bool {
    $start = safe_timestamp(strtotime($date . ' ' . $time)) - max(0, $before) * 60;
    $end = safe_timestamp(strtotime($date . ' ' . $time)) + (max(1, $duration) + max(0, $after)) * 60;
    foreach ($busy_periods as $busy) {
        $busy_start = strtotime($busy['start']);
        $busy_end = strtotime($busy['end']);
        if ($busy_start !== false && $busy_end !== false && $start < $busy_end && $busy_start < $end) { return false; }
    }
    return true;
}

/** @return array{date: string, available_slots: list<string>, google_calendar_checked: bool} */
function bdta_booking_available_slots(SafePDO $conn, int $appointment_type_id, string $date, int $exclude_booking_id = 0, int $requested_units = 1, bool $respect_google_calendar = true, ?int $persisted_duration = null, ?int $persisted_admin = null): array {
    $result = ['date'=>$date, 'available_slots'=>[], 'google_calendar_checked'=>false];
    $stmt = $conn->prepare('SELECT * FROM appointment_types WHERE id = ? AND is_active = 1');
    $stmt->execute([$appointment_type_id]);
    $appointment_type = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($appointment_type === []) { return $result; }
    $candidates = bdta_booking_candidate_slots($appointment_type, $date);
    if ($candidates === []) { return $result; }
    $duration = max(1, $persisted_duration ?? array_int_value($appointment_type, 'duration_minutes', 60));
    $before = max(0, array_int_value($appointment_type, 'buffer_before_minutes'));
    $after = max(0, array_int_value($appointment_type, 'buffer_after_minutes'));
    $admin = $persisted_admin ?? array_int_value($appointment_type, 'admin_user_id');
    $resource = bdta_booking_resource_config($appointment_type);
    $stmt = $conn->prepare("SELECT b.id, b.google_event_id, b.appointment_time, b.duration_minutes, b.appointment_type_id,
        COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before, COALESCE(at.buffer_after_minutes, 0) AS b_buffer_after,
        COALESCE(apc.pet_count, 0) AS pet_count, COALESCE(b.admin_user_id, at.admin_user_id, 0) AS schedule_admin_user_id
        FROM bookings b LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        LEFT JOIN (SELECT booking_id, COUNT(*) AS pet_count FROM appointment_pets GROUP BY booking_id) apc ON apc.booking_id = b.id
        WHERE b.appointment_date = ? AND b.status != 'cancelled' AND b.id != ?");
    $stmt->execute([$date, $exclude_booking_id]);
    $bookings = api_booking_filter_schedule_rows(assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC)), $admin);
    $reserved = api_booking_reserved_schedule_rows($conn, $date, $date, $appointment_type_id, $admin);
    $busy = [];
    if ($respect_google_calendar && GoogleCalendarIntegration::isOAuthConfigured()) {
        try {
            $calendar_admin = $admin > 0 ? $admin : GoogleCalendarIntegration::getAnyConnectedOAuthAdminUserId();
            if ($calendar_admin > 0) {
                // The database already accounts for managed bookings. Exclude only
                // identified events that may share this type's capacity or are moving.
                $excluded_events = [];
                if ($exclude_booking_id > 0) {
                    $stmt = $conn->prepare('SELECT google_event_id FROM bookings WHERE id = ?');
                    $stmt->execute([$exclude_booking_id]);
                    $event_id = scalar_string($stmt->fetchColumn());
                    if ($event_id !== '') { $excluded_events[] = $event_id; }
                }
                if (!empty($appointment_type['is_group_class']) || !empty($resource['enabled'])) {
                    foreach ($bookings as $booking) {
                        if (array_int_value($booking, 'appointment_type_id') === $appointment_type_id) {
                            $event_id = array_string_value($booking, 'google_event_id');
                            if ($event_id !== '') { $excluded_events[] = $event_id; }
                        }
                    }
                }
                $busy = GoogleCalendarIntegration::getFreeBusy($date, $calendar_admin, $excluded_events);
                $result['google_calendar_checked'] = true;
            }
        } catch (Exception $e) { error_log('Booking availability calendar check failed: ' . $e->getMessage()); }
    }
    foreach ($candidates as $slot) {
        $usage = bdta_booking_slot_usage_summary($bookings, $slot, $duration, $before, $after, $resource, $appointment_type_id);
        if (api_booking_slot_conflicts_with_rows($reserved, $slot, $duration, $before, $after)) { continue; }
        if (!empty($appointment_type['is_group_class'])) {
            if ($usage['exact_type_slot_count'] >= max(1, array_int_value($appointment_type, 'max_participants', 1))) { continue; }
        } elseif (!empty($resource['enabled'])) {
            // This type's resource bookings share its configured capacity. Other
            // appointment types still occupy the assigned trainer's schedule.
            $other_types = array_values(array_filter($bookings, static fn(array $row): bool => array_int_value($row, 'appointment_type_id') !== $appointment_type_id));
            if (api_booking_slot_conflicts_with_rows($other_types, $slot, $duration, $before, $after)) { continue; }
        } elseif ($usage['has_overlap_conflict']) { continue; }
        if (!empty($resource['enabled']) && !bdta_booking_resource_capacity_available($resource, $usage['overlapping_resource_units'], $requested_units)) { continue; }
        if (!bdta_booking_slot_passes_calendar($date, $slot, $duration, $before, $after, $busy)) { continue; }
        $result['available_slots'][] = $slot;
    }
    return $result;
}

function bdta_booking_slot_error(SafePDO $conn, int $appointment_type_id, string $date, string $time, int $exclude_booking_id = 0, int $requested_units = 1, ?int $persisted_duration = null, ?int $persisted_admin = null): ?string {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/D', $time)) {
        return 'Invalid appointment time. Please choose an available time.';
    }
    $available = bdta_booking_available_slots($conn, $appointment_type_id, $date, $exclude_booking_id, $requested_units, true, $persisted_duration, $persisted_admin);
    return in_array(substr($time, 0, 5), $available['available_slots'], true)
        ? null : 'That time slot is not available. Please choose another time.';
}
