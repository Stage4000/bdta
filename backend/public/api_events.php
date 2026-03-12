<?php
/**
 * Brook's Dog Training Academy - Public Events API
 * Returns upcoming group-class and mini-session events with availability status.
 * Only events with a specific date on or after today are returned.
 * No authentication required.
 */

// Buffer ALL output so that die() messages from database initialisation never
// leak into the response body as non-JSON text.
ob_start();

require_once __DIR__ . '/../includes/config.php';

/**
 * @return list<array<string, mixed>>
 */
function public_event_assoc_rows(mixed $value): array {
    if (is_string($value)) {
        return decode_json_assoc_list($value);
    }
    if (!is_array($value)) {
        return [];
    }

    $rows = [];
    foreach ($value as $item) {
        if (is_array($item)) {
            $rows[] = $item;
        }
    }
    return $rows;
}

// Default to an empty-events response so shutdown still returns valid JSON
// if die() is called during DB init before the happy-path assignment runs.
$api_result = json_encode(['events' => []]);

register_shutdown_function(function () use (&$api_result) {
    ob_end_clean(); // Discard any non-JSON output (DB init errors, PHP notices, etc.)
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=60');
    }
    echo $api_result;
});

try {
    $db   = new Database();
    $conn = $db->getConnection();
    $today = date('Y-m-d');

    // Fetch active group-class and mini-session appointment types with specific date scheduling
    $stmt = $conn->prepare("
        SELECT id, name, description, default_amount,
               duration_minutes, time_slot_interval,
               schedule_type, specific_date, specific_dates,
               available_start_time, available_end_time,
               is_group_class, max_participants, group_class_location,
               is_mini_session, mini_session_location, mini_session_topic,
               unique_link
        FROM appointment_types
        WHERE is_active = 1
          AND (is_group_class = 1 OR is_mini_session = 1)
          AND schedule_type = 'specific_date'
        ORDER BY name ASC
    ");
    $stmt->execute();
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $base_url = getDynamicBaseUrl();
    $events   = [];

    foreach ($types as $type) {
        $duration = max(1, array_int_value($type, 'duration_minutes', 60));
        $interval = max(1, array_int_value($type, 'time_slot_interval', 30));
        $is_group = !empty($type['is_group_class']);
        $max_part = max(1, array_int_value($type, 'max_participants', 1));
        $type_id  = array_int_value($type, 'id');

        // Build the list of dates to generate events for.
        // Prefer multi-date specific_dates; fall back to legacy specific_date.
        $dates_to_process = [];
        foreach (public_event_assoc_rows(array_string_value($type, 'specific_dates')) as $entry) {
            $d = array_string_value($entry, 'date');
            if ($d >= $today) {
                $dates_to_process[] = ['date' => $d, 'timeslots' => public_event_assoc_rows($entry['timeslots'] ?? [])];
            }
        }
        $specific_date = array_string_value($type, 'specific_date');
        if (empty($dates_to_process) && $specific_date !== '' && $specific_date >= $today) {
            $dates_to_process[] = ['date' => $specific_date, 'timeslots' => []];
        }

        // Build booking URL using unique_link if available
        $booking_url = null;
        $unique_link = array_string_value($type, 'unique_link');
        if ($unique_link !== '') {
            $booking_url = $base_url . '/backend/public/book.php?link=' . rawurlencode($unique_link);
        }

        foreach ($dates_to_process as $date_entry) {
            $date = array_string_value($date_entry, 'date');
            $custom_slots = public_event_assoc_rows($date_entry['timeslots']);

            // Build candidate slot minutes for this date
            $candidate_minutes = [];
            if (!empty($custom_slots)) {
                foreach ($custom_slots as $cfg) {
                    $slot_type = array_string_value($cfg, 'type', 'point');
                    $time_value = array_string_value($cfg, 'time');
                    $start_value = array_string_value($cfg, 'start');
                    $end_value = array_string_value($cfg, 'end');
                    if ($slot_type === 'point' && $time_value !== '') {
                        $p = explode(':', $time_value);
                        if (count($p) === 2) {
                            $candidate_minutes[] = (int)$p[0] * 60 + (int)$p[1];
                        }
                    } elseif ($slot_type === 'range' && $start_value !== '' && $end_value !== '') {
                        $sp = explode(':', $start_value);
                        $ep = explode(':', $end_value);
                        if (count($sp) === 2 && count($ep) === 2) {
                            $rs = (int)$sp[0] * 60 + (int)$sp[1];
                            $re = (int)$ep[0] * 60 + (int)$ep[1];
                            for ($m = $rs; $m < $re; $m += $interval) {
                                $candidate_minutes[] = $m;
                            }
                        }
                    }
                }
                $candidate_minutes = array_values(array_unique($candidate_minutes));
                sort($candidate_minutes);
            } else {
                // Use global start/end
                $start_parts = array_pad(explode(':', array_string_value($type, 'available_start_time', '09:00')), 2, '0');
                $end_parts   = array_pad(explode(':', array_string_value($type, 'available_end_time', '17:00')), 2, '0');
                list($sh, $sm) = array_map('intval', $start_parts);
                list($eh, $em) = array_map('intval', $end_parts);
                $start_minutes = $sh * 60 + $sm;
                $end_minutes   = $eh * 60 + $em;
                for ($t = $start_minutes; $t < $end_minutes; $t += $interval) {
                    $candidate_minutes[] = $t;
                }
            }

            // Fetch bookings for this date and appointment type
            $bk_stmt = $conn->prepare("
                SELECT appointment_time
                FROM bookings
                WHERE appointment_date = ?
                  AND appointment_type_id = ?
                  AND status != 'cancelled'
            ");
            $bk_stmt->execute([$date, $type_id]);
            $bookings = $bk_stmt->fetchAll(PDO::FETCH_COLUMN);

            // Count bookings per time slot
            $slot_counts = [];
            foreach ($bookings as $bk_time) {
                $slot_key = substr(scalar_string($bk_time), 0, 5);
                $slot_counts[$slot_key] = ($slot_counts[$slot_key] ?? 0) + 1;
            }

            // Check whether at least one slot is available
            $any_available = false;
            $total_slots   = count($candidate_minutes);
            foreach ($candidate_minutes as $t) {
                $slot_str    = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
                $slot_booked = $slot_counts[$slot_str] ?? 0;
                $capacity    = $is_group ? $max_part : 1;
                if ($slot_booked < $capacity) {
                    $any_available = true;
                    break;
                }
            }

            // Determine display start/end times for this date
            if (!empty($custom_slots) && !empty($candidate_minutes)) {
                $disp_start   = sprintf('%02d:%02d', intdiv(min($candidate_minutes), 60), min($candidate_minutes) % 60);
                $end_minutes  = max($candidate_minutes) + $duration;
                // Clamp to 23:59 in case the slot extends past midnight
                $end_minutes  = min($end_minutes, 23 * 60 + 59);
                $disp_end     = sprintf('%02d:%02d', intdiv($end_minutes, 60), $end_minutes % 60);
            } else {
                $disp_start = array_string_value($type, 'available_start_time', '09:00');
                $disp_end   = array_string_value($type, 'available_end_time', '17:00');
            }

            $event = [
                'id'               => $type_id,
                'name'             => array_string_value($type, 'name'),
                'description'      => array_string_value($type, 'description'),
                'price'            => safe_float($type['default_amount'] ?? 0),
                'date'             => $date,
                'start_time'       => $disp_start,
                'end_time'         => $disp_end,
                'duration_minutes' => $duration,
                'fully_booked'     => ($total_slots > 0 && !$any_available),
                'booking_url'      => $booking_url,
            ];

            if ($is_group) {
                $event['type']             = 'group_class';
                $event['max_participants'] = $max_part;
                $event['location']         = array_string_value($type, 'group_class_location');
            } else {
                $event['type']     = 'mini_session';
                $event['location'] = array_string_value($type, 'mini_session_location');
                $event['topic']    = array_string_value($type, 'mini_session_topic');
            }

            $events[] = $event;
        }
    }

    // Sort events by date ascending
    usort($events, fn($a, $b) => $a['date'] <=> $b['date']);

    $api_result = json_encode(['events' => $events]);
} catch (Throwable $e) {
    $api_result = json_encode(['events' => []]);
}
