<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/follow_up_notes.php';
require_once '../backend/includes/invoice_status.php';
require_once '../backend/includes/google_calendar.php';
require_once '../backend/includes/achievements.php';
requireLogin();

const BDTA_SECONDS_PER_DAY = 60 * 60 * 24;

function bdta_booking_action_request_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = trim(explode(',', scalar_string($_SERVER['HTTP_X_FORWARDED_FOR']))[0]);
        return filter_var($forwarded, FILTER_VALIDATE_IP) ? $forwarded : scalar_string($_SERVER['REMOTE_ADDR'] ?? '');
    }

    return scalar_string($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * @param array<string, mixed> $appointment
 */
function bdta_client_view_appointment_is_past(array $appointment, ?DateTimeImmutable $reference_time = null): bool
{
    $reference_time = $reference_time ?? new DateTimeImmutable('now', bdta_get_display_timezone());

    $appointment_date = array_string_value($appointment, 'appointment_date');
    if ($appointment_date === '') {
        return false;
    }

    $appointment_time = trim(array_string_value($appointment, 'appointment_time'));
    if ($appointment_time === '') {
        return $appointment_date < $reference_time->format('Y-m-d');
    }

    $normalized_time = strlen($appointment_time) === 5 ? $appointment_time . ':00' : $appointment_time;
    $appointment_start = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $appointment_date . ' ' . $normalized_time,
        bdta_get_display_timezone()
    );
    $appointment_start_errors = DateTimeImmutable::getLastErrors();

    if (
        !$appointment_start instanceof DateTimeImmutable
        || ($appointment_start_errors !== false && (
            $appointment_start_errors['warning_count'] > 0
            || $appointment_start_errors['error_count'] > 0
        ))
    ) {
        return $appointment_date < $reference_time->format('Y-m-d');
    }

    return $appointment_start->modify('+1 hour') <= $reference_time;
}

function bdta_client_view_is_strict_award_date(string $date): bool
{
    $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $parsed_errors = DateTimeImmutable::getLastErrors();
    $has_errors = $parsed_errors !== false && (
        $parsed_errors['warning_count'] > 0
        || $parsed_errors['error_count'] > 0
    );

    return $parsed_date instanceof DateTimeImmutable
        && !$has_errors
        && $parsed_date->format('Y-m-d') === $date;
}

/**
 * @param array<string, mixed> $client
 * @param array<string, mixed> $achievement_type
 * @param array<string, mixed> $assignment
 */
function bdta_send_achievement_award_email(PDO $conn, array $client, array $achievement_type, array $assignment): void
{
    $client_email = trim(array_string_value($client, 'email'));
    if ($client_email === '') {
        return;
    }

    $client_name = array_string_value($client, 'name', 'Client');
    $achievement_title = array_string_value($achievement_type, 'title', 'Achievement');
    $award_mode = bdta_achievement_modes()[bdta_normalize_achievement_mode(array_string_value($achievement_type, 'award_mode'))] ?? 'Achievement';
    $portal_link = getDynamicBaseUrl() . '/portal/achievements.php';

    $subject = 'New achievement awarded: ' . $achievement_title;
    $html = '<p>Hi ' . escape($client_name) . ',</p>'
        . '<p>You have been awarded a new achievement from Brook&apos;s Dog Training Academy.</p>'
        . '<p><strong>' . escape($achievement_title) . '</strong><br><span style="color:#6c757d;">' . escape($award_mode) . '</span></p>'
        . '<p><a href="' . escape($portal_link) . '" style="display:inline-block;padding:12px 18px;border-radius:999px;background:#9a0073;color:#fff;text-decoration:none;">View achievements</a></p>';
    $text = "Hi {$client_name},\n\n"
        . "You have been awarded a new achievement from Brook's Dog Training Academy.\n\n"
        . "{$achievement_title} ({$award_mode})\n\n"
        . "View your achievements here: {$portal_link}\n";

    $email_service = new EmailService(null, $conn);
    $email_service->sendGenericEmail(
        $client_email,
        $subject,
        $html,
        $text,
        EmailService::MAIL_TYPE_GENERIC,
        array_int_value($client, 'id')
    );
}

/**
 * @param array<string, mixed> $client
 * @param array<string, mixed> $achievement_type
 */
function bdta_client_view_award_achievement(
    PDO $conn,
    int $client_id,
    array $client,
    array $achievement_type,
    string $awarded_on,
    string $dog_name,
    string $program_name,
    string $notes,
    int $admin_id
): int {
    $achievement_type_id = array_int_value($achievement_type, 'id');
    if ($achievement_type_id <= 0) {
        throw new RuntimeException('Selected achievement type was not found.');
    }

    $stmt = $conn->prepare("
        INSERT INTO client_achievements
            (client_id, achievement_type_id, status, awarded_on, dog_name, program_name, notes, awarded_by, updated_by)
        VALUES (?, ?, 'awarded', ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $client_id,
        $achievement_type_id,
        $awarded_on,
        $dog_name !== '' ? $dog_name : null,
        $program_name !== '' ? $program_name : null,
        $notes !== '' ? $notes : null,
        $admin_id > 0 ? $admin_id : null,
        $admin_id > 0 ? $admin_id : null,
    ]);
    $new_assignment_id = safe_int($conn->lastInsertId());

    $stmt = $conn->prepare("
        INSERT INTO achievement_assignment_log (client_achievement_id, action, status, notes, admin_user_id)
        VALUES (?, 'awarded', 'awarded', ?, ?)
    ");
    $stmt->execute([
        $new_assignment_id,
        $notes !== '' ? $notes : null,
        $admin_id > 0 ? $admin_id : null,
    ]);

    bdta_create_notification(
        $conn,
        'portal',
        $client_id,
        'achievement',
        $new_assignment_id,
        'New achievement awarded',
        array_string_value($achievement_type, 'title') . ' was added to your profile.',
        '/portal/achievements.php'
    );
    bdta_send_achievement_award_email($conn, $client, $achievement_type, [
        'id' => $new_assignment_id,
        'achievement_title' => array_string_value($achievement_type, 'title'),
        'client_name' => array_string_value($client, 'name'),
        'dog_name' => $dog_name,
        'program_name' => $program_name,
        'awarded_on' => $awarded_on,
        'notes' => $notes,
    ]);
    logClientActivity(
        $client_id,
        'achievement_awarded',
        'Achievement awarded: ' . array_string_value($achievement_type, 'title'),
        $conn
    );

    return $new_assignment_id;
}

$db = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);

if ($id <= 0) {
    setFlashMessage('Invalid client ID!', 'danger');
    redirect('clients_list.php');
}

// Get client details
$stmt = $conn->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($client)) {
    setFlashMessage('Client not found!', 'danger');
    redirect('clients_list.php');
}

