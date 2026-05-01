<?php
/**
 * Public Booking Page
 * Allows clients to book appointments directly
 */
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/form_types.php';
require_once '../includes/public_portal_return.php';

/**
 * @return array<string, mixed>
 */
function public_book_map(mixed $value): array {
    return assoc_row($value);
}

/**
 * @param array<string, mixed>|null $row
 */
function public_book_string(?array $row, string|int $key, string $default = ''): string {
    return is_array($row) ? array_string_value($row, $key, $default) : $default;
}

/**
 * @param array<string, mixed>|null $row
 */
function public_book_int(?array $row, string|int $key, int $default = 0): int {
    return is_array($row) ? safe_int($row[$key] ?? $default) : $default;
}

/**
 * @return list<array<string, mixed>>
 */
function public_book_assoc_rows(mixed $value): array {
    if (is_string($value)) {
        return decode_json_assoc_list($value);
    }
    return assoc_rows($value);
}

/**
 * @return list<string>
 */
function public_book_string_list(mixed $value): array {
    if (!is_array($value)) {
        return [];
    }

    $strings = [];
    foreach ($value as $item) {
        if (is_scalar($item) || $item === null) {
            $strings[] = scalar_string($item);
        }
    }
    return $strings;
}

/**
 * @param array<string, string> $portal_profile
 */
function public_book_portal_prefill_value(array $portal_profile, string $mapping): string
{
    return match ($mapping) {
        'client.name' => $portal_profile['name'] ?? '',
        'client.email' => $portal_profile['email'] ?? '',
        'client.phone' => $portal_profile['phone'] ?? '',
        'client.address' => $portal_profile['address'] ?? '',
        default => '',
    };
}

$db = new Database();
$conn = $db->getConnection();
$portal_return = bdta_public_portal_return_path();
$portal_login_url = bdta_public_portal_login_url(
    bdta_public_current_path('/backend/public/book.php')
);
$portal_prefill_profile = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
];
$portal_prefill_pets = [];

if (isPortalLoggedIn()) {
    $portal_client_id = (int) portalClientId();
    if ($portal_client_id > 0) {
        $stmt = $conn->prepare("SELECT name, email, phone, address FROM clients WHERE id = ?");
        $stmt->execute([$portal_client_id]);
        $portal_client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($portal_client)) {
            $portal_prefill_profile = [
                'name' => public_book_string($portal_client, 'name'),
                'email' => public_book_string($portal_client, 'email'),
                'phone' => public_book_string($portal_client, 'phone'),
                'address' => public_book_string($portal_client, 'address'),
            ];
        }

        $stmt = $conn->prepare("
            SELECT id, name, species, breed, date_of_birth, age_years, age_months,
                   source, ownership_length_years, ownership_length_months,
                   spayed_neutered, vaccines_current
            FROM pets
            WHERE client_id = ? AND is_active = 1
            ORDER BY name
        ");
        $stmt->execute([$portal_client_id]);
        $portal_prefill_pets = public_book_assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}

// Get appointment type from URL - supports both numeric ID and unique link
$appointment_type_id = 0;
/** @var array<string, mixed>|null $selected_type */
$selected_type = null;
$is_standalone = false; // All appointment types are now standalone

// Check for unique link parameter first
if (isset($_GET['link']) && !empty($_GET['link'])) {
    $unique_link = scalar_string($_GET['link']);
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE unique_link = ? AND is_active = 1");
    $stmt->execute([$unique_link]);
    $selected_type = public_book_map($stmt->fetch(PDO::FETCH_ASSOC));
    if ($selected_type) {
        $appointment_type_id = public_book_int($selected_type, 'id');
        $is_standalone = true;
    }
}
// Also support numeric type ID as standalone
elseif (isset($_GET['type']) && !empty($_GET['type'])) {
    $appointment_type_id = safe_int($_GET['type']);
    $stmt = $conn->prepare("SELECT * FROM appointment_types WHERE id = ? AND is_active = 1");
    $stmt->execute([$appointment_type_id]);
    $selected_type = public_book_map($stmt->fetch(PDO::FETCH_ASSOC));
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
    $booking_intake_form = null;
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
        $req_row['fields'] = public_book_assoc_rows($req_row['fields'] ?? []);
    }
    unset($req_row);
    $required_forms = $req_rows;

    // Load required contract template for this appointment type
    $required_contract = null;
    if (!empty($selected_type['contract_template_id'])) {
        $stmt = $conn->prepare("SELECT id, name, template_text FROM contract_templates WHERE id = ? AND is_active = 1");
        $stmt->execute([public_book_int($selected_type, 'contract_template_id')]);
        $required_contract_row = $stmt->fetch(PDO::FETCH_ASSOC);
        $required_contract = is_array($required_contract_row) ? $required_contract_row : null;
    }

    // Load configured booking intake form (replaces hardcoded fields if set)
    $booking_intake_form = null;
    $stmt_bfid = $conn->prepare("SELECT setting_value FROM settings WHERE setting_key = 'default_booking_form_id'");
    $stmt_bfid->execute();
    $bfid_row = public_book_map($stmt_bfid->fetch(PDO::FETCH_ASSOC));
    $bfid = public_book_int($bfid_row, 'setting_value');
    if ($bfid > 0) {
        $stmt_bf = $conn->prepare("SELECT * FROM form_templates WHERE id = ? AND form_type = 'booking_form' AND is_active = 1");
        $stmt_bf->execute([$bfid]);
        $bf_row = public_book_map($stmt_bf->fetch(PDO::FETCH_ASSOC));
        if ($bf_row) {
            $bf_row['fields'] = public_book_assoc_rows($bf_row['fields'] ?? []);
            $booking_intake_form = $bf_row;
        }
    }
}

