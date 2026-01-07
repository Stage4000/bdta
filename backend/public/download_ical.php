<?php
/**
 * Download iCalendar file for a booking
 */
require_once '../includes/config.php';
require_once '../includes/icalendar.php';

$booking_id = $_GET['booking_id'] ?? null;

if (!$booking_id) {
    http_response_code(400);
    die('Booking ID required');
}

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
$stmt->execute([$booking_id]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    die('Booking not found');
}

// Generate iCalendar content
$ics_content = ICalendarGenerator::generate($booking);

// Set headers for file download
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="booking-' . $booking_id . '.ics"');
header('Content-Length: ' . strlen($ics_content));
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

echo $ics_content;
?>
