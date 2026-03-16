# Mail Routing — Developer Guide

This document describes the **central mail routing function** in the BDTA application
and explains how to use and extend the email system.

---

## Architecture Overview

All outgoing email flows through a single entry point:

```
EmailService::routeMail()
        │
        ├─ logs to backend/logs/mailrouter.log  "[MailRouter] ROUTING type=… to=… subject=…"
        │
        ├─ calls  EmailService::sendEmail()  (PHPMailer transport)
        │
        └─ logs to backend/logs/mailrouter.log  "[MailRouter] SENT …"  or  "[MailRouter] FAILED …"
```

Every public send-method in `EmailService` (`sendBookingConfirmation`,
`sendPaymentReceipt`, `sendInvoiceEmail`, `sendGenericEmail`, `sendComposeEmail`)
delegates to `routeMail()` internally, so no email bypasses the central log and
transport layer.

---

## Mail Type Constants

Each email is labelled with one of the following `MAIL_TYPE_*` constants defined in
`EmailService`. The type appears in every log line and can be used in the future for
per-type rate-limiting, metrics, or provider routing.

| Constant | Value | Used for |
|---|---|---|
| `MAIL_TYPE_BOOKING_CONFIRMATION` | `booking_confirmation` | Confirmation sent right after a booking is created |
| `MAIL_TYPE_BOOKING_REMINDER`     | `booking_reminder`     | Reminder sent ahead of an upcoming appointment |
| `MAIL_TYPE_PAYMENT_RECEIPT`      | `payment_receipt`      | Receipt after a full or partial invoice payment |
| `MAIL_TYPE_INVOICE`              | `invoice`              | Invoice sent to the client requesting payment |
| `MAIL_TYPE_INVOICE_REMINDER`     | `invoice_reminder`     | Overdue-invoice reminder |
| `MAIL_TYPE_CONTRACT_REMINDER`    | `contract_reminder`    | Unsigned-contract reminder |
| `MAIL_TYPE_QUOTE_REMINDER`       | `quote_reminder`       | Unanswered-quote reminder |
| `MAIL_TYPE_FORM_REMINDER`        | `form_reminder`        | Incomplete-form reminder |
| `MAIL_TYPE_WORKFLOW`             | `workflow`             | Automated email from a workflow step |
| `MAIL_TYPE_COMPOSE`              | `compose`              | Manually composed or scheduled client email |
| `MAIL_TYPE_PASSWORD_RESET`       | `password_reset`       | Password-reset link for admin or portal users |
| `MAIL_TYPE_GENERIC`              | `generic`              | General-purpose fallback |

---

## How to Send an Email

### Option 1 — Use a typed helper (preferred for transactional mail)

```php
$emailService = new EmailService(null, $conn);

// Booking confirmation
$result = $emailService->sendBookingConfirmation($booking);

// Invoice
$result = $emailService->sendInvoiceEmail($invoice, $items);

// Payment receipt
$result = $emailService->sendPaymentReceipt($invoice, null, $items);
```

### Option 2 — `sendGenericEmail()` with a mail-type label

Use this when you build your own HTML/text content and none of the typed helpers apply.
Always pass the most specific `MAIL_TYPE_*` constant available.

```php
$emailService = new EmailService();

$result = $emailService->sendGenericEmail(
    'client@example.com',                     // recipient
    'Subject line',                            // subject
    $html_body,                                // HTML body
    $text_body,                                // plain-text body (optional)
    EmailService::MAIL_TYPE_CONTRACT_REMINDER  // mail type
);

if (!$result['success']) {
    error_log('Email failed: ' . $result['message']);
}
```

### Option 3 — `routeMail()` directly (advanced / special delivery options)

Use `routeMail()` when you need CC/BCC recipients or want to attach extra context to
the log entry.

```php
$result = $emailService->routeMail(
    EmailService::MAIL_TYPE_INVOICE_REMINDER,
    'client@example.com',
    'Your invoice is overdue',
    $html_body,
    $text_body,
    [
        'cc'      => ['admin@example.com'],
        'bcc'     => ['audit@example.com'],
        'context' => ['invoice_id' => 42, 'days_overdue' => 7],
    ]
);
```

---

## How to Add a New Mail Type

1. **Define the constant** in `EmailService` (top of the class, after the other
   `MAIL_TYPE_*` constants):

   ```php
   /** Brief description of when this type is used. */
   const MAIL_TYPE_MY_NEW_TYPE = 'my_new_type';
   ```

2. **Add it to the table** in this document.

3. **Use it** in your send call — either via `sendGenericEmail()` or `routeMail()`.

