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
        $stmt = $conn->prepare("
            INSERT INTO bookings (client_name, client_email, client_phone, service_type, appointment_date, appointment_time, notes, duration_minutes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
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
        
        // Get the complete booking info
        $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Generate calendar links
        require_once '../includes/icalendar.php';
        require_once '../includes/settings.php';
        $base_url = Settings::get('base_url', 'http://localhost:8000');
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
