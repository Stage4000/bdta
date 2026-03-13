<?php
/**
 * Portal Credit Booking API
 * Handles authenticated booking submissions from the client portal.
 * Supports both booking creation and inline pet addition.
 */
require_once '../backend/includes/config.php';
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

$action = $data['action'] ?? '';

/* ══════════════════════════════════════════════════════════════════════════
 *  Action: add_pet  — create a new pet for the logged-in client
 * ═════════════════════════════════════════════════════════════════════════ */
if ($action === 'add_pet') {
    $name    = trim(scalar_string($data['name'] ?? ''));
    $species = trim(scalar_string($data['species'] ?? 'Dog'));
    $breed   = trim(scalar_string($data['breed'] ?? ''));

    if ($name === '') {
        echo json_encode(['error' => 'Pet name is required.']);
        exit;
    }
    // Sanitise: no more than 100 chars each
    $name    = mb_substr($name,    0, 100);
    $species = mb_substr($species, 0, 100);
    $breed   = mb_substr($breed,   0, 100);

    $stmt = $conn->prepare("
        INSERT INTO pets (client_id, name, species, breed, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$client_id, $name, $species, $breed]);
    $pet_id = (int)$conn->lastInsertId();

    logClientActivity($client_id, 'pet_add', 'Added pet via booking: ' . $name, $conn);

    echo json_encode(['success' => true, 'pet_id' => $pet_id]);
    exit;
}

/* ══════════════════════════════════════════════════════════════════════════
 *  Action: book  — create the booking
 * ═════════════════════════════════════════════════════════════════════════ */
if ($action !== 'book') {
    echo json_encode(['error' => 'Unknown action.']);
    exit;
}

// ── Validate required fields ─────────────────────────────────────────────
$required = ['appointment_type_id', 'appointment_date', 'appointment_time', 'client_name', 'client_email'];
foreach ($required as $f) {
    if (empty($data[$f])) {
        echo json_encode(['error' => "Missing required field: {$f}"]);
        exit;
    }
}

