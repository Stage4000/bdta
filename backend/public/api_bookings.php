<?php
require_once '../includes/config.php';

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
            INSERT INTO bookings (client_name, client_email, client_phone, service_type, appointment_date, appointment_time, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['client_name'],
            $data['client_email'],
            $data['client_phone'] ?? '',
            $data['service_type'],
            $data['appointment_date'],
            $data['appointment_time'],
            $data['notes'] ?? ''
        ]);
        
        $booking_id = $conn->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Booking created successfully!',
            'booking_id' => $booking_id
        ]);
    } catch (PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>
