<?php
require_once '../includes/config.php';
require_once '../includes/email_service.php';
require_once '../includes/google_calendar.php';
require_once '../includes/workflow_helper.php';

header('Content-Type: application/json');

$method = scalar_string($_SERVER['REQUEST_METHOD'] ?? '');

/**
 * @return array<string, mixed>
 */
function api_booking_db_row(mixed $row): array {
    return assoc_row($row);
}

/**
 * @return list<array<string, mixed>>
 */
function api_booking_assoc_rows(mixed $value): array {
    if (is_string($value)) {
        return decode_json_assoc_list($value);
    }
    if (!is_array($value)) {
        return [];
    }
    return assoc_rows($value);
}

/**
 * @return array<string, array<string, mixed>>
 */
function api_booking_assoc_map(mixed $value): array {
    $decoded = is_string($value) ? decode_json_assoc($value) : assoc_row($value);
    $rows = [];
    foreach ($decoded as $key => $item) {
        if (is_array($item)) {
            $rows[(string)$key] = assoc_row($item);
        }
    }
    return $rows;
}

/**
 * @return list<int>
 */
function api_booking_int_list(mixed $value): array {
    if (!is_array($value)) {
        return [];
    }

    $ints = [];
    foreach ($value as $item) {
        $ints[] = safe_int($item);
    }
    return $ints;
}

/**
 * @return list<string>
 */