$client_view_url = 'clients_view.php?id=' . $id;
$clientListUrl = !empty($client['is_archived']) ? 'clients_list.php?view=archived' : 'clients_list.php';
$allowed_tabs = ['appointments', 'contracts', 'forms', 'quotes', 'invoices', 'emails', 'achievements'];
$active_tab = scalar_string($_GET['tab'] ?? 'appointments');
if (!in_array($active_tab, $allowed_tabs, true)) {
    $active_tab = 'appointments';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['achievement_action'])) {
    requireValidCsrfToken($client_view_url . '&tab=achievements');

    $achievement_action = scalar_string($_POST['achievement_action']);
    $admin_id = safe_int($_SESSION['admin_id'] ?? 0);

    try {
        if ($achievement_action === 'save_assignment') {
            $assignment_id = safe_int($_POST['assignment_id'] ?? 0);
            $achievement_type_id = safe_int($_POST['achievement_type_id'] ?? 0);
            $awarded_on = trim(scalar_string($_POST['awarded_on'] ?? ''));
            $dog_name = trim(scalar_string($_POST['dog_name'] ?? ''));
            $program_name = trim(scalar_string($_POST['program_name'] ?? ''));
            $notes = trim(scalar_string($_POST['notes'] ?? ''));

            if ($achievement_type_id <= 0) {
                throw new RuntimeException('Select an achievement type before saving an assignment.');
            }
            if (!bdta_client_view_is_strict_award_date($awarded_on)) {
                throw new RuntimeException('Choose a valid award date.');
            }

            $stmt = $conn->prepare("SELECT * FROM achievement_types WHERE id = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$achievement_type_id]);
            $achievement_type = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
            if ($achievement_type === []) {
                throw new RuntimeException('Selected achievement type was not found.');
            }

            if ($assignment_id > 0) {
                $stmt = $conn->prepare("
                    SELECT id, status
                    FROM client_achievements
                    WHERE id = ? AND client_id = ?
                    LIMIT 1
                ");
                $stmt->execute([$assignment_id, $id]);
                $existing_assignment = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
                if ($existing_assignment === []) {
                    throw new RuntimeException('Achievement assignment not found.');
                }

                $stmt = $conn->prepare("
                    UPDATE client_achievements
                    SET achievement_type_id = ?,
                        awarded_on = ?,
                        dog_name = ?,
                        program_name = ?,
                        notes = ?,
                        updated_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND client_id = ?
                ");
                $stmt->execute([
                    $achievement_type_id,
                    $awarded_on,
                    $dog_name !== '' ? $dog_name : null,
                    $program_name !== '' ? $program_name : null,
                    $notes !== '' ? $notes : null,
                    $admin_id > 0 ? $admin_id : null,
                    $assignment_id,
                    $id,
                ]);

                $stmt = $conn->prepare("
                    INSERT INTO achievement_assignment_log (client_achievement_id, action, status, notes, admin_user_id)
                    VALUES (?, 'updated', ?, ?, ?)
                ");
                $stmt->execute([
                    $assignment_id,
                    array_string_value($existing_assignment, 'status', 'awarded'),
                    $notes !== '' ? $notes : null,
                    $admin_id > 0 ? $admin_id : null,
                ]);

                setFlashMessage('Achievement assignment updated.', 'success');
            } else {
                bdta_client_view_award_achievement(
                    $conn,
                    $id,
                    $client,
                    $achievement_type,
                    $awarded_on,
                    $dog_name,
                    $program_name,
                    $notes,
                    $admin_id
                );
                setFlashMessage('Achievement awarded and client notified.', 'success');
            }
        } elseif ($achievement_action === 'save_custom_assignment') {
            $awarded_on = trim(scalar_string($_POST['awarded_on'] ?? ''));
            $dog_name = trim(scalar_string($_POST['dog_name'] ?? ''));
            $program_name = trim(scalar_string($_POST['program_name'] ?? ''));
            $notes = trim(scalar_string($_POST['notes'] ?? ''));

            if (!bdta_client_view_is_strict_award_date($awarded_on)) {
                throw new RuntimeException('Choose a valid award date.');
            }

            $badge_icon_upload = isset($_FILES['badge_icon']) && is_array($_FILES['badge_icon'])
                ? assoc_row($_FILES['badge_icon'])
                : [];
            $certificate_template_upload = isset($_FILES['certificate_template']) && is_array($_FILES['certificate_template'])
                ? assoc_row($_FILES['certificate_template'])
                : [];
            $custom_type_id = bdta_save_achievement_type(
                $conn,
                [
                    'title' => scalar_string($_POST['title'] ?? ''),
                    'description' => scalar_string($_POST['description'] ?? ''),
                    'scope_type' => 'custom',
                    'award_mode' => scalar_string($_POST['award_mode'] ?? 'badge_certificate'),
                    'certificate_body_html' => scalar_string($_POST['certificate_body_html'] ?? ''),
                ],
                $badge_icon_upload,
                $certificate_template_upload,
                $admin_id
            );

            bdta_client_view_award_achievement(
                $conn,
                $id,
                $client,
                [
                    'id' => $custom_type_id,
                    'title' => scalar_string($_POST['title'] ?? ''),
                    'award_mode' => scalar_string($_POST['award_mode'] ?? 'badge_certificate'),
                ],
                $awarded_on,
                $dog_name,
                $program_name,
                $notes,
                $admin_id
            );
            setFlashMessage('Custom achievement created, awarded, and client notified.', 'success');
        } elseif ($achievement_action === 'revoke_assignment') {
            $assignment_id = safe_int($_POST['assignment_id'] ?? 0);
            $stmt = $conn->prepare("
                SELECT ca.id, at.title
                FROM client_achievements ca
                INNER JOIN achievement_types at ON at.id = ca.achievement_type_id
                WHERE ca.id = ? AND ca.client_id = ?
                LIMIT 1
            ");
            $stmt->execute([$assignment_id, $id]);
            $assignment_row = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
            if ($assignment_row === []) {
                throw new RuntimeException('Achievement assignment not found.');
            }

                $stmt = $conn->prepare("
                    UPDATE client_achievements
                    SET status = 'revoked',
                        revoked_by = ?,
                        revoked_at = CURRENT_TIMESTAMP,
                        updated_by = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND client_id = ?
                ");
                $stmt->execute([
                    $admin_id > 0 ? $admin_id : null,
                    $admin_id > 0 ? $admin_id : null,
                    $assignment_id,
                    $id,
                ]);

            $stmt = $conn->prepare("
                INSERT INTO achievement_assignment_log (client_achievement_id, action, status, notes, admin_user_id)
                VALUES (?, 'revoked', 'revoked', ?, ?)
            ");
            $stmt->execute([
                $assignment_id,
                'Achievement revoked',
                $admin_id > 0 ? $admin_id : null,
            ]);
            logClientActivity($id, 'achievement_revoked', 'Achievement revoked: ' . array_string_value($assignment_row, 'title'), $conn);
            setFlashMessage('Achievement revoked.', 'warning');
        } else {
            throw new RuntimeException('Unsupported achievement action.');
        }
    } catch (Throwable $e) {
        setFlashMessage($e->getMessage(), 'danger');
    }

    redirect($client_view_url . '&tab=achievements');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_action'])) {
    requireValidCsrfToken($client_view_url);

    $booking_action = scalar_string($_POST['booking_action']);
    $booking_id = safe_int($_POST['booking_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT b.*,
               at.duration_minutes AS appointment_type_duration_minutes,
               at.advance_booking_min_days,
               at.advance_booking_max_days
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        WHERE b.id = ? AND b.client_id = ?
        LIMIT 1
    ");
    $stmt->execute([$booking_id, $id]);
    $booking_for_action = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

    if ($booking_id <= 0 || $booking_for_action === []) {
        setFlashMessage('Booking not found for this client.', 'danger');
        redirect($client_view_url);
    }

    $booking_status = array_string_value($booking_for_action, 'status');
    if (!in_array($booking_status, ['pending', 'confirmed'], true)) {
        setFlashMessage('Only pending or confirmed bookings can be updated here.', 'warning');
        redirect($client_view_url);
    }

    $booking_start_ts = strtotime(
        array_string_value($booking_for_action, 'appointment_date') . ' ' . array_string_value($booking_for_action, 'appointment_time')
    );
    if ($booking_start_ts === false || $booking_start_ts <= time()) {
        setFlashMessage('Only upcoming bookings can be updated here.', 'warning');
        redirect($client_view_url);
    }

    $client_ip = bdta_booking_action_request_ip();
    $email_service = new EmailService(null, $conn);

    if ($booking_action === 'cancel') {
        $conn->prepare("UPDATE bookings SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$booking_id]);

        if (!empty($booking_for_action['google_event_id'])
            && GoogleCalendarIntegration::deleteEventForBooking(array_string_value($booking_for_action, 'google_event_id'), $booking_for_action)
        ) {
            $conn->prepare("UPDATE bookings SET google_event_id = NULL WHERE id = ?")->execute([$booking_id]);
        }

        $pkg_credit_id = safe_int($booking_for_action['package_credit_id'] ?? 0);
        $credit_refunded = false;
        if ($pkg_credit_id > 0) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM package_credit_transactions
                WHERE client_package_credit_id = ? AND booking_id = ? AND transaction_type = 'refund'
            ");
            $stmt->execute([$pkg_credit_id, $booking_id]);
            if (!safe_int($stmt->fetchColumn())) {
                $conn->prepare("
                    UPDATE client_package_credits
                    SET used_credits = used_credits - 1, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND used_credits > 0
                ")->execute([$pkg_credit_id]);

                $stmt = $conn->prepare("SELECT appointment_type_id, client_id FROM client_package_credits WHERE id = ?");
                $stmt->execute([$pkg_credit_id]);
                $credit_row = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
                if ($credit_row !== []) {
                    $conn->prepare("
                        INSERT INTO package_credit_transactions
                            (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
                        VALUES (?, ?, ?, 'refund', 1, ?, ?, ?)
                    ")->execute([
                        $pkg_credit_id,
                        array_int_value($credit_row, 'client_id'),
                        array_int_value($credit_row, 'appointment_type_id'),
                        $booking_id,
                        "Credit refunded for cancelled booking #{$booking_id}",
                        safe_int($_SESSION['admin_id'] ?? 0),
                    ]);
                    $credit_refunded = true;
                }
            }
        }

        $conn->prepare("
            INSERT INTO booking_change_log
                (booking_id, client_id, change_type, old_date, old_time, initiated_by, ip_address)
            VALUES (?, ?, 'cancellation', ?, ?, 'admin', ?)
        ")->execute([
            $booking_id,
            $id,
            array_string_value($booking_for_action, 'appointment_date'),
            array_string_value($booking_for_action, 'appointment_time'),
            $client_ip,
        ]);

        if (!empty($booking_for_action['client_email'])) {
            $email_service->sendBookingCancellation($booking_for_action);
        }
        if (!empty($booking_for_action['client_id'])) {
            bdta_create_notification(
                $conn,
                'portal',
                safe_int($booking_for_action['client_id']),
                'booking',
                $booking_id,
                'Appointment cancelled',
                array_string_value($booking_for_action, 'service_type') . ' on ' . array_string_value($booking_for_action, 'appointment_date'),
                '/portal/appointments.php'
            );
        }

        setFlashMessage(
            $credit_refunded ? 'Booking cancelled and credit refunded.' : 'Booking cancelled.',
            'success'
        );
        redirect($client_view_url);
    }

    if ($booking_action === 'reschedule') {
        $new_date = trim(scalar_string($_POST['new_date'] ?? ''));
        $new_time = trim(scalar_string($_POST['new_time'] ?? ''));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $new_time)) {
            setFlashMessage('Choose a valid reschedule date and time.', 'danger');
            redirect($client_view_url);
        }

        $new_time_hhmm = substr($new_time, 0, 5);
        $new_datetime = strtotime($new_date . ' ' . $new_time_hhmm);
        if ($new_datetime === false || $new_datetime <= time()) {
            setFlashMessage('The new appointment time must be in the future.', 'danger');
            redirect($client_view_url);
        }

        $apt_type_id = safe_int($booking_for_action['appointment_type_id'] ?? 0);
        if ($apt_type_id <= 0) {
            setFlashMessage('This booking cannot be rescheduled from the client profile because it has no appointment type.', 'warning');
            redirect($client_view_url);
        }

        $old_date = array_string_value($booking_for_action, 'appointment_date');
        $old_time = array_string_value($booking_for_action, 'appointment_time');

        $conn->prepare("
            UPDATE bookings
            SET appointment_date = ?, appointment_time = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$new_date, $new_time_hhmm, $booking_id]);

        if (!empty($booking_for_action['google_event_id']) && GoogleCalendarIntegration::isOAuthConfigured()) {
            $updated_booking = array_merge($booking_for_action, [
                'appointment_date' => $new_date,
                'appointment_time' => $new_time_hhmm,
            ]);
            $gcal_result = GoogleCalendarIntegration::updateEventForBooking($updated_booking, array_string_value($booking_for_action, 'google_event_id'));
            if (empty($gcal_result['success'])
                && GoogleCalendarIntegration::deleteEventForBooking(array_string_value($booking_for_action, 'google_event_id'), $booking_for_action)
            ) {
                $conn->prepare("UPDATE bookings SET google_event_id = NULL WHERE id = ?")->execute([$booking_id]);
            }
        }

        $conn->prepare("
            INSERT INTO booking_change_log
                (booking_id, client_id, change_type, old_date, old_time, new_date, new_time, initiated_by, ip_address)
            VALUES (?, ?, 'reschedule', ?, ?, ?, ?, 'admin', ?)
        ")->execute([
            $booking_id,
            $id,
            $old_date,
            $old_time,
            $new_date,
            $new_time_hhmm,
            $client_ip,
        ]);

        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $updated_booking = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

        if ($updated_booking !== [] && !empty($updated_booking['client_email'])) {
            $email_service->sendBookingReschedule($updated_booking, $old_date, $old_time);
        }
        if ($updated_booking !== [] && !empty($updated_booking['client_id'])) {
            bdta_create_notification(
                $conn,
                'portal',
                safe_int($updated_booking['client_id']),
                'booking',
                $booking_id,
                'Appointment rescheduled',
                array_string_value($updated_booking, 'service_type') . ' moved to ' . $new_date . ' ' . $new_time_hhmm,
                '/portal/appointments.php'
            );
        }

        setFlashMessage('Booking rescheduled.', 'success');
        redirect($client_view_url);
    }

    setFlashMessage('Unknown booking action requested.', 'danger');
    redirect($client_view_url);
}

// Get client's pets with file count
$stmt = $conn->prepare("
    SELECT p.*, COUNT(pf.id) as file_count
    FROM pets p
    LEFT JOIN pet_files pf ON p.id = pf.pet_id
    WHERE p.client_id = ?
    GROUP BY p.id
    ORDER BY COALESCE(p.is_active, 1) DESC, p.name
");
$stmt->execute([$id]);
$pets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get appointments (past and upcoming)
$stmt = $conn->prepare("
    SELECT b.*, at.name as appointment_type_name, at.advance_booking_min_days, at.advance_booking_max_days, at.duration_minutes AS appointment_type_duration_minutes
    FROM bookings b
    LEFT JOIN appointment_types at ON b.appointment_type_id = at.id
    WHERE b.client_id = ?
    ORDER BY b.appointment_date DESC, b.appointment_time DESC
");
$stmt->execute([$id]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Separate past and upcoming
$upcoming_appointments = [];
$past_appointments = [];
$appointment_split_time = new DateTimeImmutable('now', bdta_get_display_timezone());
foreach ($appointments as $apt) {
    if (bdta_client_view_appointment_is_past($apt, $appointment_split_time)) {
        $past_appointments[] = $apt;
    } else {
        $upcoming_appointments[] = $apt;
    }
}

// Get contracts
$stmt = $conn->prepare("SELECT * FROM contracts WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get forms
$stmt = $conn->prepare("
    SELECT fs.*, ft.name as form_name, ft.form_type,
           p.name AS pet_name,
           b.appointment_date,
           b.appointment_time,
           b.service_type
    FROM form_submissions fs
    JOIN form_templates ft ON fs.template_id = ft.id
    LEFT JOIN pets p ON fs.pet_id = p.id
    LEFT JOIN bookings b ON fs.booking_id = b.id
    WHERE fs.client_id = ?
    ORDER BY fs.submitted_at DESC
");
$stmt->execute([$id]);
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
$follow_up_submissions_by_booking = bdta_index_follow_up_submissions_by_booking($forms);

// Get quotes
$stmt = $conn->prepare("SELECT * FROM quotes WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get invoices
$stmt = $conn->prepare("SELECT * FROM invoices WHERE client_id = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get active package credit summary per appointment type
$stmt = $conn->prepare("
    SELECT at.name AS apt_type_name,
           SUM(cpc.total_credits - cpc.used_credits) AS remaining
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    JOIN appointment_types at ON cpc.appointment_type_id = at.id
    WHERE cpc.client_id = ?
      AND cp.is_active = 1
      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
      AND (cpc.total_credits - cpc.used_credits) > 0
    GROUP BY cpc.appointment_type_id, at.name
");
$stmt->execute([$id]);
$pkg_credits_summary = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $summary_row) {
    $pkg_credits_summary[array_string_value($summary_row, 'apt_type_name')] = array_int_value($summary_row, 'remaining');
}

// Get email count
$stmt = $conn->prepare("SELECT COUNT(*) as email_count FROM client_emails WHERE client_id = ?");
$stmt->execute([$id]);
$email_count = safe_int($stmt->fetchColumn());

// Get client contacts
$stmt = $conn->prepare("SELECT * FROM client_contacts WHERE client_id = ? ORDER BY is_primary DESC, name ASC");
$stmt->execute([$id]);
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get achievement types and assignments
$achievement_types = bdta_get_achievement_types($conn, 'general', true);
$achievement_type_ids = array_fill_keys(
    array_map(static fn (array $row): int => array_int_value($row, 'id'), $achievement_types),
    true
);
$achievement_form_mode = empty($achievement_types) ? 'custom' : 'reusable';

$client_achievements = bdta_get_client_achievement_rows($conn, $id, true);
$achievement_logs_by_assignment = bdta_get_achievement_logs_grouped(
    $conn,
    array_map(static fn (array $row): int => array_int_value($row, 'id'), $client_achievements)
);
$active_badge_count = count(array_filter(
    $client_achievements,
    static fn (array $row): bool => array_string_value($row, 'status', 'awarded') === 'awarded'
        && bdta_achievement_mode_supports_badge(array_string_value($row, 'award_mode'))
));

include '../backend/includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <i class="fas fa-user-circle me-2"></i><?= escape($client['name']) ?>
            <?php if (!empty($client['is_archived'])): ?>
                <span class="badge bg-secondary ms-2"><i class="fas fa-box-archive me-1"></i>Archived</span>
            <?php endif; ?>
        </h2>
        <div>
            <a href="clients_edit.php?id=<?= $id ?>" class="btn btn-primary me-2">
                <i class="fas fa-pencil"></i> Edit Client
            </a>
            <?php if (empty($client['is_archived'])): ?>
            <a href="bookings_create.php?client_id=<?= $id ?>" class="btn btn-success me-2">
                <i class="fas fa-calendar-plus"></i> New Booking
            </a>
            <?php endif; ?>
            <?php if (empty($client['is_admin']) && empty($client['is_archived'])): ?>
            <a href="impersonate_client.php?id=<?= $id ?>" class="btn btn-warning me-2"
               onclick="return confirm('View the client portal as this client?')">
                <i class="fas fa-eye"></i> View Portal as Client
            </a>
            <?php endif; ?>
            <form method="post" action="clients_list.php" class="d-inline me-2" onsubmit="return confirm('<?= !empty($client['is_archived']) ? 'Unarchive this client and return them to the active client list?' : 'Archive this client? Pending items such as quotes, contracts, invoices, forms, workflows, and bookings will be cancelled or voided.' ?>')">
                <input type="hidden" name="<?= !empty($client['is_archived']) ? 'unarchive_id' : 'archive_id' ?>" value="<?= $id ?>">
                <input type="hidden" name="return_view" value="<?= !empty($client['is_archived']) ? 'archived' : 'active' ?>">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <button type="submit" class="btn <?= !empty($client['is_archived']) ? 'btn-info' : 'btn-outline-secondary' ?>">
                    <i class="fas <?= !empty($client['is_archived']) ? 'fa-box-open' : 'fa-box-archive' ?>"></i>
                    <?= !empty($client['is_archived']) ? 'Unarchive Client' : 'Archive Client' ?>
                </button>
            </form>
            <a href="<?= $clientListUrl ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <?php
    $flash = getFlashMessage();
    if ($flash):
    ?>
        <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show">
            <?= escape($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($client['is_archived'])): ?>
        <div class="alert alert-secondary">
            <i class="fas fa-box-archive me-2"></i>
            This client is archived and hidden from active client selectors. Historical records remain available here.
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Client Info Card -->
        <div class="col-md-4 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-circle-info me-2"></i>Client Information</h5>
                </div>
                <div class="card-body">
                    <dl class="mb-0">
                        <dt>Primary Contact:</dt>
                        <dd>
                            <a href="mailto:<?= escape($client['email']) ?>"><?= escape($client['email']) ?></a>
                            <br>
                            <small class="text-muted"><?= escape($client['phone'] ?: 'No phone') ?></small>
                        </dd>
                        
                        <dt>Address:</dt>
                        <dd><?= escape($client['address'] ?: 'Not provided') ?></dd>
                        
                        <dt>Member Since:</dt>
                        <dd><?= formatDate($client['created_at']) ?></dd>

                        <?php if (!empty($client['is_archived']) && !empty($client['archived_at'])): ?>
                            <dt>Archived On:</dt>
                            <dd><?= formatDate($client['archived_at']) ?></dd>
                        <?php endif; ?>
                        
                        <?php if (!empty($pkg_credits_summary)): ?>
                            <dt>Credits:</dt>
                            <dd>
                                <?php foreach ($pkg_credits_summary as $apt_name => $rem): ?>
                                     <span class="badge bg-primary me-1"><?= escape($apt_name) ?>: <?= (int)$rem ?></span>
                                <?php endforeach; ?>
                            </dd>
                        <?php endif; ?>

                        <dt>Packages &amp; Credits:</dt>
                        <dd>
                            <a href="credits_manage.php?client_id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-wallet"></i> Manage Credits &amp; Packages
                            </a>
                        </dd>
                        
                        <?php if ($client['notes']): ?>
                            <dt>Notes:</dt>
                            <dd><?= nl2br(escape($client['notes'])) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>

            <!-- Contacts Card -->
            <div class="card mt-3">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-address-book me-2"></i>Additional Contacts</h5>
                    <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#contactModal" onclick="showAddContactModal()">
                        <i class="fas fa-plus"></i> Add Contact
                    </button>
                </div>
                <div class="card-body" id="contactsList">
                    <?php if (empty($contacts)): ?>
                        <p class="text-muted mb-0">No additional contacts</p>
                    <?php else: ?>
                        <?php foreach ($contacts as $contact): ?>
                            <div class="border-bottom pb-2 mb-2" id="contact-<?= $contact['id'] ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= escape($contact['name']) ?></strong>
                                        <?php if ($contact['is_primary']): ?>
                                            <span class="badge bg-primary ms-1">Primary</span>
                                        <?php endif; ?>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-envelope"></i> <a href="mailto:<?= escape($contact['email']) ?>"><?= escape($contact['email']) ?></a>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-phone"></i> <?= escape($contact['phone']) ?>
                                        </small>
                                    </div>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary edit-contact-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#contactModal"
                                                data-contact-id="<?= $contact['id'] ?>"
                                                data-contact-name="<?= escape($contact['name']) ?>"
                                                data-contact-email="<?= escape($contact['email']) ?>"
                                                data-contact-phone="<?= escape($contact['phone']) ?>"
                                                data-contact-primary="<?= $contact['is_primary'] ?>">
                                            <i class="fas fa-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-danger delete-contact-btn" 
                                                data-contact-id="<?= $contact['id'] ?>"
                                                data-contact-name="<?= escape($contact['name']) ?>">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pets Card -->
            <div class="card mt-3">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-dog me-2"></i>Pets</h5>
                    <a href="pets_edit.php?client_id=<?= $id ?>" class="btn btn-sm btn-light">
                        <i class="fas fa-plus"></i> Add Pet
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($pets)): ?>
                        <p class="text-muted mb-0">No pets registered</p>
                    <?php else: ?>
                        <?php foreach ($pets as $pet): ?>
                            <?php
                            $pet_is_active = array_int_value($pet, 'is_active', 1) === 1;
                            $pet_archived_classes = $pet_is_active ? '' : ' bg-light rounded px-2 text-muted opacity-75';
                            ?>
                            <div class="border-bottom pb-2 mb-2<?= $pet_archived_classes ?>">
                                <strong>
                                    <a href="pets_view.php?id=<?= (int) $pet['id'] ?>" class="text-decoration-none">
                                        <?= escape($pet['name']) ?>
                                    </a>
                                    <?php if (!$pet_is_active): ?>
                                        <span class="badge bg-secondary ms-1">Archived</span>
                                    <?php endif; ?>
                                </strong>
                                <small class="text-muted d-block">
                                    <?= escape($pet['species']) ?> 
                                    <?= $pet['breed'] ? '- ' . escape($pet['breed']) : '' ?>
                                </small>
                                <?php if ($pet['file_count'] > 0): ?>
                                    <small class="text-info d-block">
                                        <i class="fas fa-paperclip"></i> <?= $pet['file_count'] ?> file(s) uploaded
                                    </small>
                                <?php endif; ?>
                                <a href="pets_view.php?id=<?= (int) $pet['id'] ?>" class="btn btn-xs btn-outline-info mt-1">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="pets_edit.php?id=<?= $pet['id'] ?>" class="btn btn-xs btn-outline-secondary mt-1">
                                    <i class="fas fa-pencil"></i> Edit
                                </a>
                                <form method="post" action="pets_delete.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this pet?')">
                                    <input type="hidden" name="id" value="<?= (int) $pet['id'] ?>">
                                    <input type="hidden" name="client_id" value="<?= (int) $id ?>">
                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                    <button type="submit" class="btn btn-xs btn-outline-danger mt-1">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                                <a href="form_requests_create.php?form_type=pet_form&amp;pet_id=<?= (int) $pet['id'] ?>" class="btn btn-xs btn-outline-success mt-1">
                                    <i class="fas fa-file-medical"></i> Pet Form
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Activity Tabs -->
        <div class="col-md-8">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'appointments' ? 'active' : '' ?>" data-bs-toggle="tab" href="#appointments">
                        <i class="fas fa-calendar-check"></i> Appointments 
                        <span class="badge bg-primary"><?= count($appointments) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'contracts' ? 'active' : '' ?>" data-bs-toggle="tab" href="#contracts">
                        <i class="fas fa-file-invoice"></i> Contracts 
                        <span class="badge bg-secondary"><?= count($contracts) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'forms' ? 'active' : '' ?>" data-bs-toggle="tab" href="#forms">
                        <i class="fas fa-list-check"></i> Forms 
                        <span class="badge bg-secondary"><?= count($forms) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'quotes' ? 'active' : '' ?>" data-bs-toggle="tab" href="#quotes">
                        <i class="fas fa-file-ruled"></i> Quotes 
                        <span class="badge bg-secondary"><?= count($quotes) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'invoices' ? 'active' : '' ?>" data-bs-toggle="tab" href="#invoices">
                        <i class="fas fa-receipt"></i> Invoices 
                        <span class="badge bg-secondary"><?= count($invoices) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'emails' ? 'active' : '' ?>" data-bs-toggle="tab" href="#emails">
                        <i class="fas fa-envelope"></i> Email 
                        <span class="badge bg-secondary"><?= $email_count ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $active_tab === 'achievements' ? 'active' : '' ?>" data-bs-toggle="tab" href="#achievements">
                        <i class="fas fa-award"></i> Achievements
                        <span class="badge bg-secondary"><?= count($client_achievements) ?></span>
                    </a>
                </li>
            </ul>

            <div class="tab-content border border-top-0 p-3">
                <!-- Appointments Tab -->
                <div id="appointments" class="tab-pane fade <?= $active_tab === 'appointments' ? 'show active' : '' ?>">
                    <h5>Upcoming Appointments</h5>
                    <?php if (empty($upcoming_appointments)): ?>
                        <p class="text-muted">No upcoming appointments</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($upcoming_appointments as $apt): ?>
                                        <?php
                                        $can_manage_upcoming = in_array(array_string_value($apt, 'status'), ['pending', 'confirmed'], true);
                                        $can_reschedule_upcoming = $can_manage_upcoming && array_int_value($apt, 'appointment_type_id') > 0;
                                        $appointment_date_display = formatDate(array_string_value($apt, 'appointment_date'));
                                        $appointment_time_display = date('g:i A', safe_timestamp(strtotime(array_string_value($apt, 'appointment_time'))));
                                        ?>
                                        <tr>
                                            <td><?= $appointment_date_display ?></td>
                                            <td><?= $appointment_time_display ?></td>
                                            <td><?= escape($apt['appointment_type_name'] ?: $apt['service_type']) ?></td>
                                            <td><span class="badge bg-info"><?= escape($apt['status']) ?></span></td>
                                            <td>
                                                <?php if ($can_manage_upcoming): ?>
                                                    <div class="d-flex flex-wrap gap-1">
                                                        <?php if ($can_reschedule_upcoming): ?>
                                                            <button type="button"
                                                                    class="btn btn-xs btn-outline-primary"
                                                                    data-booking-id="<?= (int) $apt['id'] ?>"
                                                                    data-type-id="<?= (int) $apt['appointment_type_id'] ?>"
                                                                    data-type-name="<?= escape($apt['appointment_type_name'] ?: $apt['service_type']) ?>"
                                                                    onclick="showAdminRescheduleModal(this)">
                                                                <i class="fas fa-calendar-alt"></i> Reschedule
                                                            </button>
                                                        <?php endif; ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this booking?')">
                                                            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                                            <input type="hidden" name="booking_action" value="cancel">
                                                            <input type="hidden" name="booking_id" value="<?= (int) $apt['id'] ?>">
                                                            <button type="submit" class="btn btn-xs btn-outline-danger">
                                                                <i class="fas fa-times-circle"></i> Cancel
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted small">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <h5 class="mt-4">Past Appointments</h5>
                    <?php if (empty($past_appointments)): ?>
                        <p class="text-muted">No past appointments</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Service</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($past_appointments, 0, 10) as $apt): ?>
                                        <?php
                                        $follow_up_submission = $follow_up_submissions_by_booking[array_int_value($apt, 'id')] ?? null;
                                        $follow_up_status = is_array($follow_up_submission)
                                            ? array_string_value($follow_up_submission, 'status')
                                            : '';
                                        $follow_up_badge = $follow_up_status === 'reviewed'
                                            ? 'bg-success'
                                            : 'bg-primary';
                                        ?>
                                        <tr>
                                            <td><?= formatDate($apt['appointment_date']) ?></td>
                                            <td><?= date('g:i A', safe_timestamp(strtotime($apt['appointment_time']))) ?></td>
                                            <td><?= escape($apt['appointment_type_name'] ?: $apt['service_type']) ?></td>
                                            <td><span class="badge bg-secondary"><?= escape($apt['status']) ?></span></td>
                                            <td>
                                                <?php if (is_array($follow_up_submission)): ?>
                                                    <a href="form_submissions_view.php?id=<?= array_int_value($follow_up_submission, 'id') ?>" class="btn btn-xs btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View Follow-up
                                                    </a>
                                                    <span class="badge <?= $follow_up_badge ?> ms-1"><?= escape(ucfirst($follow_up_status)) ?></span>
                                                <?php else: ?>
                                                    <a href="form_requests_create.php?form_type=follow_up_note&amp;booking_id=<?= (int) $apt['id'] ?>" class="btn btn-xs btn-outline-secondary">
                                                        <i class="fas fa-note-sticky"></i> Follow-up Form
                                                    </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($past_appointments) > 10): ?>
                            <p class="text-muted text-center">Showing 10 of <?= count($past_appointments) ?> past appointments</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Contracts Tab -->
                <div id="contracts" class="tab-pane fade <?= $active_tab === 'contracts' ? 'show active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Contracts</h5>
                        <a href="contracts_create.php?client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> New Contract
                        </a>
                    </div>
                    <?php if (empty($contracts)): ?>
                        <p class="text-muted">No contracts found</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Contract #</th>
                                        <th>Title</th>
                                        <th>Created</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($contracts as $contract): ?>
                                        <tr>
                                            <td><?= escape($contract['contract_number']) ?></td>
                                            <td><?= escape($contract['title']) ?></td>
                                            <td><?= formatDate($contract['created_date']) ?></td>
                                            <td>
                                                <?php
                                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'signed' => 'success', 'expired' => 'danger'];
                                                $color = $colors[$contract['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($contract['status']) ?></span>
                                            </td>
                                            <td>
                                                <a href="contracts_view.php?id=<?= $contract['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Forms Tab -->
                <div id="forms" class="tab-pane fade <?= $active_tab === 'forms' ? 'show active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Form Submissions</h5>
                        <div class="d-flex gap-2">
                            <a href="form_requests_create.php?form_type=client_form&amp;client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                                <i class="fas fa-paper-plane"></i> Send Client Form
                            </a>
                            <a href="form_requests_create.php?form_type=survey_form&amp;client_id=<?= $id ?>" class="btn btn-sm btn-outline-warning">
                                <i class="fas fa-square-poll-vertical"></i> Send Survey
                            </a>
                        </div>
                    </div>
                    <?php if (empty($forms)): ?>
                        <p class="text-muted">No forms submitted</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Form Name</th>
                                        <th>Type</th>
                                        <th>Context</th>
                                        <th>Submitted</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($forms as $form): ?>
                                        <tr>
                                            <td><?= escape($form['form_name']) ?></td>
                                            <td>
                                                <span class="badge <?= escape(bdta_get_form_type_badge_class(array_string_value($form, 'form_type'))) ?>">
                                                    <?= escape(bdta_get_form_type_label(array_string_value($form, 'form_type'))) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (array_string_value($form, 'pet_name') !== ''): ?>
                                                    <small class="text-muted d-block">Pet: <?= escape(array_string_value($form, 'pet_name')) ?></small>
                                                <?php endif; ?>
                                                <?php if (array_int_value($form, 'booking_id') > 0): ?>
                                                    <small class="text-muted">
                                                        <?= escape(array_string_value($form, 'service_type')) ?> · <?= escape(array_string_value($form, 'appointment_date')) ?>
                                                    </small>
                                                <?php elseif (array_string_value($form, 'pet_name') === ''): ?>
                                                    <span class="text-muted small">Client profile</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= formatDate($form['submitted_at']) ?></td>
                                            <td><span class="badge bg-info"><?= escape($form['status']) ?></span></td>
                                            <td>
                                                <a href="form_submissions_view.php?id=<?= $form['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quotes Tab -->
                <div id="quotes" class="tab-pane fade <?= $active_tab === 'quotes' ? 'show active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Quotes</h5>
                        <a href="quotes_create.php?client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> New Quote
                        </a>
                    </div>
                    <?php if (empty($quotes)): ?>
                        <p class="text-muted">No quotes found</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Quote #</th>
                                        <th>Title</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($quotes as $quote): ?>
                                        <tr>
                                            <td><?= escape($quote['quote_number']) ?></td>
                                            <td><?= escape($quote['title']) ?></td>
                                            <td>$<?= number_format(safe_float($quote['amount']), 2) ?></td>
                                            <td>
                                                <?php
                                                $colors = ['draft' => 'secondary', 'sent' => 'info', 'viewed' => 'primary', 'accepted' => 'success', 'declined' => 'danger', 'expired' => 'warning'];
                                                $color = $colors[$quote['status']] ?? 'secondary';
                                                ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($quote['status']) ?></span>
                                            </td>
                                            <td><?= formatDate($quote['created_at']) ?></td>
                                            <td>
                                                <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                                    <a href="quotes_view.php?id=<?= $quote['id'] ?>" class="btn btn-xs btn-outline-info table-action-btn">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </div>
                                                <div class="d-md-none table-action-dropdown">
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                            <i class="fas fa-ellipsis-v"></i>
                                                        </button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li>
                                                                <a class="dropdown-item" href="quotes_view.php?id=<?= $quote['id'] ?>">
                                                                    <i class="fas fa-eye me-2 text-info"></i>View Quote
                                                                </a>
                                                            </li>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Invoices Tab -->
                <div id="invoices" class="tab-pane fade <?= $active_tab === 'invoices' ? 'show active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Invoices</h5>
                        <a href="invoices_create.php?client_id=<?= $id ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> New Invoice
                        </a>
                    </div>
                    <?php if (empty($invoices)): ?>
                        <p class="text-muted">No invoices found</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Invoice #</th>
                                        <th>Date</th>
                                        <th>Due Date</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $invoice): ?>
                                        <tr>
                                            <td><?= escape($invoice['invoice_number']) ?></td>
                                            <td><?= formatDate($invoice['issue_date']) ?></td>
                                            <td><?= formatDate($invoice['due_date']) ?></td>
                                            <td>$<?= number_format(safe_float($invoice['total_amount']), 2) ?></td>
                                            <td>
                                                <?php $color = bdta_invoice_status_color(array_string_value($invoice, 'status')); ?>
                                                <span class="badge bg-<?= $color ?>"><?= strtoupper($invoice['status']) ?></span>
                                            </td>
                                            <td>
                                                <a href="invoices_view.php?id=<?= $invoice['id'] ?>" class="btn btn-xs btn-outline-info">
                                                    <i class="fas fa-eye"></i> View
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Email Tab -->
                <div id="emails" class="tab-pane fade <?= $active_tab === 'emails' ? 'show active' : '' ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Email Correspondence</h5>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#composeEmailModal">
                            <i class="fas fa-paper-plane"></i> Compose Email
                        </button>
                    </div>
                    
                    <div id="emailsList">
                        <div class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Achievements Tab -->
                <div id="achievements" class="tab-pane fade <?= $active_tab === 'achievements' ? 'show active' : '' ?>">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <div>
                            <h5 class="mb-0">Achievements</h5>
                            <small class="text-muted">Manage this client's awarded badges, certificates, and achievement audit history.</small>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
                                <i class="fas fa-list-check me-1"></i><?= count($client_achievements) ?> achievement<?= count($client_achievements) === 1 ? '' : 's' ?> on record
                            </span>
                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">
                                <i class="fas fa-award me-1"></i><?= $active_badge_count ?> badge<?= $active_badge_count === 1 ? '' : 's' ?> visible in the portal
                            </span>
                            <a href="achievement_types.php" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-medal me-1"></i>Manage reusable templates
                            </a>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <strong>Assign reusable template or create custom one-off</strong>
                            <ul class="nav nav-pills card-header-pills" role="tablist">
                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link <?= $achievement_form_mode === 'reusable' ? 'active' : '' ?>"
                                        id="achievement-reusable-tab"
                                        data-bs-toggle="pill"
                                        data-bs-target="#achievement-reusable-pane"
                                        type="button"
                                        role="tab"
                                    >
                                        Assign reusable template
                                    </button>
                                </li>
                                <li class="nav-item" role="presentation">
                                    <button
                                        class="nav-link <?= $achievement_form_mode === 'custom' ? 'active' : '' ?>"
                                        id="achievement-custom-tab"
                                        data-bs-toggle="pill"
                                        data-bs-target="#achievement-custom-pane"
                                        type="button"
                                        role="tab"
                                    >
                                        Create custom one-off
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <div class="tab-content">
                                <div
                                    class="tab-pane fade <?= $achievement_form_mode === 'reusable' ? 'show active' : '' ?>"
                                    id="achievement-reusable-pane"
                                    role="tabpanel"
                                    aria-labelledby="achievement-reusable-tab"
                                >
                                    <?php if (empty($achievement_types)): ?>
                                        <div class="alert alert-light border mb-0">
                                            <p class="mb-2">No reusable achievement templates are configured yet.</p>
                                            <a href="achievement_types.php" class="btn btn-sm btn-outline-secondary">
                                                <i class="fas fa-medal me-1"></i>Open template management
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" action="<?= escape($client_view_url . '&tab=achievements') ?>">
                                            <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                            <input type="hidden" name="achievement_action" value="save_assignment">
                                            <div class="row g-3">
                                                <div class="col-lg-6">
                                                    <label for="achievementTypeId" class="form-label">Reusable achievement template</label>
                                                    <select class="form-select" id="achievementTypeId" name="achievement_type_id" required>
                                                        <option value="">Select an achievement</option>
                                                        <?php foreach ($achievement_types as $achievement_type_option): ?>
                                                            <option value="<?= array_int_value($achievement_type_option, 'id') ?>">
                                                                <?= escape(array_string_value($achievement_type_option, 'title')) ?>
                                                                — <?= escape(bdta_achievement_modes()[bdta_normalize_achievement_mode(array_string_value($achievement_type_option, 'award_mode'))] ?? 'Achievement') ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-lg-3">
                                                    <label for="achievementAwardedOn" class="form-label">Date awarded</label>
                                                    <input type="date" class="form-control" id="achievementAwardedOn" name="awarded_on" value="<?= date('Y-m-d') ?>" required>
                                                </div>
                                                <div class="col-lg-3">
                                                    <label for="achievementDogName" class="form-label">Dog name</label>
                                                    <input type="text" class="form-control" id="achievementDogName" name="dog_name" list="clientDogNames" placeholder="Optional certificate field">
                                                </div>
                                                <div class="col-lg-6">
                                                    <label for="achievementProgramName" class="form-label">Program name</label>
                                                    <input type="text" class="form-control" id="achievementProgramName" name="program_name" placeholder="Optional certificate field">
                                                </div>
                                                <div class="col-12">
                                                    <label for="achievementNotes" class="form-label">Notes</label>
                                                    <textarea class="form-control" id="achievementNotes" name="notes" rows="3" placeholder="Visible in admin details and audit history"></textarea>
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-primary mt-3">
                                                <i class="fas fa-award me-1"></i>Award achievement
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <div
                                    class="tab-pane fade <?= $achievement_form_mode === 'custom' ? 'show active' : '' ?>"
                                    id="achievement-custom-pane"
                                    role="tabpanel"
                                    aria-labelledby="achievement-custom-tab"
                                >
                                    <form method="POST" action="<?= escape($client_view_url . '&tab=achievements') ?>" enctype="multipart/form-data">
                                        <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                        <input type="hidden" name="achievement_action" value="save_custom_assignment">
                                        <div class="row g-3">
                                            <div class="col-lg-4">
                                                <label for="customAchievementTitle" class="form-label">Title</label>
                                                <input type="text" class="form-control" id="customAchievementTitle" name="title" required>
                                            </div>
                                            <div class="col-lg-4">
                                                <label for="customAchievementAwardMode" class="form-label">Visibility</label>
                                                <select class="form-select" id="customAchievementAwardMode" name="award_mode">
                                                    <?php foreach (bdta_achievement_modes() as $mode_value => $mode_label): ?>
                                                        <option value="<?= escape($mode_value) ?>"><?= escape($mode_label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-lg-4">
                                                <label for="customAchievementAwardedOn" class="form-label">Date awarded</label>
                                                <input type="date" class="form-control" id="customAchievementAwardedOn" name="awarded_on" value="<?= date('Y-m-d') ?>" required>
                                            </div>
                                            <div class="col-12">
                                                <label for="customAchievementDescription" class="form-label">Description</label>
                                                <textarea class="form-control" id="customAchievementDescription" name="description" rows="2"></textarea>
                                            </div>
                                            <div class="col-lg-6">
                                                <label for="customAchievementDogName" class="form-label">Dog name</label>
                                                <input type="text" class="form-control" id="customAchievementDogName" name="dog_name" list="clientDogNames" placeholder="Optional certificate field">
                                            </div>
                                            <div class="col-lg-6">
                                                <label for="customAchievementProgramName" class="form-label">Program name</label>
                                                <input type="text" class="form-control" id="customAchievementProgramName" name="program_name" placeholder="Optional certificate field">
                                            </div>
                                            <div class="col-12">
                                                <label for="customAchievementNotes" class="form-label">Notes</label>
                                                <textarea class="form-control" id="customAchievementNotes" name="notes" rows="3" placeholder="Visible in admin details and audit history"></textarea>
                                            </div>
                                            <div class="col-lg-6">
                                                <label for="customAchievementBadgeIcon" class="form-label">Badge icon</label>
                                                <input type="file" class="form-control" id="customAchievementBadgeIcon" name="badge_icon" accept="image/png,image/jpeg,image/gif,image/webp">
                                            </div>
                                            <div class="col-lg-6">
                                                <label for="customAchievementCertificateTemplate" class="form-label">Certificate PDF template</label>
                                                <input type="file" class="form-control" id="customAchievementCertificateTemplate" name="certificate_template" accept="application/pdf">
                                            </div>
                                            <div class="col-12">
                                                <label for="customAchievementCertificateBody" class="form-label">Certificate body HTML</label>
                                                <textarea class="form-control" id="customAchievementCertificateBody" name="certificate_body_html" rows="4" placeholder="Use placeholders like {{client_name}}, {{dog_name}}, {{program_name}}, {{award_date}}, and {{achievement_title}}"><?= escape(bdta_default_certificate_body_html()) ?></textarea>
                                                <small class="text-muted">Custom one-offs are created for this client from here, while reusable templates are managed on the dedicated templates page.</small>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-secondary mt-3">
                                            <i class="fas fa-certificate me-1"></i>Create and award custom achievement
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <datalist id="clientDogNames">
                                <?php foreach ($pets as $pet): ?>
                                    <option value="<?= escape(array_string_value($pet, 'name')) ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <strong>Assigned achievements</strong>
                        </div>
                        <div class="card-body">
                            <?php if (empty($client_achievements)): ?>
                                <p class="text-muted mb-0">This client has not been awarded any achievements yet.</p>
                            <?php else: ?>
                                <div class="accordion" id="clientAchievementsAccordion">
                                    <?php foreach ($client_achievements as $achievement): ?>
                                        <?php
                                        $assignment_id = array_int_value($achievement, 'id');
                                        $assignment_mode = bdta_normalize_achievement_mode(array_string_value($achievement, 'award_mode'));
                                        $assignment_status = array_string_value($achievement, 'status', 'awarded');
                                        $assignment_icon_path = array_string_value($achievement, 'badge_icon_path');
                                        $assignment_logs = $achievement_logs_by_assignment[$assignment_id] ?? [];
                                        ?>
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="client-achievement-heading-<?= $assignment_id ?>">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#client-achievement-<?= $assignment_id ?>">
                                                    <span class="me-3">
                                                        <?php if (bdta_achievement_mode_supports_badge($assignment_mode) && $assignment_icon_path !== ''): ?>
                                                            <img src="<?= escape($assignment_icon_path) ?>" alt="" style="width:42px;height:42px;object-fit:cover;border-radius:50%;">
                                                        <?php else: ?>
                                                            <span class="badge bg-<?= $assignment_status === 'revoked' ? 'secondary' : 'primary' ?> rounded-circle p-3">
                                                                <i class="fas <?= bdta_achievement_mode_supports_badge($assignment_mode) ? 'fa-award' : 'fa-certificate' ?>"></i>
                                                            </span>
                                                        <?php endif; ?>
                                                    </span>
                                                    <span class="flex-grow-1">
                                                        <strong><?= escape(array_string_value($achievement, 'achievement_title')) ?></strong>
                                                        <small class="d-block text-muted">
                                                            Awarded <?= escape(array_string_value($achievement, 'awarded_on')) ?>
                                                            <?php if (array_string_value($achievement, 'program_name') !== ''): ?>
                                                                · <?= escape(array_string_value($achievement, 'program_name')) ?>
                                                            <?php endif; ?>
                                                        </small>
                                                    </span>
                                                    <span class="badge bg-<?= $assignment_status === 'revoked' ? 'warning text-dark' : 'success' ?>">
                                                        <?= escape(ucfirst($assignment_status)) ?>
                                                    </span>
                                                </button>
                                            </h2>
                                            <div id="client-achievement-<?= $assignment_id ?>" class="accordion-collapse collapse" data-bs-parent="#clientAchievementsAccordion">
                                                <div class="accordion-body">
                                                    <div class="row g-4">
                                                        <div class="col-lg-4">
                                                            <?php if (bdta_achievement_mode_supports_badge($assignment_mode)): ?>
                                                                <div class="border rounded p-3 text-center h-100">
                                                                    <h6>Badge</h6>
                                                                    <?php if ($assignment_icon_path !== ''): ?>
                                                                        <img src="<?= escape($assignment_icon_path) ?>" alt="" style="width:88px;height:88px;object-fit:cover;border-radius:50%;">
                                                                    <?php else: ?>
                                                                        <div class="display-5 text-primary"><i class="fas fa-award"></i></div>
                                                                    <?php endif; ?>
                                                                    <p class="small text-muted mb-0 mt-2">Visible on the client dashboard.</p>
                                                                </div>
                                                            <?php endif; ?>
                                                            <?php if (bdta_achievement_mode_supports_certificate($assignment_mode)): ?>
                                                                <div class="border rounded p-3 text-center <?= bdta_achievement_mode_supports_badge($assignment_mode) ? 'mt-3' : '' ?>">
                                                                    <h6>Certificate</h6>
                                                                    <?php if ($assignment_status === 'awarded'): ?>
                                                                        <div class="d-grid gap-2">
                                                                            <a href="achievement_certificate.php?id=<?= $assignment_id ?>" class="btn btn-outline-primary btn-sm">
                                                                                <i class="fas fa-print me-1"></i>Print preview
                                                                            </a>
                                                                            <a href="achievement_certificate.php?id=<?= $assignment_id ?>&amp;download=1" class="btn btn-primary btn-sm">
                                                                                <i class="fas fa-download me-1"></i>Download PDF
                                                                            </a>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <p class="text-muted mb-0">Certificate access is hidden after revocation.</p>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="col-lg-8">
                                                            <div class="mb-3">
                                                                <h6>Details</h6>
                                                                <p class="mb-2"><?= nl2br(escape(array_string_value($achievement, 'achievement_description', 'No description provided.'))) ?></p>
                                                                <dl class="row small mb-0">
                                                                    <dt class="col-sm-4">Award date</dt>
                                                                    <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'awarded_on')) ?></dd>
                                                                    <dt class="col-sm-4">Dog name</dt>
                                                                    <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'dog_name', '—')) ?></dd>
                                                                    <dt class="col-sm-4">Program name</dt>
                                                                    <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'program_name', '—')) ?></dd>
                                                                    <dt class="col-sm-4">Awarded by</dt>
                                                                    <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'awarded_by_name', 'Admin')) ?></dd>
                                                                    <?php if ($assignment_status === 'revoked'): ?>
                                                                        <dt class="col-sm-4">Revoked</dt>
                                                                        <dd class="col-sm-8"><?= escape(array_string_value($achievement, 'revoked_at', '')) ?><?= array_string_value($achievement, 'revoked_by_name') !== '' ? ' by ' . escape(array_string_value($achievement, 'revoked_by_name')) : '' ?></dd>
                                                                    <?php endif; ?>
                                                                </dl>
                                                                <?php if (trim(array_string_value($achievement, 'notes')) !== ''): ?>
                                                                    <div class="alert alert-light border mt-3 mb-0">
                                                                        <strong>Notes:</strong><br><?= nl2br(escape(array_string_value($achievement, 'notes'))) ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>

                                                            <form method="POST" action="<?= escape($client_view_url . '&tab=achievements') ?>">
                                                                <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                                                <input type="hidden" name="achievement_action" value="save_assignment">
                                                                <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                                                                <div class="row g-3">
                                                                    <div class="col-md-6">
                                                                        <label class="form-label">Achievement type</label>
                                                                        <select class="form-select" name="achievement_type_id" required>
                                                                            <?php foreach ($achievement_types as $achievement_type_option): ?>
                                                                                <option value="<?= array_int_value($achievement_type_option, 'id') ?>" <?= array_int_value($achievement, 'achievement_type_id') === array_int_value($achievement_type_option, 'id') ? 'selected' : '' ?>>
                                                                                    <?= escape(array_string_value($achievement_type_option, 'title')) ?>
                                                                                </option>
                                                                            <?php endforeach; ?>
                                                                            <?php if (!isset($achievement_type_ids[array_int_value($achievement, 'achievement_type_id')])): ?>
                                                                                <option value="<?= array_int_value($achievement, 'achievement_type_id') ?>" selected>
                                                                                    <?= escape(array_string_value($achievement, 'achievement_title')) ?> — custom one-off
                                                                                </option>
                                                                            <?php endif; ?>
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label">Award date</label>
                                                                        <input type="date" class="form-control" name="awarded_on" value="<?= escape(array_string_value($achievement, 'awarded_on')) ?>" required>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label">Dog name</label>
                                                                        <input type="text" class="form-control" name="dog_name" value="<?= escape(array_string_value($achievement, 'dog_name')) ?>" list="clientDogNames">
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <label class="form-label">Program name</label>
                                                                        <input type="text" class="form-control" name="program_name" value="<?= escape(array_string_value($achievement, 'program_name')) ?>">
                                                                    </div>
                                                                    <div class="col-12">
                                                                        <label class="form-label">Notes</label>
                                                                        <textarea class="form-control" name="notes" rows="3"><?= escape(array_string_value($achievement, 'notes')) ?></textarea>
                                                                    </div>
                                                                </div>
                                                                <div class="d-flex flex-wrap gap-2 mt-3">
                                                                    <button type="submit" class="btn btn-outline-primary btn-sm">
                                                                        <i class="fas fa-save me-1"></i>Save changes
                                                                    </button>
                                                                </div>
                                                            </form>
                                                            <?php if ($assignment_status !== 'revoked'): ?>
                                                                <form method="POST" action="<?= escape($client_view_url . '&tab=achievements') ?>" class="mt-2">
                                                                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                                                                    <input type="hidden" name="achievement_action" value="revoke_assignment">
                                                                    <input type="hidden" name="assignment_id" value="<?= $assignment_id ?>">
                                                                    <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Revoke this achievement?')">
                                                                        <i class="fas fa-ban me-1"></i>Revoke
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>

                                                            <div class="mt-4">
                                                                <h6 class="mb-2">Audit history</h6>
                                                                <?php if (empty($assignment_logs)): ?>
                                                                    <p class="text-muted small mb-0">No audit history recorded yet.</p>
                                                                <?php else: ?>
                                                                    <div class="table-responsive">
                                                                        <table class="table table-sm align-middle mb-0">
                                                                            <thead>
                                                                                <tr>
                                                                                    <th>Status</th>
                                                                                    <th>Action</th>
                                                                                    <th>Admin</th>
                                                                                    <th>Timestamp</th>
                                                                                    <th>Notes</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <?php foreach ($assignment_logs as $assignment_log): ?>
                                                                                    <tr>
                                                                                        <td><span class="badge bg-secondary"><?= escape(ucfirst(array_string_value($assignment_log, 'status'))) ?></span></td>
                                                                                        <td><?= escape(ucfirst(array_string_value($assignment_log, 'action'))) ?></td>
                                                                                        <td><?= escape(array_string_value($assignment_log, 'admin_name', 'Admin')) ?></td>
                                                                                        <td><?= escape(array_string_value($assignment_log, 'created_at')) ?></td>
                                                                                        <td><?= escape(array_string_value($assignment_log, 'notes', '—')) ?></td>
                                                                                    </tr>
                                                                                <?php endforeach; ?>
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Compose Email Modal -->
<div class="modal fade" id="composeEmailModal" tabindex="-1" aria-labelledby="composeEmailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="composeEmailModalLabel">
                    <i class="fas fa-paper-plane"></i> Compose Email to <?= escape($client['name']) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="composeEmailForm">
                    <input type="hidden" name="client_id" value="<?= $id ?>">
                    
                    <!-- Template Selection -->
                    <div class="mb-3">
                        <label for="emailTemplate" class="form-label">Use Template (Optional)</label>
                        <select class="form-select" id="emailTemplate" name="template_id">
                            <option value="">-- Custom Message --</option>
                        </select>
                        <small class="form-text text-muted">Select a template to auto-fill the email content</small>
                    </div>
                    
                    <!-- Subject -->
                    <div class="mb-3">
                        <label for="emailSubject" class="form-label">Subject <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="emailSubject" name="subject" required>
                    </div>
                    
                    <!-- Body -->
                    <div class="mb-3">
                        <label for="emailBody" class="form-label">Message <span class="text-danger">*</span></label>
                        <textarea id="emailBody" name="body_html" required></textarea>
                        <small class="form-text text-muted">HTML is supported</small>
                    </div>
                    
                    <!-- Send Options -->
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="scheduleEmail" name="schedule">
                            <label class="form-check-label" for="scheduleEmail">
                                Schedule for later
                            </label>
                        </div>
                    </div>
                    
                    <!-- Schedule DateTime -->
                    <div id="scheduleOptions" class="mb-3" style="display: none;">
                        <label for="scheduledAt" class="form-label">Schedule Date & Time</label>
                        <input type="datetime-local" class="form-control" id="scheduledAt" name="scheduled_at">
                    </div>
                    
                    <div id="emailFormAlert" class="alert d-none"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="sendEmailBtn">
                    <i class="fas fa-paper-plane"></i> Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Booking Reschedule Modal -->
<div class="modal fade" id="adminRescheduleModal" tabindex="-1" aria-labelledby="adminRescheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adminRescheduleModalLabel">
                    <i class="fas fa-calendar-alt me-2 text-primary"></i>Reschedule Booking
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>Select a new date and time for <strong id="adminRescheduleBookingLabel"></strong>.</p>
                <div class="mb-3">
                    <label for="adminRescheduleDate" class="form-label">New Date</label>
                    <input type="date" class="form-control" id="adminRescheduleDate" onchange="syncAdminRescheduleState()">
                </div>
                <div class="mb-3">
                    <label for="adminRescheduleTime" class="form-label">New Time</label>
                    <input type="time" class="form-control" id="adminRescheduleTime" onchange="syncAdminRescheduleFormState()">
                    <div class="form-text">Enter any future date and time, or turn on availability suggestions below.</div>
                </div>
                <div class="mb-3">
                    <div class="form-check mt-2">
                        <input type="checkbox" class="form-check-input" id="adminRescheduleRespectGoogleCalendar" onchange="syncAdminRescheduleState()">
                        <label class="form-check-label" for="adminRescheduleRespectGoogleCalendar">
                            Show availability using website rules and connected Google Calendar
                        </label>
                    </div>
                </div>
                <div class="mb-3" id="adminRescheduleTimesSection" style="display:none;">
                    <label class="form-label">Available Times</label>
                    <div id="adminRescheduleTimesGrid" class="d-flex flex-wrap gap-2"></div>
                    <div id="adminRescheduleNoSlots" class="text-muted small d-none">No available times on this date.</div>
                </div>
                <div id="adminRescheduleError" class="alert alert-danger d-none"></div>
            </div>
            <div class="modal-footer">
                <form method="POST" id="adminRescheduleForm" class="d-flex gap-2">
                    <input type="hidden" name="csrf_token" value="<?= escape(csrfToken()) ?>">
                    <input type="hidden" name="booking_action" value="reschedule">
                    <input type="hidden" name="booking_id" id="adminRescheduleBookingId">
                    <input type="hidden" name="new_date" id="adminRescheduleDateField">
                    <input type="hidden" name="new_time" id="adminRescheduleTimeField">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Current Time</button>
                    <button type="submit" class="btn btn-primary" id="confirmAdminRescheduleBtn" disabled>
                        Confirm Reschedule
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Email Details Modal -->
<div class="modal fade" id="emailDetailsModal" tabindex="-1" aria-labelledby="emailDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="emailDetailsModalLabel">
                    <i class="fas fa-envelope"></i> Email Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="emailDetailsBody">
                <!-- Email details will be loaded here -->
            </div>
            <div class="modal-footer" id="emailDetailsFooter">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Contact Modal -->
<div class="modal fade" id="contactModal" tabindex="-1" aria-labelledby="contactModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contactModalLabel">
                    <i class="fas fa-user-plus"></i> Add Contact
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="contactForm">
                    <div class="mb-3">
                        <label for="contactName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="contactName" required>
                    </div>
                    <div class="mb-3">
                        <label for="contactEmail" class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="contactEmail" required>
                    </div>
                    <div class="mb-3">
                        <label for="contactPhone" class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="contactPhone" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="contactPrimary">
                        <label class="form-check-label" for="contactPrimary">
                            Set as primary contact
                        </label>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveContact()">Save Contact</button>
            </div>
        </div>
    </div>
</div>

<script>
// Email management
const clientId = <?= $id ?>;
const adminRescheduleTimeZone = <?= json_encode(date_default_timezone_get()) ?>;
let emailTemplates = [];
let adminRescheduleBookingId = null;
let adminRescheduleTypeId = null;
let adminRescheduleAvailabilityRequestSequence = 0;
let adminRescheduleAvailabilityController = null;

function getAdminRescheduleCurrentDateTimeParts() {
    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: adminRescheduleTimeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });

    const parts = formatter.formatToParts(new Date()).reduce((map, part) => {
        if (part.type !== 'literal') {
            map[part.type] = part.value;
        }

        return map;
    }, {});

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
    };
}

