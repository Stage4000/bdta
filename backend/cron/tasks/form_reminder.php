<?php
/**
 * Form Reminder Task
 * Sends reminder emails to clients with incomplete forms
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';

class FormReminderTask {
    private $conn;
    private $task;
    
    public function __construct($conn, $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    public function execute() {
        // Get form requests that haven't been completed
        // Send reminders for forms sent more than 3 days ago
        $reminder_threshold = date('Y-m-d H:i:s', strtotime('-3 days'));
        
        $stmt = $this->conn->prepare("
            SELECT f.*, c.email as client_email, c.name as client_name, ft.name as form_name
            FROM form_submissions f
            LEFT JOIN clients c ON f.client_id = c.id
            LEFT JOIN form_templates ft ON f.form_template_id = ft.id
            WHERE f.status = 'pending'
            AND f.sent_at < ?
            AND (f.last_reminder_sent IS NULL OR f.last_reminder_sent < ?)
            ORDER BY f.sent_at
        ");
        
        $stmt->execute([$reminder_threshold, $reminder_threshold]);
        $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent_count = 0;
        $errors = [];
        
        foreach ($forms as $form) {
            try {
                if (empty($form['client_email'])) {
                    $errors[] = "No email found for form submission #{$form['id']}";
                    continue;
                }
                
                // Send reminder email
                $result = $this->sendFormReminder($form);
                
                if ($result['success']) {
                    // Mark reminder as sent
                    $update = $this->conn->prepare("UPDATE form_submissions SET last_reminder_sent = ? WHERE id = ?");
                    $update->execute([date('Y-m-d H:i:s'), $form['id']]);
                    $sent_count++;
                } else {
                    $errors[] = "Failed to send to {$form['client_email']}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                $errors[] = "Error processing form #{$form['id']}: " . $e->getMessage();
            }
        }
        
        // Prepare result message
        $message = "Sent {$sent_count} form reminder(s)";
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
     * Send form reminder email
     */
    private function sendFormReminder($form) {
        $email_service = new EmailService();
        
        $client_name = htmlspecialchars($form['client_name']);
        $form_name = htmlspecialchars($form['form_name'] ?: 'Client Form');
        $form_link = getDynamicBaseUrl() . '/backend/public/form.php?id=' . $form['id'];
        
        $subject = "Reminder: Please Complete Your Form";
        
        $html_body = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #8b5cf6; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 8px 8px; }
        .button { display: inline-block; padding: 12px 24px; margin: 20px 0; background: #2563eb; color: white !important; text-decoration: none; border-radius: 6px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📋 Form Completion Reminder</h1>
        </div>
        <div class="content">
            <p>Dear {$client_name},</p>
            
            <p>This is a friendly reminder to complete the <strong>{$form_name}</strong> that we sent you.</p>
            
            <p>Completing this form helps us provide you with the best possible service.</p>
            
            <div style="text-align: center;">
                <a href="{$form_link}" class="button">Complete Form Now</a>
            </div>
            
            <p>If you have any questions or need assistance, please let us know.</p>
            
            <p>Best regards,<br>
            <strong>Brook Lefkowitz</strong><br>
            Brook's Dog Training Academy</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        $text_body = <<<TEXT
FORM COMPLETION REMINDER - Brook's Dog Training Academy

Dear {$client_name},

This is a friendly reminder to complete the {$form_name} that we sent you.

Completing this form helps us provide you with the best possible service.

Complete Form: {$form_link}

If you have any questions or need assistance, please let us know.

Best regards,
Brook Lefkowitz
Brook's Dog Training Academy
TEXT;
        
        return $email_service->sendGenericEmail($form['client_email'], $subject, $html_body, $text_body);
    }
}
?>
