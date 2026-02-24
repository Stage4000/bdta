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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
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

// Get base URL for building booking link dynamically from current request
$base_url = getDynamicBaseUrl();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $description = $_POST['description'] ?? '';
    $duration_minutes = (int)($_POST['duration_minutes'] ?? 60);
    $buffer_before_minutes = (int)($_POST['buffer_before_minutes'] ?? 0);
    $buffer_after_minutes = (int)($_POST['buffer_after_minutes'] ?? 0);
    $use_travel_time_buffer = isset($_POST['use_travel_time_buffer']) ? 1 : 0;
    $travel_time_minutes = (int)($_POST['travel_time_minutes'] ?? 0);
    $advance_booking_min_days = (int)($_POST['advance_booking_min_days'] ?? 1);
    $advance_booking_max_days = (int)($_POST['advance_booking_max_days'] ?? 90);
    $requires_forms = isset($_POST['requires_forms']) ? 1 : 0;
    $requires_contract = isset($_POST['requires_contract']) ? 1 : 0;
    $auto_invoice = isset($_POST['auto_invoice']) ? 1 : 0;
    $invoice_due_days = (int)($_POST['invoice_due_days'] ?? 7);
    $default_amount = floatval($_POST['default_amount'] ?? 0);
    $consumes_credits = isset($_POST['consumes_credits']) ? 1 : 0;
    $credit_count = (int)($_POST['credit_count'] ?? 1);
    $is_group_class = isset($_POST['is_group_class']) ? 1 : 0;
    $max_participants = (int)($_POST['max_participants'] ?? 1);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Handle Mini Sessions configuration
    $is_mini_session = isset($_POST['is_mini_session']) ? 1 : 0;
    $mini_session_location = $is_mini_session ? ($_POST['mini_session_location'] ?? '') : null;
    $mini_session_topic = $is_mini_session ? ($_POST['mini_session_topic'] ?? '') : null;
    
    // Handle Field Rental configuration
    $is_field_rental = isset($_POST['is_field_rental']) ? 1 : 0;
    $field_rental_location = $is_field_rental ? ($_POST['field_rental_location'] ?? '') : null;

    // Handle allowed location types configuration
    // Fixed types (mini_session/field_rental) don't need this — location is always 'fixed'
    $allowed_loc_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall'];
    if ($is_mini_session || $is_field_rental) {
        $location_types_json = null; // Fixed location — no selection needed
    } else {
        $selected_loc_types = isset($_POST['location_types']) && is_array($_POST['location_types'])
            ? array_values(array_filter($_POST['location_types'], fn($t) => in_array($t, $allowed_loc_types)))
            : [];
        $location_types_json = !empty($selected_loc_types) ? json_encode($selected_loc_types) : null;
    }
    
    // Handle schedule type and specific date
    $schedule_type = $_POST['schedule_type'] ?? 'recurring';
    $specific_date = null;
    if ($schedule_type === 'specific_date' && !empty($_POST['specific_date'])) {
        $specific_date = $_POST['specific_date'];
    }
    
    // Handle availability configuration
    $available_days = isset($_POST['available_days']) && is_array($_POST['available_days']) 
        ? array_map('intval', $_POST['available_days']) 
        : [0,1,2,3,4,5,6];
    $available_days_json = json_encode($available_days);
    $available_start_time = $_POST['available_start_time'] ?? '09:00';
    $available_end_time = $_POST['available_end_time'] ?? '17:00';
    $time_slot_interval = (int)($_POST['time_slot_interval'] ?? 30);

    // Handle per-day schedule configuration
    $per_day_schedule = null;
    if (isset($_POST['use_per_day_schedule'])) {
        $day_start_times = $_POST['day_start_time'] ?? [];
        $day_end_times = $_POST['day_end_time'] ?? [];
        $per_day = [];
        foreach ($available_days as $day_index) {
            $start = $day_start_times[$day_index] ?? '';
            $end   = $day_end_times[$day_index] ?? '';
            if (!empty($start) && !empty($end) && $start < $end) {
                $per_day[$day_index] = ['start' => $start, 'end' => $end];
            }
        }
        if (!empty($per_day)) {
            $per_day_schedule = json_encode($per_day);
        }
    }
    
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
                    requires_forms = ?,
                    requires_contract = ?,
                    auto_invoice = ?,
                    invoice_due_days = ?,
                    consumes_credits = ?,
                    credit_count = ?,
                    is_group_class = ?,
                    max_participants = ?,
                    is_active = ?,
                    schedule_type = ?,
                    specific_date = ?,
                    available_days = ?,
                    available_start_time = ?,
                    available_end_time = ?,
                    time_slot_interval = ?,
                    is_mini_session = ?,
                    mini_session_location = ?,
                    mini_session_topic = ?,
                    is_field_rental = ?,
                    field_rental_location = ?,
                    per_day_schedule = ?,
                    default_amount = ?,
                    location_types = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $name, $description, $duration_minutes,
                $buffer_before_minutes, $buffer_after_minutes,
                $use_travel_time_buffer, $travel_time_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $requires_forms, $requires_contract,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active,
                $schedule_type, $specific_date,
                $available_days_json, $available_start_time, $available_end_time, $time_slot_interval,
                $is_mini_session, $mini_session_location, $mini_session_topic,
                $is_field_rental, $field_rental_location,
                $per_day_schedule,
                $default_amount,
                $location_types_json,
                $id
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type updated successfully!'];
        } else {
            // Generate unique link for new appointment type with collision detection
            do {
                $unique_link = bin2hex(random_bytes(16));
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM appointment_types WHERE unique_link = ?");
                $check_stmt->execute([$unique_link]);
                $exists = $check_stmt->fetchColumn();
            } while ($exists > 0);
            
            $stmt = $conn->prepare("
                INSERT INTO appointment_types (
                    name, description, duration_minutes,
                    buffer_before_minutes, buffer_after_minutes,
                    use_travel_time_buffer, travel_time_minutes,
                    advance_booking_min_days, advance_booking_max_days,
                    requires_forms, requires_contract,
                    auto_invoice, invoice_due_days,
                    consumes_credits, credit_count,
                    is_group_class, max_participants,
                    is_active, unique_link,
                    schedule_type, specific_date,
                    available_days, available_start_time, available_end_time, time_slot_interval,
                    is_mini_session, mini_session_location, mini_session_topic,
                    is_field_rental, field_rental_location,
                    per_day_schedule,
                    default_amount,
                    location_types
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $name, $description, $duration_minutes,
                $buffer_before_minutes, $buffer_after_minutes,
                $use_travel_time_buffer, $travel_time_minutes,
                $advance_booking_min_days, $advance_booking_max_days,
                $requires_forms, $requires_contract,
                $auto_invoice, $invoice_due_days,
                $consumes_credits, $credit_count,
                $is_group_class, $max_participants,
                $is_active, $unique_link,
                $schedule_type, $specific_date,
                $available_days_json, $available_start_time, $available_end_time, $time_slot_interval,
                $is_mini_session, $mini_session_location, $mini_session_topic,
                $is_field_rental, $field_rental_location,
                $per_day_schedule,
                $default_amount,
                $location_types_json
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Appointment type created successfully!'];
        }
        
        header('Location: appointment_types_list.php');
        exit;
    } catch (PDOException $e) {
        $error = "Error saving appointment type: " . $e->getMessage();
    }
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

            <?php if ($is_edit && !empty($type['unique_link'])): ?>
                <div class="alert alert-info">
                    <h6 class="alert-heading"><i class="fas fa-link"></i> Unique Booking Link</h6>
                    <p class="mb-2">Share this link with clients to book this appointment type directly:</p>
                    <div class="input-group">
                        <input type="text" class="form-control" id="booking-link" 
                               value="<?= htmlspecialchars($base_url . '/backend/public/book.php?link=' . $type['unique_link']) ?>" 
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
                               value="<?= htmlspecialchars($type['name'] ?? '') ?>" required>
                        <div class="form-text">The name of this appointment type</div>
                    </div>
                    <div class="col-md-6">
                        <label for="duration_minutes" class="form-label">Duration (minutes) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="duration_minutes" name="duration_minutes" 
                               value="<?= $type['duration_minutes'] ?? 60 ?>" min="5" step="5" required>
                        <div class="form-text">Length of the appointment</div>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?= htmlspecialchars($type['description'] ?? '') ?></textarea>
                        <div class="form-text">Brief description of this appointment type</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Booking Rules</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="buffer_before_minutes" class="form-label">Buffer Before (minutes)</label>
                        <input type="number" class="form-control" id="buffer_before_minutes" name="buffer_before_minutes" 
                               value="<?= $type['buffer_before_minutes'] ?? 0 ?>" min="0" step="5">
                        <div class="form-text">Time blocked before appointment starts</div>
                    </div>
                    <div class="col-md-6">
                        <label for="buffer_after_minutes" class="form-label">Buffer After (minutes)</label>
                        <input type="number" class="form-control" id="buffer_after_minutes" name="buffer_after_minutes" 
                               value="<?= $type['buffer_after_minutes'] ?? 0 ?>" min="0" step="5">
                        <div class="form-text">Time blocked after appointment ends</div>
                    </div>
                </div>
                
                <!-- Phase 2: Travel Time Buffer -->
                <div class="row mb-3">
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="use_travel_time_buffer" name="use_travel_time_buffer" 
                                   value="1" <?= ($type['use_travel_time_buffer'] ?? 0) ? 'checked' : '' ?>
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
                               value="<?= $type['travel_time_minutes'] ?? 0 ?>" min="0" step="5">
                        <div class="form-text">Time needed for travel to/from appointment location</div>
                    </div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="advance_booking_min_days" class="form-label">Minimum Advance Booking (days)</label>
                        <input type="number" class="form-control" id="advance_booking_min_days" name="advance_booking_min_days" 
                               value="<?= $type['advance_booking_min_days'] ?? 1 ?>" min="0">
                        <div class="form-text">Clients must book at least this many days in advance</div>
                    </div>
                    <div class="col-md-6">
                        <label for="advance_booking_max_days" class="form-label">Maximum Advance Booking (days)</label>
                        <input type="number" class="form-control" id="advance_booking_max_days" name="advance_booking_max_days" 
                               value="<?= $type['advance_booking_max_days'] ?? 90 ?>" min="1">
                        <div class="form-text">Clients can book up to this many days in advance</div>
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
                                           <?= (!isset($type['schedule_type']) || $type['schedule_type'] === 'recurring') ? 'checked' : '' ?>
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
                                           <?= (isset($type['schedule_type']) && $type['schedule_type'] === 'specific_date') ? 'checked' : '' ?>
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
                            $available_days = isset($type['available_days']) ? json_decode($type['available_days'], true) : [0,1,2,3,4,5,6];
                            if (!is_array($available_days)) {
                                $available_days = [0,1,2,3,4,5,6];
                            }
                            $per_day_data = [];
                            $has_per_day = false;
                            if (!empty($type['per_day_schedule'])) {
                                $decoded_pds = json_decode($type['per_day_schedule'], true);
                                if (is_array($decoded_pds) && !empty($decoded_pds)) {
                                    $per_day_data = $decoded_pds;
                                    $has_per_day = true;
                                }
                            }
                            foreach ($days as $index => $day): 
                            ?>
                            <div class="col-md-3 col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="available_days[]" 
                                           id="day_<?= $index ?>" value="<?= $index ?>"
                                           <?= in_array($index, $available_days) ? 'checked' : '' ?>>
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
                                <tr id="per_day_row_<?= $index ?>" style="display: <?= in_array($index, $available_days) ? 'table-row' : 'none' ?>;">
                                    <td><strong><?= $day ?></strong></td>
                                    <td>
                                        <input type="time" class="form-control form-control-sm"
                                               name="day_start_time[<?= $index ?>]"
                                               value="<?= htmlspecialchars($per_day_data[$index]['start'] ?? ($type['available_start_time'] ?? '09:00')) ?>">
                                    </td>
                                    <td>
                                        <input type="time" class="form-control form-control-sm"
                                               name="day_end_time[<?= $index ?>]"
                                               value="<?= htmlspecialchars($per_day_data[$index]['end'] ?? ($type['available_end_time'] ?? '17:00')) ?>">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="form-text"><i class="fas fa-info-circle"></i> Per-day times override the global start/end times below for each specific day.</div>
                    </div>
                </div>

                <div id="specific_date_section" style="display: none;">
                    <div class="col-12 mb-3">
                        <label for="specific_date" class="form-label">Specific Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="specific_date" name="specific_date" 
                               value="<?= htmlspecialchars($type['specific_date'] ?? '') ?>">
                        <div class="form-text">Select the exact date this appointment will be available</div>
                    </div>
                </div>

                <div class="row g-3 mb-4 mt-3">
                    <div class="col-md-4">
                        <label for="available_start_time" class="form-label">Available Start Time</label>
                        <input type="time" class="form-control" id="available_start_time" name="available_start_time" 
                               value="<?= $type['available_start_time'] ?? '09:00' ?>" required>
                        <div class="form-text">Earliest time for appointments</div>
                    </div>
                    <div class="col-md-4">
                        <label for="available_end_time" class="form-label">Available End Time</label>
                        <input type="time" class="form-control" id="available_end_time" name="available_end_time" 
                               value="<?= $type['available_end_time'] ?? '17:00' ?>" required>
                        <div class="form-text">Latest time for appointments</div>
                    </div>
                    <div class="col-md-4">
                        <label for="time_slot_interval" class="form-label">Time Slot Interval (minutes)</label>
                        <select class="form-select" id="time_slot_interval" name="time_slot_interval" required>
                            <option value="15" <?= ($type['time_slot_interval'] ?? 30) == 15 ? 'selected' : '' ?>>15 minutes</option>
                            <option value="30" <?= ($type['time_slot_interval'] ?? 30) == 30 ? 'selected' : '' ?>>30 minutes</option>
                            <option value="60" <?= ($type['time_slot_interval'] ?? 30) == 60 ? 'selected' : '' ?>>60 minutes</option>
                        </select>
                        <div class="form-text">Interval between available time slots</div>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Preview:</strong> <span id="preview_text">
                            <?php
                            $schedule_type = $type['schedule_type'] ?? 'recurring';
                            if ($schedule_type === 'specific_date' && !empty($type['specific_date'])) {
                                echo 'This appointment will be available only on <strong>' . date('F j, Y', strtotime($type['specific_date'])) . '</strong>';
                            } else {
                                echo 'Based on your settings, appointment slots will be available ';
                            }
                            ?>
                            </span>
                            <span id="preview_recurring">
                            <span id="preview_days">
                                <?php
                                if (!isset($schedule_type) || $schedule_type === 'recurring') {
                                    $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                    $available_days = isset($type['available_days']) ? json_decode($type['available_days'], true) : [0,1,2,3,4,5,6];
                                    if (!is_array($available_days)) $available_days = [0,1,2,3,4,5,6];
                                    $selected_day_names = array_map(function($d) use ($day_names) { return $day_names[$d]; }, $available_days);
                                    echo implode(', ', $selected_day_names);
                                }
                                ?>
                            </span> 
                            from <strong id="preview_start">
                                <?php
                                $start = $type['available_start_time'] ?? '09:00';
                                list($h, $m) = explode(':', $start);
                                $hi = (int)$h;
                                echo ($hi % 12 ?: 12) . ':' . $m . ' ' . ($hi >= 12 ? 'PM' : 'AM');
                                ?>
                            </strong> to <strong id="preview_end">
                                <?php
                                $end = $type['available_end_time'] ?? '17:00';
                                list($h, $m) = explode(':', $end);
                                $hi = (int)$h;
                                echo ($hi % 12 ?: 12) . ':' . $m . ' ' . ($hi >= 12 ? 'PM' : 'AM');
                                ?>
                            </strong> 
                            in <strong id="preview_interval"><?= $type['time_slot_interval'] ?? 30 ?></strong>-minute intervals.
                            </span>
                        </div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Requirements</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="requires_forms" name="requires_forms"
                                   <?= !empty($type['requires_forms']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="requires_forms">
                                Requires Forms
                            </label>
                            <div class="form-text">Client must complete required forms before booking</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="requires_contract" name="requires_contract"
                                   <?= !empty($type['requires_contract']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="requires_contract">
                                Requires Contract
                            </label>
                            <div class="form-text">Client must sign contract before booking</div>
                        </div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Invoice Behavior</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_invoice" name="auto_invoice"
                                   <?= !empty($type['auto_invoice']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_invoice">
                                Auto-Invoice
                            </label>
                            <div class="form-text">Automatically create invoice for this appointment type</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="invoice_due_days" class="form-label">Invoice Due (days after appointment)</label>
                        <input type="number" class="form-control" id="invoice_due_days" name="invoice_due_days" 
                               value="<?= $type['invoice_due_days'] ?? 7 ?>" min="0">
                        <div class="form-text">Invoice due date offset from appointment</div>
                    </div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label for="default_amount" class="form-label">Default Invoice Amount ($)</label>
                        <input type="number" class="form-control" id="default_amount" name="default_amount"
                               value="<?= htmlspecialchars((string)(float)($type['default_amount'] ?? 0)) ?>" min="0" step="0.01">
                        <div class="form-text">Dollar amount used when auto-invoicing this appointment type</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Credits System</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="consumes_credits" name="consumes_credits"
                                   <?= !empty($type['consumes_credits']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="consumes_credits">
                                Consumes Credits
                            </label>
                            <div class="form-text">This appointment type uses session credits</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="credit_count" class="form-label">Credit Count</label>
                        <input type="number" class="form-control" id="credit_count" name="credit_count" 
                               value="<?= $type['credit_count'] ?? 1 ?>" min="1">
                        <div class="form-text">Number of credits consumed per appointment</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Group Classes</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_group_class" name="is_group_class"
                                   <?= !empty($type['is_group_class']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_group_class">
                                Is Group Class
                            </label>
                            <div class="form-text">This appointment type supports multiple participants</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="max_participants" class="form-label">Maximum Participants</label>
                        <input type="number" class="form-control" id="max_participants" name="max_participants" 
                               value="<?= $type['max_participants'] ?? 1 ?>" min="1">
                        <div class="form-text">Maximum number of clients for group classes</div>
                    </div>
                </div>

                <h6 class="border-bottom pb-2 mb-3">Mini Sessions (Venue-Based Events)</h6>
                <div class="row g-3 mb-4">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_mini_session" name="is_mini_session"
                                   <?= !empty($type['is_mini_session']) ? 'checked' : '' ?>
                                   onchange="toggleMiniSessionFields()">
                            <label class="form-check-label" for="is_mini_session">
                                <strong>This is a Mini Sessions Event</strong>
                            </label>
                            <div class="form-text">Enable for venue-based events where clients book individual time blocks at a fixed location</div>
                        </div>
                    </div>
                </div>
                
                <div id="mini_session_fields" style="display: none;">
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="mini_session_location" class="form-label">Event Location <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="mini_session_location" name="mini_session_location" 
                                   value="<?= htmlspecialchars($type['mini_session_location'] ?? '') ?>"
                                   placeholder="e.g., Greenwood Dog Park, 123 Main St, City, State ZIP">
                            <div class="form-text">Fixed venue where all mini sessions will be held. This location will be shown to clients when booking.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="mini_session_topic" class="form-label">Event Topic/Focus</label>
                            <input type="text" class="form-control" id="mini_session_topic" name="mini_session_topic" 
                                   value="<?= htmlspecialchars($type['mini_session_topic'] ?? '') ?>"
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
                                   <?= !empty($type['is_field_rental']) ? 'checked' : '' ?>
                                   onchange="toggleFieldRentalFields()">
                            <label class="form-check-label" for="is_field_rental">
                                <strong>This is a Field Rental</strong>
                            </label>
                            <div class="form-text">Enable for appointments where clients rent a training field or outdoor space</div>
                        </div>
                    </div>
                </div>
                
                <div id="field_rental_fields" style="display: <?= !empty($type['is_field_rental']) ? 'block' : 'none' ?>;">
                    <div class="row g-3 mb-4">
                        <div class="col-md-12">
                            <label for="field_rental_location" class="form-label">Field Location <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="field_rental_location" name="field_rental_location" 
                                   value="<?= htmlspecialchars($type['field_rental_location'] ?? '') ?>"
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
                $current_location_types = [];
                if (!empty($type['location_types'])) {
                    $decoded = json_decode($type['location_types'], true);
                    if (is_array($decoded)) $current_location_types = $decoded;
                }
                $is_fixed_type = !empty($type['is_mini_session']) || !empty($type['is_field_rental']);
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
                            $checked = in_array($lt_key, $current_location_types) ? 'checked' : '';
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

                <h6 class="border-bottom pb-2 mb-3">Status</h6>
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active"
                                   <?= !isset($type) || !empty($type['is_active']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_active">
                                Active
                            </label>
                            <div class="form-text">Only active types are available for booking</div>
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
        </div>
    </div>
</div>

<script>
// Toggle between schedule types
function toggleScheduleType() {
    const recurringRadio = document.getElementById('schedule_type_recurring');
    const recurringSection = document.getElementById('recurring_schedule_section');
    const specificDateSection = document.getElementById('specific_date_section');
    const specificDateInput = document.getElementById('specific_date');
    
    if (recurringRadio.checked) {
        recurringSection.style.display = 'block';
        specificDateSection.style.display = 'none';
        specificDateInput.removeAttribute('required');
    } else {
        recurringSection.style.display = 'none';
        specificDateSection.style.display = 'block';
        specificDateInput.setAttribute('required', 'required');
    }
    
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

// Show/hide the location options section based on whether this is a fixed-location type
function updateLocationTypesVisibility() {
    const isMini = document.getElementById('is_mini_session').checked;
    const isField = document.getElementById('is_field_rental').checked;
    const section = document.getElementById('locationTypesSection');
    if (section) {
        section.style.display = (isMini || isField) ? 'none' : 'block';
    }
}

// Toggle Per-Day Schedule fields
function togglePerDaySchedule() {
    const checkbox = document.getElementById('use_per_day_schedule');
    const section = document.getElementById('per_day_schedule_section');
    section.style.display = checkbox.checked ? 'block' : 'none';
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
    updateAvailabilityPreview();
    toggleFieldRentalFields();
    togglePerDaySchedule();
    
    // Add event listeners for availability fields
    document.getElementById('available_start_time').addEventListener('change', updateAvailabilityPreview);
    document.getElementById('available_end_time').addEventListener('change', updateAvailabilityPreview);
    document.getElementById('time_slot_interval').addEventListener('change', updateAvailabilityPreview);
    
    // Add event listeners for schedule type
    document.getElementById('schedule_type_recurring').addEventListener('change', toggleScheduleType);
    document.getElementById('schedule_type_specific').addEventListener('change', toggleScheduleType);
    document.getElementById('specific_date').addEventListener('change', updateAvailabilityPreview);
    
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
        const selectedDays = Array.from(dayCheckboxes).map(cb => dayNames[parseInt(cb.value)]);
        
        // Format time for display (convert 24h to 12h format)
        function formatTime(time) {
            const [hours, minutes] = time.split(':');
            const hourValue = parseInt(hours);
            const ampm = hourValue >= 12 ? 'PM' : 'AM';
            const displayHour = hourValue % 12 || 12;
            return displayHour + ':' + minutes + ' ' + ampm;
        }
        
        // Update preview text
        const previewDays = selectedDays.length > 0 ? selectedDays.join(', ') : 'no days selected';
        document.getElementById('preview_days').textContent = previewDays;
        document.getElementById('preview_start').textContent = formatTime(startTime);
        document.getElementById('preview_end').textContent = formatTime(endTime);
        document.getElementById('preview_interval').textContent = interval;
    } else {
        // Show specific date preview
        previewRecurring.style.display = 'none';
        const specificDate = document.getElementById('specific_date').value;
        if (specificDate) {
            // Parse date components to avoid timezone issues
            const [year, month, day] = specificDate.split('-').map(Number);
            const date = new Date(year, month - 1, day);
            const formattedDate = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            previewText.innerHTML = 'This appointment will be available only on <strong>' + formattedDate + '</strong>';
        } else {
            previewText.textContent = 'Please select a specific date';
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleScheduleType();
    toggleTravelTime();
    toggleMiniSessionFields();
});
</script>

<?php include __DIR__ . '/../backend/includes/footer.php'; ?>
