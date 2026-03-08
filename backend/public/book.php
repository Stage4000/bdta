<?php
/**
 * Public Booking Page
 * Allows clients to book appointments directly
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

$db = new Database();
$conn = $db->getConnection();

// Get appointment type from URL - supports both numeric ID and unique link
$appointment_type_id = 0;
$selected_type = null;
$is_standalone = false; // All appointment types are now standalone

// Check for unique link parameter first
if (isset($_GET['link']) && !empty($_GET['link'])) {
    $unique_link = $_GET['link'];
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE unique_link = ? AND is_active = 1");
    $stmt->execute([$unique_link]);
    $selected_type = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($selected_type) {
        $appointment_type_id = $selected_type['id'];
        $is_standalone = true;
    }
}
// Also support numeric type ID as standalone
elseif (isset($_GET['type']) && !empty($_GET['type'])) {
    $appointment_type_id = intval($_GET['type']);
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE id = ? AND is_active = 1");
    $stmt->execute([$appointment_type_id]);
    $selected_type = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($selected_type) {
        $is_standalone = true;
    }
}

// If no appointment type is specified, show error or redirect
if (!$selected_type) {
    // No appointment type specified - cannot proceed
    $error_mode = true;
    $appointment_types = [];
    $required_forms = [];
    $required_contract = null;
} else {
    // For standalone pages, only show the selected type
    $appointment_types = [$selected_type];

    // Load required form templates for this appointment type
    $required_forms = [];
    $stmt = $conn->prepare("
        SELECT ft.id, ft.name, ft.description, ft.fields
        FROM appointment_type_forms atf
        JOIN form_templates ft ON atf.form_template_id = ft.id
        WHERE atf.appointment_type_id = ? AND ft.is_active = 1
        ORDER BY ft.name
    ");
    $stmt->execute([$appointment_type_id]);
    $req_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($req_rows as &$req_row) {
        $req_row['fields'] = json_decode($req_row['fields'], true) ?: [];
    }
    unset($req_row);
    $required_forms = $req_rows;

    // Load required contract template for this appointment type
    $required_contract = null;
    if (!empty($selected_type['contract_template_id'])) {
        $stmt = $conn->prepare("SELECT id, name, template_text FROM contract_templates WHERE id = ? AND is_active = 1");
        $stmt->execute([$selected_type['contract_template_id']]);
        $required_contract = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

// Set page title based on booking type
if (isset($error_mode) && $error_mode) {
    $page_title = "Invalid Booking Link";
} elseif ($is_standalone && $selected_type) {
    $page_title = "Book " . $selected_type['name'];
} else {
    $page_title = "Book an Appointment";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <title><?= $page_title ?> - Brook's Dog Training Academy</title>
    <!-- Dark mode: respect saved user preference, fall back to system preference -->
    <script>
        (function () {
            'use strict';
            var saved = localStorage.getItem('bdta-theme');
            var theme = saved ? saved : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-bs-theme', theme);
        }());
    </script>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&family=Dancing+Script:wght@700&family=Pacifico&family=Satisfy&family=Great+Vibes&family=Allura&display=swap" rel="stylesheet">
    
    <?php
    $tc_primary      = (preg_match('/^#[0-9A-Fa-f]{6}$/', Settings::get('theme_primary_color', '#9a0073')))      ? Settings::get('theme_primary_color', '#9a0073')      : '#9a0073';
    $tc_primary_dark = (preg_match('/^#[0-9A-Fa-f]{6}$/', Settings::get('theme_primary_dark_color', '#7a005a'))) ? Settings::get('theme_primary_dark_color', '#7a005a') : '#7a005a';
    $tc_secondary    = (preg_match('/^#[0-9A-Fa-f]{6}$/', Settings::get('theme_secondary_color', '#0a9a9c')))    ? Settings::get('theme_secondary_color', '#0a9a9c')    : '#0a9a9c';
    $tc_accent       = (preg_match('/^#[0-9A-Fa-f]{6}$/', Settings::get('theme_accent_color', '#a39f89')))       ? Settings::get('theme_accent_color', '#a39f89')       : '#a39f89';
    ?>
    <style>
        :root {
            --primary-color: <?= $tc_primary ?>;
            --primary-dark: <?= $tc_primary_dark ?>;
            --secondary-color: <?= $tc_secondary ?>;
            --accent-color: <?= $tc_accent ?>;
            --dark-color: #1f2937;
            --light-color: #f9fafb;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--light-color) 0%, #e5e7eb 100%);
            min-height: 100vh;
            padding: 2rem 0;
        }
        
        .booking-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .booking-header {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .booking-header h1 {
            color: var(--primary-color);
            font-family: 'Montserrat', sans-serif;
            margin-bottom: 0.5rem;
        }
        
        .booking-card {
            background: white;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
        }
        
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 0;
            right: 0;
            height: 2px;
            background: #e5e7eb;
            z-index: 0;
        }
        
        .step {
            flex: 1;
            text-align: center;
            position: relative;
            z-index: 1;
        }
        
        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.5rem;
            font-weight: 600;
        }
        
        .step.active .step-circle {
            background: var(--primary-color);
            color: white;
        }
        
        .step.completed .step-circle {
            background: var(--secondary-color);
            color: white;
        }
        
        .step-label {
            font-size: 0.875rem;
            color: #6b7280;
        }
        
        .step.active .step-label {
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .appointment-type-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .appointment-type-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }
        
        .appointment-type-card.selected {
            border-color: var(--primary-color);
            background: #eff6ff;
        }
        
        .time-slot {
            padding: 0.75rem 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
            background: white;
        }
        
        .time-slot:hover {
            border-color: var(--primary-color);
            background: #eff6ff;
        }
        
        .time-slot.selected {
            border-color: var(--primary-color);
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        
        .time-slot.unavailable {
            background: #f3f4f6;
            color: #9ca3af;
            cursor: not-allowed;
        }
        
        .form-step {
            display: none;
        }
        
        .form-step.active {
            display: block;
        }
        
        .btn-primary {
            background: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }
        
        .alert-info {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1e40af;
        }
        
        .loading-spinner {
            display: none;
        }
        
        .loading-spinner.active {
            display: inline-block;
        }

        /* Signature font styles */
        .font-dancing     { font-family: 'Dancing Script', cursive; }
        .font-pacifico    { font-family: 'Pacifico', cursive; }
        .font-satisfy     { font-family: 'Satisfy', cursive; }
        .font-great-vibes { font-family: 'Great Vibes', cursive; }
        .font-allura      { font-family: 'Allura', cursive; }

        .sig-preview {
            font-size: 2.2rem;
            color: #1a1a2e;
            min-height: 3.5rem;
            border-bottom: 2px solid #495057;
            padding-bottom: 0.25rem;
            line-height: 1.2;
        }
        .font-option-btn {
            cursor: pointer;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-size: 1.5rem;
            background: white;
            transition: border-color .2s;
        }
        .font-option-btn.selected,
        .font-option-btn:hover { border-color: #9a0073; background: #fdf0f9; }
        /* ── Custom date-picker calendar ── */
        .bdta-calendar {
            display: inline-block;
            width: 100%;
            max-width: 360px;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            user-select: none;
        }
        .bdta-cal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--primary-color);
            color: #fff;
            padding: .6rem 1rem;
        }
        .bdta-cal-nav {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.3rem;
            line-height: 1;
            cursor: pointer;
            padding: 0 .4rem;
            border-radius: 4px;
            transition: background .15s;
        }
        .bdta-cal-nav:hover { background: rgba(255,255,255,.2); }
        .bdta-cal-nav:disabled { opacity: .35; cursor: default; }
        .bdta-cal-month-label { font-weight: 600; font-size: .95rem; }
        .bdta-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            background: #fff;
        }
        .bdta-cal-dow {
            text-align: center;
            font-size: .72rem;
            font-weight: 600;
            color: #6b7280;
            padding: .45rem 0;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        .bdta-cal-day {
            text-align: center;
            padding: .55rem 0;
            font-size: .88rem;
            border-radius: 50%;
            margin: 3px auto;
            width: 36px;
            height: 36px;
            line-height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        /* Empty filler cell */
        .bdta-cal-day.empty { visibility: hidden; }
        /* Past / unavailable */
        .bdta-cal-day.unavailable {
            color: #c0c4cc;
            cursor: not-allowed;
            background: transparent;
        }
        /* Available */
        .bdta-cal-day.available {
            color: #111827;
            cursor: pointer;
            font-weight: 600;
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            transition: background .15s, border-color .15s;
        }
        .bdta-cal-day.available:hover {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: #fff;
        }
        /* Selected */
        .bdta-cal-day.selected {
            background: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            color: #fff !important;
            font-weight: 700;
        }
        /* Today marker */
        .bdta-cal-day.today-marker::after {
            content: '';
            display: block;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--primary-color);
            margin: 0 auto;
            margin-top: -4px;
        }
        .bdta-cal-day.selected.today-marker::after { background: #fff; }
        .bdta-cal-footer {
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            padding: .5rem .8rem;
            font-size: .8rem;
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .bdta-cal-legend-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 4px;
        }
        .bdta-cal-legend-dot.avail  { background: #86efac; border: 1px solid #86efac; }
        .bdta-cal-legend-dot.unavail { background: #e5e7eb; border: 1px solid #e5e7eb; }
        .bdta-cal-selected-label {
            background: #f0fdf4;
            border: 1px solid #86efac;
            border-radius: 6px;
            padding: .45rem .8rem;
            font-size: .88rem;
            color: #166534;
            margin-top: .6rem;
            display: none;
        }
        /* Dark mode overrides */
        @media (prefers-color-scheme: dark) {
            body {
                background: linear-gradient(135deg, #1a1d23 0%, #0d1117 100%);
            }
            .booking-header,
            .booking-card {
                background: #1f2937;
            }
            .step-indicator::before {
                background: #374151;
            }
            .bdta-calendar { border-color: #374151; box-shadow: none; }
            .bdta-cal-grid { background: #1f2937; }
            .bdta-cal-dow  { background: #111827; color: #9ca3af; border-bottom-color: #374151; }
            .bdta-cal-day.available { background: #052e16; border-color: #16a34a; color: #d1fae5; }
            .bdta-cal-day.available:hover { background: var(--primary-color); border-color: var(--primary-color); color: #fff; }
            .bdta-cal-day.unavailable { color: #4b5563; }
            .bdta-cal-footer { background: #111827; border-top-color: #374151; color: #9ca3af; }
            .bdta-cal-selected-label { background: #052e16; border-color: #16a34a; color: #d1fae5; }
        }
        [data-bs-theme="dark"] .bdta-calendar { border-color: #374151; box-shadow: none; }
        [data-bs-theme="dark"] .bdta-cal-grid { background: #1f2937; }
        [data-bs-theme="dark"] .bdta-cal-dow  { background: #111827; color: #9ca3af; border-bottom-color: #374151; }
        [data-bs-theme="dark"] .bdta-cal-day.available { background: #052e16; border-color: #16a34a; color: #d1fae5; }
        [data-bs-theme="dark"] .bdta-cal-day.available:hover { background: var(--primary-color); border-color: var(--primary-color); color: #fff; }
        [data-bs-theme="dark"] .bdta-cal-day.unavailable { color: #4b5563; }
        [data-bs-theme="dark"] .bdta-cal-footer { background: #111827; border-top-color: #374151; color: #9ca3af; }
        [data-bs-theme="dark"] .bdta-cal-selected-label { background: #052e16; border-color: #16a34a; color: #d1fae5; }
    </style>
</head>
<body>
    <div class="booking-container">
        <div class="booking-header">
            <?php if (isset($error_mode) && $error_mode): ?>
                <h1><i class="fas fa-exclamation-circle me-2"></i>Invalid Booking Link</h1>
                <p class="text-muted mb-0">Please use a valid appointment type link to book</p>
            <?php elseif ($is_standalone && $selected_type): ?>
                <h1><i class="fas fa-calendar-check me-2"></i>Book <?= escape($selected_type['name']) ?></h1>
                <p class="text-muted mb-0"><?= escape($selected_type['description']) ?></p>
                <?php if (!empty($selected_type['is_mini_session'])): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-location-dot me-3 mt-1" style="font-size: 1.5rem;"></i>
                            <div>
                                <h5 class="mb-2"><strong>Mini Sessions Event</strong></h5>
                                <?php if (!empty($selected_type['mini_session_topic'])): ?>
                                    <p class="mb-2"><strong>Topic:</strong> <?= escape($selected_type['mini_session_topic']) ?></p>
                                <?php endif; ?>
                                <p class="mb-0">
                                    <i class="fas fa-map-marker-alt me-2"></i>
                                    <strong>Location:</strong> <?= escape($selected_type['mini_session_location']) ?>
                                </p>
                                <small class="text-muted d-block mt-2">
                                    This event takes place at a fixed venue. Book your preferred time slot below.
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($selected_type['is_group_class'])): ?>
                    <div class="alert alert-primary mt-3 mb-0">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-users me-3 mt-1" style="font-size: 1.5rem;"></i>
                            <div>
                                <h5 class="mb-2"><strong>Group Class</strong></h5>
                                <p class="mb-0">
                                    <i class="fas fa-map-marker-alt me-2"></i>
                                    <strong>Location:</strong> <?= escape($selected_type['group_class_location']) ?>
                                </p>
                                <small class="text-muted d-block mt-2">
                                    This class takes place at a fixed venue. Book your spot below.
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($selected_type['is_field_rental'])): ?>
                    <div class="alert alert-success mt-3 mb-0">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-tree me-3 mt-1" style="font-size: 1.5rem;"></i>
                            <div>
                                <h5 class="mb-2"><strong>Field Rental</strong></h5>
                                <p class="mb-0">
                                    <i class="fas fa-map-marker-alt me-2"></i>
                                    <strong>Location:</strong> <?= escape($selected_type['field_rental_location']) ?>
                                </p>
                                <small class="text-muted d-block mt-2">
                                    Reserve private time at this fenced training field. Book your preferred time slot below.
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <h1><i class="fas fa-calendar-check me-2"></i>Book Your Appointment</h1>
                <p class="text-muted mb-0">Schedule your dog training session with Brook's Dog Training Academy</p>
            <?php endif; ?>
        </div>
        
        <?php if (isset($error_mode) && $error_mode): ?>
            <!-- Error Message -->
            <div class="booking-card">
                <div class="alert alert-warning" role="alert">
                    <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>No Appointment Type Selected</h5>
                    <p>To book an appointment, please use a specific appointment type link.</p>
                    <hr>
                    <p class="mb-0">Contact us to get the booking link for the service you're interested in.</p>
                </div>
            </div>
        <?php else: ?>
        
        <div class="booking-card">
            <!-- Step Indicator (All pages are now standalone with 4 steps) -->
            <div class="step-indicator">
                <div class="step active" data-step="1">
                    <div class="step-circle">1</div>
                    <div class="step-label">Date</div>
                </div>
                <div class="step" data-step="2">
                    <div class="step-circle">2</div>
                    <div class="step-label">Time</div>
                </div>
                <div class="step" data-step="3">
                    <div class="step-circle">3</div>
                    <div class="step-label">Your Info</div>
                </div>
                <div class="step" data-step="4">
                    <div class="step-circle">4</div>
                    <div class="step-label">Confirm</div>
                </div>
            </div>
            
            <!-- Alert Area -->
            <div id="alertArea"></div>
            
            <!-- Booking Form -->
            <form id="bookingForm">
                <!-- Hidden input to store the pre-selected appointment type (all pages are standalone now) -->
                <input type="hidden" name="appointment_type" value="<?= intval($selected_type['id']) ?>" id="standaloneType">
                
                <?php
                // Determine whether this appointment type is locked to specific date(s)
                $is_specific_date_type = false;
                $specific_date_value   = null;   // single date (legacy or 1-entry multi)
                $specific_dates_list   = [];     // all dates (multi-date)

                if (!empty($selected_type['schedule_type'])
                    && $selected_type['schedule_type'] === 'specific_date') {

                    // Try new multi-date format first
                    if (!empty($selected_type['specific_dates'])) {
                        $parsed_sd = json_decode($selected_type['specific_dates'], true);
                        if (is_array($parsed_sd) && !empty($parsed_sd)) {
                            // Only keep dates that are today or in the future
                            $today_str = date('Y-m-d');
                            $specific_dates_list = array_values(
                                array_filter($parsed_sd, fn($e) => !empty($e['date']) && $e['date'] >= $today_str)
                            );
                            usort($specific_dates_list, fn($a, $b) => $a['date'] <=> $b['date']);
                            if (!empty($specific_dates_list)) {
                                $is_specific_date_type = true;
                                if (count($specific_dates_list) === 1) {
                                    $specific_date_value = $specific_dates_list[0]['date'];
                                }
                            }
                        }
                    }

                    // Fall back to legacy single-date
                    if (!$is_specific_date_type
                        && !empty($selected_type['specific_date'])
                        && DateTime::createFromFormat('Y-m-d', $selected_type['specific_date']) !== false) {
                        $is_specific_date_type = true;
                        $specific_date_value   = $selected_type['specific_date'];
                        $specific_dates_list   = [['date' => $specific_date_value, 'timeslots' => []]];
                    }
                }
                $is_multi_specific_date = $is_specific_date_type && count($specific_dates_list) > 1;
                ?>
                
                <!-- Step 1: Select Date -->
                <div class="form-step active" data-step="1">
                    <h3 class="mb-4">Choose Your Date</h3>
                    
                    <div class="row">
                        <div class="col-md-8 mx-auto mb-3">
                            <label class="form-label fw-bold">Select Date *</label>
                            <?php if ($is_specific_date_type && !$is_multi_specific_date): ?>
                            <input type="date" class="form-control form-control-lg" id="appointmentDate"
                                   name="appointment_date" required
                                   value="<?= htmlspecialchars($specific_date_value) ?>"
                                   min="<?= htmlspecialchars($specific_date_value) ?>"
                                   max="<?= htmlspecialchars($specific_date_value) ?>"
                                   readonly>
                            <small class="text-muted mt-2 d-block">
                                <i class="fas fa-calendar-check me-1"></i>
                                This session is only available on <strong><?= date('F j, Y', strtotime($specific_date_value)) ?></strong>.
                            </small>
                            <?php else: ?>
                            <!-- Dates loaded dynamically; only dates with open slots are shown -->
                            <div id="dateLoadingArea" class="alert alert-info py-2">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                                Loading available dates&hellip;
                            </div>
                            <input type="hidden" id="appointmentDate" name="appointment_date">
                            <div id="calendarWidget" style="display:none;"></div>
                            <div id="calSelectedLabel" class="bdta-cal-selected-label">
                                <i class="fas fa-calendar-check me-1"></i>
                                <span id="calSelectedText"></span>
                            </div>
                            <div id="noAvailableDatesMsg" class="alert alert-warning" style="display:none;">
                                <i class="fas fa-calendar-times me-2"></i>
                                There are currently no available dates. Please check back later or <a href="/">contact us</a> for assistance.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end mt-4">
                        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()" id="step2Next">
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 2: Select Time -->
                <div class="form-step" data-step="2">
                    <h3 class="mb-4">Choose Your Time</h3>
                    
                    <div class="row">
                        <div class="col-12">
                            <p class="text-muted mb-3">
                                <i class="fas fa-calendar me-2"></i>
                                Selected date: <strong id="selectedDateDisplay">-</strong>
                            </p>
                            <label class="form-label fw-bold">Select Time *</label>
                            <div class="alert alert-info" id="loadingSlots">
                                <div class="spinner-border spinner-border-sm me-2"></div>
                                Loading available times...
                            </div>
                            <div id="timeSlotsContainer" class="row g-2" style="display: none;"></div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()" id="step3Next" disabled>
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 3: Your Information -->
                <div class="form-step" data-step="3">
                    <h3 class="mb-4">Your Information</h3>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Your Name *</label>
                            <input type="text" class="form-control form-control-lg" name="client_name" 
                                   id="clientName" required placeholder="John Doe">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" class="form-control form-control-lg" name="client_email" 
                                   id="clientEmail" required placeholder="john@example.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control form-control-lg" name="client_phone" 
                                   id="clientPhone" placeholder="(555) 123-4567">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Dog's Name(s)</label>
                            <input type="text" class="form-control form-control-lg" name="dog_names" 
                                   id="dogNames" placeholder="e.g., Max, Bella">
                            <small class="text-muted">If you have multiple dogs, separate with commas</small>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" name="notes" id="notes" rows="3" 
                                      placeholder="Tell us about your dog's needs, behavior concerns, or any special requirements..."></textarea>
                        </div>

                        <!-- Location -->
                        <?php
                        $is_fixed_type = !empty($selected_type['is_mini_session']) || !empty($selected_type['is_field_rental']) || !empty($selected_type['is_group_class']);
                        $pub_loc_types_all = [
                            'client_address' => ['label' => 'My registered address',                'needsValue' => false],
                            'custom_address' => ['label' => 'A different address',                  'needsValue' => true,  'placeholder' => 'Enter full address',         'valueLabel' => 'Address *',   'type' => 'text'],
                            'phone_inbound'  => ['label' => 'Phone call — I will call the trainer', 'needsValue' => false],
                            'phone_outbound' => ['label' => 'Phone call — Trainer will call me',    'needsValue' => false],
                            'webcall'        => ['label' => 'Video call (Zoom, Google Meet, etc.)', 'needsValue' => true,  'placeholder' => 'https://zoom.us/j/...',      'valueLabel' => 'Video call URL *', 'type' => 'url'],
                        ];
                        // Determine allowed types from appointment type config
                        $pub_allowed = [];
                        if (!$is_fixed_type && !empty($selected_type['location_types'])) {
                            $pub_decoded = json_decode($selected_type['location_types'], true);
                            if (is_array($pub_decoded) && !empty($pub_decoded)) {
                                $pub_allowed = array_filter($pub_decoded, fn($t) => isset($pub_loc_types_all[$t]));
                            }
                        }
                        if (empty($pub_allowed) && !$is_fixed_type) {
                            $pub_allowed = array_keys($pub_loc_types_all); // Default: all types
                        }
                        ?>
                        <div class="col-12 mb-3">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white py-2">
                                    <h6 class="mb-0"><i class="fas fa-map-marker-alt me-2" aria-hidden="true"></i>Appointment Location</h6>
                                </div>
                                <div class="card-body">
                                <?php if ($is_fixed_type): ?>
                                    <?php
                                    if (!empty($selected_type['is_mini_session'])) {
                                        $fixed_loc = $selected_type['mini_session_location'] ?? '';
                                    } elseif (!empty($selected_type['is_field_rental'])) {
                                        $fixed_loc = $selected_type['field_rental_location'] ?? '';
                                    } else {
                                        $fixed_loc = $selected_type['group_class_location'] ?? '';
                                    }
                                    ?>
                                    <p class="mb-1 text-muted small">This appointment has a fixed location:</p>
                                    <p class="mb-0 fw-bold"><i class="fas fa-location-dot me-2" aria-hidden="true"></i><?= escape($fixed_loc) ?></p>
                                    <input type="hidden" name="location_type" value="fixed">
                                    <input type="hidden" name="location_value" value="<?= escape($fixed_loc) ?>">
                                <?php elseif (count($pub_allowed) === 1): ?>
                                    <?php $only_lt = reset($pub_allowed); $only_def = $pub_loc_types_all[$only_lt]; ?>
                                    <p class="mb-2 text-muted small">Location for this appointment:</p>
                                    <p class="mb-2 fw-bold"><i class="fas fa-map-marker-alt me-2" aria-hidden="true"></i><?= htmlspecialchars($only_def['label']) ?></p>
                                    <input type="hidden" name="location_type" value="<?= htmlspecialchars($only_lt) ?>">
                                    <?php if (!empty($only_def['needsValue'])): ?>
                                    <div>
                                        <label class="form-label"><?= htmlspecialchars($only_def['valueLabel']) ?></label>
                                        <input type="<?= htmlspecialchars($only_def['type']) ?>" class="form-control form-control-lg"
                                               name="location_value" id="publicLocationValueInput"
                                               placeholder="<?= htmlspecialchars($only_def['placeholder']) ?>" required>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <label class="form-label">Where will this appointment take place? *</label>
                                    <select class="form-select form-select-lg" name="location_type" id="publicLocationType" required>
                                        <option value="">— Select location type —</option>
                                        <?php foreach ($pub_allowed as $lt_key): ?>
                                            <?php $lt_def = $pub_loc_types_all[$lt_key]; ?>
                                            <option value="<?= htmlspecialchars($lt_key) ?>"><?= htmlspecialchars($lt_def['label']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div id="publicLocationValueWrapper" class="mt-2" style="display:none;">
                                        <label class="form-label" id="publicLocationValueLabel">Value *</label>
                                        <input type="text" class="form-control form-control-lg" name="location_value" id="publicLocationValueInput" placeholder="">
                                    </div>
                                <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($required_forms)): ?>
                    <hr class="my-4">
                    <h5 class="mb-1"><i class="fas fa-file-alt me-2"></i>Required Forms</h5>
                    <p class="text-muted mb-3">Please complete the following forms as part of your booking.</p>
                    <?php foreach ($required_forms as $form): ?>
                        <div class="card mb-4" data-form-id="<?= $form['id'] ?>">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><?= htmlspecialchars($form['name']) ?></h6>
                                <?php if (!empty($form['description'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars($form['description']) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php foreach ($form['fields'] as $fi => $field):
                                    $fn = 'form_resp_' . $form['id'] . '_' . $fi;
                                    $is_req = !empty($field['required']);
                                    $ph = htmlspecialchars($field['placeholder'] ?? '');
                                ?>
                                <div class="mb-3">
                                    <label class="form-label">
                                        <?= htmlspecialchars($field['label']) ?>
                                        <?php if ($is_req): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <?php if (!empty($field['description'])): ?>
                                    <div class="form-text text-muted mb-1" id="field-desc-<?= $form['id'] ?>-<?= $fi ?>"><?= htmlspecialchars($field['description']) ?></div>
                                    <?php endif; ?>
                                    <?php
                                    $aria = !empty($field['description']) ? 'aria-describedby="field-desc-' . $form['id'] . '-' . $fi . '"' : '';
                                    switch ($field['type']):
                                        case 'textarea': ?>
                                        <textarea class="form-control" data-form-field="<?= $fi ?>"
                                                  placeholder="<?= $ph ?>"
                                                  <?= $aria ?>
                                                  <?= $is_req ? 'required' : '' ?>></textarea>
                                        <?php break; case 'select': ?>
                                        <select class="form-select" data-form-field="<?= $fi ?>" <?= $aria ?> <?= $is_req ? 'required' : '' ?>>
                                            <option value="">— Select —</option>
                                            <?php foreach ($field['options'] ?? [] as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php break; case 'radio': ?>
                                        <?php foreach ($field['options'] ?? [] as $oi => $opt): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio"
                                                   data-form-field="<?= $fi ?>"
                                                   name="<?= $fn ?>"
                                                   id="<?= $fn ?>_<?= $oi ?>"
                                                   value="<?= htmlspecialchars($opt) ?>"
                                                   <?= $aria ?>
                                                   <?= ($is_req && $oi === 0) ? 'required' : '' ?>>
                                            <label class="form-check-label" for="<?= $fn ?>_<?= $oi ?>"><?= htmlspecialchars($opt) ?></label>
                                        </div>
                                        <?php endforeach;
                                        break; case 'checkbox': ?>
                                        <?php foreach ($field['options'] ?? [] as $oi => $opt): ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   data-form-field="<?= $fi ?>"
                                                   id="<?= $fn ?>_<?= $oi ?>"
                                                   value="<?= htmlspecialchars($opt) ?>"
                                                   <?= $aria ?>>
                                            <label class="form-check-label" for="<?= $fn ?>_<?= $oi ?>"><?= htmlspecialchars($opt) ?></label>
                                        </div>
                                        <?php endforeach;
                                        break; default: ?>
                                        <input type="<?= htmlspecialchars($field['type']) ?>"
                                               class="form-control" data-form-field="<?= $fi ?>"
                                               placeholder="<?= $ph ?>"
                                               <?= $aria ?>
                                               <?= $is_req ? 'required' : '' ?>>
                                        <?php break; endswitch; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php endif; ?>

                    <?php if (!empty($required_contract)): ?>
                    <hr class="my-4">
                    <h5 class="mb-1"><i class="fas fa-file-contract me-2"></i>Required Contract</h5>
                    <p class="text-muted mb-3">Please review and sign the following contract to continue with your booking.</p>
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><?= htmlspecialchars($required_contract['name']) ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="border rounded p-3 mb-4 bg-white" style="max-height: 300px; overflow-y: auto; font-size: 0.9rem;"><?= $required_contract['template_text'] ?></div>

                            <!-- Typed Name -->
                            <div class="mb-4">
                                <label for="contractTypedName" class="form-label fw-semibold">
                                    Type your full legal name to sign <span class="text-danger">*</span>
                                </label>
                                <input type="text" class="form-control form-control-lg"
                                       id="contractTypedName" name="contract_typed_name"
                                       placeholder="Your full name"
                                       autocomplete="name"
                                       maxlength="200">
                            </div>

                            <!-- Font Style Selector -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Choose a signature style</label>
                                <div class="d-flex flex-wrap gap-3" id="contractFontOptions">
                                    <button type="button" class="font-option-btn font-dancing selected"
                                            data-font="font-dancing">
                                        <span class="contract-font-preview">Your Name</span>
                                    </button>
                                    <button type="button" class="font-option-btn font-pacifico"
                                            data-font="font-pacifico">
                                        <span class="contract-font-preview">Your Name</span>
                                    </button>
                                    <button type="button" class="font-option-btn font-satisfy"
                                            data-font="font-satisfy">
                                        <span class="contract-font-preview">Your Name</span>
                                    </button>
                                    <button type="button" class="font-option-btn font-great-vibes"
                                            data-font="font-great-vibes">
                                        <span class="contract-font-preview">Your Name</span>
                                    </button>
                                    <button type="button" class="font-option-btn font-allura"
                                            data-font="font-allura">
                                        <span class="contract-font-preview">Your Name</span>
                                    </button>
                                </div>
                            </div>

                            <!-- Live Preview -->
                            <div class="mb-4">
                                <label class="form-label fw-semibold">Signature preview</label>
                                <div class="sig-preview font-dancing" id="contractSigPreview">&nbsp;</div>
                            </div>

                            <!-- Confirmation -->
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="contractConfirmation" name="contract_confirmation">
                                <label class="form-check-label" for="contractConfirmation">
                                    I have read and agree to the terms outlined in this contract.
                                </label>
                            </div>

                            <input type="hidden" name="contract_template_id" value="<?= intval($required_contract['id']) ?>">
                            <input type="hidden" id="contractSignatureFont" name="contract_signature_font" value="font-dancing">
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </button>
                        <button type="button" class="btn btn-primary btn-lg" onclick="nextStep()">
                            Continue <i class="fas fa-arrow-right ms-2"></i>
                        </button>
                    </div>
                </div>
                
                <!-- Step 4: Confirmation -->
                <div class="form-step" data-step="4">
                    <h3 class="mb-4">Confirm Your Booking</h3>
                    
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title mb-3">Appointment Summary</h5>
                            <dl class="row mb-0">
                                <dt class="col-sm-4">Service:</dt>
                                <dd class="col-sm-8" id="confirmService">-</dd>
                                
                                <dt class="col-sm-4">Date:</dt>
                                <dd class="col-sm-8" id="confirmDate">-</dd>
                                
                                <dt class="col-sm-4">Time:</dt>
                                <dd class="col-sm-8" id="confirmTime">-</dd>
                                
                                <dt class="col-sm-4">Name:</dt>
                                <dd class="col-sm-8" id="confirmName">-</dd>
                                
                                <dt class="col-sm-4">Email:</dt>
                                <dd class="col-sm-8" id="confirmEmail">-</dd>
                                
                                <dt class="col-sm-4">Phone:</dt>
                                <dd class="col-sm-8" id="confirmPhone">-</dd>
                                
                                <dt class="col-sm-4">Dog(s):</dt>
                                <dd class="col-sm-8" id="confirmDogs">-</dd>
                                
                                <dt class="col-sm-4">Location:</dt>
                                <dd class="col-sm-8" id="confirmLocation">-</dd>
                            </dl>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-circle-info me-2"></i>
                        You will receive a confirmation email with your appointment details and calendar links.
                    </div>

                    <!-- Credit toggle: shown only when client has available credits for this appointment type -->
                    <div class="alert alert-success mt-3 d-none" id="creditToggleArea">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="useCreditToggle" name="use_credit" value="1">
                            <label class="form-check-label fw-bold" for="useCreditToggle">
                                <i class="fas fa-ticket me-1"></i>
                                Use a credit from my package for this booking
                            </label>
                        </div>
                        <small class="text-muted d-block mt-1" id="creditRemainingNote"></small>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-outline-secondary btn-lg" onclick="prevStep()">
                            <i class="fas fa-arrow-left me-2"></i> Back
                        </button>
                        <button type="submit" class="btn btn-success btn-lg" id="submitBtn">
                            <span class="loading-spinner spinner-border spinner-border-sm me-2"></span>
                            <i class="fas fa-check-circle me-2"></i> Confirm Booking
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php endif; // End error_mode check ?>
    </div>
    
    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h2 class="mb-3">Booking Confirmed!</h2>
                    <p class="text-muted mb-4">Your appointment has been successfully booked. Check your email for confirmation details and calendar links.</p>
                    <a href="/" class="btn btn-primary btn-lg">Back to Home</a>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    // Prepare JavaScript variables for appointment type data
    $js_type_id = $selected_type ? intval($selected_type['id']) : 'null';
    $js_type_name = $selected_type ? json_encode($selected_type['name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';
    $js_type_duration = ($selected_type && isset($selected_type['duration_minutes']) && $selected_type['duration_minutes'] > 0) 
        ? intval($selected_type['duration_minutes']) 
        : 'null';
    // For single specific date, pre-load it; for multi-date or free choice, start as null
    $js_specific_date = ($is_specific_date_type && $specific_date_value && !$is_multi_specific_date)
        ? json_encode($specific_date_value)
        : 'null';
    // Whether the date selector needs to be populated dynamically
    $js_needs_dynamic_dates = (!isset($error_mode) || !$error_mode) && !($is_specific_date_type && !$is_multi_specific_date);
    // Range for available_dates API: use wider window for specific_date types so all configured dates are included
    $js_date_range_days = $is_multi_specific_date ? 365 : 60;
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // All pages are now standalone with 4 steps
        let currentStep = 1;
        let selectedType = <?= $js_type_id ?>;
        let selectedTypeName = <?= $js_type_name ?>;
        let selectedTypeDuration = <?= $js_type_duration ?>;
        let selectedDate = <?= $js_specific_date ?>;
        let selectedTime = null;
        const maxSteps = 4;
        
        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Only initialize form elements if they exist (not on error page)
            const bookingForm = document.getElementById('bookingForm');
            const publicLocationType = document.getElementById('publicLocationType');

            // Dynamically load available dates when the calendar widget is present
            if (<?= $js_needs_dynamic_dates ? 'true' : 'false' ?>) {
                loadAvailableDates();
            }

            if (publicLocationType) {
                publicLocationType.addEventListener('change', function() {
                    const type = this.value;
                    const wrapper = document.getElementById('publicLocationValueWrapper');
                    const label = document.getElementById('publicLocationValueLabel');
                    const input = document.getElementById('publicLocationValueInput');
                    if (type === 'custom_address') {
                        wrapper.style.display = 'block';
                        label.textContent = 'Address *';
                        input.placeholder = 'Enter full address';
                        input.type = 'text';
                        input.required = true;
                    } else if (type === 'webcall') {
                        wrapper.style.display = 'block';
                        label.textContent = 'Webcall URL *';
                        input.placeholder = 'https://zoom.us/j/... or similar';
                        input.type = 'url';
                        input.required = true;
                    } else {
                        wrapper.style.display = 'none';
                        input.required = false;
                        input.value = '';
                    }
                });
            }
            
            if (bookingForm) {
                // Form submission
                bookingForm.addEventListener('submit', submitBooking);
            }
        });
        
        function nextStep() {
            // Validation (all pages are now standalone with 4 steps)
            if (currentStep === 1) {
                // Step 1 is date selection
                if (!selectedDate) {
                    showAlert('Please select a date', 'warning');
                    return;
                }
                updateSelectedDateDisplay();
                loadAvailableSlots();
            } else if (currentStep === 2) {
                // Step 2 is time selection
                if (!selectedTime) {
                    showAlert('Please select a time', 'warning');
                    return;
                }
            } else if (currentStep === 3) {
                // Step 3 is info collection
                const name = document.getElementById('clientName').value.trim();
                const email = document.getElementById('clientEmail').value.trim();
                if (!name || !email) {
                    showAlert('Please fill in your name and email', 'warning');
                    return;
                }
                // Validate location (only if selector is visible — not for fixed/single types)
                const locTypeEl = document.getElementById('publicLocationType');
                if (locTypeEl) {
                    if (!locTypeEl.value) {
                        showAlert('Please select a location type for your appointment', 'warning');
                        return;
                    }
                    if (['custom_address', 'webcall'].includes(locTypeEl.value)) {
                        const locVal = document.getElementById('publicLocationValueInput');
                        if (!locVal || !locVal.value.trim()) {
                            showAlert(locTypeEl.value === 'webcall' ? 'Please enter the webcall URL.' : 'Please enter the address.', 'warning');
                            return;
                        }
                    }
                } else {
                    // Single-option or fixed: validate value field if present and required
                    const locVal = document.getElementById('publicLocationValueInput');
                    if (locVal && locVal.required && !locVal.value.trim()) {
                        showAlert('Please enter the required location information.', 'warning');
                        return;
                    }
                }
                // Validate contract signature if required
                const contractNameEl = document.getElementById('contractTypedName');
                if (contractNameEl) {
                    if (!contractNameEl.value.trim()) {
                        showAlert('Please type your full name to sign the required contract.', 'warning');
                        contractNameEl.focus();
                        return;
                    }
                    const contractConfirm = document.getElementById('contractConfirmation');
                    if (contractConfirm && !contractConfirm.checked) {
                        showAlert('You must check the confirmation box to accept the contract.', 'warning');
                        return;
                    }
                }
            }
            
            if (currentStep < maxSteps) {
                currentStep++;
                updateSteps();
                if (currentStep === maxSteps) {
                    updateConfirmation();
                }
            }
        }
        
        function prevStep() {
            if (currentStep > 1) {
                currentStep--;
                updateSteps();
            }
        }
        
        function updateSteps() {
            // Update step indicators
            document.querySelectorAll('.step').forEach(step => {
                const stepNum = parseInt(step.dataset.step);
                step.classList.remove('active', 'completed');
                if (stepNum === currentStep) {
                    step.classList.add('active');
                } else if (stepNum < currentStep) {
                    step.classList.add('completed');
                }
            });
            
            // Update form steps
            document.querySelectorAll('.form-step').forEach(step => {
                step.classList.remove('active');
            });
            document.querySelector(`.form-step[data-step="${currentStep}"]`).classList.add('active');
            
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        /* ── Calendar date picker ─────────────────────────────────── */
        let calendarYear  = new Date().getFullYear();
        let calendarMonth = new Date().getMonth(); // 0-indexed
        let availableDatesSet = new Set();
        const CAL_DAY_NAMES  = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        const CAL_MONTH_NAMES = ['January','February','March','April','May','June',
                             'July','August','September','October','November','December'];

        function loadAvailableDates() {
            const loadingArea = document.getElementById('dateLoadingArea');
            const calWidget   = document.getElementById('calendarWidget');
            const noAvailMsg  = document.getElementById('noAvailableDatesMsg');
            if (!loadingArea || !calWidget || !selectedType) return;

            const today    = new Date().toISOString().split('T')[0];
            const rangeDays = <?= intval($js_date_range_days) ?>;
            const endDate  = new Date(Date.now() + rangeDays * 24 * 60 * 60 * 1000).toISOString().split('T')[0];

            fetch(`api_bookings.php?action=available_dates&appointment_type_id=${selectedType}&from=${today}&to=${endDate}`)
                .then(r => r.json())
                .then(data => {
                    loadingArea.style.display = 'none';
                    const dates = data.available_dates || [];
                    availableDatesSet = new Set(dates);
                    if (dates.length > 0) {
                        // Jump calendar to the month of the first available date
                        const firstDate = new Date(dates[0] + 'T00:00');
                        calendarYear  = firstDate.getFullYear();
                        calendarMonth = firstDate.getMonth();
                        renderCalendar();
                        calWidget.style.display = '';
                    } else {
                        if (noAvailMsg) noAvailMsg.style.display = '';
                    }
                })
                .catch(() => {
                    // On network error, fall back to a plain date input
                    if (loadingArea) loadingArea.style.display = 'none';
                    const calWidget2 = document.getElementById('calendarWidget');
                    if (calWidget2) {
                        const hiddenInput = document.getElementById('appointmentDate');
                        const input = document.createElement('input');
                        input.type  = 'date';
                        input.id    = 'appointmentDate';
                        input.name  = 'appointment_date';
                        input.className = 'form-control form-control-lg';
                        input.required  = true;
                        input.min = new Date().toISOString().split('T')[0];
                        input.addEventListener('change', function() {
                            selectedDate = this.value;
                            document.getElementById('step2Next').disabled = false;
                        });
                        if (hiddenInput) hiddenInput.replaceWith(input);
                        calWidget2.remove();
                    }
                });
        }

        function renderCalendar() {
            const calWidget = document.getElementById('calendarWidget');
            if (!calWidget) return;
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            const todayStr = today.toISOString().split('T')[0];

            const firstDay = new Date(calendarYear, calendarMonth, 1).getDay();
            const daysInMonth = new Date(calendarYear, calendarMonth + 1, 0).getDate();
            const nowYear = today.getFullYear();
            const nowMonth = today.getMonth();
            const isPrevDisabled = (calendarYear < nowYear) ||
                                   (calendarYear === nowYear && calendarMonth <= nowMonth);

            let html = `<div class="bdta-calendar">
  <div class="bdta-cal-header">
    <button class="bdta-cal-nav" onclick="calPrevMonth()" ${isPrevDisabled ? 'disabled' : ''}>&lsaquo;</button>
    <span class="bdta-cal-month-label">${CAL_MONTH_NAMES[calendarMonth]} ${calendarYear}</span>
    <button class="bdta-cal-nav" onclick="calNextMonth()">&rsaquo;</button>
  </div>
  <div class="bdta-cal-grid">`;

            CAL_DAY_NAMES.forEach(d => { html += `<div class="bdta-cal-dow">${d}</div>`; });

            for (let i = 0; i < firstDay; i++) {
                html += `<div class="bdta-cal-day empty"></div>`;
            }

            for (let day = 1; day <= daysInMonth; day++) {
                const mm   = String(calendarMonth + 1).padStart(2, '0');
                const dd   = String(day).padStart(2, '0');
                const dStr = `${calendarYear}-${mm}-${dd}`;
                const isAvail    = availableDatesSet.has(dStr);
                const isSelected = (selectedDate === dStr);
                const isToday    = (dStr === todayStr);
                let cls = 'bdta-cal-day';
                if      (isSelected) cls += ' available selected';
                else if (isAvail)    cls += ' available';
                else                 cls += ' unavailable';
                if (isToday) cls += ' today-marker';
                const onclick = isAvail ? `onclick="selectCalendarDate('${dStr}')"` : '';
                html += `<div class="${cls}" ${onclick}>${day}</div>`;
            }
            html += `</div>
  <div class="bdta-cal-footer">
    <span><span class="bdta-cal-legend-dot avail"></span>Available</span>
    <span><span class="bdta-cal-legend-dot unavail"></span>Unavailable</span>
  </div>
</div>`;
            calWidget.innerHTML = html;
        }

        window.calPrevMonth = function () {
            const now = new Date();
            if (calendarYear === now.getFullYear() && calendarMonth <= now.getMonth()) return;
            if (calendarMonth === 0) { calendarMonth = 11; calendarYear--; }
            else { calendarMonth--; }
            renderCalendar();
        };

        window.calNextMonth = function () {
            if (calendarMonth === 11) { calendarMonth = 0; calendarYear++; }
            else { calendarMonth++; }
            renderCalendar();
        };

        window.selectCalendarDate = function (d) {
            selectedDate = d;
            const hiddenInput = document.getElementById('appointmentDate');
            if (hiddenInput) hiddenInput.value = d;
            document.getElementById('step2Next').disabled = false;
            const label    = document.getElementById('calSelectedLabel');
            const labelTxt = document.getElementById('calSelectedText');
            if (label && labelTxt) {
                const dateObj = new Date(d + 'T00:00');
                labelTxt.textContent = dateObj.toLocaleDateString('en-US', {
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
                });
                label.style.display = '';
            }
            renderCalendar();
        };

        function loadAvailableSlots() {
            if (!selectedDate || !selectedType) return;
            
            const loadingSlots = document.getElementById('loadingSlots');
            const slotsContainer = document.getElementById('timeSlotsContainer');
            
            loadingSlots.style.display = 'block';
            loadingSlots.innerHTML = '<div class="spinner-border spinner-border-sm me-2"></div> Loading available times...';
            slotsContainer.style.display = 'none';
            slotsContainer.innerHTML = '';
            
            fetch(`api_bookings.php?date=${selectedDate}&appointment_type_id=${selectedType}`)
                .then(r => r.json())
                .then(data => {
                    loadingSlots.style.display = 'none';
                    slotsContainer.style.display = 'flex';
                    
                    if (data.available_slots && data.available_slots.length > 0) {
                        data.available_slots.forEach(slot => {
                            const slotDiv = document.createElement('div');
                            slotDiv.className = 'col-6 col-md-3';
                            slotDiv.innerHTML = `<div class="time-slot" data-time="${slot}" onclick="selectTimeSlot('${slot}')">${formatTime(slot)}</div>`;
                            slotsContainer.appendChild(slotDiv);
                        });
                    } else {
                        let message = 'No available time slots for this date.';
                        if (data.message) {
                            message = data.message;
                        }
                        slotsContainer.innerHTML = `<div class="col-12"><div class="alert alert-warning"><i class="fas fa-info-circle me-2"></i>${message} Please try another date.</div></div>`;
                    }
                })
                .catch(err => {
                    loadingSlots.style.display = 'block';
                    loadingSlots.className = 'alert alert-danger';
                    loadingSlots.innerHTML = '<i class="fas fa-triangle-exclamation me-2"></i> Error loading time slots. Please try again.';
                });
        }
        
        function selectTimeSlot(time) {
            selectedTime = time;
            document.querySelectorAll('.time-slot').forEach(slot => {
                slot.classList.remove('selected');
            });
            event.target.classList.add('selected');
            document.getElementById('step3Next').disabled = false;
        }
        
        function updateSelectedDateDisplay() {
            if (selectedDate) {
                const dateObj = new Date(selectedDate + 'T00:00');
                const formatted = dateObj.toLocaleDateString('en-US', { 
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
                });
                document.getElementById('selectedDateDisplay').textContent = formatted;
            }
        }
        
        function formatTime(time) {
            const [hours, minutes] = time.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour > 12 ? hour - 12 : (hour === 0 ? 12 : hour);
            return `${hour12}:${minutes} ${ampm}`;
        }
        
        function getLocationSummary() {
            const locTypeEl = document.getElementById('publicLocationType');
            if (!locTypeEl) {
                // Fixed type — find the hidden input
                const hiddenType = document.querySelector('input[name="location_type"]');
                const hiddenVal = document.querySelector('input[name="location_value"]');
                return hiddenVal ? hiddenVal.value : 'Fixed location';
            }
            const type = locTypeEl.value;
            const labels = {
                'client_address': 'My registered address',
                'custom_address': document.getElementById('publicLocationValueInput')?.value || 'Custom address',
                'phone_inbound': 'Phone call (I call the trainer)',
                'phone_outbound': 'Phone call (trainer calls me)',
                'webcall': document.getElementById('publicLocationValueInput')?.value || 'Video call',
            };
            return labels[type] || '';
        }

        function updateConfirmation() {
            const typeName = selectedTypeName || 'Appointment';
            
            document.getElementById('confirmService').textContent = typeName;
            document.getElementById('confirmDate').textContent = new Date(selectedDate + 'T00:00').toLocaleDateString('en-US', { 
                weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
            });
            document.getElementById('confirmTime').textContent = formatTime(selectedTime);
            document.getElementById('confirmName').textContent = document.getElementById('clientName').value;
            document.getElementById('confirmEmail').textContent = document.getElementById('clientEmail').value;
            document.getElementById('confirmPhone').textContent = document.getElementById('clientPhone').value || 'Not provided';
            document.getElementById('confirmDogs').textContent = document.getElementById('dogNames').value || 'Not specified';
            document.getElementById('confirmLocation').textContent = getLocationSummary() || 'Not specified';

            // Check for available credits
            const email = document.getElementById('clientEmail').value.trim();
            const creditToggleArea = document.getElementById('creditToggleArea');
            const creditRemainingNote = document.getElementById('creditRemainingNote');
            if (email && selectedType) {
                fetch(`api_bookings.php?action=credits&email=${encodeURIComponent(email)}&appointment_type_id=${selectedType}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.credits && data.credits.length > 0) {
                            const best = data.credits[0];
                            const totalRemaining = data.credits.reduce((sum, c) => sum + parseInt(c.remaining), 0);
                            creditRemainingNote.textContent = `You have ${totalRemaining} credit(s) available for this appointment type.`;
                            creditToggleArea.classList.remove('d-none');
                        } else {
                            creditToggleArea.classList.add('d-none');
                            document.getElementById('useCreditToggle').checked = false;
                        }
                    })
                    .catch(() => {
                        creditToggleArea.classList.add('d-none');
                    });
            } else {
                creditToggleArea.classList.add('d-none');
            }
        }
        
        function collectFormResponses() {
            const responses = {};
            document.querySelectorAll('[data-form-id]').forEach(section => {
                const formId = section.dataset.formId;
                const fields = {};
                // text, email, tel, number, date, textarea, select
                section.querySelectorAll('input:not([type=checkbox]):not([type=radio]), textarea, select').forEach(el => {
                    if (el.dataset.formField !== undefined) {
                        fields[el.dataset.formField] = el.value;
                    }
                });
                // radio — pick checked value per group
                const radioSeen = {};
                section.querySelectorAll('input[type=radio]').forEach(el => {
                    const fi = el.dataset.formField;
                    if (fi !== undefined && radioSeen[fi] === undefined) radioSeen[fi] = '';
                    if (el.checked) radioSeen[fi] = el.value;
                });
                Object.assign(fields, radioSeen);
                // checkboxes — collect array of checked values
                const cbGroups = {};
                section.querySelectorAll('input[type=checkbox]').forEach(el => {
                    const fi = el.dataset.formField;
                    if (fi !== undefined) {
                        if (!cbGroups[fi]) cbGroups[fi] = [];
                        if (el.checked) cbGroups[fi].push(el.value);
                    }
                });
                Object.assign(fields, cbGroups);
                responses[formId] = fields;
            });
            return responses;
        }

        function submitBooking(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const spinner = submitBtn.querySelector('.loading-spinner');
            
            submitBtn.disabled = true;
            spinner.classList.add('active');
            
            const typeName = selectedTypeName || 'Appointment';
            
            // Gather location data
            let location_type = '';
            let location_value = '';
            const locTypeEl = document.getElementById('publicLocationType');
            if (locTypeEl) {
                location_type = locTypeEl.value;
                const locValEl = document.getElementById('publicLocationValueInput');
                location_value = locValEl ? locValEl.value.trim() : '';
            } else {
                // Fixed type from hidden inputs
                const hiddenType = document.querySelector('input[name="location_type"]');
                const hiddenVal = document.querySelector('input[name="location_value"]');
                location_type = hiddenType ? hiddenType.value : 'fixed';
                location_value = hiddenVal ? hiddenVal.value : '';
            }

            const bookingData = {
                appointment_type_id: selectedType,
                service_type: typeName,
                appointment_date: selectedDate,
                appointment_time: selectedTime,
                client_name: document.getElementById('clientName').value,
                client_email: document.getElementById('clientEmail').value,
                client_phone: document.getElementById('clientPhone').value,
                dog_names: document.getElementById('dogNames').value,
                notes: document.getElementById('notes').value,
                // Default to 60 minutes if appointment type duration is not available
                duration_minutes: selectedTypeDuration ?? 60,
                location_type: location_type,
                location_value: location_value,
                use_credit: !!document.getElementById('useCreditToggle')?.checked,
                form_responses: collectFormResponses(),
                contract_template_id: document.querySelector('input[name="contract_template_id"]') ? parseInt(document.querySelector('input[name="contract_template_id"]').value) : null,
                contract_typed_name: document.getElementById('contractTypedName')?.value.trim() || null,
                contract_signature_font: document.getElementById('contractSignatureFont')?.value || null
            };
            
            fetch('api_bookings.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(bookingData)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (data.credit_applied) {
                        // Store the credit applied message for the success modal
                        const modalBody = document.querySelector('#successModal .modal-body p.text-muted');
                        if (modalBody) {
                            modalBody.innerHTML = 'Your appointment has been successfully booked and a credit has been applied. Check your email for confirmation details and calendar links.';
                        }
                    }
                    const modal = new bootstrap.Modal(document.getElementById('successModal'));
                    modal.show();
                } else {
                    showAlert(data.error || 'Booking failed. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    spinner.classList.remove('active');
                }
            })
            .catch(err => {
                showAlert('Network error. Please check your connection and try again.', 'danger');
                submitBtn.disabled = false;
                spinner.classList.remove('active');
            });
        }
        
        function showAlert(message, type) {
            const alertArea = document.getElementById('alertArea');
            alertArea.innerHTML = `
                <div class="alert alert-${type} alert-dismissible fade show">
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // Contract signature UI
        (function () {
            const typedNameEl  = document.getElementById('contractTypedName');
            const sigPreviewEl = document.getElementById('contractSigPreview');
            const fontInput    = document.getElementById('contractSignatureFont');
            const fontButtons  = document.querySelectorAll('#contractFontOptions .font-option-btn');

            if (!typedNameEl) return; // no contract required for this appointment type

            function updateContractPreview() {
                const name = typedNameEl.value.trim() || '\u00a0';
                sigPreviewEl.textContent = name;
                document.querySelectorAll('.contract-font-preview').forEach(function (span) {
                    span.textContent = typedNameEl.value.trim() || 'Your Name';
                });
            }

            function selectContractFont(btn) {
                fontButtons.forEach(function (b) { b.classList.remove('selected'); });
                btn.classList.add('selected');
                const font = btn.dataset.font;
                fontInput.value = font;
                sigPreviewEl.className = 'sig-preview ' + font;
            }

            typedNameEl.addEventListener('input', updateContractPreview);

            fontButtons.forEach(function (btn) {
                btn.addEventListener('click', function () { selectContractFont(btn); });
            });
        })();
    </script>
    <!-- Dark mode toggle (floating) -->
    <button id="darkModeToggle" class="btn btn-outline-secondary btn-sm position-fixed top-0 end-0 m-3 no-print" style="z-index:1100;" title="Toggle dark mode" aria-label="Toggle dark mode">
        <i class="fas fa-moon" id="darkModeIcon"></i>
    </button>
    <script>
    (function () {
        'use strict';
        function updateIcon() {
            var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            var icon = document.getElementById('darkModeIcon');
            if (icon) icon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
        }
        updateIcon();
        var btn = document.getElementById('darkModeToggle');
        if (btn) {
            btn.addEventListener('click', function () {
                var next = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-bs-theme', next);
                localStorage.setItem('bdta-theme', next);
                updateIcon();
            });
        }
    }());
    </script>
</body>
</html>
