<?php
/**
 * Email Service
 *
 * Central hub for all outgoing email in the BDTA application.
 * Every email must pass through {@see EmailService::routeMail()} so that
 * sending, logging, and transport configuration are applied consistently.
 *
 * Quick reference for callers:
 *   - Use the high-level helpers (sendBookingConfirmation, sendInvoiceEmail, …)
 *     for typed transactional email.
 *   - Use sendGenericEmail() with a MAIL_TYPE_* constant for ad-hoc messages.
 *   - Never instantiate PHPMailer directly outside this file.
 *
 * @see backend/MAIL_ROUTING.md for full developer documentation.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * @phpstan-type AssocRow array<string, mixed>
 * @phpstan-type MailResult array{success: bool, message: string}
 * @phpstan-type MailOptions array{cc?: list<string>, bcc?: list<string>, context?: array<string, mixed>, client_id?: int|string|null}
 * @phpstan-type RenderedTemplate array{subject: string, body_html: string, body_text: string}
 */
class EmailService {

    // ─── Mail type constants ──────────────────────────────────────────────────
    // Pass one of these to routeMail() / sendGenericEmail() so that every
    // outgoing message is labelled in the logs and can be handled distinctly
    // in the future (e.g. per-type rate-limiting, templates, or providers).

    /** Booking confirmation sent to the client immediately after a booking is created. */
    const MAIL_TYPE_BOOKING_CONFIRMATION = 'booking_confirmation';

    /** Reminder sent to the client ahead of an upcoming appointment. */
    const MAIL_TYPE_BOOKING_REMINDER     = 'booking_reminder';

    /** Receipt sent to the client after a full invoice payment. */
    const MAIL_TYPE_PAYMENT_RECEIPT      = 'payment_receipt';

    /** Invoice sent to the client requesting payment. */
    const MAIL_TYPE_INVOICE              = 'invoice';

    /** Reminder sent to the client for an overdue invoice. */
    const MAIL_TYPE_INVOICE_REMINDER     = 'invoice_reminder';

    /** Quote sent to the client (initial send or resend). */
    const MAIL_TYPE_QUOTE                = 'quote';

    /** Reminder sent to the client for an unsigned contract. */
    const MAIL_TYPE_CONTRACT_REMINDER    = 'contract_reminder';

    /** Reminder sent to the client for an unanswered quote. */
    const MAIL_TYPE_QUOTE_REMINDER       = 'quote_reminder';

    /** Reminder sent to the client for an incomplete form. */
    const MAIL_TYPE_FORM_REMINDER        = 'form_reminder';

    /** Cancellation notification sent to the client when an appointment is cancelled. */
    const MAIL_TYPE_BOOKING_CANCELLATION = 'booking_cancellation';

    /** Automated email dispatched by a workflow step. */
    const MAIL_TYPE_WORKFLOW             = 'workflow';

    /** Manually composed or scheduled email sent to a client. */
    const MAIL_TYPE_COMPOSE              = 'compose';

    /** Password-reset link sent to an admin or portal user. */
    const MAIL_TYPE_PASSWORD_RESET       = 'password_reset';

    /** General-purpose fallback type for emails that do not fit a specific category. */
    const MAIL_TYPE_GENERIC              = 'generic';

    // ─────────────────────────────────────────────────────────────────────────

    private string $from_email;
    private string $from_name;
    private string $base_url;
    private ?PDO $conn;

    public function __construct(?string $base_url = null, ?PDO $conn = null) {
        $this->from_email = self::settingString('email_from_address', 'bookings@brooksdogtrainingacademy.com');
        $this->from_name  = self::settingString('email_from_name', "Brook's Dog Training Academy");

        // Use provided base_url, or get it dynamically.
        // getDynamicBaseUrl() handles both HTTP and CLI contexts internally.
        $this->base_url = $base_url ?? getDynamicBaseUrl();
        $this->conn     = $conn;
    }

