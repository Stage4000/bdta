<?php
/**
 * Quote Reminder Task
 * Sends reminder emails for quotes that have been sent but not approved for 3+ days
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';

class QuoteReminderTask {
    private $conn;
    private $task;
    
    public function __construct($conn, $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    public function execute() {
        // Get quotes that have been sent but not approved for 3+ days
        $reminder_threshold = date('Y-m-d H:i:s', strtotime('-3 days'));
        
        $stmt = $this->conn->prepare("
            SELECT q.*, c.email as client_email, c.name as client_name
            FROM quotes q
            LEFT JOIN clients c ON q.client_id = c.id
            WHERE q.status = 'sent'
            AND q.created_at < ?
            AND (q.last_reminder_sent IS NULL OR q.last_reminder_sent < ?)
            AND q.accepted_at IS NULL
            AND q.declined_at IS NULL
            AND (q.expiration_date IS NULL OR q.expiration_date > date('now'))
            ORDER BY q.created_at
        ");
        
        $stmt->execute([$reminder_threshold, $reminder_threshold]);
        $quotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent_count = 0;
        $errors = [];
        
        foreach ($quotes as $quote) {
            try {
                if (empty($quote['client_email'])) {
                    $errors[] = "No email found for quote #{$quote['id']}";
                    continue;
                }
                
                // Send reminder email
                $result = $this->sendQuoteReminder($quote);
                
                if ($result['success']) {
                    // Mark reminder as sent
                    $update = $this->conn->prepare("UPDATE quotes SET last_reminder_sent = ? WHERE id = ?");
                    $update->execute([date('Y-m-d H:i:s'), $quote['id']]);
                    $sent_count++;
                } else {
                    $errors[] = "Failed to send to {$quote['client_email']}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error processing quote #{$quote['id']}: " . $e->getMessage();
            }
        }
        
        // Prepare result message
        $message = "Sent {$sent_count} quote reminder(s)";
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
     * Send quote reminder email
     */
    private function sendQuoteReminder($quote) {
        $email_service = new EmailService();
        
        $client_name = htmlspecialchars($quote['client_name']);
        $quote_title = htmlspecialchars($quote['title']);
        $quote_amount = number_format($quote['amount'], 2);
        $quote_link = getDynamicBaseUrl() . '/backend/public/quote.php?id=' . $quote['id'];
        
        // Check if quote expires soon
        $expires_soon = false;
        $days_until_expiry = null;
        if ($quote['expiration_date']) {
            $expiry = strtotime($quote['expiration_date']);
            $now = time();
            $days_until_expiry = ceil(($expiry - $now) / 86400);
            $expires_soon = $days_until_expiry <= 7;
        }
        
        $subject = "Reminder: Quote Awaiting Your Review";
        
        $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #3b82f6; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .quote-box { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #3b82f6; }
        .amount { font-size: 24px; font-weight: bold; color: #3b82f6; }
        .button { display: inline-block; padding: 12px 24px; margin: 20px 0; background: #10b981; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .expiry-warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💰 Quote Reminder</h1>
        </div>
        <div class="content">
            <p>Dear {$client_name},</p>
            
            <p>This is a friendly reminder about the quote we sent you.</p>
            
            <div class="quote-box">
                <h2>{$quote_title}</h2>
                <p class="amount">\${$quote_amount}</p>
            </div>
HTML;

        if ($expires_soon && $days_until_expiry > 0) {
            $html_body .= <<<HTML
            
            <div class="expiry-warning">
                <p style="margin: 0; font-weight: bold;">⚠️ This quote expires in {$days_until_expiry} day(s)!</p>
            </div>
HTML;
        }

        $html_body .= <<<HTML
            
            <p>Please take a moment to review the quote and let us know if you'd like to proceed or if you have any questions.</p>
            
            <div style="text-align: center;">
                <a href="{$quote_link}" class="button">View Quote & Respond</a>
            </div>
            
            <p>If you have any questions or would like to discuss the quote, please don't hesitate to reach out.</p>
            
            <p>Best regards,<br>
            <strong>Brook Lefkowitz</strong><br>
            Brook's Dog Training Academy</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $text_body = "QUOTE REMINDER - Brook's Dog Training Academy\n\n";
        $text_body .= "Dear {$client_name},\n\n";
        $text_body .= "This is a friendly reminder about the quote we sent you.\n\n";
        $text_body .= "{$quote_title}\n";
        $text_body .= "Amount: \${$quote_amount}\n\n";
        
        if ($expires_soon && $days_until_expiry > 0) {
            $text_body .= "*** This quote expires in {$days_until_expiry} day(s)! ***\n\n";
        }
        
        $text_body .= "Please take a moment to review the quote and let us know if you'd like to proceed.\n\n";
        $text_body .= "View Quote: {$quote_link}\n\n";
        $text_body .= "If you have any questions, please don't hesitate to reach out.\n\n";
        $text_body .= "Best regards,\nBrook Lefkowitz\nBrook's Dog Training Academy";
        
        return $email_service->sendGenericEmail($quote['client_email'], $subject, $html_body, $text_body);
    }
}
?>