function showAdminRescheduleModal(btn) {
    adminRescheduleBookingId = parseInt(btn.dataset.bookingId, 10);
    adminRescheduleTypeId = parseInt(btn.dataset.typeId, 10);
    const currentDateTime = getAdminRescheduleCurrentDateTimeParts();

    document.getElementById('adminRescheduleBookingLabel').textContent = btn.dataset.typeName;
    document.getElementById('adminRescheduleDate').min = currentDateTime.date;
    document.getElementById('adminRescheduleDate').value = '';
    document.getElementById('adminRescheduleTime').value = '';
    document.getElementById('adminRescheduleRespectGoogleCalendar').checked = false;
    clearAdminRescheduleAvailabilityDisplay();
    document.getElementById('adminRescheduleBookingId').value = adminRescheduleBookingId || '';
    document.getElementById('adminRescheduleDateField').value = '';
    document.getElementById('adminRescheduleTimeField').value = '';
    document.getElementById('confirmAdminRescheduleBtn').disabled = true;

    bootstrap.Modal.getOrCreateInstance(document.getElementById('adminRescheduleModal')).show();
}

function clearAdminRescheduleAvailabilityDisplay() {
    if (adminRescheduleAvailabilityController) {
        adminRescheduleAvailabilityController.abort();
        adminRescheduleAvailabilityController = null;
    }

    document.getElementById('adminRescheduleTimesGrid').innerHTML = '';
    document.getElementById('adminRescheduleTimesSection').style.display = 'none';
    document.getElementById('adminRescheduleNoSlots').classList.add('d-none');
    document.getElementById('adminRescheduleError').classList.add('d-none');
}

