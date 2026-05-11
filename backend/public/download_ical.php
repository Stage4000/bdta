<?php
/**
 * Download iCalendar file for a booking
 */
require_once '../includes/config.php';
require_once '../includes/icalendar.php';
require_once '../includes/public_access_links.php';

$booking_id = safe_int($_GET['booking_id'] ?? 0);
$booking_token = trim(scalar_string($_GET['token'] ?? ''));

if ($booking_id <= 0 && $booking_token === '') {
    http_response_code(400);
    die('Booking link required');
}

$db = new Database();
$conn = $db->getConnection();

$lookup_column = $booking_token !== '' ? 'ical_token' : 'id';
$lookup_value = $booking_token !== '' ? $booking_token : (string) $booking_id;
$stmt = $conn->prepare("SELECT * FROM bookings WHERE {$lookup_column} = ?");
$stmt->execute([$lookup_value]);
$booking = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    http_response_code(404);
    die('Booking not found');
}

$booking_access = bdta_public_record_access_context($booking, 'ical_token', $booking_token);
if (!$booking_access['has_valid_token'] && !$booking_access['is_portal_owner']) {
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
