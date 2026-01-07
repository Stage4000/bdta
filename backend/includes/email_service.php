<?php
/**
 * Email Service for Booking Confirmations
 * Sends confirmation emails with calendar export links
 */

class EmailService {
    private $from_email = 'bookings@brooksdogtraining.com';
    private $from_name = 'Brook\'s Dog Training Academy';
    private $base_url = 'http://localhost:8000'; // Update for production
    
    /**
     * Send booking confirmation email
     */
    public function sendBookingConfirmation($booking) {
        $to = $booking['client_email'];
        $subject = 'Booking Confirmation - Brook\'s Dog Training Academy';
        
        // Generate calendar links
        require_once __DIR__ . '/icalendar.php';
        $google_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
        $ical_link = $this->base_url . '/backend/public/download_ical.php?booking_id=' . $booking['id'];
        
        // Format date and time nicely
        $date = date('l, F j, Y', strtotime($booking['appointment_date']));
        $time = date('g:i A', strtotime($booking['appointment_time']));
        
        // HTML email body
        $html_body = $this->getConfirmationEmailHTML($booking, $date, $time, $google_link, $ical_link);
        
        // Plain text alternative
        $text_body = $this->getConfirmationEmailText($booking, $date, $time, $google_link, $ical_link);
        
        // Send email
        return $this->sendEmail($to, $subject, $html_body, $text_body);
    }
    
    /**
     * Get HTML email template
     */
    private function getConfirmationEmailHTML($booking, $date, $time, $google_link, $ical_link) {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .booking-details { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #10b981; }
        .button { display: inline-block; padding: 12px 24px; margin: 10px 5px; background: #2563eb; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .button:hover { background: #1e40af; }
        .button-secondary { background: #10b981; }
        .button-secondary:hover { background: #059669; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🐕 Booking Confirmed!</h1>
        </div>
        <div class="content">
            <p>Dear {$booking['client_name']},</p>
            
            <p>Your dog training appointment has been confirmed. We're excited to work with you and your furry friend!</p>
            
            <div class="booking-details">
                <h2>Appointment Details</h2>
                <p><strong>Service:</strong> {$booking['service_type']}</p>
                <p><strong>Date:</strong> {$date}</p>
                <p><strong>Time:</strong> {$time}</p>
                <p><strong>Duration:</strong> {$booking['duration_minutes']} minutes</p>
                <p><strong>Location:</strong> Highlands County, Florida</p>
            </div>
            
            <h3>Add to Your Calendar</h3>
            <p>Don't forget your appointment! Click below to add it to your calendar:</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="{$google_link}" class="button" target="_blank">
                    📅 Add to Google Calendar
                </a>
                <a href="{$ical_link}" class="button button-secondary">
                    📲 Download iCal File
                </a>
            </div>
            
            <p><small>The iCal file works with Apple Calendar, Outlook, and most other calendar applications.</small></p>
            
            <hr style="margin: 30px 0; border: none; border-top: 1px solid #ddd;">
            
            <h3>What to Expect</h3>
            <p>Please arrive 5 minutes early. If you need to reschedule or have any questions, please contact us at:</p>
            <p>📧 Email: info@brooksdogtraining.com<br>
            🔗 Website: https://brooksdogtrainingacademy.com</p>
            
            <p>We look forward to seeing you!</p>
            
            <p>Best regards,<br>
            <strong>Brook Lefkowitz</strong><br>
            ABC Certified Dog Trainer<br>
            Brook's Dog Training Academy</p>
        </div>
        <div class="footer">
            <p>© 2024 Brook's Dog Training Academy | "Teaching Humans to Speak Dog"</p>
            <p>This is an automated confirmation email. Please do not reply directly to this message.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Get plain text email template
     */
    private function getConfirmationEmailText($booking, $date, $time, $google_link, $ical_link) {
        return <<<TEXT
BOOKING CONFIRMED - Brook's Dog Training Academy

Dear {$booking['client_name']},

Your dog training appointment has been confirmed. We're excited to work with you and your furry friend!

APPOINTMENT DETAILS
-------------------
Service: {$booking['service_type']}
Date: {$date}
Time: {$time}
Duration: {$booking['duration_minutes']} minutes
Location: Highlands County, Florida

ADD TO YOUR CALENDAR
--------------------
Don't forget your appointment! Use these links to add it to your calendar:

Google Calendar: {$google_link}

Download iCal file: {$ical_link}
(Works with Apple Calendar, Outlook, and most calendar apps)

WHAT TO EXPECT
--------------
Please arrive 5 minutes early. If you need to reschedule or have any questions, please contact us at:

Email: info@brooksdogtraining.com
Website: https://brooksdogtrainingacademy.com

We look forward to seeing you!

Best regards,
Brook Lefkowitz
ABC Certified Dog Trainer
Brook's Dog Training Academy

---
© 2024 Brook's Dog Training Academy | "Teaching Humans to Speak Dog"
This is an automated confirmation email.
TEXT;
    }
    
    /**
     * Send email using PHP mail() function
     * For production, consider using PHPMailer or similar library
     */
    private function sendEmail($to, $subject, $html_body, $text_body) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->from_name . ' <' . $this->from_email . '>',
            'Reply-To: info@brooksdogtraining.com',
            'X-Mailer: PHP/' . phpversion()
        ];
        
        // In production, use a proper email service (SendGrid, Mailgun, AWS SES, etc.)
        $sent = mail($to, $subject, $html_body, implode("\r\n", $headers));
        
        return [
            'success' => $sent,
            'message' => $sent ? 'Confirmation email sent' : 'Failed to send email'
        ];
    }
}
?>
