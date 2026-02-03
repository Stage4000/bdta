<?php
require_once '../includes/config.php';
require_once '../includes/email_service.php';
require_once '../includes/google_calendar.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Check availability
    $date = $_GET['date'] ?? '';
    
    if (!$date) {
        echo json_encode(['error' => 'Date parameter required']);
        exit;
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT appointment_time, duration_minutes 
        FROM bookings 
        WHERE appointment_date = ? AND status != 'cancelled'
    ");
    $stmt->execute([$date]);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate available slots (9 AM to 5 PM)
    $available_slots = [];
    for ($hour = 9; $hour < 17; $hour++) {
        foreach ([0, 30] as $minute) {
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
        
        // Create booking with client_id
        $stmt = $conn->prepare("
            INSERT INTO bookings (client_id, client_name, client_email, client_phone, service_type, appointment_date, appointment_time, notes, duration_minutes, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->execute([
            $client_id,
            $data['client_name'],
            $data['client_email'],
            $data['client_phone'] ?? '',
            $data['service_type'],
            $data['appointment_date'],
            $data['appointment_time'],
            $data['notes'] ?? '',
            $data['duration_minutes'] ?? 60
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
        $email_service = new EmailService();
        $email_result = $email_service->sendBookingConfirmation($booking);
        
        // Try to add to Google Calendar (if configured)
        $google_calendar = new GoogleCalendarIntegration();
        $google_result = ['success' => false, 'message' => 'Google Calendar integration not configured'];
        if ($google_calendar->isConfigured()) {
            $google_result = $google_calendar->addEvent($booking);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Booking created successfully!',
            'booking_id' => $booking_id,
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
