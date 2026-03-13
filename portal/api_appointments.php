<?php
/**
 * Portal Appointments API
 * Handles client-initiated cancellation and rescheduling of their own bookings.
 * All enforcement of advance notice windows and ownership checks are done here.
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/google_calendar.php';
header('Content-Type: application/json');

// Must be a logged-in portal client
if (!isPortalLoggedIn()) {
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$client_id = portalClientId();
$db   = new Database();
$conn = $db->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$data = json_decode(scalar_string(file_get_contents('php://input')), true);
if (!is_array($data)) {
    echo json_encode(['error' => 'Invalid JSON payload.']);
    exit;
}

$action     = $data['action'] ?? '';
$booking_id = intval($data['booking_id'] ?? 0);
$reason     = mb_substr(trim($data['reason'] ?? ''), 0, 1000);

if ($booking_id <= 0) {
    echo json_encode(['error' => 'Invalid booking ID.']);
    exit;
}

// ── Load and verify ownership ────────────────────────────────────────────────
// Fetch booking joined with appointment type for notice period
$stmt = $conn->prepare("
    SELECT b.*, at.cancellation_notice_hours, at.name AS apt_type_name,
           at.portal_available, at.schedule_type,
           at.available_days, at.available_start_time, at.available_end_time,
           at.time_slot_interval, at.duration_minutes AS apt_duration_minutes,
           at.advance_booking_min_days, at.advance_booking_max_days
    FROM bookings b
    LEFT JOIN appointment_types at ON b.appointment_type_id = at.id
    WHERE b.id = ?
");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    echo json_encode(['error' => 'Booking not found.']);
    exit;
}

// Verify the booking belongs to this client (by client_id or email)
$stmt = $conn->prepare("SELECT email FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_email = $stmt->fetchColumn();

$belongs = (
    (int)($booking['client_id'] ?? 0) === $client_id ||
    (!empty($client_email) && strtolower(scalar_string($booking['client_email'] ?? '')) === strtolower((string) $client_email))
);
if (!$belongs) {
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

// ── Block modifications on past / already-cancelled / completed bookings ─────
$allowed_statuses = ['pending', 'confirmed'];
if (!in_array($booking['status'] ?? '', $allowed_statuses, true)) {
    echo json_encode(['error' => 'This appointment cannot be modified (status: ' . ($booking['status'] ?? 'unknown') . ').']);
    exit;
}

// ── Enforce advance notice window ────────────────────────────────────────────
$notice_hours    = intval($booking['cancellation_notice_hours'] ?? 0);
$apt_datetime    = strtotime($booking['appointment_date'] . ' ' . $booking['appointment_time']);
$hours_until_apt = ($apt_datetime - time()) / 3600.0;

// If appointment is in the past (or now), never allow modification
if ($hours_until_apt <= 0) {
    $business_email = scalar_string(Settings::get('business_email', ''));
    $msg = 'This appointment cannot be changed online. Please contact us directly.';
    if ($business_email) $msg .= " ({$business_email})";
    echo json_encode(['error' => 'restriction', 'message' => $msg]);
    exit;
}

if ($notice_hours > 0 && $hours_until_apt < $notice_hours) {
    $business_email = scalar_string(Settings::get('business_email', ''));
    $msg = 'This appointment cannot be changed online. Please contact us directly.';
    if ($business_email) $msg .= " ({$business_email})";
    echo json_encode(['error' => 'restriction', 'message' => $msg]);
    exit;
}

// ── Resolve IP for audit log ─────────────────────────────────────────────────
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $forwarded = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    $client_ip = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : ($_SERVER['REMOTE_ADDR'] ?? '');
} else {
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
}

/* ══════════════════════════════════════════════════════════════════════════
 *  Action: cancel
 * ═════════════════════════════════════════════════════════════════════════ */