No other changes are needed; the routing function handles the new type automatically.

---

## Log Format

Every email attempt is written to `backend/logs/mailrouter.log` in the following
format:

```
[MailRouter] ROUTING type=booking_confirmation to=client@example.com subject="Booking Confirmed"
[MailRouter] SENT    type=booking_confirmation to=client@example.com
```

On failure:

```
[MailRouter] ROUTING type=invoice_reminder to=client@example.com subject="Payment Overdue"
[MailRouter] FAILED  type=invoice_reminder to=client@example.com reason="SMTP connect() failed"
```

When extra context is provided via `$options['context']`, it is appended as JSON:

```
[MailRouter] ROUTING type=invoice_reminder to=client@example.com subject="Payment Overdue" context={"invoice_id":42}
```

---

## Transport Configuration

Transport settings (SMTP vs PHP `mail()`) are managed through **Admin Panel →
Settings → Email**.  See [`EMAIL_CONFIGURATION.md`](EMAIL_CONFIGURATION.md) for
setup instructions.

`routeMail()` always calls `sendEmail()` (the internal PHPMailer wrapper), so
changing the transport setting affects every mail type simultaneously — no per-type
transport changes are required.

MailRouter routing audit lines are kept out of the PHP/Apache error log unless the
application cannot write to `backend/logs/mailrouter.log`, in which case the logger
falls back to `error_log()` with a `mailrouter_log_error` suffix so the write issue
is still visible.

---

## Error Handling

- `routeMail()` / `sendGenericEmail()` never throw; they always return an array:
  ```php
  ['success' => true,  'message' => 'Confirmation email sent successfully']
  ['success' => false, 'message' => 'SMTP connect() failed. …']
  ```
- Callers should check `$result['success']` and log failures.
- PHPMailer exceptions are caught inside `sendEmail()` and converted to the error
  array format above.

---

## Extending the Routing Function

If you need to add cross-cutting behaviour (e.g. rate-limiting, provider failover,
database audit log, async queuing), modify `EmailService::routeMail()` in
`backend/includes/email_service.php`.  All existing callers will benefit immediately
without requiring changes elsewhere.

Example — write every send attempt to a `mail_log` database table:

```php
public function routeMail(string $mail_type, string $to, string $subject,
                           string $html_body, string $text_body = '',
                           array $options = []): array {
    // … existing code …
    $result = $this->sendEmail($to, $subject, $html_body, $text_body, $cc, $bcc);

    // NEW: persist to audit table
    if ($this->conn) {
        $stmt = $this->conn->prepare(
            "INSERT INTO mail_log (mail_type, recipient, subject, success, created_at)
             VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([$mail_type, $to, $subject, $result['success'] ? 1 : 0]);
    }

    return $result;
}
```

---

## File Map

| File | Role |
|---|---|
| `backend/includes/email_service.php` | `EmailService` class — routing, transport, templates |
| `backend/includes/email_signature_helper.php` | Appends configured email signature |
| `backend/includes/settings.php` | Reads SMTP/email settings from the database |
| `backend/includes/phpmailer/` | PHPMailer library (do not modify) |
| `backend/cron/tasks/booking_reminder.php` | Sends `MAIL_TYPE_BOOKING_REMINDER` emails |
| `backend/cron/tasks/invoice_reminder.php` | Sends `MAIL_TYPE_INVOICE_REMINDER` emails |
| `backend/cron/tasks/contract_reminder.php` | Sends `MAIL_TYPE_CONTRACT_REMINDER` emails |
| `backend/cron/tasks/quote_reminder.php` | Sends `MAIL_TYPE_QUOTE_REMINDER` emails |
| `backend/cron/tasks/form_reminder.php` | Sends `MAIL_TYPE_FORM_REMINDER` emails |
| `backend/cron/tasks/workflow_processor.php` | Sends `MAIL_TYPE_WORKFLOW` emails |
| `backend/cron/tasks/scheduled_email_sender.php` | Sends `MAIL_TYPE_COMPOSE` (scheduled) emails |
| `client/client_emails_api.php` | Sends `MAIL_TYPE_COMPOSE` emails via UI |
| `client/forgot_password.php` | Sends `MAIL_TYPE_PASSWORD_RESET` (admin area) |
| `portal/forgot_password.php` | Sends `MAIL_TYPE_PASSWORD_RESET` (client portal) |
| `backend/EMAIL_CONFIGURATION.md` | SMTP setup and troubleshooting guide |
| `backend/MAIL_ROUTING.md` | **This file** — routing developer guide |
