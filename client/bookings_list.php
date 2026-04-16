<?php
require_once '../backend/includes/config.php';
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/google_calendar.php';
requireLogin();

$db = new Database();
$conn = $db->getConnection();

const BDTA_BOOKING_LIST_SORT_EMPTY_TIME = '00:00:00';
const BDTA_BOOKING_LIST_FILTER_EMPTY_TIME = '23:59:59';

/**
 * @param array<string, mixed> $booking
 */
function bdta_booking_list_client_label(array $booking): string
{
    $client_name = trim(array_string_value($booking, 'client_profile_name'));
    if ($client_name !== '') {
        return $client_name;
    }

    return trim(array_string_value($booking, 'client_name'));
}

function bdta_booking_list_time_label(mixed $value): string
{
    $time_value = trim(scalar_string($value));
    if ($time_value === '') {
        return 'Time TBD';
    }

    $timestamp = strtotime($time_value);
    return $timestamp === false ? $time_value : date('g:i A', $timestamp);
}

function bdta_booking_list_date_label(mixed $value): string
{
    $date_value = trim(scalar_string($value));
    if ($date_value === '') {
        return 'Date TBD';
    }

    $formatted_date = formatDate($date_value, 'M j, Y');
    return $formatted_date !== '' ? $formatted_date : $date_value;
}

