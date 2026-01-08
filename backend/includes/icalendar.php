<?php
/**
 * iCalendar (.ics) File Generator
 * Creates calendar files compatible with Google Calendar, Apple Calendar, Outlook, etc.
 */

class ICalendarGenerator {
    
    /**
     * Generate iCalendar (.ics) content for a booking
     */
    public static function generate($booking) {
        $start_datetime = new DateTime($booking['appointment_date'] . ' ' . $booking['appointment_time']);
        $end_datetime = clone $start_datetime;
        $end_datetime->modify('+' . $booking['duration_minutes'] . ' minutes');
        
        $now = new DateTime();
        
        // Format dates for iCalendar (YYYYMMDDTHHmmss)
        $start = $start_datetime->format('Ymd\THis');
        $end = $end_datetime->format('Ymd\THis');
        $stamp = $now->format('Ymd\THis');
        
        // Escape text for iCalendar format
        $summary = self::escapeString($booking['service_type'] . ' - Brook\'s Dog Training Academy');
        $description = self::escapeString(
            "Dog Training Appointment\n\n" .
            "Service: " . $booking['service_type'] . "\n" .
            "Client: " . $booking['client_name'] . "\n" .
            ($booking['notes'] ? "Notes: " . $booking['notes'] . "\n" : "") .
            "\nFor questions, contact: info@brooksdogtraining.com"
        );
        $location = self::escapeString('Highlands County, Florida');
        
        // Generate unique ID
        $uid = 'booking-' . $booking['id'] . '@brooksdogtraining.com';
        
        // Build iCalendar content
        $ics = "BEGIN:VCALENDAR\r\n";
        $ics .= "VERSION:2.0\r\n";
        $ics .= "PRODID:-//Brook's Dog Training Academy//Booking System//EN\r\n";
        $ics .= "CALSCALE:GREGORIAN\r\n";
        $ics .= "METHOD:REQUEST\r\n";
        $ics .= "BEGIN:VEVENT\r\n";
        $ics .= "UID:" . $uid . "\r\n";
        $ics .= "DTSTAMP:" . $stamp . "\r\n";
        $ics .= "DTSTART:" . $start . "\r\n";
        $ics .= "DTEND:" . $end . "\r\n";
        $ics .= "SUMMARY:" . $summary . "\r\n";
        $ics .= "DESCRIPTION:" . $description . "\r\n";
        $ics .= "LOCATION:" . $location . "\r\n";
        $ics .= "STATUS:CONFIRMED\r\n";
        $ics .= "SEQUENCE:0\r\n";
        $ics .= "BEGIN:VALARM\r\n";
        $ics .= "TRIGGER:-PT1H\r\n";
        $ics .= "ACTION:DISPLAY\r\n";
        $ics .= "DESCRIPTION:Reminder: " . $summary . "\r\n";
        $ics .= "END:VALARM\r\n";
        $ics .= "END:VEVENT\r\n";
        $ics .= "END:VCALENDAR\r\n";
        
        return $ics;
    }
    
    /**
     * Escape special characters for iCalendar format
     */
    private static function escapeString($string) {
        $string = str_replace('\\', '\\\\', $string);
        $string = str_replace(',', '\\,', $string);
        $string = str_replace(';', '\\;', $string);
        $string = str_replace("\n", '\\n', $string);
        return $string;
    }
    
    /**
     * Generate Google Calendar add link
     */
    public static function generateGoogleCalendarLink($booking) {
        $start_datetime = new DateTime($booking['appointment_date'] . ' ' . $booking['appointment_time']);
        $end_datetime = clone $start_datetime;
        $end_datetime->modify('+' . $booking['duration_minutes'] . ' minutes');
        
        $params = [
            'action' => 'TEMPLATE',
            'text' => $booking['service_type'] . ' - Brook\'s Dog Training Academy',
            'dates' => $start_datetime->format('Ymd\THis') . '/' . $end_datetime->format('Ymd\THis'),
            'details' => "Dog Training Appointment\n\nService: " . $booking['service_type'] . 
                        "\nClient: " . $booking['client_name'] .
                        ($booking['notes'] ? "\nNotes: " . $booking['notes'] : "") .
                        "\n\nFor questions, contact: info@brooksdogtraining.com",
            'location' => 'Highlands County, Florida',
            'trp' => 'false'
        ];
        
        return 'https://calendar.google.com/calendar/render?' . http_build_query($params);
    }
    
    /**
     * Save iCalendar file and return path
     */
    public static function saveToFile($booking, $directory = '/tmp') {
        $ics_content = self::generate($booking);
        $filename = 'booking-' . $booking['id'] . '.ics';
        $filepath = $directory . '/' . $filename;
        
        file_put_contents($filepath, $ics_content);
        
        return $filepath;
    }
}
?>
