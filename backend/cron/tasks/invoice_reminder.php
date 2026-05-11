<?php
/**
 * Invoice Reminder Task
 * Sends reminder emails for overdue invoices
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';
require_once dirname(dirname(__DIR__)) . '/includes/invoice_status.php';

/**
 * @phpstan-type InvoiceRow array<string, mixed>
 * @phpstan-type MailResult array{success: bool, message: string}
 * @phpstan-type TaskResult array{success: bool, items_processed: int, message: string, errors: list<string>}
 */
class InvoiceReminderTask {
    private PDO $conn;
    
    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }
    
    /**
     * @return TaskResult
     */
    public function execute(): array {
        // Get overdue invoices
        // Send reminders for invoices overdue by 1+ days
        $current_date = date('Y-m-d');
        $reminder_threshold = date('Y-m-d H:i:s', strtotime('-1 day'));
        
        $stmt = $this->conn->prepare("
            SELECT i.*, c.email as client_email, c.name as client_name
            FROM invoices i
            LEFT JOIN clients c ON i.client_id = c.id
            WHERE i.status IN ('sent', 'partial')
            AND i.due_date < ?
            AND (i.last_reminder_sent IS NULL OR i.last_reminder_sent < ?)
            ORDER BY i.due_date
        ");
        
        $stmt->execute([$current_date, $reminder_threshold]);
        $invoices = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
        
        $sent_count = 0;
        $errors = [];
        
        foreach ($invoices as $invoice) {
            try {
                $invoice_id = scalar_string($invoice['id'] ?? '');
                $invoice_number = array_string_value($invoice, 'invoice_number');
                $client_email = array_string_value($invoice, 'client_email');
                if ($client_email === '') {
                    $errors[] = "No email found for invoice #{$invoice_number}";
                    continue;
                }
                
                // Send reminder email
                $result = $this->sendInvoiceReminder($invoice);
                
                if ($result['success']) {
                    // Mark reminder as sent
                    $update = $this->conn->prepare("UPDATE invoices SET last_reminder_sent = ? WHERE id = ?");
                    $update->execute([date('Y-m-d H:i:s'), $invoice_id]);
                    $sent_count++;
                } else {
                    $errors[] = "Failed to send to {$client_email}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error processing invoice #{$invoice_number}: " . $e->getMessage();
            }
        }
        
        // Prepare result message
        $message = "Sent {$sent_count} invoice reminder(s)";
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
     * Send invoice reminder email
     */
    /**
     * @param InvoiceRow $invoice
     * @return MailResult
     */
    private function sendInvoiceReminder(array $invoice): array {
        $email_service = new EmailService(null, $this->conn);
        
        $client_name = htmlspecialchars(scalar_string($invoice['client_name'] ?? ''));
        $invoice_number = htmlspecialchars(scalar_string($invoice['invoice_number'] ?? ''));
        $total_amount_value = safe_float($invoice['total_amount'] ?? 0);
        $total_amount = number_format($total_amount_value, 2);
        $due_date_string = scalar_string($invoice['due_date'] ?? '');
        $invoice_id = safe_int($invoice['id'] ?? 0);
        $client_email = scalar_string($invoice['client_email'] ?? '');
        $client_id = $invoice['client_id'] ?? null;
        $status = scalar_string($invoice['status'] ?? '');
        
        // Calculate how many days overdue
        $due_date = strtotime($due_date_string);
        $now = time();
        $days_overdue = (int) ceil(($now - safe_timestamp($due_date)) / 86400);
        
        // Reminders should keep the client on the same guest invoice flow as the
        // original invoice email, even for older rows that are missing pay_token.
        $invoice_link = bdta_get_public_invoice_pay_url(
            $this->conn,
            $invoice_id,
            $invoice['pay_token'] ?? null,
            getDynamicBaseUrl()
        );
        
        // Calculate amount due (for partial payments)
        $amount_due = $total_amount;
        if ($status === 'partial') {
            $payment_summary = bdta_invoice_get_payment_summary($this->conn, $invoice);
            $amount_due = number_format(safe_float($payment_summary['remaining_amount']), 2);
        }
        
        $subject = "Payment Reminder: Invoice {$invoice_number} is Overdue";
        
        $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc2626; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .invoice-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #dc2626; }
        .amount { font-size: 24px; font-weight: bold; color: #dc2626; }
        .button { display: inline-block; padding: 12px 24px; margin: 20px 0; background: #10b981; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .overdue-warning { background: #fee2e2; border-left: 4px solid #dc2626; padding: 15px; margin: 20px 0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Payment Overdue</h1>
        </div>
        <div class="content">
            <p>Dear {$client_name},</p>
            
            <div class="overdue-warning">
                <p style="margin: 0; font-weight: bold;">This invoice is {$days_overdue} day(s) overdue.</p>
            </div>
            
            <div class="invoice-box">
                <h2>Invoice {$invoice_number}</h2>
                <p><strong>Due Date:</strong> {$due_date_string}</p>
                <p class="amount">Amount Due: \${$amount_due}</p>
            </div>
            
            <p>Please arrange payment at your earliest convenience to avoid any service interruptions.</p>
            
            <div style="text-align: center;">
                <a href="{$invoice_link}" class="button">View Invoice & Pay Now</a>
            </div>
            
            <p>If you've already sent payment or have any questions, please contact us immediately.</p>
            
            <p>Best regards,<br>
            <strong>Brook Lefkowitz</strong><br>
            Brook's Dog Training Academy</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $text_body = "PAYMENT REMINDER - Brook's Dog Training Academy\n\n";
        $text_body .= "Dear {$client_name},\n\n";
        $text_body .= "*** This invoice is {$days_overdue} day(s) overdue. ***\n\n";
        $text_body .= "Invoice {$invoice_number}\n";
        $text_body .= "Due Date: {$due_date_string}\n";
        $text_body .= "Amount Due: \${$amount_due}\n\n";
        $text_body .= "Please arrange payment at your earliest convenience to avoid any service interruptions.\n\n";
        $text_body .= "View Invoice & Pay: {$invoice_link}\n\n";
        $text_body .= "If you've already sent payment or have any questions, please contact us immediately.\n\n";
        $text_body .= "Best regards,\nBrook Lefkowitz\nBrook's Dog Training Academy";
        
        return $email_service->sendGenericEmail($client_email, $subject, $html_body, $text_body, EmailService::MAIL_TYPE_INVOICE_REMINDER, is_int($client_id) || is_string($client_id) ? $client_id : null);
    }
}
?>