function bdta_booking_list_is_valid_date_string(string $value): bool
{
    if ($value === '') {
        return false;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, bdta_get_display_timezone());
    $date_errors = DateTimeImmutable::getLastErrors();

    return $date instanceof DateTimeImmutable
        && ($date_errors === false || ($date_errors['warning_count'] === 0 && $date_errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

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

        if ($previous_status !== $status) {
            bdta_create_admin_notifications(
                $conn,
                'booking',
                $booking_id,
                'Booking status changed',
                'Booking #' . $booking_id . ' for ' . scalar_string($booking_row['client_name']) . ' changed from ' . $previous_status . ' to ' . $status . '.',
                '/client/bookings_list.php'
            );
        }

        // Remove the event from Google Calendar when a booking is cancelled
        if ($status === 'cancelled' && !empty($booking_row['google_event_id'])) {
            $gcal_event_id = $booking_row['google_event_id'];
            if (GoogleCalendarIntegration::deleteEventForBooking($gcal_event_id, $booking_row)) {
                // Clear stored event ID so a future re-activation doesn't try to delete again
                $conn->prepare("UPDATE bookings SET google_event_id = NULL WHERE id = ?")->execute([$booking_id]);
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
                $gcal_result = GoogleCalendarIntegration::addEventForBooking($updated_booking);
                $gcal_event_id = $gcal_result['event_id'] ?? null;
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

$requested_view_filter = scalar_string($_GET['view'] ?? '');
$view_filter = in_array($requested_view_filter, ['upcoming', 'past', 'custom'], true)
    ? $requested_view_filter
    : 'upcoming';

$requested_sort_preference = scalar_string($_GET['sort'] ?? '');
$sort_preference = in_array($requested_sort_preference, ['default', 'asc', 'desc'], true)
    ? $requested_sort_preference
    : 'default';

$sort_direction = 'asc';
if ($sort_preference === 'desc' || ($sort_preference === 'default' && $view_filter === 'past')) {
    $sort_direction = 'desc';
}

$start_date = trim(scalar_string($_GET['start_date'] ?? ''));
if (!bdta_booking_list_is_valid_date_string($start_date)) {
    $start_date = '';
}

$end_date = trim(scalar_string($_GET['end_date'] ?? ''));
if (!bdta_booking_list_is_valid_date_string($end_date)) {
    $end_date = '';
}

if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) {
    setFlashMessage('Start date must be on or before the end date.', 'warning');
    $start_date = '';
    $end_date = '';
}

$now = new DateTimeImmutable('now', bdta_get_display_timezone());
$current_date = $now->format('Y-m-d');
$current_time = $now->format('H:i:s');
// Use end-of-day for filtering so same-day bookings without a stored time still appear in upcoming results
// until the day passes, while sorting should treat missing times as the start of the day for stable ordering.
$appointment_time_fallback_sql = "TIME(COALESCE(NULLIF(b.appointment_time, ''), ?))";
$appointment_time_missing_sort_sql = "CASE WHEN NULLIF(TRIM(COALESCE(b.appointment_time, '')), '') IS NULL THEN 1 ELSE 0 END";
$where_conditions = [];
$query_params = [];

if ($view_filter === 'upcoming') {
    $where_conditions[] = "(
        b.appointment_date > ?
        OR (b.appointment_date = ? AND {$appointment_time_fallback_sql} >= ?)
    )";
    $query_params[] = $current_date;
    $query_params[] = $current_date;
    $query_params[] = BDTA_BOOKING_LIST_FILTER_EMPTY_TIME;
    $query_params[] = $current_time;
} elseif ($view_filter === 'past') {
    $where_conditions[] = "(
        b.appointment_date < ?
        OR (b.appointment_date = ? AND {$appointment_time_fallback_sql} < ?)
    )";
    $query_params[] = $current_date;
    $query_params[] = $current_date;
    $query_params[] = BDTA_BOOKING_LIST_FILTER_EMPTY_TIME;
    $query_params[] = $current_time;
}

if ($start_date !== '') {
    $where_conditions[] = 'b.appointment_date >= ?';
    $query_params[] = $start_date;
}

if ($end_date !== '') {
    $where_conditions[] = 'b.appointment_date <= ?';
    $query_params[] = $end_date;
}

$booking_sql = "
    SELECT b.*, c.name AS client_profile_name, c.address AS client_address_on_file
    FROM bookings b
    LEFT JOIN clients c ON b.client_id = c.id
";

if ($where_conditions !== []) {
    $booking_sql .= ' WHERE ' . implode(' AND ', $where_conditions);
}

$order_by_sql = [
    'asc' => "
        ORDER BY b.appointment_date ASC,
                 {$appointment_time_missing_sort_sql} ASC,
                 {$appointment_time_fallback_sql} ASC,
                 b.id ASC",
    'desc' => "
        ORDER BY b.appointment_date DESC,
                 {$appointment_time_missing_sort_sql} ASC,
                 {$appointment_time_fallback_sql} DESC,
                 b.id DESC",
];
$booking_sql .= $order_by_sql[$sort_direction];
$query_params[] = BDTA_BOOKING_LIST_SORT_EMPTY_TIME;

// nosemgrep: php.lang.security.injection.tainted-callable.tainted-callable, php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- fixed SQL fragments only; request values stay parameterized in $query_params.
$stmt = $conn->prepare($booking_sql);
$stmt->execute($query_params);
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$active_view_labels = [
    'upcoming' => 'Upcoming appointments',
    'past' => 'Past appointments',
    'custom' => 'Custom date range',
];
$sort_labels = [
    'upcoming' => [
        'asc' => 'Soonest appointment first',
        'desc' => 'Furthest appointment first',
    ],
    'past' => [
        'asc' => 'Oldest appointment first',
        'desc' => 'Most recent appointment first',
    ],
    'custom' => [
        'asc' => 'Earliest appointment first',
        'desc' => 'Latest appointment first',
    ],
];
$active_filter_summary = match ($view_filter) {
    'upcoming' => $active_view_labels['upcoming'],
    'past' => $active_view_labels['past'],
    'custom' => $active_view_labels['custom'],
    default => 'Appointments',
};
$active_sort_summary = match ($view_filter) {
    'upcoming' => $sort_labels['upcoming'][$sort_direction],
    'past' => $sort_labels['past'][$sort_direction],
    'custom' => $sort_labels['custom'][$sort_direction],
    default => 'Sorted appointments',
};

$page_title = 'Bookings';
require_once '../backend/includes/header.php';
?>

<style>
    .booking-filter-card,
    .booking-table-card {
        border: 0;
        box-shadow: 0 0.5rem 1.5rem rgba(15, 23, 42, 0.08);
    }

    .booking-filter-card .card-body,
    .booking-table-card .card-body {
        padding: 1.25rem;
    }

    .booking-summary-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .booking-summary-badge {
        border-radius: 999px;
        background: rgba(13, 110, 253, 0.08);
        color: #0d6efd;
        font-size: 0.875rem;
        font-weight: 600;
        padding: 0.45rem 0.85rem;
    }

    .booking-table {
        --bs-table-bg: transparent;
        margin-bottom: 0;
    }

    .booking-table thead th {
        background: #f8fafc;
        border-bottom: 0;
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        padding-top: 0.9rem;
        padding-bottom: 0.9rem;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .booking-table tbody td {
        border-color: #e2e8f0;
        padding-top: 1rem;
        padding-bottom: 1rem;
        vertical-align: middle;
    }

    .booking-table tbody tr:nth-of-type(odd) {
        --bs-table-accent-bg: rgba(148, 163, 184, 0.06);
    }

    .booking-client-link {
        color: inherit;
        font-weight: 600;
        text-decoration: none;
    }

    .booking-client-link:hover,
    .booking-client-link:focus {
        color: #0d6efd;
        text-decoration: underline;
    }

    .booking-subtext {
        color: #64748b;
        font-size: 0.875rem;
    }

    .booking-date-label {
        font-weight: 600;
    }

    .booking-status-select {
        min-width: 8.75rem;
    }
</style>

<div class="py-4">
    <div class="mb-4">
        <h2 class="mb-2"><i class="fas fa-calendar-check me-2"></i>Bookings Management</h2>
        <div class="booking-summary-badges">
            <span class="booking-summary-badge"><?php echo escape($active_filter_summary); ?></span>
            <span class="booking-summary-badge"><?php echo escape($active_sort_summary); ?></span>
            <span class="booking-summary-badge"><?php echo count($bookings); ?> shown</span>
        </div>
    </div>
    
    <div class="card booking-filter-card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="bookingViewFilter" class="form-label">Show</label>
                    <select name="view" id="bookingViewFilter" class="form-select">
                        <option value="upcoming" <?php echo $view_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming appointments</option>
                        <option value="past" <?php echo $view_filter === 'past' ? 'selected' : ''; ?>>Past appointments</option>
                        <option value="custom" <?php echo $view_filter === 'custom' ? 'selected' : ''; ?>>Custom date range</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="bookingSortFilter" class="form-label">Sort order</label>
                    <select name="sort" id="bookingSortFilter" class="form-select">
                        <option value="default" <?php echo $sort_preference === 'default' ? 'selected' : ''; ?>>Default for selection</option>
                        <option value="asc" <?php echo $sort_preference === 'asc' ? 'selected' : ''; ?>>Ascending</option>
                        <option value="desc" <?php echo $sort_preference === 'desc' ? 'selected' : ''; ?>>Descending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="bookingStartDate" class="form-label">Start date</label>
                    <input type="date" name="start_date" id="bookingStartDate" class="form-control" value="<?php echo escape($start_date); ?>">
                </div>
                <div class="col-md-2">
                    <label for="bookingEndDate" class="form-label">End date</label>
                    <input type="date" name="end_date" id="bookingEndDate" class="form-control" value="<?php echo escape($end_date); ?>">
                </div>
                <div class="col-md-2 d-grid d-md-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-fill">
                        <i class="fas fa-filter me-1"></i>Apply
                    </button>
                    <a href="bookings_list.php" class="btn btn-outline-secondary flex-fill">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card booking-table-card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover booking-table align-middle">
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
                                <td>
                                    <span class="fw-semibold">#<?php echo $booking['id']; ?></span>
                                </td>
                                <td>
                                    <?php $client_label = bdta_booking_list_client_label($booking); ?>
                                    <?php $client_profile_id = safe_int($booking['client_id'] ?? 0); ?>
                                    <?php if ($client_profile_id > 0): ?>
                                        <a href="clients_view.php?id=<?php echo $client_profile_id; ?>" class="booking-client-link">
                                            <?php echo escape($client_label); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="fw-semibold"><?php echo escape($client_label); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="booking-subtext">
                                        <?php echo escape($booking['client_email']); ?><br>
                                        <?php if ($booking['client_phone']): ?>
                                            <?php echo escape($booking['client_phone']); ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <span class="fw-semibold"><?php echo escape($booking['service_type']); ?></span>
                                </td>
                                <td>
                                    <div class="booking-date-label"><?php echo escape(bdta_booking_list_date_label($booking['appointment_date'])); ?></div>
                                    <div class="booking-subtext"><?php echo escape(bdta_booking_list_time_label($booking['appointment_time'])); ?></div>
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
                                        <select name="status" class="form-select form-select-sm booking-status-select" onchange="this.form.submit()">
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
                            <td colspan="8" class="text-center text-muted py-5">No bookings found for the selected filters.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once '../backend/includes/footer.php'; ?>
