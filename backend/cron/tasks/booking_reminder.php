<?php
/**
 * Booking Reminder Task
 * Sends reminder emails to clients with upcoming appointments.
 * Supports multiple configurable reminder rules (e.g. 2 days before, 1 day before),
 * each with an optional email template override.
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';
require_once dirname(dirname(__DIR__)) . '/includes/settings.php';
require_once dirname(dirname(__DIR__)) . '/includes/booking_action_links.php';

/**
 * @phpstan-type BookingRow array<string, mixed>
 * @phpstan-type ReminderRule array<string, mixed>
 * @phpstan-type MailResult array{success: bool, message: string}
 * @phpstan-type RuleResult array{sent: int, errors: list<string>}
 * @phpstan-type TaskResult array{success: bool, items_processed: int, message: string, errors: list<string>}
 */
class BookingReminderTask {
    private SafePDO $conn;
    
    public function __construct(SafePDO $conn) {
        $this->conn = $conn;
    }
    
    /**
     * @return TaskResult
     */
    public function execute(): array {
        // Load per-appointment-type rules (appointment_type_id IS NOT NULL)
        $per_type_rules = $this->conn->query(
            "SELECT * FROM booking_reminder_rules WHERE is_active = 1 AND appointment_type_id IS NOT NULL ORDER BY appointment_type_id, hours_before DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        // Load global rules (appointment_type_id IS NULL)
        $global_rules = $this->conn->query(
            "SELECT * FROM booking_reminder_rules WHERE is_active = 1 AND appointment_type_id IS NULL ORDER BY hours_before DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        if (empty($per_type_rules) && empty($global_rules)) {
            return $this->executeLegacy();
        }

        // Appointment types that have their own rules (global rules skip these bookings)
        $types_with_rules = array_values(array_filter(
            array_map(
                static fn(mixed $appointmentTypeId): string => scalar_string($appointmentTypeId),
                array_column($per_type_rules, 'appointment_type_id')
            ),
            static fn(string $appointmentTypeId): bool => $appointmentTypeId !== ''
        ));

        $total_sent = 0;
        $all_errors = [];

        // Process per-appointment-type rules — each rule only sends to its own appointment type
        foreach ($per_type_rules as $rule) {
            $appointment_type_id = scalar_string($rule['appointment_type_id'] ?? '');
            $result = $this->processRule(
                $rule,
                $appointment_type_id !== '' ? [$appointment_type_id] : null,
                []
            );
            $total_sent  += $result['sent'];
            $all_errors   = array_merge($all_errors, $result['errors']);
        }

        // Process global rules — skip bookings whose appointment type already has per-type rules
        foreach ($global_rules as $rule) {
            $result = $this->processRule($rule, null, $types_with_rules);
            $total_sent  += $result['sent'];
            $all_errors   = array_merge($all_errors, $result['errors']);
        }

        $rule_count = count($per_type_rules) + count($global_rules);
        $message = "Sent {$total_sent} reminder email(s) across {$rule_count} rule(s)";
        if (!empty($all_errors)) {
            $message .= " with " . count($all_errors) . " error(s)";
        }

        return [
            'success'         => true,
            'items_processed' => $total_sent,
            'message'         => $message,
            'errors'          => $all_errors,
        ];
    }

    /**
     * Process a single reminder rule: find eligible bookings and send emails.
     *
     * @param array      $rule              Reminder rule row
     * @param int[]|null $only_apt_types    Limit to these appointment_type_ids (null = no restriction)
     * @param int[]      $exclude_apt_types Skip bookings whose appointment_type_id is in this list
     */
    /**
     * @param ReminderRule $rule
     * @param list<int|string>|null $only_apt_types
     * @param list<int|string> $exclude_apt_types
     * @return RuleResult
     */
    private function processRule(array $rule, ?array $only_apt_types, array $exclude_apt_types): array {
        $hours_before = safe_int($rule['hours_before'] ?? 0);
        $start_time   = date('Y-m-d H:i:s', safe_timestamp(strtotime("+{$hours_before} hours")));
        $end_time     = date('Y-m-d H:i:s', safe_timestamp(strtotime("+{$hours_before} hours + 2 hours")));
        $appointment_datetime_sql = $this->getAppointmentDateTimeSql('b');

        // Build WHERE clause additions for appointment-type filtering
        $extra_where = '';
        $extra_params = [];

        if (!empty($only_apt_types)) {
            $placeholders = implode(',', array_fill(0, count($only_apt_types), '?'));
            $extra_where .= " AND b.appointment_type_id IN ({$placeholders})";
            $extra_params = array_merge($extra_params, $only_apt_types);
        }

        if (!empty($exclude_apt_types)) {
            // Bookings with NULL appointment_type_id have no type-specific rules
            // so global rules should apply to them — include them here.
            // Only skip bookings whose appointment type IS in $exclude_apt_types.
            $placeholders = implode(',', array_fill(0, count($exclude_apt_types), '?'));
            $extra_where .= " AND (b.appointment_type_id IS NULL OR b.appointment_type_id NOT IN ({$placeholders}))";
            $extra_params = array_merge($extra_params, $exclude_apt_types);
        }

        // Find confirmed bookings in the time window that haven't been sent this rule yet
        $stmt = $this->conn->prepare("
            SELECT b.*, c.email AS client_email, c.name AS client_name
            FROM bookings b
            LEFT JOIN clients c ON b.client_id = c.id
            LEFT JOIN booking_reminders_sent brs
                ON brs.booking_id = b.id AND brs.rule_id = ?
            WHERE b.status = 'confirmed'
            AND {$appointment_datetime_sql} BETWEEN ? AND ?
            AND brs.id IS NULL
            {$extra_where}
            ORDER BY b.appointment_date, b.appointment_time
        ");
        $stmt->execute(array_merge([$rule['id'], $start_time, $end_time], $extra_params));
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent   = 0;
        $errors = [];

        foreach ($bookings as $booking) {
            try {
                if (empty($booking['client_email'])) {
                    $errors[] = "No email found for booking #{$booking['id']}";
                    continue;
                }

                $result = $this->sendReminderEmail($booking, $rule);

                if ($result['success']) {
                    // Record this rule as sent for this booking
                    $mark = $this->conn->prepare(
                        "INSERT INTO booking_reminders_sent (booking_id, rule_id) VALUES (?, ?)"
                    );
                    $mark->execute([$booking['id'], $rule['id']]);
                    // Also set the legacy reminder_sent flag for backward compatibility
                    $this->conn->prepare("UPDATE bookings SET reminder_sent = 1 WHERE id = ?")
                               ->execute([$booking['id']]);
                    $sent++;
                } else {
                    $errors[] = "Failed for booking #{$booking['id']}: {$result['message']}";
                }
            } catch (Exception $e) {
                $errors[] = "Error on booking #{$booking['id']}: " . $e->getMessage();
            }
        }

        return ['sent' => $sent, 'errors' => $errors];
    }

    /**
     * Legacy fallback: send a single 24-hour reminder when no rules are configured.
     */
    /**
     * @return TaskResult
     */
    private function executeLegacy(): array {
        $hours_before = 24;
        $start_time   = date('Y-m-d H:i:s', strtotime("+{$hours_before} hours"));
        $end_time     = date('Y-m-d H:i:s', strtotime("+{$hours_before} hours + 2 hours"));
        $appointment_datetime_sql = $this->getAppointmentDateTimeSql('b');

        $stmt = $this->conn->prepare("
            SELECT b.*, c.email AS client_email, c.name AS client_name
            FROM bookings b
            LEFT JOIN clients c ON b.client_id = c.id
            WHERE b.status = 'confirmed'
            AND {$appointment_datetime_sql} BETWEEN ? AND ?
            AND b.reminder_sent = 0
            ORDER BY b.appointment_date, b.appointment_time
        ");
        $stmt->execute([$start_time, $end_time]);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sent_count = 0;
        $errors     = [];

        foreach ($bookings as $booking) {
            if (empty($booking['client_email'])) {
                $errors[] = "No email found for booking #{$booking['id']}";
                continue;
            }
            $result = $this->sendReminderEmail($booking, null);
            if ($result['success']) {
                $this->conn->prepare("UPDATE bookings SET reminder_sent = 1 WHERE id = ?")
                           ->execute([$booking['id']]);
                $sent_count++;
            } else {
                $errors[] = "Failed for booking #{$booking['id']}: {$result['message']}";
            }
        }

        $message = "Sent {$sent_count} reminder email(s) (legacy mode — no rules configured)";
        if (!empty($errors)) {
            $message .= " with " . count($errors) . " error(s)";
        }

        return [
            'success'         => true,
            'items_processed' => $sent_count,
            'message'         => $message,
            'errors'          => $errors,
        ];
    }

    private function getAppointmentDateTimeSql(string $booking_alias): string {
        $driver_name = strtolower(scalar_string($this->conn->getAttribute(PDO::ATTR_DRIVER_NAME)));

        if ($driver_name === 'mysql') {
            return "TIMESTAMP({$booking_alias}.appointment_date, {$booking_alias}.appointment_time)";
        }

        throw new RuntimeException(
            'Unsupported database driver for booking reminders: ' . $driver_name . '. Supported driver: mysql'
        );
    }
    
    /**
     * Send reminder email for a booking, using the rule's template when available.
     *
     * @param array      $booking  Booking row
     * @param array|null $rule     Reminder rule row (or null for legacy mode)
     */
    /**
     * @param BookingRow $booking
     * @param ReminderRule|null $rule
     * @return MailResult
     */
    private function sendReminderEmail(array $booking, ?array $rule): array {
        $email_service = new EmailService(null, $this->conn);
        $appointment_date = scalar_string($booking['appointment_date'] ?? '');
        $appointment_time = scalar_string($booking['appointment_time'] ?? '');
        $booking_id = scalar_string($booking['id'] ?? '');
        $booking_id_int = safe_int($booking['id'] ?? 0);
        $client_email = scalar_string($booking['client_email'] ?? '');
        $client_id = $booking['client_id'] ?? null;
        
        // Format date and time
        $date = date('l, F j, Y', safe_timestamp(strtotime($appointment_date)));
        $time = date('g:i A', safe_timestamp(strtotime($appointment_time)));
        
        // Get calendar links
        require_once dirname(dirname(__DIR__)) . '/includes/icalendar.php';
        $google_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
        $ical_link   = getDynamicBaseUrl() . '/backend/public/download_ical.php?booking_id=' . $booking_id;

        $appointment_type_id = !empty($booking['appointment_type_id']) ? safe_int($booking['appointment_type_id']) : null;
        $rule_template_id    = !empty($rule['template_id']) ? safe_int($rule['template_id']) : null;

        // Priority: appt-type override → rule template → system default → hardcoded
        $db_template = $email_service->getTemplateForTask('booking_reminder', $appointment_type_id, $rule_template_id);

        $html_signature_handled = false;
        $text_signature_handled = false;

        if ($db_template) {
            $variables = [
                'client_name'          => $booking['client_name']      ?? '',
                'client_email'         => $booking['client_email']      ?? '',
                'appointment_date'     => $date,
                'appointment_time'     => $time,
                'appointment_type'     => $booking['service_type']      ?? '',
                'duration'             => $booking['duration_minutes']  ?? '',
                'appointment_location' => $email_service->formatLocationForEmail($booking),
                'booking_link'         => bdta_build_portal_booking_link(getDynamicBaseUrl(), $booking_id_int),
                'booking_reschedule_link' => bdta_build_portal_booking_link(getDynamicBaseUrl(), $booking_id_int, 'reschedule'),
                'booking_cancel_link'     => bdta_build_portal_booking_link(getDynamicBaseUrl(), $booking_id_int, 'cancel'),
                'google_calendar_link' => $google_link,
                'ical_link'            => $ical_link,
                'business_name'        => Settings::get('site_name',      "Brook's Dog Training Academy"),
                'business_email'       => Settings::get('business_email', 'bookings@brooksdogtrainingacademy.com'),
                'business_phone'       => Settings::get('business_phone', ''),
            ];
            $rendered  = $email_service->renderTemplate($db_template, $variables);
            $subject   = $rendered['subject'];
            $html_body = $rendered['body_html'];
            $text_body = $rendered['body_text'] ?: strip_tags($html_body);
            $html_signature_handled = (bool) ($rendered['html_signature_handled'] ?? false);
            $text_signature_handled = ($rendered['body_text'] ?? '') !== ''
                ? (bool) ($rendered['text_signature_handled'] ?? false)
                : $html_signature_handled;
        } else {
            // Fallback hardcoded template — derive subject from rule timing
            $hours = $rule ? safe_int($rule['hours_before'] ?? 24) : 24;
            if ($hours >= 48) {
                $days    = (int)round($hours / 24);
                $subject = "Reminder: Your appointment is in {$days} day" . ($days !== 1 ? 's' : '');
            } elseif ($hours >= 24) {
                $subject = "Reminder: Your appointment is tomorrow";
            } else {
                $subject = "Reminder: Your appointment is in {$hours} hours";
            }
            $html_body = $this->getReminderEmailHTML($booking, $date, $time, $google_link, $ical_link);
            $text_body = $this->getReminderEmailText($booking, $date, $time, $google_link, $ical_link);
        }
        
        return $email_service->routeMail(
            EmailService::MAIL_TYPE_BOOKING_REMINDER,
            $client_email,
            $subject,
            $html_body,
            $text_body,
            [
                'client_id' => is_int($client_id) || is_string($client_id) ? $client_id : null,
                'html_signature_handled' => $html_signature_handled,
                'text_signature_handled' => $text_signature_handled,
            ]
        );
    }
    
    /**
     * Get HTML email template for reminder
     */
    /**
     * @param BookingRow $booking
     */
    private function getReminderEmailHTML(array $booking, string $date, string $time, string $google_link, string $ical_link): string {
        $client_name  = htmlspecialchars(scalar_string($booking['client_name'] ?? ''));
        $service_type = htmlspecialchars(scalar_string($booking['service_type'] ?? ''));
        $duration     = htmlspecialchars(scalar_string($booking['duration_minutes'] ?? ''));
        $booking_id   = safe_int($booking['id'] ?? 0);
        $reschedule_link = htmlspecialchars(bdta_build_portal_booking_link(getDynamicBaseUrl(), $booking_id, 'reschedule'));
        $cancel_link = htmlspecialchars(bdta_build_portal_booking_link(getDynamicBaseUrl(), $booking_id, 'cancel'));
        
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
                <a href="{$reschedule_link}" class="button" target="_blank">
                    🔄 Reschedule Appointment
                </a>
                <a href="{$cancel_link}" class="button button-secondary" target="_blank">
                    ❌ Cancel Appointment
                </a>
            </div>

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
    /**
     * @param BookingRow $booking
     */
    private function getReminderEmailText(array $booking, string $date, string $time, string $google_link, string $ical_link): string {
        $client_name  = scalar_string($booking['client_name'] ?? '');
        $service_type = scalar_string($booking['service_type'] ?? '');
        $duration     = scalar_string($booking['duration_minutes'] ?? '');
        $booking_id   = safe_int($booking['id'] ?? 0);
        $reschedule_link = bdta_build_portal_booking_link(getDynamicBaseUrl(), $booking_id, 'reschedule');
        $cancel_link = bdta_build_portal_booking_link(getDynamicBaseUrl(), $booking_id, 'cancel');
        
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

MANAGE YOUR APPOINTMENT
-----------------------
Reschedule: {$reschedule_link}
Cancel: {$cancel_link}

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
