<?php
/**
 * Portal Booking API
 * Handles authenticated booking submissions from the client portal.
 * Supports both booking creation and inline pet addition.
 */
require_once '../backend/includes/config.php';
require_once '../backend/includes/booking_resources.php';
require_once '../backend/includes/form_types.php';
require_once '../backend/includes/mailjet_newsletter.php';
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

$client_name = trim(scalar_string($data['client_name'] ?? ''));
$client_email = trim(scalar_string($data['client_email'] ?? ''));
$appointment_type_id = safe_int($data['appointment_type_id'] ?? 0);

// ── Verify this appointment type exists and is active ────────────────────
$stmt = $conn->prepare("
    SELECT id, name, duration_minutes, contract_template_id, portal_available,
           is_mini_session, mini_session_location,
           is_field_rental, field_rental_location,
           is_group_class, group_class_location,
           location_types, requires_admin_confirmation, admin_user_id,
           uses_resource, resource_name, resource_capacity, resource_allocation,
           buffer_before_minutes, buffer_after_minutes
    FROM appointment_types
    WHERE id = ? AND is_active = 1
");
$stmt->execute([$appointment_type_id]);
$apt_type = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
if ($apt_type === []) {
    echo json_encode(['error' => 'Invalid or inactive appointment type.']);
    exit;
}

$requires_admin_confirmation = array_int_value($apt_type, 'requires_admin_confirmation') === 1;
$is_pending_request = $requires_admin_confirmation;
$initial_status = $is_pending_request ? 'pending' : 'confirmed';
$appointment_type_admin_user_id = array_int_value($apt_type, 'admin_user_id');
$resource_config = bdta_booking_resource_config($apt_type);

// ── Verify that this appointment type is bookable from the portal ─────────
// Prefer credits that expire soonest; non-expiring credits (NULL expires_at)
// are treated as the far future so they are consumed last.
$stmt = $conn->prepare("
    SELECT cpc.id
    FROM client_package_credits cpc
    JOIN client_packages cp ON cpc.client_package_id = cp.id
    WHERE cpc.client_id = ?
      AND cpc.appointment_type_id = ?
      AND (cpc.total_credits - cpc.used_credits) > 0
      AND cp.is_active = 1
      AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
    ORDER BY (cp.expires_at IS NULL) ASC, cp.expires_at ASC
    LIMIT 1
");
$stmt->execute([$client_id, $appointment_type_id]);
$credit_row = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
$has_available_credit = $credit_row !== [];
$pkg_credit_id = $has_available_credit ? array_int_value($credit_row, 'id') : null;
$portal_available = array_int_value($apt_type, 'portal_available') === 1;

if (!$portal_available && !$has_available_credit) {
    echo json_encode(['error' => 'This appointment type is not currently available to book from the portal.']);
    exit;
}

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
    $requested_pet_ids = array_values(array_filter(
        array_unique(array_map('safe_int', $pet_ids_raw)),
        static fn (int $pet_id): bool => $pet_id > 0
    ));
    $requested_pet_ids = array_slice($requested_pet_ids, 0, 100);

    if (!empty($requested_pet_ids)) {
        $placeholders = implode(', ', array_fill(0, count($requested_pet_ids), '?'));
        // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- placeholder count comes from safe_int()-sanitized positive pet IDs and every value is bound separately.
        $stmt = $conn->prepare("SELECT id FROM pets WHERE client_id = ? AND is_active = 1 AND id IN ($placeholders)");
        $stmt->execute(array_merge([$client_id], $requested_pet_ids));
        $verified_pet_ids = array_map('safe_int', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $pet_ids = api_booking_order_verified_pet_ids($requested_pet_ids, $verified_pet_ids);
    }
}

$pet_updates = [];
if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
    $pet_updates = api_booking_collect_pet_profile_mapped_values($conn, $data['form_responses']);
}

if ($pet_ids === [] && $pet_updates !== []) {
    $pet_ids = api_booking_create_pets_from_profile_updates($conn, $client_id, $pet_updates);
}

// Distinguish between three cases sent by the client:
//   • overwrite_profile key absent  → modal was never shown (no detected conflict); always apply mapping
//   • overwrite_profile: true       → user confirmed the overwrite prompt; always apply mapping
//   • overwrite_profile: false      → user explicitly chose "Keep Existing"; skip conflicting client fields
//                                       and create new pet profiles for conflicting pet mappings
$overwrite_declined = isset($data['overwrite_profile']) && !(bool)$data['overwrite_profile'];

if ($overwrite_declined) {
    if ($pet_updates !== []) {
        $pet_ids = api_booking_clone_conflicting_pets($conn, $client_id, $pet_ids, $pet_updates);
    }
}

if (!empty($resource_config['enabled'])) {
    $stmt = $conn->prepare("
        SELECT b.appointment_time, b.duration_minutes, b.appointment_type_id,
               COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before,
               COALESCE(at.buffer_after_minutes, 0) AS b_buffer_after,
               COALESCE(apc.pet_count, 0) AS pet_count
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        LEFT JOIN (
            SELECT booking_id, COUNT(*) AS pet_count
            FROM appointment_pets
            GROUP BY booking_id
        ) apc ON apc.booking_id = b.id
        WHERE b.appointment_date = ? AND b.status != 'cancelled' AND b.appointment_type_id = ?
    ");
    $stmt->execute([scalar_string($data['appointment_date'] ?? ''), $appointment_type_id]);
    $existing_resource_bookings = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
    if (!bdta_booking_resource_has_capacity(
        $resource_config,
        $existing_resource_bookings,
        scalar_string($data['appointment_time'] ?? ''),
        array_int_value($apt_type, 'duration_minutes', 60),
        max(0, array_int_value($apt_type, 'buffer_before_minutes')),
        max(0, array_int_value($apt_type, 'buffer_after_minutes')),
        bdta_booking_resource_units($resource_config, count($pet_ids)),
        $appointment_type_id
    )) {
        $resource_label = trim($resource_config['name']);
        echo json_encode(['error' => 'No ' . ($resource_label !== '' ? $resource_label : 'resource') . ' units are available for this time slot.']);
        exit;
    }
}

// ── Insert booking ────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    INSERT INTO bookings
        (client_id, appointment_type_id, admin_user_id, client_name, client_email, client_phone,
         service_type, appointment_date, appointment_time, notes, duration_minutes,
         location, location_type, package_credit_id,
         contract_accepted, contract_accepted_at, contract_signature_name, contract_signature_font,
         status, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
");
$stmt->execute([
    $client_id,
    $appointment_type_id,
    $appointment_type_admin_user_id > 0 ? $appointment_type_admin_user_id : null,
    $client_name,
    $client_email,
    trim(scalar_string($data['client_phone'] ?? '')),
    array_string_value($apt_type, 'name'),
    scalar_string($data['appointment_date'] ?? ''),
    scalar_string($data['appointment_time'] ?? ''),
    trim(scalar_string($data['notes'] ?? '')),
    array_int_value($apt_type, 'duration_minutes', 60),
    $location,
    $location_type,
    (!$is_pending_request && $pkg_credit_id !== null) ? $pkg_credit_id : null,
    $contract_accepted,
    $contract_accepted_at,
    $contract_accepted ? $contract_typed_name : null,
    $contract_accepted ? $contract_sig_font   : null,
    $initial_status,
]);
$booking_id = (int)$conn->lastInsertId();
$booking_notification_title = $initial_status === 'pending'
    ? 'New appointment request'
    : 'New appointment booked';
$booking_notification_message = trim(scalar_string($data['client_name'] ?? 'Client')) . ' booked ' . array_string_value($apt_type, 'name') . ' for ' . scalar_string($data['appointment_date'] ?? '');
bdta_create_admin_notifications(
    $conn,
    'booking',
    $booking_id,
    $booking_notification_title,
    $booking_notification_message,
    '/client/bookings_list.php'
);

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
    $template_frequency_stmt = null;
    $insert_supports_pet_id = true;
    try {
        $template_frequency_stmt = $conn->prepare("SELECT required_frequency FROM form_templates WHERE id = ?");
    } catch (Throwable $e) {
        $template_frequency_stmt = null;
    }
    try {
        $ins = $conn->prepare("
            INSERT INTO form_submissions (client_id, template_id, booking_id, pet_id, responses, status, submitted_at)
            VALUES (?, ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)
        ");
    } catch (Throwable $e) {
        $insert_supports_pet_id = false;
        $ins = $conn->prepare("
            INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at)
            VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)
        ");
    }
    foreach ($data['form_responses'] as $template_id => $responses) {
        if (is_array($responses) && !empty($responses)) {
            $template_id = (int) $template_id;
            $template_frequency = '';
            if ($template_frequency_stmt !== null) {
                try {
                    $template_frequency_stmt->execute([$template_id]);
                    $template_frequency = scalar_string($template_frequency_stmt->fetchColumn());
                } catch (Throwable $e) {
                    $template_frequency = '';
                }
            }
            $submission_pet_ids = $insert_supports_pet_id
                ? bdta_get_form_submission_pet_ids($template_frequency, $pet_ids)
                : [null];
            foreach ($submission_pet_ids as $submission_pet_id) {
                $params = $insert_supports_pet_id
                    ? [$client_id, $template_id, $booking_id, $submission_pet_id, json_encode($responses)]
                    : [$client_id, $template_id, $booking_id, json_encode($responses)];
                $ins->execute($params);
                $form_submission_id = (int)$conn->lastInsertId();
                $workflow_helper->checkFormTriggers($form_submission_id);
            }
        }
    }
}

// ── Apply profile mappings from form responses ────────────────────────────
function updateClientProfileField(PDO $conn, string $attr, string $value, int $client_id): bool {
    switch ($attr) {
        case 'name':
            $conn->prepare("UPDATE clients SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $client_id]);
            return true;
        case 'email':
            $conn->prepare("UPDATE clients SET email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $client_id]);
            return true;
        case 'phone':
            $conn->prepare("UPDATE clients SET phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $client_id]);
            return true;
        case 'address':
            $conn->prepare("UPDATE clients SET address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $client_id]);
            return true;
        default:
            return false;
    }
}

function updatePetProfileField(PDO $conn, string $attr, string|int $value, int $pet_id): bool {
    switch ($attr) {
        case 'name':
            $conn->prepare("UPDATE pets SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'species':
            $conn->prepare("UPDATE pets SET species = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'breed':
            $conn->prepare("UPDATE pets SET breed = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'date_of_birth':
            $conn->prepare("UPDATE pets SET date_of_birth = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'age_years':
            $conn->prepare("UPDATE pets SET age_years = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'age_months':
            $conn->prepare("UPDATE pets SET age_months = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'source':
            $conn->prepare("UPDATE pets SET source = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'ownership_length_years':
            $conn->prepare("UPDATE pets SET ownership_length_years = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'ownership_length_months':
            $conn->prepare("UPDATE pets SET ownership_length_months = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'spayed_neutered':
            $conn->prepare("UPDATE pets SET spayed_neutered = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'vaccines_current':
            $conn->prepare("UPDATE pets SET vaccines_current = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'vaccine_notes':
            $conn->prepare("UPDATE pets SET vaccine_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'behavior_notes':
            $conn->prepare("UPDATE pets SET behavior_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'medical_notes':
            $conn->prepare("UPDATE pets SET medical_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'training_notes':
            $conn->prepare("UPDATE pets SET training_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        case 'pet_sitting_notes':
            $conn->prepare("UPDATE pets SET pet_sitting_notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                 ->execute([$value, $pet_id]);
            return true;
        default:
            return false;
    }
}

function api_booking_normalize_pet_profile_value(string $attr, mixed $value): string|int|null {
    if (is_array($value)) {
        $value = implode(', ', string_list($value));
    }

    $value = scalar_string($value);
    if ($value === '') {
        return null;
    }

    if ($attr === 'date_of_birth') {
        $dt = api_booking_parse_pet_profile_date($value);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    if (in_array($attr, ['spayed_neutered', 'vaccines_current'], true)) {
        return in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true) ? 1 : 0;
    }

    return $value;
}

function api_booking_parse_pet_profile_date(string $value): ?DateTime {
    foreach (['Y-m-d', 'm/d/Y', 'd/m/Y'] as $format) {
        $dt = date_create_from_format('!' . $format, $value);
        $errors = assoc_row(DateTime::getLastErrors());
        $has_errors = array_int_value($errors, 'warning_count') > 0
            || array_int_value($errors, 'error_count') > 0;
        if ($dt instanceof DateTime && !$has_errors) {
            return $dt;
        }
    }

    return null;
}

/**
 * @param list<int> $requested_pet_ids
 * @param list<int> $verified_pet_ids
 * @return list<int>
 */
function api_booking_order_verified_pet_ids(array $requested_pet_ids, array $verified_pet_ids): array {
    $verified_map = [];
    foreach ($verified_pet_ids as $verified_pet_id) {
        $verified_map[(string) $verified_pet_id] = true;
    }

    $ordered_pet_ids = [];
    foreach ($requested_pet_ids as $requested_pet_id) {
        if (isset($verified_map[(string) $requested_pet_id])) {
            $ordered_pet_ids[] = $requested_pet_id;
        }
    }

    return $ordered_pet_ids;
}

/**
 * @param array<int|string, mixed> $form_responses
 * @return array<int, array<string, string|int>>
 */
function api_booking_collect_pet_profile_mapped_values(PDO $conn, array $form_responses): array {
    $supported_attrs = [
        'name' => true,
        'species' => true,
        'breed' => true,
        'date_of_birth' => true,
        'age_years' => true,
        'age_months' => true,
        'source' => true,
        'ownership_length_years' => true,
        'ownership_length_months' => true,
        'spayed_neutered' => true,
        'vaccines_current' => true,
        'vaccine_notes' => true,
        'behavior_notes' => true,
        'medical_notes' => true,
        'training_notes' => true,
        'pet_sitting_notes' => true,
    ];
    $pet_updates = [];

    foreach ($form_responses as $tpl_id => $responses) {
        if (!is_array($responses)) {
            continue;
        }

        $tpl_stmt = $conn->prepare("SELECT fields FROM form_templates WHERE id = ?");
        $tpl_stmt->execute([(int)$tpl_id]);
        $tpl_row = assoc_row($tpl_stmt->fetch(PDO::FETCH_ASSOC));
        if ($tpl_row === []) {
            continue;
        }

        $tpl_fields = decode_json_assoc_list(array_string_value($tpl_row, 'fields'));
        foreach ($tpl_fields as $fi => $field) {
            if (bdta_form_field_is_pet_info_group($field)) {
                foreach (bdta_form_field_pet_info_group_profile_values($field, $responses[$fi] ?? $responses[(string) $fi] ?? null) as $pet_index => $pet_profile) {
                    if (!isset($pet_updates[$pet_index])) {
                        $pet_updates[$pet_index] = [];
                    }
                    foreach ($pet_profile as $attr => $normalized_value) {
                        if (isset($supported_attrs[$attr])) {
                            $pet_updates[$pet_index][$attr] = $normalized_value;
                        }
                    }
                }
                continue;
            }

            $mapping = array_string_value($field, 'profile_mapping');
            if (!preg_match('/^pet_([123])\.(.+)$/', $mapping, $matches)) {
                continue;
            }

            $pet_index = (int)$matches[1] - 1;
            $attr = $matches[2];
            if (!isset($supported_attrs[$attr])) {
                continue;
            }

            $normalized = api_booking_normalize_pet_profile_value($attr, $responses[$fi] ?? null);
            if ($normalized === null) {
                continue;
            }

            if (!isset($pet_updates[$pet_index])) {
                $pet_updates[$pet_index] = [];
            }
            $pet_updates[$pet_index][$attr] = $normalized;
        }
    }

    return $pet_updates;
}

/**
 * @param array<int, array<string, string|int>> $pet_updates
 * @return list<int>
 */
function api_booking_create_pets_from_profile_updates(PDO $conn, int $client_id, array $pet_updates): array {
    if ($client_id <= 0 || $pet_updates === []) {
        return [];
    }

    $pet_columns = api_booking_pet_table_columns($conn);
    if ($pet_columns === []) {
        return [];
    }

    $supported_attrs = array_values(array_intersect([
        'name',
        'species',
        'breed',
        'date_of_birth',
        'age_years',
        'age_months',
        'source',
        'ownership_length_years',
        'ownership_length_months',
        'spayed_neutered',
        'vaccines_current',
        'vaccine_notes',
        'behavior_notes',
        'medical_notes',
        'training_notes',
        'pet_sitting_notes',
    ], $pet_columns));

    $created_pet_ids = [];
    $find_pet_stmt = $conn->prepare('SELECT id FROM pets WHERE client_id = ? AND name = ? ORDER BY id ASC LIMIT 1');

    foreach ($pet_updates as $pet_profile) {
        $pet_name = trim(scalar_string($pet_profile['name'] ?? ''));
        if ($pet_name === '') {
            continue;
        }

        $find_pet_stmt->execute([$client_id, $pet_name]);
        $existing_pet_id = safe_int($find_pet_stmt->fetchColumn());

        $params = [];
        if ($existing_pet_id > 0) {
            $assignments = [];
            foreach ($supported_attrs as $attr) {
                if (!array_key_exists($attr, $pet_profile)) {
                    continue;
                }
                $assignments[] = $attr . ' = ?';
                $params[] = $pet_profile[$attr];
            }
            if ($assignments !== []) {
                $params[] = $existing_pet_id;
                $conn->prepare(
                    'UPDATE pets SET ' . implode(', ', $assignments) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute($params);
            }
            $created_pet_ids[] = $existing_pet_id;
            continue;
        }

        $insert_columns = ['client_id'];
        $insert_sql = ['?'];
        $params[] = $client_id;
        foreach ($supported_attrs as $attr) {
            if (!array_key_exists($attr, $pet_profile)) {
                continue;
            }
            $insert_columns[] = $attr;
            $insert_sql[] = '?';
            $params[] = $pet_profile[$attr];
        }
        if (in_array('is_active', $pet_columns, true)) {
            $insert_columns[] = 'is_active';
            $insert_sql[] = '?';
            $params[] = 1;
        }
        if (in_array('created_at', $pet_columns, true)) {
            $insert_columns[] = 'created_at';
            $insert_sql[] = 'CURRENT_TIMESTAMP';
        }
        if (in_array('updated_at', $pet_columns, true)) {
            $insert_columns[] = 'updated_at';
            $insert_sql[] = 'CURRENT_TIMESTAMP';
        }

        $conn->prepare(
            'INSERT INTO pets (' . implode(', ', $insert_columns) . ') VALUES (' . implode(', ', $insert_sql) . ')'
        )->execute($params);
        $created_pet_ids[] = (int)$conn->lastInsertId();
    }

    return $created_pet_ids;
}

/**
 * @return list<string>
 */
function api_booking_pet_table_columns(PDO $conn): array {
    $stmt = $conn->query('SELECT * FROM pets LIMIT 0');
    if ($stmt === false) {
        return [];
    }
    $columns = [];
    for ($index = 0, $count = $stmt->columnCount(); $index < $count; $index++) {
        $column_meta = $stmt->getColumnMeta($index);
        $column_name = scalar_string($column_meta['name'] ?? '');
        if ($column_name !== '') {
            $columns[] = $column_name;
        }
    }
    return $columns;
}

/**
 * @param list<int> $pet_ids
 * @param array<int, array<string, string|int>> $pet_updates
 * @return list<int>
 */
function api_booking_clone_conflicting_pets(PDO $conn, int $client_id, array $pet_ids, array $pet_updates): array {
    if ($client_id <= 0 || $pet_ids === [] || $pet_updates === []) {
        return $pet_ids;
    }

    $pet_columns = api_booking_pet_table_columns($conn);
    $supported_attrs = [
        'name',
        'species',
        'breed',
        'date_of_birth',
        'age_years',
        'age_months',
        'source',
        'ownership_length_years',
        'ownership_length_months',
        'spayed_neutered',
        'vaccines_current',
        'vaccine_notes',
        'behavior_notes',
        'medical_notes',
        'training_notes',
        'pet_sitting_notes',
    ];
    $fetch_pet_stmt = $conn->prepare("SELECT * FROM pets WHERE id = ? AND client_id = ?");

    foreach ($pet_ids as $pet_index => $pet_id) {
        $mapped_values = $pet_updates[$pet_index] ?? [];
        if ($pet_id <= 0 || $mapped_values === []) continue;

        $fetch_pet_stmt->execute([$pet_id, $client_id]);
        $cur_pet = assoc_row($fetch_pet_stmt->fetch(PDO::FETCH_ASSOC));
        if ($cur_pet === []) continue;

        $has_conflict = false;
        foreach ($mapped_values as $attr => $new_value) {
            $existing_value = scalar_string($cur_pet[$attr] ?? '');
            if ($existing_value !== '' && $existing_value !== (string)$new_value) {
                $has_conflict = true;
                break;
            }
        }
        if (!$has_conflict) continue;

        $insert_columns = ['client_id'];
        $insert_sql = ['?'];
        $insert_values = [$client_id];

        foreach ($supported_attrs as $attr) {
            if (!in_array($attr, $pet_columns, true)) continue;

            $value = $mapped_values[$attr] ?? ($cur_pet[$attr] ?? null);
            if ($value === null && !in_array($attr, ['name', 'species'], true)) continue;
            if ($attr === 'name' && scalar_string($value) === '') {
                $value = scalar_string($cur_pet['name'] ?? 'Pet');
            }
            if ($attr === 'species' && scalar_string($value) === '') {
                $value = scalar_string($cur_pet['species'] ?? 'Dog');
            }

            $insert_columns[] = $attr;
            $insert_sql[] = '?';
            $insert_values[] = $value;
        }

        if (in_array('is_active', $pet_columns, true)) {
            $insert_columns[] = 'is_active';
            $insert_sql[] = '?';
            $insert_values[] = 1;
        }
        if (in_array('created_at', $pet_columns, true)) {
            $insert_columns[] = 'created_at';
            $insert_sql[] = 'CURRENT_TIMESTAMP';
        }
        if (in_array('updated_at', $pet_columns, true)) {
            $insert_columns[] = 'updated_at';
            $insert_sql[] = 'CURRENT_TIMESTAMP';
        }

        $insert_stmt = $conn->prepare(
            'INSERT INTO pets (' . implode(', ', $insert_columns) . ') VALUES (' . implode(', ', $insert_sql) . ')'
        );
        $insert_stmt->execute($insert_values);
        $pet_ids[$pet_index] = (int)$conn->lastInsertId();
    }

    /** @var list<int> $pet_ids */
    return $pet_ids;
}
if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
    // Ordered list of pet IDs selected for this booking (0-based)
    $booking_pet_ids = $pet_ids;

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
                if ($attr === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) continue;

                // Only skip when the user explicitly declined the overwrite prompt
                $existing = (string)($cur_client[$attr] ?? '');
                if ($overwrite_declined && $existing !== '' && $existing !== $value) continue;

                if (!updateClientProfileField($conn, $attr, $value, $client_id)) continue;
                logClientActivity($client_id, 'profile_update_from_form',
                    "Profile field '{$attr}' updated via form submission (booking #{$booking_id})", $conn);

            } elseif (preg_match('/^pet_([123])\.(.+)$/', $mapping, $m)) {
                $pet_index = (int)$m[1] - 1;
                $attr      = $m[2];

                $pet_id = $booking_pet_ids[$pet_index] ?? null;
                if (!$pet_id) continue;

                // Verify ownership
                $own = $conn->prepare("SELECT * FROM pets WHERE id = ? AND client_id = ?");
                $own->execute([$pet_id, $client_id]);
                $cur_pet = $own->fetch(PDO::FETCH_ASSOC);
                if (!$cur_pet) continue;

                // Type coercion / validation
                if ($attr === 'date_of_birth') {
                    $dt = api_booking_parse_pet_profile_date($value);
                    if (!$dt) continue;
                    $value = $dt->format('Y-m-d');
                } elseif (in_array($attr, ['spayed_neutered', 'vaccines_current'], true)) {
                    $value = in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true) ? 1 : 0;
                }

                // Only skip when the user explicitly declined the overwrite prompt
                $existing_pet_val = (string)($cur_pet[$attr] ?? '');
                if ($overwrite_declined && $existing_pet_val !== '' && (string)$existing_pet_val !== (string)$value) continue;

                if (!updatePetProfileField($conn, $attr, $value, $pet_id)) continue;
                logClientActivity($client_id, 'pet_profile_update_from_form',
                    "Pet #{$pet_id} field '{$attr}' updated via form submission (booking #{$booking_id})", $conn);
            }
        }
    }
}

// ── Trigger auto-enrollment for appointment workflow triggers ─────────────
$workflow_helper->checkAppointmentTriggers($booking_id);

// ── Deduct credit ─────────────────────────────────────────────────────────
if ($pkg_credit_id !== null && !$is_pending_request) {
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
}

// ── Log activity ──────────────────────────────────────────────────────────
logClientActivity($client_id, 'booking_created', 'Created booking #' . $booking_id . ' for ' . array_string_value($apt_type, 'name'), $conn);

$newsletter_opt_in_selected = false;
if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
    $newsletter_form_fields_by_template_id = [];
    $newsletter_template_ids = array_values(array_unique(array_filter(
        array_map('intval', array_keys($data['form_responses'])),
        static fn (int $template_id): bool => $template_id > 0
    )));

    if ($newsletter_template_ids !== []) {
        $newsletter_placeholders = implode(', ', array_fill(0, count($newsletter_template_ids), '?'));
        // nosemgrep: php.lang.security.injection.tainted-sql-string.tainted-sql-string -- placeholder count is derived from sanitized positive integers and values are parameterized.
        $stmt_newsletter_forms = $conn->prepare(
            "SELECT id, fields FROM form_templates WHERE id IN ($newsletter_placeholders)"
        );
        $stmt_newsletter_forms->execute($newsletter_template_ids);

        while ($newsletter_form_row = $stmt_newsletter_forms->fetch(PDO::FETCH_ASSOC)) {
            $newsletter_form = assoc_row($newsletter_form_row);
            if ($newsletter_form === []) {
                continue;
            }

            $newsletter_form_fields_by_template_id[array_int_value($newsletter_form, 'id')] = decode_json_assoc_list(
                array_string_value($newsletter_form, 'fields')
            );
        }
    }

    foreach ($data['form_responses'] as $template_id => $responses) {
        if (!is_array($responses)) {
            continue;
        }

        $template_id = (int) $template_id;
        if (!isset($newsletter_form_fields_by_template_id[$template_id])) {
            continue;
        }

        if (bdta_form_fields_include_newsletter_opt_in(
            $newsletter_form_fields_by_template_id[$template_id],
            $responses
        )) {
            $newsletter_opt_in_selected = true;
            break;
        }
    }
}

if ($newsletter_opt_in_selected) {
    $newsletter_result = bdta_subscribe_mailjet_contact_to_newsletter($client_email, $client_name);
    if (!$newsletter_result['success']) {
        error_log(
            'Mailjet newsletter opt-in failed for client portal booking #' . $booking_id . ': '
            . scalar_string($newsletter_result['message'])
        );
    }
}

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
$google_cal_link   = '';
$ical_link         = '';
if (!$is_pending_request) {
    $google_cal_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
    $ical_link       = $base_url . '/backend/public/download_ical.php?booking_id=' . $booking_id;
}

$email_service = new EmailService(null, $conn);
$email_result  = $is_pending_request
    ? $email_service->sendBookingRequest($booking)
    : $email_service->sendBookingConfirmation($booking);

$gcal_result = ['success' => false];
if (!$is_pending_request) {
    $gcal_result = GoogleCalendarIntegration::addEventForBooking($booking);
    if (!empty($gcal_result['event_id'])) {
        $conn->prepare("UPDATE bookings SET google_event_id = ? WHERE id = ?")
             ->execute([$gcal_result['event_id'], $booking_id]);
    }
}

$credit_applied = $pkg_credit_id !== null && !$is_pending_request;
$pending_credit_requested = $pkg_credit_id !== null && $is_pending_request;

if ($is_pending_request) {
    $message = 'Your appointment request has been received. We\'ll review it and email you once it is confirmed.';
    if ($pending_credit_requested) {
        $message .= ' If your appointment is confirmed and still eligible at that time, we\'ll attempt to apply your credit.';
    }
} elseif ($credit_applied) {
    $message = 'Your appointment has been successfully booked and a credit has been applied. Check your email for details and calendar links.';
} else {
    $message = 'Your appointment has been successfully booked. Check your email for details and calendar links.';
}

echo json_encode([
    'success'              => true,
    'booking_id'           => $booking_id,
    'booking_status'       => $initial_status,
    'message'              => $message,
    'credit_applied'       => $credit_applied,
    'calendar_links'       => [
        'google_calendar' => $google_cal_link,
        'ical_download'   => $ical_link,
    ],
    'email_sent'           => $email_result['success'],
    'google_calendar_synced' => $gcal_result['success'],
]);
