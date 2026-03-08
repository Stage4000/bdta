<?php
require_once '../includes/config.php';
require_once '../includes/email_service.php';
require_once '../includes/google_calendar.php';
require_once '../includes/workflow_helper.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'credits') {
    // Check available credits for a client email + appointment type
    $email = $_GET['email'] ?? '';
    $appointment_type_id = isset($_GET['appointment_type_id']) ? (int)$_GET['appointment_type_id'] : 0;

    if (!$email || !$appointment_type_id) {
        echo json_encode(['credits' => []]);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Look up client by email
    $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
    $stmt->execute([$email]);
    $client_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client_row) {
        echo json_encode(['credits' => []]);
        exit;
    }

    $client_id = (int)$client_row['id'];

    // Fetch active, non-expired package credits for this client + appointment type
    $stmt = $conn->prepare("
        SELECT cpc.id, cpc.client_package_id,
               (cpc.total_credits - cpc.used_credits) AS remaining,
               cp.package_name
        FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ?
          AND cpc.appointment_type_id = ?
          AND (cpc.total_credits - cpc.used_credits) > 0
          AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
        ORDER BY cp.expires_at ASC
    ");
    $stmt->execute([$client_id, $appointment_type_id]);
    $credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['credits' => $credits]);
    exit;

} elseif ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'available_dates') {

    /**
     * Helper: returns true if the given slot does NOT conflict with any GCal busy period.
     *
     * @param string $date          YYYY-MM-DD
     * @param string $slot_str      HH:MM
     * @param int    $duration_min  appointment duration in minutes
     * @param int    $buf_before    buffer before in minutes
     * @param int    $buf_after     buffer after in minutes
     * @param array  $busy_periods  flat array of ['start'=>RFC3339, 'end'=>RFC3339]
     */
    function ad_slot_passes_gcal(string $date, string $slot_str, int $duration_min, int $buf_before, int $buf_after, array $busy_periods): bool {
        $slot_ts    = strtotime($date . 'T' . $slot_str . ':00');
        $buf_s_ts   = $slot_ts - $buf_before * 60;
        $buf_e_ts   = $slot_ts + ($duration_min + $buf_after) * 60;
        foreach ($busy_periods as $busy) {
            if (empty($busy['start']) || empty($busy['end'])) continue;
            $bs = strtotime($busy['start']);
            $be = strtotime($busy['end']);
            if ($bs === false || $be === false) continue;
            if ($buf_s_ts < $be && $bs < $buf_e_ts) {
                return false; // conflict
            }
        }
        return true;
    }

    // Return a list of dates (within a given range) that have at least one available slot.
    // Used by the booking UI to hide dates with no availability from the date selector.
    $appointment_type_id = isset($_GET['appointment_type_id']) ? (int)$_GET['appointment_type_id'] : 0;
    $from_date = $_GET['from'] ?? date('Y-m-d');
    $to_date   = $_GET['to']   ?? date('Y-m-d', strtotime('+60 days'));

    // Sanitize date params
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
        $from_date = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
        $to_date = date('Y-m-d', strtotime('+60 days'));
    }
    // Enforce minimum of today and cap range at 365 days
    $today_str = date('Y-m-d');
    if ($from_date < $today_str) {
        $from_date = $today_str;
    }
    $max_to = date('Y-m-d', strtotime($from_date . ' +365 days'));
    if ($to_date > $max_to) {
        $to_date = $max_to;
    }
    if ($to_date < $from_date) {
        $to_date = $from_date;
    }

    if (!$appointment_type_id) {
        echo json_encode(['available_dates' => [], 'schedule_type' => 'recurring']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT available_days, available_start_time, available_end_time, time_slot_interval,
               schedule_type, specific_date, specific_dates, per_day_schedule,
               duration_minutes, is_group_class, max_participants,
               buffer_before_minutes, buffer_after_minutes,
               advance_booking_min_days, advance_booking_max_days
        FROM appointment_types
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$appointment_type_id]);
    $appt_type = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appt_type) {
        echo json_encode(['available_dates' => [], 'schedule_type' => 'recurring']);
        exit;
    }

    $ad_schedule_type   = $appt_type['schedule_type'] ?? 'recurring';
    $ad_available_days  = json_decode($appt_type['available_days'] ?? '[0,1,2,3,4,5,6]', true);
    if (!is_array($ad_available_days)) {
        $ad_available_days = [0, 1, 2, 3, 4, 5, 6];
    }
    $ad_start_time     = $appt_type['available_start_time'] ?? '09:00';
    $ad_end_time       = $appt_type['available_end_time']   ?? '17:00';
    $ad_interval       = max(1, (int)($appt_type['time_slot_interval'] ?? 30)); // guard against 0
    $ad_duration       = (int)($appt_type['duration_minutes']   ?? 60);
    $ad_is_group       = !empty($appt_type['is_group_class']);
    $ad_max_part       = max(1, (int)($appt_type['max_participants'] ?? 1));
    $ad_buf_before     = max(0, (int)($appt_type['buffer_before_minutes'] ?? 0));
    $ad_buf_after      = max(0, (int)($appt_type['buffer_after_minutes']  ?? 0));
    $ad_per_day        = !empty($appt_type['per_day_schedule'])
                         ? json_decode($appt_type['per_day_schedule'], true)
                         : null;
    // Advance-booking window: honour the appointment type's min/max booking lead time
    $ad_min_days       = max(0, (int)($appt_type['advance_booking_min_days'] ?? 0));
    $ad_max_days       = max(1, (int)($appt_type['advance_booking_max_days'] ?? 365));

    // Tighten from_date by the minimum advance notice (e.g. min_days=1 → earliest is tomorrow)
    $advance_min_from = date('Y-m-d', strtotime($today_str . ' +' . $ad_min_days . ' days'));
    if ($from_date < $advance_min_from) {
        $from_date = $advance_min_from;
    }
    // Cap to_date by the maximum booking window
    $advance_max_to = date('Y-m-d', strtotime($today_str . ' +' . $ad_max_days . ' days'));
    if ($to_date > $advance_max_to) {
        $to_date = $advance_max_to;
    }
    if ($to_date < $from_date) {
        echo json_encode(['available_dates' => [], 'schedule_type' => $ad_schedule_type]);
        exit;
    }

    // Build the list of candidate dates to evaluate
    $candidate_dates = [];
    if ($ad_schedule_type === 'specific_date') {
        // Only check the configured specific dates that fall in the requested range
        $raw_sd = $appt_type['specific_dates'] ?? null;
        if (!empty($raw_sd)) {
            $sd_arr = json_decode($raw_sd, true);
            if (is_array($sd_arr)) {
                foreach ($sd_arr as $sd_entry) {
                    $d = $sd_entry['date'] ?? '';
                    if ($d >= $from_date && $d <= $to_date) {
                        $candidate_dates[] = $d;
                    }
                }
            }
        } elseif (!empty($appt_type['specific_date'])) {
            $d = $appt_type['specific_date'];
            if ($d >= $from_date && $d <= $to_date) {
                $candidate_dates[] = $d;
            }
        }
        sort($candidate_dates);
    } else {
        // Recurring: check every date in the range that falls on an allowed day of week
        $cur = new DateTime($from_date);
        $end = new DateTime($to_date);
        while ($cur <= $end) {
            if (in_array((int)$cur->format('w'), $ad_available_days)) {
                $candidate_dates[] = $cur->format('Y-m-d');
            }
            $cur->modify('+1 day');
        }
    }

    // Pre-fetch all bookings for the entire range in one query for efficiency
    $stmt = $conn->prepare("
        SELECT b.appointment_date, b.appointment_time, b.duration_minutes, b.appointment_type_id,
               COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before,
               COALESCE(at.buffer_after_minutes,  0) AS b_buffer_after
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        WHERE b.appointment_date BETWEEN ? AND ? AND b.status != 'cancelled'
    ");
    $stmt->execute([$from_date, $to_date]);
    $all_bookings_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group bookings by date
    $bookings_by_date = [];
    foreach ($all_bookings_rows as $row) {
        $bookings_by_date[$row['appointment_date']][] = $row;
    }

    // Build specific_dates config map (for specific_date type custom timeslots)
    $specific_dates_config = [];
    if ($ad_schedule_type === 'specific_date' && !empty($appt_type['specific_dates'])) {
        $sd_arr = json_decode($appt_type['specific_dates'], true);
        if (is_array($sd_arr)) {
            foreach ($sd_arr as $sd_entry) {
                if (!empty($sd_entry['date'])) {
                    $specific_dates_config[$sd_entry['date']] = $sd_entry['timeslots'] ?? null;
                }
            }
        }
    }

    // Pre-fetch Google Calendar busy periods for the entire range in ONE API call.
    // Mirrors the per-slot GCal check already done in the single-date slot endpoint,
    // so that dates blocked only by GCal events are correctly marked unavailable here too.
    $gcal_busy_periods = [];
    if (GoogleCalendarIntegration::isOAuthConfigured()) {
        try {
            $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id LIMIT 1");
            if ($admin_row = $stmt_admins->fetch(PDO::FETCH_ASSOC)) {
                $gcal_busy_periods = GoogleCalendarIntegration::getFreeBusyRange(
                    $from_date, $to_date, (int)$admin_row['admin_user_id']
                );
            }
        } catch (Exception $e) {
            error_log('api_bookings available_dates: GCal free/busy range check failed: ' . $e->getMessage());
        }
    }

    // Evaluate each candidate date: does it have at least one available slot?
    $available_dates = [];
    foreach ($candidate_dates as $check_date) {
        $existing_bookings = $bookings_by_date[$check_date] ?? [];

        // Pre-compute booking counts per slot for group classes to avoid O(n²) in the slot loop
        $group_slot_counts = [];
        if ($ad_is_group) {
            foreach ($existing_bookings as $bk) {
                $bt = substr($bk['appointment_time'], 0, 5);
                if ((int)$bk['appointment_type_id'] === $appointment_type_id) {
                    $group_slot_counts[$bt] = ($group_slot_counts[$bt] ?? 0) + 1;
                }
            }
        }

        // Determine timeslot config for this date
        $custom_slots = null;
        if ($ad_schedule_type === 'specific_date') {
            $custom_slots = $specific_dates_config[$check_date] ?? null;
        }

        // Determine start/end times (with per-day override for recurring)
        $day_start = $ad_start_time;
        $day_end   = $ad_end_time;
        if ($ad_schedule_type !== 'specific_date' && is_array($ad_per_day)) {
            $dow = (int)(new DateTime($check_date))->format('w');
            if (isset($ad_per_day[$dow])) {
                $ds = $ad_per_day[$dow]['start'] ?? '';
                $de = $ad_per_day[$dow]['end']   ?? '';
                if (!empty($ds) && !empty($de) && $ds < $de) {
                    $day_start = $ds;
                    $day_end   = $de;
                }
            }
        }

        // Build candidate slot minutes for this date
        $cand_mins = [];
        if (!empty($custom_slots)) {
            foreach ($custom_slots as $cfg) {
                $slot_type = $cfg['type'] ?? 'point';
                if ($slot_type === 'point' && !empty($cfg['time'])) {
                    $p = explode(':', $cfg['time']);
                    if (count($p) === 2) {
                        $cand_mins[] = (int)$p[0] * 60 + (int)$p[1];
                    }
                } elseif ($slot_type === 'range' && !empty($cfg['start']) && !empty($cfg['end'])) {
                    $sp = explode(':', $cfg['start']);
                    $ep = explode(':', $cfg['end']);
                    if (count($sp) === 2 && count($ep) === 2) {
                        $rs = (int)$sp[0] * 60 + (int)$sp[1];
                        $re = (int)$ep[0] * 60 + (int)$ep[1];
                        for ($m = $rs; $m < $re; $m += $ad_interval) {
                            $cand_mins[] = $m;
                        }
                    }
                }
            }
            $cand_mins = array_values(array_unique($cand_mins));
            sort($cand_mins);
        } else {
            $sp = explode(':', $day_start);
            $ep = explode(':', $day_end);
            $sm = (int)$sp[0] * 60 + (int)$sp[1];
            $em = (int)$ep[0] * 60 + (int)$ep[1];
            for ($m = $sm; $m < $em; $m += $ad_interval) {
                $cand_mins[] = $m;
            }
        }

        // Check if any candidate slot is free
        $has_available = false;
        foreach ($cand_mins as $tm) {
            $hour         = intdiv($tm, 60);
            $min          = $tm % 60;
            $slot_str     = sprintf('%02d:%02d', $hour, $min);
            $slot_end     = $tm + $ad_duration;
            $slot_buf_s   = $tm       - $ad_buf_before;
            $slot_buf_e   = $slot_end + $ad_buf_after;

            if ($ad_is_group) {
                $count = $group_slot_counts[$slot_str] ?? 0;
                if ($count < $ad_max_part) {
                    // Also check Google Calendar
                    if (!empty($gcal_busy_periods) && !ad_slot_passes_gcal($check_date, $slot_str, $ad_duration, $ad_buf_before, $ad_buf_after, $gcal_busy_periods)) {
                        continue; // GCal blocks this slot
                    }
                    $has_available = true;
                    break;
                }
            } else {
                $slot_free    = true;
                $seen_windows = [];
                foreach ($existing_bookings as $bk) {
                    $bt = substr($bk['appointment_time'], 0, 5);
                    $bp = explode(':', $bt);
                    if (count($bp) !== 2) continue;
                    $b_s   = (int)$bp[0] * 60 + (int)$bp[1];
                    $b_dur = max(1, (int)($bk['duration_minutes'] ?? 60));
                    $b_bb  = max(0, (int)($bk['b_buffer_before'] ?? 0));
                    $b_ba  = max(0, (int)($bk['b_buffer_after']  ?? 0));
                    $b_bs  = $b_s - $b_bb;
                    $b_be  = $b_s + $b_dur + $b_ba;
                    $wkey  = $b_bs . '-' . $b_be;
                    if (isset($seen_windows[$wkey])) continue;
                    $seen_windows[$wkey] = true;
                    if ($slot_buf_s < $b_be && $b_bs < $slot_buf_e) {
                        $slot_free = false;
                        break;
                    }
                }
                // Also check Google Calendar
                if ($slot_free && !empty($gcal_busy_periods)) {
                    $slot_free = ad_slot_passes_gcal($check_date, $slot_str, $ad_duration, $ad_buf_before, $ad_buf_after, $gcal_busy_periods);
                }
                if ($slot_free) {
                    $has_available = true;
                    break;
                }
            }
        }

        if ($has_available) {
            $available_dates[] = $check_date;
        }
    }

    echo json_encode([
        'available_dates' => $available_dates,
        'schedule_type'   => $ad_schedule_type,
    ]);
    exit;

} elseif ($method === 'GET') {
    // Check availability
    $date = $_GET['date'] ?? '';
    $appointment_type_id = isset($_GET['appointment_type_id']) ? (int)$_GET['appointment_type_id'] : null;
    
    if (!$date) {
        echo json_encode(['error' => 'Date parameter required']);
        exit;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get appointment type configuration if provided
    $available_days = [0,1,2,3,4,5,6]; // Default: all days
    $available_start_time = '09:00';
    $available_end_time = '17:00';
    $time_slot_interval = 30;
    $slot_duration = 60; // appointment duration in minutes (used for overlap detection)
    $is_group_class = false;
    $max_participants = 1;
    $buffer_before = 0; // minutes of buffer required before this appointment type
    $buffer_after  = 0; // minutes of buffer required after this appointment type
    
    if ($appointment_type_id) {
        $stmt = $conn->prepare("
            SELECT available_days, available_start_time, available_end_time, time_slot_interval,
                   schedule_type, specific_date, specific_dates, per_day_schedule,
                   duration_minutes, is_group_class, max_participants,
                   buffer_before_minutes, buffer_after_minutes
            FROM appointment_types 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$appointment_type_id]);
        $appointment_type = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($appointment_type) {
            $schedule_type = $appointment_type['schedule_type'] ?? 'recurring';
            
            // Handle specific date scheduling (single or multi-date)
            if ($schedule_type === 'specific_date') {
                $custom_slot_configs = null; // null = use global times; array = per-timeslot config

                // Try new multi-date format first
                $raw_specific_dates = $appointment_type['specific_dates'] ?? null;
                if (!empty($raw_specific_dates)) {
                    $specific_dates_arr = json_decode($raw_specific_dates, true);
                    if (is_array($specific_dates_arr) && !empty($specific_dates_arr)) {
                        // Find the entry matching the requested date
                        $matched_entry = null;
                        foreach ($specific_dates_arr as $entry) {
                            if (($entry['date'] ?? '') === $date) {
                                $matched_entry = $entry;
                                break;
                            }
                        }
                        if ($matched_entry === null) {
                            // Date not in the list
                            $all_date_labels = array_map(
                                fn($e) => date('F j, Y', strtotime($e['date'])),
                                $specific_dates_arr
                            );
                            echo json_encode([
                                'date' => $date,
                                'available_slots' => [],
                                'message' => 'This appointment is only available on: ' . implode(', ', $all_date_labels),
                            ]);
                            exit;
                        }
                        // If the matched entry has custom timeslots, record them
                        if (!empty($matched_entry['timeslots'])) {
                            $custom_slot_configs = $matched_entry['timeslots'];
                        }
                    }
                } else {
                    // Legacy single-date fallback
                    $specific_date_legacy = $appointment_type['specific_date'] ?? null;
                    if ($specific_date_legacy !== $date) {
                        echo json_encode([
                            'date' => $date,
                            'available_slots' => [],
                            'message' => 'This appointment is only available on: ' . date('F j, Y', strtotime($specific_date_legacy)),
                        ]);
                        exit;
                    }
                }
            }
            
            $available_days = json_decode($appointment_type['available_days'], true);
            if (!is_array($available_days)) {
                $available_days = [0,1,2,3,4,5,6];
            }
            $available_start_time = $appointment_type['available_start_time'] ?? '09:00';
            $available_end_time = $appointment_type['available_end_time'] ?? '17:00';
            $time_slot_interval = (int)($appointment_type['time_slot_interval'] ?? 30);
            $slot_duration      = (int)($appointment_type['duration_minutes']   ?? 60);
            $is_group_class     = !empty($appointment_type['is_group_class']);
            $max_participants   = max(1, (int)($appointment_type['max_participants'] ?? 1));
            $buffer_before      = max(0, (int)($appointment_type['buffer_before_minutes'] ?? 0));
            $buffer_after       = max(0, (int)($appointment_type['buffer_after_minutes']  ?? 0));
        }
    }
    
    // Check if the requested date's day of week is available (only for recurring schedules)
    if (!isset($schedule_type) || $schedule_type === 'recurring') {
        $day_of_week = (int)date('w', strtotime($date)); // 0 = Sunday, 6 = Saturday
        if (!in_array($day_of_week, $available_days)) {
            $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $available_day_names = array_map(function($day) use ($day_names) {
                return $day_names[$day];
            }, $available_days);
            
            echo json_encode([
                'date' => $date,
                'available_slots' => [],
                'message' => 'This appointment type is only available on: ' . implode(', ', $available_day_names)
            ]);
            exit;
        }

        // Apply per-day time overrides if configured
        if (!empty($appointment_type['per_day_schedule'])) {
            $per_day = json_decode($appointment_type['per_day_schedule'], true);
            if (is_array($per_day) && isset($per_day[$day_of_week])) {
                $day_start = $per_day[$day_of_week]['start'] ?? '';
                $day_end   = $per_day[$day_of_week]['end']   ?? '';
                if (!empty($day_start) && !empty($day_end) && $day_start < $day_end) {
                    $available_start_time = $day_start;
                    $available_end_time   = $day_end;
                }
            }
        }
    }
    
    $stmt = $conn->prepare("
        SELECT b.appointment_time, b.duration_minutes, b.appointment_type_id,
               COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before,
               COALESCE(at.buffer_after_minutes,  0) AS b_buffer_after
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        WHERE b.appointment_date = ? AND b.status != 'cancelled'
    ");
    $stmt->execute([$date]);
    $existing_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Query Google Calendar for busy periods on this date (best-effort; errors are non-fatal)
    $google_busy_periods = [];
    $google_calendar_checked = false;
    if (GoogleCalendarIntegration::isOAuthConfigured()) {
        try {
            // Use the first connected admin's calendar – consistent with how the POST
            // handler adds events (it iterates all admins and stops on first success).
            $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id LIMIT 1");
            if ($admin_row = $stmt_admins->fetch(PDO::FETCH_ASSOC)) {
                $google_busy_periods = GoogleCalendarIntegration::getFreeBusy($date, (int)$admin_row['admin_user_id']);
                $google_calendar_checked = true;
            }
        } catch (Exception $e) {
            error_log('api_bookings: Google Calendar free/busy check failed: ' . $e->getMessage());
        }
    }
    
    // Generate available slots based on appointment type configuration
    $available_slots = [];
    
    // Build the list of candidate time-slots (in minutes from midnight).
    // When the appointment type defines custom timeslots for this specific date,
    // expand them to individual minute-offsets; otherwise fall back to the
    // global start→end sweep at the configured interval.
    $candidate_minutes = []; // each value: minutes from midnight

    if (!empty($custom_slot_configs)) {
        // Custom timeslots defined for this specific date
        foreach ($custom_slot_configs as $cfg) {
            $slot_type = $cfg['type'] ?? 'point';
            if ($slot_type === 'point' && !empty($cfg['time'])) {
                $parts = explode(':', $cfg['time']);
                if (count($parts) === 2) {
                    $candidate_minutes[] = (int)$parts[0] * 60 + (int)$parts[1];
                }
            } elseif ($slot_type === 'range' && !empty($cfg['start']) && !empty($cfg['end'])) {
                $s_parts = explode(':', $cfg['start']);
                $e_parts = explode(':', $cfg['end']);
                if (count($s_parts) === 2 && count($e_parts) === 2) {
                    $range_start = (int)$s_parts[0] * 60 + (int)$s_parts[1];
                    $range_end   = (int)$e_parts[0] * 60 + (int)$e_parts[1];
                    for ($m = $range_start; $m < $range_end; $m += $time_slot_interval) {
                        $candidate_minutes[] = $m;
                    }
                }
            }
        }
        // Deduplicate and sort
        $candidate_minutes = array_values(array_unique($candidate_minutes));
        sort($candidate_minutes);
    } else {
        // Default: sweep from global start to global end at interval
        $start_parts = explode(':', $available_start_time);
        $end_parts   = explode(':', $available_end_time);
        if (count($start_parts) !== 2 || count($end_parts) !== 2) {
            $start_time_minutes = 9 * 60;
            $end_time_minutes   = 17 * 60;
        } else {
            $start_time_minutes = (int)$start_parts[0] * 60 + (int)$start_parts[1];
            $end_time_minutes   = (int)$end_parts[0]   * 60 + (int)$end_parts[1];
        }
        for ($m = $start_time_minutes; $m < $end_time_minutes; $m += $time_slot_interval) {
            $candidate_minutes[] = $m;
        }
    }

    // Evaluate each candidate slot for conflicts / availability
    foreach ($candidate_minutes as $time_minutes) {
        $hour = intdiv($time_minutes, 60);
        $minute = $time_minutes % 60;
        $time_slot = sprintf('%02d:%02d', $hour, $minute);
        $time_slot_end_minutes = $time_minutes + $slot_duration;

        // The buffered window for this proposed slot:
        //   starts $buffer_before minutes before the slot
        //   ends   $buffer_after  minutes after the slot ends
        $slot_buffered_start = $time_minutes - $buffer_before;
        $slot_buffered_end   = $time_slot_end_minutes + $buffer_after;
        
        // Check if slot is available
        $is_available = true;

        // ── Internal booking conflict detection ──────────────────────────────
        if ($is_group_class && $appointment_type_id) {
            // Group class: count existing participants for this exact slot and type.
            // Allow booking as long as capacity is not yet reached.
            $participant_count = 0;
            foreach ($existing_bookings as $booking) {
                $b_time = substr($booking['appointment_time'], 0, 5);
                if ($b_time === $time_slot && (int)$booking['appointment_type_id'] === $appointment_type_id) {
                    $participant_count++;
                }
            }
            if ($participant_count >= $max_participants) {
                $is_available = false;
            }
        } else {
            // Regular appointment: block if any existing booking's buffered window overlaps
            // with the proposed slot's buffered window.
            // De-duplicate by buffered [start, end) window so that group-class bookings (multiple
            // rows at the same time) are treated as a single occupancy block.
            $seen_windows = [];
            foreach ($existing_bookings as $booking) {
                $b_time   = substr($booking['appointment_time'], 0, 5);
                $b_parts  = explode(':', $b_time);
                if (count($b_parts) !== 2) continue;
                $b_start        = (int)$b_parts[0] * 60 + (int)$b_parts[1];
                $b_dur          = max(1, (int)($booking['duration_minutes'] ?? 60));
                $b_buf_before   = max(0, (int)($booking['b_buffer_before'] ?? 0));
                $b_buf_after    = max(0, (int)($booking['b_buffer_after']  ?? 0));
                // Existing booking's buffered window
                $b_buf_start    = $b_start - $b_buf_before;
                $b_buf_end      = $b_start + $b_dur + $b_buf_after;
                $win_key        = $b_buf_start . '-' . $b_buf_end;

                if (isset($seen_windows[$win_key])) {
                    continue; // Already evaluated this time window
                }
                $seen_windows[$win_key] = true;

                // Two intervals [A,B) and [C,D) overlap iff A < D && C < B
                if ($slot_buffered_start < $b_buf_end && $b_buf_start < $slot_buffered_end) {
                    $is_available = false;
                    break;
                }
            }
        }

        // ── Google Calendar busy-period check ────────────────────────────────
        // Expand the check window by the appointment type's buffer times so that
        // a GCal event ending at 9:00 won't allow a 15-min-buffer-before slot at 9:05.
        if ($is_available && !empty($google_busy_periods)) {
            $slot_ts                = strtotime($date . 'T' . $time_slot . ':00');
            $slot_buffered_start_ts = $slot_ts - $buffer_before * 60;
            $slot_buffered_end_ts   = $slot_ts + ($slot_duration + $buffer_after) * 60;

            foreach ($google_busy_periods as $busy) {
                if (empty($busy['start']) || empty($busy['end'])) continue;
                $busy_start_ts = strtotime($busy['start']);
                $busy_end_ts   = strtotime($busy['end']);
                if ($busy_start_ts === false || $busy_end_ts === false) continue;

                // Overlap check: buffered slot window vs. GCal busy window
                if ($slot_buffered_start_ts < $busy_end_ts && $busy_start_ts < $slot_buffered_end_ts) {
                    $is_available = false;
                    break;
                }
            }
        }
        
        if ($is_available) {
            $available_slots[] = $time_slot;
        }
    }
    
    echo json_encode([
        'date' => $date,
        'available_slots' => $available_slots,
        'google_calendar_checked' => $google_calendar_checked,
    ]);
    
} elseif ($method === 'POST') {
    // Create booking
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required_fields = ['client_name', 'client_email', 'service_type', 'appointment_date', 'appointment_time'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            echo json_encode(['error' => "Missing required field: $field"]);
            exit;
        }
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        // Validate email format
        if (!filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Invalid email format for client_email']);
            exit;
        }
        
        // Check if client exists by email
        $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
        $stmt->execute([$data['client_email']]);
        $existing_client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_client) {
            // Client exists, use their ID
            $client_id = $existing_client['id'];
        } else {
            // Create new client
            $stmt = $conn->prepare("
                INSERT INTO clients (name, email, phone, notes, created_at, updated_at) 
                VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([
                $data['client_name'],
                $data['client_email'],
                $data['client_phone'] ?? '',
                'Created from booking form'
            ]);
            $client_id = $conn->lastInsertId();
        }
        
        // Create pet profiles from dog names if provided
        $dog_names = isset($data['dog_names']) ? $data['dog_names'] : '';
        $pet_ids = [];
        if (!empty($dog_names)) {
            // Split comma-separated dog names and remove empty strings explicitly
            $names = array_filter(
                array_map('trim', explode(',', $dog_names)),
                fn($n) => $n !== ''
            );
            
            if (!empty($names)) {
                // Fetch all existing pets for this client in one query
                $placeholders = str_repeat('?,', count($names) - 1) . '?';
                $stmt = $conn->prepare("SELECT id, name FROM pets WHERE client_id = ? AND name IN ($placeholders)");
                $stmt->execute(array_merge([$client_id], $names));
                $existing_pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $existing_pet_map = [];
                foreach ($existing_pets as $pet) {
                    $existing_pet_map[$pet['name']] = $pet['id'];
                }
                
                // Create new pets or use existing ones
                foreach ($names as $dog_name) {
                    if (isset($existing_pet_map[$dog_name])) {
                        // Pet already exists
                        $pet_ids[] = $existing_pet_map[$dog_name];
                    } else {
                        // Create new pet
                        $stmt = $conn->prepare("
                            INSERT INTO pets (client_id, name, species, is_active, created_at, updated_at) 
                            VALUES (?, ?, 'Dog', 1, datetime('now'), datetime('now'))
                        ");
                        $stmt->execute([$client_id, $dog_name]);
                        $pet_ids[] = $conn->lastInsertId();
                    }
                }
            }
        }
        
        // Get appointment type info to check if it's a Mini Session or Field Rental
        $location = null;
        $location_type = trim($data['location_type'] ?? '');
        $location_value = trim($data['location_value'] ?? '');
        $allowed_location_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall', 'fixed'];

        if (!empty($data['appointment_type_id'])) {
            $stmt = $conn->prepare("SELECT is_mini_session, mini_session_location, is_field_rental, field_rental_location, is_group_class, group_class_location, location_types, contract_template_id FROM appointment_types WHERE id = ?");
            $stmt->execute([$data['appointment_type_id']]);
            $apt_type = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($apt_type && !empty($apt_type['is_mini_session'])) {
                // Fixed location: override any submitted location
                $location_type = 'fixed';
                $location = $apt_type['mini_session_location'];
            } elseif ($apt_type && !empty($apt_type['is_field_rental'])) {
                $location_type = 'fixed';
                $location = $apt_type['field_rental_location'];
            } elseif ($apt_type && !empty($apt_type['is_group_class'])) {
                $location_type = 'fixed';
                $location = $apt_type['group_class_location'];
            } elseif ($apt_type && !empty($apt_type['location_types'])) {
                // Restrict to appointment type's configured location types
                $configured = json_decode($apt_type['location_types'], true);
                if (is_array($configured) && !empty($configured)) {
                    $allowed_location_types = array_merge($configured, ['fixed']);
                }
            }

            // Validate contract signature if this appointment type requires one
            if (!empty($apt_type['contract_template_id'])) {
                $contract_typed_name = trim($data['contract_typed_name'] ?? '');
                if (empty($contract_typed_name)) {
                    echo json_encode(['error' => 'You must sign the required contract (type your full name) to complete your booking.']);
                    exit;
                }
            }
        }

        // For non-fixed types, validate and resolve location
        if ($location_type !== 'fixed') {
            if (empty($location_type) || !in_array($location_type, $allowed_location_types)) {
                echo json_encode(['error' => 'A valid location type is required. Please select how the appointment will be conducted.']);
                exit;
            }
            if (in_array($location_type, ['custom_address', 'webcall']) && empty($location_value)) {
                echo json_encode(['error' => $location_type === 'webcall' ? 'Webcall URL is required.' : 'Custom address is required.']);
                exit;
            }
            // For client_address, resolve the actual address from the client's profile
            if ($location_type === 'client_address') {
                $stmt = $conn->prepare("SELECT address FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $client_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $resolved_address = trim($client_row['address'] ?? '');
                if (empty($resolved_address)) {
                    echo json_encode(['error' => 'Your account does not have an address on file. Please update your profile or choose a different location type.']);
                    exit;
                }
                $location = $resolved_address;
            } else {
                $location = $location_value;
            }
        }
        
        // Resolve credit to use, if requested
        $use_credit = ($data['use_credit'] ?? false) === true;
        $pkg_credit_id_to_use = null;
        if ($use_credit && !empty($data['appointment_type_id'])) {
            // Find the best eligible credit row (soonest expiry first)
            $stmt = $conn->prepare("
                SELECT cpc.id
                FROM client_package_credits cpc
                JOIN client_packages cp ON cpc.client_package_id = cp.id
                WHERE cpc.client_id = ?
                  AND cpc.appointment_type_id = ?
                  AND (cpc.total_credits - cpc.used_credits) > 0
                  AND cp.is_active = 1
                  AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
                ORDER BY cp.expires_at ASC
                LIMIT 1
            ");
            $stmt->execute([$client_id, (int)$data['appointment_type_id']]);
            $credit_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($credit_row) {
                $pkg_credit_id_to_use = (int)$credit_row['id'];
            }
        }

        // Determine contract signature data
        $contract_typed_name = trim($data['contract_typed_name'] ?? '');
        $allowed_sig_fonts = ['font-dancing', 'font-pacifico', 'font-satisfy', 'font-great-vibes', 'font-allura'];
        $contract_sig_font = in_array($data['contract_signature_font'] ?? '', $allowed_sig_fonts)
            ? $data['contract_signature_font']
            : 'font-dancing';
        $contract_accepted = !empty($contract_typed_name) ? 1 : 0;
        $contract_accepted_at = $contract_accepted ? date('Y-m-d H:i:s') : null;

        // Create booking with client_id, appointment_type_id, location, location_type, and package_credit_id
        $stmt = $conn->prepare("
            INSERT INTO bookings (client_id, appointment_type_id, client_name, client_email, client_phone, service_type, appointment_date, appointment_time, notes, duration_minutes, location, location_type, package_credit_id, contract_accepted, contract_accepted_at, contract_signature_name, contract_signature_font, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $client_id,
            !empty($data['appointment_type_id']) ? (int)$data['appointment_type_id'] : null,
            $data['client_name'],
            $data['client_email'],
            $data['client_phone'] ?? '',
            $data['service_type'],
            $data['appointment_date'],
            $data['appointment_time'],
            $data['notes'] ?? '',
            $data['duration_minutes'] ?? 60,
            $location,
            $location_type,
            $pkg_credit_id_to_use,
            $contract_accepted,
            $contract_accepted_at,
            $contract_accepted ? $contract_typed_name : null,
            $contract_accepted ? $contract_sig_font : null
        ]);
        
        $booking_id = $conn->lastInsertId();
        
        // Link pets to booking
        if (!empty($pet_ids)) {
            foreach ($pet_ids as $pet_id) {
                $stmt = $conn->prepare("
                    INSERT INTO appointment_pets (booking_id, pet_id, created_at) 
                    VALUES (?, ?, datetime('now'))
                ");
                $stmt->execute([$booking_id, $pet_id]);
            }
        }

        // Save form responses submitted during booking
        $workflow_helper = new WorkflowHelper($conn);
        if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
            $ins = $conn->prepare("INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at) VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)");
            foreach ($data['form_responses'] as $template_id => $responses) {
                if (is_array($responses) && !empty($responses)) {
                    $ins->execute([$client_id, (int)$template_id, $booking_id, json_encode($responses)]);
                    $form_submission_id = $conn->lastInsertId();
                    $workflow_helper->checkFormTriggers($form_submission_id);
                }
            }
        }

        // Trigger auto-enrollment for matching appointment workflow triggers
        $workflow_helper->checkAppointmentTriggers($booking_id);

        // Deduct credit if one was selected
        if ($pkg_credit_id_to_use) {
            $conn->prepare("
                UPDATE client_package_credits
                SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$pkg_credit_id_to_use]);

            // Look up appointment_type_id for the transaction log
            $apt_type_id_for_log = !empty($data['appointment_type_id']) ? (int)$data['appointment_type_id'] : null;
            if ($apt_type_id_for_log) {
                $conn->prepare("
                    INSERT INTO package_credit_transactions
                        (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
                    VALUES (?, ?, ?, 'consume', -1, ?, ?, NULL)
                ")->execute([
                    $pkg_credit_id_to_use,
                    $client_id,
                    $apt_type_id_for_log,
                    $booking_id,
                    "Credit applied at booking #{$booking_id} via client portal"
                ]);
            }
        }

        // Get the complete booking info
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Generate calendar links
        require_once '../includes/icalendar.php';
        $base_url = getDynamicBaseUrl();
        $google_calendar_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
        $ical_download_link = $base_url . '/backend/public/download_ical.php?booking_id=' . $booking_id;
        
        // Send confirmation email
        $email_service = new EmailService(null, $conn);
        $email_result = $email_service->sendBookingConfirmation($booking);
        
        // Try to add to Google Calendar
        // Priority: OAuth tokens (per admin user) → service account (legacy)
        $google_result = ['success' => false, 'message' => 'Google Calendar integration not configured'];

        // Attempt OAuth sync: use the first admin user that has a valid OAuth token
        if (GoogleCalendarIntegration::isOAuthConfigured()) {
            $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
            while ($admin_row = $stmt_admins->fetch(PDO::FETCH_ASSOC)) {
                $google_result = GoogleCalendarIntegration::addEventOAuth($booking, (int)$admin_row['admin_user_id']);
                if ($google_result['success']) {
                    break;
                }
            }
        }

        // Fall back to service account if OAuth did not succeed
        if (!$google_result['success']) {
            $google_calendar = new GoogleCalendarIntegration();
            if ($google_calendar->isConfigured()) {
                $google_result = $google_calendar->addEvent($booking);
            }
        }

        // Persist the Google event ID so we can delete it later if cancelled
        if (!empty($google_result['event_id'])) {
            $conn->prepare("UPDATE bookings SET google_event_id = ? WHERE id = ?")
                 ->execute([$google_result['event_id'], $booking_id]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Booking created successfully!',
            'booking_id' => $booking_id,
            'credit_applied' => $pkg_credit_id_to_use !== null,
            'calendar_links' => [
                'google_calendar' => $google_calendar_link,
                'ical_download' => $ical_download_link
            ],
            'email_sent' => $email_result['success'],
            'google_calendar_synced' => $google_result['success']
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