function adminRescheduleSelectionIsFuture(date, time) {
    if (!date || !time) {
        return false;
    }

    const currentDateTime = getAdminRescheduleCurrentDateTimeParts();

    return date > currentDateTime.date || (date === currentDateTime.date && time > currentDateTime.time);
}

function syncAdminRescheduleFormState() {
    const date = document.getElementById('adminRescheduleDate').value;
    const time = document.getElementById('adminRescheduleTime').value;
    const hasValidSelection = adminRescheduleBookingId && adminRescheduleSelectionIsFuture(date, time);

    document.getElementById('adminRescheduleBookingId').value = adminRescheduleBookingId || '';
    document.getElementById('adminRescheduleDateField').value = date;
    document.getElementById('adminRescheduleTimeField').value = time;
    document.getElementById('confirmAdminRescheduleBtn').disabled = !hasValidSelection;

    document.querySelectorAll('#adminRescheduleTimesGrid .btn').forEach(existingButton => {
        const isSelected = existingButton.dataset.time === time;
        existingButton.classList.toggle('btn-primary', isSelected);
        existingButton.classList.toggle('btn-outline-primary', !isSelected);
    });
}

function syncAdminRescheduleState() {
    syncAdminRescheduleFormState();

    if (!document.getElementById('adminRescheduleRespectGoogleCalendar').checked) {
        clearAdminRescheduleAvailabilityDisplay();
        return;
    }

    loadAdminRescheduleSlots();
}

