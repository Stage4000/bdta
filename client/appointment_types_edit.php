<?php
/**
 * Brook's Dog Training Academy - Add/Edit Appointment Type
 * Configure appointment type with rules and behaviors
 */

require_once __DIR__ . '/../backend/includes/config.php';
require_once __DIR__ . '/../backend/includes/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$db = new Database();
$conn = $db->getConnection();

$id = safe_int($_GET['id'] ?? 0);
$is_edit = $id > 0;

// Get existing type data if editing
$type = null;
if ($is_edit) {
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE id = ?");
    $stmt->execute([$id]);
    $type = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$type) {
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Appointment type not found.'];
        header('Location: appointment_types_list.php');
        exit;
    }
}
$type_row = is_array($type) ? $type : [];

// Get base URL for building booking link dynamically from current request
$base_url = getDynamicBaseUrl();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = scalar_string($_POST['name'] ?? '');
    $description = scalar_string($_POST['description'] ?? '');
    $duration_minutes = safe_int($_POST['duration_minutes'] ?? 60);
    $buffer_before_minutes = safe_int($_POST['buffer_before_minutes'] ?? 0);
    $buffer_after_minutes = safe_int($_POST['buffer_after_minutes'] ?? 0);
    $use_travel_time_buffer = isset($_POST['use_travel_time_buffer']) ? 1 : 0;
    $travel_time_minutes = safe_int($_POST['travel_time_minutes'] ?? 0);
    $advance_booking_min_days = safe_int($_POST['advance_booking_min_days'] ?? 1);
    $advance_booking_max_days = safe_int($_POST['advance_booking_max_days'] ?? 90);
    $cancellation_notice_hours = safe_int($_POST['cancellation_notice_hours'] ?? 0);
    $selected_form_ids = isset($_POST['form_ids']) && is_array($_POST['form_ids'])
        ? array_map('intval', $_POST['form_ids'])
        : [];
    $requires_forms = !empty($selected_form_ids) ? 1 : 0;
    $contract_template_id = !empty($_POST['contract_template_id']) ? safe_int($_POST['contract_template_id']) : null;
    $requires_contract = ($contract_template_id !== null) ? 1 : 0;
    $auto_invoice = isset($_POST['auto_invoice']) ? 1 : 0;
    $invoice_due_days = safe_int($_POST['invoice_due_days'] ?? 7);
    $default_amount = safe_float($_POST['default_amount'] ?? 0);
    $consumes_credits = isset($_POST['consumes_credits']) ? 1 : 0;
    $credit_count = safe_int($_POST['credit_count'] ?? 1);
    $is_group_class = isset($_POST['is_group_class']) ? 1 : 0;
    $max_participants = safe_int($_POST['max_participants'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $portal_available = isset($_POST['portal_available']) ? 1 : 0;
    $confirmation_template_id = !empty($_POST['confirmation_template_id']) ? safe_int($_POST['confirmation_template_id']) : null;
    $reminder_template_id     = !empty($_POST['reminder_template_id'])     ? safe_int($_POST['reminder_template_id'])     : null;
    $cancellation_template_id = !empty($_POST['cancellation_template_id']) ? safe_int($_POST['cancellation_template_id']) : null;
    
    // Handle Mini Sessions configuration
    $is_mini_session = isset($_POST['is_mini_session']) ? 1 : 0;
    $mini_session_location = $is_mini_session ? scalar_string($_POST['mini_session_location'] ?? '') : null;
    $mini_session_topic = $is_mini_session ? scalar_string($_POST['mini_session_topic'] ?? '') : null;
    
    // Handle Field Rental configuration
    $is_field_rental = isset($_POST['is_field_rental']) ? 1 : 0;
    $field_rental_location = $is_field_rental ? scalar_string($_POST['field_rental_location'] ?? '') : null;

    // Handle Group Class location
    $group_class_location = $is_group_class ? scalar_string($_POST['group_class_location'] ?? '') : null;

    // Handle allowed location types configuration
    // Fixed types (mini_session/field_rental/group_class) don't need this — location is always 'fixed'
    $allowed_loc_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall'];
    if ($is_mini_session || $is_field_rental || $is_group_class) {
        $location_types_json = null; // Fixed location — no selection needed
    } else {
        $selected_loc_types = isset($_POST['location_types']) && is_array($_POST['location_types'])
            ? array_values(array_filter($_POST['location_types'], static fn($t): bool => is_string($t) && in_array($t, $allowed_loc_types, true)))
            : [];
        $location_types_json = !empty($selected_loc_types) ? json_encode($selected_loc_types) : null;
    }
    
    // Handle schedule type and specific date(s)
    $schedule_type = scalar_string($_POST['schedule_type'] ?? 'recurring');
    $specific_date  = null;
    $specific_dates = null;

    if ($schedule_type === 'specific_date') {
        // Parse the new multi-date JSON submitted from the builder UI
        $raw_specific_dates = scalar_string($_POST['specific_dates'] ?? '');
        if ($raw_specific_dates !== '') {
            $parsed_dates = decode_json_assoc_list($raw_specific_dates);
            if ($parsed_dates !== []) {
                $clean_dates = [];
                foreach ($parsed_dates as $entry) {
                    $entry_date = trim(array_string_value($entry, 'date'));
                    if (empty($entry_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date)) {
                        continue;
                    }
                    $clean_timeslots = [];
                    $timeslots = $entry['timeslots'] ?? [];
                    if (!is_array($timeslots)) {
                        $timeslots = [];
                    }
                    foreach ($timeslots as $slot) {
                        if (!is_array($slot)) {
                            continue;
                        }
                        $slot_type = array_string_value($slot, 'type');
                        $slot_time = array_string_value($slot, 'time');
                        $slot_start = array_string_value($slot, 'start');
                        $slot_end = array_string_value($slot, 'end');
                        if ($slot_type === 'point' && $slot_time !== ''
                            && preg_match('/^\d{2}:\d{2}$/', $slot_time)) {
                            $clean_timeslots[] = ['type' => 'point', 'time' => $slot_time];
                        } elseif ($slot_type === 'range'
                            && $slot_start !== '' && $slot_end !== ''
                            && preg_match('/^\d{2}:\d{2}$/', $slot_start)
                            && preg_match('/^\d{2}:\d{2}$/', $slot_end)
                            && $slot_start < $slot_end) {
                            $clean_timeslots[] = [
                                'type'  => 'range',
                                'start' => $slot_start,
                                'end'   => $slot_end,
                            ];
                        }
                    }
                    $clean_dates[] = ['date' => $entry_date, 'timeslots' => $clean_timeslots];
                }
                if (!empty($clean_dates)) {
                    $specific_dates = json_encode($clean_dates);
                    // Keep legacy specific_date as the first/earliest date for backward compat
                    usort(
                        $clean_dates,
                        static fn(array $a, array $b): int => array_string_value($a, 'date') <=> array_string_value($b, 'date')
                    );
                    $specific_date = array_string_value($clean_dates[0], 'date');
                }
            }
        }
        // Fallback to legacy single-date field if no multi-date data was submitted
        if (empty($specific_dates) && !empty($_POST['specific_date'])) {
            $specific_date = scalar_string($_POST['specific_date']);
        }
    }
    
    // Handle availability configuration
    $available_days = isset($_POST['available_days']) && is_array($_POST['available_days']) 
        ? array_map('intval', $_POST['available_days']) 
        : [0,1,2,3,4,5,6];
    $available_days_json = json_encode($available_days);
    $available_start_time = scalar_string($_POST['available_start_time'] ?? '09:00');
    $available_end_time = scalar_string($_POST['available_end_time'] ?? '17:00');
    $time_slot_interval = safe_int($_POST['time_slot_interval'] ?? 30);

    // Handle per-day schedule configuration
    $per_day_schedule = null;
    if (isset($_POST['use_per_day_schedule'])) {
        $day_start_times = isset($_POST['day_start_time']) && is_array($_POST['day_start_time']) ? $_POST['day_start_time'] : [];
        $day_end_times = isset($_POST['day_end_time']) && is_array($_POST['day_end_time']) ? $_POST['day_end_time'] : [];
        $per_day = [];
        foreach ($available_days as $day_index) {
            $start = scalar_string($day_start_times[$day_index] ?? '');
            $end   = scalar_string($day_end_times[$day_index] ?? '');
            if (!empty($start) && !empty($end) && $start < $end) {
                $per_day[$day_index] = ['start' => $start, 'end' => $end];
            }
        }
        if (!empty($per_day)) {
            $per_day_schedule = json_encode($per_day);
        }
    }

    // Server-side validation
    $errors = [];
    if (empty(trim($name))) {
        $errors[] = 'Appointment type name is required.';
    }
    if ($is_group_class && empty(trim($group_class_location ?? ''))) {
        $errors[] = 'Class Location is required for group class appointment types.';
    }
    if (!empty($errors)) {
        $error = implode(' ', $errors);
    } else {

    try {
        if ($is_edit) {
            $stmt = $conn->prepare("
                UPDATE appointment_types SET
                    name = ?,
                    description = ?,
                    duration_minutes = ?,
                    buffer_before_minutes = ?,
                    buffer_after_minutes = ?,
                    use_travel_time_buffer = ?,
                    travel_time_minutes = ?,
                    advance_booking_min_days = ?,
                    advance_booking_max_days = ?,
                    cancellation_notice_hours = ?,
                    requires_forms = ?,
                    requires_contract = ?,
                    contract_template_id = ?,
                    auto_invoice = ?,
                    invoice_due_days = ?,
                    consumes_credits = ?,
                    credit_count = ?,
                    is_group_class = ?,
                    max_participants = ?,
                    is_active = ?,
                    portal_available = ?,
                    schedule_type = ?,
                    specific_date = ?,
                    specific_dates = ?,
                    available_days = ?,
                    available_start_time = ?,
                    available_end_time = ?,
                    time_slot_interval = ?,
                    is_mini_session = ?,
                    mini_session_location = ?,
                    mini_session_topic = ?,
                    is_field_rental = ?,
                    field_rental_location = ?,
                    group_class_location = ?,
                    per_day_schedule = ?,
                    default_amount = ?,
                    location_types = ?,
                    confirmation_template_id = ?,
                    reminder_template_id = ?,
                    cancellation_template_id = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $description, $duration_minutes,
                $buffer_before_minutes, $buffer_after_minutes,
                $use_travel_time_buffer, $travel_time_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $cancellation_notice_hours,
                $requires_forms, $requires_contract, $contract_template_id,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active, $portal_available,
                $schedule_type, $specific_date, $specific_dates,
                $available_days_json, $available_start_time, $available_end_time, $time_slot_interval,
                $is_mini_session, $mini_session_location, $mini_session_topic,
                $is_field_rental, $field_rental_location,
                $group_class_location,
                $per_day_schedule,
                $default_amount,
                $location_types_json,
                $confirmation_template_id,
                $reminder_template_id,
                $cancellation_template_id,
                $id
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type updated successfully!'];
        } else {
            // Generate unique link for new appointment type with collision detection
            do {
                $unique_link = bin2hex(random_bytes(16));
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM appointment_types WHERE unique_link = ?");
                $check_stmt->execute([$unique_link]);
                $exists = safe_int($check_stmt->fetchColumn());
            } while ($exists > 0);
            
            $stmt = $conn->prepare("
                INSERT INTO appointment_types (
                    name, description, duration_minutes,
                    buffer_before_minutes, buffer_after_minutes,
                    use_travel_time_buffer, travel_time_minutes,
                    advance_booking_min_days, advance_booking_max_days,
                    cancellation_notice_hours,
                    requires_forms, requires_contract, contract_template_id,
                    auto_invoice, invoice_due_days,
                    consumes_credits, credit_count,
                    is_group_class, max_participants,
                    is_active, unique_link,
                    portal_available,
                    schedule_type, specific_date, specific_dates,
                    available_days, available_start_time, available_end_time, time_slot_interval,
                    is_mini_session, mini_session_location, mini_session_topic,
                    is_field_rental, field_rental_location,
                    group_class_location,
                    per_day_schedule,
                    default_amount,
                    location_types,
                    confirmation_template_id,
                    reminder_template_id,
                    cancellation_template_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $description, $duration_minutes,
                $buffer_before_minutes, $buffer_after_minutes,
                $use_travel_time_buffer, $travel_time_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $cancellation_notice_hours,
                $requires_forms, $requires_contract, $contract_template_id,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active, $unique_link,
                $portal_available,
                $schedule_type, $specific_date, $specific_dates,
                $available_days_json, $available_start_time, $available_end_time, $time_slot_interval,
                $is_mini_session, $mini_session_location, $mini_session_topic,
                $is_field_rental, $field_rental_location,
                $group_class_location,
                $per_day_schedule,
                $default_amount,
                $location_types_json,
                $confirmation_template_id,
                $reminder_template_id,
                $cancellation_template_id
            ]);
            $id = safe_int($conn->lastInsertId());
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type created successfully!'];
        }

        // Save form associations
        $conn->prepare("DELETE FROM appointment_type_forms WHERE appointment_type_id = ?")->execute([$id]);
        if (!empty($selected_form_ids)) {
            $ins = $conn->prepare("INSERT IGNORE INTO appointment_type_forms (appointment_type_id, form_template_id) VALUES (?, ?)");
            foreach ($selected_form_ids as $fid) {
                $ins->execute([$id, $fid]);
            }
        }
        
        header('Location: appointment_types_list.php');
        exit;
    } catch (PDOException $e) {
        $error = "Error saving appointment type: " . $e->getMessage();
    }
    } // end else (no validation errors)
}

// Handle reminder rule sub-actions (add/delete/toggle) — must be editing an existing type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sub_action']) && $is_edit) {
    $sub_action = scalar_string($_POST['sub_action'] ?? '');

    if ($sub_action === 'add_rule') {
        $rule_name    = trim(scalar_string($_POST['rule_name'] ?? ''));
        $hours_before = safe_int($_POST['rule_hours_before'] ?? 0);
        $tpl_id       = !empty($_POST['rule_template_id']) ? safe_int($_POST['rule_template_id']) : null;
        $rule_active  = isset($_POST['rule_is_active']) ? 1 : 0;
        if ($rule_name !== '' && $hours_before >= 1) {
            $conn->prepare("INSERT INTO booking_reminder_rules (appointment_type_id, name, hours_before, template_id, is_active) VALUES (?, ?, ?, ?, ?)")
                 ->execute([$id, $rule_name, $hours_before, $tpl_id, $rule_active]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Reminder rule added.'];
        } else {
            $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Name and hours (≥1) are required for a reminder rule.'];
        }
        header("Location: appointment_types_edit.php?id={$id}#reminder-rules");
        exit;
    }

    if ($sub_action === 'delete_rule') {
        $rule_id = safe_int($_POST['rule_id'] ?? 0);
        $conn->prepare("DELETE FROM booking_reminder_rules WHERE id = ? AND appointment_type_id = ?")
             ->execute([$rule_id, $id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Reminder rule removed.'];
        header("Location: appointment_types_edit.php?id={$id}#reminder-rules");
        exit;
    }

    if ($sub_action === 'toggle_rule') {
        $rule_id = safe_int($_POST['rule_id'] ?? 0);
        $conn->prepare("UPDATE booking_reminder_rules SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND appointment_type_id = ?")
             ->execute([$rule_id, $id]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Reminder rule status updated.'];
        header("Location: appointment_types_edit.php?id={$id}#reminder-rules");
        exit;
    }
}

// Load all active form templates for the selection UI
$all_forms = $conn->query("SELECT id, name FROM form_templates WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Prepare existing specific_dates data for the multi-date builder
$existing_specific_dates = [];
if ($is_edit) {
    $existing_specific_dates = decode_json_assoc_list(array_string_value($type_row, 'specific_dates'));
}
// Migrate legacy single specific_date into the new format for display
if ($is_edit && $existing_specific_dates === []) {
    $legacy_specific_date = array_string_value($type_row, 'specific_date');
    if ($legacy_specific_date !== '') {
        $existing_specific_dates = [['date' => $legacy_specific_date, 'timeslots' => []]];
    }
}
$existing_specific_dates_json = htmlspecialchars(scalar_string(json_encode($existing_specific_dates)), ENT_QUOTES, 'UTF-8');

// Load all active contract templates for the dropdown
$all_contract_templates = $conn->query("SELECT id, name FROM contract_templates WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Load email templates for confirmation/reminder/cancellation overrides
$confirmation_templates  = $conn->query("SELECT id, name FROM email_templates WHERE template_type = 'booking_confirmation' AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$reminder_templates      = $conn->query("SELECT id, name FROM email_templates WHERE template_type = 'booking_reminder'     AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$cancellation_templates  = $conn->query("SELECT id, name FROM email_templates WHERE template_type = 'booking_cancellation' AND is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Load per-appointment-type reminder rules (only when editing)
$type_reminder_rules = [];
if ($is_edit) {
    $stmt = $conn->prepare("
        SELECT r.*, et.name AS template_name
        FROM booking_reminder_rules r
        LEFT JOIN email_templates et ON et.id = r.template_id
        WHERE r.appointment_type_id = ?
        ORDER BY r.hours_before ASC
    ");
    $stmt->execute([$id]);
    $type_reminder_rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
$selected_form_ids_current = [];
$type_name = array_string_value($type_row, 'name');
$type_description = array_string_value($type_row, 'description');
$type_duration = array_int_value($type_row, 'duration_minutes', 60);
$type_buffer_before = array_int_value($type_row, 'buffer_before_minutes');
$type_buffer_after = array_int_value($type_row, 'buffer_after_minutes');
$type_use_travel_time_buffer = array_int_value($type_row, 'use_travel_time_buffer') === 1;
$type_travel_time_minutes = array_int_value($type_row, 'travel_time_minutes');
$type_advance_min_days = array_int_value($type_row, 'advance_booking_min_days', 1);
$type_advance_max_days = array_int_value($type_row, 'advance_booking_max_days', 90);
$type_cancellation_notice_hours = array_int_value($type_row, 'cancellation_notice_hours');
$type_schedule = array_string_value($type_row, 'schedule_type', 'recurring');
$type_available_days = array_map('intval', decode_json_assoc(array_string_value($type_row, 'available_days')));
if ($type_available_days === []) {
    $type_available_days = [0, 1, 2, 3, 4, 5, 6];
}
$type_available_start_time = array_string_value($type_row, 'available_start_time', '09:00');
$type_available_end_time = array_string_value($type_row, 'available_end_time', '17:00');
$type_time_slot_interval = array_int_value($type_row, 'time_slot_interval', 30);
$type_contract_template_id = array_int_value($type_row, 'contract_template_id');
$type_confirmation_template_id = array_int_value($type_row, 'confirmation_template_id');
$type_reminder_template_id = array_int_value($type_row, 'reminder_template_id');
$type_cancellation_template_id = array_int_value($type_row, 'cancellation_template_id');
$type_auto_invoice = array_int_value($type_row, 'auto_invoice') === 1;
$type_invoice_due_days = array_int_value($type_row, 'invoice_due_days', 7);
$type_default_amount = safe_float($type_row['default_amount'] ?? 0);
$type_consumes_credits = array_int_value($type_row, 'consumes_credits') === 1;
$type_credit_count = array_int_value($type_row, 'credit_count', 1);
$type_is_group_class = array_int_value($type_row, 'is_group_class') === 1;
$type_max_participants = array_int_value($type_row, 'max_participants', 1);
$type_group_class_location = array_string_value($type_row, 'group_class_location');
$type_is_mini_session = array_int_value($type_row, 'is_mini_session') === 1;
$type_mini_session_location = array_string_value($type_row, 'mini_session_location');
$type_mini_session_topic = array_string_value($type_row, 'mini_session_topic');
$type_is_field_rental = array_int_value($type_row, 'is_field_rental') === 1;
$type_field_rental_location = array_string_value($type_row, 'field_rental_location');
$type_location_types = decode_json_assoc(array_string_value($type_row, 'location_types'));
$type_unique_link = array_string_value($type_row, 'unique_link');
$type_is_active = !isset($type) || array_int_value($type_row, 'is_active', 1) === 1;
$type_portal_available = array_int_value($type_row, 'portal_available') === 1;
$type_per_day_data = decode_json_assoc(array_string_value($type_row, 'per_day_schedule'));
$has_per_day = $type_per_day_data !== [];

/**
 * Human-readable label for hours_before, e.g. 48 → "2 days before"
 */
function formatHoursBefore(int $hours): string {
    if ($hours >= 168 && $hours % 168 === 0) {
        $w = $hours / 168;
        return $w . ' week' . ($w !== 1 ? 's' : '') . ' before';
    }
    if ($hours >= 24 && $hours % 24 === 0) {
        $d = $hours / 24;
        return $d . ' day' . ($d !== 1 ? 's' : '') . ' before';
    }
    return $hours . ' hour' . ($hours !== 1 ? 's' : '') . ' before';
}

// Load currently associated form IDs for this appointment type
$selected_form_ids_current = [];
if ($is_edit) {
    $stmt = $conn->prepare("SELECT form_template_id FROM appointment_type_forms WHERE appointment_type_id = ?");
    $stmt->execute([$id]);
    $selected_form_ids_current = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$page_title = $is_edit ? "Edit Appointment Type" : "Add Appointment Type";
include __DIR__ . '/../backend/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="appointment_types_list.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left"></i> Back to Appointment Types
        </a>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><?= $is_edit ? 'Edit' : 'Add' ?> Appointment Type</h5>
        </div>
        <div class="card-body">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($is_edit && $type_unique_link !== ''): ?>
                <div class="alert alert-info">
                    <h6 class="alert-heading"><i class="fas fa-link"></i> Unique Booking Link</h6>
                    <p class="mb-2">Share this link with clients to book this appointment type directly:</p>
                    <div class="input-group">
                        <input type="text" class="form-control" id="booking-link" 
                               value="<?= htmlspecialchars($base_url . '/backend/public/book.php?link=' . $type_unique_link) ?>" 
                               readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyBookingLink(event)">
                            <i class="fas fa-copy"></i> Copy
                        </button>
                    </div>
                    <small class="text-muted">This link was automatically generated and is unique to this appointment type.</small>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <h6 class="border-bottom pb-2 mb-3">Basic Information</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?= htmlspecialchars($type_name) ?>" required>
                        <div class="form-text">The name of this appointment type</div>
                    </div>
                    <div class="col-md-6">
                        <label for="duration_minutes" class="form-label">Duration (minutes) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="duration_minutes" name="duration_minutes" 
                               value="<?= $type_duration ?>" min="5" step="5" required>
                        <div class="form-text">Length of the appointment</div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($type_description) ?></textarea>
                        <div class="form-text">Brief description of this appointment type</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Booking Rules</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="buffer_before_minutes" class="form-label">Buffer Before (minutes)</label>
                        <input type="number" class="form-control" id="buffer_before_minutes" name="buffer_before_minutes" 
                               value="<?= $type_buffer_before ?>" min="0" step="5">
                        <div class="form-text">Time blocked before appointment starts</div>
                    </div>
                    <div class="col-md-6">
                        <label for="buffer_after_minutes" class="form-label">Buffer After (minutes)</label>
                        <input type="number" class="form-control" id="buffer_after_minutes" name="buffer_after_minutes" 
                               value="<?= $type_buffer_after ?>" min="0" step="5">
                        <div class="form-text">Time blocked after appointment ends</div>
                    </div>
                </div>
                
                <!-- Phase 2: Travel Time Buffer -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="use_travel_time_buffer" name="use_travel_time_buffer" 
                                   value="1" <?= $type_use_travel_time_buffer ? 'checked' : '' ?>
                                   onchange="toggleTravelTime()">
                            <label class="form-check-label" for="use_travel_time_buffer">
                                Use Travel Time Buffer (Phase 2 Feature)
                            </label>
                            <div class="form-text">Automatically calculate buffers based on travel time instead of fixed values</div>
                        </div>
                    </div>
                </div>
                
                <div class="row mb-3" id="travel_time_section" style="display: none;">
                    <div class="col-md-6">
                        <label for="travel_time_minutes" class="form-label">Travel Time (minutes)</label>
                        <input type="number" class="form-control" id="travel_time_minutes" name="travel_time_minutes" 
                               value="<?= $type_travel_time_minutes ?>" min="0" step="5">
                        <div class="form-text">Time needed for travel to/from appointment location</div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="advance_booking_min_days" class="form-label">Minimum Advance Booking (days)</label>
                        <input type="number" class="form-control" id="advance_booking_min_days" name="advance_booking_min_days" 
                               value="<?= $type_advance_min_days ?>" min="0">
                        <div class="form-text">Clients must book at least this many days in advance</div>
                    </div>
                    <div class="col-md-6">
                        <label for="advance_booking_max_days" class="form-label">Maximum Advance Booking (days)</label>
                        <input type="number" class="form-control" id="advance_booking_max_days" name="advance_booking_max_days" 
                               value="<?= $type_advance_max_days ?>" min="1">
                        <div class="form-text">Clients can book up to this many days in advance</div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="cancellation_notice_hours" class="form-label">Minimum Notice for Changes (hours)</label>
                        <input type="number" class="form-control" id="cancellation_notice_hours" name="cancellation_notice_hours"
                               value="<?= $type_cancellation_notice_hours ?>" min="0">
                        <div class="form-text">Clients can only cancel or reschedule if the appointment is at least this many hours away. Set to 0 to allow changes at any time.</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Availability Configuration</h6>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <label class="form-label">Schedule Type <span class="text-danger">*</span></label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="schedule_type" 
                                           id="schedule_type_recurring" value="recurring"
                                           <?= $type_schedule === 'recurring' ? 'checked' : '' ?>
                                           onchange="toggleScheduleType()">
                                    <label class="form-check-label" for="schedule_type_recurring">
                                        <strong>Recurring Schedule</strong>
                                        <div class="form-text">Available on specific days of the week (e.g., every Monday and Wednesday)</div>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="schedule_type" 
                                           id="schedule_type_specific" value="specific_date"
                                           <?= $type_schedule === 'specific_date' ? 'checked' : '' ?>
                                           onchange="toggleScheduleType()">
                                    <label class="form-check-label" for="schedule_type_specific">
                                        <strong>Specific Date</strong>
                                        <div class="form-text">Available only on one specific calendar date (e.g., October 31st, 2026)</div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="recurring_schedule_section">
                    <div class="col-12">
                        <label class="form-label">Available Days</label>
                        <div class="row">
                            <?php 
                            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                            $available_days = $type_available_days;
                            $per_day_data = $type_per_day_data;
                            foreach ($days as $index => $day): 
                            ?>
                            <div class="col-md-3 col-6">
                                <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="available_days[]" 
                                               id="day_<?= $index ?>" value="<?= $index ?>"
                                           <?= in_array($index, $available_days, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="day_<?= $index ?>">
                                        <?= $day ?>
                                    </label>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Select which days of the week this appointment type is available</div>
                    </div>

                    <div class="col-12 mt-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="use_per_day_schedule"
                                   name="use_per_day_schedule" <?= $has_per_day ? 'checked' : '' ?>
                                   onchange="togglePerDaySchedule()">
                            <label class="form-check-label" for="use_per_day_schedule">
                                <strong>Set different times per day</strong>
                            </label>
                            <div class="form-text">Configure a different start/end time for each selected day (e.g., 8am–12pm on Monday, 4pm–7pm on Friday)</div>
                        </div>
                    </div>

                    <div id="per_day_schedule_section" class="col-12 mt-2" style="display: <?= $has_per_day ? 'block' : 'none' ?>;">
                        <table class="table table-bordered table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:35%">Day</th>
                                    <th>Start Time</th>
                                    <th>End Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days as $index => $day): ?>
                                <?php
                                $per_day_row = isset($per_day_data[$index]) && is_array($per_day_data[$index]) ? $per_day_data[$index] : [];
                                ?>
                                <tr id="per_day_row_<?= $index ?>" style="display: <?= in_array($index, $available_days, true) ? 'table-row' : 'none' ?>;">
                                    <td><strong><?= $day ?></strong></td>
                                    <td>
                                        <input type="time" class="form-control form-control-sm"
                                               name="day_start_time[<?= $index ?>]"
                                               value="<?= htmlspecialchars(array_string_value($per_day_row, 'start', $type_available_start_time)) ?>">
                                    </td>
                                    <td>
                                        <input type="time" class="form-control form-control-sm"
                                               name="day_end_time[<?= $index ?>]"
                                               value="<?= htmlspecialchars(array_string_value($per_day_row, 'end', $type_available_end_time)) ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="form-text"><i class="fas fa-info-circle"></i> Per-day times define the available window for each day, replacing the global time settings.</div>
                    </div>
                </div>

                <div id="specific_date_section" style="display: none;">
                    <div class="col-12 mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="form-label mb-0">
                                <i class="fas fa-calendar-day me-1"></i>
                                Specific Dates <span class="text-danger">*</span>
                            </label>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addSpecificDateEntry()">
                                <i class="fas fa-plus me-1"></i>Add Date
                            </button>
                        </div>
                        <div class="form-text mb-3">
                            Add one or more specific dates. For each date you can leave timeslots empty to use the global
                            time range below, or add individual timeslots (specific times or time ranges).
                        </div>
                        <div id="specific_dates_container">
                            <!-- Date entries are rendered by JS on page load -->
                        </div>
                        <div id="no_dates_hint" class="alert alert-warning d-none">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            Please add at least one specific date.
                        </div>
                        <!-- Hidden field carrying the serialised JSON to the server -->
                        <input type="hidden" name="specific_dates" id="specific_dates_json"
                               value="<?= $existing_specific_dates_json ?>">
                    </div>
                </div>

                <div class="row g-3 mb-4 mt-3">
                    <?php
                    $display_schedule_type = $type_schedule;
                    $show_global_times = ($display_schedule_type !== 'specific_date') && !$has_per_day;
                    ?>
                    <div class="col-12" id="global_availability_times"
                         style="display: <?= $show_global_times ? 'block' : 'none' ?>;">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="available_start_time" class="form-label">Available Start Time</label>
                                <input type="time" class="form-control" id="available_start_time" name="available_start_time"
                                       value="<?= $type_available_start_time ?>">
                                <div class="form-text">Earliest time for appointments</div>
                            </div>
                            <div class="col-md-4">
                                <label for="available_end_time" class="form-label">Available End Time</label>
                                <input type="time" class="form-control" id="available_end_time" name="available_end_time"
                                       value="<?= $type_available_end_time ?>">
                                <div class="form-text">Latest time for appointments</div>
                            </div>
                            <div class="col-md-4">
                                <label for="time_slot_interval" class="form-label">Time Slot Interval (minutes)</label>
                                <select class="form-select" id="time_slot_interval" name="time_slot_interval">
                                    <option value="15" <?= $type_time_slot_interval === 15 ? 'selected' : '' ?>>15 minutes</option>
                                    <option value="30" <?= $type_time_slot_interval === 30 ? 'selected' : '' ?>>30 minutes</option>
                                    <option value="60" <?= $type_time_slot_interval === 60 ? 'selected' : '' ?>>60 minutes</option>
                                </select>
                                <div class="form-text">Interval between available time slots</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Preview:</strong> <span id="preview_text">
                            <?php
                            $schedule_type = $type_schedule;
                            if ($schedule_type === 'specific_date' && !empty($existing_specific_dates)) {
                                $date_labels = array_map(static fn(array $e): string => date('F j, Y', strtotime(array_string_value($e, 'date'))), $existing_specific_dates);
                                echo 'This appointment will be available on <strong>' . implode(', ', $date_labels) . '</strong>';
                            } else {
                                echo 'Based on your settings, appointment slots will be available ';
                            }
                            ?>
                            </span>
                            <span id="preview_recurring">
                            <span id="preview_days">
                                <?php
                                if ($schedule_type === 'recurring') {
                                    $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                    $available_days = $type_available_days;
                                    $selected_day_names = array_map(static fn(int $d): string => $day_names[$d] ?? '', $available_days);
                                    echo implode(', ', $selected_day_names);
                                }
                                ?>
                            </span><span id="preview_global_time"> 
                            from <strong id="preview_start">
                                <?php
                                $start = $type_available_start_time;
                                list($h, $m) = explode(':', $start);
                                $hi = (int)$h;
                                echo ($hi % 12 ?: 12) . ':' . $m . ' ' . ($hi >= 12 ? 'PM' : 'AM');
                                ?>
                            </strong> to <strong id="preview_end">
                                <?php
                                $end = $type_available_end_time;
                                list($h, $m) = explode(':', $end);
                                $hi = (int)$h;
                                echo ($hi % 12 ?: 12) . ':' . $m . ' ' . ($hi >= 12 ? 'PM' : 'AM');
                                ?>
                            </strong></span> 
                            in <strong id="preview_interval"><?= $type_time_slot_interval ?></strong>-minute intervals.
                            </span>
                        </div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Requirements</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Required Forms</label>
                        <?php if (empty($all_forms)): ?>
                            <p class="text-muted small">No active form templates available. <a href="form_templates_edit.php">Create a form</a> first.</p>
                        <?php else: ?>
                            <div class="border rounded p-2" style="max-height: 160px; overflow-y: auto;">
                                <?php foreach ($all_forms as $form): ?>
                                    <?php
                                    $form_id = array_int_value($form, 'id');
                                    $form_name = array_string_value($form, 'name');
                                    ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="form_ids[]"
                                               id="form_<?= $form_id ?>" value="<?= $form_id ?>"
                                               <?= in_array($form_id, $selected_form_ids_current, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="form_<?= $form_id ?>">
                                            <?= htmlspecialchars($form_name) ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Select forms clients must complete before booking. If none selected, no forms are required.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Required Contract</label>
                        <?php if (empty($all_contract_templates)): ?>
                            <p class="text-muted small">No active contract templates available. <a href="contract_templates_edit.php">Create a template</a> first.</p>
                            <input type="hidden" name="contract_template_id" value="">
                        <?php else: ?>
                            <select class="form-select" id="contract_template_id" name="contract_template_id">
                                <option value="">— None (no contract required) —</option>
                                <?php foreach ($all_contract_templates as $tmpl): ?>
                                    <?php
                                    $tmpl_id = array_int_value($tmpl, 'id');
                                    $tmpl_name = array_string_value($tmpl, 'name');
                                    ?>
                                    <option value="<?= $tmpl_id ?>"
                                        <?= $type_contract_template_id === $tmpl_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tmpl_name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Select a contract clients must review and accept before booking. Leave blank for no contract requirement.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Invoice Behavior</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_invoice" name="auto_invoice"
                                   <?= $type_auto_invoice ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_invoice">
                                Auto-Invoice
                            </label>
                            <div class="form-text">Automatically create invoice for this appointment type</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="invoice_due_days" class="form-label">Invoice Due (days after appointment)</label>
                        <input type="number" class="form-control" id="invoice_due_days" name="invoice_due_days" 
                               value="<?= $type_invoice_due_days ?>" min="0">
                        <div class="form-text">Invoice due date offset from appointment</div>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="default_amount" class="form-label">Default Invoice Amount ($)</label>
                        <input type="number" class="form-control" id="default_amount" name="default_amount"
                               value="<?= htmlspecialchars((string) $type_default_amount) ?>" min="0" step="0.01">
                        <div class="form-text">Dollar amount used when auto-invoicing this appointment type</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Credits System</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="consumes_credits" name="consumes_credits"
                                   <?= $type_consumes_credits ? 'checked' : '' ?>>
                            <label class="form-check-label" for="consumes_credits">
                                Consumes Credits
                            </label>
                            <div class="form-text">This appointment type requires its own credits for booking</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="credit_count" class="form-label">Credit Count</label>
                        <input type="number" class="form-control" id="credit_count" name="credit_count" 
                               value="<?= $type_credit_count ?>" min="1">
                        <div class="form-text">Number of credits consumed per appointment</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Group Classes</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_group_class" name="is_group_class"
                                   <?= $type_is_group_class ? 'checked' : '' ?>
                                   onchange="toggleGroupClassFields()">
                            <label class="form-check-label" for="is_group_class">
                                Is Group Class
                            </label>
                            <div class="form-text">This appointment type supports multiple participants</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="max_participants" class="form-label">Maximum Participants</label>
                        <input type="number" class="form-control" id="max_participants" name="max_participants" 
                               value="<?= $type_max_participants ?>" min="1">
                        <div class="form-text">Maximum number of clients for group classes</div>
                    </div>
                </div>

                <div id="group_class_fields" style="display: <?= $type_is_group_class ? 'block' : 'none' ?>;">
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="group_class_location" class="form-label">Class Location <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="group_class_location" name="group_class_location"
                                   value="<?= htmlspecialchars($type_group_class_location) ?>"
                                   placeholder="e.g., Brooks Training Center - 123 Main St, City, State ZIP">
                            <div class="form-text">Address or venue where the group class will be held. This location will be shown to clients when booking.</div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Group Class Setup:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Set the maximum number of participants above</li>
                            <li>Clients will see the class location when booking</li>
                            <li>Use the availability configuration above to define class schedule</li>
                        </ul>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Mini Sessions (Venue-Based Events)</h6>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_mini_session" name="is_mini_session"
                                   <?= $type_is_mini_session ? 'checked' : '' ?>
                                   onchange="toggleMiniSessionFields()">
                            <label class="form-check-label" for="is_mini_session">
                                <strong>This is a Mini Sessions Event</strong>
                            </label>
                            <div class="form-text">Enable for venue-based events where clients book individual time blocks at a fixed location</div>
                        </div>
                    </div>
                </div>
                
                <div id="mini_session_fields" style="display: <?= $type_is_mini_session ? 'block' : 'none' ?>;">
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="mini_session_location" class="form-label">Event Location <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="mini_session_location" name="mini_session_location" 
                                   value="<?= htmlspecialchars($type_mini_session_location) ?>"
                                   placeholder="e.g., Greenwood Dog Park, 123 Main St, City, State ZIP">
                            <div class="form-text">Fixed venue where all mini sessions will be held. This location will be shown to clients when booking.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="mini_session_topic" class="form-label">Event Topic/Focus</label>
                            <input type="text" class="form-control" id="mini_session_topic" name="mini_session_topic" 
                                   value="<?= htmlspecialchars($type_mini_session_topic) ?>"
                                   placeholder="e.g., Recall Training, Agility Introduction, Leash Manners">
                            <div class="form-text">Optional: Specific topic or focus for this Mini Sessions event</div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Mini Sessions Setup:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Configure your event date and time blocks using the schedule settings above</li>
                            <li>Use "Specific Date" schedule type for single-day events</li>
                            <li>Set the duration for each time block (e.g., 30 or 45 minutes)</li>
                            <li>Each block can be booked by one client at the specified location</li>
                            <li>Clients will see the location and topic when booking their slot</li>
                        </ul>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Field Rentals</h6>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_field_rental" name="is_field_rental"
                                   <?= $type_is_field_rental ? 'checked' : '' ?>
                                   onchange="toggleFieldRentalFields()">
                            <label class="form-check-label" for="is_field_rental">
                                <strong>This is a Field Rental</strong>
                            </label>
                            <div class="form-text">Enable for appointments where clients rent a training field or outdoor space</div>
                        </div>
                    </div>
                </div>
                
                <div id="field_rental_fields" style="display: <?= $type_is_field_rental ? 'block' : 'none' ?>;">
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="field_rental_location" class="form-label">Field Location <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="field_rental_location" name="field_rental_location" 
                                   value="<?= htmlspecialchars($type_field_rental_location) ?>"
                                   placeholder="e.g., Brooks Training Field - 456 Park Ave, City, State ZIP">
                            <div class="form-text">Address or description of the rental field.</div>
                        </div>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Field Rental Setup:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Set the duration for each rental block (e.g., 60 minutes)</li>
                            <li>Use the availability configuration above to define open rental slots</li>
                            <li>Field rental credits in bundled packages apply exclusively to this appointment type</li>
                        </ul>
                    </div>
                </div>

                <?php
                $current_location_types = array_values(array_filter(array_map('scalar_string', $type_location_types)));
                $is_fixed_type = $type_is_mini_session || $type_is_field_rental || $type_is_group_class;
                ?>

                <div id="locationTypesSection" style="display: <?= $is_fixed_type ? 'none' : 'block' ?>;">
                    <h6 class="border-bottom pb-2 mb-3">Appointment Location Options</h6>
                    <p class="text-muted small mb-3">
                        Select which location options are available when booking this appointment type.
                        If only one option is selected, it will be displayed prominently without a dropdown.
                        If none are selected, all options will be available.
                    </p>
                    <div class="row g-3 mb-4">
                        <?php
                        $loc_type_defs = [
                            'client_address'  => ['label' => "Client's Registered Address",    'icon' => 'fa-home',           'desc' => 'Appointment takes place at the client\'s address on file'],
                            'custom_address'  => ['label' => 'Custom Address',                 'icon' => 'fa-map-marker-alt', 'desc' => 'A user-specified address entered at booking time'],
                            'phone_inbound'   => ['label' => 'Phone Call (Inbound)',            'icon' => 'fa-phone',          'desc' => 'Client calls the provider\'s number'],
                            'phone_outbound'  => ['label' => 'Phone Call (Outbound)',           'icon' => 'fa-phone',          'desc' => 'Provider calls the client\'s number'],
                            'webcall'         => ['label' => 'Webcall (Zoom, Google Meet…)',   'icon' => 'fa-video',          'desc' => 'Video call via a URL provided at booking time'],
                        ];
                        foreach ($loc_type_defs as $lt_key => $lt_def):
                            $checked = in_array($lt_key, $current_location_types, true) ? 'checked' : '';
                        ?>
                        <div class="col-md-6">
                            <div class="form-check border rounded p-3">
                                <input class="form-check-input" type="checkbox" name="location_types[]"
                                       id="lt_<?= $lt_key ?>" value="<?= $lt_key ?>" <?= $checked ?>>
                                <label class="form-check-label w-100" for="lt_<?= $lt_key ?>">
                                    <i class="fas <?= $lt_def['icon'] ?> me-2 text-primary" aria-hidden="true"></i>
                                    <strong><?= htmlspecialchars($lt_def['label']) ?></strong>
                                    <div class="form-text mb-0"><?= htmlspecialchars($lt_def['desc']) ?></div>
                                </label>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Email Template Overrides</h6>
                <p class="text-muted small mb-3">
                    Optionally assign specific email templates for confirmations and reminders sent for this appointment type.
                    If left blank, the system-wide default template (configured in 
                    <a href="email_template_defaults.php">Email Template Defaults</a>) will be used.
                </p>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="confirmation_template_id" class="form-label">Confirmation Email Template</label>
                        <select class="form-select" id="confirmation_template_id" name="confirmation_template_id">
                            <option value="">— Use system default —</option>
                            <?php foreach ($confirmation_templates as $tmpl): ?>
                                <?php
                                $tmpl_id = array_int_value($tmpl, 'id');
                                $tmpl_name = array_string_value($tmpl, 'name');
                                ?>
                                <option value="<?= $tmpl_id ?>"
                                    <?= $type_confirmation_template_id === $tmpl_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tmpl_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Override the booking confirmation email for this appointment type.</div>
                    </div>
                    <div class="col-md-6">
                        <label for="reminder_template_id" class="form-label">Reminder Email Template</label>
                        <select class="form-select" id="reminder_template_id" name="reminder_template_id">
                            <option value="">— Use system default —</option>
                            <?php foreach ($reminder_templates as $tmpl): ?>
                                <?php
                                $tmpl_id = array_int_value($tmpl, 'id');
                                $tmpl_name = array_string_value($tmpl, 'name');
                                ?>
                                <option value="<?= $tmpl_id ?>"
                                    <?= $type_reminder_template_id === $tmpl_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tmpl_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Default reminder template for this appointment type (overridden per rule below).</div>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="cancellation_template_id" class="form-label">Cancellation Email Template</label>
                        <select class="form-select" id="cancellation_template_id" name="cancellation_template_id">
                            <option value="">— Use system default —</option>
                            <?php foreach ($cancellation_templates as $tmpl): ?>
                                <?php
                                $tmpl_id = array_int_value($tmpl, 'id');
                                $tmpl_name = array_string_value($tmpl, 'name');
                                ?>
                                <option value="<?= $tmpl_id ?>"
                                    <?= $type_cancellation_template_id === $tmpl_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tmpl_name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Override the cancellation notification email for this appointment type.</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Status</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?= $type_is_active ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active
                            </label>
                            <div class="form-text">Only active types are available for booking</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="portal_available" name="portal_available"
                                   <?= $type_portal_available ? 'checked' : '' ?>>
                            <label class="form-check-label" for="portal_available">
                                Available in Client Portal
                            </label>
                            <div class="form-text">Allow clients to book this type directly from the client portal</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> <?= $is_edit ? 'Update' : 'Create' ?> Appointment Type
                    </button>
                    <a href="appointment_types_list.php" class="btn btn-secondary">Cancel</a>
                </div>
            </form>

            <!-- Per-appointment-type reminder rules (kept outside the main form to avoid nested-form issues) -->
            <div id="reminder-rules" class="mt-4">
                <h6 class="border-bottom pb-2 mb-3">
                    <i class="fas fa-bell me-1"></i>Reminder Rules
                </h6>
                <?php if (!$is_edit): ?>
                    <div class="alert alert-info small">
                        <i class="fas fa-circle-info me-1"></i>
                        Save this appointment type first, then return to configure per-type reminder rules.
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-3">
                        Configure separate reminder emails at different times before the appointment.
                        Each rule can use a different template (e.g. a 2-day teaser and a day-before checklist).
                        If no rules are set here, the <a href="booking_reminder_rules.php">global reminder rules</a> apply.
                    </p>

                    <!-- Existing rules table -->
                    <?php if (!empty($type_reminder_rules)): ?>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Timing</th>
                                        <th>Template</th>
                                        <th>Status</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($type_reminder_rules as $rule): ?>
                                        <?php
                                        $rule_name = array_string_value($rule, 'name');
                                        $rule_hours_before = array_int_value($rule, 'hours_before');
                                        $rule_template_name = array_string_value($rule, 'template_name');
                                        $rule_is_active = array_int_value($rule, 'is_active') === 1;
                                        $rule_id = array_int_value($rule, 'id');
                                        ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($rule_name) ?></strong></td>
                                            <td>
                                                <span class="badge bg-secondary"><?= $rule_hours_before ?>h</span>
                                                <small class="text-muted ms-1"><?= formatHoursBefore($rule_hours_before) ?></small>
                                            </td>
                                            <td>
                                                <?php if ($rule_template_name !== ''): ?>
                                                    <small><?= htmlspecialchars($rule_template_name) ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted fst-italic">system default</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($rule_is_active): ?>
                                                    <span class="badge bg-success">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="sub_action" value="toggle_rule">
                                                        <input type="hidden" name="rule_id" value="<?= $rule_id ?>">
                                                        <button type="submit" class="btn btn-outline-secondary btn-sm"
                                                                title="<?= $rule_is_active ? 'Deactivate' : 'Activate' ?>">
                                                            <i class="fas fa-<?= $rule_is_active ? 'pause' : 'play' ?>"></i>
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="d-inline"
                                                          onsubmit="return confirm('Remove this reminder rule?')">
                                                        <input type="hidden" name="sub_action" value="delete_rule">
                                                        <input type="hidden" name="rule_id" value="<?= $rule_id ?>">
                                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Remove">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border small mb-3">
                            <i class="fas fa-circle-info me-1 text-muted"></i>
                            No per-type rules yet — global reminder rules will be used.
                        </div>
                    <?php endif; ?>

                    <!-- Add new rule form -->
                    <details class="mb-4">
                        <summary class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-plus me-1"></i>Add Reminder Rule
                        </summary>
                        <div class="card mt-2">
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="sub_action" value="add_rule">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label form-label-sm">Rule Name <span class="text-danger">*</span></label>
                                            <input type="text" name="rule_name" class="form-control form-control-sm"
                                                   placeholder="e.g. Day Before" required>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label form-label-sm">Hours Before <span class="text-danger">*</span></label>
                                            <input type="number" name="rule_hours_before" class="form-control form-control-sm"
                                                   min="1" step="1" value="24" required>
                                            <div class="form-text" style="font-size:0.7rem">24=1d • 48=2d • 168=1w</div>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label form-label-sm">Email Template</label>
                                            <select name="rule_template_id" class="form-select form-select-sm">
                                                <option value="">— Use system default —</option>
                                                <?php foreach ($reminder_templates as $tmpl): ?>
                                                    <?php
                                                    $tmpl_id = array_int_value($tmpl, 'id');
                                                    $tmpl_name = array_string_value($tmpl, 'name');
                                                    ?>
                                                    <option value="<?= $tmpl_id ?>"><?= htmlspecialchars($tmpl_name) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 d-flex align-items-end">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="rule_is_active" id="new_rule_active" checked>
                                                <label class="form-check-label form-label-sm" for="new_rule_active">Active</label>
                                            </div>
                                        </div>
                                        <div class="col-md-1 d-flex align-items-end">
                                            <button type="submit" class="btn btn-primary btn-sm w-100">Add</button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// ─── Specific Dates multi-date builder ──────────────────────────────────────

/** In-memory representation of the configured specific dates. */
let specificDatesData = [];

try {
    const raw = document.getElementById('specific_dates_json').value;
    if (raw) specificDatesData = JSON.parse(raw) || [];
} catch(e) { specificDatesData = []; }

/** Render a single timeslot row inside a date card. */
function buildTimeslotRow(slotIdx, slot) {
    const type = slot.type || 'point';
    const pointVal = (type === 'point') ? (slot.time || '') : '';
    const rangeStart = (type === 'range') ? (slot.start || '') : '';
    const rangeEnd   = (type === 'range') ? (slot.end   || '') : '';

    return `
<div class="timeslot-entry d-flex flex-wrap align-items-center gap-2 mb-2 p-2 border rounded bg-light">
    <select class="form-select form-select-sm timeslot-type" style="width:auto;" onchange="onTimeslotTypeChange(this)" data-slot-idx="${slotIdx}">
        <option value="point"${type==='point'?' selected':''}>Specific time</option>
        <option value="range"${type==='range'?' selected':''}>Time range</option>
    </select>
    <div class="timeslot-point-inputs d-flex align-items-center gap-1"${type==='range'?' style="display:none!important"':''}>
        <span class="text-muted small">at</span>
        <input type="time" class="form-control form-control-sm timeslot-point-time" style="width:auto;" value="${pointVal}" onchange="serializeSpecificDates()">
    </div>
    <div class="timeslot-range-inputs d-flex align-items-center gap-1"${type==='point'?' style="display:none!important"':''}>
        <span class="text-muted small">from</span>
        <input type="time" class="form-control form-control-sm timeslot-range-start" style="width:auto;" value="${rangeStart}" onchange="serializeSpecificDates()">
        <span class="text-muted small">to</span>
        <input type="time" class="form-control form-control-sm timeslot-range-end" style="width:auto;" value="${rangeEnd}" onchange="serializeSpecificDates()">
    </div>
    <button type="button" class="btn btn-outline-danger btn-sm ms-auto" onclick="removeTimeslotRow(this)" title="Remove timeslot">
        <i class="fas fa-times"></i>
    </button>
</div>`;
}

/** Render one date card with its timeslot rows. */
function buildDateCard(dateIdx, entry) {
    const dateVal = entry.date || '';
    const timeslots = entry.timeslots || [];
    let slotsHtml = timeslots.map((s, si) => buildTimeslotRow(si, s)).join('');

    return `
<div class="specific-date-entry card mb-3 border-secondary" data-date-idx="${dateIdx}">
    <div class="card-header d-flex align-items-center gap-2 py-2">
        <i class="fas fa-calendar-day text-secondary"></i>
        <input type="date" class="form-control form-control-sm sd-date-input" style="width:auto;" value="${dateVal}" onchange="serializeSpecificDates()">
        <button type="button" class="btn btn-outline-danger btn-sm ms-auto" onclick="removeDateCard(this)" title="Remove this date">
            <i class="fas fa-trash me-1"></i>Remove Date
        </button>
    </div>
    <div class="card-body pb-2">
        <p class="form-text mb-2">
            <i class="fas fa-info-circle me-1"></i>
            Leave timeslots empty to automatically generate slots using this appointment type's Duration (slot length) and Buffer Time (gap between slots), or add custom timeslots below.
        </p>
        <div class="timeslots-list">
            ${slotsHtml}
        </div>
        <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addTimeslotRow(this)">
            <i class="fas fa-plus me-1"></i>Add Timeslot
        </button>
    </div>
</div>`;
}

/** Re-render all date cards from specificDatesData. */
function renderSpecificDates() {
    const container = document.getElementById('specific_dates_container');
    container.innerHTML = specificDatesData.map((e, i) => buildDateCard(i, e)).join('');
    const hint = document.getElementById('no_dates_hint');
    if (hint) hint.classList.toggle('d-none', specificDatesData.length > 0);
    serializeSpecificDates();
    updateAvailabilityPreview();
}

/** Add a new empty date entry. */
function addSpecificDateEntry() {
    specificDatesData.push({ date: '', timeslots: [] });
    renderSpecificDates();
}

/** Remove the date card that contains the clicked button. */
function removeDateCard(btn) {
    const card = btn.closest('.specific-date-entry');
    const idx = parseInt(card.dataset.dateIdx);
    specificDatesData.splice(idx, 1);
    renderSpecificDates();
}

/** Add a new timeslot row inside a date card. */
function addTimeslotRow(btn) {
    const card = btn.closest('.specific-date-entry');
    const dateIdx = parseInt(card.dataset.dateIdx);
    specificDatesData[dateIdx].timeslots.push({ type: 'point', time: '' });
    renderSpecificDates();
    // Scroll the newly added row into view
    const timeslotRows = card.querySelectorAll('.timeslot-entry');
    if (timeslotRows.length) timeslotRows[timeslotRows.length - 1].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/** Remove a timeslot row. */
function removeTimeslotRow(btn) {
    const entry  = btn.closest('.timeslot-entry');
    const card   = btn.closest('.specific-date-entry');
    const dateIdx = parseInt(card.dataset.dateIdx);
    const list    = card.querySelector('.timeslots-list');
    const rows    = Array.from(list.querySelectorAll('.timeslot-entry'));
    const slotIdx = rows.indexOf(entry);
    if (slotIdx >= 0) specificDatesData[dateIdx].timeslots.splice(slotIdx, 1);
    renderSpecificDates();
}

/** Toggle point/range inputs when the type <select> changes. */
function onTimeslotTypeChange(sel) {
    const entry      = sel.closest('.timeslot-entry');
    const pointDiv   = entry.querySelector('.timeslot-point-inputs');
    const rangeDiv   = entry.querySelector('.timeslot-range-inputs');
    if (sel.value === 'range') {
        pointDiv.style.setProperty('display', 'none', 'important');
        rangeDiv.style.removeProperty('display');
    } else {
        rangeDiv.style.setProperty('display', 'none', 'important');
        pointDiv.style.removeProperty('display');
    }
    serializeSpecificDates();
}

/** Read the current DOM state into specificDatesData then write JSON to the hidden field. */
function serializeSpecificDates() {
    const container = document.getElementById('specific_dates_container');
    const cards = container.querySelectorAll('.specific-date-entry');
    specificDatesData = Array.from(cards).map(function(card) {
        const dateInput = card.querySelector('.sd-date-input');
        const dateVal = dateInput ? dateInput.value : '';
        const slotRows = card.querySelectorAll('.timeslot-entry');
        const timeslots = Array.from(slotRows).map(function(row) {
            const typeSel = row.querySelector('.timeslot-type');
            const slotType = typeSel ? typeSel.value : 'point';
            if (slotType === 'range') {
                const start = (row.querySelector('.timeslot-range-start') || {}).value || '';
                const end   = (row.querySelector('.timeslot-range-end')   || {}).value || '';
                return { type: 'range', start, end };
            } else {
                const time = (row.querySelector('.timeslot-point-time') || {}).value || '';
                return { type: 'point', time };
            }
        });
        return { date: dateVal, timeslots };
    });
    document.getElementById('specific_dates_json').value = JSON.stringify(specificDatesData);
    updateAvailabilityPreview();
}

// ─── Schedule type toggle ────────────────────────────────────────────────────

// Show/hide global availability time fields based on schedule type and per-day setting
function updateGlobalTimesVisibility() {
    const isSpecificDate = document.getElementById('schedule_type_specific').checked;
    const usePerDay = document.getElementById('use_per_day_schedule').checked;
    const section = document.getElementById('global_availability_times');
    if (section) {
        section.style.display = (!isSpecificDate && !usePerDay) ? 'block' : 'none';
    }
}

// Toggle between schedule types
function toggleScheduleType() {
    const recurringRadio = document.getElementById('schedule_type_recurring');
    const recurringSection = document.getElementById('recurring_schedule_section');
    const specificDateSection = document.getElementById('specific_date_section');
    
    if (recurringRadio.checked) {
        recurringSection.style.display = 'block';
        specificDateSection.style.display = 'none';
    } else {
        recurringSection.style.display = 'none';
        specificDateSection.style.display = 'block';
        // Render date cards when section becomes visible
        renderSpecificDates();
    }
    
    updateGlobalTimesVisibility();
    updateAvailabilityPreview();
}

// Phase 2: Travel Time Buffer Toggle
function toggleTravelTime() {
    const checkbox = document.getElementById('use_travel_time_buffer');
    const section = document.getElementById('travel_time_section');
    section.style.display = checkbox.checked ? 'block' : 'none';
}

// Toggle Mini Session fields
function toggleMiniSessionFields() {
    const checkbox = document.getElementById('is_mini_session');
    const fieldsSection = document.getElementById('mini_session_fields');
    const locationInput = document.getElementById('mini_session_location');
    
    if (checkbox.checked) {
        fieldsSection.style.display = 'block';
        locationInput.setAttribute('required', 'required');
    } else {
        fieldsSection.style.display = 'none';
        locationInput.removeAttribute('required');
    }
    updateLocationTypesVisibility();
}

// Toggle Field Rental fields
function toggleFieldRentalFields() {
    const checkbox = document.getElementById('is_field_rental');
    const fieldsSection = document.getElementById('field_rental_fields');
    const locationInput = document.getElementById('field_rental_location');
    
    if (checkbox.checked) {
        fieldsSection.style.display = 'block';
        locationInput.setAttribute('required', 'required');
    } else {
        fieldsSection.style.display = 'none';
        locationInput.removeAttribute('required');
    }
    updateLocationTypesVisibility();
}

// Toggle Group Class fields
function toggleGroupClassFields() {
    const checkbox = document.getElementById('is_group_class');
    const fieldsSection = document.getElementById('group_class_fields');
    const locationInput = document.getElementById('group_class_location');

    if (checkbox.checked) {
        fieldsSection.style.display = 'block';
        locationInput.setAttribute('required', 'required');
    } else {
        fieldsSection.style.display = 'none';
        locationInput.removeAttribute('required');
    }
    updateLocationTypesVisibility();
}

// Show/hide the location options section based on whether this is a fixed-location type
function updateLocationTypesVisibility() {
    const isMini = document.getElementById('is_mini_session').checked;
    const isField = document.getElementById('is_field_rental').checked;
    const isGroup = document.getElementById('is_group_class').checked;
    const section = document.getElementById('locationTypesSection');
    if (section) {
        section.style.display = (isMini || isField || isGroup) ? 'none' : 'block';
    }
}

// Toggle Per-Day Schedule fields
function togglePerDaySchedule() {
    const checkbox = document.getElementById('use_per_day_schedule');
    const section = document.getElementById('per_day_schedule_section');
    section.style.display = checkbox.checked ? 'block' : 'none';
    updateGlobalTimesVisibility();
    updateAvailabilityPreview();
}

// Copy booking link to clipboard
function copyBookingLink(event) {
    const linkInput = document.getElementById('booking-link');
    
    navigator.clipboard.writeText(linkInput.value).then(function() {
        // Show success feedback
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    }).catch(function(err) {
        // Fallback: select the text so user can copy manually
        linkInput.select();
        linkInput.setSelectionRange(0, 99999);
        alert('Could not copy automatically. The link is now selected - please press Ctrl+C (or Cmd+C) to copy.');
    });
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleTravelTime();
    toggleScheduleType();
    toggleMiniSessionFields();
    // If specific date is already selected, render the date cards immediately
    const recurringRadio = document.getElementById('schedule_type_recurring');
    if (!recurringRadio.checked) {
        renderSpecificDates();
    }
    updateAvailabilityPreview();
    toggleFieldRentalFields();
    toggleGroupClassFields();
    togglePerDaySchedule();
    
    // Add event listeners for availability fields
    document.getElementById('available_start_time').addEventListener('change', updateAvailabilityPreview);
    document.getElementById('available_end_time').addEventListener('change', updateAvailabilityPreview);
    document.getElementById('time_slot_interval').addEventListener('change', updateAvailabilityPreview);
    
    // Add event listeners for schedule type
    document.getElementById('schedule_type_recurring').addEventListener('change', toggleScheduleType);
    document.getElementById('schedule_type_specific').addEventListener('change', toggleScheduleType);
    
    // Add event listeners for day checkboxes
    document.querySelectorAll('input[name="available_days[]"]').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            updateAvailabilityPreview();
            // Keep per-day row in sync with day selection
            const row = document.getElementById('per_day_row_' + checkbox.value);
            if (row) {
                row.style.display = checkbox.checked ? 'table-row' : 'none';
            }
        });
    });
    
    // Add event listeners for per-day time inputs
    document.querySelectorAll('input[name^="day_start_time"], input[name^="day_end_time"]').forEach(function(input) {
        input.addEventListener('change', updateAvailabilityPreview);
    });

    // Serialize specific dates before form submit
    const mainForm = document.querySelector('form[method="POST"]:not([action])');
    if (mainForm) {
        mainForm.addEventListener('submit', function(event) {
            serializeSpecificDates();
            // Validate: at least one date required when specific_date mode
            if (!recurringRadio.checked) {
                const hasDates = specificDatesData.some(function(e) { return e.date; });
                if (!hasDates) {
                    event.preventDefault();
                    const hint = document.getElementById('no_dates_hint');
                    if (hint) hint.classList.remove('d-none');
                    document.getElementById('specific_dates_container').scrollIntoView({ behavior: 'smooth' });
                    return false;
                }
            }
        });
    }
});

// Update the availability preview based on form inputs
function updateAvailabilityPreview() {
    const recurringRadio = document.getElementById('schedule_type_recurring');
    const isRecurring = recurringRadio.checked;
    const previewRecurring = document.getElementById('preview_recurring');
    const previewText = document.getElementById('preview_text');
    
    if (isRecurring) {
        // Show recurring schedule preview
        previewRecurring.style.display = 'inline';
        previewText.textContent = 'Based on your settings, appointment slots will be available ';
        
        const startTime = document.getElementById('available_start_time').value;
        const endTime = document.getElementById('available_end_time').value;
        const interval = document.getElementById('time_slot_interval').value;
        
        // Get selected days
        const dayCheckboxes = document.querySelectorAll('input[name="available_days[]"]:checked');
        const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        
        // Format time for display (convert 24h to 12h format)
        function formatTime(time) {
            const [hours, minutes] = time.split(':');
            const hourValue = parseInt(hours);
            const ampm = hourValue >= 12 ? 'PM' : 'AM';
            const displayHour = hourValue % 12 || 12;
            return displayHour + ':' + minutes + ' ' + ampm;
        }
        
        const usePerDay = document.getElementById('use_per_day_schedule').checked;
        const previewGlobalTime = document.getElementById('preview_global_time');
        
        if (usePerDay) {
            // Build per-day preview showing each day's individual time range
            const dayParts = Array.from(dayCheckboxes).map(function(cb) {
                const idx = parseInt(cb.value);
                const row = document.getElementById('per_day_row_' + idx);
                const dayStartInput = row ? row.querySelector('input[name="day_start_time[' + idx + ']"]') : null;
                const dayEndInput = row ? row.querySelector('input[name="day_end_time[' + idx + ']"]') : null;
                const dayStart = dayStartInput ? dayStartInput.value : startTime;
                const dayEnd = dayEndInput ? dayEndInput.value : endTime;
                return dayNames[idx] + ' ' + formatTime(dayStart) + ' to ' + formatTime(dayEnd);
            });
            const previewDays = dayParts.length > 0 ? dayParts.join(', ') : 'no days selected';
            document.getElementById('preview_days').textContent = previewDays;
            previewGlobalTime.style.display = 'none';
        } else {
            // Use global times
            const selectedDays = Array.from(dayCheckboxes).map(cb => dayNames[parseInt(cb.value)]);
            const previewDays = selectedDays.length > 0 ? selectedDays.join(', ') : 'no days selected';
            document.getElementById('preview_days').textContent = previewDays;
            document.getElementById('preview_start').textContent = formatTime(startTime);
            document.getElementById('preview_end').textContent = formatTime(endTime);
            previewGlobalTime.style.display = 'inline';
        }
        
        // Update preview text
        document.getElementById('preview_interval').textContent = interval;
    } else {
        // Show specific dates preview
        previewRecurring.style.display = 'none';
        const datesWithValues = specificDatesData.filter(function(e) { return e.date; });
        if (datesWithValues.length > 0) {
            const labels = datesWithValues.map(function(e) {
                const [y, mo, d] = e.date.split('-').map(Number);
                return new Date(y, mo - 1, d).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            });
            previewText.innerHTML = 'This appointment will be available on <strong>' + labels.join(', ') + '</strong>';
        } else {
            previewText.textContent = 'Please add at least one specific date';
        }
    }
}
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
