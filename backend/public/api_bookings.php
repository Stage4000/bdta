<?php
require_once '../includes/config.php';
require_once '../includes/booking_resources.php';
require_once '../includes/booking_availability.php';
require_once '../includes/contract_signing.php';
require_once '../includes/email_service.php';
require_once '../includes/google_calendar.php';
require_once '../includes/invoice_due.php';
require_once '../includes/form_types.php';
require_once '../includes/mailjet_newsletter.php';
require_once '../includes/public_access_links.php';
require_once '../includes/turnstile.php';
require_once '../includes/workflow_helper.php';

header('Content-Type: application/json');

$method = scalar_string($_SERVER['REQUEST_METHOD'] ?? '');

function api_booking_generate_invoice_number(SafePDO $conn): string {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM invoices WHERE invoice_number = ?");
    $max_attempts = 10;
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $invoice_number = 'INV-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt->execute([$invoice_number]);
        if (safe_int($stmt->fetchColumn()) === 0) {
            return $invoice_number;
        }
    }

    $invoice_number = 'INV-' . date('Ymd') . '-' . bin2hex(random_bytes(8));
    $stmt->execute([$invoice_number]);
    if (safe_int($stmt->fetchColumn()) > 0) {
        throw new RuntimeException('Unable to generate a unique invoice number.');
    }

    return $invoice_number;
}