if (!filter_var(scalar_string($data['client_email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'Invalid email address.']);
    exit;
}

$appointment_type_id = safe_int($data['appointment_type_id'] ?? 0);

// ── Verify this appointment type exists and is active ────────────────────
$stmt = $conn->prepare("
    SELECT id, name, duration_minutes, contract_template_id,
           is_mini_session, mini_session_location,
           is_field_rental, field_rental_location,
           is_group_class, group_class_location,
           location_types
    FROM appointment_types
    WHERE id = ? AND is_active = 1
");
$stmt->execute([$appointment_type_id]);
$apt_type = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
if ($apt_type === []) {
    echo json_encode(['error' => 'Invalid or inactive appointment type.']);
    exit;
}

// ── Verify that the client actually has credits for this appointment type ─
$stmt = $conn->prepare("
    SELECT cpc.id
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    WHERE cpc.client_id = ?
      AND cpc.appointment_type_id = ?
      AND (cpc.total_credits - cpc.used_credits) > 0
      AND cp.is_active = 1
      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
    LIMIT 1
");
$stmt->execute([$client_id, $appointment_type_id]);
$credit_row = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
if ($credit_row === []) {
    echo json_encode(['error' => 'No available credits for this appointment type.']);
    exit;
}
$pkg_credit_id = array_int_value($credit_row, 'id');

// ── Contract validation (skip if client already has a current one) ────────
$contract_typed_name = trim(scalar_string($data['contract_typed_name'] ?? ''));
$allowed_sig_fonts   = ['font-dancing', 'font-pacifico', 'font-satisfy', 'font-great-vibes', 'font-allura'];
$contract_sig_font   = in_array(scalar_string($data['contract_signature_font'] ?? ''), $allowed_sig_fonts, true)
    ? scalar_string($data['contract_signature_font'] ?? '')
    : 'font-dancing';

$contract_template_id = !empty($data['contract_template_id']) ? safe_int($data['contract_template_id']) : null;

// Server-side contract skip re-check (don't trust client-side flag)
$contract_accepted    = 0;
$contract_accepted_at = null;

if (!empty($apt_type['contract_template_id'])) {
    $ctpl_id = array_int_value($apt_type, 'contract_template_id');

    // Check renewal period
    $renewal_months = 12;
    $stmt = $conn->prepare("SELECT renewal_period_months FROM contract_templates WHERE id = ? AND is_active = 1");
    $stmt->execute([$ctpl_id]);
    $ctpl = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
    if ($ctpl !== []) {
        $renewal_months = max(1, array_int_value($ctpl, 'renewal_period_months', 12));
    }

    $stmt = $conn->prepare("
        SELECT b.contract_accepted_at
        FROM bookings b
        JOIN appointment_types apt ON apt.id = b.appointment_type_id
        WHERE b.client_id = ?
          AND apt.contract_template_id = ?
          AND b.contract_accepted = 1
          AND b.contract_accepted_at IS NOT NULL
        ORDER BY b.contract_accepted_at DESC
        LIMIT 1
    ");
    $stmt->execute([$client_id, $ctpl_id]);
    $prev = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));

    $can_skip = false;
    if ($prev !== []) {
        $expiry = strtotime(array_string_value($prev, 'contract_accepted_at') . " +{$renewal_months} months");
        if ($expiry >= time()) {
            $can_skip = true;
        }
    }

    if (!$can_skip) {
        // Require a typed name signature
        if (empty($contract_typed_name)) {
            echo json_encode(['error' => 'You must sign the required contract (type your full name) to complete your booking.']);
            exit;
        }
        $contract_accepted    = 1;
        $contract_accepted_at = date('Y-m-d H:i:s');
    }
    // If can_skip: contract_accepted stays 0 / null — the existing record covers it
}

// ── Resolve location ──────────────────────────────────────────────────────
$location       = null;
$location_type  = trim(scalar_string($data['location_type'] ?? ''));
$location_value = trim(scalar_string($data['location_value'] ?? ''));
$allowed_location_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall', 'fixed'];

if (!empty($apt_type['is_mini_session'])) {
    $location_type = 'fixed';
    $location      = $apt_type['mini_session_location'];
} elseif (!empty($apt_type['is_field_rental'])) {
    $location_type = 'fixed';
    $location      = $apt_type['field_rental_location'];
} elseif (!empty($apt_type['is_group_class'])) {
    $location_type = 'fixed';
    $location      = $apt_type['group_class_location'];
} else {
    // Restrict to configured types for this appointment type
    if (!empty($apt_type['location_types'])) {
        $location_types_json = array_string_value($apt_type, 'location_types');
        $location_types_raw = json_decode($location_types_json, true);
        $configured = string_list($location_types_raw);
        if (!empty($configured)) {
            $allowed_location_types = array_merge($configured, ['fixed']);
        }
    }
    if ($location_type !== 'fixed') {
        if (empty($location_type) || !in_array($location_type, $allowed_location_types)) {
            echo json_encode(['error' => 'A valid location type is required.']);
            exit;
        }
        if (in_array($location_type, ['custom_address', 'webcall']) && empty($location_value)) {
            echo json_encode(['error' => $location_type === 'webcall' ? 'Webcall URL is required.' : 'Custom address is required.']);
            exit;
        }
        if ($location_type === 'client_address') {
            $stmt = $conn->prepare("SELECT address FROM clients WHERE id = ?");
            $stmt->execute([$client_id]);
            $cl = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
            $resolved = trim(array_string_value($cl, 'address'));
            if (empty($resolved)) {
                echo json_encode(['error' => 'Your account does not have an address on file. Please update your profile or choose a different location type.']);
                exit;
            }
            $location = $resolved;
        } else {
            $location = $location_value;
        }
    }
}

// ── Handle pet IDs ────────────────────────────────────────────────────────
$pet_ids_raw = $data['pet_ids'] ?? [];
$pet_ids     = [];
if (is_array($pet_ids_raw) && !empty($pet_ids_raw)) {
    // Verify all pet IDs belong to this client
    $placeholders = implode(',', array_fill(0, count($pet_ids_raw), '?'));
    $stmt = $conn->prepare("SELECT id FROM pets WHERE client_id = ? AND id IN ($placeholders) AND is_active = 1");
    $stmt->execute(array_merge([$client_id], array_map('safe_int', $pet_ids_raw)));
    $pet_ids = array_map('safe_int', array_column(assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC)), 'id'));
}

// ── Insert booking ────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO bookings
        (client_id, appointment_type_id, client_name, client_email, client_phone,
         service_type, appointment_date, appointment_time, notes, duration_minutes,
         location, location_type, package_credit_id,
         contract_accepted, contract_accepted_at, contract_signature_name, contract_signature_font,
         status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
");
$stmt->execute([
    $client_id,
    $appointment_type_id,
    trim(scalar_string($data['client_name'] ?? '')),
    trim(scalar_string($data['client_email'] ?? '')),
    trim(scalar_string($data['client_phone'] ?? '')),
    array_string_value($apt_type, 'name'),
    scalar_string($data['appointment_date'] ?? ''),
    scalar_string($data['appointment_time'] ?? ''),
    trim(scalar_string($data['notes'] ?? '')),
    array_int_value($apt_type, 'duration_minutes', 60),
    $location,
    $location_type,
    $pkg_credit_id,
    $contract_accepted,
    $contract_accepted_at,
    $contract_accepted ? $contract_typed_name : null,
    $contract_accepted ? $contract_sig_font   : null,
]);
$booking_id = (int)$conn->lastInsertId();

// ── Link pets ─────────────────────────────────────────────────────────────
foreach ($pet_ids as $pid) {
    $conn->prepare("
        INSERT INTO appointment_pets (booking_id, pet_id, created_at)
        VALUES (?, ?, CURRENT_TIMESTAMP)
    ")->execute([$booking_id, $pid]);
}

// ── Save form responses ───────────────────────────────────────────────────
require_once '../backend/includes/workflow_helper.php';
$workflow_helper = new WorkflowHelper($conn);
if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
    $ins = $conn->prepare("
        INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at)
        VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)
    ");
    foreach ($data['form_responses'] as $template_id => $responses) {
        if (is_array($responses) && !empty($responses)) {
            $ins->execute([$client_id, (int)$template_id, $booking_id, json_encode($responses)]);
            $form_submission_id = (int)$conn->lastInsertId();
            $workflow_helper->checkFormTriggers($form_submission_id);
        }
    }
}

// ── Apply profile mappings from form responses ────────────────────────────
// Explicit safe column name maps (not user-controlled — never interpolate raw input)
$client_col_map = [
    'name'    => 'name',
    'email'   => 'email',
    'phone'   => 'phone',
    'address' => 'address',
];
$pet_col_map = [
    'name'                     => 'name',
    'species'                  => 'species',
    'breed'                    => 'breed',
    'date_of_birth'            => 'date_of_birth',
    'source'                   => 'source',
    'spayed_neutered'          => 'spayed_neutered',
    'vaccines_current'         => 'vaccines_current',
    'vaccine_notes'            => 'vaccine_notes',
    'behavior_notes'           => 'behavior_notes',
    'medical_notes'            => 'medical_notes',
    'training_notes'           => 'training_notes',
];
// Distinguish between three cases sent by the client:
//   • overwrite_profile key absent  → modal was never shown (no detected conflict); always apply mapping
//   • overwrite_profile: true       → user confirmed the overwrite prompt; always apply mapping
//   • overwrite_profile: false      → user explicitly chose "Keep Existing"; skip conflicting fields
$overwrite_declined = isset($data['overwrite_profile']) && !(bool)$data['overwrite_profile'];

if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
    // Ordered list of pet IDs selected for this booking (0-based)
    $booking_pet_ids = array_values(array_filter(array_map('safe_int', (array)($data['pet_ids'] ?? []))));

    // Load current client record for conflict checking
    $cur_client_stmt = $conn->prepare("SELECT name, email, phone, address FROM clients WHERE id = ?");
    $cur_client_stmt->execute([$client_id]);
    $cur_client = $cur_client_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($data['form_responses'] as $tpl_id => $responses) {
        if (!is_array($responses)) continue;

        $tpl_stmt = $conn->prepare("SELECT fields FROM form_templates WHERE id = ?");
        $tpl_stmt->execute([(int)$tpl_id]);
        $tpl_row = $tpl_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tpl_row) continue;

        $tpl_fields = decode_json_assoc_list(array_string_value($tpl_row, 'fields'));

        foreach ($tpl_fields as $fi => $field) {
            $mapping = array_string_value($field, 'profile_mapping');
            if (empty($mapping)) continue;

            $value = $responses[$fi] ?? null;
            if ($value === null || $value === '') continue;
            if (is_array($value)) $value = implode(', ', string_list($value));
            $value = scalar_string($value);

            if (strpos($mapping, 'client.') === 0) {
                $attr = substr($mapping, 7);
                if (!isset($client_col_map[$attr])) continue;
                if ($attr === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) continue;

                // Only skip when the user explicitly declined the overwrite prompt
                $existing = (string)($cur_client[$attr] ?? '');
                if ($overwrite_declined && $existing !== '' && $existing !== $value) continue;

                $safe_col = $client_col_map[$attr];
                $conn->prepare("UPDATE clients SET {$safe_col} = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                     ->execute([$value, $client_id]);
                logClientActivity($client_id, 'profile_update_from_form',
                    "Profile field '{$attr}' updated via form submission (booking #{$booking_id})", $conn);

            } elseif (preg_match('/^pet_([123])\.(.+)$/', $mapping, $m)) {
                $pet_index = (int)$m[1] - 1;
                $attr      = $m[2];
                if (!isset($pet_col_map[$attr])) continue;

                $pet_id = $booking_pet_ids[$pet_index] ?? null;
                if (!$pet_id) continue;

                // Verify ownership
                $own = $conn->prepare("SELECT * FROM pets WHERE id = ? AND client_id = ?");
                $own->execute([$pet_id, $client_id]);
                $cur_pet = $own->fetch(PDO::FETCH_ASSOC);
                if (!$cur_pet) continue;

                // Type coercion / validation
                if ($attr === 'date_of_birth') {
                    $dt = date_create_from_format('Y-m-d', $value)
                       ?: date_create_from_format('m/d/Y', $value)
                       ?: date_create_from_format('d/m/Y', $value);
                    if (!$dt) continue;
                    $value = $dt->format('Y-m-d');
                } elseif (in_array($attr, ['spayed_neutered', 'vaccines_current'], true)) {
                    $value = in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true) ? 1 : 0;
                }

                // Only skip when the user explicitly declined the overwrite prompt
                $existing_pet_val = (string)($cur_pet[$attr] ?? '');
                if ($overwrite_declined && $existing_pet_val !== '' && (string)$existing_pet_val !== (string)$value) continue;

                $safe_col = $pet_col_map[$attr];
                $conn->prepare("UPDATE pets SET {$safe_col} = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                     ->execute([$value, $pet_id]);
                logClientActivity($client_id, 'pet_profile_update_from_form',
                    "Pet #{$pet_id} field '{$attr}' updated via form submission (booking #{$booking_id})", $conn);
            }
        }
    }
}

// ── Trigger auto-enrollment for appointment workflow triggers ─────────────
$workflow_helper->checkAppointmentTriggers($booking_id);

// ── Deduct credit ─────────────────────────────────────────────────────────
$conn->prepare("
    UPDATE client_package_credits
    SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP
    WHERE id = ?
")->execute([$pkg_credit_id]);

$conn->prepare("
    INSERT INTO package_credit_transactions
        (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
    VALUES (?, ?, ?, 'consume', -1, ?, ?, NULL)
")->execute([
    $pkg_credit_id,
    $client_id,
    $appointment_type_id,
    $booking_id,
    "Credit applied at booking #{$booking_id} via client portal credit booking",
]);

// ── Log activity ──────────────────────────────────────────────────────────
logClientActivity($client_id, 'booking_created', 'Created booking #' . $booking_id . ' for ' . array_string_value($apt_type, 'name'), $conn);

// ── Send confirmation email ───────────────────────────────────────────────
require_once '../backend/includes/email_service.php';
require_once '../backend/includes/google_calendar.php';
require_once '../backend/includes/icalendar.php';

$stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$booking) {
    echo json_encode(['error' => 'Booking confirmation data could not be loaded.']);
    exit;
}

$base_url          = getDynamicBaseUrl();
$google_cal_link   = ICalendarGenerator::generateGoogleCalendarLink($booking);
$ical_link         = $base_url . '/backend/public/download_ical.php?booking_id=' . $booking_id;

$email_service = new EmailService(null, $conn);
$email_result  = $email_service->sendBookingConfirmation($booking);

$google_calendar = new GoogleCalendarIntegration();
$gcal_result = ['success' => false];
if ($google_calendar->isConfigured()) {
    $gcal_result = $google_calendar->addEvent($booking);
}

echo json_encode([
    'success'              => true,
    'booking_id'           => $booking_id,
    'credit_applied'       => true,
    'calendar_links'       => [
        'google_calendar' => $google_cal_link,
        'ical_download'   => $ical_link,
    ],
    'email_sent'           => $email_result['success'],
    'google_calendar_synced' => $gcal_result['success'],
]);
