<?php
/**
 * Workflow Processor Task
 * Processes automated workflow step executions and sends emails with attachments
 */

require_once dirname(dirname(__DIR__)) . '/includes/email_service.php';
require_once dirname(dirname(__DIR__)) . '/includes/form_link_requests.php';

/**
 * @phpstan-type WorkflowExecutionRow array<string, mixed>
 * @phpstan-type MailResult array{success: bool, message: string}
 * @phpstan-type TaskResult array{success: bool, items_processed: int, message: string, errors: list<string>}
 */
class WorkflowProcessorTask {
    private PDO $conn;
    
    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }
    
    /**
     * @return TaskResult
     */
    public function execute(): array {
        $current_time = date('Y-m-d H:i:s');
        
        // Get pending workflow step executions that are due
        $stmt = $this->conn->prepare("
            SELECT 
                wse.id AS execution_id,
                wse.enrollment_id,
                wse.step_id,
                wse.scheduled_for,
                wse.status AS execution_status,
                wse.error_message,
                ws.step_order,
                ws.step_name,
                ws.email_subject,
                ws.email_body_html,
                ws.email_body_text,
                ws.delay_type,
                ws.delay_value,
                ws.scheduled_date,
                ws.attach_contract_id,
                ws.attach_form_id,
                ws.attach_quote_id,
                ws.attach_invoice_id,
                i.pay_token AS attach_invoice_pay_token,
                ws.include_appointment_link,
                ws.appointment_type_id,
                we.client_id, 
                w.name as workflow_name,
                c.email as client_email, 
                c.name as client_name
            FROM workflow_step_executions wse
            JOIN workflow_steps ws ON wse.step_id = ws.id
            JOIN workflow_enrollments we ON wse.enrollment_id = we.id
            JOIN workflows w ON ws.workflow_id = w.id
            JOIN clients c ON we.client_id = c.id
            LEFT JOIN invoices i ON ws.attach_invoice_id = i.id
            WHERE wse.status = 'pending'
            AND wse.scheduled_for <= ?
            AND we.status = 'active'
            AND w.is_active = 1
            ORDER BY wse.scheduled_for
        ");
        
        $stmt->execute([$current_time]);
        $executions = assoc_rows($stmt->fetchAll(PDO::FETCH_ASSOC));
        
        $sent_count = 0;
        $errors = [];
        
        foreach ($executions as $execution) {
            try {
                $execution_id = $execution['execution_id'] ?? null;
                $enrollment_id = $execution['enrollment_id'] ?? null;
                $client_email = array_string_value($execution, 'client_email');
                if (!is_int($execution_id) && !is_string($execution_id)) {
                    $errors[] = 'Workflow step missing id.';
                    continue;
                }
                if ($client_email === '') {
                    $this->markExecutionFailed($execution_id, "No email found for client");
                    $errors[] = "No email for workflow step #{$execution_id}";
                    continue;
                }
                
                // Send workflow email
                $result = $this->sendWorkflowEmail($execution);
                
                if ($result['success']) {
                    // Mark as executed
                    $this->markExecutionComplete($execution_id);
                    $sent_count++;
                    
                    // Check if this was the last step and mark enrollment as complete
                    if (is_int($enrollment_id) || is_string($enrollment_id)) {
                        $this->checkEnrollmentCompletion($enrollment_id);
                    }
                } else {
                    $this->markExecutionFailed($execution_id, $result['message']);
                    $errors[] = "Failed to send to {$client_email}: {$result['message']}";
                }
                
            } catch (Exception $e) {
                $error_msg = $e->getMessage();
                if (isset($execution_id) && (is_int($execution_id) || is_string($execution_id))) {
                    $this->markExecutionFailed($execution_id, $error_msg);
                    $errors[] = "Error processing workflow step #{$execution_id}: " . $error_msg;
                } else {
                    $errors[] = 'Error processing workflow step: ' . $error_msg;
                }
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
    /**
     * @param WorkflowExecutionRow $execution
     * @return MailResult
     */
    private function sendWorkflowEmail(array $execution): array {
        $email_service = new EmailService(null, $this->conn);
        
        $subject = $this->replacePlaceholders(scalar_string($execution['email_subject'] ?? ''), $execution);
        $html_body = $this->replacePlaceholders(scalar_string($execution['email_body_html'] ?? ''), $execution);
        $text_body_source = scalar_string($execution['email_body_text'] ?? '');
        $text_body = $text_body_source !== ''
            ? $this->replacePlaceholders($text_body_source, $execution)
            : strip_tags($html_body);
        
        // Add attachment links to email body
        $html_body = $this->addAttachmentLinks($html_body, $execution);
        $text_body = $this->addAttachmentLinks($text_body, $execution, false);
        
        $client_email = scalar_string($execution['client_email'] ?? '');
        $client_id = $execution['client_id'] ?? null;
        return $email_service->sendGenericEmail($client_email, $subject, $html_body, $text_body, EmailService::MAIL_TYPE_WORKFLOW, is_int($client_id) || is_string($client_id) ? $client_id : null);
    }
    
    /**
     * Replace placeholders in email content
     */
    /**
     * @param WorkflowExecutionRow $execution
     */
    private function replacePlaceholders(string $content, array $execution): string {
        $replacements = [
            '{client_name}' => htmlspecialchars(scalar_string($execution['client_name'] ?? '')),
            '{workflow_name}' => htmlspecialchars(scalar_string($execution['workflow_name'] ?? '')),
            '{step_name}' => htmlspecialchars(scalar_string($execution['step_name'] ?? '')),
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }
    
    /**
     * Add attachment links to email body
     */
    /**
     * @param WorkflowExecutionRow $execution
     */
    private function addAttachmentLinks(string $body, array $execution, bool $html = true): string {
        $base_url = getDynamicBaseUrl();
        $links = [];
        
        // Contract link - use template to generate link to contract template
        $attach_contract_id = $execution['attach_contract_id'] ?? null;
        $attach_form_id = $execution['attach_form_id'] ?? null;
        $attach_quote_id = $execution['attach_quote_id'] ?? null;
        $attach_invoice_id = $execution['attach_invoice_id'] ?? null;
        $client_id = scalar_string($execution['client_id'] ?? '');
        $appointment_type_id = $execution['appointment_type_id'] ?? null;
        $include_appointment_link = !empty($execution['include_appointment_link']);

        if (!empty($attach_contract_id)) {
            // Link to the contract template (admin can create contract from template for client)
            $link = $base_url . '/client/contracts_create.php?template_id=' . scalar_string($attach_contract_id) . '&client_id=' . $client_id;
            if ($html) {
                $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">📄 View Contract</a></p>';
            } else {
                $links[] = "\n\n📄 View Contract: " . $link;
            }
        }
        
        // Form link
        if (!empty($attach_form_id)) {
            $link = $base_url . '/backend/public/form.php?template_id=' . scalar_string($attach_form_id);
            $form_template_id = safe_int($attach_form_id);
            $form_client_id = safe_int($client_id);
            if ($form_template_id > 0 && $form_client_id > 0) {
                $request = bdta_create_form_request($this->conn, $form_template_id, $form_client_id);
                $link = array_string_value($request, 'url', $link);
            }
            if ($html) {
                $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #8b5cf6; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">📋 Complete Form</a></p>';
            } else {
                $links[] = "\n\n📋 Complete Form: " . $link;
            }
        }

        if (!empty($attach_quote_id)) {
            $link = $base_url . '/backend/public/quote.php?id=' . scalar_string($attach_quote_id);
            if ($html) {
                $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #2563eb; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">💬 View Quote</a></p>';
            } else {
                $links[] = "\n\n💬 View Quote: " . $link;
            }
        }

        if (!empty($attach_invoice_id)) {
            $invoice_pay_token = scalar_string($execution['attach_invoice_pay_token'] ?? '');
            $link = $invoice_pay_token !== ''
                ? $base_url . '/portal/invoice_pay.php?token=' . urlencode($invoice_pay_token)
                : $base_url . '/portal/invoice_view.php?id=' . scalar_string($attach_invoice_id);
            if ($html) {
                $links[] = '<p><a href="' . $link . '" style="display: inline-block; padding: 12px 24px; background: #16a34a; color: white; text-decoration: none; border-radius: 6px; font-weight: bold;">💳 View Invoice</a></p>';
            } else {
                $links[] = "\n\n💳 View Invoice: " . $link;
            }
        }
        
        // Appointment booking link
        if ($include_appointment_link && !empty($appointment_type_id)) {
            // Get appointment type unique link
            $stmt = $this->conn->prepare("SELECT unique_link FROM appointment_types WHERE id = ?");
            $stmt->execute([$appointment_type_id]);
            $apt_type = assoc_row($stmt->fetch(PDO::FETCH_ASSOC));
            
            if ($apt_type !== [] && !empty($apt_type['unique_link'])) {
                $link = $base_url . '/backend/public/book.php?link=' . scalar_string($apt_type['unique_link']);
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
    private function markExecutionComplete(int|string $execution_id): void {
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
    private function markExecutionFailed(int|string $execution_id, string $error_message): void {
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
    private function checkEnrollmentCompletion(int|string $enrollment_id): void {
        // Check if there are any pending or failed steps
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as pending_count
            FROM workflow_step_executions
            WHERE enrollment_id = ?
            AND status IN ('pending', 'failed')
        ");
        $stmt->execute([$enrollment_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (is_array($result) && safe_int($result['pending_count'] ?? 0) === 0) {
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