if ($action === 'cancel') {
    // Record old values for the log
    $old_date = $booking['appointment_date'];
    $old_time = $booking['appointment_time'];

    // Update booking status to cancelled
    $conn->prepare("UPDATE bookings SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$booking_id]);

    // Remove from Google Calendar if linked
    if (!empty($booking['google_event_id']) && GoogleCalendarIntegration::isOAuthConfigured()) {
        $gcal_event_id = $booking['google_event_id'];
        $stmt_tok = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
        while ($tok_row = $stmt_tok->fetch(PDO::FETCH_ASSOC)) {
            if (GoogleCalendarIntegration::deleteEventOAuth($gcal_event_id, (int)$tok_row['admin_user_id'])) {
                $conn->prepare("UPDATE bookings SET google_event_id = NULL WHERE id = ?")->execute([$booking_id]);
                break;
            }
        }
    }

    // Refund package credit if applicable
    $pkg_credit_id = intval($booking['package_credit_id'] ?? 0);
    if ($pkg_credit_id > 0) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) FROM package_credit_transactions
            WHERE client_package_credit_id = ? AND booking_id = ? AND transaction_type = 'refund'
        ");
        $stmt->execute([$pkg_credit_id, $booking_id]);
        if (!(int)$stmt->fetchColumn()) {
            $conn->prepare("
                UPDATE client_package_credits
                SET used_credits = used_credits - 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND used_credits > 0
            ")->execute([$pkg_credit_id]);

            $stmt2 = $conn->prepare("SELECT appointment_type_id, client_id FROM client_package_credits WHERE id = ?");
            $stmt2->execute([$pkg_credit_id]);
            $cpc = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($cpc) {
                $conn->prepare("
                    INSERT INTO package_credit_transactions
                        (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
                    VALUES (?, ?, ?, 'refund', 1, ?, ?, NULL)
                ")->execute([
                    $pkg_credit_id,
                    $cpc['client_id'],
                    $cpc['appointment_type_id'],
                    $booking_id,
                    "Credit refunded — client self-cancelled booking #{$booking_id}",
                ]);
            }
        }
    }

    // Log the change
    $conn->prepare("
        INSERT INTO booking_change_log
            (booking_id, client_id, change_type, reason, old_date, old_time, initiated_by, ip_address)
        VALUES (?, ?, 'cancellation', ?, ?, ?, 'client', ?)
    ")->execute([$booking_id, $client_id, $reason ?: null, $old_date, $old_time, $client_ip]);

    // Activity log
    logClientActivity($client_id, 'appointment_cancel', "Cancelled booking #{$booking_id}", $conn);

    // Send emails
    $email_service = new EmailService(null, $conn);
    if (!empty($booking['client_email'])) {
        $email_service->sendBookingCancellation($booking, $reason);
    }
    $email_service->sendAdminBookingChangeNotification($booking, 'cancellation', $reason);

    echo json_encode(['success' => true, 'message' => 'Your appointment has been cancelled.']);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════
 *  Action: reschedule
 * ═════════════════════════════════════════════════════════════════════════ */
if ($action === 'reschedule') {
    $new_date = trim($data['new_date'] ?? '');
    $new_time = trim($data['new_time'] ?? '');

    // Validate date/time format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
        exit;
    }
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $new_time)) {
        echo json_encode(['error' => 'Invalid time format. Use HH:MM.']);
        exit;
    }
    $new_time_hhmm = substr($new_time, 0, 5);

    // Validate new date is in the future
    $new_datetime = strtotime($new_date . ' ' . $new_time_hhmm);
    if ($new_datetime === false || $new_datetime <= time()) {
        echo json_encode(['error' => 'The new date and time must be in the future.']);
        exit;
    }

    // Enforce advance booking minimum on new slot
    $apt_type_id = intval($booking['appointment_type_id'] ?? 0);
    if ($apt_type_id > 0) {
        $min_days = intval($booking['advance_booking_min_days'] ?? 0);
        $max_days = intval($booking['advance_booking_max_days'] ?? 365);
        $days_until = ($new_datetime - time()) / 86400;
        if ($min_days > 0 && $days_until < $min_days) {
            echo json_encode(['error' => "Appointments must be booked at least {$min_days} day(s) in advance."]);
            exit;
        }
        if ($days_until > $max_days) {
            echo json_encode(['error' => "Appointments cannot be booked more than {$max_days} day(s) in advance."]);
            exit;
        }
    }

    // Check the new slot isn't already taken by another confirmed/pending booking
    // (for this appointment type; excludes the current booking being rescheduled)
    // Use PHP-computed end time so query works on both MySQL and SQLite.
    if ($apt_type_id > 0) {
        $duration = intval($booking['apt_duration_minutes'] ?? $booking['duration_minutes'] ?? 60);
        // Use DateTime for safe end-time arithmetic (avoids integer overflow with strtotime)
        $new_end_dt = new DateTime($new_date . ' ' . $new_time_hhmm . ':00');
        $new_end_dt->modify("+{$duration} minutes");
        $new_end_time = $new_end_dt->format('H:i:s');
        // Fetch all bookings that start before our new appointment ends on the same date
        $stmt = $conn->prepare("
            SELECT appointment_time, duration_minutes FROM bookings
            WHERE appointment_type_id = ?
              AND appointment_date = ?
              AND id != ?
              AND status IN ('pending', 'confirmed')
              AND appointment_time < ?
        ");
        $stmt->execute([$apt_type_id, $new_date, $booking_id, $new_end_time]);
        $conflict = false;
        $new_start_ts = strtotime($new_date . ' ' . $new_time_hhmm . ':00');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Compute existing booking's end time using DateTime for consistency
            $existing_start = new DateTime($new_date . ' ' . substr($row['appointment_time'], 0, 8));
            $existing_dur   = intval($row['duration_minutes'] ?? 60);
            $existing_end   = clone $existing_start;
            $existing_end->modify("+{$existing_dur} minutes");
            // Overlap if existing_end > new_start (we already know existing_start < new_end from query)
            if ($existing_end->getTimestamp() > $new_start_ts) {
                $conflict = true;
                break;
            }
        }
        if ($conflict) {
            echo json_encode(['error' => 'That time slot is not available. Please choose another time.']);
            exit;
        }
    }

    $old_date = $booking['appointment_date'];
    $old_time = $booking['appointment_time'];

    // Update booking with new date and time
    $conn->prepare("
        UPDATE bookings
        SET appointment_date = ?, appointment_time = ?, status = 'confirmed', updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$new_date, $new_time_hhmm, $booking_id]);

    // Update or remove the Google Calendar event
    if (!empty($booking['google_event_id']) && GoogleCalendarIntegration::isOAuthConfigured()) {
        // Build a synthetic booking row with updated date/time for calendar update
        $updated_booking = array_merge($booking, [
            'appointment_date' => $new_date,
            'appointment_time' => $new_time_hhmm,
        ]);
        $gcal = new GoogleCalendarIntegration();
        $stmt_tok = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
        $gcal_updated = false;
        while ($tok_row = $stmt_tok->fetch(PDO::FETCH_ASSOC)) {
            $result = GoogleCalendarIntegration::updateEventOAuth($updated_booking, $booking['google_event_id'], (int)$tok_row['admin_user_id']);
            if (!empty($result['success'])) {
                $gcal_updated = true;
                break;
            }
        }
        // If update failed, fall back to delete so stale event is removed
        if (!$gcal_updated) {
            $stmt_tok2 = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
            while ($tok_row2 = $stmt_tok2->fetch(PDO::FETCH_ASSOC)) {
                if (GoogleCalendarIntegration::deleteEventOAuth($booking['google_event_id'], (int)$tok_row2['admin_user_id'])) {
                    $conn->prepare("UPDATE bookings SET google_event_id = NULL WHERE id = ?")->execute([$booking_id]);
                    break;
                }
            }
        }
    }

    // Log the change
    $conn->prepare("
        INSERT INTO booking_change_log
            (booking_id, client_id, change_type, reason, old_date, old_time, new_date, new_time, initiated_by, ip_address)
        VALUES (?, ?, 'reschedule', ?, ?, ?, ?, ?, 'client', ?)
    ")->execute([$booking_id, $client_id, $reason ?: null, $old_date, $old_time, $new_date, $new_time_hhmm, $client_ip]);

    // Activity log
    logClientActivity($client_id, 'appointment_reschedule', "Rescheduled booking #{$booking_id} to {$new_date} {$new_time_hhmm}", $conn);

    // Fetch updated booking row for emails
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $updated_booking = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$updated_booking) {
        echo json_encode(['error' => 'Updated booking could not be loaded.']);
        exit;
    }

    // Send emails
    $email_service = new EmailService(null, $conn);
    if (!empty($booking['client_email'])) {
        $email_service->sendBookingReschedule($updated_booking, $old_date, $old_time, $reason);
    }
    $email_service->sendAdminBookingChangeNotification($updated_booking, 'reschedule', $reason, $old_date, $old_time);

    echo json_encode([
        'success'  => true,
        'message'  => 'Your appointment has been rescheduled.',
        'new_date' => $new_date,
        'new_time' => $new_time_hhmm,
    ]);
    exit;
}

echo json_encode(['error' => 'Unknown action.']);
exit;
