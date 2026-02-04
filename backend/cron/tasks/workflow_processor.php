<?php
/**
 * Workflow Processor Task
 * Processes automated workflow step executions and sends emails with attachments
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';

class WorkflowProcessorTask {
    private $conn;
    private $task;
    
    public function __construct($conn, $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    public function execute() {
        $current_time = date('Y-m-d H:i:s');
        
        // Get pending workflow step executions that are due
        $stmt = $this->conn->prepare("
            SELECT wse.*, ws.*, we.client_id, w.name as workflow_name,
                   c.email as client_email, c.name as client_name
            FROM workflow_step_executions wse
            JOIN workflow_steps ws ON wse.step_id = ws.id
            JOIN workflow_enrollments we ON wse.enrollment_id = we.id
            JOIN workflows w ON ws.workflow_id = w.id
            JOIN clients c ON we.client_id = c.id
            WHERE wse.status = 'pending'
            AND wse.scheduled_for <= ?
            AND we.status = 'active'
            AND w.is_active = 1
            ORDER BY wse.scheduled_for
        ");
        
        $stmt->execute([$current_time]);
        $executions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $sent_count = 0;
        $errors = [];
        
        foreach ($executions as $execution) {
            try {
                if (empty($execution['client_email'])) {
                    $this->markExecutionFailed($execution['id'], "No email found for client");
                    $errors[] = "No email for workflow step #{$execution['id']}";
                    continue;
                }
                
                // Send workflow email
                $result = $this->sendWorkflowEmail($execution);
                
                if ($result['success']) {
                    // Mark as executed
                    $this->markExecutionComplete($execution['id']);
                    $sent_count++;
                    
                    // Check if this was the last step and mark enrollment as complete
                    $this->checkEnrollmentCompletion($execution['enrollment_id']);
                } else {
                    $this->markExecutionFailed($execution['id'], $result['message']);
                    $errors[] = "Failed to send to {$execution['client_email']}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
                $this->markExecutionFailed($execution['id'], $error_msg);
                $errors[] = "Error processing workflow step #{$execution['id']}: " . $error_msg;
            }
        }
        
        // Prepare result message
        $message = "Sent {$sent_count} workflow email(s)";
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
     * Send workflow email with attachments
     */
    private function sendWorkflowEmail($execution) {
        $email_service = new EmailService();
        
        $client_name = htmlspecialchars($execution['client_name']);
        $subject = $this->replacePlaceholders($execution['email_subject'], $execution);
        $html_body = $this->replacePlaceholders($execution['email_body_html'], $execution);
        $text_body = $execution['email_body_text'] 
            ? $this->replacePlaceholders($execution['email_body_text'], $execution)
            : strip_tags($html_body);
        
        // Add attachment links to email body
        $html_body = $this->addAttachmentLinks($html_body, $execution);
        $text_body = $this->addAttachmentLinks($text_body, $execution, false);
        
        return $email_service->sendGenericEmail($execution['client_email'], $subject, $html_body, $text_body);
    }
    
    /**
     * Replace placeholders in email content
     */
    private function replacePlaceholders($content, $execution) {
        $replacements = [
            '{client_name}' => htmlspecialchars($execution['client_name']),
            '{workflow_name}' => htmlspecialchars($execution['workflow_name']),
            '{step_name}' => htmlspecialchars($execution['step_name']),
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    /**
     * Add attachment links to email body
     */
    private function addAttachmentLinks($body, $execution, $html = true) {
        $base_url = getDynamicBaseUrl();
        $links = [];
        
        // Contract link
        if ($execution['attach_contract_id']) {
            $stmt = $this->conn->prepare("
                SELECT id, contract_number, client_id 
                FROM contracts 
                WHERE client_id = ? AND id IN (
                    SELECT id FROM contract_templates WHERE id = ?
                )
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$execution['client_id'], $execution['attach_contract_id']]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($contract) {
                $link = $base_url . '/backend/public/contract.php?id=' . $contract['id'];
                if ($html) {
                    $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">📄 View Contract</a></p>';
                } else {
                    $links[] = "\n\n📄 View Contract: " . $link;
                }
            }
        }
        
        // Form link
        if ($execution['attach_form_id']) {
            $link = $base_url . '/backend/public/form.php?template_id=' . $execution['attach_form_id'] . '&client_id=' . $execution['client_id'];
            if ($html) {
                $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #8b5cf6; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">📋 Complete Form</a></p>';
            } else {
                $links[] = "\n\n📋 Complete Form: " . $link;
            }
        }
        
        // Quote link
        if ($execution['attach_quote_id']) {
            $link = $base_url . '/backend/public/quote.php?id=' . $execution['attach_quote_id'];
            if ($html) {
                $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #3b82f6; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">💰 View Quote</a></p>';
            } else {
                $links[] = "\n\n💰 View Quote: " . $link;
            }
        }
        
        // Invoice link
        if ($execution['attach_invoice_id']) {
            $link = $base_url . '/client/invoices_view.php?id=' . $execution['attach_invoice_id'];
            if ($html) {
                $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #10b981; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">💳 View Invoice</a></p>';
            } else {
                $links[] = "\n\n💳 View Invoice: " . $link;
            }
        }
        
        // Appointment booking link
        if ($execution['include_appointment_link'] && $execution['appointment_type_id']) {
            // Get appointment type unique link
            $stmt = $this->conn->prepare("SELECT unique_link FROM appointment_types WHERE id = ?");
            $stmt->execute([$execution['appointment_type_id']]);
            $apt_type = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($apt_type && $apt_type['unique_link']) {
                $link = $base_url . '/backend/public/book.php?type=' . $apt_type['unique_link'];
                if ($html) {
                    $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #f59e0b; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">📅 Book Appointment</a></p>';
                } else {
                    $links[] = "\n\n📅 Book Appointment: " . $link;
                }
            }
        }
        
        // Add links to body
        if (!empty($links)) {
            if ($html) {
                $separator = '<div style="margin: 30px 0; text-align: center;">' . implode('', $links) . '</div>';
                // Try to insert before closing body tag, or append
                if (stripos($body, '</body>') !== false) {
                    $body = str_ireplace('</body>', $separator . '</body>', $body);
                } else {
                    $body .= $separator;
                }
            } else {
                $body .= implode('', $links);
            }
        }
        
        return $body;
    }
    
    /**
     * Mark execution as complete
     */
    private function markExecutionComplete($execution_id) {
        $stmt = $this->conn->prepare("
            UPDATE workflow_step_executions 
            SET status = 'completed', executed_at = ?
            WHERE id = ?
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $execution_id]);
    }
    
    /**
     * Mark execution as failed
     */
    private function markExecutionFailed($execution_id, $error_message) {
        $stmt = $this->conn->prepare("
            UPDATE workflow_step_executions 
            SET status = 'failed', error_message = ?, executed_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$error_message, date('Y-m-d H:i:s'), $execution_id]);
    }
    
    /**
     * Check if all steps are complete and mark enrollment as complete
     */
    private function checkEnrollmentCompletion($enrollment_id) {
        // Check if there are any pending or failed steps
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as pending_count
            FROM workflow_step_executions
            WHERE enrollment_id = ?
            AND status IN ('pending', 'failed')
        ");
        $stmt->execute([$enrollment_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['pending_count'] == 0) {
            // All steps complete, mark enrollment as complete
            $update = $this->conn->prepare("
                UPDATE workflow_enrollments 
                SET status = 'completed', completed_at = ?
                WHERE id = ?
            ");
            $update->execute([date('Y-m-d H:i:s'), $enrollment_id]);
        }
    }
}
?>
