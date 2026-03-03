<?php
require_once '../includes/config.php';
require_once '../includes/email_service.php';
require_once '../includes/google_calendar.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['action']) && $_GET['action'] === 'credits') {
    // Check available credits for a client email + appointment type
    $email = $_GET['email'] ?? '';
    $appointment_type_id = isset($_GET['appointment_type_id']) ? (int)$_GET['appointment_type_id'] : 0;

    if (!$email || !$appointment_type_id) {
        echo json_encode(['credits' => []]);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Look up client by email
    $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
    $stmt->execute([$email]);
    $client_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client_row) {
        echo json_encode(['credits' => []]);
        exit;
    }

    $client_id = (int)$client_row['id'];

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

} elseif ($method === 'GET') {
    // Check availability
    $date = $_GET['date'] ?? '';
    $appointment_type_id = isset($_GET['appointment_type_id']) ? (int)$_GET['appointment_type_id'] : null;
    
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
    
    if ($appointment_type_id) {
        $stmt = $conn->prepare("
            SELECT available_days, available_start_time, available_end_time, time_slot_interval,
                   schedule_type, specific_date, per_day_schedule
            FROM appointment_types 
            WHERE id = ? AND is_active = 1
        ");
        $stmt->execute([$appointment_type_id]);
        $appointment_type = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($appointment_type) {
            $schedule_type = $appointment_type['schedule_type'] ?? 'recurring';
            $specific_date = $appointment_type['specific_date'] ?? null;
            
            // Check if this is a specific date appointment and the date matches
            if ($schedule_type === 'specific_date') {
                if ($specific_date !== $date) {
                    echo json_encode([
                        'date' => $date,
                        'available_slots' => [],
                        'message' => 'This appointment is only available on: ' . date('F j, Y', strtotime($specific_date))
                    ]);
                    exit;
                }
            }
            
            $available_days = json_decode($appointment_type['available_days'], true);
            if (!is_array($available_days)) {
                $available_days = [0,1,2,3,4,5,6];
            }
            $available_start_time = $appointment_type['available_start_time'] ?? '09:00';
            $available_end_time = $appointment_type['available_end_time'] ?? '17:00';
            $time_slot_interval = (int)($appointment_type['time_slot_interval'] ?? 30);
        }
    }
    
    // Check if the requested date's day of week is available (only for recurring schedules)
    if (!isset($schedule_type) || $schedule_type === 'recurring') {
        $day_of_week = (int)date('w', strtotime($date)); // 0 = Sunday, 6 = Saturday
        if (!in_array($day_of_week, $available_days)) {
            $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $available_day_names = array_map(function($day) use ($day_names) {
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
        if (!empty($appointment_type['per_day_schedule'])) {
            $per_day = json_decode($appointment_type['per_day_schedule'], true);
            if (is_array($per_day) && isset($per_day[$day_of_week])) {
                $day_start = $per_day[$day_of_week]['start'] ?? '';
                $day_end   = $per_day[$day_of_week]['end']   ?? '';
                if (!empty($day_start) && !empty($day_end) && $day_start < $day_end) {
                    $available_start_time = $day_start;
                    $available_end_time   = $day_end;
                }
            }
        }
    }
    
    $stmt = $conn->prepare("
        SELECT appointment_time, duration_minutes 
        FROM bookings 
        WHERE appointment_date = ? AND status != 'cancelled'
    ");
    $stmt->execute([$date]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate available slots based on appointment type configuration
    $available_slots = [];
    
    // Parse start and end times with validation
    $start_parts = explode(':', $available_start_time);
    $end_parts = explode(':', $available_end_time);
    
    if (count($start_parts) !== 2 || count($end_parts) !== 2) {
        // Invalid time format, use defaults
        $start_hour = 9;
        $start_minute = 0;
        $end_hour = 17;
        $end_minute = 0;
    } else {
        $start_hour = (int)$start_parts[0];
        $start_minute = (int)$start_parts[1];
        $end_hour = (int)$end_parts[0];
        $end_minute = (int)$end_parts[1];
    }
    
    $start_time_minutes = $start_hour * 60 + $start_minute;
    $end_time_minutes = $end_hour * 60 + $end_minute;
    
    // Generate slots at specified interval
    for ($time_minutes = $start_time_minutes; $time_minutes < $end_time_minutes; $time_minutes += $time_slot_interval) {
        $hour = floor($time_minutes / 60);
        $minute = $time_minutes % 60;
        $time_slot = sprintf('%02d:%02d', $hour, $minute);
        
        // Check if slot is available
        $is_available = true;
        foreach ($bookings as $booking) {
            if ($booking['appointment_time'] === $time_slot) {
                $is_available = false;
                break;
            }
        }
        
        if ($is_available) {
            $available_slots[] = $time_slot;
        }
    }
    
    echo json_encode([
        'date' => $date,
        'available_slots' => $available_slots
    ]);
    
} elseif ($method === 'POST') {
    // Create booking
    $data = json_decode(file_get_contents('php://input'), true);
    
    $required_fields = ['client_name', 'client_email', 'service_type', 'appointment_date', 'appointment_time'];
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            echo json_encode(['error' => "Missing required field: $field"]);
            exit;
        }
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        // Validate email format
        if (!filter_var($data['client_email'], FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error' => 'Invalid email format for client_email']);
            exit;
        }
        
        // Check if client exists by email
        $stmt = $conn->prepare("SELECT id FROM clients WHERE email = ?");
        $stmt->execute([$data['client_email']]);
        $existing_client = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_client) {
            // Client exists, use their ID
            $client_id = $existing_client['id'];
        } else {
            // Create new client
            $stmt = $conn->prepare("
                INSERT INTO clients (name, email, phone, notes, created_at, updated_at) 
                VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
            ");
            $stmt->execute([
                $data['client_name'],
                $data['client_email'],
                $data['client_phone'] ?? '',
                'Created from booking form'
            ]);
            $client_id = $conn->lastInsertId();
        }
        
        // Create pet profiles from dog names if provided
        $dog_names = isset($data['dog_names']) ? $data['dog_names'] : '';
        $pet_ids = [];
        if (!empty($dog_names)) {
            // Split comma-separated dog names and remove empty strings explicitly
            $names = array_filter(
                array_map('trim', explode(',', $dog_names)),
                fn($n) => $n !== ''
            );
            
            if (!empty($names)) {
                // Fetch all existing pets for this client in one query
                $placeholders = str_repeat('?,', count($names) - 1) . '?';
                $stmt = $conn->prepare("SELECT id, name FROM pets WHERE client_id = ? AND name IN ($placeholders)");
                $stmt->execute(array_merge([$client_id], $names));
                $existing_pets = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $existing_pet_map = [];
                foreach ($existing_pets as $pet) {
                    $existing_pet_map[$pet['name']] = $pet['id'];
                }
                
                // Create new pets or use existing ones
                foreach ($names as $dog_name) {
                    if (isset($existing_pet_map[$dog_name])) {
                        // Pet already exists
                        $pet_ids[] = $existing_pet_map[$dog_name];
                    } else {
                        // Create new pet
                        $stmt = $conn->prepare("
                            INSERT INTO pets (client_id, name, species, is_active, created_at, updated_at) 
                            VALUES (?, ?, 'Dog', 1, datetime('now'), datetime('now'))
                        ");
                        $stmt->execute([$client_id, $dog_name]);
                        $pet_ids[] = $conn->lastInsertId();
                    }
                }
            }
        }
        
        // Get appointment type info to check if it's a Mini Session or Field Rental
        $location = null;
        $location_type = trim($data['location_type'] ?? '');
        $location_value = trim($data['location_value'] ?? '');
        $allowed_location_types = ['client_address', 'custom_address', 'phone_inbound', 'phone_outbound', 'webcall', 'fixed'];

        if (!empty($data['appointment_type_id'])) {
            $stmt = $conn->prepare("SELECT is_mini_session, mini_session_location, is_field_rental, field_rental_location, location_types, contract_template_id FROM appointment_types WHERE id = ?");
            $stmt->execute([$data['appointment_type_id']]);
            $apt_type = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($apt_type && !empty($apt_type['is_mini_session'])) {
                // Fixed location: override any submitted location
                $location_type = 'fixed';
                $location = $apt_type['mini_session_location'];
            } elseif ($apt_type && !empty($apt_type['is_field_rental'])) {
                $location_type = 'fixed';
                $location = $apt_type['field_rental_location'];
            } elseif ($apt_type && !empty($apt_type['location_types'])) {
                // Restrict to appointment type's configured location types
                $configured = json_decode($apt_type['location_types'], true);
                if (is_array($configured) && !empty($configured)) {
                    $allowed_location_types = array_merge($configured, ['fixed']);
                }
            }

            // Validate contract signature if this appointment type requires one
            if (!empty($apt_type['contract_template_id'])) {
                $contract_typed_name = trim($data['contract_typed_name'] ?? '');
                if (empty($contract_typed_name)) {
                    echo json_encode(['error' => 'You must sign the required contract (type your full name) to complete your booking.']);
                    exit;
                }
            }
        }

        // For non-fixed types, validate and resolve location
        if ($location_type !== 'fixed') {
            if (empty($location_type) || !in_array($location_type, $allowed_location_types)) {
                echo json_encode(['error' => 'A valid location type is required. Please select how the appointment will be conducted.']);
                exit;
            }
            if (in_array($location_type, ['custom_address', 'webcall']) && empty($location_value)) {
                echo json_encode(['error' => $location_type === 'webcall' ? 'Webcall URL is required.' : 'Custom address is required.']);
                exit;
            }
            // For client_address, resolve the actual address from the client's profile
            if ($location_type === 'client_address') {
                $stmt = $conn->prepare("SELECT address FROM clients WHERE id = ?");
                $stmt->execute([$client_id]);
                $client_row = $stmt->fetch(PDO::FETCH_ASSOC);
                $resolved_address = trim($client_row['address'] ?? '');
                if (empty($resolved_address)) {
                    echo json_encode(['error' => 'Your account does not have an address on file. Please update your profile or choose a different location type.']);
                    exit;
                }
                $location = $resolved_address;
            } else {
                $location = $location_value;
            }
        }
        
        // Resolve credit to use, if requested
        $use_credit = ($data['use_credit'] ?? false) === true;
        $pkg_credit_id_to_use = null;
        if ($use_credit && !empty($data['appointment_type_id'])) {
            // Find the best eligible credit row (soonest expiry first)
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
            $stmt->execute([$client_id, (int)$data['appointment_type_id']]);
            $credit_row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($credit_row) {
                $pkg_credit_id_to_use = (int)$credit_row['id'];
            }
        }

        // Determine contract signature data
        $contract_typed_name = trim($data['contract_typed_name'] ?? '');
        $allowed_sig_fonts = ['font-dancing', 'font-pacifico', 'font-satisfy', 'font-great-vibes', 'font-allura'];
        $contract_sig_font = in_array($data['contract_signature_font'] ?? '', $allowed_sig_fonts)
            ? $data['contract_signature_font']
            : 'font-dancing';
        $contract_accepted = !empty($contract_typed_name) ? 1 : 0;
        $contract_accepted_at = $contract_accepted ? date('Y-m-d H:i:s') : null;

        // Create booking with client_id, appointment_type_id, location, location_type, and package_credit_id
        $stmt = $conn->prepare("
            INSERT INTO bookings (client_id, appointment_type_id, client_name, client_email, client_phone, service_type, appointment_date, appointment_time, notes, duration_minutes, location, location_type, package_credit_id, contract_accepted, contract_accepted_at, contract_signature_name, contract_signature_font, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $client_id,
            !empty($data['appointment_type_id']) ? (int)$data['appointment_type_id'] : null,
            $data['client_name'],
            $data['client_email'],
            $data['client_phone'] ?? '',
            $data['service_type'],
            $data['appointment_date'],
            $data['appointment_time'],
            $data['notes'] ?? '',
            $data['duration_minutes'] ?? 60,
            $location,
            $location_type,
            $pkg_credit_id_to_use,
            $contract_accepted,
            $contract_accepted_at,
            $contract_accepted ? $contract_typed_name : null,
            $contract_accepted ? $contract_sig_font : null
        ]);
        
        $booking_id = $conn->lastInsertId();
        
        // Link pets to booking
        if (!empty($pet_ids)) {
            foreach ($pet_ids as $pet_id) {
                $stmt = $conn->prepare("
                    INSERT INTO appointment_pets (booking_id, pet_id, created_at) 
                    VALUES (?, ?, datetime('now'))
                ");
                $stmt->execute([$booking_id, $pet_id]);
            }
        }

        // Save form responses submitted during booking
        if (!empty($data['form_responses']) && is_array($data['form_responses'])) {
            $ins = $conn->prepare("INSERT INTO form_submissions (client_id, template_id, booking_id, responses, status, submitted_at) VALUES (?, ?, ?, ?, 'submitted', CURRENT_TIMESTAMP)");
            foreach ($data['form_responses'] as $template_id => $responses) {
                if (is_array($responses) && !empty($responses)) {
                    $ins->execute([$client_id, (int)$template_id, $booking_id, json_encode($responses)]);
                }
            }
        }

        // Deduct credit if one was selected
        if ($pkg_credit_id_to_use) {
            $conn->prepare("
                UPDATE client_package_credits
                SET used_credits = used_credits + 1, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$pkg_credit_id_to_use]);

            // Look up appointment_type_id for the transaction log
            $apt_type_id_for_log = !empty($data['appointment_type_id']) ? (int)$data['appointment_type_id'] : null;
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

        // Get the complete booking info
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Generate calendar links
        require_once '../includes/icalendar.php';
        $base_url = getDynamicBaseUrl();
        $google_calendar_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
        $ical_download_link = $base_url . '/backend/public/download_ical.php?booking_id=' . $booking_id;
        
        // Send confirmation email
        $email_service = new EmailService(null, $conn);
        $email_result = $email_service->sendBookingConfirmation($booking);
        
        // Try to add to Google Calendar
        // Priority: OAuth tokens (per admin user) → service account (legacy)
        $google_result = ['success' => false, 'message' => 'Google Calendar integration not configured'];

        // Attempt OAuth sync: use the first admin user that has a valid OAuth token
        if (GoogleCalendarIntegration::isOAuthConfigured()) {
            $stmt_admins = $conn->query("SELECT admin_user_id FROM google_oauth_tokens ORDER BY admin_user_id");
            while ($admin_row = $stmt_admins->fetch(PDO::FETCH_ASSOC)) {
                $google_result = GoogleCalendarIntegration::addEventOAuth($booking, (int)$admin_row['admin_user_id']);
                if ($google_result['success']) {
                    break;
                }
            }
        }

        // Fall back to service account if OAuth did not succeed
        if (!$google_result['success']) {
            $google_calendar = new GoogleCalendarIntegration();
            if ($google_calendar->isConfigured()) {
                $google_result = $google_calendar->addEvent($booking);
            }
        }

        // Persist the Google event ID so we can delete it later if cancelled
        if (!empty($google_result['event_id'])) {
            $conn->prepare("UPDATE bookings SET google_event_id = ? WHERE id = ?")
                 ->execute([$google_result['event_id'], $booking_id]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Booking created successfully!',
            'booking_id' => $booking_id,
            'credit_applied' => $pkg_credit_id_to_use !== null,
            'calendar_links' => [
                'google_calendar' => $google_calendar_link,
                'ical_download' => $ical_download_link
            ],
            'email_sent' => $email_result['success'],
            'google_calendar_synced' => $google_result['success']
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