function api_booking_mark_invoice_sent(SafePDO $conn, int $invoice_id): void {
    if ($invoice_id <= 0) {
        return;
    }

    $conn->prepare("
        UPDATE invoices
        SET
            status = CASE WHEN status = 'draft' THEN 'sent' ELSE status END,
            invoice_sent_at = COALESCE(invoice_sent_at, CURRENT_TIMESTAMP)
        WHERE id = ?
    ")->execute([$invoice_id]);
}

function api_booking_normalize_pet_profile_value(string $attr, mixed $value): string|int|null
{
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

function api_booking_parse_pet_profile_date(string $value): ?DateTime
{
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
function api_booking_order_verified_pet_ids(array $requested_pet_ids, array $verified_pet_ids): array
{
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
 * @return list<string>
 */
function api_booking_validate_pet_info_group_responses(SafePDO $conn, array $form_responses): array
{
    $errors = [];

    foreach ($form_responses as $tpl_id => $responses) {
        if (!is_array($responses)) {
            continue;
        }

        $tpl_stmt = $conn->prepare("SELECT fields FROM form_templates WHERE id = ?");
        $tpl_stmt->execute([(int)$tpl_id]);
        $tpl_row = api_booking_db_row($tpl_stmt->fetch(PDO::FETCH_ASSOC));
        if ($tpl_row === []) {
            continue;
        }

        $tpl_fields = api_booking_assoc_rows(array_string_value($tpl_row, 'fields'));
        foreach ($tpl_fields as $fi => $field) {
            if (!bdta_form_field_is_pet_info_group($field)) {
                continue;
            }

            foreach (bdta_form_field_pet_info_group_validate_response($field, $responses[$fi] ?? $responses[(string) $fi] ?? null) as $error) {
                $errors[] = $error;
            }
        }
    }

    return $errors;
}

/**
 * @param array<int|string, mixed> $form_responses
 * @return array<int, array<string, string|int>>
 */
function api_booking_collect_pet_profile_mapped_values(SafePDO $conn, array $form_responses): array
{
    $pet_col_map = [
        'name'              => true,
        'species'           => true,
        'breed'             => true,
        'date_of_birth'     => true,
        'age_years'         => true,
        'age_months'        => true,
        'source'            => true,
        'ownership_length_years' => true,
        'ownership_length_months' => true,
        'spayed_neutered'   => true,
        'vaccines_current'  => true,
        'vaccine_notes'     => true,
        'behavior_notes'    => true,
        'medical_notes'     => true,
        'training_notes'    => true,
        'pet_sitting_notes' => true,
    ];
    $pet_updates = [];

    foreach ($form_responses as $tpl_id => $responses) {
        if (!is_array($responses)) {
            continue;
        }

        $tpl_stmt = $conn->prepare("SELECT fields FROM form_templates WHERE id = ?");
        $tpl_stmt->execute([(int)$tpl_id]);
        $tpl_row = api_booking_db_row($tpl_stmt->fetch(PDO::FETCH_ASSOC));
        if ($tpl_row === []) {
            continue;
        }

        $tpl_fields = api_booking_assoc_rows(array_string_value($tpl_row, 'fields'));
        foreach ($tpl_fields as $fi => $field) {
            if (bdta_form_field_is_pet_info_group($field)) {
                foreach (bdta_form_field_pet_info_group_profile_values($field, $responses[$fi] ?? $responses[(string) $fi] ?? null) as $pet_index => $pet_profile) {
                    if (!isset($pet_updates[$pet_index])) {
                        $pet_updates[$pet_index] = [];
                    }
                    foreach ($pet_profile as $attr => $normalized_value) {
                        if (isset($pet_col_map[$attr])) {
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
            if (!isset($pet_col_map[$attr])) {
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
 * @return array<int, int>
 */
function api_booking_create_pets_from_profile_updates(SafePDO $conn, int $client_id, array $pet_updates): array
{
    if ($client_id <= 0 || $pet_updates === []) {
        return [];
    }

    $pet_columns = api_booking_table_columns($conn, 'pets');
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

    foreach ($pet_updates as $pet_index => $pet_profile) {
        $pet_name = trim(scalar_string($pet_profile['name'] ?? ''));
        if ($pet_name === '') {
            continue;
        }

        $find_pet_stmt->execute([$client_id, $pet_name]);
        $existing_pet_id = safe_int($find_pet_stmt->fetchColumn());

        $params = [];
        if ($existing_pet_id > 0) {
            $created_pet_ids[$pet_index] = $existing_pet_id;
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
        $created_pet_ids[$pet_index] = safe_int($conn->lastInsertId());
    }

    return $created_pet_ids;
}

/**
 * @param array<int, int> $pet_ids
 * @param array<int, array<string, string|int>> $pet_updates
 * @return array<int, int>
 */
function api_booking_clone_conflicting_pets(SafePDO $conn, int $client_id, array $pet_ids, array $pet_updates): array
{
    if ($client_id <= 0 || $pet_ids === [] || $pet_updates === []) {
        return $pet_ids;
    }

    $pet_columns = api_booking_table_columns($conn, 'pets');
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
        if ($pet_id <= 0 || $mapped_values === []) {
            continue;
        }

        $fetch_pet_stmt->execute([$pet_id, $client_id]);
        $cur_pet = api_booking_db_row($fetch_pet_stmt->fetch(PDO::FETCH_ASSOC));
        if ($cur_pet === []) {
            continue;
        }

        $has_conflict = false;
        foreach ($mapped_values as $attr => $new_value) {
            $existing_value = scalar_string($cur_pet[$attr] ?? '');
            if ($existing_value !== '' && $existing_value !== (string)$new_value) {
                $has_conflict = true;
                break;
            }
        }
        if (!$has_conflict) {
            continue;
        }

        $insert_columns = ['client_id'];
        $insert_sql = ['?'];
        $insert_values = [$client_id];

        foreach ($supported_attrs as $attr) {
            if (!in_array($attr, $pet_columns, true)) {
                continue;
            }

            $value = $mapped_values[$attr] ?? ($cur_pet[$attr] ?? null);
            if ($value === null && !in_array($attr, ['name', 'species'], true)) {
                continue;
            }
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
        $pet_ids[$pet_index] = safe_int($conn->lastInsertId());
    }

    /** @var list<int> $pet_ids */
    return $pet_ids;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function api_booking_create_booking(SafePDO $conn, array $data): array {
    $mapped_emails = [];
    if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
        $mapped_form_values = api_booking_extract_profile_mapped_form_values($conn, $data['form_responses'], $mapped_emails);
        foreach ($mapped_form_values as $key => $value) {
            if (array_string_value($data, $key) === '') {
                $data[$key] = $value;
            }
        }
    }

    $required_fields = ['client_name', 'client_email', 'service_type', 'appointment_date', 'appointment_time'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return ['error' => "Missing required field: $field"];
        }
    }

    $client_name = array_string_value($data, 'client_name');
    $client_email = array_string_value($data, 'client_email');
    $client_phone = array_string_value($data, 'client_phone');
    $service_type = array_string_value($data, 'service_type');
    $appointment_date = array_string_value($data, 'appointment_date');
    $appointment_time = array_string_value($data, 'appointment_time');
    $notes = array_string_value($data, 'notes');
    $appointment_type_id_value = safe_int($data['appointment_type_id'] ?? 0);
    $duration_minutes = safe_int($data['duration_minutes'] ?? 60);
    $apt_type = [];
    $requires_admin_confirmation = false;
    $resource_config = ['enabled' => false, 'name' => '', 'capacity' => 1, 'allocation' => 'per_appointment'];
    $schedule_lock = null;

    try {
        if (!filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Invalid email format for client_email'];
        }
        // Every email mapping must agree before any client, booking, or form writes.
        foreach ($mapped_emails as $mapped_email) {
            if (strcasecmp($mapped_email, $client_email) !== 0) {
                return ['error' => 'Please use the same email address throughout your booking details.'];
            }
        }

        // Email is intake data, not proof of ownership. Resolve the session owner first,
        // including when several legacy client records share an email address.
        $portal_client_id = isPortalLoggedIn() ? portalClientId() : 0;
        $existing_client = [];
        if ($portal_client_id > 0) {
            $stmt = $conn->prepare("SELECT id FROM clients WHERE id = ? AND email = ? AND COALESCE(is_archived, 0) = 0");
            $stmt->execute([$portal_client_id, $client_email]);
            $existing_client = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
        }
        if ($existing_client === []) {
            $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
            $stmt->execute([$client_email]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                return ['error' => 'Please verify your booking details by signing in to the client portal, or contact us for assistance.'];
            }
        }
        $client_id = $existing_client !== [] ? array_int_value($existing_client, 'id') : 0;

        $location = null;
        $location_type = trim(array_string_value($data, 'location_type'));
        $location_value = trim(array_string_value($data, 'location_value'));
        $submitted_client_address = trim(array_string_value($data, 'client_address'));
        $resolved_client_address = '';
        $overwrite_profile = filter_var($data['overwrite_profile'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $should_persist_client_address = false;
        $allowed_location_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall'];
        if ($appointment_type_id_value <= 0) {
            return ['error' => 'Invalid or inactive appointment type.'];
        }
        $appointment_type_admin_user_id = 0;

        $appointment_type_columns = api_booking_table_columns($conn, 'appointment_types');
        $appointment_type_select_map = [
            'name' => in_array('name', $appointment_type_columns, true) ? 'name' : "'' AS name",
            'description' => in_array('description', $appointment_type_columns, true) ? 'description' : "'' AS description",
            'is_mini_session' => in_array('is_mini_session', $appointment_type_columns, true) ? 'is_mini_session' : '0 AS is_mini_session',
            'mini_session_location' => in_array('mini_session_location', $appointment_type_columns, true) ? 'mini_session_location' : "'' AS mini_session_location",
            'is_field_rental' => in_array('is_field_rental', $appointment_type_columns, true) ? 'is_field_rental' : '0 AS is_field_rental',
            'field_rental_location' => in_array('field_rental_location', $appointment_type_columns, true) ? 'field_rental_location' : "'' AS field_rental_location",
            'is_group_class' => in_array('is_group_class', $appointment_type_columns, true) ? 'is_group_class' : '0 AS is_group_class',
            'group_class_location' => in_array('group_class_location', $appointment_type_columns, true) ? 'group_class_location' : "'' AS group_class_location",
            'location_types' => in_array('location_types', $appointment_type_columns, true) ? 'location_types' : "NULL AS location_types",
            'contract_template_id' => in_array('contract_template_id', $appointment_type_columns, true) ? 'contract_template_id' : 'NULL AS contract_template_id',
            'requires_admin_confirmation' => in_array('requires_admin_confirmation', $appointment_type_columns, true) ? 'requires_admin_confirmation' : '0 AS requires_admin_confirmation',
            'uses_resource' => in_array('uses_resource', $appointment_type_columns, true) ? 'uses_resource' : '0 AS uses_resource',
            'resource_name' => in_array('resource_name', $appointment_type_columns, true) ? 'resource_name' : "'' AS resource_name",
            'resource_capacity' => in_array('resource_capacity', $appointment_type_columns, true) ? 'resource_capacity' : '1 AS resource_capacity',
            'resource_allocation' => in_array('resource_allocation', $appointment_type_columns, true) ? 'resource_allocation' : "'per_appointment' AS resource_allocation",
            'duration_minutes' => in_array('duration_minutes', $appointment_type_columns, true) ? 'duration_minutes' : '60 AS duration_minutes',
            'buffer_before_minutes' => in_array('buffer_before_minutes', $appointment_type_columns, true) ? 'buffer_before_minutes' : '0 AS buffer_before_minutes',
            'buffer_after_minutes' => in_array('buffer_after_minutes', $appointment_type_columns, true) ? 'buffer_after_minutes' : '0 AS buffer_after_minutes',
            'admin_user_id' => in_array('admin_user_id', $appointment_type_columns, true) ? 'admin_user_id' : '0 AS admin_user_id',
            'auto_invoice' => in_array('auto_invoice', $appointment_type_columns, true) ? 'auto_invoice' : '0 AS auto_invoice',
            'invoice_due_days' => in_array('invoice_due_days', $appointment_type_columns, true) ? 'invoice_due_days' : '7 AS invoice_due_days',
            'invoice_due_timing' => in_array('invoice_due_timing', $appointment_type_columns, true) ? 'invoice_due_timing' : "'after' AS invoice_due_timing",
            'default_amount' => in_array('default_amount', $appointment_type_columns, true) ? 'default_amount' : '0 AS default_amount',
        ];
        $stmt = $conn->prepare("SELECT " . implode(', ', $appointment_type_select_map) . " FROM appointment_types WHERE id = ? AND is_active = 1");
        $stmt->execute([$appointment_type_id_value]);
        $apt_type = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
        if ($apt_type === []) {
            return ['error' => 'Invalid or inactive appointment type.'];
        }
        $requires_admin_confirmation = array_int_value($apt_type, 'requires_admin_confirmation') === 1;
        $resource_config = bdta_booking_resource_config($apt_type);
        $appointment_type_admin_user_id = array_int_value($apt_type, 'admin_user_id');
        $duration_minutes = array_int_value($apt_type, 'duration_minutes', $duration_minutes);
        if (!empty($apt_type['is_mini_session'])) {
            $location_type = 'fixed';
            $location = array_string_value($apt_type, 'mini_session_location');
        } elseif (!empty($apt_type['is_field_rental'])) {
            $location_type = 'fixed';
            $location = array_string_value($apt_type, 'field_rental_location');
        } elseif (!empty($apt_type['is_group_class'])) {
            $location_type = 'fixed';
            $location = array_string_value($apt_type, 'group_class_location');
        } elseif (!empty($apt_type['location_types'])) {
            $configured = api_booking_string_list(decode_json_assoc(array_string_value($apt_type, 'location_types')));
            if (!empty($configured)) {
                $allowed_location_types = array_values(array_diff($configured, ['fixed']));
            }
        }

        if (!empty($apt_type['contract_template_id'])) {
            $contract_typed_name = trim(array_string_value($data, 'contract_typed_name'));
            if (empty($contract_typed_name)) {
                return ['error' => 'You must sign the required contract (type your full name) to complete your booking.'];
            }
        }

        $is_pending_request = $requires_admin_confirmation;
        $initial_status = $is_pending_request ? 'pending' : 'confirmed';

        if ($location_type === 'fixed' && empty($apt_type['is_mini_session']) && empty($apt_type['is_field_rental']) && empty($apt_type['is_group_class'])) {
            return ['error' => 'A valid location type is required.'];
        }
        if ($location_type !== 'fixed') {
            if (empty($location_type) || !in_array($location_type, $allowed_location_types)) {
                return ['error' => 'A valid location type is required. Please select how the appointment will be conducted.'];
            }
            if (in_array($location_type, ['custom_address', 'webcall'], true) && empty($location_value)) {
                return ['error' => $location_type === 'webcall' ? 'Webcall URL is required.' : 'Custom address is required.'];
            }
            if ($location_type === 'client_address') {
                if ($submitted_client_address !== '' && mb_strlen($submitted_client_address) > 500) {
                    return ['error' => 'The address provided is too long. Please keep it under 500 characters.'];
                }

                if ($client_id > 0) {
                    $stmt = $conn->prepare("SELECT address FROM clients WHERE id = ?");
                    $stmt->execute([$client_id]);
                    $client_row = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
                    $resolved_client_address = trim(array_string_value($client_row, 'address'));
                }

                if ($client_id === 0) {
                    // New client: require an address in the form and use it for this booking.
                    if (!empty($submitted_client_address)) {
                        $location = $submitted_client_address;
                    } else {
                        return ['error' => 'An address is required for this booking. Please provide your address in the booking form.'];
                    }
                } else {
                    // Existing client.
                    if ($resolved_client_address === '') {
                        if ($submitted_client_address === '') {
                            // No stored address and none provided in the form.
                            return ['error' => 'Your account does not have an address on file. Please update your profile or choose a different location type.'];
                        }

                        // Existing client without a stored address: use the form-provided one.
                        $location = $submitted_client_address;
                        $should_persist_client_address = true;
                    } elseif ($overwrite_profile && $submitted_client_address !== '') {
                        // Client agreed to overwrite profile: use the new form address.
                        $location = $submitted_client_address;
                        $should_persist_client_address = true;
                    } else {
                        // Existing client with a stored address: keep using it when no replacement address was provided
                        // or the client declined overwriting their saved profile address.
                        $location = $resolved_client_address;
                    }
                }
            } else {
                $location = $location_value;
            }
        }

        $use_credit = ($data['use_credit'] ?? false) === true;
        $pkg_credit_id_to_use = null;
        if ($use_credit && $client_id > 0) {
            $stmt = $conn->prepare("
                SELECT cpc.id
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
            $stmt->execute([$client_id, $appointment_type_id_value]);
            $credit_row = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
            if ($credit_row !== []) {
                $pkg_credit_id_to_use = array_int_value($credit_row, 'id');
            }
        }
        $package_credit_id_for_booking = $is_pending_request ? null : $pkg_credit_id_to_use;

        $contract_typed_name = trim(array_string_value($data, 'contract_typed_name'));
        $allowed_sig_fonts = ['font-dancing', 'font-pacifico', 'font-satisfy', 'font-great-vibes', 'font-allura'];
        $contract_signature_font = array_string_value($data, 'contract_signature_font');
        $contract_sig_font = in_array($contract_signature_font, $allowed_sig_fonts, true)
            ? $contract_signature_font
            : 'font-dancing';
        $contract_accepted = !empty($contract_typed_name) ? 1 : 0;
        $contract_accepted_at = $contract_accepted ? date('Y-m-d H:i:s') : null;

        $schedule_lock = new BookingScheduleLock($conn);
        $slot_error = bdta_booking_slot_error($conn, $appointment_type_id_value, $appointment_date, $appointment_time);
        if ($slot_error !== null) { return ['error' => $slot_error]; }
        $appointment_time = substr($appointment_time, 0, 5);
        $conn->beginTransaction();

        if ($client_id === 0) {
            $client_address = trim(array_string_value($data, 'client_address'));
            $stmt = $conn->prepare("
                INSERT INTO clients (name, email, phone, address, notes, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ");
            $stmt->execute([
                $client_name,
                $client_email,
                $client_phone,
                !empty($client_address) ? $client_address : null,
                'Created from booking form'
            ]);
            $client_id = safe_int($conn->lastInsertId());
        }

        $pet_ids = [];
        $requested_pet_ids = [];
        $pet_ids_raw = $data['pet_ids'] ?? [];
        if (is_array($pet_ids_raw) && $pet_ids_raw !== []) {
            $requested_pet_ids = array_values(array_filter(
                array_unique(array_map('safe_int', $pet_ids_raw)),
                static fn (int $pet_id): bool => $pet_id > 0
            ));
            $requested_pet_ids = array_slice($requested_pet_ids, 0, 100);
        }
        if ($requested_pet_ids !== [] && $client_id > 0 && $portal_client_id === $client_id) {
            $placeholders = implode(', ', array_fill(0, count($requested_pet_ids), '?'));
            // nosemgrep: php.doctrine.security.audit.doctrine-dbal-dangerous-query.doctrine-dbal-dangerous-query, php.lang.security.injection.tainted-sql-string.tainted-sql-string -- placeholder count comes from safe_int()-sanitized positive pet IDs and every value is bound separately.
            $stmt = $conn->prepare("SELECT id FROM pets WHERE client_id = ? AND is_active = 1 AND id IN ($placeholders)");
            $stmt->execute(array_merge([$client_id], $requested_pet_ids));
            $verified_pet_ids = array_map('safe_int', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
            $pet_ids = api_booking_order_verified_pet_ids($requested_pet_ids, $verified_pet_ids);
        }

        $pet_updates = [];
        if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
            /** @var array<int|string, mixed> $form_responses */
            $form_responses = $data['form_responses'];
            $pet_info_group_errors = api_booking_validate_pet_info_group_responses($conn, $form_responses);
            if ($pet_info_group_errors !== []) {
                $conn->rollBack();
                return ['error' => $pet_info_group_errors[0]];
            }
            $pet_updates = api_booking_collect_pet_profile_mapped_values($conn, $form_responses);
        }

        if ($pet_ids === [] && $pet_updates !== []) {
            $pet_ids = api_booking_create_pets_from_profile_updates($conn, $client_id, $pet_updates);
        }

        $dog_names = array_string_value($data, 'dog_names');
        if ($pet_ids === [] && !empty($dog_names)) {
            $names = array_filter(
                array_map('trim', explode(',', $dog_names)),
                fn($n) => $n !== ''
            );

            if (!empty($names)) {
                $placeholders = str_repeat('?,', count($names) - 1) . '?';
                $stmt = $conn->prepare("SELECT id, name FROM pets WHERE client_id = ? AND name IN ($placeholders)");
                $stmt->execute(array_merge([$client_id], $names));
                $existing_pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $existing_pet_map = [];
                foreach ($existing_pets as $pet) {
                    $pet_row = api_booking_db_row($pet);
                    $existing_pet_map[array_string_value($pet_row, 'name')] = array_int_value($pet_row, 'id');
                }

                foreach ($names as $dog_name) {
                    if (isset($existing_pet_map[$dog_name])) {
                        $pet_ids[] = $existing_pet_map[$dog_name];
                    } else {
                        $stmt = $conn->prepare("
                            INSERT INTO pets (client_id, name, species, is_active, created_at, updated_at)
                            VALUES (?, ?, 'Dog', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
                        ");
                        $stmt->execute([$client_id, $dog_name]);
                        $pet_ids[] = safe_int($conn->lastInsertId());
                    }
                }
            }
        }

        $overwrite_declined = isset($data['overwrite_profile']) && !(bool)$data['overwrite_profile'];
        if ($overwrite_declined) {
            if ($pet_updates !== []) {
                $pet_ids = api_booking_clone_conflicting_pets($conn, $client_id, $pet_ids, $pet_updates);
            }
        }

        $slot_error = bdta_booking_slot_error($conn, $appointment_type_id_value, $appointment_date, $appointment_time, 0,
            bdta_booking_resource_units($resource_config, count($pet_ids)));
        if ($slot_error !== null) {
            $conn->rollBack();
            return ['error' => $slot_error];
        }
        $stmt = $conn->prepare("
            INSERT INTO bookings (client_id, appointment_type_id, admin_user_id, client_name, client_email, client_phone, service_type, appointment_date, appointment_time, notes, duration_minutes, location, location_type, package_credit_id, contract_accepted, contract_accepted_at, contract_signature_name, contract_signature_font, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $client_id,
            $appointment_type_id_value,
            $appointment_type_admin_user_id > 0 ? $appointment_type_admin_user_id : null,
            $client_name,
            $client_email,
            $client_phone,
            $service_type,
            $appointment_date,
            $appointment_time,
            $notes,
            $duration_minutes,
            $location,
            $location_type,
            $package_credit_id_for_booking,
            $contract_accepted,
            $contract_accepted_at,
            $contract_accepted ? $contract_typed_name : null,
            $contract_accepted ? $contract_sig_font : null,
            $initial_status
        ]);

        $booking_id = safe_int($conn->lastInsertId());
        $booking_notification_title = $initial_status === 'pending'
            ? 'New appointment request'
            : 'New appointment booked';
        $booking_notification_message = $client_name . ' booked ' . $service_type . ' for ' . $appointment_date;
        bdta_create_admin_notifications(
            $conn,
            'booking',
            $booking_id,
            $booking_notification_title,
            $booking_notification_message,
            '/client/bookings_list.php'
        );

        if ($contract_accepted && !empty($apt_type['contract_template_id'])) {
            bdta_create_signed_contract_from_template(
                $conn,
                $client_id,
                array_int_value($apt_type, 'contract_template_id'),
                $contract_typed_name,
                $contract_sig_font,
                $contract_accepted_at,
                null,
                scalar_string($_SERVER['HTTP_USER_AGENT'] ?? ''),
                [
                    'name' => $client_name,
                    'email' => $client_email,
                ]
            );
        }

        if (!empty($pet_ids)) {
            foreach ($pet_ids as $pet_id) {
                $stmt = $conn->prepare("
                    INSERT INTO appointment_pets (booking_id, pet_id, created_at)
                    VALUES (?, ?, CURRENT_TIMESTAMP)
                ");
                $stmt->execute([$booking_id, $pet_id]);
            }
        }

        $workflow_helper = new WorkflowHelper($conn);
        if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
            /** @var array<int|string, mixed> $form_responses */
            $form_responses = $data['form_responses'];
            $template_frequency_stmt = null;
            $insert_supports_pet_id = true;
            try {
                $template_frequency_stmt = $conn->prepare("SELECT required_frequency FROM form_templates WHERE id = ?");
            } catch (\Throwable $e) {
                $template_frequency_stmt = null;
            }
            try {
                $ins = $conn->prepare("INSERT INTO form_submissions (client_id, template_id, booking_id, pet_id, responses, status, submitted_at) VALUES (?, ?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)");
            } catch (\Throwable $e) {
                $insert_supports_pet_id = false;
                $ins = $conn->prepare("INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at) VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)");
            }
            foreach ($form_responses as $template_id => $responses) {
                if (is_array($responses) && !empty($responses)) {
                    $template_id = (int) $template_id;
                    $template_frequency = '';
                    if ($template_frequency_stmt !== null) {
                        try {
                            $template_frequency_stmt->execute([$template_id]);
                            $template_frequency = scalar_string($template_frequency_stmt->fetchColumn());
                        } catch (\Throwable $e) {
                            $template_frequency = '';
                        }
                    }
                    $submission_pet_ids = $insert_supports_pet_id
                        ? bdta_get_form_submission_pet_ids($template_frequency, array_values($pet_ids))
                        : [null];
                    foreach ($submission_pet_ids as $submission_pet_id) {
                        $params = $insert_supports_pet_id
                            ? [$client_id, $template_id, $booking_id, $submission_pet_id, json_encode($responses)]
                            : [$client_id, $template_id, $booking_id, json_encode($responses)];
                        $ins->execute($params);
                        $form_submission_id = scalar_string($conn->lastInsertId());
                        try {
                            $workflow_helper->checkFormTriggers($form_submission_id);
                        } catch (\Throwable $e) {
                            error_log("Workflow trigger error for form submission #{$form_submission_id}: " . $e->getMessage());
                        }
                    }
                }
            }
        }

        $client_col_map = [
            'name'    => 'name',
            'email'   => 'email',
            'phone'   => 'phone',
            'address' => 'address',
        ];
        $pet_col_map = [
            'name'            => 'name',
        'species'         => 'species',
        'breed'           => 'breed',
        'date_of_birth'   => 'date_of_birth',
        'age_years'       => 'age_years',
        'age_months'      => 'age_months',
        'source'          => 'source',
        'ownership_length_years'  => 'ownership_length_years',
        'ownership_length_months' => 'ownership_length_months',
        'spayed_neutered'        => 'spayed_neutered',
        'vaccines_current'       => 'vaccines_current',
        'vaccine_notes'          => 'vaccine_notes',
        'behavior_notes'         => 'behavior_notes',
        'medical_notes'          => 'medical_notes',
        'training_notes'         => 'training_notes',
        'pet_sitting_notes'      => 'pet_sitting_notes',
        ];
        $overwrite_declined = isset($data['overwrite_profile']) && !(bool)$data['overwrite_profile'];

        if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
            /** @var array<int|string, mixed> $form_responses */
            $form_responses = $data['form_responses'];
            $booking_pet_ids = $pet_ids;

            $cur_client_stmt = $conn->prepare("SELECT name, email, phone, address FROM clients WHERE id = ?");
            $cur_client_stmt->execute([$client_id]);
            $cur_client = api_booking_db_row($cur_client_stmt->fetch(PDO::FETCH_ASSOC));

            foreach ($form_responses as $tpl_id => $responses) {
                if (!is_array($responses)) continue;

                $tpl_stmt = $conn->prepare("SELECT fields FROM form_templates WHERE id = ?");
                $tpl_stmt->execute([(int)$tpl_id]);
                $tpl_row = api_booking_db_row($tpl_stmt->fetch(PDO::FETCH_ASSOC));
                if ($tpl_row === []) continue;

                $tpl_fields = api_booking_assoc_rows(array_string_value($tpl_row, 'fields'));

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

                        $existing = scalar_string($cur_client[$attr] ?? '');
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

                        $own = $conn->prepare("SELECT * FROM pets WHERE id = ? AND client_id = ?");
                        $own->execute([$pet_id, $client_id]);
                        $cur_pet = api_booking_db_row($own->fetch(PDO::FETCH_ASSOC));
                        if ($cur_pet === []) continue;

                        if ($attr === 'date_of_birth') {
                            $dt = api_booking_parse_pet_profile_date($value);
                            if (!$dt) continue;
                            $value = $dt->format('Y-m-d');
                        } elseif (in_array($attr, ['spayed_neutered', 'vaccines_current'], true)) {
                            $value = in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true) ? 1 : 0;
                        }

                        $existing_pet_val = scalar_string($cur_pet[$attr] ?? '');
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

        if ($should_persist_client_address && $client_id > 0 && $submitted_client_address !== '') {
            $conn->prepare("UPDATE clients SET address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$submitted_client_address, $client_id]);
            logClientActivity(
                $client_id,
                'profile_update_from_booking',
                "Client address updated during registered-address booking #{$booking_id}",
                $conn
            );
        }

        $workflow_helper->checkAppointmentTriggers(scalar_string($booking_id));

        if ($pkg_credit_id_to_use && !$is_pending_request) {
            $conn->prepare("
                UPDATE client_package_credits
                SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$pkg_credit_id_to_use]);

            $apt_type_id_for_log = $appointment_type_id_value;
            $conn->prepare("
                INSERT INTO package_credit_transactions
                    (client_package_credit_id, client_id, appointment_type_id, transaction_type, amount, booking_id, notes, created_by)
                VALUES (?, ?, ?, 'consume', -1, ?, ?, NULL)
            ")->execute([
                $pkg_credit_id_to_use,
                $client_id,
                $apt_type_id_for_log,
                $booking_id,
                "Credit applied at booking #{$booking_id} via client portal"
            ]);
        }

        $invoice = null;
        $invoice_items = [];
        if (!$is_pending_request && array_int_value($apt_type, 'auto_invoice') === 1) {
            $default_amount = safe_float($apt_type['default_amount'] ?? 0);
            $invoice_due_days = max(0, array_int_value($apt_type, 'invoice_due_days', 7));
            $invoice_due_timing = bdta_normalize_invoice_due_timing($apt_type['invoice_due_timing'] ?? 'after');
            $invoice_number = api_booking_generate_invoice_number($conn);
            $issue_date = date('Y-m-d');
            $due_date = bdta_calculate_invoice_due_date($appointment_date, $invoice_due_days, $invoice_due_timing);
            $pay_token = bin2hex(random_bytes(32));
            $invoice_line_description = trim(array_string_value($apt_type, 'name', $service_type));
            $appointment_type_description = trim(array_string_value($apt_type, 'description'));
            if ($appointment_type_description !== '') {
                $invoice_line_description .= ' — ' . $appointment_type_description;
            }

            $stmt = $conn->prepare("
                INSERT INTO invoices (invoice_number, client_id, issue_date, due_date, subtotal, tax_rate, tax_amount, total_amount, notes, status, pay_token)
                VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, 'draft', ?)
            ");
            $invoice_notes = "Auto-generated for booking #{$booking_id} ({$service_type})";
            $stmt->execute([$invoice_number, $client_id, $issue_date, $due_date, $default_amount, $default_amount, $invoice_notes, $pay_token]);
            $invoice_id = safe_int($conn->lastInsertId());

            $stmt = $conn->prepare("
                INSERT INTO invoice_items (invoice_id, item_type, reference_id, description, quantity, rate, amount)
                VALUES (?, 'appointment_type', ?, ?, 1, ?, ?)
            ");
            $stmt->execute([$invoice_id, $appointment_type_id_value, $invoice_line_description, $default_amount, $default_amount]);

            $invoice = [
                'id' => $invoice_id,
                'client_id' => $client_id,
                'client_name' => $client_name,
                'client_email' => $client_email,
                'invoice_number' => $invoice_number,
                'issue_date' => $issue_date,
                'due_date' => $due_date,
                'total_amount' => $default_amount,
                'status' => 'draft',
                'pay_token' => $pay_token,
            ];
            $invoice_items[] = [
                'item_type' => 'appointment_type',
                'reference_id' => $appointment_type_id_value,
                'description' => $invoice_line_description,
                'quantity' => 1,
                'rate' => $default_amount,
                'amount' => $default_amount,
            ];
        }

        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
        if ($booking === []) {
            throw new RuntimeException('Booking record not found after insert');
        }

        $conn->commit();
        $schedule_lock->release();

        $newsletter_opt_in_selected = false;
        if (!empty($data['booking_form_id']) && isset($data['booking_intake_fields']) && is_array($data['booking_intake_fields'])) {
            $stmt_newsletter_booking_form = $conn->prepare("SELECT fields FROM form_templates WHERE id = ? AND form_type = 'booking_form'");
            $stmt_newsletter_booking_form->execute([safe_int($data['booking_form_id'])]);
            $newsletter_booking_form = api_booking_db_row($stmt_newsletter_booking_form->fetch(PDO::FETCH_ASSOC));
            if ($newsletter_booking_form !== []) {
                $newsletter_opt_in_selected = bdta_form_fields_include_newsletter_opt_in(
                    api_booking_assoc_rows(array_string_value($newsletter_booking_form, 'fields')),
                    $data['booking_intake_fields']
                );
            }
        }

        if (!$newsletter_opt_in_selected && !empty($data['form_responses']) && is_array($data['form_responses'])) {
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
                    $newsletter_form = api_booking_db_row($newsletter_form_row);
                    if ($newsletter_form === []) {
                        continue;
                    }

                    $newsletter_form_fields_by_template_id[array_int_value($newsletter_form, 'id')] = api_booking_assoc_rows(
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
                    'Mailjet newsletter opt-in failed for booking #' . $booking_id . ': '
                    . scalar_string($newsletter_result['message'])
                );
            }
        }

        $google_calendar_link = '';
        $ical_download_link = '';
        if (!$is_pending_request) {
            require_once __DIR__ . '/../includes/icalendar.php';
            $ical_download_link = bdta_get_public_booking_ical_url($conn, $booking_id, $booking['ical_token'] ?? null);
            try {
                $google_calendar_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
            } catch (Throwable $e) {
                error_log('api_booking_create_booking: calendar link generation failed for booking #' . $booking_id . ': ' . $e->getMessage());
            }
        }

        $email_result = ['success' => false];
        try {
            $email_service = new EmailService(null, $conn);
            $email_result = $is_pending_request
                ? $email_service->sendBookingRequest($booking)
                : $email_service->sendBookingConfirmation($booking);
            if ($invoice !== null) {
                $invoice_email_result = $email_service->sendInvoiceEmail($invoice, $invoice_items);
                if (!empty($invoice_email_result['success'])) {
                    api_booking_mark_invoice_sent($conn, safe_int($invoice['id']));
                }
            }
        } catch (Throwable $e) {
            error_log('api_booking_create_booking: booking email failed for booking #' . $booking_id . ': ' . $e->getMessage());
        }

        $google_result = ['success' => false, 'message' => 'Google Calendar integration not configured'];
        if (!$is_pending_request) {
            try {
                $google_result = GoogleCalendarIntegration::addEventForBooking($booking);
                if (!empty($google_result['event_id'])) {
                    $conn->prepare("UPDATE bookings SET google_event_id = ? WHERE id = ?")
                         ->execute([$google_result['event_id'], $booking_id]);
                }
            } catch (Throwable $e) {
                $google_result = ['success' => false, 'message' => 'Google Calendar sync failed'];
                error_log('api_booking_create_booking: Google Calendar sync failed for booking #' . $booking_id . ': ' . $e->getMessage());
            }
        }

        $credit_applied = $pkg_credit_id_to_use !== null && !$is_pending_request;
        $pending_credit_requested = $pkg_credit_id_to_use !== null && $is_pending_request;
        if ($is_pending_request) {
            $message = 'Your appointment request has been received. We\'ll review it and email you once it is confirmed.';
            if ($pending_credit_requested) {
                $message .= ' If your appointment is confirmed and still eligible at that time, we\'ll attempt to apply your credit.';
            }
        } elseif ($credit_applied) {
            $message = 'Your appointment has been successfully booked and a credit has been applied. Check your email for confirmation details and calendar links.';
        } else {
            $message = 'Your appointment has been successfully booked. Check your email for confirmation details and calendar links.';
        }

        return [
            'success' => true,
            'message' => $message,
            'booking_id' => $booking_id,
            'booking_status' => $initial_status,
            'credit_applied' => $credit_applied,
            'calendar_links' => [
                'google_calendar' => $google_calendar_link,
                'ical_download' => $ical_download_link
            ],
            'email_sent' => $email_result['success'],
            'google_calendar_synced' => $google_result['success']
        ];
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Error in api_booking_create_booking (PDOException): ' . $e->getMessage());
        return ['error' => 'An unexpected error occurred while creating the booking. Please try again later.'];
    } catch (RuntimeException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Error in api_booking_create_booking (RuntimeException): ' . $e->getMessage());
        return ['error' => 'An unexpected error occurred while creating the booking. Please try again later.'];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        return ['error' => $e->getMessage()];
    } finally {
        $schedule_lock?->release();
    }
}

/**
 * @param array<int|string, mixed> $form_responses
 * @param list<string> $mapped_emails
 * @return array<string, string>
 */
function api_booking_extract_profile_mapped_form_values(SafePDO $conn, array $form_responses, array &$mapped_emails = []): array {
    $mapped_values = [];
    $template_ids = [];

    foreach ($form_responses as $tpl_id => $responses) {
        if (is_array($responses)) {
            $template_ids[] = (int) $tpl_id;
        }
    }

    $template_ids = array_unique(array_filter($template_ids, static fn (int $id): bool => $id > 0));
    if ($template_ids === []) {
        return $mapped_values;
    }

    $placeholders = implode(',', array_fill(0, count($template_ids), '?'));
    $tpl_stmt = $conn->prepare("SELECT id, fields FROM form_templates WHERE id IN ($placeholders)");
    $tpl_stmt->execute($template_ids);
    $template_fields_by_id = [];
    foreach ($tpl_stmt->fetchAll(PDO::FETCH_ASSOC) as $tpl_row) {
        $tpl_row = api_booking_db_row($tpl_row);
        $template_fields_by_id[array_int_value($tpl_row, 'id')] = api_booking_assoc_rows(array_string_value($tpl_row, 'fields'));
    }

    foreach ($form_responses as $tpl_id => $responses) {
        if (!is_array($responses)) {
            continue;
        }

        $tpl_fields = $template_fields_by_id[(int) $tpl_id] ?? [];
        if ($tpl_fields === []) {
            continue;
        }

        foreach ($tpl_fields as $fi => $field) {
            $mapping = array_string_value($field, 'profile_mapping');
            if ($mapping === '') {
                continue;
            }

            $value = $responses[$fi] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (is_array($value)) {
                $value = implode(', ', string_list($value));
            }
            $value = trim(scalar_string($value));
            if ($value === '') {
                continue;
            }

            if ($mapping === 'client.email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $mapped_emails[] = $value;
            }
            if ($mapping === 'client.name' && !isset($mapped_values['client_name'])) {
                $mapped_values['client_name'] = $value;
            } elseif ($mapping === 'client.email' && !isset($mapped_values['client_email'])) {
                $mapped_values['client_email'] = $value;
            } elseif ($mapping === 'client.phone' && !isset($mapped_values['client_phone'])) {
                $mapped_values['client_phone'] = $value;
            } elseif ($mapping === 'client.address' && !isset($mapped_values['client_address'])) {
                $mapped_values['client_address'] = $value;
            } elseif ($mapping === 'pet_1.name' && !isset($mapped_values['dog_names'])) {
                $mapped_values['dog_names'] = $value;
            } elseif ($mapping === 'booking.notes' && !isset($mapped_values['notes'])) {
                $mapped_values['notes'] = $value;
            }
        }
    }

    return $mapped_values;
}

/**
 * @param array<string, mixed> $input
 */
function api_booking_should_respect_google_calendar(array $input): bool
{
    if (!array_key_exists('respect_google_calendar', $input)) {
        return true;
    }

    $value = $input['respect_google_calendar'];
    if (!is_scalar($value)) {
        return true;
    }

    $parsed = filter_var((string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? true;
}

if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'credits') {
    // Credit details belong only to the active authenticated portal owner.
    $email = scalar_string($_GET['email'] ?? '');
    $appointment_type_id = isset($_GET['appointment_type_id']) ? safe_int($_GET['appointment_type_id']) : 0;

    if (!$email || !$appointment_type_id || !isPortalLoggedIn() || portalClientId() <= 0) {
        echo json_encode(['credits' => []]);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT id FROM clients WHERE id = ? AND email = ? AND COALESCE(is_archived, 0) = 0");
    $stmt->execute([portalClientId(), $email]);
    $client_row = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));

    if ($client_row === []) {
        echo json_encode(['credits' => []]);
        exit;
    }

    $client_id = array_int_value($client_row, 'id');

    // Fetch active, non-expired package credits for this client + appointment type
    $stmt = $conn->prepare("
        SELECT cpc.id, cpc.client_package_id,
               (cpc.total_credits - cpc.used_credits) AS remaining,
               cp.package_name
        FROM client_package_credits cpc
        JOIN client_packages cp ON cpc.client_package_id = cp.id
        WHERE cpc.client_id = ?
          AND cpc.appointment_type_id = ?
          AND (cpc.total_credits - cpc.used_credits) > 0
          AND cp.is_active = 1
          AND (cp.expires_at IS NULL OR cp.expires_at > CURRENT_TIMESTAMP)
        ORDER BY cp.expires_at ASC
    ");
    $stmt->execute([$client_id, $appointment_type_id]);
    $credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['credits' => $credits]);
    exit;

} elseif ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'profile') {
    // Prefill and conflict detection may read only the active authenticated owner's profile.
    $email      = trim(scalar_string($_GET['email'] ?? ''));
    $dog_names_raw = trim(scalar_string($_GET['dog_names'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isPortalLoggedIn() || portalClientId() <= 0) {
        echo json_encode(['client' => null, 'pets' => []]);
        exit;
    }

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT id, name, email, phone, address FROM clients WHERE id = ? AND email = ? AND COALESCE(is_archived, 0) = 0");
    $stmt->execute([portalClientId(), $email]);
    $client_row = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));

    if ($client_row === []) {
        echo json_encode(['client' => null, 'pets' => []]);
        exit;
    }

    $client_id = array_int_value($client_row, 'id');

    // Resolve ordered pet list from dog_names (same logic as POST handler)
    $dog_name_list = array_values(array_filter(array_map('trim', explode(',', $dog_names_raw)), fn($n) => $n !== ''));
    $ordered_pets  = [];

    if (!empty($dog_name_list)) {
        $placeholders = implode(',', array_fill(0, count($dog_name_list), '?'));
        $stmt = $conn->prepare("
            SELECT id, name, breed, date_of_birth, species, source, spayed_neutered, vaccines_current
            FROM pets WHERE client_id = ? AND name IN ($placeholders) AND is_active = 1
        ");
        $stmt->execute(array_merge([$client_id], $dog_name_list));
        $pet_map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $pet_row = api_booking_db_row($p);
            $pet_map[array_string_value($pet_row, 'name')] = $pet_row;
        }
        foreach ($dog_name_list as $dname) {
            $ordered_pets[] = $pet_map[$dname] ?? null; // null = new pet (no existing profile)
        }
    }

    echo json_encode([
        'client' => [
            'name'    => array_string_value($client_row, 'name'),
            'email'   => array_string_value($client_row, 'email'),
            'phone'   => array_string_value($client_row, 'phone'),
            'address' => array_string_value($client_row, 'address'),
        ],
        'pets' => array_map(fn($p) => is_array($p) ? [
            'name'             => array_string_value($p, 'name'),
            'species'          => array_string_value($p, 'species'),
            'breed'            => array_string_value($p, 'breed'),
            'date_of_birth'    => array_string_value($p, 'date_of_birth'),
            'source'           => array_string_value($p, 'source'),
            'spayed_neutered'  => !empty($p['spayed_neutered']) ? 'yes' : '',
            'vaccines_current' => !empty($p['vaccines_current']) ? 'yes' : '',
        ] : null, $ordered_pets),
    ]);
    exit;

} elseif ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'available_dates') {

    /**
     * Helper: returns true if the given slot does NOT conflict with any GCal busy period.
     *
     * @param string $date          YYYY-MM-DD
     * @param string $slot_str      HH:MM
     * @param int    $duration_min  appointment duration in minutes
     * @param int    $buf_before    buffer before in minutes
     * @param int    $buf_after     buffer after in minutes
     * @param array<int, array{start: string, end: string}> $busy_periods flat array of ['start'=>RFC3339, 'end'=>RFC3339]
     */
    function ad_slot_passes_gcal(string $date, string $slot_str, int $duration_min, int $buf_before, int $buf_after, array $busy_periods): bool {
        $slot_ts    = strtotime($date . 'T' . $slot_str . ':00');
        $buf_s_ts   = $slot_ts - $buf_before * 60;
        $buf_e_ts   = $slot_ts + ($duration_min + $buf_after) * 60;
        foreach ($busy_periods as $busy) {
            if (empty($busy['start']) || empty($busy['end'])) continue;
            $bs = strtotime($busy['start']);
            $be = strtotime($busy['end']);
            if ($bs === false || $be === false) continue;
            if ($buf_s_ts < $be && $bs < $buf_e_ts) {
                return false; // conflict
            }
        }
        return true;
    }

    // Return a list of dates (within a given range) that have at least one available slot.
    // Used by the booking UI to hide dates with no availability from the date selector.
    $appointment_type_id = isset($_GET['appointment_type_id']) ? safe_int($_GET['appointment_type_id']) : 0;
    $from_date = scalar_string($_GET['from'] ?? date('Y-m-d'));
    $to_date   = scalar_string($_GET['to']   ?? date('Y-m-d', strtotime('+60 days')));
    $respect_google_calendar = api_booking_should_respect_google_calendar($_GET);

    // Sanitize date params
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date)) {
        $from_date = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
        $to_date = date('Y-m-d', strtotime('+60 days'));
    }
    // Enforce minimum of today and cap range at 365 days
    $today_str = date('Y-m-d');
    if ($from_date < $today_str) {
        $from_date = $today_str;
    }
    $max_to = date('Y-m-d', safe_timestamp(strtotime($from_date . ' +365 days')));
    if ($to_date > $max_to) {
        $to_date = $max_to;
    }
    if ($to_date < $from_date) {
        $to_date = $from_date;
    }

    if (!$appointment_type_id) {
        echo json_encode(['available_dates' => [], 'schedule_type' => 'recurring']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT available_days, available_start_time, available_end_time, time_slot_interval,
               schedule_type, specific_date, specific_dates, per_day_schedule,
               duration_minutes, is_group_class, max_participants,
               buffer_before_minutes, buffer_after_minutes,
               advance_booking_min_days, advance_booking_max_days, admin_user_id,
               uses_resource, resource_name, resource_capacity, resource_allocation
        FROM appointment_types
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$appointment_type_id]);
    $appt_type = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));

    if ($appt_type === []) {
        echo json_encode(['available_dates' => [], 'schedule_type' => 'recurring']);
        exit;
    }

    $ad_schedule_type   = array_string_value($appt_type, 'schedule_type', 'recurring');
    $ad_available_days  = api_booking_int_list(decode_json_assoc(array_string_value($appt_type, 'available_days', '[0,1,2,3,4,5,6]')));
    if ($ad_available_days === []) {
        $ad_available_days = [0, 1, 2, 3, 4, 5, 6];
    }
    $ad_start_time     = array_string_value($appt_type, 'available_start_time', '09:00');
    $ad_end_time       = array_string_value($appt_type, 'available_end_time', '17:00');
    $ad_interval       = max(1, array_int_value($appt_type, 'time_slot_interval', 30)); // guard against 0
    $ad_duration       = array_int_value($appt_type, 'duration_minutes', 60);
    $ad_is_group       = !empty($appt_type['is_group_class']);
    $ad_max_part       = max(1, array_int_value($appt_type, 'max_participants', 1));
    $ad_buf_before     = max(0, array_int_value($appt_type, 'buffer_before_minutes'));
    $ad_buf_after      = max(0, array_int_value($appt_type, 'buffer_after_minutes'));
    $ad_admin_user_id  = array_int_value($appt_type, 'admin_user_id');
    $ad_resource       = bdta_booking_resource_config($appt_type);
    $ad_per_day        = api_booking_assoc_map(array_string_value($appt_type, 'per_day_schedule'));
    // Advance-booking window: honour the appointment type's min/max booking lead time
    $ad_min_days       = max(0, array_int_value($appt_type, 'advance_booking_min_days'));
    $ad_max_days       = max(1, array_int_value($appt_type, 'advance_booking_max_days', 365));

    // Tighten from_date by the minimum advance notice (e.g. min_days=1 → earliest is tomorrow)
    $advance_min_from = date('Y-m-d', safe_timestamp(strtotime($today_str . ' +' . $ad_min_days . ' days')));
    if ($from_date < $advance_min_from) {
        $from_date = $advance_min_from;
    }
    // Cap to_date by the maximum booking window
    $advance_max_to = date('Y-m-d', safe_timestamp(strtotime($today_str . ' +' . $ad_max_days . ' days')));
    if ($to_date > $advance_max_to) {
        $to_date = $advance_max_to;
    }
    if ($to_date < $from_date) {
        echo json_encode(['available_dates' => [], 'schedule_type' => $ad_schedule_type]);
        exit;
    }

    // Build the list of candidate dates to evaluate
    $candidate_dates = [];
    if ($ad_schedule_type === 'specific_date') {
        // Only check the configured specific dates that fall in the requested range
        foreach (api_booking_assoc_rows(array_string_value($appt_type, 'specific_dates')) as $sd_entry) {
            $d = array_string_value($sd_entry, 'date');
            if ($d >= $from_date && $d <= $to_date) {
                $candidate_dates[] = $d;
            }
        }
        $legacy_specific_date = array_string_value($appt_type, 'specific_date');
        if (empty($candidate_dates) && $legacy_specific_date !== '') {
            $d = $legacy_specific_date;
            if ($d >= $from_date && $d <= $to_date) {
                $candidate_dates[] = $d;
            }
        }
        sort($candidate_dates);
    } else {
        // Recurring: check every date in the range that falls on an allowed day of week
        $cur = new DateTime($from_date);
        $end = new DateTime($to_date);
        while ($cur <= $end) {
            if (in_array((int)$cur->format('w'), $ad_available_days)) {
                $candidate_dates[] = $cur->format('Y-m-d');
            }
            $cur->modify('+1 day');
        }
    }

    // Pre-fetch all bookings for the entire range in one query for efficiency
    $stmt = $conn->prepare("
        SELECT b.google_event_id, b.appointment_date, b.appointment_time, b.duration_minutes, b.appointment_type_id,
               COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before,
               COALESCE(at.buffer_after_minutes,  0) AS b_buffer_after,
               COALESCE(apc.pet_count, 0) AS pet_count,
               COALESCE(b.admin_user_id, at.admin_user_id, 0) AS schedule_admin_user_id
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        LEFT JOIN (
            SELECT booking_id, COUNT(*) AS pet_count
            FROM appointment_pets
            GROUP BY booking_id
        ) apc ON apc.booking_id = b.id
        WHERE b.appointment_date BETWEEN ? AND ? AND b.status != 'cancelled'
    ");
    $stmt->execute([$from_date, $to_date]);
    $all_bookings_rows = api_booking_filter_schedule_rows(
        assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC)),
        $ad_admin_user_id
    );
    $reserved_schedule_rows = api_booking_reserved_schedule_rows(
        $conn,
        $from_date,
        $to_date,
        $appointment_type_id,
        $ad_admin_user_id
    );

    // Group bookings by date
    $bookings_by_date = [];
    foreach ($all_bookings_rows as $row) {
        $booking_row = api_booking_db_row($row);
        $bookings_by_date[array_string_value($booking_row, 'appointment_date')][] = $booking_row;
    }
    $reserved_rows_by_date = [];
    foreach ($reserved_schedule_rows as $row) {
        $reserved_row = api_booking_db_row($row);
        $reserved_rows_by_date[array_string_value($reserved_row, 'appointment_date')][] = $reserved_row;
    }

    // Build specific_dates config map (for specific_date type custom timeslots)
    $specific_dates_config = [];
    if ($ad_schedule_type === 'specific_date') {
        foreach (api_booking_assoc_rows(array_string_value($appt_type, 'specific_dates')) as $sd_entry) {
            $date_key = array_string_value($sd_entry, 'date');
            if ($date_key !== '') {
                $specific_dates_config[$date_key] = api_booking_assoc_rows($sd_entry['timeslots'] ?? []);
            }
        }
    }

    // Pre-fetch Google Calendar busy periods for the entire range in ONE API call.
    // Mirrors the per-slot GCal check already done in the single-date slot endpoint,
    // so that dates blocked only by GCal events are correctly marked unavailable here too.
    $gcal_busy_periods = [];
    if ($respect_google_calendar && GoogleCalendarIntegration::isOAuthConfigured()) {
        try {
            $calendar_admin_user_id = $ad_admin_user_id > 0
                ? $ad_admin_user_id
                : GoogleCalendarIntegration::getAnyConnectedOAuthAdminUserId();
            if ($calendar_admin_user_id > 0) {
                $excluded_events = [];
                if ($ad_is_group || !empty($ad_resource['enabled'])) {
                    foreach ($all_bookings_rows as $booking) {
                        if (array_int_value($booking, 'appointment_type_id') === $appointment_type_id) {
                            $event_id = array_string_value($booking, 'google_event_id');
                            if ($event_id !== '') { $excluded_events[] = $event_id; }
                        }
                    }
                }
                $gcal_busy_periods = GoogleCalendarIntegration::getFreeBusyRange(
                    $from_date, $to_date, $calendar_admin_user_id, $excluded_events
                );
            }
        } catch (Exception $e) {
            error_log('api_bookings available_dates: GCal free/busy range check failed: ' . $e->getMessage());
        }
    }

    // Evaluate each candidate date: does it have at least one available slot?
    $available_dates = [];
    foreach ($candidate_dates as $check_date) {
        $existing_bookings = $bookings_by_date[$check_date] ?? [];
        $normalized_existing_bookings = api_booking_assoc_rows($existing_bookings);
        $reserved_rows_for_date = api_booking_assoc_rows($reserved_rows_by_date[$check_date] ?? []);

        // Pre-compute booking counts per slot for group classes to avoid O(n²) in the slot loop
        $group_slot_counts = [];
        if ($ad_is_group) {
            foreach ($normalized_existing_bookings as $bk) {
                $bt = substr(array_string_value($bk, 'appointment_time'), 0, 5);
                if (array_int_value($bk, 'appointment_type_id') === $appointment_type_id) {
                    $group_slot_counts[$bt] = ($group_slot_counts[$bt] ?? 0) + 1;
                }
            }
        }

        $candidate_slots = bdta_booking_candidate_slots($appt_type, $check_date);

        // Check if any candidate slot is free
        $has_available = false;
        foreach ($candidate_slots as $slot_str) {
            $slot_usage   = bdta_booking_slot_usage_summary(
                $normalized_existing_bookings,
                $slot_str,
                $ad_duration,
                $ad_buf_before,
                $ad_buf_after,
                $ad_resource,
                $appointment_type_id
            );
            $schedule_reserved = api_booking_slot_conflicts_with_rows(
                $reserved_rows_for_date,
                $slot_str,
                $ad_duration,
                $ad_buf_before,
                $ad_buf_after
            );
            $resource_available = empty($ad_resource['enabled'])
                || bdta_booking_resource_capacity_available($ad_resource, $slot_usage['overlapping_resource_units'], 1);

            if ($ad_is_group) {
                if ($schedule_reserved) {
                    continue;
                }
                $count = $slot_usage['exact_type_slot_count'] ?: ($group_slot_counts[$slot_str] ?? 0);
                if ($count < $ad_max_part) {
                    if (!$resource_available) {
                        continue;
                    }
                    // Also check Google Calendar
                    if (!empty($gcal_busy_periods) && !ad_slot_passes_gcal($check_date, $slot_str, $ad_duration, $ad_buf_before, $ad_buf_after, $gcal_busy_periods)) {
                        continue; // GCal blocks this slot
                    }
                    $has_available = true;
                    break;
                }
            } else {
                $overlap_conflict = $slot_usage['has_overlap_conflict'];
                if (!empty($ad_resource['enabled'])) {
                    $other_types = array_values(array_filter($normalized_existing_bookings, static fn(array $row): bool => array_int_value($row, 'appointment_type_id') !== $appointment_type_id));
                    $overlap_conflict = api_booking_slot_conflicts_with_rows($other_types, $slot_str, $ad_duration, $ad_buf_before, $ad_buf_after);
                }
                $slot_free = !$overlap_conflict && !$schedule_reserved && $resource_available;
                // Also check Google Calendar
                if ($slot_free && !empty($gcal_busy_periods)) {
                    $slot_free = ad_slot_passes_gcal($check_date, $slot_str, $ad_duration, $ad_buf_before, $ad_buf_after, $gcal_busy_periods);
                }
                if ($slot_free) {
                    $has_available = true;
                    break;
                }
            }
        }

        if ($has_available) {
            $available_dates[] = $check_date;
        }
    }

    echo json_encode([
        'available_dates' => $available_dates,
        'schedule_type'   => $ad_schedule_type,
    ]);
    exit;

} elseif ($method === 'GET') {
    $date = scalar_string($_GET['date'] ?? '');
    $appointment_type_id = safe_int($_GET['appointment_type_id'] ?? 0);
    $respect_google_calendar = api_booking_should_respect_google_calendar($_GET);
    if ($date === '') {
        echo json_encode(['error' => 'Date parameter required']);
        exit;
    }
    $conn = (new Database())->getConnection();
    echo json_encode(bdta_booking_available_slots($conn, $appointment_type_id, $date, 0, 1, $respect_google_calendar));
} elseif ($method === 'POST') {
    // Create booking
    $data = decode_json_assoc(scalar_string(file_get_contents('php://input')));

    $turnstile_result = bdta_verify_turnstile_submission($data, scalar_string($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!$turnstile_result['success']) {
        echo json_encode(['error' => $turnstile_result['error'] ?? 'Please confirm you are not a robot and try again.']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // If a custom booking intake form was used, extract profile-mapped field values
    // and validate required fields before the standard required_fields check.
    if (!empty($data['booking_form_id']) && isset($data['booking_intake_fields']) && is_array($data['booking_intake_fields'])) {
        $bfid = safe_int($data['booking_form_id']);
        /** @var array<int|string, mixed> $booking_intake_fields */
        $booking_intake_fields = $data['booking_intake_fields'];
        $stmt_bf = $conn->prepare("SELECT fields FROM form_templates WHERE id = ? AND form_type = 'booking_form' AND is_active = 1");
        $stmt_bf->execute([$bfid]);
        $bf_row = api_booking_db_row($stmt_bf->fetch(PDO::FETCH_ASSOC));
        if ($bf_row !== []) {
            $bf_fields = api_booking_assoc_rows(array_string_value($bf_row, 'fields'));
            foreach ($bf_fields as $fi => $field) {
                $val = trim(scalar_string($booking_intake_fields[$fi] ?? ''));
                $field_label = array_string_value($field, 'label');
                if (!empty($field['required']) && $val === '') {
                    echo json_encode(['error' => 'Required field is missing: ' . $field_label]);
                    exit;
                }
                $mapping = array_string_value($field, 'profile_mapping');
                if ($mapping === 'client.name'    && $val !== '') $data['client_name']    = $val;
                if ($mapping === 'client.email'   && $val !== '') $data['client_email']   = $val;
                if ($mapping === 'client.phone'   && $val !== '') $data['client_phone']   = $val;
                if ($mapping === 'client.address' && $val !== '') $data['client_address'] = $val;
                if ($mapping === 'pet_1.name'     && $val !== '') $data['dog_names']      = $val;
                if ($mapping === 'booking.notes'  && $val !== '') $data['notes']          = $val;
            }
        }
    }

    echo json_encode(api_booking_create_booking($conn, $data));
}
?>
