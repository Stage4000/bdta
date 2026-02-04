<?php
/**
 * Contract Reminder Task
 * Sends reminder emails to clients with unsigned contracts
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';

class ContractReminderTask {
    private $conn;
    private $task;
    
    public function __construct($conn, $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    public function execute() {
        // Get contracts that have been sent but not signed
        // Send reminders for contracts sent more than 3 days ago
        $reminder_threshold = date('Y-m-d H:i:s', strtotime('-3 days'));
        
        $stmt = $this->conn->prepare("
            SELECT c.*, cl.email as client_email, cl.name as client_name
            FROM contracts c
            LEFT JOIN clients cl ON c.client_id = cl.id
            WHERE c.status = 'sent'
            AND c.sent_at < ?
            AND (c.last_reminder_sent IS NULL OR c.last_reminder_sent < ?)
            ORDER BY c.sent_at
        ");
        
        $stmt->execute([$reminder_threshold, $reminder_threshold]);
        $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent_count = 0;
        $errors = [];
        
        foreach ($contracts as $contract) {
            try {
                if (empty($contract['client_email'])) {
                    $errors[] = "No email found for contract #{$contract['id']}";
                    continue;
                }
                
                // Send reminder email
                $result = $this->sendContractReminder($contract);
                
                if ($result['success']) {
                    // Mark reminder as sent
                    $update = $this->conn->prepare("UPDATE contracts SET last_reminder_sent = ? WHERE id = ?");
                    $update->execute([date('Y-m-d H:i:s'), $contract['id']]);
                    $sent_count++;
                } else {
                    $errors[] = "Failed to send to {$contract['client_email']}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error processing contract #{$contract['id']}: " . $e->getMessage();
            }
        }
        
        // Prepare result message
        $message = "Sent {$sent_count} contract reminder(s)";
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
     * Send contract reminder email
     */
    private function sendContractReminder($contract) {
        $email_service = new EmailService();
        
        $client_name = htmlspecialchars($contract['client_name']);
        $contract_link = getDynamicBaseUrl() . '/backend/public/contract.php?id=' . $contract['id'];
        
        $subject = "Reminder: Contract Signature Required";
        
        $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #6366f1; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 24px; margin: 20px 0; background: #2563eb; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📝 Contract Signature Reminder</h1>
        </div>
        <div class="content">
            <p>Dear {$client_name},</p>
            
            <p>This is a friendly reminder that we're waiting for your signature on a contract.</p>
            
            <p>Please take a moment to review and sign the contract at your earliest convenience.</p>
            
            <div style="text-align: center;">
                <a href="{$contract_link}" class="button">View & Sign Contract</a>
            </div>
            
            <p>If you have any questions or concerns, please don't hesitate to reach out.</p>
            
            <p>Best regards,<br>
            <strong>Brook Lefkowitz</strong><br>
            Brook's Dog Training Academy</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $text_body = <<<TEXT
CONTRACT SIGNATURE REMINDER - Brook's Dog Training Academy

Dear {$client_name},

This is a friendly reminder that we're waiting for your signature on a contract.

Please take a moment to review and sign the contract at your earliest convenience.

View & Sign Contract: {$contract_link}

If you have any questions or concerns, please don't hesitate to reach out.

Best regards,
Brook Lefkowitz
Brook's Dog Training Academy
TEXT;
        
        return $email_service->sendGenericEmail($contract['client_email'], $subject, $html_body, $text_body);
    }
}
?>
