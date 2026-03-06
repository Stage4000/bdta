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

// $api_result is set to a JSON string on the happy path.
// The shutdown function falls back to an empty-events response if it is still null
// (e.g. because die() was called during DB init before we could set it).
$api_result = null;

register_shutdown_function(function () use (&$api_result) {
    ob_end_clean(); // Discard any non-JSON output (DB init errors, PHP notices, etc.)
    if (!headers_sent()) {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=60');
    }
    echo $api_result !== null ? $api_result : json_encode(['events' => []]);
});

try {
    $db   = new Database();
    $conn = $db->getConnection();
    $today = date('Y-m-d');

    // Fetch active group-class and mini-session appointment types that have a specific date
    $stmt = $conn->prepare("
        SELECT id, name, description, default_amount,
               duration_minutes, time_slot_interval,
               schedule_type, specific_date,
               available_start_time, available_end_time,
               is_group_class, max_participants,
               is_mini_session, mini_session_location, mini_session_topic,
               unique_link
        FROM appointment_types
        WHERE is_active = 1
          AND (is_group_class = 1 OR is_mini_session = 1)
          AND schedule_type = 'specific_date'
          AND specific_date >= ?
        ORDER BY specific_date ASC, name ASC
    ");
    $stmt->execute([$today]);
    $types = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $base_url = getDynamicBaseUrl();
    $events   = [];

    foreach ($types as $type) {
        $date     = $type['specific_date'];
        $duration = max(1, (int)($type['duration_minutes'] ?? 60));
        $interval = max(1, (int)($type['time_slot_interval'] ?? 30));
        $is_group = !empty($type['is_group_class']);
        $max_part = max(1, (int)($type['max_participants'] ?? 1));
        $type_id  = (int)$type['id'];

        // Parse times to minutes with safe fallback
        $start_parts = array_pad(explode(':', $type['available_start_time'] ?? '09:00'), 2, '0');
        $end_parts   = array_pad(explode(':', $type['available_end_time']   ?? '17:00'), 2, '0');
        list($sh, $sm) = array_map('intval', $start_parts);
        list($eh, $em) = array_map('intval', $end_parts);
        $start_minutes = $sh * 60 + $sm;
        $end_minutes   = $eh * 60 + $em;

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
            $slot_key = substr($bk_time, 0, 5);
            $slot_counts[$slot_key] = ($slot_counts[$slot_key] ?? 0) + 1;
        }

        // Check whether at least one slot is available
        $any_available = false;
        $total_slots   = 0;
        for ($t = $start_minutes; $t < $end_minutes; $t += $interval) {
            // Skip slots where the appointment would extend past end time
            if ($t + $duration > $end_minutes) {
                break;
            }
            $total_slots++;
            $slot_str    = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
            $slot_booked = $slot_counts[$slot_str] ?? 0;
            $capacity    = $is_group ? $max_part : 1;
            if ($slot_booked < $capacity) {
                $any_available = true;
                break;
            }
        }

        // Build booking URL using unique_link if available
        $booking_url = null;
        if (!empty($type['unique_link'])) {
            $booking_url = $base_url . '/backend/public/book.php?link=' . rawurlencode($type['unique_link']);
        }

        $event = [
            'id'               => $type_id,
            'name'             => $type['name'],
            'description'      => $type['description'],
            'price'            => (float)($type['default_amount'] ?? 0),
            'date'             => $date,
            'start_time'       => $type['available_start_time'] ?? '09:00',
            'end_time'         => $type['available_end_time']   ?? '17:00',
            'duration_minutes' => $duration,
            'fully_booked'     => ($total_slots > 0 && !$any_available),
            'booking_url'      => $booking_url,
        ];

        if ($is_group) {
            $event['type']             = 'group_class';
            $event['max_participants'] = $max_part;
        } else {
            $event['type']     = 'mini_session';
            $event['location'] = $type['mini_session_location'];
            $event['topic']    = $type['mini_session_topic'];
        }

        $events[] = $event;
    }

    $api_result = json_encode(['events' => $events]);
} catch (Throwable $e) {
    $api_result = json_encode(['events' => []]);
}