    private static function settingString(string $key, string $default = ''): string {
        return scalar_string(Settings::get($key, $default));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowString(array $row, string $key, string $default = ''): string {
        return scalar_string($row[$key] ?? $default);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowFloat(array $row, string $key, float $default = 0.0): float {
        return safe_float($row[$key] ?? $default);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowId(array $row, string $key = 'client_id'): int|string|null {
        $value = $row[$key] ?? null;
        return is_int($value) || is_string($value) ? $value : null;
    }

    // =========================================================================
    // CENTRAL MAIL ROUTING
    // =========================================================================

    /**
     * Central mail routing function — the single unified entry point for every
     * outgoing email in the application.
     *
     * All public send-methods in this class delegate to routeMail() so that:
     *   - Every outgoing message is logged with its type, recipient, and subject.
     *   - Transport configuration (SMTP vs PHP mail) is applied in one place.
     *   - Future cross-cutting concerns (rate-limiting, multi-provider failover,
     *     audit trails, metrics) can be added here without touching callers.
     *
     * Usage (for callers that build their own content):
     * <code>
     * $result = $emailService->routeMail(
     *     EmailService::MAIL_TYPE_PASSWORD_RESET,
     *     'user@example.com',
     *     'Reset your password',
     *     $html_body,
     *     $text_body,
     *     ['context' => ['user_id' => 42]]
     * );
     * if (!$result['success']) { … }
     * </code>
     *
     * @param string $mail_type  One of the EmailService::MAIL_TYPE_* constants.
     *                           Use MAIL_TYPE_GENERIC when no specific type applies.
     * @param string $to         Recipient email address.
     * @param string $subject    Email subject line.
     * @param string $html_body  HTML version of the message body.
     * @param string $text_body  Plain-text version. Auto-derived from HTML when empty.
     * @param MailOptions $options Optional delivery overrides:
     *                           - 'cc'      (array)  CC recipient addresses.
     *                           - 'bcc'     (array)  BCC recipient addresses.
     *                           - 'context' (array)  Extra key→value data appended
     *                                                to the log entry for tracing.
     * @return MailResult
     */
    public function routeMail(
        string $mail_type,
        string $to,
        string $subject,
        string $html_body,
        string $text_body = '',
        array  $options   = []
    ): array {
        // Normalize plain-text fallback
        if (empty($text_body)) {
            $text_body = strip_tags($html_body);
        }

        $cc        = $options['cc']        ?? [];
        $bcc       = $options['bcc']       ?? [];
        $context   = $options['context']   ?? [];
        $client_id = $options['client_id'] ?? null;

        // ── Pre-send log entry ────────────────────────────────────────────────
        $log_prefix = '[MailRouter]';
        $log_entry  = sprintf(
            '%s ROUTING type=%s to=%s subject="%s"',
            $log_prefix,
            $mail_type,
            $to,
            $subject
        );
        if (!empty($context)) {
            $log_entry .= ' context=' . json_encode($context, JSON_UNESCAPED_SLASHES);
        }
        error_log($log_entry);

        // ── Dispatch through the transport layer ──────────────────────────────
        $result = $this->sendEmail($to, $subject, $html_body, $text_body, $cc, $bcc);

        // ── Post-send log entry ───────────────────────────────────────────────
        if ($result['success']) {
            error_log(sprintf('%s SENT    type=%s to=%s', $log_prefix, $mail_type, $to));
        } else {
            error_log(sprintf(
                '%s FAILED  type=%s to=%s reason="%s"',
                $log_prefix,
                $mail_type,
                $to,
                $result['message']
            ));
        }

        // ── Persist to client email history ──────────────────────────────────
        // Skip MAIL_TYPE_COMPOSE (already logged by client_emails_api.php before send)
        // and MAIL_TYPE_PASSWORD_RESET (not a client-facing communication).
        if (
            $client_id
            && $this->conn
            && $mail_type !== self::MAIL_TYPE_COMPOSE
            && $mail_type !== self::MAIL_TYPE_PASSWORD_RESET
        ) {
            $this->logToClientEmails($client_id, $to, $subject, $html_body, $text_body, $result, $mail_type);
        }

        return $result;
    }

    /**
     * Insert a row into client_emails to record an automated outgoing email.
     *
     * @param int    $client_id  Client the email was sent to.
     * @param string $to         Recipient email address.
     * @param string $subject    Email subject.
     * @param string $html_body  HTML body.
     * @param string $text_body  Plain-text body.
     * @param array<string, mixed> $result Return value of sendEmail() (has 'success' and 'message').
     * @param string $mail_type  MAIL_TYPE_* constant for categorisation.
     */
    private function logToClientEmails(int|string $client_id, string $to, string $subject, string $html_body, string $text_body, array $result, string $mail_type): void {
        try {
            if ($this->conn === null) {
                return;
            }

            $now    = date('Y-m-d H:i:s');
            $status = $result['success'] ? 'sent' : 'failed';
            $stmt   = $this->conn->prepare("
                INSERT INTO client_emails (
                    client_id, direction, status, from_email, to_email,
                    subject, body_html, body_text, mail_type,
                    sent_at, failed_at, error_message,
                    created_at, updated_at
                ) VALUES (
                    ?, 'outgoing', ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?,
                    ?, ?
                )
            ");
            $stmt->execute([
                (int)$client_id,
                $status,
                $this->from_email,
                $to,
                $subject,
                $html_body,
                $text_body,
                $mail_type,
                $result['success'] ? $now : null,
                $result['success'] ? null : $now,
                $result['success'] ? null : ($result['message'] ?? 'Unknown error'),
                $now,
                $now,
            ]);
        } catch (\Exception $e) {
            error_log('[MailRouter] Failed to log email to client_emails: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // TEMPLATE RESOLUTION
    // =========================================================================

    /**
     * Look up the applicable email template for a given task type and optional appointment type.
     * Priority: appointment-type override → rule template → system default → null (use hardcoded fallback).
     *
     * @param string   $template_type      One of: booking_confirmation, booking_reminder, payment_receipt, …
     * @param int|null $appointment_type_id  ID of the appointment type (for per-type overrides)
     * @param int|null $rule_template_id     Template ID from the specific reminder rule being processed
     * @return AssocRow|null Row from email_templates, or null
     */
    public function getTemplateForTask(string $template_type, ?int $appointment_type_id = null, ?int $rule_template_id = null): ?array {
        if (!$this->conn) {
            return null;
        }

        // Column name in appointment_types for the override
        $override_col_map = [
            'booking_confirmation' => 'confirmation_template_id',
            'booking_reminder'     => 'reminder_template_id',
            'booking_cancellation' => 'cancellation_template_id',
        ];

        // Setting key for the system-wide default
        $default_setting_map = [
            'booking_confirmation' => 'default_confirmation_template_id',
            'booking_reminder'     => 'default_reminder_template_id',
            'payment_receipt'      => 'default_payment_receipt_template_id',
            'booking_cancellation' => 'default_cancellation_template_id',
        ];

        // 1. Check per-appointment-type override
        if ($appointment_type_id && isset($override_col_map[$template_type])) {
            $col = $override_col_map[$template_type];
            // Whitelist the column name to prevent any future SQL injection risk
            $allowed_cols = ['confirmation_template_id', 'reminder_template_id', 'cancellation_template_id'];
            if (!in_array($col, $allowed_cols, true)) {
                // Should never happen since $override_col_map is hardcoded
                return null;
            }
            $stmt = $this->conn->prepare(
                "SELECT et.* FROM email_templates et
                 INNER JOIN appointment_types at2 ON at2.{$col} = et.id
                 WHERE at2.id = ? AND et.is_active = 1"
            );
            $stmt->execute([$appointment_type_id]);
            $tmpl = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($tmpl)) {
                /** @var array<string, mixed> $tmpl */
                return $tmpl;
            }
        }

        // 2. Check the reminder-rule-specific template (if provided)
        if ($rule_template_id) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM email_templates WHERE id = ? AND is_active = 1"
            );
            $stmt->execute([$rule_template_id]);
            $tmpl = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($tmpl)) {
                /** @var array<string, mixed> $tmpl */
                return $tmpl;
            }
        }

        // 3. Check system default setting
        if (isset($default_setting_map[$template_type])) {
            $setting_key = $default_setting_map[$template_type];
            $default_id = safe_int(Settings::get($setting_key, 0));
            if ($default_id > 0) {
                $stmt = $this->conn->prepare(
                    "SELECT * FROM email_templates WHERE id = ? AND is_active = 1"
                );
                $stmt->execute([$default_id]);
                $tmpl = $stmt->fetch(PDO::FETCH_ASSOC);
                if (is_array($tmpl)) {
                    /** @var array<string, mixed> $tmpl */
                    return $tmpl;
                }
            }
        }

        return null;
    }

    /**
     * Render an email template row by replacing {{variable}} placeholders.
     *
     * After substitution the HTML body is automatically wrapped in the
     * standard styled email container (see wrapEmailHtml()) unless the
     * template already supplies a full HTML document.
     *
     * @param AssocRow $template  Row from email_templates (keys: subject, body_html, body_text)
     * @param array<string, mixed> $variables Map of variable name => value
     * @return RenderedTemplate ['subject' => …, 'body_html' => …, 'body_text' => …]
     */
    public function renderTemplate(array $template, array $variables): array {
        $subject   = self::rowString($template, 'subject');
        $body_html = self::rowString($template, 'body_html');
        $body_text = self::rowString($template, 'body_text');

        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $replacement = scalar_string($value);
            $subject   = str_replace($placeholder, $replacement, $subject);
            $body_html = str_replace($placeholder, $replacement, $body_html);
            $body_text = str_replace($placeholder, $replacement, $body_text);
        }

        // Apply the standard email wrapper so custom templates get the same
        // visual styling as system-generated emails.
        $body_html = self::wrapEmailHtml($body_html);

        return [
            'subject'   => $subject,
            'body_html' => $body_html,
            'body_text' => $body_text,
        ];
    }

    /**
     * Wrap a partial HTML email body in the standard styled email container.
     *
     * All system-generated emails share a consistent visual style (white
     * content card on a light-grey background, branded footer, etc.).
     * This method applies that same wrapper to custom templates so that
     * every outgoing email looks equally polished.
     *
     * If the supplied content already contains a complete HTML document
     * (i.e. it starts with <!DOCTYPE or <html>) it is returned unchanged,
     * allowing templates that supply their own full layout to opt out.
     *
     * @param string $content       HTML fragment or full document.
     * @param string $business_name Business name shown in the footer.
     *                              Defaults to the site_name setting when empty.
     * @return string Full HTML email document.
     */
    public static function wrapEmailHtml(string $content, string $business_name = ''): string {
        // Do not re-wrap templates that already supply a full HTML document.
        $trimmed = ltrim($content);
        if (stripos($trimmed, '<!doctype') === 0 || stripos($trimmed, '<html') === 0) {
            return $content;
        }

        if ($business_name === '') {
            $business_name = self::settingString('site_name', "Brook's Dog Training Academy");
        }
        $year         = date('Y');
        $business_esc = htmlspecialchars($business_name);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 8px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
        .button { display: inline-block; padding: 12px 24px; margin: 10px 5px; background: #2563eb; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .button-secondary { background: #10b981; }
        a { color: #2563eb; }
        h1, h2, h3 { color: #1e293b; }
        .details-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #10b981; }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            {$content}
        </div>
        <div class="footer">
            <p>&copy; {$year} {$business_esc}</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Send booking confirmation email
     */
    /**
     * @param AssocRow $booking
     * @return MailResult
     */
    public function sendBookingConfirmation(array $booking): array {
        $to = self::rowString($booking, 'client_email');
        $booking_id = self::rowString($booking, 'id');
        $appointment_date = self::rowString($booking, 'appointment_date');
        $appointment_time = self::rowString($booking, 'appointment_time');
        $appointment_type_id = ($booking['appointment_type_id'] ?? null) !== null ? safe_int($booking['appointment_type_id']) : 0;
        
        // Generate calendar links
        require_once __DIR__ . '/icalendar.php';
        $google_link = ICalendarGenerator::generateGoogleCalendarLink($booking);
        $ical_link = $this->base_url . '/backend/public/download_ical.php?booking_id=' . $booking_id;
        
        // Format date and time nicely
        $date = date('l, F j, Y', safe_timestamp(strtotime($appointment_date)));
        $time = date('g:i A', safe_timestamp(strtotime($appointment_time)));

        // Try to use a custom DB template (appointment-type override or system default)
        $db_template = $this->getTemplateForTask('booking_confirmation', $appointment_type_id > 0 ? $appointment_type_id : null);

        if ($db_template) {
            $variables = $this->buildBookingVariables($booking, $date, $time, $google_link, $ical_link);
            $rendered  = $this->renderTemplate($db_template, $variables);
            $subject   = $rendered['subject'];
            $html_body = $rendered['body_html'];
            $text_body = $rendered['body_text'] ?: strip_tags($html_body);
        } else {
            // Fallback to hardcoded template
            $subject   = 'Booking Confirmation - Brook\'s Dog Training Academy';
            $html_body = $this->getConfirmationEmailHTML($booking, $date, $time, $google_link, $ical_link);
            $text_body = $this->getConfirmationEmailText($booking, $date, $time, $google_link, $ical_link);
        }
        
        // Route through central mail router
        return $this->routeMail(self::MAIL_TYPE_BOOKING_CONFIRMATION, $to, $subject, $html_body, $text_body, [
            'client_id' => self::rowId($booking),
        ]);
    }

    /**
     * Send a booking cancellation email to the client.
     *
     * @param array  $booking  Row from bookings (must include client_email, client_name, etc.)
     * @param string $reason   Optional reason for the cancellation
     * @return array{success: bool, message: string}
     */
    /**
     * @param AssocRow $booking
     * @return MailResult
     */
    public function sendBookingCancellation(array $booking, string $reason = ''): array {
        $to = self::rowString($booking, 'client_email');
        if (empty($to)) {
            return ['success' => false, 'message' => 'No client email address on file'];
        }

        // Format date and time nicely
        $date = date('l, F j, Y', safe_timestamp(strtotime(self::rowString($booking, 'appointment_date'))));
        $time = date('g:i A', safe_timestamp(strtotime(self::rowString($booking, 'appointment_time'))));

        // Try to use a custom DB template (appointment-type override or system default)
        $appointment_type_id = safe_int($booking['appointment_type_id'] ?? 0);
        $db_template = $this->getTemplateForTask('booking_cancellation', $appointment_type_id > 0 ? $appointment_type_id : null);

        if ($db_template) {
            $variables = array_merge(
                $this->buildBookingVariables($booking, $date, $time, '', ''),
                ['cancellation_reason' => $reason]
            );
            $rendered  = $this->renderTemplate($db_template, $variables);
            $subject   = $rendered['subject'];
            $html_body = $rendered['body_html'];
            $text_body = $rendered['body_text'] ?: strip_tags($html_body);
        } else {
            // Fallback to hardcoded template
            $subject   = 'Appointment Cancelled - Brook\'s Dog Training Academy';
            $html_body = $this->getCancellationEmailHTML($booking, $date, $time, $reason);
            $text_body = $this->getCancellationEmailText($booking, $date, $time, $reason);
        }

        return $this->routeMail(self::MAIL_TYPE_BOOKING_CANCELLATION, $to, $subject, $html_body, $text_body, [
            'client_id' => self::rowId($booking),
        ]);
    }

    /**
     * Send a booking reschedule confirmation email to the client.
     *
     * @param array  $booking     Row from bookings with new date/time already set
     * @param string $old_date    Previous appointment date (Y-m-d)
     * @param string $old_time    Previous appointment time (H:i or H:i:s)
     * @param string $reason      Optional reason provided by the client
     * @return array{success: bool, message: string}
     */
    /**
     * @param AssocRow $booking
     * @return MailResult
     */
    public function sendBookingReschedule(array $booking, string $old_date, string $old_time, string $reason = ''): array {
        $to = self::rowString($booking, 'client_email');
        if (empty($to)) {
            return ['success' => false, 'message' => 'No client email address on file'];
        }

        $new_date = date('l, F j, Y', safe_timestamp(strtotime(scalar_string($booking['appointment_date']))));
        $new_time = date('g:i A', safe_timestamp(strtotime(scalar_string($booking['appointment_time']))));
        $old_date_fmt = date('l, F j, Y', safe_timestamp(strtotime($old_date)));
        $old_time_fmt = date('g:i A', safe_timestamp(strtotime($old_time)));

        $business_name  = self::settingString('site_name', "Brook's Dog Training Academy");
        $business_email = self::settingString('business_email', 'bookings@brooksdogtrainingacademy.com');
        $client_name    = self::rowString($booking, 'client_name', 'Valued Client');
        $service        = self::rowString($booking, 'service_type');

        $subject = "Appointment Rescheduled - {$business_name}";

        $html_body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #9a0073;'>Appointment Rescheduled</h2>
            <p>Hi {$client_name},</p>
            <p>Your appointment has been successfully rescheduled. Here are the updated details:</p>
            <table style='width:100%; border-collapse:collapse;'>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Service</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>" . htmlspecialchars($service) . "</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>New Date</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$new_date}</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>New Time</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$new_time}</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Previous Date</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$old_date_fmt}</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Previous Time</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$old_time_fmt}</td></tr>
            </table>" .
            (!empty($reason) ? "<p><strong>Reason:</strong> " . htmlspecialchars($reason) . "</p>" : "") .
            "<p>If you need to make any further changes or have questions, please contact us at
            <a href='mailto:{$business_email}'>{$business_email}</a>.</p>
            <p>Best regards,<br>{$business_name}</p>
        </div>";

        $text_body = "Appointment Rescheduled\n\nHi {$client_name},\n\n"
            . "Your appointment has been rescheduled.\n\n"
            . "Service: {$service}\nNew Date: {$new_date}\nNew Time: {$new_time}\n"
            . "Previous Date: {$old_date_fmt}\nPrevious Time: {$old_time_fmt}\n"
            . (!empty($reason) ? "Reason: {$reason}\n" : '')
            . "\nQuestions? Contact us at {$business_email}.\n\nBest regards,\n{$business_name}";

        return $this->routeMail(self::MAIL_TYPE_BOOKING_CONFIRMATION, $to, $subject, $html_body, $text_body, [
            'client_id' => self::rowId($booking),
        ]);
    }

    /**
     * Notify the admin when a client cancels or reschedules an appointment.
     *
     * @param array  $booking     Booking row (with old data for reschedules)
     * @param string $change_type 'cancellation' or 'reschedule'
     * @param string $reason      Optional client-provided reason
     * @param string $old_date    Previous date (for reschedules, Y-m-d)
     * @param string $old_time    Previous time (for reschedules, H:i or H:i:s)
     * @return array{success: bool, message: string}
     */
    /**
     * @param AssocRow $booking
     * @return MailResult
     */
    public function sendAdminBookingChangeNotification(array $booking, string $change_type, string $reason = '', string $old_date = '', string $old_time = ''): array {
        $admin_email = self::settingString('business_email', 'bookings@brooksdogtrainingacademy.com');
        if (empty($admin_email)) {
            return ['success' => false, 'message' => 'No admin email configured'];
        }

        $business_name = self::settingString('site_name', "Brook's Dog Training Academy");
        $client_name   = self::rowString($booking, 'client_name', 'A client');
        $service       = self::rowString($booking, 'service_type');
        $booking_id = self::rowString($booking, 'id');

        if ($change_type === 'cancellation') {
            $subject = "Client Cancellation: {$client_name} - {$business_name}";
            $date_fmt = date('l, F j, Y', safe_timestamp(strtotime(self::rowString($booking, 'appointment_date'))));
            $time_fmt = date('g:i A', safe_timestamp(strtotime(self::rowString($booking, 'appointment_time'))));
            $detail_rows = "
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Date</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$date_fmt}</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Time</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$time_fmt}</td></tr>";
            $action_label = 'cancelled';
        } else {
            $subject = "Client Reschedule: {$client_name} - {$business_name}";
            $new_date_fmt = date('l, F j, Y', safe_timestamp(strtotime(scalar_string($booking['appointment_date']))));
            $new_time_fmt = date('g:i A', safe_timestamp(strtotime(scalar_string($booking['appointment_time']))));
            $old_date_fmt = date('l, F j, Y', safe_timestamp(strtotime($old_date)));
            $old_time_fmt = date('g:i A', safe_timestamp(strtotime($old_time)));
            $detail_rows = "
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>New Date</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$new_date_fmt}</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>New Time</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$new_time_fmt}</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Previous Date</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$old_date_fmt}</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Previous Time</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>{$old_time_fmt}</td></tr>";
            $action_label = 'rescheduled';
        }

        $html_body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #9a0073;'>Appointment {$action_label} by Client</h2>
            <p><strong>{$client_name}</strong> has {$action_label} their appointment.</p>
            <table style='width:100%; border-collapse:collapse;'>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Client</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>" . htmlspecialchars($client_name) . "</td></tr>
                <tr><td style='padding:8px; border:1px solid #ddd; background:#f9f9f9;'><strong>Service</strong></td>
                    <td style='padding:8px; border:1px solid #ddd;'>" . htmlspecialchars($service) . "</td></tr>
                {$detail_rows}
            </table>" .
            (!empty($reason) ? "<p><strong>Client reason:</strong> " . htmlspecialchars($reason) . "</p>" : "") .
            "<p>Booking ID: #{$booking_id}</p>
        </div>";

        $text_body = "Appointment {$action_label} by client\n\n"
            . "Client: {$client_name}\nService: {$service}\n"
            . (!empty($reason) ? "Reason: {$reason}\n" : '')
            . "Booking ID: #{$booking_id}\n";

        return $this->routeMail(self::MAIL_TYPE_GENERIC, $admin_email, $subject, $html_body, $text_body);
    }

    /**
     *
     * @param AssocRow $invoice Row from invoices (joined with client name/email)
     * @param AssocRow|null $installment Row from invoice_installments, or null for full invoice
     * @param list<AssocRow> $items Rows from invoice_items (used for full-invoice receipts)
     * @return MailResult Result array with 'success' and 'message' keys
     */
    public function sendPaymentReceipt(array $invoice, ?array $installment = null, array $items = []): array {
        $to = self::rowString($invoice, 'client_email');
        if (empty($to)) {
            return ['success' => false, 'message' => 'No client email address on file'];
        }

        $client_name    = self::rowString($invoice, 'client_name', 'Valued Client');
        $invoice_number = self::rowString($invoice, 'invoice_number');
        $business_name  = self::settingString('site_name', "Brook's Dog Training Academy");
        $business_email = self::settingString('business_email', 'bookings@brooksdogtrainingacademy.com');

        // CC the business on receipts for record-keeping
        $cc = array_filter([$business_email]);

        if ($installment) {
            // Installment receipt
            $amount        = number_format(self::rowFloat($installment, 'amount'), 2);
            $payment_date  = !empty($installment['payment_date'])
                ? date('F j, Y', safe_timestamp(strtotime(self::rowString($installment, 'payment_date'))))
                : date('F j, Y');
            $payment_method = ucwords(str_replace('_', ' ', self::rowString($installment, 'payment_method')));
            $inst_number   = self::rowString($installment, 'installment_number');

            $subject = "Payment Receipt — {$business_name} (Invoice {$invoice_number}, Installment #{$inst_number})";

            $items_html = '';
            $items_text = '';
        } else {
            // Full invoice receipt
            $amount        = number_format(self::rowFloat($invoice, 'total_amount'), 2);
            $payment_date  = !empty($invoice['payment_date'])
                ? date('F j, Y', safe_timestamp(strtotime(self::rowString($invoice, 'payment_date'))))
                : date('F j, Y');
            $payment_method = ucwords(str_replace('_', ' ', self::rowString($invoice, 'payment_method')));
            $inst_number   = null;

            $subject = "Payment Receipt — {$business_name} (Invoice {$invoice_number})";

            // Build line-item HTML for full invoice
            $items_html = '';
            $items_text = '';
            foreach ($items as $item) {
                $desc   = htmlspecialchars(self::rowString($item, 'description'));
                $qty    = number_format(self::rowFloat($item, 'quantity'), 2);
                $rate   = number_format(self::rowFloat($item, 'rate'), 2);
                $lamt   = number_format(self::rowFloat($item, 'amount'), 2);
                $items_html .= "<tr><td>{$desc}</td><td style='text-align:right'>{$qty}</td>"
                    . "<td style='text-align:right'>\${$rate}</td>"
                    . "<td style='text-align:right'>\${$lamt}</td></tr>";
                $items_text .= "  {$desc} — Qty: {$qty}  Rate: \${$rate}  Amount: \${$lamt}\n";
            }
        }

        // Try a custom DB template first
        $db_template = $this->getTemplateForTask('payment_receipt');
        if ($db_template) {
            $variables = [
                'client_name'      => $client_name,
                'client_email'     => $to,
                'invoice_number'   => $invoice_number,
                'amount'           => $amount,
                'payment_date'     => $payment_date,
                'payment_method'   => $payment_method,
                'installment_number' => $inst_number ?? '',
                'business_name'    => $business_name,
                'business_email'   => $business_email,
            ];
            $rendered  = $this->renderTemplate($db_template, $variables);
            $html_body = $rendered['body_html'];
            $text_body = $rendered['body_text'] ?: strip_tags($html_body);
            $subject   = $rendered['subject'] ?: $subject;
        } else {
            // Built-in receipt template
            $inst_label = $inst_number ? " — Installment #{$inst_number}" : '';

            $items_section_html = '';
            if (!$installment && !empty($items_html)) {
                $items_section_html = <<<HTML
<h3 style="margin:20px 0 10px">Services</h3>
<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse">
  <thead>
    <tr style="background:#f0f4f8">
      <th style="text-align:left">Description</th>
      <th style="text-align:right">Qty</th>
      <th style="text-align:right">Rate</th>
      <th style="text-align:right">Amount</th>
    </tr>
  </thead>
  <tbody>{$items_html}</tbody>
</table>
HTML;
            }

            $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;line-height:1.6">
<div style="max-width:600px;margin:0 auto;padding:20px">
  <div style="background:#10b981;color:white;padding:20px;text-align:center;border-radius:8px 8px 0 0">
    <h1 style="margin:0">&#10003; Payment Receipt</h1>
  </div>
  <div style="background:#f8f9fa;padding:30px;border-radius:0 0 8px 8px">
    <p>Dear {$client_name},</p>
    <p>Thank you! We have received your payment. Please keep this receipt for your records.</p>
    <div style="background:white;padding:20px;margin:20px 0;border-radius:8px;border-left:4px solid #10b981">
      <h2 style="margin-top:0">Receipt Details{$inst_label}</h2>
      <p><strong>Invoice Number:</strong> {$invoice_number}</p>
      <p><strong>Date Paid:</strong> {$payment_date}</p>
      <p><strong>Amount Paid:</strong> <span style="font-size:20px;font-weight:bold;color:#10b981">\${$amount}</span></p>
      <p><strong>Payment Method:</strong> {$payment_method}</p>
    </div>
    {$items_section_html}
    <p>If you have any questions about this receipt, please contact us at
       <a href="mailto:{$business_email}">{$business_email}</a>.</p>
    <p>Thank you for choosing {$business_name}!</p>
  </div>
</div>
</body>
</html>
HTML;

            $items_section_text = '';
            if (!$installment && !empty($items_text)) {
                $items_section_text = "\nSERVICES\n--------\n{$items_text}";
            }

            $text_body = "PAYMENT RECEIPT — {$business_name}\n\n"
                . "Dear {$client_name},\n\n"
                . "Thank you! We have received your payment.\n\n"
                . "RECEIPT DETAILS{$inst_label}\n"
                . str_repeat('-', 30) . "\n"
                . "Invoice Number : {$invoice_number}\n"
                . "Date Paid      : {$payment_date}\n"
                . "Amount Paid    : \${$amount}\n"
                . "Payment Method : {$payment_method}\n"
                . $items_section_text . "\n\n"
                . "Questions? Contact us at {$business_email}\n\n"
                . "Thank you for choosing {$business_name}!";
        }

        return $this->routeMail(self::MAIL_TYPE_PAYMENT_RECEIPT, $to, $subject, $html_body, $text_body, [
            'cc'        => $cc,
            'client_id' => self::rowId($invoice),
        ]);
    }

    /**
     * Send an invoice email to the client (before payment).
     *
     * @param AssocRow $invoice Row from invoices (joined with client name/email)
     * @param list<AssocRow> $items Rows from invoice_items
     * @return MailResult Result array with 'success' and 'message' keys
     */
    public function sendInvoiceEmail(array $invoice, array $items = []): array {
        $to = self::rowString($invoice, 'client_email');
        if (empty($to)) {
            return ['success' => false, 'message' => 'No client email address on file'];
        }

        $client_name    = self::rowString($invoice, 'client_name', 'Valued Client');
        $invoice_number = self::rowString($invoice, 'invoice_number');
        $invoice_id     = self::rowString($invoice, 'id', '0');
        $business_name  = self::settingString('site_name', "Brook's Dog Training Academy");
        $business_email = self::settingString('business_email', 'bookings@brooksdogtrainingacademy.com');
        $total_amount   = number_format(self::rowFloat($invoice, 'total_amount'), 2);
        $due_date       = !empty($invoice['due_date'])
            ? date('F j, Y', safe_timestamp(strtotime(self::rowString($invoice, 'due_date'))))
            : '';
        $issue_date     = !empty($invoice['issue_date'])
            ? date('F j, Y', safe_timestamp(strtotime(self::rowString($invoice, 'issue_date'))))
            : '';

        $subject = "Invoice {$invoice_number} — {$business_name}";

        // Use the secure pay_token for the guest payment link if available
        $pay_token    = self::rowString($invoice, 'pay_token');
        $guest_pay_url = !empty($pay_token)
            ? $this->base_url . '/portal/invoice_pay.php?token=' . urlencode($pay_token)
            : $this->base_url . '/portal/invoice_view.php?id=' . $invoice_id;

        // Build "Pay Now" button section if Stripe is enabled and invoice is unpaid
        require_once __DIR__ . '/stripe_config.php';
        $pay_now_html = '';
        $pay_now_text = '';
        if (isStripeEnabled() && ($invoice['status'] ?? '') !== 'paid') {
            $pay_url = $guest_pay_url;
            $pay_now_html = <<<HTML
    <div style="text-align:center;margin:24px 0">
      <a href="{$pay_url}"
         style="display:inline-block;padding:14px 32px;background:#10b981;color:white;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px">
        &#128179; Pay Now with Credit Card
      </a>
    </div>
HTML;
            $pay_now_text = "\nPAY ONLINE\n----------\nPay securely with a credit card: {$pay_url}\n";
        }

        // View invoice link section — uses guest URL (no login required)
        $view_invoice_html = <<<HTML
    <div style="text-align:center;margin:16px 0">
      <a href="{$guest_pay_url}"
         style="display:inline-block;padding:10px 24px;background:#2563eb;color:white;text-decoration:none;border-radius:6px;font-weight:bold">
        &#128196; View Invoice Online
      </a>
    </div>
HTML;
        $view_invoice_text = "\nVIEW INVOICE ONLINE\n-------------------\n{$guest_pay_url}\n";

        // Build line-item HTML and text
        $items_html = '';
            $items_text = '';
            foreach ($items as $item) {
                $desc  = htmlspecialchars(self::rowString($item, 'description'));
                $qty   = number_format(self::rowFloat($item, 'quantity'), 2);
                $rate  = number_format(self::rowFloat($item, 'rate'), 2);
                $lamt  = number_format(self::rowFloat($item, 'amount'), 2);
            $items_html .= "<tr><td>{$desc}</td><td style='text-align:right'>{$qty}</td>"
                . "<td style='text-align:right'>\${$rate}</td>"
                . "<td style='text-align:right'>\${$lamt}</td></tr>";
            $items_text .= "  {$desc} — Qty: {$qty}  Rate: \${$rate}  Amount: \${$lamt}\n";
        }

        $items_section_html = '';
        if (!empty($items_html)) {
            $items_section_html = <<<HTML
<h3 style="margin:20px 0 10px">Services</h3>
<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse">
  <thead>
    <tr style="background:#f0f4f8">
      <th style="text-align:left">Description</th>
      <th style="text-align:right">Qty</th>
      <th style="text-align:right">Rate</th>
      <th style="text-align:right">Amount</th>
    </tr>
  </thead>
  <tbody>{$items_html}</tbody>
</table>
HTML;
        }

        $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;line-height:1.6">
<div style="max-width:600px;margin:0 auto;padding:20px">
  <div style="background:#2563eb;color:white;padding:20px;text-align:center;border-radius:8px 8px 0 0">
    <h1 style="margin:0">&#128196; Invoice</h1>
  </div>
  <div style="background:#f8f9fa;padding:30px;border-radius:0 0 8px 8px">
    <p>Dear {$client_name},</p>
    <p>Please find your invoice from {$business_name} below. Payment is due by the date shown.</p>
    <div style="background:white;padding:20px;margin:20px 0;border-radius:8px;border-left:4px solid #2563eb">
      <h2 style="margin-top:0">Invoice Details</h2>
      <p><strong>Invoice Number:</strong> {$invoice_number}</p>
      <p><strong>Issue Date:</strong> {$issue_date}</p>
      <p><strong>Due Date:</strong> {$due_date}</p>
      <p><strong>Amount Due:</strong> <span style="font-size:20px;font-weight:bold;color:#2563eb">\${$total_amount}</span></p>
    </div>
    {$items_section_html}
    {$pay_now_html}
    {$view_invoice_html}
    <p>If you have any questions about this invoice, please contact us at
       <a href="mailto:{$business_email}">{$business_email}</a>.</p>
    <p>Thank you for choosing {$business_name}!</p>
  </div>
</div>
</body>
</html>
HTML;

        $items_section_text = !empty($items_text) ? "\nSERVICES\n--------\n{$items_text}" : '';

        $text_body = "INVOICE — {$business_name}\n\n"
            . "Dear {$client_name},\n\n"
            . "Please find your invoice details below.\n\n"
            . "INVOICE DETAILS\n"
            . str_repeat('-', 30) . "\n"
            . "Invoice Number : {$invoice_number}\n"
            . "Issue Date     : {$issue_date}\n"
            . "Due Date       : {$due_date}\n"
            . "Amount Due     : \${$total_amount}\n"
            . $items_section_text
            . $pay_now_text
            . $view_invoice_text . "\n"
            . "Questions? Contact us at {$business_email}\n\n"
            . "Thank you for choosing {$business_name}!";

        return $this->routeMail(self::MAIL_TYPE_INVOICE, $to, $subject, $html_body, $text_body, [
            'client_id' => self::rowId($invoice),
        ]);
    }

    /**
     * Send a quote email to the client (initial send or resend).
     *
     * @param AssocRow $quote Row from quotes joined with client name/email
     * @param list<AssocRow> $items Rows from quote_items
     * @return MailResult
     */
    public function sendQuoteEmail(array $quote, array $items = []): array {
        $to = self::rowString($quote, 'client_email');
        if (empty($to)) {
            return ['success' => false, 'message' => 'No client email address on file'];
        }

        $client_name   = self::rowString($quote, 'client_name', 'Valued Client');
        $quote_title   = htmlspecialchars(self::rowString($quote, 'title'));
        $quote_number  = self::rowString($quote, 'quote_number');
        $quote_amount  = number_format(self::rowFloat($quote, 'amount'), 2);
        $quote_link    = $this->base_url . '/backend/public/quote.php?id=' . self::rowString($quote, 'id', '0');
        $business_name = self::settingString('site_name', "Brook's Dog Training Academy");
        $business_email = self::settingString('business_email', 'bookings@brooksdogtrainingacademy.com');

        $expiration_html = '';
        $expiration_text = '';
        if (!empty($quote['expiration_date'])) {
            $exp_formatted = date('F j, Y', safe_timestamp(strtotime(self::rowString($quote, 'expiration_date'))));
            $expiration_html = "<p><strong>Expiration Date:</strong> {$exp_formatted}</p>";
            $expiration_text = "Expiration Date: {$exp_formatted}\n";
        }

        // Build line-item HTML and text
        $items_html = '';
        $items_text = '';
        foreach ($items as $item) {
            $desc  = htmlspecialchars(self::rowString($item, 'description'));
            $qty   = safe_int($item['quantity'] ?? 1);
            $rate  = number_format(self::rowFloat($item, 'unit_price'), 2);
            $lamt  = number_format(self::rowFloat($item, 'amount'), 2);
            $items_html .= "<tr>"
                . "<td style='padding:6px 8px'>{$desc}</td>"
                . "<td style='text-align:center;padding:6px 8px'>{$qty}</td>"
                . "<td style='text-align:right;padding:6px 8px'>\${$rate}</td>"
                . "<td style='text-align:right;padding:6px 8px'>\${$lamt}</td>"
                . "</tr>";
            $items_text .= "  {$desc} — Qty: {$qty}  Unit Price: \${$rate}  Amount: \${$lamt}\n";
        }

        $items_section_html = '';
        if (!empty($items_html)) {
            $items_section_html = <<<HTML
<h3 style="margin:20px 0 10px">Quote Items</h3>
<table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse">
  <thead>
    <tr style="background:#f0f4f8">
      <th style="text-align:left;padding:6px 8px">Description</th>
      <th style="text-align:center;padding:6px 8px">Qty</th>
      <th style="text-align:right;padding:6px 8px">Unit Price</th>
      <th style="text-align:right;padding:6px 8px">Amount</th>
    </tr>
  </thead>
  <tbody>{$items_html}</tbody>
  <tfoot>
    <tr style="border-top:2px solid #dee2e6">
      <td colspan="3" style="text-align:right;padding:6px 8px"><strong>Total:</strong></td>
      <td style="text-align:right;padding:6px 8px"><strong>\${$quote_amount}</strong></td>
    </tr>
  </tfoot>
</table>
HTML;
        }

        $subject = "Quote {$quote_number} — {$business_name}";

        $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;line-height:1.6">
<div style="max-width:600px;margin:0 auto;padding:20px">
  <div style="background:#3b82f6;color:white;padding:20px;text-align:center;border-radius:8px 8px 0 0">
    <h1 style="margin:0">&#128203; Quote</h1>
  </div>
  <div style="background:#f8f9fa;padding:30px;border-radius:0 0 8px 8px">
    <p>Dear {$client_name},</p>
    <p>Please find your quote from {$business_name} below. We look forward to working with you!</p>
    <div style="background:white;padding:20px;margin:20px 0;border-radius:8px;border-left:4px solid #3b82f6">
      <h2 style="margin-top:0">{$quote_title}</h2>
      <p><strong>Quote Number:</strong> {$quote_number}</p>
      {$expiration_html}
      <p><strong>Total Amount:</strong> <span style="font-size:20px;font-weight:bold;color:#3b82f6">\${$quote_amount}</span></p>
    </div>
    {$items_section_html}
    <div style="text-align:center;margin:24px 0">
      <a href="{$quote_link}"
         style="display:inline-block;padding:14px 32px;background:#10b981;color:white;text-decoration:none;border-radius:6px;font-weight:bold;font-size:16px">
        &#128203; View &amp; Respond to Quote
      </a>
    </div>
    <p>If you have any questions about this quote, please contact us at
       <a href="mailto:{$business_email}">{$business_email}</a>.</p>
    <p>Thank you for choosing {$business_name}!</p>
  </div>
</div>
</body>
</html>
HTML;

        $items_section_text = !empty($items_text) ? "\nQUOTE ITEMS\n-----------\n{$items_text}" : '';

        $text_body = "QUOTE — {$business_name}\n\n"
            . "Dear {$client_name},\n\n"
            . "Please find your quote details below.\n\n"
            . "QUOTE DETAILS\n"
            . str_repeat('-', 30) . "\n"
            . "Quote Number: {$quote_number}\n"
            . "Title: {$quote_title}\n"
            . $expiration_text
            . "Total Amount: \${$quote_amount}\n"
            . $items_section_text . "\n\n"
            . "VIEW & RESPOND TO QUOTE\n"
            . str_repeat('-', 30) . "\n"
            . "{$quote_link}\n\n"
            . "Questions? Contact us at {$business_email}\n\n"
            . "Thank you for choosing {$business_name}!";

        return $this->routeMail(self::MAIL_TYPE_QUOTE, $to, $subject, $html_body, $text_body, [
            'client_id' => self::rowId($quote),
        ]);
    }

    /**
     * Send a generic email.
     *
     * Use this method when no more specific send-method exists.  Pass a MAIL_TYPE_*
     * constant as $mail_type so that the routing log captures the correct category.
     *
     * NOTE: $mail_type is intentionally the *last* (optional) parameter to preserve
     * backward compatibility with existing callers that pass only ($to, $subject,
     * $html_body, $text_body).  New callers should always supply $mail_type explicitly.
     * If you need $mail_type as the first argument, call routeMail() directly instead.
     *
     * @param string   $to        Recipient email address.
     * @param string   $subject   Email subject line.
     * @param string   $html_body HTML version of the message body.
     * @param string   $text_body Plain-text version (auto-derived from HTML when empty).
     * @param string   $mail_type One of the EmailService::MAIL_TYPE_* constants.
     *                            Defaults to MAIL_TYPE_GENERIC for backward compatibility.
     * @param int|null $client_id Client ID for logging to client email history (optional).
     * @return MailResult
     */
    public function sendGenericEmail(string $to, string $subject, string $html_body, string $text_body = '', string $mail_type = self::MAIL_TYPE_GENERIC, int|string|null $client_id = null): array {
        return $this->routeMail($mail_type, $to, $subject, $html_body, $text_body, [
            'client_id' => $client_id,
        ]);
    }

    /**
     * Build variable map for booking-related email templates.
     *
     * @param AssocRow $booking
     * @return array<string, mixed>
     */
    private function buildBookingVariables(array $booking, string $date, string $time, string $google_link, string $ical_link): array {
        $formatted_location = $this->formatLocationForEmail($booking);
        $booking_link       = $this->base_url . '/portal/appointments.php';
        return [
            'client_name'          => self::rowString($booking, 'client_name'),
            'client_email'         => self::rowString($booking, 'client_email'),
            'appointment_date'     => $date,
            'appointment_time'     => $time,
            'appointment_type'     => self::rowString($booking, 'service_type'),
            'duration'             => self::rowString($booking, 'duration_minutes'),
            'location'             => $formatted_location,
            'appointment_location' => $formatted_location,
            'booking_link'         => $booking_link,
            'google_calendar_link' => $google_link,
            'ical_link'            => $ical_link,
            'business_name'        => self::settingString('site_name', "Brook's Dog Training Academy"),
            'business_email'       => self::settingString('business_email', 'bookings@brooksdogtrainingacademy.com'),
            'business_phone'       => self::settingString('business_phone', ''),
        ];
    }

    /**
     * Send a composed email with optional CC and BCC recipients
     * @param string $to Primary recipient email address
     * @param list<string> $cc Array of CC email addresses
     * @param list<string> $bcc Array of BCC email addresses
     * @param string $subject Email subject
     * @param string $html_body HTML body content
     * @param string $text_body Plain text body content (optional)
     * @return MailResult Result array with 'success' and 'message' keys
     */
    public function sendComposeEmail(string $to, array $cc, array $bcc, string $subject, string $html_body, string $text_body = ''): array {
        if (empty($text_body)) {
            $text_body = strip_tags($html_body);
        }

        return $this->routeMail(self::MAIL_TYPE_COMPOSE, $to, $subject, $html_body, $text_body, ['cc' => $cc, 'bcc' => $bcc]);
    }
    
    /**
     * Get HTML email template
     *
     * @param AssocRow $booking
     */
    private function getConfirmationEmailHTML(array $booking, string $date, string $time, string $google_link, string $ical_link): string {
        $client_name = self::rowString($booking, 'client_name');
        $service_type = self::rowString($booking, 'service_type');
        $duration_minutes = self::rowString($booking, 'duration_minutes');
        $location = $this->formatLocationForEmail($booking);

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
            <p>Dear {$client_name},</p>
            
            <p>Your dog training appointment has been confirmed. We're excited to work with you and your furry friend!</p>
            
            <div class="booking-details">
                <h2>Appointment Details</h2>
                <p><strong>Service:</strong> {$service_type}</p>
                <p><strong>Date:</strong> {$date}</p>
                <p><strong>Time:</strong> {$time}</p>
                <p><strong>Duration:</strong> {$duration_minutes} minutes</p>
                <p><strong>Location:</strong> {$location}</p>
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
            <p>📧 Email: bookings@brooksdogtrainingacademy.com<br>
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
     *
     * @param AssocRow $booking
     */
    private function getConfirmationEmailText(array $booking, string $date, string $time, string $google_link, string $ical_link): string {
        $client_name = self::rowString($booking, 'client_name');
        $service_type = self::rowString($booking, 'service_type');
        $duration_minutes = self::rowString($booking, 'duration_minutes');
        $location = $this->formatLocationForEmail($booking);

        return <<<TEXT
BOOKING CONFIRMED - Brook's Dog Training Academy

Dear {$client_name},

Your dog training appointment has been confirmed. We're excited to work with you and your furry friend!

APPOINTMENT DETAILS
-------------------
Service: {$service_type}
Date: {$date}
Time: {$time}
Duration: {$duration_minutes} minutes
Location: {$location}

ADD TO YOUR CALENDAR
--------------------
Don't forget your appointment! Use these links to add it to your calendar:

Google Calendar: {$google_link}

Download iCal file: {$ical_link}
(Works with Apple Calendar, Outlook, and most calendar apps)

WHAT TO EXPECT
--------------
Please arrive 5 minutes early. If you need to reschedule or have any questions, please contact us at:

Email: bookings@brooksdogtrainingacademy.com
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
     * Get HTML email template for cancellation notification
     *
     * @param AssocRow $booking
     */
    private function getCancellationEmailHTML(array $booking, string $date, string $time, string $reason = ''): string {
        $client_name  = htmlspecialchars(self::rowString($booking, 'client_name'));
        $service_type = htmlspecialchars(self::rowString($booking, 'service_type'));
        $duration     = htmlspecialchars(self::rowString($booking, 'duration_minutes'));
        $location     = htmlspecialchars($this->formatLocationForEmail($booking));
        $reason_block = '';
        if (!empty($reason)) {
            $reason_html  = htmlspecialchars($reason);
            $reason_block = "<p><strong>Reason:</strong> {$reason_html}</p>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .booking-details { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #dc3545; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Appointment Cancelled</h1>
        </div>
        <div class="content">
            <p>Dear {$client_name},</p>

            <p>We're writing to let you know that your upcoming appointment has been cancelled.</p>

            <div class="booking-details">
                <h2>Cancelled Appointment Details</h2>
                <p><strong>Service:</strong> {$service_type}</p>
                <p><strong>Date:</strong> {$date}</p>
                <p><strong>Time:</strong> {$time}</p>
                <p><strong>Duration:</strong> {$duration} minutes</p>
                <p><strong>Location:</strong> {$location}</p>
                {$reason_block}
            </div>

            <p>If you have any questions or would like to reschedule, please contact us at:</p>
            <p>📧 Email: bookings@brooksdogtrainingacademy.com<br>
            🔗 Website: https://brooksdogtrainingacademy.com</p>

            <p>We apologise for any inconvenience this may cause and hope to see you soon.</p>

            <p>Best regards,<br>
            <strong>Brook Lefkowitz</strong><br>
            ABC Certified Dog Trainer<br>
            Brook's Dog Training Academy</p>
        </div>
        <div class="footer">
            <p>© 2024 Brook's Dog Training Academy | "Teaching Humans to Speak Dog"</p>
            <p>This is an automated cancellation notification. Please do not reply directly to this message.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get plain text email template for cancellation notification
     *
     * @param AssocRow $booking
     */
    private function getCancellationEmailText(array $booking, string $date, string $time, string $reason = ''): string {
        $client_name  = self::rowString($booking, 'client_name');
        $service_type = self::rowString($booking, 'service_type');
        $duration     = self::rowString($booking, 'duration_minutes');
        $location     = $this->formatLocationForEmail($booking);
        $reason_line  = !empty($reason) ? "Reason: {$reason}\n" : '';

        return <<<TEXT
APPOINTMENT CANCELLED - Brook's Dog Training Academy

Dear {$client_name},

We're writing to let you know that your upcoming appointment has been cancelled.

CANCELLED APPOINTMENT DETAILS
------------------------------
Service: {$service_type}
Date: {$date}
Time: {$time}
Duration: {$duration} minutes
Location: {$location}
{$reason_line}
If you have any questions or would like to reschedule, please contact us at:

Email: bookings@brooksdogtrainingacademy.com
Website: https://brooksdogtrainingacademy.com

We apologise for any inconvenience this may cause and hope to see you soon.

Best regards,
Brook Lefkowitz
ABC Certified Dog Trainer
Brook's Dog Training Academy

---
© 2024 Brook's Dog Training Academy | "Teaching Humans to Speak Dog"
This is an automated cancellation notification.
TEXT;
    }

    /**
     * Format the location for display in emails based on location_type.
     * Public to allow external callers (e.g. cron tasks) to reuse the same logic.
     *
     * @param AssocRow $booking
     */
    public function formatLocationForEmail(array $booking): string {
        $type = self::rowString($booking, 'location_type');
        $value = self::rowString($booking, 'location');

        switch ($type) {
            case 'client_address':
            case 'custom_address':
            case 'fixed':
                return $value ?: 'TBD';
            case 'phone_inbound':
                return 'Phone call — you will call us';
            case 'phone_outbound':
                return 'Phone call — we will call you';
            case 'webcall':
                return $value ? "Video call: {$value}" : 'Video call (link to follow)';
            default:
                return $value ?: 'TBD';
        }
    }

    /**
     * Add email signature to message body
     * @param string $body Message body
     * @param bool $is_html Whether the body is HTML
     * @return string Body with signature appended
     */
    private function addSignature(string $body, bool $is_html = true): string {
        require_once __DIR__ . '/email_signature_helper.php';
        
        // Get default signature
        $signature_html = EmailSignatureHelper::render();
        
        if (!$signature_html) {
            return $body; // No signature to add
        }
        
        if ($is_html) {
            // For HTML emails, append signature with a separator
            $separator = '<hr style="border: none; border-top: 1px solid #dee2e6; margin: 30px 0;">';
            return $body . $separator . $signature_html;
        } else {
            // For plain text emails, strip HTML from signature and append
            $signature_text = strip_tags($signature_html);
            $separator = "\n\n---\n\n";
            return $body . $separator . $signature_text;
        }
    }
    
    /**
     * Send email using PHPMailer with SMTP support
     *
     * @param list<string> $cc
     * @param list<string> $bcc
     * @return MailResult
     */
    private function sendEmail(string $to, string $subject, string $html_body, string $text_body, array $cc = [], array $bcc = []): array {
        try {
            // Add email signature if enabled
            $enable_signatures = Settings::get('enable_email_signatures', true);
            if ($enable_signatures) {
                $html_body = $this->addSignature($html_body);
                $text_body = $this->addSignature($text_body, false);
            }
            
            $mail = new PHPMailer(true);
            
            // Enable debug mode if configured (useful for troubleshooting)
            $debug_mode = Settings::get('smtp_debug', false);
            if ($debug_mode) {
                $mail->SMTPDebug = 2; // Show detailed debug output
                $mail->Debugoutput = function($str, $level) {
                    error_log("PHPMailer Debug: $str");
                };
            }
            
            // Get email configuration from settings
            $email_service = Settings::get('email_service', 'mail');
            
            if ($email_service === 'smtp') {
                // Get SMTP configuration
                $smtp_host = self::settingString('smtp_host', '');
                $smtp_username = self::settingString('smtp_username', '');
                $smtp_password = self::settingString('smtp_password', '');
                $smtp_port = safe_int(Settings::get('smtp_port', 587));
                $smtp_encryption = self::settingString('smtp_encryption', 'tls'); // 'tls', 'ssl', or 'none'
                
                // Validate SMTP configuration
                if (empty($smtp_host)) {
                    throw new Exception('SMTP host is not configured');
                }
                
                // Configure SMTP
                $mail->isSMTP();
                $mail->Host = $smtp_host;
                
                // Only enable authentication if credentials are provided
                if (!empty($smtp_username) && !empty($smtp_password)) {
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtp_username;
                    $mail->Password = $smtp_password;
                } else {
                    $mail->SMTPAuth = false;
                }
                
                // Set encryption type
                if ($smtp_encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    // SSL typically uses port 465
                    if ($smtp_port === 587) {
                        error_log("SMTP Warning: Using SSL encryption with port 587. Port 465 is typically used for SSL. Current port: $smtp_port");
                        $smtp_port = 465;
                    }
                } elseif ($smtp_encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                } else {
                    // No encryption
                    $mail->SMTPSecure = '';
                    $mail->SMTPAutoTLS = false;
                }
                
                $mail->Port = $smtp_port;
                
                // Set timeout to prevent hanging (in seconds)
                $mail->Timeout = 30;
                $mail->SMTPKeepAlive = false;
                
                // Some servers require this
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => true,
                        'verify_peer_name' => true,
                        'allow_self_signed' => false
                    )
                );
            } else {
                // Use PHP mail() function as fallback
                $mail->isMail();
            }
            
            // Set sender
            $mail->setFrom($this->from_email, $this->from_name);
            $mail->addReplyTo('bookings@brooksdogtrainingacademy.com', $this->from_name);
            
            // Set recipient
            $mail->addAddress($to);
            foreach ($cc as $cc_email) {
                if (!empty($cc_email)) {
                    $mail->addCC($cc_email);
                }
            }
            foreach ($bcc as $bcc_email) {
                if (!empty($bcc_email)) {
                    $mail->addBCC($bcc_email);
                }
            }
            
            // Set email format and content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html_body;
            $mail->AltBody = $text_body;
            
            // Set character encoding
            $mail->CharSet = 'UTF-8';
            
            // Send email
            $mail->send();
            
            return [
                'success' => true,
                'message' => 'Confirmation email sent successfully'
            ];
        } catch (Exception $e) {
            // Log detailed error information
            $error_message = "Email sending failed: " . $e->getMessage();
            if (!empty($mail->ErrorInfo)) {
                $error_message .= " | PHPMailer Error: " . $mail->ErrorInfo;
            }
            error_log($error_message);
            
            return [
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage()
            ];
        }
    }
}