// Set page title based on booking type
if (isset($error_mode) && $error_mode) {
    $page_title = "Invalid Booking Link";
} elseif ($is_standalone && $selected_type) {
    $page_title = "Book " . public_book_string($selected_type, 'name');
} else {
    $page_title = "Book an Appointment";
}
$page_has_turnstile_widget = !isset($error_mode) || !$error_mode;
?>
<?php require_once __DIR__ . '/includes/public_head.php'; ?>
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Montserrat:wght@400;500;600;700&family=Dancing+Script:wght@700&family=Pacifico&family=Satisfy&family=Great+Vibes&family=Allura&display=swap" rel="stylesheet">
    
    <?php
    $theme_primary = scalar_string(Settings::get('theme_primary_color', '#9a0073'));
    $theme_primary_dark = scalar_string(Settings::get('theme_primary_dark_color', '#7a005a'));
    $theme_secondary = scalar_string(Settings::get('theme_secondary_color', '#0a9a9c'));
    $theme_accent = scalar_string(Settings::get('theme_accent_color', '#a39f89'));
    $tc_primary      = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary) ? $theme_primary : '#9a0073';
    $tc_primary_dark = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_primary_dark) ? $theme_primary_dark : '#7a005a';
    $tc_secondary    = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_secondary) ? $theme_secondary : '#0a9a9c';
    $tc_accent       = preg_match('/^#[0-9A-Fa-f]{6}$/', $theme_accent) ? $theme_accent : '#a39f89';
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

        .booking-subtitle {
            color: #4b5563;
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

        .pet-option {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 0.75rem 1rem;
            margin-bottom: 0.5rem;
            cursor: pointer;
            transition: all 0.2s ease;
            background: white;
        }

        .pet-option:hover {
            border-color: var(--primary-color);
            background: #eff6ff;
        }

        .pet-option.selected {
            border-color: var(--primary-color);
            background: #eff6ff;
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
        [data-bs-theme="dark"] .bdta-calendar { border-color: #374151; box-shadow: none; }
        [data-bs-theme="dark"] .bdta-cal-grid { background: #1f2937; }
        [data-bs-theme="dark"] .bdta-cal-dow  { background: #111827; color: #9ca3af; border-bottom-color: #374151; }
        [data-bs-theme="dark"] .bdta-cal-day.available { background: #052e16; border-color: #16a34a; color: #d1fae5; }
        [data-bs-theme="dark"] .bdta-cal-day.available:hover { background: var(--primary-color); border-color: var(--primary-color); color: #fff; }
        [data-bs-theme="dark"] .bdta-cal-day.unavailable { color: #4b5563; }
        [data-bs-theme="dark"] .bdta-cal-footer { background: #111827; border-top-color: #374151; color: #9ca3af; }
        [data-bs-theme="dark"] .bdta-cal-selected-label { background: #052e16; border-color: #16a34a; color: #d1fae5; }
        [data-bs-theme="dark"] body {
            background: linear-gradient(135deg, #1a1d23 0%, #0d1117 100%);
        }
        [data-bs-theme="dark"] .booking-header,
        [data-bs-theme="dark"] .booking-card {
            background: #1f2937;
            color: #e5e7eb;
        }
        [data-bs-theme="dark"] .booking-header h1 {
            color: #f5d0fe;
        }
        [data-bs-theme="dark"] .booking-subtitle {
            color: #d1d5db;
        }
        [data-bs-theme="dark"] .step-indicator::before {
            background: #374151;
        }
        [data-bs-theme="dark"] .step-circle {
            background: #374151;
            color: #d1d5db;
        }
        [data-bs-theme="dark"] .step-label {
            color: #9ca3af;
        }
        [data-bs-theme="dark"] .appointment-type-card {
            border-color: #374151;
            background: #111827;
        }
        [data-bs-theme="dark"] .appointment-type-card.selected {
            background: rgba(154, 0, 115, 0.18);
        }
        [data-bs-theme="dark"] .time-slot {
            background: #111827;
            border-color: #374151;
            color: #e5e7eb;
        }
        [data-bs-theme="dark"] .time-slot:hover {
            background: rgba(154, 0, 115, 0.18);
        }
        [data-bs-theme="dark"] .time-slot.selected {
            background: rgba(154, 0, 115, 0.35);
            border-color: #9a0073;
            color: #f5d0fe;
        }
        [data-bs-theme="dark"] .time-slot.unavailable {
            background: #1f2937;
            color: #6b7280;
        }
        [data-bs-theme="dark"] .pet-option {
            border-color: #374151;
            background: #111827;
            color: #e5e7eb;
        }
        [data-bs-theme="dark"] .pet-option.selected {
            background: rgba(154, 0, 115, 0.18);
            border-color: #9a0073;
            color: #f5d0fe;
        }
        [data-bs-theme="dark"] .alert-info {
            background: #172554;
            border-color: #1d4ed8;
            color: #bfdbfe;
        }
        [data-bs-theme="dark"] .sig-preview {
            color: #e5e7eb;
            border-bottom-color: #9ca3af;
        }
        [data-bs-theme="dark"] .font-option-btn {
            background: #111827;
            border-color: #374151;
            color: #e5e7eb;
        }
        [data-bs-theme="dark"] .font-option-btn.selected,
        [data-bs-theme="dark"] .font-option-btn:hover {
            background: rgba(154, 0, 115, 0.18);
        }
        [data-bs-theme="dark"] .bdta-cal-legend-dot.unavail {
            background: #374151;
            border-color: #374151;
        }
        [data-bs-theme="dark"] .booking-header .text-muted,
        [data-bs-theme="dark"] .booking-card .text-muted,
        [data-bs-theme="dark"] .booking-header .form-text,
        [data-bs-theme="dark"] .booking-card .form-text {
            color: #cbd5e1 !important;
        }
    </style>
</head>
<body>
    <div class="booking-container">
        <div class="booking-header">
            <?php if (isset($error_mode) && $error_mode): ?>
                <h1><i class="fas fa-exclamation-circle me-2"></i>Invalid Booking Link</h1>
                <p class="booking-subtitle mb-0">Please use a valid appointment type link to book</p>
            <?php elseif ($is_standalone && $selected_type): ?>
                <h1><i class="fas fa-calendar-check me-2"></i>Book <?= escape(public_book_string($selected_type, 'name')) ?></h1>
                <p class="booking-subtitle mb-0"><?= escape(public_book_string($selected_type, 'description')) ?></p>
                <?php if (!empty($selected_type['is_mini_session'])): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-location-dot me-3 mt-1" style="font-size: 1.5rem;"></i>
                            <div>
                                <h5 class="mb-2"><strong>Mini Sessions Event</strong></h5>
                                <?php if (!empty($selected_type['mini_session_topic'])): ?>
                                    <p class="mb-2"><strong>Topic:</strong> <?= escape(public_book_string($selected_type, 'mini_session_topic')) ?></p>
                                <?php endif; ?>
                                <p class="mb-0">
                                    <i class="fas fa-map-marker-alt me-2"></i>
                                    <strong>Location:</strong> <?= escape(public_book_string($selected_type, 'mini_session_location')) ?>
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
                                    <strong>Location:</strong> <?= escape(public_book_string($selected_type, 'group_class_location')) ?>
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
                                    <strong>Location:</strong> <?= escape(public_book_string($selected_type, 'field_rental_location')) ?>
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
                <p class="booking-subtitle mb-0">Schedule your dog training session with Brook's Dog Training Academy</p>
            <?php endif; ?>
            <?php if ($portal_return !== ''): ?>
                <div class="mt-3">
                    <a href="<?= escape($portal_return) ?>" class="btn btn-outline-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to Client Portal
                    </a>
                </div>
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
                <input type="hidden" name="appointment_type" value="<?= public_book_int($selected_type, 'id') ?>" id="standaloneType">
                
                <?php
                // Determine whether this appointment type is locked to specific date(s)
                $is_specific_date_type = false;
                $specific_date_value   = null;   // single date (legacy or 1-entry multi)
                $specific_dates_list   = [];     // all dates (multi-date)

                if (!empty($selected_type['schedule_type'])
                    && $selected_type['schedule_type'] === 'specific_date') {

                    // Try new multi-date format first
                    if (!empty($selected_type['specific_dates'])) {
                        $parsed_sd = public_book_assoc_rows($selected_type['specific_dates']);
                        if (!empty($parsed_sd)) {
                            // Only keep dates that are today or in the future
                            $today_str = date('Y-m-d');
                            $specific_dates_list = array_values(
                                array_filter($parsed_sd, fn(array $e): bool => ($date_value = array_string_value($e, 'date')) !== '' && $date_value >= $today_str)
                            );
                            usort($specific_dates_list, fn(array $a, array $b): int => array_string_value($a, 'date') <=> array_string_value($b, 'date'));
                            if (!empty($specific_dates_list)) {
                                $is_specific_date_type = true;
                                if (count($specific_dates_list) === 1) {
                                    $specific_date_value = array_string_value($specific_dates_list[0], 'date');
                                }
                            }
                        }
                    }

                    // Fall back to legacy single-date
                    if (!$is_specific_date_type
                        && !empty($selected_type['specific_date'])
                        && DateTime::createFromFormat('Y-m-d', public_book_string($selected_type, 'specific_date')) !== false) {
                        $is_specific_date_type = true;
                        $specific_date_value   = public_book_string($selected_type, 'specific_date');
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
                                   value="<?= htmlspecialchars((string)$specific_date_value) ?>"
                                   min="<?= htmlspecialchars((string)$specific_date_value) ?>"
                                   max="<?= htmlspecialchars((string)$specific_date_value) ?>"
                                   readonly>
                            <small class="text-muted mt-2 d-block">
                                <i class="fas fa-calendar-check me-1"></i>
                                This session is only available on <strong><?= date('F j, Y', safe_timestamp(strtotime((string)$specific_date_value))) ?></strong>.
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
                        <?php if ($booking_intake_form): ?>
                        <?php foreach (public_book_assoc_rows($booking_intake_form['fields']) as $bifi => $bifield):
                            $bi_req  = !empty($bifield['required']);
                            $bi_ph   = htmlspecialchars(array_string_value($bifield, 'placeholder'));
                            $bi_type = array_string_value($bifield, 'type', 'text');
                            $bi_map  = array_string_value($bifield, 'profile_mapping');
                            $bi_prefill = public_book_portal_prefill_value($portal_prefill_profile, $bi_map);
                            $bi_prefill_trimmed = trim($bi_prefill);
                            $bi_matches = static function (mixed $option) use ($bi_prefill_trimmed): bool {
                                return $bi_prefill_trimmed !== '' && $bi_prefill_trimmed === trim(scalar_string($option));
                            };
                            $bi_label = array_string_value($bifield, 'label');
                            $bi_description = array_string_value($bifield, 'description');
                            $bi_options = public_book_string_list($bifield['options'] ?? []);
                            $bi_fn   = 'booking_intake_' . $bifi;
                        ?>
                        <div class="col-12 mb-3">
                            <label class="form-label">
                                <?= htmlspecialchars($bi_label) ?>
                                <?php if ($bi_req): ?><span class="text-danger">*</span><?php endif; ?>
                            </label>
                                    <?php if ($bi_description !== ''): ?>
                                    <div class="form-text text-muted mb-1"><?= htmlspecialchars($bi_description) ?></div>
                                    <?php endif; ?>
                                    <?php switch ($bi_type):
                                        case bdta_pet_info_group_field_type():
                                            $bi_pet_group_config = bdta_form_field_pet_info_group_config($bifield);
                                            $bi_pet_group_config_json = json_encode($bi_pet_group_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                            $bi_existing_pets = isPortalLoggedIn()
                                                ? bdta_form_field_pet_info_group_prefill_pets($bifield, $portal_prefill_pets)
                                                : [];
                                            $bi_existing_pets_json = json_encode($bi_existing_pets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                            <div class="pet-info-group border rounded p-3 bg-light"
                                 data-booking-intake-field="<?= $bifi ?>"
                                  data-form-field-type="<?= htmlspecialchars($bi_type) ?>"
                                  data-pet-info-config="<?= htmlspecialchars($bi_pet_group_config_json === false ? '{}' : $bi_pet_group_config_json, ENT_QUOTES, 'UTF-8') ?>"
                                 data-pet-info-value="[]"
                                 data-existing-pets="<?= htmlspecialchars($bi_existing_pets_json === false ? '[]' : $bi_existing_pets_json, ENT_QUOTES, 'UTF-8') ?>"
                                 data-login-url="<?= htmlspecialchars(isPortalLoggedIn() ? '' : $portal_login_url, ENT_QUOTES, 'UTF-8') ?>">
                                <?php if (!isPortalLoggedIn()): ?>
                                <div class="small text-muted mb-3">
                                    Already a client with us?
                                    <a href="<?= htmlspecialchars($portal_login_url) ?>">Login</a>
                                    to skip the forms!
                                </div>
                                <?php endif; ?>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label" for="petCountBookingIntake<?= (int) $bifi ?>">Number of Pets <span class="text-danger">*</span></label>
                                        <input type="text" id="petCountBookingIntake<?= (int) $bifi ?>" inputmode="numeric" pattern="[0-9]*" class="form-control" data-pet-count value="1">
                                        <div class="form-text d-none" id="petCountBookingIntakeLimit<?= (int) $bifi ?>" data-pet-limit-message aria-live="polite"></div>
                                    </div>
                                    <div class="col-md-auto d-flex align-items-end">
                                        <button type="button" class="btn btn-outline-primary" data-add-pet-button>Add New Pet</button>
                                    </div>
                                </div>
                                <div class="mt-3 d-none" data-existing-pets-section></div>
                                <div class="mt-3" data-pet-list></div>
                            </div>
                            <?php break;
                                        case 'textarea': ?>
                            <textarea class="form-control form-control-lg"
                                      data-booking-intake-field="<?= $bifi ?>"
                                      data-profile-mapping="<?= htmlspecialchars($bi_map) ?>"
                                      placeholder="<?= $bi_ph ?>"
                                      rows="3"
                                      <?= $bi_req ? 'required' : '' ?>><?= htmlspecialchars($bi_prefill) ?></textarea>
                            <?php break; case 'select': ?>
                                <select class="form-select form-select-lg"
                                    data-booking-intake-field="<?= $bifi ?>"
                                    data-profile-mapping="<?= htmlspecialchars($bi_map) ?>"
                                    <?= $bi_req ? 'required' : '' ?>>
                                <option value="">— Select —</option>
                                <?php foreach ($bi_options as $bi_opt): ?>
                                    <option value="<?= htmlspecialchars($bi_opt) ?>" <?= $bi_matches($bi_opt) ? 'selected' : '' ?>><?= htmlspecialchars($bi_opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php break; case 'newsletter_opt_in':
                                $newsletter_choice = bdta_form_field_newsletter_checkbox_label();
                                $newsletter_checked = bdta_form_field_newsletter_is_opted_in($bi_prefill); ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       data-booking-intake-field="<?= $bifi ?>"
                                       data-profile-mapping="<?= htmlspecialchars($bi_map) ?>"
                                       id="<?= $bi_fn ?>_newsletter"
                                       value="<?= htmlspecialchars($newsletter_choice) ?>"
                                       <?= $newsletter_checked ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= $bi_fn ?>_newsletter"><?= htmlspecialchars($newsletter_choice) ?></label>
                            </div>
                            <?php break; case 'radio': ?>
                            <?php foreach ($bi_options as $bi_oi => $bi_opt): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="radio"
                                       data-booking-intake-field="<?= $bifi ?>"
                                       data-profile-mapping="<?= htmlspecialchars($bi_map) ?>"
                                       name="<?= $bi_fn ?>"
                                       id="<?= $bi_fn ?>_<?= $bi_oi ?>"
                                       value="<?= htmlspecialchars($bi_opt) ?>"
                                       <?= $bi_matches($bi_opt) ? 'checked' : '' ?>
                                       <?= ($bi_req && $bi_oi === 0) ? 'required' : '' ?>>
                                <label class="form-check-label" for="<?= $bi_fn ?>_<?= $bi_oi ?>"><?= htmlspecialchars($bi_opt) ?></label>
                            </div>
                            <?php endforeach;
                            break; case 'checkbox': ?>
                            <?php foreach ($bi_options as $bi_oi => $bi_opt): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                       data-booking-intake-field="<?= $bifi ?>"
                                       data-profile-mapping="<?= htmlspecialchars($bi_map) ?>"
                                       id="<?= $bi_fn ?>_<?= $bi_oi ?>"
                                       value="<?= htmlspecialchars($bi_opt) ?>"
                                       <?= $bi_matches($bi_opt) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="<?= $bi_fn ?>_<?= $bi_oi ?>"><?= htmlspecialchars($bi_opt) ?></label>
                            </div>
                            <?php endforeach;
                            break; default: ?>
                            <input type="<?= htmlspecialchars(in_array($bi_type, ['phone']) ? 'tel' : $bi_type) ?>"
                                    class="form-control form-control-lg"
                                    data-booking-intake-field="<?= $bifi ?>"
                                    data-profile-mapping="<?= htmlspecialchars($bi_map) ?>"
                                    placeholder="<?= $bi_ph ?>"
                                    value="<?= htmlspecialchars($bi_prefill) ?>"
                                    <?= $bi_req ? 'required' : '' ?>>
                            <?php break; endswitch; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Your Name *</label>
                            <input type="text" class="form-control form-control-lg" name="client_name" 
                                   id="clientName" required placeholder="John Doe" value="<?= escape($portal_prefill_profile['name']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" class="form-control form-control-lg" name="client_email" 
                                   id="clientEmail" required placeholder="john@example.com" value="<?= escape($portal_prefill_profile['email']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control form-control-lg" name="client_phone" 
                                   id="clientPhone" placeholder="(555) 123-4567" value="<?= escape($portal_prefill_profile['phone']) ?>">
                        </div>
                        <?php if (!isPortalLoggedIn()): ?>
                        <div class="col-12 mb-3">
                            <label class="form-label">Dog's Name(s)</label>
                            <input type="text" class="form-control form-control-lg" name="dog_names" 
                                    id="dogNames" placeholder="e.g., Max, Bella">
                            <small class="text-muted">If you have multiple dogs, separate with commas</small>
                        </div>
                        <?php endif; ?>
                        <div class="col-12 mb-3">
                            <label class="form-label">Additional Notes</label>
                            <textarea class="form-control" name="notes" id="notes" rows="3" 
                                       placeholder="Tell us about your dog's needs, behavior concerns, or any special requirements..."></textarea>
                        </div>
                        <?php if (isPortalLoggedIn()): ?>
                        <div class="col-12 mb-4">
                            <label class="form-label fw-bold">Which pet(s) is this booking for?</label>
                            <input type="hidden" name="dog_names" id="dogNames" data-portal-pet-field="1" value="">
                            <?php if ($portal_prefill_pets === []): ?>
                            <div class="alert alert-info py-2 mb-0">
                                <i class="fas fa-info-circle me-1"></i>
                                No pets on file. Manage pets in <a href="<?= escape(PORTAL_URL . 'pets.php') ?>">My Pets</a>.
                            </div>
                            <?php else: ?>
                            <div id="portalPetList">
                                <?php foreach ($portal_prefill_pets as $pet): ?>
                                <div class="pet-option d-flex align-items-center gap-2" data-pet-id="<?= public_book_int($pet, 'id') ?>">
                                    <input type="checkbox" class="form-check-input portal-pet-checkbox"
                                           id="portalPet<?= public_book_int($pet, 'id') ?>"
                                           data-pet-id="<?= public_book_int($pet, 'id') ?>"
                                           onchange="togglePortalPet(this)">
                                    <label class="mb-0 d-flex align-items-center gap-2 flex-grow-1" for="portalPet<?= public_book_int($pet, 'id') ?>">
                                        <span class="fw-semibold"><?= escape(public_book_string($pet, 'name')) ?></span>
                                    <?php if (public_book_string($pet, 'breed') !== ''): ?>
                                        <span class="text-muted small">(<?= escape(public_book_string($pet, 'breed')) ?>)</span>
                                    <?php endif; ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted">Manage pets in <a href="<?= escape(PORTAL_URL . 'pets.php') ?>">My Pets</a>.</small>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

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
                            $pub_decoded = public_book_string_list(decode_json_assoc(public_book_string($selected_type, 'location_types')));
                            if (!empty($pub_decoded)) {
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
                                        $fixed_loc = public_book_string($selected_type, 'mini_session_location');
                                    } elseif (!empty($selected_type['is_field_rental'])) {
                                        $fixed_loc = public_book_string($selected_type, 'field_rental_location');
                                    } else {
                                        $fixed_loc = public_book_string($selected_type, 'group_class_location');
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
                        <?php $form_id = public_book_int($form, 'id'); $form_fields = public_book_assoc_rows($form['fields'] ?? []); ?>
                        <div class="card mb-4" data-form-id="<?= $form_id ?>">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><?= htmlspecialchars(public_book_string($form, 'name')) ?></h6>
                                <?php if (public_book_string($form, 'description') !== ''): ?>
                                    <small class="text-muted"><?= htmlspecialchars(public_book_string($form, 'description')) ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <?php foreach ($form_fields as $fi => $field):
                                    $fn = 'form_resp_' . $form_id . '_' . $fi;
                                    $is_req = !empty($field['required']);
                                    $ph = htmlspecialchars(array_string_value($field, 'placeholder'));
                                    $field_label = array_string_value($field, 'label');
                                    $field_description = array_string_value($field, 'description');
                                    $field_type = array_string_value($field, 'type', 'text');
                                    $field_options = public_book_string_list($field['options'] ?? []);
                                ?>
                                <div class="mb-3">
                                    <?php if (bdta_form_field_is_display_only($field)): ?>
                                        <div class="p-3 rounded border bg-light">
                                            <div class="fw-semibold mb-1"><?= htmlspecialchars($field_label) ?></div>
                                            <?php $text_block_body = bdta_form_field_text_block_body($field); ?>
                                            <?php if ($text_block_body !== ''): ?>
                                                <div class="text-muted small"><?= nl2br(htmlspecialchars($text_block_body)) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        </div>
                                        <?php continue; ?>
                                    <?php endif; ?>
                                    <label class="form-label">
                                        <?= htmlspecialchars($field_label) ?>
                                        <?php if ($is_req): ?><span class="text-danger">*</span><?php endif; ?>
                                    </label>
                                    <?php if ($field_description !== ''): ?>
                                    <div class="form-text text-muted mb-1" id="field-desc-<?= $form_id ?>-<?= $fi ?>"><?= htmlspecialchars($field_description) ?></div>
                                    <?php endif; ?>
                                    <?php
                                    $aria = $field_description !== '' ? 'aria-describedby="field-desc-' . $form_id . '-' . $fi . '"' : '';
                                    switch ($field_type):
                                        case bdta_pet_info_group_field_type():
                                            $pet_group_config = bdta_form_field_pet_info_group_config($field);
                                            $pet_group_config_json = json_encode($pet_group_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                                            $pet_group_existing_pets = isPortalLoggedIn()
                                                ? bdta_form_field_pet_info_group_prefill_pets($field, $portal_prefill_pets)
                                                : [];
                                            $pet_group_existing_pets_json = json_encode($pet_group_existing_pets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                                        <div class="pet-info-group border rounded p-3 bg-light"
                                             data-form-field="<?= $fi ?>"
                                             data-form-field-type="<?= htmlspecialchars($field_type) ?>"
                                             data-pet-info-config="<?= htmlspecialchars($pet_group_config_json === false ? '{}' : $pet_group_config_json, ENT_QUOTES, 'UTF-8') ?>"
                                             data-pet-info-value="[]"
                                             data-existing-pets="<?= htmlspecialchars($pet_group_existing_pets_json === false ? '[]' : $pet_group_existing_pets_json, ENT_QUOTES, 'UTF-8') ?>"
                                             data-login-url="<?= htmlspecialchars(isPortalLoggedIn() ? '' : $portal_login_url, ENT_QUOTES, 'UTF-8') ?>">
                                            <?php if (!isPortalLoggedIn()): ?>
                                            <div class="small text-muted mb-3">
                                                Already a client with us?
                                                <a href="<?= htmlspecialchars($portal_login_url) ?>">Login</a>
                                                to skip the forms!
                                            </div>
                                            <?php endif; ?>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label" for="petCountRequiredForm<?= (int) $form_id ?>_<?= (int) $fi ?>">Number of Pets <span class="text-danger">*</span></label>
                                                    <input type="text" id="petCountRequiredForm<?= (int) $form_id ?>_<?= (int) $fi ?>" inputmode="numeric" pattern="[0-9]*" class="form-control" data-pet-count value="1">
                                                    <div class="form-text d-none" id="petCountRequiredFormLimit<?= (int) $form_id ?>_<?= (int) $fi ?>" data-pet-limit-message aria-live="polite"></div>
                                                </div>
                                                <div class="col-md-auto d-flex align-items-end">
                                                    <button type="button" class="btn btn-outline-primary" data-add-pet-button>Add New Pet</button>
                                                </div>
                                            </div>
                                            <div class="mt-3 d-none" data-existing-pets-section></div>
                                            <div class="mt-3" data-pet-list></div>
                                        </div>
                                        <?php break;
                                        case 'textarea': ?>
                                        <textarea class="form-control" data-form-field="<?= $fi ?>"
                                                  placeholder="<?= $ph ?>"
                                                  <?= $aria ?>
                                                  <?= $is_req ? 'required' : '' ?>></textarea>
                                        <?php break; case 'select': ?>
                                        <select class="form-select" data-form-field="<?= $fi ?>" <?= $aria ?> <?= $is_req ? 'required' : '' ?>>
                                            <option value="">— Select —</option>
                                            <?php foreach ($field_options as $opt): ?>
                                                <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php break; case 'newsletter_opt_in':
                                            $newsletter_choice = bdta_form_field_newsletter_checkbox_label(); ?>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox"
                                                   data-form-field="<?= $fi ?>"
                                                   data-form-field-type="<?= htmlspecialchars($field_type) ?>"
                                                   id="<?= $fn ?>_newsletter"
                                                   value="<?= htmlspecialchars($newsletter_choice) ?>"
                                                   <?= $aria ?>>
                                            <label class="form-check-label" for="<?= $fn ?>_newsletter"><?= htmlspecialchars($newsletter_choice) ?></label>
                                        </div>
                                        <?php break; case 'radio': ?>
                                        <?php foreach ($field_options as $oi => $opt): ?>
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
                                        <?php foreach ($field_options as $oi => $opt): ?>
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
                                        <input type="<?= htmlspecialchars($field_type) ?>"
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
                            <h6 class="mb-0"><?= htmlspecialchars(public_book_string($required_contract, 'name')) ?></h6>
                        </div>
                        <div class="card-body">
                            <div class="border rounded p-3 mb-4 bg-white" style="max-height: 300px; overflow-y: auto; font-size: 0.9rem;"><?= public_book_string($required_contract, 'template_text') ?></div>

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

                            <input type="hidden" name="contract_template_id" value="<?= public_book_int($required_contract, 'id') ?>">
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
                    
                    <?php echo bdta_get_turnstile_widget_markup(['wrapper_class' => 'mt-4']); ?>
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
    
    <!-- Profile Overwrite Confirmation Modal -->
    <div class="modal fade" id="profileOverwriteModal" tabindex="-1" aria-labelledby="profileOverwriteModalLabel">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="profileOverwriteModalLabel">
                        <i class="fas fa-user-pen me-2"></i>Update Your Profile?
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">The following form answers differ from what's currently saved in your profile. Would you like to update your profile with the new values?</p>
                    <p class="small text-muted mb-3">If you keep your existing profile, your saved client details will stay unchanged and any conflicting pet answers will be saved to a new pet profile for this booking.</p>
                    <div id="bookOverwriteConflictList"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Keep Existing</button>
                    <button type="button" class="btn btn-primary" id="bookConfirmOverwriteBtn">
                        <i class="fas fa-check me-1"></i>Yes, Update Profile
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="modal fade" id="successModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-5">
                    <div class="mb-4">
                        <i class="fas fa-check-circle-fill text-success" style="font-size: 4rem;"></i>
                    </div>
                    <h2 class="mb-3">Booking Submitted!</h2>
                    <p class="text-muted mb-4">Your booking details have been received.</p>
                    <a href="<?= escape($portal_return !== '' ? $portal_return : '/') ?>" class="btn btn-primary btn-lg">
                        <?= $portal_return !== '' ? 'Back to Client Portal' : 'Back to Home' ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    // These specific-date values are populated in the booking form block above
    // when a selected appointment type has specific-date scheduling enabled.
    // Default them here as well so the JS export remains defined when no type
    // has been selected yet or the specific-date block did not run.
    $is_specific_date_type = $is_specific_date_type ?? false;
    $specific_date_value = $specific_date_value ?? null;
    $is_multi_specific_date = $is_multi_specific_date ?? false;
    $js_type_id = $selected_type ? public_book_int($selected_type, 'id') : 'null';
    $js_type_name = $selected_type ? json_encode(public_book_string($selected_type, 'name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) : 'null';
    $selected_type_duration = $selected_type ? public_book_int($selected_type, 'duration_minutes') : 0;
    $js_type_duration = ($selected_type && $selected_type_duration > 0)
        ? $selected_type_duration
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
        const requiredFieldSelector = '.form-step input[required], .form-step select[required], .form-step textarea[required]';

        // Form profile mappings: formId -> fieldIndex -> mapping string
        const formFieldMappings = <?= (function() use ($required_forms) {
            $map = [];
            foreach ($required_forms as $form) {
                $fmap = [];
                foreach (public_book_assoc_rows($form['fields'] ?? []) as $fi => $field) {
                    $profile_mapping = array_string_value($field, 'profile_mapping');
                    if ($profile_mapping !== '') {
                        $fmap[$fi] = $profile_mapping;
                    }
                }
                if (!empty($fmap)) $map[public_book_int($form, 'id')] = $fmap;
            }
            return json_encode($map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        })() ?>;

        // Booking intake form fields configuration (null = use built-in hardcoded fields)
        const bookingIntakeFields = <?= ($booking_intake_form && !empty($booking_intake_form['fields']))
            ? json_encode(public_book_assoc_rows($booking_intake_form['fields']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            : 'null' ?>;
        const bookingIntakeFormId = <?= ($booking_intake_form) ? public_book_int($booking_intake_form, 'id') : 'null' ?>;
        const portalPetNames = {};
        <?php foreach ($portal_prefill_pets as $pet): ?>
        portalPetNames[<?= public_book_int($pet, 'id') ?>] = <?= json_encode(public_book_string($pet, 'name'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        <?php endforeach; ?>

        function normalizeMappedFormValue(value) {
            if (Array.isArray(value)) {
                return value
                    .filter(item => item !== null && item !== undefined && item !== '')
                    .map(item => String(item))
                    .join(', ')
                    .trim();
            }
            return String(value ?? '').trim();
        }

        function parsePetInfoConfig(rawConfig) {
            if (!rawConfig) return {};
            try {
                return JSON.parse(rawConfig) || {};
            } catch (err) {
                return {};
            }
        }

        function parsePetInfoValue(rawValue) {
            if (!rawValue) return [];
            try {
                const parsed = JSON.parse(rawValue);
                return Array.isArray(parsed) ? parsed : [];
            } catch (err) {
                return [];
            }
        }

        function collectPetInfoGroupResponse(group) {
            if (!group) return [];
            return Array.from(group.querySelectorAll('[data-pet-row]')).map(function (row) {
                return {
                    existing_pet_id: Number.parseInt(String(row.querySelector('[data-pet-existing-id]')?.value || '0'), 10) || 0,
                    name: row.querySelector('[data-pet-attr="name"]')?.value.trim() || '',
                    age_or_dob: row.querySelector('[data-pet-attr="age_or_dob"]')?.value.trim() || '',
                    breed: row.querySelector('[data-pet-attr="breed"]')?.value.trim() || '',
                    vaccines_current: row.querySelector('[data-pet-attr="vaccines_current"]')?.value || '',
                    spayed_neutered: row.querySelector('[data-pet-attr="spayed_neutered"]')?.value || '',
                    source: row.querySelector('[data-pet-attr="source"]')?.value.trim() || '',
                    ownership_length: row.querySelector('[data-pet-attr="ownership_length"]')?.value.trim() || '',
                    species: row.querySelector('[data-pet-attr="species"]')?.value.trim() || ''
                };
            });
        }

        function getPetInfoGroupPetNames(value) {
            if (!Array.isArray(value)) return [];
            return value
                .map(function (pet) { return (pet && pet.name ? String(pet.name).trim() : ''); })
                .filter(Boolean);
        }

        function escapePetInfoHtml(value) {
            return String(value || '').replace(/[&<>"']/g, function (char) {
                return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char] || char;
            });
        }

        function renderPetInfoGroup(group) {
            if (!group) return;
            const config = parsePetInfoConfig(group.dataset.petInfoConfig || '{}');
            const countInput = group.querySelector('[data-pet-count]');
            const list = group.querySelector('[data-pet-list]');
            const addPetButton = group.querySelector('[data-add-pet-button]');
            const existingPetsSection = group.querySelector('[data-existing-pets-section]');
            const limitMessage = group.querySelector('[data-pet-limit-message]');
            if (!countInput || !list) return;

            function parseExistingPets() {
                try {
                    const parsed = JSON.parse(group.dataset.existingPets || '[]');
                    return Array.isArray(parsed) ? parsed : [];
                } catch (err) {
                    return [];
                }
            }

            function petIdValue(value) {
                return Number.parseInt(String(value || '0'), 10) || 0;
            }

            function sanitizePetCountInput() {
                countInput.value = countInput.value.replace(/[^\d]/g, '');
            }

            function petLimitMessageText(configuredMax) {
                return 'This session allows up to ' + configuredMax + ' pet' + (configuredMax === 1 ? '' : 's') + '.';
            }

            function clonePet(pet) {
                return {
                    existing_pet_id: petIdValue(pet?.existing_pet_id),
                    name: String(pet?.name || ''),
                    age_or_dob: String(pet?.age_or_dob || ''),
                    breed: String(pet?.breed || ''),
                    vaccines_current: String(pet?.vaccines_current || ''),
                    spayed_neutered: String(pet?.spayed_neutered || ''),
                    source: String(pet?.source || ''),
                    ownership_length: String(pet?.ownership_length || ''),
                    species: String(pet?.species || '')
                };
            }

            function blankPet() {
                return {
                    existing_pet_id: 0,
                    name: '',
                    age_or_dob: '',
                    breed: '',
                    vaccines_current: '',
                    spayed_neutered: '',
                    source: '',
                    ownership_length: '',
                    species: config.default_species || (config.dog_only_species ? 'Dog' : '')
                };
            }

            function maxPets() {
                const parsed = Number.parseInt(String(config.max_pets || '0'), 10) || 0;
                return parsed > 0 ? parsed : 0;
            }

            const existingPets = parseExistingPets().map(clonePet);
            if (!Array.isArray(group.bdtaSelectedExistingPetIds)) {
                group.bdtaSelectedExistingPetIds = [];
                const initialPets = parsePetInfoValue(group.dataset.petInfoValue || '[]').map(clonePet);
                const initialExistingIds = initialPets.map(pet => petIdValue(pet.existing_pet_id)).filter(Boolean);
                if (initialExistingIds.length > 0) {
                    group.bdtaSelectedExistingPetIds = initialExistingIds;
                } else if (initialPets.length === 0 && existingPets.length > 0) {
                    const allowed = maxPets();
                    group.bdtaSelectedExistingPetIds = existingPets
                        .slice(0, allowed > 0 ? allowed : existingPets.length)
                        .map(pet => petIdValue(pet.existing_pet_id))
                        .filter(Boolean);
                    if (group.bdtaSelectedExistingPetIds.length > 0) {
                        group.dataset.petInfoValue = JSON.stringify(group.bdtaSelectedExistingPetIds
                            .map(id => existingPets.find(pet => petIdValue(pet.existing_pet_id) === id) || null)
                            .filter(Boolean));
                    }
                }
            }

            const selectedExistingIds = group.bdtaSelectedExistingPetIds;
            const currentPets = list.children.length > 0
                ? collectPetInfoGroupResponse(group).map(clonePet)
                : parsePetInfoValue(group.dataset.petInfoValue || '[]').map(clonePet);
            const selectedExistingPets = selectedExistingIds
                .map(function (petId) {
                    return currentPets.find(function (pet) { return petIdValue(pet.existing_pet_id) === petId; })
                        || existingPets.find(function (pet) { return petIdValue(pet.existing_pet_id) === petId; })
                        || null;
                })
                .filter(Boolean)
                .map(clonePet);
            const newPets = currentPets
                .filter(function (pet) { return petIdValue(pet.existing_pet_id) <= 0; })
                .map(clonePet);

            let requestedCount = Number.parseInt(String(countInput.value || ''), 10);
            if (!Number.isFinite(requestedCount) || requestedCount <= 0) {
                requestedCount = Math.max(1, currentPets.length || selectedExistingPets.length || 1);
            }
            requestedCount = Math.max(requestedCount, selectedExistingPets.length || 1);
            const configuredMax = maxPets();
            if (configuredMax > 0) {
                requestedCount = Math.min(requestedCount, configuredMax);
            }
            countInput.value = String(requestedCount);

            const pets = selectedExistingPets.slice(0, requestedCount);
            while (pets.length < requestedCount) {
                pets.push(newPets.shift() || blankPet());
            }
            group.dataset.petInfoValue = JSON.stringify(pets);

            if (limitMessage) {
                if (configuredMax > 0) {
                    limitMessage.classList.remove('d-none');
                    limitMessage.textContent = petLimitMessageText(configuredMax);
                } else {
                    limitMessage.classList.add('d-none');
                    limitMessage.textContent = '';
                }
            }
            if (addPetButton) {
                addPetButton.disabled = configuredMax > 0 && requestedCount >= configuredMax;
            }
            if (existingPetsSection) {
                if (existingPets.length === 0) {
                    existingPetsSection.classList.add('d-none');
                    existingPetsSection.innerHTML = '';
                } else {
                    existingPetsSection.classList.remove('d-none');
                    existingPetsSection.innerHTML = `
                        <div class="small fw-semibold mb-2">Pets already on file</div>
                        <div class="d-flex flex-column gap-2">
                            ${existingPets.map(function (pet) {
                                const petId = petIdValue(pet.existing_pet_id);
                                const isSelected = selectedExistingIds.includes(petId);
                                const disableUnchecked = configuredMax > 0 && !isSelected && selectedExistingIds.length >= configuredMax;
                                return `
                                    <label class="form-check border rounded px-3 py-2 bg-white">
                                        <input class="form-check-input me-2" type="checkbox" data-existing-pet-checkbox value="${petId}" ${isSelected ? 'checked' : ''} ${disableUnchecked ? 'disabled' : ''}>
                                        <span class="fw-semibold">${escapePetInfoHtml(pet.name || 'Pet')}</span>
                                        ${pet.breed ? `<span class="text-muted small">(${escapePetInfoHtml(pet.breed)})</span>` : ''}
                                    </label>
                                `;
                            }).join('')}
                        </div>
                    `;
                    existingPetsSection.querySelectorAll('[data-existing-pet-checkbox]').forEach(function (checkbox) {
                        checkbox.addEventListener('change', function () {
                            const petId = petIdValue(checkbox.value);
                            if (checkbox.checked) {
                                if (!group.bdtaSelectedExistingPetIds.includes(petId)) {
                                    group.bdtaSelectedExistingPetIds.push(petId);
                                }
                            } else {
                                group.bdtaSelectedExistingPetIds = group.bdtaSelectedExistingPetIds.filter(function (id) { return id !== petId; });
                            }
                            renderPetInfoGroup(group);
                        });
                    });
                }
            }

            list.innerHTML = pets.map(function (pet, index) {
                const speciesField = config.include_species
                    ? (config.dog_only_species
                        ? `<div class="col-md-6">
                                <label class="form-label">Species</label>
                                <input type="text" class="form-control" value="Dog" readonly>
                                <input type="hidden" value="Dog" data-pet-attr="species">
                           </div>`
                        : `<div class="col-md-6">
                                <label class="form-label">Species</label>
                                <input type="text" class="form-control" value="${escapePetInfoHtml(pet.species || config.default_species || '')}" data-pet-attr="species">
                           </div>`)
                    : '';
                return `
                    <div class="card mb-3" data-pet-row>
                        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                            <span>Pet ${index + 1}</span>
                            ${petIdValue(pet.existing_pet_id) > 0 ? '<span class="badge text-bg-secondary">On File</span>' : ''}
                        </div>
                        <div class="card-body">
                            <input type="hidden" value="${petIdValue(pet.existing_pet_id)}" data-pet-existing-id>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Pet Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" value="${escapePetInfoHtml(pet.name)}" data-pet-attr="name" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Age or Date of Birth <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" value="${escapePetInfoHtml(pet.age_or_dob)}" data-pet-attr="age_or_dob" placeholder="e.g. 2 years, 6 months or 2021-04-15" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Breed <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" value="${escapePetInfoHtml(pet.breed)}" data-pet-attr="breed" required>
                                    <div class="form-text">If breed is unknown, describe the pet’s color, pattern, or identifying features.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Vaccine Status <span class="text-danger">*</span></label>
                                    <select class="form-select" data-pet-attr="vaccines_current" required>
                                        <option value="">— Select —</option>
                                        <option value="yes" ${pet.vaccines_current === 'yes' ? 'selected' : ''}>Current</option>
                                        <option value="no" ${pet.vaccines_current === 'no' ? 'selected' : ''}>Not Current</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Spay/Neuter Status <span class="text-danger">*</span></label>
                                    <select class="form-select" data-pet-attr="spayed_neutered" required>
                                        <option value="">— Select —</option>
                                        <option value="yes" ${pet.spayed_neutered === 'yes' ? 'selected' : ''}>Yes, spayed/neutered</option>
                                        <option value="no" ${pet.spayed_neutered === 'no' ? 'selected' : ''}>No, intact</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Where did you acquire this pet from? <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" value="${escapePetInfoHtml(pet.source)}" data-pet-attr="source" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">How long have you had this pet? <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" value="${escapePetInfoHtml(pet.ownership_length)}" data-pet-attr="ownership_length" placeholder="e.g. 1 year, 3 months" required>
                                </div>
                                ${speciesField}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function initPetInfoGroups() {
            document.querySelectorAll('.pet-info-group').forEach(function (group) {
                const countInput = group.querySelector('[data-pet-count]');
                if (!countInput || countInput.dataset.petInfoBound === '1') return;
                countInput.dataset.petInfoBound = '1';
                countInput.addEventListener('input', function () {
                    sanitizePetCountInput();
                });
                countInput.addEventListener('change', function () {
                    renderPetInfoGroup(group);
                });
                countInput.addEventListener('blur', function () {
                    renderPetInfoGroup(group);
                });
                const addPetButton = group.querySelector('[data-add-pet-button]');
                if (addPetButton) {
                    addPetButton.addEventListener('click', function () {
                        const currentCount = Number.parseInt(String(countInput.value || '0'), 10)
                            || collectPetInfoGroupResponse(group).length
                            || 0;
                        countInput.value = String(currentCount + 1);
                        renderPetInfoGroup(group);
                    });
                }
                renderPetInfoGroup(group);
            });
        }

        /**
         * Collect values from the dynamic booking intake form fields.
         * Returns an object with extracted profile values and a raw field index map.
         */
        function getBookingIntakeValues() {
            if (!bookingIntakeFields) return null;
            const result = {
                client_name: '',
                client_email: '',
                client_phone: '',
                client_address: '',
                dog_names: '',
                notes: '',
                intake_field_values: {}
            };
            bookingIntakeFields.forEach(function(field, fi) {
                let val = '';
                if (field.type === '<?= bdta_pet_info_group_field_type() ?>') {
                    const group = document.querySelector('.pet-info-group[data-booking-intake-field="' + fi + '"]');
                    val = collectPetInfoGroupResponse(group);
                } else if (field.type === 'checkbox') {
                    const checked = document.querySelectorAll('[data-booking-intake-field="' + fi + '"]:checked');
                    val = normalizeMappedFormValue(Array.from(checked).map(function(c) { return c.value; }));
                } else if (field.type === 'newsletter_opt_in') {
                    const checked = document.querySelector('[data-booking-intake-field="' + fi + '"]:checked');
                    val = normalizeMappedFormValue(checked ? checked.value : '');
                } else if (field.type === 'radio') {
                    const checked = document.querySelector('[data-booking-intake-field="' + fi + '"]:checked');
                    val = normalizeMappedFormValue(checked ? checked.value : '');
                } else {
                    const el = document.querySelector('[data-booking-intake-field="' + fi + '"]');
                    val = normalizeMappedFormValue(el ? el.value : '');
                }
                result.intake_field_values[fi] = val;
                const mapping = field.profile_mapping || '';
                if (mapping === 'client.name')  result.client_name  = val;
                if (mapping === 'client.email') result.client_email = val;
                if (mapping === 'client.phone') result.client_phone = val;
                if (mapping === 'client.address') result.client_address = val;
                if (mapping === 'pet_1.name')   result.dog_names    = val;
                if (mapping === 'booking.notes') result.notes       = val;
                if (field.type === '<?= bdta_pet_info_group_field_type() ?>' && !result.dog_names) {
                    result.dog_names = getPetInfoGroupPetNames(val).join(', ');
                }
            });
            return result;
        }

        function getMappedFormValues(formResponses) {
            const result = {
                client_name: '',
                client_email: '',
                client_phone: '',
                client_address: '',
                dog_names: '',
                notes: '',
            };

            for (const [formId, fieldMaps] of Object.entries(formFieldMappings)) {
                const responses = formResponses[formId] || {};
                for (const [fi, mapping] of Object.entries(fieldMaps)) {
                    const val = normalizeMappedFormValue(responses[fi]);
                    if (!val) continue;
                    if (mapping === 'client.name' && !result.client_name) result.client_name = val;
                    if (mapping === 'client.email' && !result.client_email) result.client_email = val;
                    if (mapping === 'client.phone' && !result.client_phone) result.client_phone = val;
                    if (mapping === 'client.address' && !result.client_address) result.client_address = val;
                    if (mapping === 'pet_1.name' && !result.dog_names) result.dog_names = val;
                    if (mapping === 'booking.notes' && !result.notes) result.notes = val;
                }
            }

            if (!result.dog_names) {
                for (const responses of Object.values(formResponses || {})) {
                    if (!responses || typeof responses !== 'object') continue;
                    for (const value of Object.values(responses)) {
                        const petNames = getPetInfoGroupPetNames(value);
                        if (petNames.length > 0) {
                            result.dog_names = petNames.join(', ');
                            return result;
                        }
                    }
                }
            }

            return result;
        }

        function mergeProfileMappedValues(primaryValues = null, fallbackValues = null) {
            const primary = primaryValues || {};
            const fallback = fallbackValues || {};
            return {
                client_name: primary.client_name || fallback.client_name || '',
                client_email: primary.client_email || fallback.client_email || '',
                client_phone: primary.client_phone || fallback.client_phone || '',
                client_address: primary.client_address || fallback.client_address || '',
                dog_names: primary.dog_names || fallback.dog_names || '',
                notes: primary.notes || fallback.notes || '',
            };
        }

        // Loaded on confirm step from profile lookup API
        let currentClientProfile = {};
        let currentPetProfiles   = []; // ordered by dog_names

        // Pending booking payload waiting for overwrite confirmation
        let pendingBookingPayload = null;

        function getSelectedPortalPetIds() {
            return Array.from(document.querySelectorAll('.portal-pet-checkbox'))
                .filter(function (cb) { return cb.checked; })
                .map(function (cb) { return parseInt(cb.dataset.petId, 10); })
                .filter(function (petId) { return !isNaN(petId) && petId > 0; });
        }

        function getSelectedPortalPetNames() {
            return getSelectedPortalPetIds().map(function (petId) {
                return portalPetNames[petId] || ('Pet #' + petId);
            });
        }

        function syncSelectedPortalPets() {
            const dogNamesInput = document.querySelector('#dogNames[data-portal-pet-field="1"]');
            if (dogNamesInput) {
                dogNamesInput.value = getSelectedPortalPetNames().join(', ');
            }
        }

        window.togglePortalPet = function (checkbox) {
            const petOption = checkbox ? checkbox.closest('.pet-option') : null;
            if (petOption) {
                petOption.classList.toggle('selected', !!checkbox.checked);
            }
            syncSelectedPortalPets();
        };

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
                // Disable native browser validation so hidden steps don't block submit silently
                bookingForm.setAttribute('novalidate', 'novalidate');
                // Form submission
                bookingForm.addEventListener('submit', submitBooking);
            }

            document.querySelectorAll('.portal-pet-checkbox').forEach(function (checkbox) {
                window.togglePortalPet(checkbox);
            });
            syncSelectedPortalPets();
            initPetInfoGroups();
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
                if (bookingIntakeFields) {
                    // Dynamic form: validate all required fields
                    let validationPassed = true;
                    let hasEmail = false;
                    for (let fi = 0; fi < bookingIntakeFields.length; fi++) {
                        const field = bookingIntakeFields[fi];
                        const isReq = !!field.required;
                        let val = '';
                        if (field.type === '<?= bdta_pet_info_group_field_type() ?>') {
                            const group = document.querySelector('.pet-info-group[data-booking-intake-field="' + fi + '"]');
                            val = collectPetInfoGroupResponse(group);
                        } else if (field.type === 'checkbox') {
                            const checked = document.querySelectorAll('[data-booking-intake-field="' + fi + '"]:checked');
                            val = checked.length > 0 ? 'ok' : '';
                        } else if (field.type === 'newsletter_opt_in') {
                            const checked = document.querySelector('[data-booking-intake-field="' + fi + '"]:checked');
                            val = checked ? checked.value : '';
                        } else if (field.type === 'radio') {
                            const checked = document.querySelector('[data-booking-intake-field="' + fi + '"]:checked');
                            val = checked ? checked.value : '';
                        } else {
                            const el = document.querySelector('[data-booking-intake-field="' + fi + '"]');
                            val = el ? el.value.trim() : '';
                        }
                        if (field.profile_mapping === 'client.email' && val) hasEmail = true;
                        if (isReq && !val) {
                            showAlert('Please fill in: ' + field.label, 'warning');
                            const focusEl = document.querySelector('[data-booking-intake-field="' + fi + '"]');
                            if (focusEl) focusEl.focus();
                            validationPassed = false;
                            break;
                        }
                    }
                    if (!validationPassed) return;
                    if (!hasEmail) {
                        // No email-mapped field filled — booking cannot proceed
                        showAlert('An email address is required to complete your booking. Please fill in your email.', 'warning');
                        return;
                    }
                } else {
                    const name = document.getElementById('clientName').value.trim();
                    const email = document.getElementById('clientEmail').value.trim();
                    if (!name || !email) {
                        showAlert('Please fill in your name and email', 'warning');
                        return;
                    }
                }
                if (!ensureRequiredFieldsValid()) {
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
        
        function getLocationSummary(mappedFormValues = null) {
            const resolvedMappedFormValues = mappedFormValues || getMappedFormValues(collectFormResponses());
            const locTypeEl = document.getElementById('publicLocationType');
            if (!locTypeEl) {
                // Fixed type — find the hidden input
                const hiddenType = document.querySelector('input[name="location_type"]');
                const hiddenVal = document.querySelector('input[name="location_value"]');
                const hiddenTypeValue = hiddenType ? hiddenType.value : '';
                if (hiddenTypeValue === 'client_address') {
                    return resolvedMappedFormValues.client_address || 'My registered address';
                }
                if (hiddenTypeValue === 'custom_address' || hiddenTypeValue === 'webcall') {
                    return hiddenVal && hiddenVal.value ? hiddenVal.value : 'Not specified';
                }
                return hiddenVal && hiddenVal.value ? hiddenVal.value : 'Fixed location';
            }
            const type = locTypeEl.value;
            const labels = {
                'client_address': resolvedMappedFormValues.client_address || 'My registered address',
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

            const formResponses = collectFormResponses();
            const mappedFormValues = getMappedFormValues(formResponses);
            const intakeMappedValues = bookingIntakeFields ? getBookingIntakeValues() : null;
            const combinedMappedValues = mergeProfileMappedValues(intakeMappedValues, mappedFormValues);
            const selectedPortalPetNames = getSelectedPortalPetNames();
            const portalDogNames = selectedPortalPetNames.join(', ');
            let email = '', dogNames = '';
            if (bookingIntakeFields) {
                const confirmName = combinedMappedValues.client_name;
                const confirmEmail = combinedMappedValues.client_email;
                const confirmPhone = combinedMappedValues.client_phone;
                const confirmDogs = portalDogNames || combinedMappedValues.dog_names;
                document.getElementById('confirmName').textContent  = confirmName || 'Not provided';
                document.getElementById('confirmEmail').textContent = confirmEmail || 'Not provided';
                document.getElementById('confirmPhone').textContent = confirmPhone || 'Not provided';
                document.getElementById('confirmDogs').textContent  = confirmDogs  || 'Not specified';
                email    = String(confirmEmail || '').trim();
                dogNames = String(confirmDogs || '').trim();
            } else {
                const confirmName = document.getElementById('clientName').value || mappedFormValues.client_name;
                const confirmEmail = document.getElementById('clientEmail').value || mappedFormValues.client_email;
                const confirmPhone = document.getElementById('clientPhone').value || mappedFormValues.client_phone;
                const confirmDogs = portalDogNames || document.getElementById('dogNames').value || mappedFormValues.dog_names;
                document.getElementById('confirmName').textContent  = confirmName || 'Not provided';
                document.getElementById('confirmEmail').textContent = confirmEmail || 'Not provided';
                document.getElementById('confirmPhone').textContent = confirmPhone || 'Not provided';
                document.getElementById('confirmDogs').textContent  = confirmDogs  || 'Not specified';
                email    = String(confirmEmail || '').trim();
                dogNames = String(confirmDogs || '').trim();
            }
            document.getElementById('confirmLocation').textContent = getLocationSummary(combinedMappedValues) || 'Not specified';

            const creditToggleArea    = document.getElementById('creditToggleArea');
            const creditRemainingNote = document.getElementById('creditRemainingNote');

            // Load credits and current profile data in parallel
            if (email && selectedType) {
                fetch(`api_bookings.php?action=credits&email=${encodeURIComponent(email)}&appointment_type_id=${selectedType}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.credits && data.credits.length > 0) {
                            const totalRemaining = data.credits.reduce((sum, c) => sum + parseInt(c.remaining), 0);
                            creditRemainingNote.textContent = `You have ${totalRemaining} credit(s) available for this appointment type.`;
                            creditToggleArea.classList.remove('d-none');
                        } else {
                            creditToggleArea.classList.add('d-none');
                            document.getElementById('useCreditToggle').checked = false;
                        }
                    })
                    .catch(() => { creditToggleArea.classList.add('d-none'); });

                // Load client+pet profiles for pre-submit conflict detection
                if (Object.keys(formFieldMappings).length > 0 || document.querySelector('.pet-info-group')) {
                    fetch(`api_bookings.php?action=profile&email=${encodeURIComponent(email)}&dog_names=${encodeURIComponent(dogNames)}`)
                        .then(r => r.json())
                        .then(data => {
                            currentClientProfile = data.client || {};
                            currentPetProfiles   = data.pets  || [];
                        })
                        .catch(() => {
                            currentClientProfile = {};
                            currentPetProfiles   = [];
                        });
                }
            } else {
                creditToggleArea.classList.add('d-none');
                currentClientProfile = {};
                currentPetProfiles   = [];
            }
        }

        function getProfileConflicts(formResponses) {
            const conflicts = [];
            const profileLabels = {
                'client.name':    'Your Name',
                'client.email':   'Your Email',
                'client.phone':   'Your Phone',
                'client.address': 'Your Address',
            };
            for (let p = 1; p <= 3; p++) {
                profileLabels[`pet_${p}.name`]             = `Pet ${p}: Name`;
                profileLabels[`pet_${p}.species`]          = `Pet ${p}: Species`;
                profileLabels[`pet_${p}.breed`]            = `Pet ${p}: Breed`;
                profileLabels[`pet_${p}.date_of_birth`]    = `Pet ${p}: Date of Birth`;
                profileLabels[`pet_${p}.source`]           = `Pet ${p}: Source`;
                profileLabels[`pet_${p}.spayed_neutered`]  = `Pet ${p}: Spayed/Neutered`;
                profileLabels[`pet_${p}.vaccines_current`] = `Pet ${p}: Vaccines Current`;
            }
            for (const [formId, fieldMaps] of Object.entries(formFieldMappings)) {
                const responses = formResponses[formId] || {};
                for (const [fi, mapping] of Object.entries(fieldMaps)) {
                    const newVal = (responses[fi] !== undefined ? responses[fi] : '').toString().trim();
                    if (!newVal) continue;
                    let currentVal = '';
                    if (mapping.startsWith('client.')) {
                        const attr = mapping.slice(7);
                        currentVal = (currentClientProfile[attr] || '').toString().trim();
                    } else {
                        const m = mapping.match(/^pet_([123])\.(.+)$/);
                        if (m) {
                            const petIndex = parseInt(m[1]) - 1;
                            const attr     = m[2];
                            const petData  = currentPetProfiles[petIndex];
                            if (petData) currentVal = (petData[attr] || '').toString().trim();
                        }
                    }
                    if (currentVal && currentVal !== newVal) {
                        conflicts.push({
                            label:    profileLabels[mapping] || mapping,
                            oldValue: currentVal,
                            newValue: newVal,
                        });
                    }
                }
            }
            for (const responses of Object.values(formResponses || {})) {
                if (!responses || typeof responses !== 'object') continue;
                for (const value of Object.values(responses)) {
                    if (!Array.isArray(value)) continue;
                    value.forEach(function (pet, petIndex) {
                        if (!pet || typeof pet !== 'object') return;
                        const currentPet = currentPetProfiles[petIndex];
                        if (!currentPet) return;
                        [
                            ['name', `Pet ${petIndex + 1}: Name`],
                            ['species', `Pet ${petIndex + 1}: Species`],
                            ['breed', `Pet ${petIndex + 1}: Breed`],
                            ['source', `Pet ${petIndex + 1}: Source`],
                            ['spayed_neutered', `Pet ${petIndex + 1}: Spayed/Neutered`],
                            ['vaccines_current', `Pet ${petIndex + 1}: Vaccines Current`],
                        ].forEach(function ([attr, label]) {
                            const newVal = String(pet[attr] || '').trim();
                            const currentVal = String((currentPet || {})[attr] || '').trim();
                            if (newVal && currentVal && currentVal !== newVal) {
                                conflicts.push({
                                    label: label,
                                    oldValue: currentVal,
                                    newValue: newVal,
                                });
                            }
                        });
                    });
                }
            }
            return conflicts;
        }

        function escapeHtml(s) {
            const d = document.createElement('div'); d.textContent = s; return d.innerHTML;
        }

        function doSubmitBooking(payload) {
            const submitBtn = document.getElementById('submitBtn');
            const spinner   = submitBtn.querySelector('.loading-spinner');
            fetch('api_bookings.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    if (typeof window.bdtaResetTurnstile === 'function') {
                        window.bdtaResetTurnstile(document.getElementById('bookingForm'));
                    }
                    const modalTitle = document.querySelector('#successModal .modal-body h2');
                    const modalBody = document.querySelector('#successModal .modal-body p.text-muted');
                    if (modalTitle) {
                        modalTitle.textContent = data.booking_status === 'pending' ? 'Request Received!' : 'Booking Confirmed!';
                    }
                    if (modalBody) {
                        modalBody.textContent = data.message || 'Your booking details have been received.';
                    }
                    new bootstrap.Modal(document.getElementById('successModal')).show();
                } else {
                    showAlert(data.error || 'Booking failed. Please try again.', 'danger');
                    submitBtn.disabled = false;
                    spinner.classList.remove('active');
                }
            })
            .catch(() => {
                showAlert('Network error. Please check your connection and try again.', 'danger');
                submitBtn.disabled = false;
                spinner.classList.remove('active');
            });
        }

        // Confirm overwrite: clear pending payload BEFORE hiding modal to prevent double-fire
        document.getElementById('bookConfirmOverwriteBtn')?.addEventListener('click', function () {
            if (pendingBookingPayload) {
                const submitPayload = Object.assign({}, pendingBookingPayload, { overwrite_profile: true });
                pendingBookingPayload = null;
                bootstrap.Modal.getInstance(document.getElementById('profileOverwriteModal'))?.hide();
                const submitBtn = document.getElementById('submitBtn');
                const spinner   = submitBtn.querySelector('.loading-spinner');
                submitBtn.disabled = true;
                spinner.classList.add('active');
                doSubmitBooking(submitPayload);
            }
        });

        // "Keep Existing" or modal dismiss — submit without overwriting conflicting fields
        document.getElementById('profileOverwriteModal')?.addEventListener('hide.bs.modal', function () {
            if (pendingBookingPayload) {
                const submitPayload = Object.assign({}, pendingBookingPayload, { overwrite_profile: false });
                pendingBookingPayload = null;
                const submitBtn = document.getElementById('submitBtn');
                const spinner   = submitBtn.querySelector('.loading-spinner');
                submitBtn.disabled = true;
                spinner.classList.add('active');
                doSubmitBooking(submitPayload);
            }
        });

        function collectFormResponses() {
            const responses = {};
            document.querySelectorAll('[data-form-id]').forEach(section => {
                const formId = section.dataset.formId;
                const fields = {};
                section.querySelectorAll('.pet-info-group[data-form-field]').forEach(group => {
                    if (group.dataset.formField !== undefined) {
                        fields[group.dataset.formField] = collectPetInfoGroupResponse(group);
                    }
                });
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
                        if (el.dataset.formFieldType === 'newsletter_opt_in') {
                            fields[fi] = el.checked ? el.value : '';
                            return;
                        }
                        if (!cbGroups[fi]) cbGroups[fi] = [];
                        if (el.checked) cbGroups[fi].push(el.value);
                    }
                });
                Object.assign(fields, cbGroups);
                responses[formId] = fields;
            });
            return responses;
        }

        // Guard against hidden required fields preventing submission without feedback
        function findLabelForField(field, selector) {
            if (!field || !selector) return null;
            const formEl = field.closest('form');
            if (formEl) {
                const match = formEl.querySelector(selector);
                if (match) return match;
            }
            const formSection = field.closest('[data-form-id]');
            if (formSection) {
                const match = formSection.querySelector(selector);
                if (match) return match;
            }
            return null;
        }

        function getFieldLabelText(field) {
            if (!field) return 'this field';
            let labelText = field.getAttribute('aria-label') || 'this field';
            if (field.labels && field.labels.length > 0) {
                return field.labels[0].textContent.trim() || labelText;
            }
            const labelSelector = field.id ? `label[for="${field.id}"]` : null;
            let labelEl = findLabelForField(field, labelSelector) || field.closest('label');
            if (labelEl) labelText = labelEl.textContent.trim() || labelText;
            return labelText;
        }

        function ensureRequiredFieldsValid() {
            const bookingForm = document.getElementById('bookingForm');
            if (!bookingForm) return true;

            const requiredFields = Array.from(bookingForm.querySelectorAll(requiredFieldSelector));

            for (const field of requiredFields) {
                let isValid = true;
                if (typeof field.checkValidity === 'function') {
                    isValid = field.checkValidity();
                } else if ('value' in field) {
                    // Fallback only when checkValidity is unavailable
                    const rawVal = String(field.value || '').trim();
                    isValid = !!rawVal;
                    if (isValid && field.tagName && field.tagName.toUpperCase() === 'SELECT') {
                        isValid = field.value.trim() !== '';
                    }
                    if (isValid && field.type === 'email') {
                        isValid = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(rawVal);
                    } else if (isValid && field.type === 'number') {
                        const numVal = Number(rawVal);
                        if (Number.isNaN(numVal)) isValid = false;
                        const minAttr = field.getAttribute('min');
                        const maxAttr = field.getAttribute('max');
                        if (isValid && minAttr !== null) isValid = numVal >= Number(minAttr);
                        if (isValid && maxAttr !== null) isValid = numVal <= Number(maxAttr);
                    }
                    const pattern = field.getAttribute('pattern');
                    if (isValid && pattern) {
                        try {
                            const re = new RegExp(pattern);
                            isValid = re.test(rawVal);
                        } catch (err) {
                            console.warn('Invalid pattern for field', pattern, err);
                        }
                    }
                }
                if (isValid) continue;

                const stepEl = field.closest('.form-step');
                if (stepEl && stepEl.dataset.step) {
                    const stepNum = parseInt(stepEl.dataset.step, 10);
                    if (!isNaN(stepNum)) {
                        currentStep = stepNum;
                        updateSteps();
                    }
                }

                const rawLabelText = getFieldLabelText(field);
                const safeLabelText = (typeof escapeHtml === 'function')
                    ? escapeHtml(rawLabelText)
                    : String(rawLabelText).replace(/[&<>"']/g, function (ch) {
                        switch (ch) {
                            case '&': return '&amp;';
                            case '<': return '&lt;';
                            case '>': return '&gt;';
                            case '"': return '&quot;';
                            case "'": return '&#39;';
                            default: return ch;
                        }
                    });
                const isSelectField = field.tagName && field.tagName.toUpperCase() === 'SELECT';
                const actionVerb = (field.type === 'checkbox' || field.type === 'radio' || isSelectField)
                    ? 'select'
                    : 'fill in';
                showAlert(`Please ${actionVerb}: ${safeLabelText}`, 'warning');
                if (typeof field.focus === 'function') {
                    try {
                        field.focus();
                    } catch (err) {
                        console.warn('Unable to focus invalid field', err);
                    }
                }
                return false;
            }

            return true;
        }

        function submitBooking(e) {
            e.preventDefault();

            // Ensure all required fields (even in previous steps) are filled
            if (!ensureRequiredFieldsValid()) {
                return;
            }
            
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

            const formResponses = collectFormResponses();
            const mappedFormValues = getMappedFormValues(formResponses);
            const selectedPortalPetIds = getSelectedPortalPetIds();
            const selectedPortalDogNames = getSelectedPortalPetNames().join(', ');
            const turnstileToken =
                typeof window.bdtaGetTurnstileResponse === 'function'
                    ? window.bdtaGetTurnstileResponse(document.getElementById('bookingForm'))
                    : '';

            // Gather client info — from dynamic intake form or hardcoded fields
            let client_name, client_email, client_phone, client_address, dog_names, notes;
            let booking_intake_field_values = null;
            if (bookingIntakeFields) {
                const iv = getBookingIntakeValues();
                const combinedMappedValues = mergeProfileMappedValues(iv, mappedFormValues);
                client_name    = combinedMappedValues.client_name;
                client_email   = combinedMappedValues.client_email;
                client_phone   = combinedMappedValues.client_phone;
                client_address = combinedMappedValues.client_address;
                dog_names      = selectedPortalDogNames || combinedMappedValues.dog_names;
                notes          = combinedMappedValues.notes;
                booking_intake_field_values = iv.intake_field_values;
            } else {
                client_name    = document.getElementById('clientName').value || mappedFormValues.client_name;
                client_email   = document.getElementById('clientEmail').value || mappedFormValues.client_email;
                client_phone   = document.getElementById('clientPhone').value || mappedFormValues.client_phone;
                client_address = mappedFormValues.client_address;
                dog_names      = selectedPortalDogNames || document.getElementById('dogNames').value || mappedFormValues.dog_names;
                notes          = document.getElementById('notes').value || mappedFormValues.notes;
            }

            const bookingData = {
                appointment_type_id: selectedType,
                service_type: typeName,
                appointment_date: selectedDate,
                appointment_time: selectedTime,
                client_name: client_name,
                client_email: client_email,
                client_phone: client_phone,
                client_address: client_address,
                dog_names: dog_names,
                pet_ids: selectedPortalPetIds,
                notes: notes,
                // Default to 60 minutes if appointment type duration is not available
                duration_minutes: selectedTypeDuration ?? 60,
                location_type: location_type,
                location_value: location_value,
                use_credit: !!document.getElementById('useCreditToggle')?.checked,
                form_responses: formResponses,
                contract_template_id: document.querySelector('input[name="contract_template_id"]') ? parseInt(document.querySelector('input[name="contract_template_id"]').value) : null,
                contract_typed_name: document.getElementById('contractTypedName')?.value.trim() || null,
                contract_signature_font: document.getElementById('contractSignatureFont')?.value || null,
                turnstile_token: turnstileToken
            };

            if (document.querySelector('#bookingForm .bdta-turnstile') && !turnstileToken) {
                showAlert('Please confirm you are not a robot and try again.', 'warning');
                submitBtn.disabled = false;
                spinner.classList.remove('active');
                return;
            }

            // Include booking intake form data when using a custom form
            if (bookingIntakeFormId && booking_intake_field_values !== null) {
                bookingData.booking_form_id = bookingIntakeFormId;
                bookingData.booking_intake_fields = booking_intake_field_values;
            }

            // Check for profile conflicts before submitting
            const conflicts = getProfileConflicts(formResponses);
            if (conflicts.length > 0) {
                let listHtml = '<ul class="list-unstyled mb-0">';
                conflicts.forEach(c => {
                    listHtml += `<li class="mb-2">
                        <strong>${escapeHtml(c.label)}</strong><br>
                        <span class="text-muted small">Current:</span> <span class="text-danger small">${escapeHtml(c.oldValue)}</span>
                        <span class="text-muted small ms-2"><i class="fas fa-arrow-right me-1" aria-hidden="true"></i>New:</span> <span class="text-success small">${escapeHtml(c.newValue)}</span>
                    </li>`;
                });
                listHtml += '</ul>';
                document.getElementById('bookOverwriteConflictList').innerHTML = listHtml;
                pendingBookingPayload = bookingData;
                submitBtn.disabled = false;
                spinner.classList.remove('active');
                new bootstrap.Modal(document.getElementById('profileOverwriteModal')).show();
                return;
            }

            doSubmitBooking(bookingData);
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
<?php if ($page_has_turnstile_widget): ?>
<?php echo bdta_get_turnstile_assets_html(); ?>
<?php endif; ?>
<?php require_once __DIR__ . '/includes/public_footer.php'; ?>