function loadAdminRescheduleSlots() {
    const date = document.getElementById('adminRescheduleDate').value;
    const grid = document.getElementById('adminRescheduleTimesGrid');
    const section = document.getElementById('adminRescheduleTimesSection');
    const noSlots = document.getElementById('adminRescheduleNoSlots');
    const errorBox = document.getElementById('adminRescheduleError');
    const showAvailability = document.getElementById('adminRescheduleRespectGoogleCalendar').checked;

    syncAdminRescheduleFormState();

    if (!showAvailability) {
        clearAdminRescheduleAvailabilityDisplay();
        return;
    }

    section.style.display = 'block';
    noSlots.classList.add('d-none');
    errorBox.classList.add('d-none');
    grid.innerHTML = '<div class="spinner-border spinner-border-sm text-secondary me-2"></div> Loading...';

    if (!date || !adminRescheduleTypeId) {
        grid.innerHTML = '';
        return;
    }

    adminRescheduleAvailabilityRequestSequence += 1;
    const requestId = adminRescheduleAvailabilityRequestSequence;
    const requestDate = date;
    const requestTypeId = adminRescheduleTypeId;
    if (adminRescheduleAvailabilityController) {
        adminRescheduleAvailabilityController.abort();
    }
    adminRescheduleAvailabilityController = new AbortController();
    const { signal } = adminRescheduleAvailabilityController;

    fetch(
        '/backend/public/api_bookings.php?date=' + encodeURIComponent(date)
        + '&appointment_type_id=' + adminRescheduleTypeId
        + '&respect_google_calendar=1',
        { signal }
    )
        .then(response => response.json())
        .then(data => {
            const isStaleRequest = requestId !== adminRescheduleAvailabilityRequestSequence
                || document.getElementById('adminRescheduleDate').value !== requestDate
                || adminRescheduleTypeId !== requestTypeId
                || !document.getElementById('adminRescheduleRespectGoogleCalendar').checked;
            if (isStaleRequest) {
                return;
            }

            adminRescheduleAvailabilityController = null;
            grid.innerHTML = '';
            const slots = Array.isArray(data.available_slots) ? data.available_slots : [];
            if (slots.length === 0) {
                noSlots.classList.remove('d-none');
                if (data.message) {
                    errorBox.textContent = data.message;
                    errorBox.classList.remove('d-none');
                }
                return;
            }

            slots.forEach(slot => {
                const time = typeof slot === 'object' ? (slot.time || '') : slot;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'btn btn-outline-primary btn-sm';
                button.dataset.time = time;
                button.textContent = formatAdminRescheduleTime(time);
                button.addEventListener('click', function () {
                    document.getElementById('adminRescheduleTime').value = time;
                    syncAdminRescheduleFormState();
                });
                grid.appendChild(button);
            });

            syncAdminRescheduleFormState();
        })
        .catch(error => {
            if (error.name === 'AbortError') {
                return;
            }

            adminRescheduleAvailabilityController = null;
            grid.innerHTML = '<span class="text-danger">Could not load available times.</span>';
        });
}

