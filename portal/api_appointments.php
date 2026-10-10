<?php
/**
 * Portal Appointments API
 * Handles client-initiated cancellation and rescheduling of their own bookings.
 * All enforcement of advance notice windows and ownership checks are done here.
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/google_calendar.php';
require_once '../backend/includes/booking_availability.php';
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

$action     = scalar_string($data['action'] ?? '');
$booking_id = safe_int($data['booking_id'] ?? 0);
$reason     = mb_substr(trim(scalar_string($data['reason'] ?? '')), 0, 1000);

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
$booking = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

if ($booking === []) {
    echo json_encode(['error' => 'Booking not found.']);
    exit;
}

// Verify the booking belongs to this client (by client_id or email)
$stmt = $conn->prepare("SELECT email FROM clients WHERE id = ?");
$stmt->execute([$client_id]);
$client_email = scalar_string($stmt->fetchColumn());

$belongs = (
    safe_int($booking['client_id'] ?? 0) === $client_id ||
    ($client_email !== '' && strtolower(scalar_string($booking['client_email'] ?? '')) === strtolower($client_email))
);
if (!$belongs) {
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

// ── Block modifications on past / already-cancelled / completed bookings ─────
$allowed_statuses = ['pending', 'confirmed'];
if (!in_array(array_string_value($booking, 'status', 'unknown'), $allowed_statuses, true)) {
    echo json_encode(['error' => 'This appointment cannot be modified (status: ' . array_string_value($booking, 'status', 'unknown') . ').']);
    exit;
}

// ── Enforce advance notice window ────────────────────────────────────────────
$notice_hours    = safe_int($booking['cancellation_notice_hours'] ?? 0);
$apt_datetime    = strtotime(array_string_value($booking, 'appointment_date') . ' ' . array_string_value($booking, 'appointment_time'));
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
    $forwarded = trim(explode(',', scalar_string($_SERVER['HTTP_X_FORWARDED_FOR']))[0]);
    $client_ip = filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : scalar_string($_SERVER['REMOTE_ADDR'] ?? '');
} else {
    $client_ip = scalar_string($_SERVER['REMOTE_ADDR'] ?? '');
}

/* ══════════════════════════════════════════════════════════════════════════
 *  Action: cancel
 * ═════════════════════════════════════════════════════════════════════════ */
