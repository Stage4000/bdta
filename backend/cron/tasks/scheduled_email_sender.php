<?php
/**
 * Scheduled Email Sender Task
 * Sends scheduled emails to clients when their scheduled time has arrived
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';

class ScheduledEmailSenderTask {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function execute() {
        // Get emails that are scheduled to be sent now or in the past
        $now = date('Y-m-d H:i:s');
        
        $stmt = $this->conn->prepare("
            SELECT ce.*, c.email as client_email, c.name as client_name
            FROM client_emails ce
            JOIN clients c ON ce.client_id = c.id
            WHERE ce.status = 'scheduled'
            AND ce.scheduled_at <= ?
            ORDER BY ce.scheduled_at
        ");
        
        $stmt->execute([$now]);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent_count = 0;
        $failed_count = 0;
        $errors = [];
        
        foreach ($emails as $email) {
            try {
                // Send the email
                $emailService = new EmailService();
                $result = $emailService->sendGenericEmail(
                    $email['to_email'],
                    $email['subject'],
                    $email['body_html'],
                    $email['body_text'] ?? '',
                    EmailService::MAIL_TYPE_COMPOSE
                );
                
                if ($result['success']) {
                    // Update email status to sent
                    $update = $this->conn->prepare("
                        UPDATE client_emails 
                        SET status = 'sent', 
                            sent_at = CURRENT_TIMESTAMP,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $update->execute([$email['id']]);
                    $sent_count++;
                } else {
                    // Update email status to failed
                    $update = $this->conn->prepare("
                        UPDATE client_emails 
                        SET status = 'failed', 
                            failed_at = CURRENT_TIMESTAMP,
                            error_message = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    $update->execute([$result['message'], $email['id']]);
                    $failed_count++;
                    $errors[] = "Failed to send email #{$email['id']} to {$email['to_email']}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                // Update email status to failed
                $error_message = $e->getMessage();
                $update = $this->conn->prepare("
                    UPDATE client_emails 
                    SET status = 'failed', 
                        failed_at = CURRENT_TIMESTAMP,
                        error_message = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $update->execute([$error_message, $email['id']]);
                $failed_count++;
                $errors[] = "Error sending email #{$email['id']}: " . $error_message;
            }
        }
        
        // Prepare result message
        $message = "Processed " . count($emails) . " scheduled email(s): {$sent_count} sent, {$failed_count} failed";
        
        return [
            'success' => true,
            'message' => $message,
            'items_processed' => count($emails),
            'errors' => $errors
        ];
    }
}