function formatAdminRescheduleTime(value) {
    if (!value) return value;
    const parts = value.split(':');
    let hour = parseInt(parts[0], 10);
    const minutes = parts[1] || '00';
    const suffix = hour >= 12 ? 'PM' : 'AM';
    hour = hour % 12 || 12;
    return hour + ':' + minutes + ' ' + suffix;
}

// Load email templates on page load
async function loadEmailTemplates() {
    try {
        const response = await fetch('email_templates_api.php?action=list');
        const data = await response.json();
        if (data.success) {
            emailTemplates = data.templates;
            const select = document.getElementById('emailTemplate');
            data.templates.forEach(template => {
                const option = document.createElement('option');
                option.value = template.id;
                option.textContent = template.name;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading templates:', error);
    }
}

// Load and display emails
async function loadEmails() {
    try {
        const response = await fetch(`client_emails_api.php?client_id=${clientId}`);
        const data = await response.json();
        
        if (data.success) {
            displayEmails(data.emails);
        } else {
            document.getElementById('emailsList').innerHTML = 
                '<div class="alert alert-danger">Failed to load emails</div>';
        }
    } catch (error) {
        console.error('Error loading emails:', error);
        document.getElementById('emailsList').innerHTML = 
            '<div class="alert alert-danger">Error loading emails</div>';
    }
}

// Display emails in the list
function displayEmails(emails) {
    const container = document.getElementById('emailsList');
    
    if (emails.length === 0) {
        container.innerHTML = '<p class="text-muted">No emails found</p>';
        return;
    }
    
    let html = '<div class="list-group">';
    
    emails.forEach(email => {
        const statusBadge = getStatusBadge(email.status);
        const icon = email.direction === 'outgoing' ? 'fa-paper-plane' : 'fa-inbox';
        const dateText = getEmailDateText(email);
        
        html += `
            <div class="list-group-item">
                <div class="d-flex w-100 justify-content-between align-items-start">
                    <a href="#" class="flex-grow-1 text-decoration-none text-reset" onclick="showEmailDetails(${email.id}); return false;">
                        <h6 class="mb-1">
                            <i class="fas ${icon} me-2"></i>
                            ${escapeHtml(email.subject)}
                        </h6>
                        <p class="mb-1 text-muted small">
                            ${email.direction === 'outgoing' ? 'To' : 'From'}: ${escapeHtml(email.direction === 'outgoing' ? email.to_email : email.from_email)}
                        </p>
                        ${email.template_name ? `<span class="badge bg-info me-2"><i class="fas fa-file-alt"></i> ${escapeHtml(email.template_name)}</span>` : ''}
                        ${email.mail_type && email.mail_type !== 'compose' ? `<span class="badge bg-secondary me-2">${escapeHtml(getMailTypeLabel(email.mail_type))}</span>` : ''}
                        ${statusBadge}
                    </a>
                    <div class="d-flex flex-column align-items-end ms-2">
                        <small class="text-muted mb-1">${dateText}</small>
                        ${email.direction === 'incoming' ? `<button class="btn btn-sm btn-outline-primary" onclick="replyToEmail(${email.id})" title="Reply"><i class="fas fa-reply"></i> Reply</button>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

// Get status badge HTML
function getStatusBadge(status) {
    const badges = {
        'pending': '<span class="badge bg-warning">Pending</span>',
        'scheduled': '<span class="badge bg-info">Scheduled</span>',
        'sent': '<span class="badge bg-success">Sent</span>',
        'delivered': '<span class="badge bg-success">Delivered</span>',
        'received': '<span class="badge bg-primary">Received</span>',
        'failed': '<span class="badge bg-danger">Failed</span>'
    };
    return badges[status] || `<span class="badge bg-secondary">${status}</span>`;
}

// Get human-readable label for mail type
function getMailTypeLabel(mailType) {
    const labels = {
        'booking_confirmation': 'Booking Confirmation',
        'booking_request':      'Booking Request',
        'booking_reminder':     'Booking Reminder',
        'booking_cancellation': 'Cancellation',
        'payment_receipt':      'Payment Receipt',
        'invoice':              'Invoice',
        'invoice_reminder':     'Invoice Reminder',
        'contract_reminder':    'Contract Reminder',
        'quote_reminder':       'Quote Reminder',
        'form_reminder':        'Form Reminder',
        'workflow':             'Workflow',
        'generic':              'Automated',
    };
    return labels[mailType] || mailType;
}

// Get email date text
function getEmailDateText(email) {
    if (email.status === 'scheduled' && email.scheduled_at) {
        return 'Scheduled: ' + formatDateTime(email.scheduled_at);
    } else if (email.sent_at) {
        return formatDateTime(email.sent_at);
    } else {
        return formatDateTime(email.created_at);
    }
}

const platformTimeZone = <?= json_encode(getSystemTimezone()) ?>;
const platformDateTimeFormatter = new Intl.DateTimeFormat('en-US', {
    timeZone: platformTimeZone,
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
});

function parseUtcDate(dateStr) {
    if (!dateStr) {
        return null;
    }

    const normalized = String(dateStr).trim().replace(' ', 'T');
    const utcValue = /(?:Z|[+-]\d{2}:\d{2})$/i.test(normalized) ? normalized : `${normalized}Z`;
    const date = new Date(utcValue);
    return Number.isNaN(date.getTime()) ? null : date;
}

// Format date time
function formatDateTime(dateStr) {
    const date = parseUtcDate(dateStr);
    return date ? platformDateTimeFormatter.format(date) : '';
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show email details
async function showEmailDetails(emailId) {
    const email = await getEmailById(emailId);
    if (!email) return;
    
    const modal = new bootstrap.Modal(document.getElementById('emailDetailsModal'));
    const body = document.getElementById('emailDetailsBody');
    
    let html = `
        <dl class="row">
            <dt class="col-sm-3">Subject:</dt>
            <dd class="col-sm-9">${escapeHtml(email.subject)}</dd>
            
            <dt class="col-sm-3">From:</dt>
            <dd class="col-sm-9">${escapeHtml(email.from_email)}</dd>
            
            <dt class="col-sm-3">To:</dt>
            <dd class="col-sm-9">${escapeHtml(email.to_email)}</dd>
            
            <dt class="col-sm-3">Status:</dt>
            <dd class="col-sm-9">${getStatusBadge(email.status)}</dd>
            
            ${email.scheduled_at ? `
                <dt class="col-sm-3">Scheduled:</dt>
                <dd class="col-sm-9">${formatDateTime(email.scheduled_at)}</dd>
            ` : ''}
            
            ${email.sent_at ? `
                <dt class="col-sm-3">Sent:</dt>
                <dd class="col-sm-9">${formatDateTime(email.sent_at)}</dd>
            ` : ''}
            
            ${email.error_message ? `
                <dt class="col-sm-3">Error:</dt>
                <dd class="col-sm-9 text-danger">${escapeHtml(email.error_message)}</dd>
            ` : ''}
        </dl>
        
        <hr>
        <h6>Message:</h6>
        <div class="border p-3 bg-light" style="max-height: 400px; overflow-y: auto;">
        </div>
    `;
    
    // Sanitize and insert HTML content separately to prevent XSS
    body.innerHTML = html;
    const messageContainer = body.querySelector('.border.p-3.bg-light');
    if (email.body_html) {
        // Create a temporary element to sanitize HTML
        const tempDiv = document.createElement('div');
        tempDiv.textContent = email.body_html; // First escape as text
        // Then allow it to be rendered as HTML (basic sanitization)
        // For production, consider using a library like DOMPurify
        messageContainer.innerHTML = email.body_html;
    } else {
        messageContainer.textContent = email.body_text || '';
    }
    
    // Show Reply button for incoming emails
    const footer = document.getElementById('emailDetailsFooter');
    footer.innerHTML = '';
    if (email.direction === 'incoming') {
        if (email.from_email) {
            footer.innerHTML += `<button type="button" class="btn btn-primary" onclick="replyToEmail(${email.id})"><i class="fas fa-reply"></i> Reply</button>`;
        } else {
            footer.innerHTML += '<button type="button" class="btn btn-primary" disabled title="Cannot reply: sender address is missing"><i class="fas fa-reply"></i> Reply</button>';
        }
    }
    footer.innerHTML += '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>';
    
    modal.show();
}

// Get email by ID
async function getEmailById(emailId) {
    try {
        const response = await fetch(`client_emails_api.php?client_id=${clientId}`);
        const data = await response.json();
        if (data.success) {
            return data.emails.find(e => e.id == emailId);
        }
    } catch (error) {
        console.error('Error getting email:', error);
    }
    return null;
}

// Open compose modal pre-filled as a reply to an incoming email
async function replyToEmail(emailId) {
    const email = await getEmailById(emailId);
    if (!email) {
        alert('Could not load email details for reply.');
        return;
    }

    if (!email.from_email) {
        alert('Cannot reply: sender address is missing.');
        return;
    }

    // Close details modal if open
    const detailsModalEl = document.getElementById('emailDetailsModal');
    const detailsModal = bootstrap.Modal.getInstance(detailsModalEl);
    if (detailsModal) detailsModal.hide();

    // Pre-fill subject with "Re: " prefix (avoid double prefix)
    const subject = email.subject.startsWith('Re: ') ? email.subject : 'Re: ' + email.subject;
    document.getElementById('emailSubject').value = subject;

    // Quote original message in body
    let originalText = email.body_text || '';
    if (!originalText && email.body_html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = email.body_html;
        originalText = tmp.textContent || tmp.innerText || '';
    }
    const quotedDate = email.sent_at ? formatDateTime(email.sent_at) : formatDateTime(email.created_at);
    const quotedBody = '\n\n---\nOn ' + quotedDate +
        ', ' + email.from_email + ' wrote:\n' + originalText.split('\n').map(l => '> ' + l).join('\n');
    if (window.emailBodyEditor) {
        const quotedHtml = '<p><br></p><hr><p>On ' + quotedDate + ', ' + email.from_email + ' wrote:</p><blockquote>' +
            originalText.replace(/\n/g, '<br>') + '</blockquote>';
        window.emailBodyEditor.setData(quotedHtml);
    } else {
        document.getElementById('emailBody').value = quotedBody;
    }

    // Reset template selection
    document.getElementById('emailTemplate').value = '';

    // Open compose modal
    const composeModal = new bootstrap.Modal(document.getElementById('composeEmailModal'));
    composeModal.show();
}

// Handle template selection
document.getElementById('emailTemplate').addEventListener('change', async function() {
    const templateId = this.value;
    if (!templateId) {
        document.getElementById('emailSubject').value = '';
        if (window.emailBodyEditor) {
            window.emailBodyEditor.setData('');
        } else {
            document.getElementById('emailBody').value = '';
        }
        return;
    }
    
    try {
        const response = await fetch(`email_templates_api.php?action=preview&id=${templateId}&client_id=${clientId}`);
        const data = await response.json();
        
        if (data.success) {
            document.getElementById('emailSubject').value = data.preview.subject;
            const bodyContent = data.preview.body_html || data.preview.body_text || '';
            if (window.emailBodyEditor) {
                window.emailBodyEditor.setData(bodyContent);
            } else {
                document.getElementById('emailBody').value = bodyContent;
            }
        }
    } catch (error) {
        console.error('Error loading template:', error);
    }
});

// Handle schedule checkbox
document.getElementById('scheduleEmail').addEventListener('change', function() {
    const scheduleOptions = document.getElementById('scheduleOptions');
    const sendBtn = document.getElementById('sendEmailBtn');
    
    if (this.checked) {
        scheduleOptions.style.display = 'block';
        sendBtn.innerHTML = '<i class="fas fa-clock"></i> Schedule Email';
        
        // Set default to tomorrow at 9 AM
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        document.getElementById('scheduledAt').value = tomorrow.toISOString().slice(0, 16);
    } else {
        scheduleOptions.style.display = 'none';
        sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Email';
    }
});

// Handle send email
document.getElementById('sendEmailBtn').addEventListener('click', async function() {
    const form = document.getElementById('composeEmailForm');

    // Sync CKEditor content to textarea before reading FormData
    if (window.emailBodyEditor) {
        document.getElementById('emailBody').value = window.emailBodyEditor.getData();
    }

    const formData = new FormData(form);
    const alertDiv = document.getElementById('emailFormAlert');
    
    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Prepare data
    const data = {
        client_id: parseInt(formData.get('client_id')),
        subject: formData.get('subject'),
        body_html: formData.get('body_html'),
        template_id: formData.get('template_id') ? parseInt(formData.get('template_id')) : null
    };
    
    // Add scheduled_at if scheduling
    if (document.getElementById('scheduleEmail').checked) {
        const scheduledAt = formData.get('scheduled_at');
        if (!scheduledAt) {
            showFormAlert('Please select a date and time for scheduling', 'danger');
            return;
        }
        data.scheduled_at = scheduledAt;
    }
    
    // Disable button
    this.disabled = true;
    const originalText = this.innerHTML;
    const isScheduling = document.getElementById('scheduleEmail').checked;
    this.innerHTML = isScheduling ? '<i class="fas fa-spinner fa-spin"></i> Scheduling...' : '<i class="fas fa-spinner fa-spin"></i> Sending...';
    
    try {
        const response = await fetch('client_emails_api.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showFormAlert(result.message, 'success');
            
            // Reset form and close modal after 1 second
            setTimeout(() => {
                form.reset();
                if (window.emailBodyEditor) {
                    window.emailBodyEditor.setData('');
                }
                bootstrap.Modal.getInstance(document.getElementById('composeEmailModal')).hide();
                loadEmails(); // Reload email list
            }, 1000);
        } else {
            showFormAlert(result.error || 'Failed to send email', 'danger');
        }
    } catch (error) {
        console.error('Error sending email:', error);
        showFormAlert('Error sending email: ' + error.message, 'danger');
    } finally {
        this.disabled = false;
        this.innerHTML = originalText;
    }
});

// Show form alert
function showFormAlert(message, type) {
    const alertDiv = document.getElementById('emailFormAlert');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.textContent = message;
    alertDiv.classList.remove('d-none');
    
    setTimeout(() => {
        alertDiv.classList.add('d-none');
    }, 5000);
}

// Load emails when the email tab is shown
document.querySelector('a[href="#emails"]').addEventListener('shown.bs.tab', function() {
    loadEmails();
});

// Load templates on page load
document.addEventListener('DOMContentLoaded', function() {
    loadEmailTemplates();
    
    // Event delegation for edit contact buttons
    document.addEventListener('click', function(e) {
        if (e.target.closest('.edit-contact-btn')) {
            const btn = e.target.closest('.edit-contact-btn');
            editContact(
                btn.dataset.contactId,
                btn.dataset.contactName,
                btn.dataset.contactEmail,
                btn.dataset.contactPhone,
                btn.dataset.contactPrimary
            );
        }
        
        if (e.target.closest('.delete-contact-btn')) {
            const btn = e.target.closest('.delete-contact-btn');
            deleteContact(btn.dataset.contactId, btn.dataset.contactName);
        }
    });
});

// Contact management functions
let editingContactId = null;

function showAddContactModal() {
    editingContactId = null;
    document.getElementById('contactModalLabel').textContent = 'Add Contact';
    document.getElementById('contactForm').reset();
}

function editContact(id, name, email, phone, isPrimary) {
    editingContactId = id;
    document.getElementById('contactModalLabel').textContent = 'Edit Contact';
    document.getElementById('contactName').value = name;
    document.getElementById('contactEmail').value = email;
    document.getElementById('contactPhone').value = phone;
    document.getElementById('contactPrimary').checked = isPrimary == 1;
}

function saveContact() {
    const name = document.getElementById('contactName').value.trim();
    const email = document.getElementById('contactEmail').value.trim();
    const phone = document.getElementById('contactPhone').value.trim();
    const isPrimary = document.getElementById('contactPrimary').checked ? 1 : 0;
    
    if (!name || !email || !phone) {
        alert('Please fill in all required fields');
        return;
    }
    
    const url = editingContactId 
        ? `client_contacts_api.php?action=update&id=${editingContactId}`
        : `client_contacts_api.php?action=add&client_id=<?= $id ?>`;
    
    fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            name: name,
            email: email,
            phone: phone,
            is_primary: isPrimary
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error saving contact: ' + error);
    });
}

function deleteContact(id, name) {
    if (!confirm('Are you sure you want to delete contact: ' + name + '?')) {
        return;
    }
    
    fetch(`client_contacts_api.php?action=delete&id=${id}`, {
        method: 'POST'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        alert('Error deleting contact: ' + error);
    });
}

</script>

<?php include '../backend/includes/footer.php'; ?>

<!-- CKEditor 5 Rich Text Editor (Self-Hosted, GPL License) -->
<link rel="stylesheet" href="js/ckeditor5/ckeditor5.css" />
<script type="module">
import {
    ClassicEditor,
    Essentials,
    Bold,
    Italic,
    Underline,
    Paragraph,
    Heading,
    Link,
    List,
    Alignment,
    SourceEditing,
    GeneralHtmlSupport
} from './js/ckeditor5/ckeditor5.js';

// Initialize CKEditor 5 for compose email modal (email-optimized preset)
// Lazy-initialize on first modal show so the element is visible
const composeEmailModal = document.getElementById('composeEmailModal');
let emailEditorInitialized = false;

composeEmailModal.addEventListener('shown.bs.modal', function () {
    if (emailEditorInitialized) return;

    ClassicEditor
        .create(document.querySelector('#emailBody'), {
            licenseKey: 'GPL',
            plugins: [
                Essentials, Bold, Italic, Underline,
                Paragraph, Heading, Link, List,
                Alignment, SourceEditing, GeneralHtmlSupport
            ],
            toolbar: [
                'undo', 'redo', '|',
                'heading', '|',
                'bold', 'italic', 'underline', '|',
                'link', '|',
                'bulletedList', 'numberedList', '|',
                'alignment', '|',
                'sourceEditing'
            ],
            heading: {
                options: [
                    { model: 'paragraph', title: 'Paragraph', class: 'ck-heading_paragraph' },
                    { model: 'heading2', view: 'h2', title: 'Heading 2', class: 'ck-heading_heading2' },
                    { model: 'heading3', view: 'h3', title: 'Heading 3', class: 'ck-heading_heading3' }
                ]
            },
            htmlSupport: {
                allow: [
                    {
                        name: /.*/,
                        attributes: true,
                        classes: true,
                        styles: true
                    }
                ]
            }
        })
        .then(editor => {
            window.emailBodyEditor = editor;
            emailEditorInitialized = true;
        })
        .catch(error => {
            console.error('CKEditor initialization error (emailBody):', error);
        });
});
</script>