if ($action === 'cancel') {
    $cancel_error = null;
    try {
        $conn->beginTransaction();
        $lock_rows = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        // Email ownership may use another client record. Lock both owners in ID
        // order before booking/credit rows, matching booking creation's order.
        $original_owner_id = array_int_value($booking, 'client_id');
        $stmt = $conn->prepare($lock_rows
            ? 'SELECT id, email FROM clients WHERE id IN (?, ?) ORDER BY id FOR UPDATE'
            : 'SELECT id, email FROM clients WHERE id IN (?, ?) ORDER BY id');
        $stmt->execute([$client_id, $original_owner_id]);
        $current_email = '';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $owner) {
            if (array_int_value($owner, 'id') === $client_id) { $current_email = array_string_value($owner, 'email'); }
        }
        $stmt = $conn->prepare($lock_rows
            ? 'SELECT * FROM bookings WHERE id = ? FOR UPDATE' : 'SELECT * FROM bookings WHERE id = ?');
        $stmt->execute([$booking_id]);
        $current = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
        if ($current === [] || array_int_value($current, 'client_id') !== $original_owner_id
            || !in_array(array_string_value($current, 'status'), $allowed_statuses, true)
            || !(array_int_value($current, 'client_id') === $client_id
                || ($current_email !== '' && strtolower(array_string_value($current, 'client_email')) === strtolower($current_email)))) {
            throw new RuntimeException('This appointment has changed. Please refresh your bookings.');
        }
        $stmt = $conn->prepare('SELECT cancellation_notice_hours FROM appointment_types WHERE id = ?');
        $stmt->execute([array_int_value($current, 'appointment_type_id')]);
        $current_notice = safe_int($stmt->fetchColumn());
        $current_start = strtotime(array_string_value($current, 'appointment_date') . ' ' . array_string_value($current, 'appointment_time'));
        $current_hours = ($current_start - time()) / 3600.0;
        if ($current_hours <= 0 || ($current_notice > 0 && $current_hours < $current_notice)) {
            throw new RuntimeException('This appointment cannot be changed online. Please contact us directly.');
        }
        $booking = array_merge($booking, $current);
    // Record old values for the log
    $old_date = $booking['appointment_date'];
    $old_time = $booking['appointment_time'];

    // Update booking status to cancelled
    $status_update = $conn->prepare("UPDATE bookings SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('pending', 'confirmed')");
    $status_update->execute([$booking_id]);
    if ($status_update->rowCount() !== 1) {
        throw new RuntimeException('This appointment has changed. Please refresh your bookings.');
    }

    // Refund package credit if applicable
    $pkg_credit_id = safe_int($booking['package_credit_id'] ?? 0);
    if ($pkg_credit_id > 0) {
        $stmt = $conn->prepare($lock_rows
            ? 'SELECT appointment_type_id, client_id FROM client_package_credits WHERE id = ? FOR UPDATE'
            : 'SELECT appointment_type_id, client_id FROM client_package_credits WHERE id = ?');
        $stmt->execute([$pkg_credit_id]);
        $cpc = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
        // A link alone does not prove a debit. Preserve unmatched historical rows.
        $stmt = $conn->prepare($lock_rows ? "SELECT id FROM package_credit_transactions
            WHERE client_package_credit_id = ? AND booking_id = ? AND client_id = ?
              AND appointment_type_id = ? AND transaction_type = 'consume' AND amount = -1 FOR UPDATE"
            : "SELECT id FROM package_credit_transactions
            WHERE client_package_credit_id = ? AND booking_id = ? AND client_id = ?
              AND appointment_type_id = ? AND transaction_type = 'consume' AND amount = -1");
        $stmt->execute([$pkg_credit_id, $booking_id, array_int_value($booking, 'client_id'), array_int_value($booking, 'appointment_type_id')]);
        $consumed = $stmt->fetchColumn() !== false;
        $stmt = $conn->prepare($lock_rows ? "SELECT id FROM package_credit_transactions
            WHERE client_package_credit_id = ? AND booking_id = ? AND transaction_type = 'refund' FOR UPDATE"
            : "SELECT id FROM package_credit_transactions
            WHERE client_package_credit_id = ? AND booking_id = ? AND transaction_type = 'refund'
        ");
        $stmt->execute([$pkg_credit_id, $booking_id]);
        $already_refunded = $stmt->fetchColumn() !== false;
        if ($cpc !== [] && $consumed && !$already_refunded
            && array_int_value($cpc, 'client_id') === array_int_value($booking, 'client_id')
            && array_int_value($cpc, 'appointment_type_id') === array_int_value($booking, 'appointment_type_id')) {
            $refund = $conn->prepare("
                UPDATE client_package_credits
                SET used_credits = used_credits - 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ? AND used_credits > 0
            ");
            $refund->execute([$pkg_credit_id]);
            if ($refund->rowCount() !== 1) {
                throw new RuntimeException('The booking credit could not be refunded. Please contact us directly.');
            }
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

    // Log the change
    $conn->prepare("
        INSERT INTO booking_change_log
            (booking_id, client_id, change_type, reason, old_date, old_time, initiated_by, ip_address)
        VALUES (?, ?, 'cancellation', ?, ?, ?, 'client', ?)
    ")->execute([$booking_id, $client_id, $reason ?: null, $old_date, $old_time, $client_ip]);

    // Activity log
    logClientActivity($client_id, 'appointment_cancel', "Cancelled booking #{$booking_id}", $conn);

    bdta_create_admin_notifications(
        $conn,
        'booking',
        $booking_id,
        'Booking cancelled',
        array_string_value($booking, 'client_name', 'Client') . ' cancelled booking #' . $booking_id . ' for ' . array_string_value($booking, 'appointment_date') . '.',
        '/client/bookings_list.php'
    );
        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $cancel_error = $e instanceof RuntimeException && !($e instanceof PDOException)
            ? $e->getMessage() : 'The appointment could not be cancelled. Please try again.';
    }
    if ($cancel_error !== null) {
        echo json_encode(['error' => $cancel_error]);
        exit;
    }

    // Provider failures cannot undo or repeat a committed cancellation/refund.
    try {
        if (!empty($booking['google_event_id'])) {
            $gcal_event_id = array_string_value($booking, 'google_event_id');
            if (GoogleCalendarIntegration::deleteEventForBooking($gcal_event_id, $booking)) {
                $conn->prepare('UPDATE bookings SET google_event_id = NULL WHERE id = ? AND google_event_id = ?')->execute([$booking_id, $gcal_event_id]);
            }
        }
    } catch (Throwable $e) {
        error_log('Google Calendar deletion failed for cancelled booking #' . $booking_id);
    }
    try {
        $email_service = new EmailService(null, $conn);
        if (!empty($booking['client_email'])) {
            $email_service->sendBookingCancellation($booking, $reason);
        }
        $email_service->sendAdminBookingChangeNotification($booking, 'cancellation', $reason);
    } catch (Throwable $e) {
        error_log('Cancellation email failed for booking #' . $booking_id);
    }

    echo json_encode(['success' => true, 'message' => 'Your appointment has been cancelled.']);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════
 *  Action: reschedule
 * ═════════════════════════════════════════════════════════════════════════ */
if ($action === 'reschedule') {
    $new_date = trim(scalar_string($data['new_date'] ?? ''));
    $new_time = trim(scalar_string($data['new_time'] ?? ''));

    $schedule_lock = null;
    $reschedule_error = null;
    $new_time_hhmm = substr($new_time, 0, 5);
    try {
        $schedule_lock = new BookingScheduleLock($conn);
        $conn->beginTransaction();
        $lock_sql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $conn->prepare('SELECT * FROM bookings WHERE id = ?' . $lock_sql);
        $stmt->execute([$booking_id]);
        $current = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
        if ($current === [] || !in_array(array_string_value($current, 'status'), $allowed_statuses, true)
            || array_string_value($current, 'appointment_date') !== array_string_value($booking, 'appointment_date')
            || array_string_value($current, 'appointment_time') !== array_string_value($booking, 'appointment_time')
            || array_int_value($current, 'appointment_type_id') !== array_int_value($booking, 'appointment_type_id')
            || array_int_value($current, 'client_id') !== array_int_value($booking, 'client_id')) {
            throw new RuntimeException('This appointment has changed. Please refresh your bookings.');
        }
        $stmt = $conn->prepare('SELECT COUNT(*) FROM appointment_pets WHERE booking_id = ?');
        $stmt->execute([$booking_id]);
        $pet_count = safe_int($stmt->fetchColumn());
        $stmt = $conn->prepare('SELECT * FROM appointment_types WHERE id = ? AND is_active = 1');
        $stmt->execute([array_int_value($booking, 'appointment_type_id')]);
        $current_type = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
        $resource = bdta_booking_resource_config($current_type);
        $slot_error = bdta_booking_slot_error($conn, array_int_value($booking, 'appointment_type_id'), $new_date, $new_time,
            $booking_id, bdta_booking_resource_units($resource, $pet_count), max(1, array_int_value($current, 'duration_minutes', 60)),
            ($current['admin_user_id'] ?? null) === null ? null : array_int_value($current, 'admin_user_id'));
        if ($slot_error !== null) { throw new RuntimeException($slot_error); }
        $old_date = $booking['appointment_date'];
        $old_time = $booking['appointment_time'];

        // Update booking with new date and time
        $conn->prepare("
            UPDATE bookings
            SET appointment_date = ?, appointment_time = ?, status = 'confirmed', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$new_date, $new_time_hhmm, $booking_id]);

        // Log the change
        $conn->prepare("
            INSERT INTO booking_change_log
                (booking_id, client_id, change_type, reason, old_date, old_time, new_date, new_time, initiated_by, ip_address)
            VALUES (?, ?, 'reschedule', ?, ?, ?, ?, ?, 'client', ?)
        ")->execute([$booking_id, $client_id, $reason ?: null, $old_date, $old_time, $new_date, $new_time_hhmm, $client_ip]);

        // Activity log
        logClientActivity($client_id, 'appointment_reschedule', "Rescheduled booking #{$booking_id} to {$new_date} {$new_time_hhmm}", $conn);

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $reschedule_error = $e instanceof RuntimeException && !($e instanceof PDOException)
            ? $e->getMessage() : 'The appointment could not be rescheduled. Please try again.';
    } finally {
        $schedule_lock?->release();
    }
    if ($reschedule_error !== null) {
        echo json_encode(['error' => $reschedule_error]);
        exit;
    }
    // Update or remove the Google Calendar event
    if (!empty($booking['google_event_id'])) {
        // Build a synthetic booking row with updated date/time for calendar update
        $updated_booking = array_merge($booking, [
            'appointment_date' => $new_date,
            'appointment_time' => $new_time_hhmm,
        ]);
        $gcal_result = GoogleCalendarIntegration::updateEventForBooking($updated_booking, array_string_value($booking, 'google_event_id'));
        // If update failed, fall back to delete so stale event is removed
        if (empty($gcal_result['success'])
            && GoogleCalendarIntegration::deleteEventForBooking(array_string_value($booking, 'google_event_id'), $booking)
        ) {
            $conn->prepare("UPDATE bookings SET google_event_id = NULL WHERE id = ?")->execute([$booking_id]);
        }
    }

    // Fetch updated booking row for emails
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $updated_booking = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($updated_booking === []) {
        echo json_encode(['error' => 'Updated booking could not be loaded.']);
        exit;
    }

    // Send emails
    $email_service = new EmailService(null, $conn);
    if (!empty($booking['client_email'])) {
        $email_service->sendBookingReschedule($updated_booking, array_string_value($booking, 'appointment_date'), array_string_value($booking, 'appointment_time'), $reason);
    }
    $email_service->sendAdminBookingChangeNotification($updated_booking, 'reschedule', $reason, array_string_value($booking, 'appointment_date'), array_string_value($booking, 'appointment_time'));
    bdta_create_admin_notifications(
        $conn,
        'booking',
        $booking_id,
        'Booking rescheduled',
        array_string_value($booking, 'client_name', 'Client') . ' moved booking #' . $booking_id . ' from ' . array_string_value($booking, 'appointment_date') . ' ' . array_string_value($booking, 'appointment_time') . ' to ' . $new_date . ' ' . $new_time_hhmm . '.',
        '/client/bookings_list.php'
    );

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
