<?php
/**
 * Booking Reminder Task
 * Sends reminder emails to clients with upcoming appointments
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';

class BookingReminderTask {
    private $conn;
    private $task;
    
    public function __construct($conn, $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    public function execute() {
        // Get reminder settings from scheduled task or use defaults
        // Default: Send reminders 24 hours before appointment
        $hours_before = 24;
        
        // Calculate the time window for upcoming appointments
        $start_time = date('Y-m-d H:i:s', strtotime("+{$hours_before} hours"));
        $end_time = date('Y-m-d H:i:s', strtotime("+{$hours_before} hours + 2 hours"));
        
        // Get bookings that need reminders
        // Only send reminders for confirmed bookings that haven't been sent yet
        $stmt = $this->conn->prepare("
            SELECT b.*, c.email as client_email, c.name as client_name
            FROM bookings b
            LEFT JOIN clients c ON b.client_id = c.id
            WHERE b.status = 'confirmed'
            AND datetime(b.appointment_date || ' ' || b.appointment_time) BETWEEN ? AND ?
            AND b.reminder_sent = 0
            ORDER BY b.appointment_date, b.appointment_time
        ");
        
        $stmt->execute([$start_time, $end_time]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent_count = 0;
        $errors = [];
        
        foreach ($bookings as $booking) {
            try {
                // Use client email if available, otherwise use booking email
                $recipient_email = !empty($booking['client_email']) ? $booking['client_email'] : $booking['client_email'];
                $recipient_name = !empty($booking['client_name']) ? $booking['client_name'] : $booking['client_name'];
                
                if (empty($recipient_email)) {
                    $errors[] = "No email found for booking #{$booking['id']}";
                    continue;
                }
                
                // Send reminder email
                $result = $this->sendReminderEmail($booking);
                
                if ($result['success']) {
                    // Mark reminder as sent
                    $update = $this->conn->prepare("UPDATE bookings SET reminder_sent = 1 WHERE id = ?");
                    $update->execute([$booking['id']]);
                    $sent_count++;
                } else {
                    $errors[] = "Failed to send to {$recipient_email}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error processing booking #{$booking['id']}: " . $e->getMessage();
            }
        }
        
        // Prepare result message
        $message = "Sent {$sent_count} reminder email(s)";
        if (!empty($errors)) {
            $message .= " with " . count($errors) . " error(s)";
        }
        
        return [
            'success' => true,
            'items_processed' => $sent_count,
            'message' => $message,
            'errors' => $errors
        ];
    }
    
    /**
     * Send reminder email for a booking
     */
    private function sendReminderEmail($booking) {
        $email_service = new EmailService();
        
        // Format date and time
        $date = date('l, F j, Y', strtotime($booking['appointment_date']));
        $time = date('g:i A', strtotime($booking['appointment_time']));
        
        // Get calendar links
        require_once dirname(dirname(__DIR__)) . '/includes/icalendar.php';
        $google_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
        $ical_link = getDynamicBaseUrl() . '/backend/public/download_ical.php?booking_id=' . $booking['id'];
        
        // Prepare email content
        $subject = "Reminder: Upcoming Appointment Tomorrow";
        
        $html_body = $this->getReminderEmailHTML($booking, $date, $time, $google_link, $ical_link);
        $text_body = $this->getReminderEmailText($booking, $date, $time, $google_link, $ical_link);
        
        $recipient_email = !empty($booking['client_email']) ? $booking['client_email'] : $booking['client_email'];
        
        return $email_service->sendGenericEmail($recipient_email, $subject, $html_body, $text_body);
    }
    
    /**
     * Get HTML email template for reminder
     */
    private function getReminderEmailHTML($booking, $date, $time, $google_link, $ical_link) {
        $client_name = htmlspecialchars(!empty($booking['client_name']) ? $booking['client_name'] : $booking['client_name']);
        $service_type = htmlspecialchars($booking['service_type']);
        $duration = htmlspecialchars($booking['duration_minutes']);
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #f59e0b; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .reminder-box { background: #fff3cd; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px; }
        .appointment-details { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #10b981; }
        .button { display: inline-block; padding: 12px 24px; margin: 10px 5px; background: #2563eb; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .button-secondary { background: #10b981; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔔 Appointment Reminder</h1>
        </div>
        <div class="content">
            <p>Dear {$client_name},</p>
            
            <div class="reminder-box">
                <p style="margin: 0; font-size: 18px; font-weight: bold;">⏰ Your appointment is coming up soon!</p>
            </div>
            
            <div class="appointment-details">
                <h2>Appointment Details</h2>
                <p><strong>Service:</strong> {$service_type}</p>
                <p><strong>Date:</strong> {$date}</p>
                <p><strong>Time:</strong> {$time}</p>
                <p><strong>Duration:</strong> {$duration} minutes</p>
            </div>
            
            <p>Please arrive 5 minutes early. If you need to reschedule or have any questions, please contact us as soon as possible.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{$google_link}" class="button" target="_blank">
                    📅 Add to Google Calendar
                </a>
                <a href="{$ical_link}" class="button button-secondary">
                    📲 Download iCal File
                </a>
            </div>
            
            <p>We look forward to seeing you!</p>
            
            <p>Best regards,<br>
            <strong>Brook Lefkowitz</strong><br>
            ABC Certified Dog Trainer<br>
            Brook's Dog Training Academy</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Get plain text email template for reminder
     */
    private function getReminderEmailText($booking, $date, $time, $google_link, $ical_link) {
        $client_name = !empty($booking['client_name']) ? $booking['client_name'] : $booking['client_name'];
        $service_type = $booking['service_type'];
        $duration = $booking['duration_minutes'];
        
        return <<<TEXT
APPOINTMENT REMINDER - Brook's Dog Training Academy

Dear {$client_name},

*** YOUR APPOINTMENT IS COMING UP SOON! ***

APPOINTMENT DETAILS
-------------------
Service: {$service_type}
Date: {$date}
Time: {$time}
Duration: {$duration} minutes

Please arrive 5 minutes early. If you need to reschedule or have any questions, please contact us as soon as possible.

ADD TO CALENDAR
---------------
Google Calendar: {$google_link}
Download iCal: {$ical_link}

We look forward to seeing you!

Best regards,
Brook Lefkowitz
ABC Certified Dog Trainer
Brook's Dog Training Academy
TEXT;
    }
}
?>
