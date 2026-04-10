<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/google_calendar.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id']) && isset($_POST['status'])) {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token']), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'danger');
        header('Location: bookings_list.php');
        exit;
    }
    $booking_id = safe_int($_POST['booking_id']);
    $status = scalar_string($_POST['status']);

    // Fetch current booking for credit handling
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);
    $booking_row = $stmt->fetch(PDO::FETCH_ASSOC);
    $previous_status = $booking_row ? scalar_string($booking_row['status']) : '';

    $stmt = $conn->prepare("UPDATE bookings SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$status, $booking_id]);

    if ($booking_row) {
        $updated_booking = $booking_row;
        $updated_booking['status'] = $status;
        $pkg_credit_id = (int)($booking_row['package_credit_id'] ?? 0);
        $admin_id = $_SESSION['admin_id'] ?? null;

        // Remove the event from Google Calendar when a booking is cancelled
        if ($status === 'cancelled' && !empty($booking_row['google_event_id'])) {
            $gcal_event_id = $booking_row['google_event_id'];
            if (GoogleCalendarIntegration::isOAuthConfigured()) {
                $stmt_tok = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
                while ($tok_row = $stmt_tok->fetch(PDO::FETCH_ASSOC)) {
                    if (GoogleCalendarIntegration::deleteEventOAuth($gcal_event_id, (int)$tok_row['admin_user_id'])) {
                        // Clear stored event ID so a future re-activation doesn't try to delete again
                        $conn->prepare("UPDATE bookings SET google_event_id = NULL WHERE id = ?")->execute([$booking_id]);
                        break;
                    }
                }
            }
        }

        // Send cancellation email to the client when a booking is cancelled
        if ($status === 'cancelled' && !empty($booking_row['client_email'])) {
            $email_service = new EmailService(null, $conn);
            $email_service->sendBookingCancellation($booking_row);
        }

        if ($status === 'confirmed' && $previous_status !== 'confirmed') {
            if (!empty($booking_row['client_email'])) {
                $email_service = new EmailService(null, $conn);
                $email_service->sendBookingConfirmation($updated_booking);
            }

            if (empty($booking_row['google_event_id'])) {
                $gcal_event_id = null;
                if (GoogleCalendarIntegration::isOAuthConfigured()) {
                    $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
                    while ($admin_row = $stmt_admins->fetch(PDO::FETCH_ASSOC)) {
                        $cal_result = GoogleCalendarIntegration::addEventOAuth($updated_booking, (int)$admin_row['admin_user_id']);
                        if (!empty($cal_result['success'])) {
                            $gcal_event_id = $cal_result['event_id'] ?? null;
                            break;
                        }
                    }
                }
                if (!$gcal_event_id) {
                    $google_calendar = new GoogleCalendarIntegration();
                    if ($google_calendar->isConfigured()) {
                        $svc_result = $google_calendar->addEvent($updated_booking);
                        $gcal_event_id = $svc_result['event_id'] ?? null;
                    }
                }
                if ($gcal_event_id) {
                    $conn->prepare("UPDATE bookings SET google_event_id = ? WHERE id = ?")->execute([$gcal_event_id, $booking_id]);
                }
            }
        }

        if ($previous_status !== $status && !empty($booking_row['client_id'])) {
            $client_notification_title = null;
            if ($status === 'confirmed') {
                $client_notification_title = 'Appointment request confirmed';
            } elseif ($status === 'cancelled' && $previous_status === 'pending') {
                $client_notification_title = 'Appointment request denied';
            }

            if ($client_notification_title !== null) {
                bdta_create_notification(
                    $conn,
                    'portal',
                    safe_int($booking_row['client_id']),
                    'booking',
                    $booking_id,
                    $client_notification_title,
                    scalar_string($booking_row['service_type']) . ' on ' . scalar_string($booking_row['appointment_date']),
                    '/portal/appointments.php'
                );
            }
        }

        if ($status === 'cancelled' && $pkg_credit_id > 0) {
            // Refund credit: check that a consume transaction exists (avoid double-refund)
            $stmt = $conn->prepare("
                SELECT COUNT(*) FROM package_credit_transactions
                WHERE client_package_credit_id = ? AND booking_id = ? AND transaction_type = 'refund'
            ");
            $stmt->execute([$pkg_credit_id, $booking_id]);
            $already_refunded = safe_int($stmt->fetchColumn());

            if (!$already_refunded) {
                $conn->prepare("
                    UPDATE client_package_credits
                    SET used_credits = used_credits - 1, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ? AND used_credits > 0
                ")->execute([$pkg_credit_id]);

                // Fetch appointment_type_id for log
                $stmt = $conn->prepare("SELECT appointment_type_id, client_id FROM client_package_credits WHERE id = ?");
                $stmt->execute([$pkg_credit_id]);
                $cpc_row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($cpc_row) {
                    $conn->prepare("
                        INSERT INTO package_credit_transactions
                            (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
                        VALUES (?, ?, ?, 'refund', 1, ?, ?, ?)
                    ")->execute([
                        $pkg_credit_id,
                        $cpc_row['client_id'],
                        $cpc_row['appointment_type_id'],
                        $booking_id,
                        "Credit refunded for cancelled booking #{$booking_id}",
                        $admin_id
                    ]);
                }
                setFlashMessage("Booking cancelled and credit refunded.", 'success');
                redirect('bookings_list.php');
            }
        } elseif (in_array($status, ['confirmed', 'completed']) && empty($pkg_credit_id)
                  && !empty($booking_row['appointment_type_id']) && !empty($booking_row['client_id'])) {
            // If no credit was applied at booking time, check if one should be consumed now
            $apt_type_id = (int)$booking_row['appointment_type_id'];
            $client_id_b  = (int)$booking_row['client_id'];

            // Only deduct if appointment type has consumes_credits set
            $stmt = $conn->prepare("SELECT consumes_credits FROM appointment_types WHERE id = ?");
            $stmt->execute([$apt_type_id]);
            $apt_type = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($apt_type && $apt_type['consumes_credits']) {
                $stmt = $conn->prepare("
                    SELECT cpc.id, cpc.appointment_type_id
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
                $stmt->execute([$client_id_b, $apt_type_id]);
                $credit_row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($credit_row) {
                    $conn->prepare("
                        UPDATE client_package_credits
                        SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ")->execute([$credit_row['id']]);

                    // Link credit to booking
                    $conn->prepare("
                        UPDATE bookings SET package_credit_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?
                    ")->execute([$credit_row['id'], $booking_id]);

                    $conn->prepare("
                        INSERT INTO package_credit_transactions
                            (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
                        VALUES (?, ?, ?, 'consume', -1, ?, ?, ?)
                    ")->execute([
                        $credit_row['id'],
                        $client_id_b,
                        $apt_type_id,
                        $booking_id,
                        "Credit consumed on status change to {$status} for booking #{$booking_id}",
                        $admin_id
                    ]);
                }
            }
        }
    }

    setFlashMessage("Booking status updated to $status.", 'success');
    redirect('bookings_list.php');
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (empty($_POST['csrf_token']) || !hash_equals(scalar_string($_SESSION['csrf_token']), scalar_string($_POST['csrf_token']))) {
        setFlashMessage('Invalid request.', 'danger');
        redirect('bookings_list.php');
    }
    $booking_id = safe_int($_POST['delete_id']);
    $stmt = $conn->prepare("DELETE FROM bookings WHERE id = ?");
    $stmt->execute([$booking_id]);

    setFlashMessage('Booking deleted.', 'info');
    redirect('bookings_list.php');
}

$stmt = $conn->query("
    SELECT b.*, c.address AS client_address_on_file
    FROM bookings b
    LEFT JOIN clients c ON b.client_id = c.id
    ORDER BY b.appointment_date DESC, b.appointment_time DESC
");
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Bookings';
require_once '../backend/includes/header.php';
?>

<div class="py-4">
    <h2 class="mb-4"><i class="fas fa-calendar-check me-2"></i>Bookings Management</h2>
    
    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Client</th>
                            <th>Contact</th>
                            <th>Service</th>
                            <th>Date & Time</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bookings) > 0): ?>
                            <?php
                            $location_type_labels = [
                                'client_address' => '<i class="fas fa-home me-1" aria-hidden="true"></i>',
                                'custom_address' => '<i class="fas fa-map-marker-alt me-1" aria-hidden="true"></i>',
                                'phone_inbound'  => '<i class="fas fa-phone me-1" aria-hidden="true"></i>Phone (Inbound)',
                                'phone_outbound' => '<i class="fas fa-phone me-1" aria-hidden="true"></i>Phone (Outbound)',
                                'webcall'        => '<i class="fas fa-video me-1" aria-hidden="true"></i>',
                                'fixed'          => '<i class="fas fa-location-dot me-1" aria-hidden="true"></i>',
                            ];
                            ?>
                            <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td><?php echo $booking['id']; ?></td>
                                <td><?php echo escape($booking['client_name']); ?></td>
                                <td>
                                    <small>
                                        <?php echo escape($booking['client_email']); ?><br>
                                        <?php if ($booking['client_phone']): ?>
                                            <?php echo escape($booking['client_phone']); ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td><?php echo escape($booking['service_type']); ?></td>
                                <td>
                                    <?php echo escape($booking['appointment_date']); ?><br>
                                    <small><?php echo escape($booking['appointment_time']); ?></small>
                                </td>
                                <td>
                                    <?php
                                    $lt = $booking['location_type'] ?? '';
                                    $lv = $booking['location'] ?? '';
                                    // For client_address: use stored location value; fall back to current client address on file
                                    if ($lt === 'client_address' && empty($lv)) {
                                        $lv = $booking['client_address_on_file'] ?? '';
                                    }
                                    if ($lt) {
                                        $icon_prefix = $location_type_labels[$lt] ?? '<i class="fas fa-map-marker-alt me-1" aria-hidden="true"></i>';
                                        if (in_array($lt, ['custom_address', 'webcall', 'fixed', 'client_address'])) {
                                            echo $icon_prefix . escape($lv ?: '—');
                                        } else {
                                            echo $icon_prefix;
                                        }
                                    } elseif ($lv) {
                                        echo '<small>' . escape($lv) . '</small>';
                                    } else {
                                        echo '<span class="text-muted small">—</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">
                                        <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                                            <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                            <option value="completed" <?php echo $booking['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </form>
                                </td>
                                <td>
                                    <div class="d-none d-md-inline-flex gap-1 table-action-buttons">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this booking?')">
                                            <input type="hidden" name="delete_id" value="<?php echo $booking['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger table-action-btn" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                    <div class="d-md-none table-action-dropdown">
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle table-action-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                <i class="fas fa-ellipsis-v"></i>
                                            </button>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <form method="post" onsubmit="return confirm('Delete this booking?')">
                                                        <input type="hidden" name="delete_id" value="<?php echo $booking['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                                        <button type="submit" class="dropdown-item text-danger w-100 text-start border-0 bg-transparent">
                                                            <i class="fas fa-trash me-2"></i>Delete
                                                        </button>
                                                    </form>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                            <td colspan="8" class="text-center text-muted">No bookings yet</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