function api_booking_string_list(mixed $value): array {
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
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function api_booking_create_booking(SafePDO $conn, array $data): array {
    if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
        $mapped_form_values = api_booking_extract_profile_mapped_form_values($conn, $data['form_responses']);
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

    try {
        if (!filter_var($client_email, FILTER_VALIDATE_EMAIL)) {
            return ['error' => 'Invalid email format for client_email'];
        }

        $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
        $stmt->execute([$client_email]);
        $existing_client = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
        $client_id = $existing_client !== [] ? array_int_value($existing_client, 'id') : 0;

        $location = null;
        $location_type = trim(array_string_value($data, 'location_type'));
        $location_value = trim(array_string_value($data, 'location_value'));
        $allowed_location_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall', 'fixed'];

        if ($appointment_type_id_value > 0) {
            $stmt = $conn->prepare("SELECT is_mini_session, mini_session_location, is_field_rental, field_rental_location, is_group_class, group_class_location, location_types, contract_template_id, requires_admin_confirmation FROM appointment_types WHERE id = ?");
            $stmt->execute([$appointment_type_id_value]);
            $apt_type = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
            $requires_admin_confirmation = $apt_type !== [] && array_int_value($apt_type, 'requires_admin_confirmation') === 1;
            if ($apt_type !== [] && !empty($apt_type['is_mini_session'])) {
                $location_type = 'fixed';
                $location = array_string_value($apt_type, 'mini_session_location');
            } elseif ($apt_type !== [] && !empty($apt_type['is_field_rental'])) {
                $location_type = 'fixed';
                $location = array_string_value($apt_type, 'field_rental_location');
            } elseif ($apt_type !== [] && !empty($apt_type['is_group_class'])) {
                $location_type = 'fixed';
                $location = array_string_value($apt_type, 'group_class_location');
            } elseif ($apt_type !== [] && !empty($apt_type['location_types'])) {
                $configured = api_booking_string_list(decode_json_assoc(array_string_value($apt_type, 'location_types')));
                if (!empty($configured)) {
                    $allowed_location_types = array_merge($configured, ['fixed']);
                }
            }

            if (!empty($apt_type['contract_template_id'])) {
                $contract_typed_name = trim(array_string_value($data, 'contract_typed_name'));
                if (empty($contract_typed_name)) {
                    return ['error' => 'You must sign the required contract (type your full name) to complete your booking.'];
                }
            }
        }

        $is_pending_request = $requires_admin_confirmation;
        $initial_status = $is_pending_request ? 'pending' : 'confirmed';

        if ($location_type !== 'fixed') {
            if (empty($location_type) || !in_array($location_type, $allowed_location_types)) {
                return ['error' => 'A valid location type is required. Please select how the appointment will be conducted.'];
            }
            if (in_array($location_type, ['custom_address', 'webcall'], true) && empty($location_value)) {
                return ['error' => $location_type === 'webcall' ? 'Webcall URL is required.' : 'Custom address is required.'];
            }
            if ($location_type === 'client_address') {
                $form_provided_address = trim(array_string_value($data, 'client_address'));
                // Interpret overwrite_profile flag from the request; defaults to false when not provided.
                $overwrite_profile = filter_var($data['overwrite_profile'] ?? false, FILTER_VALIDATE_BOOLEAN);

                // Resolve stored client address, if this is an existing client.
                $resolved_address = '';
                if ($client_id > 0) {
                    $stmt = $conn->prepare("SELECT address FROM clients WHERE id = ?");
                    $stmt->execute([$client_id]);
                    $client_row = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
                    $resolved_address = trim(array_string_value($client_row, 'address'));
                }

                if ($client_id === 0) {
                    // New client: require an address in the form and use it for this booking.
                    if (!empty($form_provided_address)) {
                        $location = $form_provided_address;
                    } else {
                        return ['error' => 'An address is required for this booking. Please provide your address in the booking form.'];
                    }
                } else {
                    // Existing client.
                    if ($resolved_address === '') {
                        if ($form_provided_address === '') {
                            // No stored address and none provided in the form.
                            return ['error' => 'Your account does not have an address on file. Please update your profile or choose a different location type.'];
                        }

                        // Existing client without a stored address: use the form-provided one.
                        $location = $form_provided_address;
                    } elseif ($overwrite_profile && $form_provided_address !== '') {
                        // Client agreed to overwrite profile: use the new form address.
                        $location = $form_provided_address;
                    } else {
                        // Existing client with a stored address: keep using it when no replacement address was provided
                        // or the client declined overwriting their saved profile address.
                        $location = $resolved_address;
                    }
                }
            } else {
                $location = $location_value;
            }
        }

        $use_credit = ($data['use_credit'] ?? false) === true;
        $pkg_credit_id_to_use = null;
        if ($use_credit && $appointment_type_id_value > 0 && $client_id > 0) {
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

        $dog_names = array_string_value($data, 'dog_names');
        $pet_ids = [];
        if (!empty($dog_names)) {
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

        $stmt = $conn->prepare("
            INSERT INTO bookings (client_id, appointment_type_id, client_name, client_email, client_phone, service_type, appointment_date, appointment_time, notes, duration_minutes, location, location_type, package_credit_id, contract_accepted, contract_accepted_at, contract_signature_name, contract_signature_font, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $client_id,
            $appointment_type_id_value > 0 ? $appointment_type_id_value : null,
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
            $ins = $conn->prepare("INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at) VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)");
            foreach ($form_responses as $template_id => $responses) {
                if (is_array($responses) && !empty($responses)) {
                    $ins->execute([$client_id, (int)$template_id, $booking_id, json_encode($responses)]);
                    $form_submission_id = scalar_string($conn->lastInsertId());
                    try {
                        $workflow_helper->checkFormTriggers($form_submission_id);
                    } catch (\Throwable $e) {
                        error_log("Workflow trigger error for form submission #{$form_submission_id}: " . $e->getMessage());
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
            'source'          => 'source',
            'spayed_neutered' => 'spayed_neutered',
            'vaccines_current'=> 'vaccines_current',
            'vaccine_notes'   => 'vaccine_notes',
            'behavior_notes'  => 'behavior_notes',
            'medical_notes'   => 'medical_notes',
            'training_notes'  => 'training_notes',
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
                            $dt = date_create_from_format('Y-m-d', $value)
                               ?: date_create_from_format('m/d/Y', $value)
                               ?: date_create_from_format('d/m/Y', $value);
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

        $workflow_helper->checkAppointmentTriggers(scalar_string($booking_id));

        if ($pkg_credit_id_to_use && !$is_pending_request) {
            $conn->prepare("
                UPDATE client_package_credits
                SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$pkg_credit_id_to_use]);

            $apt_type_id_for_log = $appointment_type_id_value > 0 ? $appointment_type_id_value : null;
            if ($apt_type_id_for_log) {
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
        }

        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
        if ($booking === []) {
            throw new RuntimeException('Booking record not found after insert');
        }

        $conn->commit();

        $google_calendar_link = '';
        $ical_download_link = '';
        if (!$is_pending_request) {
            require_once __DIR__ . '/../includes/icalendar.php';
            $base_url = getDynamicBaseUrl();
            $ical_download_link = $base_url . '/backend/public/download_ical.php?booking_id=' . $booking_id;
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
        } catch (Throwable $e) {
            error_log('api_booking_create_booking: booking email failed for booking #' . $booking_id . ': ' . $e->getMessage());
        }

        $google_result = ['success' => false, 'message' => 'Google Calendar integration not configured'];
        if (!$is_pending_request) {
            try {
                if (GoogleCalendarIntegration::isOAuthConfigured()) {
                    $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
                    while (($admin_row = api_booking_db_row($stmt_admins->fetch(PDO::FETCH_ASSOC))) !== []) {
                        $google_result = GoogleCalendarIntegration::addEventOAuth($booking, array_int_value($admin_row, 'admin_user_id'));
                        if ($google_result['success']) {
                            break;
                        }
                    }
                }

                if (!$google_result['success']) {
                    $google_calendar = new GoogleCalendarIntegration();
                    if ($google_calendar->isConfigured()) {
                        $google_result = $google_calendar->addEvent($booking);
                    }
                }

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
                $message .= ' Your eligible credit will be applied when the appointment is confirmed.';
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
    }
}

/**
 * @param array<int|string, mixed> $form_responses
 * @return array<string, string>
 */
function api_booking_extract_profile_mapped_form_values(SafePDO $conn, array $form_responses): array {
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

if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'credits') {
    // Check available credits for a client email + appointment type
    $email = scalar_string($_GET['email'] ?? '');
    $appointment_type_id = isset($_GET['appointment_type_id']) ? safe_int($_GET['appointment_type_id']) : 0;

    if (!$email || !$appointment_type_id) {
        echo json_encode(['credits' => []]);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Look up client by email
    $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
    $stmt->execute([$email]);
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
    // Look up current client+pet profiles by email and dog names for pre-submit conflict detection.
    // Only returns data that the user themselves would have on file; no auth required because
    // the caller must supply the correct email to get any data back.
    $email      = trim(scalar_string($_GET['email'] ?? ''));
    $dog_names_raw = trim(scalar_string($_GET['dog_names'] ?? ''));

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['client' => null, 'pets' => []]);
        exit;
    }

    $db   = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT id, name, email, phone, address FROM clients WHERE email = ?");
    $stmt->execute([$email]);
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
               advance_booking_min_days, advance_booking_max_days
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
        SELECT b.appointment_date, b.appointment_time, b.duration_minutes, b.appointment_type_id,
               COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before,
               COALESCE(at.buffer_after_minutes,  0) AS b_buffer_after
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        WHERE b.appointment_date BETWEEN ? AND ? AND b.status != 'cancelled'
    ");
    $stmt->execute([$from_date, $to_date]);
    $all_bookings_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group bookings by date
    $bookings_by_date = [];
    foreach ($all_bookings_rows as $row) {
        $booking_row = api_booking_db_row($row);
        $bookings_by_date[array_string_value($booking_row, 'appointment_date')][] = $booking_row;
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
    if (GoogleCalendarIntegration::isOAuthConfigured()) {
        try {
            $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id LIMIT 1");
            $admin_row = api_booking_db_row($stmt_admins->fetch(PDO::FETCH_ASSOC));
            if ($admin_row !== []) {
                $gcal_busy_periods = GoogleCalendarIntegration::getFreeBusyRange(
                    $from_date, $to_date, array_int_value($admin_row, 'admin_user_id')
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

        // Pre-compute booking counts per slot for group classes to avoid O(n²) in the slot loop
        $group_slot_counts = [];
        if ($ad_is_group) {
            foreach ($existing_bookings as $bk) {
                $bt = substr(array_string_value($bk, 'appointment_time'), 0, 5);
                if (array_int_value($bk, 'appointment_type_id') === $appointment_type_id) {
                    $group_slot_counts[$bt] = ($group_slot_counts[$bt] ?? 0) + 1;
                }
            }
        }

        // Determine timeslot config for this date
        $custom_slots = null;
        if ($ad_schedule_type === 'specific_date') {
            $custom_slots = $specific_dates_config[$check_date] ?? null;
        }

        // Determine start/end times (with per-day override for recurring)
        $day_start = $ad_start_time;
        $day_end   = $ad_end_time;
        if ($ad_schedule_type !== 'specific_date' && $ad_per_day !== []) {
            $dow = (int)(new DateTime($check_date))->format('w');
            $day_key = (string)$dow;
            foreach ($ad_per_day as $config_key => $day_config) {
                if ($config_key !== $day_key) {
                    continue;
                }
                $ds = array_string_value($day_config, 'start');
                $de = array_string_value($day_config, 'end');
                if (!empty($ds) && !empty($de) && $ds < $de) {
                    $day_start = $ds;
                    $day_end   = $de;
                }
                break;
            }
        }

        // Build candidate slot minutes for this date
        $cand_mins = [];
        if (!empty($custom_slots)) {
            foreach ($custom_slots as $cfg) {
                $slot_type = array_string_value($cfg, 'type', 'point');
                $slot_time = array_string_value($cfg, 'time');
                $slot_start = array_string_value($cfg, 'start');
                $slot_end = array_string_value($cfg, 'end');
                if ($slot_type === 'point' && $slot_time !== '') {
                    $p = explode(':', $slot_time);
                    if (count($p) === 2) {
                        $cand_mins[] = (int)$p[0] * 60 + (int)$p[1];
                    }
                } elseif ($slot_type === 'range' && $slot_start !== '' && $slot_end !== '') {
                    $sp = explode(':', $slot_start);
                    $ep = explode(':', $slot_end);
                    if (count($sp) === 2 && count($ep) === 2) {
                        $rs = (int)$sp[0] * 60 + (int)$sp[1];
                        $re = (int)$ep[0] * 60 + (int)$ep[1];
                        for ($m = $rs; $m < $re; $m += $ad_interval) {
                            $cand_mins[] = $m;
                        }
                    }
                }
            }
            $cand_mins = array_values(array_unique($cand_mins));
            sort($cand_mins);
        } else {
            $sp = explode(':', $day_start);
            $ep = explode(':', $day_end);
            $sm = (int)$sp[0] * 60 + (int)$sp[1];
            $em = (int)$ep[0] * 60 + (int)$ep[1];
            for ($m = $sm; $m < $em; $m += $ad_interval) {
                $cand_mins[] = $m;
            }
        }

        // Check if any candidate slot is free
        $has_available = false;
        foreach ($cand_mins as $tm) {
            $hour         = intdiv($tm, 60);
            $min          = $tm % 60;
            $slot_str     = sprintf('%02d:%02d', $hour, $min);
            $slot_end     = $tm + $ad_duration;
            $slot_buf_s   = $tm       - $ad_buf_before;
            $slot_buf_e   = $slot_end + $ad_buf_after;

            if ($ad_is_group) {
                $count = $group_slot_counts[$slot_str] ?? 0;
                if ($count < $ad_max_part) {
                    // Also check Google Calendar
                    if (!empty($gcal_busy_periods) && !ad_slot_passes_gcal($check_date, $slot_str, $ad_duration, $ad_buf_before, $ad_buf_after, $gcal_busy_periods)) {
                        continue; // GCal blocks this slot
                    }
                    $has_available = true;
                    break;
                }
            } else {
                $slot_free    = true;
                $seen_windows = [];
                foreach ($existing_bookings as $bk) {
                    $bt = substr(array_string_value($bk, 'appointment_time'), 0, 5);
                    $bp = explode(':', $bt);
                    if (count($bp) !== 2) continue;
                    $b_s   = (int)$bp[0] * 60 + (int)$bp[1];
                    $b_dur = max(1, array_int_value($bk, 'duration_minutes', 60));
                    $b_bb  = max(0, array_int_value($bk, 'b_buffer_before'));
                    $b_ba  = max(0, array_int_value($bk, 'b_buffer_after'));
                    $b_bs  = $b_s - $b_bb;
                    $b_be  = $b_s + $b_dur + $b_ba;
                    $wkey  = $b_bs . '-' . $b_be;
                    if (isset($seen_windows[$wkey])) continue;
                    $seen_windows[$wkey] = true;
                    if ($slot_buf_s < $b_be && $b_bs < $slot_buf_e) {
                        $slot_free = false;
                        break;
                    }
                }
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
    // Check availability
    $date = scalar_string($_GET['date'] ?? '');
    $appointment_type_id = isset($_GET['appointment_type_id']) ? safe_int($_GET['appointment_type_id']) : null;
    
    if (!$date) {
        echo json_encode(['error' => 'Date parameter required']);
        exit;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get appointment type configuration if provided
    $available_days = [0,1,2,3,4,5,6]; // Default: all days
    $available_start_time = '09:00';
    $available_end_time = '17:00';
    $time_slot_interval = 30;
    $slot_duration = 60; // appointment duration in minutes (used for overlap detection)
    $is_group_class = false;
    $max_participants = 1;
    $buffer_before = 0; // minutes of buffer required before this appointment type
    $buffer_after  = 0; // minutes of buffer required after this appointment type
    $appointment_type = [];
    
    $custom_slot_configs = [];
    if ($appointment_type_id) {
        $stmt = $conn->prepare("
            SELECT available_days, available_start_time, available_end_time, time_slot_interval,
                   schedule_type, specific_date, specific_dates, per_day_schedule,
                   duration_minutes, is_group_class, max_participants,
                   buffer_before_minutes, buffer_after_minutes
            FROM appointment_types 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$appointment_type_id]);
        $appointment_type = api_booking_db_row($stmt->fetch(PDO::FETCH_ASSOC));
        
        if ($appointment_type !== []) {
            $schedule_type = array_string_value($appointment_type, 'schedule_type', 'recurring');
            
            // Handle specific date scheduling (single or multi-date)
            if ($schedule_type === 'specific_date') {
                    $custom_slot_configs = []; // [] = use global times; non-empty array = per-timeslot config

                // Try new multi-date format first
                $specific_dates_arr = api_booking_assoc_rows(array_string_value($appointment_type, 'specific_dates'));
                if (!empty($specific_dates_arr)) {
                    // Find the entry matching the requested date
                    $matched_entry = null;
                    foreach ($specific_dates_arr as $entry) {
                        if (array_string_value($entry, 'date') === $date) {
                            $matched_entry = $entry;
                            break;
                        }
                    }
                    if ($matched_entry === null) {
                        // Date not in the list
                        $all_date_labels = array_map(
                            fn(array $e): string => date('F j, Y', safe_timestamp(strtotime(array_string_value($e, 'date')))),
                            $specific_dates_arr
                        );
                        echo json_encode([
                            'date' => $date,
                            'available_slots' => [],
                            'message' => 'This appointment is only available on: ' . implode(', ', $all_date_labels),
                        ]);
                        exit;
                    }
                    // If the matched entry has custom timeslots, record them
                    $custom_slot_configs = api_booking_assoc_rows($matched_entry['timeslots'] ?? []);
                } else {
                    // Legacy single-date fallback
                    $specific_date_legacy = array_string_value($appointment_type, 'specific_date');
                    if ($specific_date_legacy !== $date) {
                        echo json_encode([
                            'date' => $date,
                            'available_slots' => [],
                            'message' => 'This appointment is only available on: ' . date('F j, Y', safe_timestamp(strtotime($specific_date_legacy))),
                        ]);
                        exit;
                    }
                }
            }
            
            $available_days = api_booking_int_list(decode_json_assoc(array_string_value($appointment_type, 'available_days')));
            if ($available_days === []) {
                $available_days = [0,1,2,3,4,5,6];
            }
            $available_start_time = array_string_value($appointment_type, 'available_start_time', '09:00');
            $available_end_time = array_string_value($appointment_type, 'available_end_time', '17:00');
            $time_slot_interval = array_int_value($appointment_type, 'time_slot_interval', 30);
            $slot_duration      = array_int_value($appointment_type, 'duration_minutes', 60);
            $is_group_class     = !empty($appointment_type['is_group_class']);
            $max_participants   = max(1, array_int_value($appointment_type, 'max_participants', 1));
            $buffer_before      = max(0, array_int_value($appointment_type, 'buffer_before_minutes'));
            $buffer_after       = max(0, array_int_value($appointment_type, 'buffer_after_minutes'));
        }
    }
    
    // Check if the requested date's day of week is available (only for recurring schedules)
    if (!isset($schedule_type) || $schedule_type === 'recurring') {
            $day_of_week = (int)date('w', safe_timestamp(strtotime($date))); // 0 = Sunday, 6 = Saturday
        if (!in_array($day_of_week, $available_days)) {
            $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $available_day_names = array_map(function(int $day) use ($day_names): string {
                return $day_names[$day];
            }, $available_days);
            
            echo json_encode([
                'date' => $date,
                'available_slots' => [],
                'message' => 'This appointment type is only available on: ' . implode(', ', $available_day_names)
            ]);
            exit;
        }

        // Apply per-day time overrides if configured
        if (array_string_value($appointment_type, 'per_day_schedule') !== '') {
            $per_day = api_booking_assoc_map(array_string_value($appointment_type, 'per_day_schedule'));
            $day_key = (string)$day_of_week;
            foreach ($per_day as $config_key => $day_config) {
                if ($config_key !== $day_key) {
                    continue;
                }
                $day_start = array_string_value($day_config, 'start');
                $day_end   = array_string_value($day_config, 'end');
                if (!empty($day_start) && !empty($day_end) && $day_start < $day_end) {
                    $available_start_time = $day_start;
                    $available_end_time   = $day_end;
                }
                break;
            }
        }
    }
    
    $stmt = $conn->prepare("
        SELECT b.appointment_time, b.duration_minutes, b.appointment_type_id,
               COALESCE(at.buffer_before_minutes, 0) AS b_buffer_before,
               COALESCE(at.buffer_after_minutes,  0) AS b_buffer_after
        FROM bookings b
        LEFT JOIN appointment_types at ON at.id = b.appointment_type_id
        WHERE b.appointment_date = ? AND b.status != 'cancelled'
    ");
    $stmt->execute([$date]);
    $existing_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Query Google Calendar for busy periods on this date (best-effort; errors are non-fatal)
    $google_busy_periods = [];
    $google_calendar_checked = false;
    if (GoogleCalendarIntegration::isOAuthConfigured()) {
        try {
            // Use the first connected admin's calendar – consistent with how the POST
            // handler adds events (it iterates all admins and stops on first success).
            $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id LIMIT 1");
            $admin_row = api_booking_db_row($stmt_admins->fetch(PDO::FETCH_ASSOC));
            if ($admin_row !== []) {
                $google_busy_periods = GoogleCalendarIntegration::getFreeBusy($date, array_int_value($admin_row, 'admin_user_id'));
                $google_calendar_checked = true;
            }
        } catch (Exception $e) {
            error_log('api_bookings: Google Calendar free/busy check failed: ' . $e->getMessage());
        }
    }
    
    // Generate available slots based on appointment type configuration
    $available_slots = [];
    
    // Build the list of candidate time-slots (in minutes from midnight).
    // When the appointment type defines custom timeslots for this specific date,
    // expand them to individual minute-offsets; otherwise fall back to the
    // global start→end sweep at the configured interval.
    $candidate_minutes = []; // each value: minutes from midnight

    if (!empty($custom_slot_configs)) {
        // Custom timeslots defined for this specific date
        foreach ($custom_slot_configs as $cfg) {
            $slot_type = array_string_value($cfg, 'type', 'point');
            $slot_time = array_string_value($cfg, 'time');
            $slot_start = array_string_value($cfg, 'start');
            $slot_end = array_string_value($cfg, 'end');
            if ($slot_type === 'point' && $slot_time !== '') {
                $parts = explode(':', $slot_time);
                if (count($parts) === 2) {
                    $candidate_minutes[] = (int)$parts[0] * 60 + (int)$parts[1];
                }
            } elseif ($slot_type === 'range' && $slot_start !== '' && $slot_end !== '') {
                $s_parts = explode(':', $slot_start);
                $e_parts = explode(':', $slot_end);
                if (count($s_parts) === 2 && count($e_parts) === 2) {
                    $range_start = (int)$s_parts[0] * 60 + (int)$s_parts[1];
                    $range_end   = (int)$e_parts[0] * 60 + (int)$e_parts[1];
                    for ($m = $range_start; $m < $range_end; $m += $time_slot_interval) {
                        $candidate_minutes[] = $m;
                    }
                }
            }
        }
        // Deduplicate and sort
        $candidate_minutes = array_values(array_unique($candidate_minutes));
        sort($candidate_minutes);
    } else {
        // Default: sweep from global start to global end at interval
        $start_parts = explode(':', $available_start_time);
        $end_parts   = explode(':', $available_end_time);
        if (count($start_parts) !== 2 || count($end_parts) !== 2) {
            $start_time_minutes = 9 * 60;
            $end_time_minutes   = 17 * 60;
        } else {
            $start_time_minutes = (int)$start_parts[0] * 60 + (int)$start_parts[1];
            $end_time_minutes   = (int)$end_parts[0]   * 60 + (int)$end_parts[1];
        }
        for ($m = $start_time_minutes; $m < $end_time_minutes; $m += $time_slot_interval) {
            $candidate_minutes[] = $m;
        }
    }

    // Evaluate each candidate slot for conflicts / availability
    foreach ($candidate_minutes as $time_minutes) {
        $hour = intdiv($time_minutes, 60);
        $minute = $time_minutes % 60;
        $time_slot = sprintf('%02d:%02d', $hour, $minute);
        $time_slot_end_minutes = $time_minutes + $slot_duration;

        // The buffered window for this proposed slot:
        //   starts $buffer_before minutes before the slot
        //   ends   $buffer_after  minutes after the slot ends
        $slot_buffered_start = $time_minutes - $buffer_before;
        $slot_buffered_end   = $time_slot_end_minutes + $buffer_after;
        
        // Check if slot is available
        $is_available = true;

        // ── Internal booking conflict detection ──────────────────────────────
        if ($is_group_class && $appointment_type_id) {
            // Group class: count existing participants for this exact slot and type.
            // Allow booking as long as capacity is not yet reached.
            $participant_count = 0;
            foreach ($existing_bookings as $booking) {
                $b_time = substr(array_string_value($booking, 'appointment_time'), 0, 5);
                if ($b_time === $time_slot && array_int_value($booking, 'appointment_type_id') === $appointment_type_id) {
                    $participant_count++;
                }
            }
            if ($participant_count >= $max_participants) {
                $is_available = false;
            }
        } else {
            // Regular appointment: block if any existing booking's buffered window overlaps
            // with the proposed slot's buffered window.
            // De-duplicate by buffered [start, end) window so that group-class bookings (multiple
            // rows at the same time) are treated as a single occupancy block.
            $seen_windows = [];
            foreach ($existing_bookings as $booking) {
                $b_time   = substr(array_string_value($booking, 'appointment_time'), 0, 5);
                $b_parts  = explode(':', $b_time);
                if (count($b_parts) !== 2) continue;
                $b_start        = (int)$b_parts[0] * 60 + (int)$b_parts[1];
                $b_dur          = max(1, array_int_value($booking, 'duration_minutes', 60));
                $b_buf_before   = max(0, array_int_value($booking, 'b_buffer_before'));
                $b_buf_after    = max(0, array_int_value($booking, 'b_buffer_after'));
                // Existing booking's buffered window
                $b_buf_start    = $b_start - $b_buf_before;
                $b_buf_end      = $b_start + $b_dur + $b_buf_after;
                $win_key        = $b_buf_start . '-' . $b_buf_end;

                if (isset($seen_windows[$win_key])) {
                    continue; // Already evaluated this time window
                }
                $seen_windows[$win_key] = true;

                // Two intervals [A,B) and [C,D) overlap iff A < D && C < B
                if ($slot_buffered_start < $b_buf_end && $b_buf_start < $slot_buffered_end) {
                    $is_available = false;
                    break;
                }
            }
        }

        // ── Google Calendar busy-period check ────────────────────────────────
        // Expand the check window by the appointment type's buffer times so that
        // a GCal event ending at 9:00 won't allow a 15-min-buffer-before slot at 9:05.
        if ($is_available && !empty($google_busy_periods)) {
            $slot_ts                = strtotime($date . 'T' . $time_slot . ':00');
            $slot_buffered_start_ts = $slot_ts - $buffer_before * 60;
            $slot_buffered_end_ts   = $slot_ts + ($slot_duration + $buffer_after) * 60;

            foreach ($google_busy_periods as $busy) {
                if (empty($busy['start']) || empty($busy['end'])) continue;
                $busy_start_ts = strtotime($busy['start']);
                $busy_end_ts   = strtotime($busy['end']);
                if ($busy_start_ts === false || $busy_end_ts === false) continue;

                // Overlap check: buffered slot window vs. GCal busy window
                if ($slot_buffered_start_ts < $busy_end_ts && $busy_start_ts < $slot_buffered_end_ts) {
                    $is_available = false;
                    break;
                }
            }
        }
        
        if ($is_available) {
            $available_slots[] = $time_slot;
        }
    }
    
    echo json_encode([
        'date' => $date,
        'available_slots' => $available_slots,
        'google_calendar_checked' => $google_calendar_checked,
    ]);
    
} elseif ($method === 'POST') {
    // Create booking
    $data = decode_json_assoc(scalar_string(file_get_contents('php://input')));

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
