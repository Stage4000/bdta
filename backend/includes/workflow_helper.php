<?php
/**
 * Workflow Helper Class
 * Provides methods for managing workflows and enrollments
 */

class WorkflowHelper {
    private SafePDO $conn;
    
    public function __construct(SafePDO $conn) {
        $this->conn = $conn;
    }
    
    /**
     * Enroll a client in a workflow
     *
     * @return array{success: bool, message?: string, enrollment_id?: string}
     */
    public function enrollClient(int|string $workflow_id, int|string $client_id, int|string|null $enrolled_by = null): array {
        // Check if client is already enrolled and active
        $stmt = $this->conn->prepare("
            SELECT id FROM workflow_enrollments 
            WHERE workflow_id = ? AND client_id = ? AND status = 'active'
        ");
        $stmt->execute([$workflow_id, $client_id]);
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Client is already enrolled in this workflow'];
        }
        
        // Create enrollment
        $stmt = $this->conn->prepare("
            INSERT INTO workflow_enrollments (workflow_id, client_id, enrolled_by, status)
            VALUES (?, ?, ?, 'active')
        ");
        $stmt->execute([$workflow_id, $client_id, $enrolled_by]);
        $enrollment_id = scalar_string($this->conn->lastInsertId());
        
        // Schedule all workflow steps
        $this->scheduleWorkflowSteps($enrollment_id);
        
        return ['success' => true, 'enrollment_id' => $enrollment_id];
    }
    
    /**
     * Schedule workflow steps for an enrollment
     */
    private function scheduleWorkflowSteps(int|string $enrollment_id): bool {
        // Get enrollment details
        $stmt = $this->conn->prepare("
            SELECT * FROM workflow_enrollments WHERE id = ?
        ");
        $stmt->execute([$enrollment_id]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$enrollment) {
            return false;
        }
        
        // Get workflow steps
        $stmt = $this->conn->prepare("
            SELECT * FROM workflow_steps 
            WHERE workflow_id = ? 
            ORDER BY step_order
        ");
        $workflow_id = array_string_value($enrollment, 'workflow_id');
        if ($workflow_id === '') {
            return false;
        }
        $stmt->execute([$workflow_id]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $enrollment_time = safe_timestamp(strtotime(scalar_string($enrollment['enrolled_at'] ?? '')));
        $previous_step_time = null;
        
        foreach ($steps as $step) {
            $scheduled_time = $this->calculateScheduledTime(
                $step, 
                $enrollment_time, 
                $previous_step_time
            );
            
            // Create step execution record
            $stmt = $this->conn->prepare("
                INSERT INTO workflow_step_executions (
                    enrollment_id, step_id, scheduled_for, status
                ) VALUES (?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $enrollment_id,
                array_int_value($step, 'id'),
                date('Y-m-d H:i:s', $scheduled_time)
            ]);
            
            $previous_step_time = $scheduled_time;
        }
        
        return true;
    }
    
    /**
     * Calculate when a step should be scheduled
     *
     * @param array<string, mixed> $step
     */
    private function calculateScheduledTime(array $step, int $enrollment_time, ?int $previous_step_time = null): int {
        $delay_type = scalar_string($step['delay_type'] ?? '');
        switch ($delay_type) {
            case 'immediate':
                return time();
            
            case 'after_enrollment':
                // Delay from enrollment time
                $delay_minutes = $this->parseDelayValue($this->getDelayValue($step));
                return $enrollment_time + ($delay_minutes * 60);
            
            case 'after_previous':
                // Delay from previous step
                $delay_minutes = $this->parseDelayValue($this->getDelayValue($step));
                $base_time = $previous_step_time ?? $enrollment_time;
                return $base_time + ($delay_minutes * 60);
            
            case 'specific_date':
                // Specific date and time
                $scheduled_date = array_string_value($step, 'scheduled_date');
                if ($scheduled_date !== '') {
                    return safe_timestamp(strtotime($scheduled_date));
                }
                return $enrollment_time;
            
            default:
                return $enrollment_time;
        }
    }
    
    /**
     * Parse delay value (e.g., "3 days", "2 hours", "30 minutes")
     */
    private function parseDelayValue(string|int|float|null $delay_value): int {
        if (empty($delay_value)) {
            return 0;
        }
        
        // Parse format like "3 days", "2 hours", "30 minutes"
        if (preg_match('/(\d+)\s*(minute|hour|day|week)s?/i', scalar_string($delay_value), $matches)) {
            $amount = intval($matches[1]);
            $unit = strtolower($matches[2]);
            
            switch ($unit) {
                case 'minute':
                    return $amount;
                case 'hour':
                    return $amount * 60;
                case 'day':
                    return $amount * 60 * 24;
                case 'week':
                    return $amount * 60 * 24 * 7;
            }
        }
        
        // If just a number, assume minutes
        if (is_numeric($delay_value)) {
            return intval($delay_value);
        }
        
        return 0;
    }

    /**
     * @param array<string, mixed> $step
     */
    private function getDelayValue(array $step): string|int|float|null {
        $delay_value = $step['delay_value'] ?? null;

        return is_string($delay_value) || is_int($delay_value) || is_float($delay_value)
            ? $delay_value
            : null;
    }
    
    /**
     * Cancel an enrollment
     */
    public function cancelEnrollment(int|string $enrollment_id): bool {
        // Update enrollment status
        $stmt = $this->conn->prepare("
            UPDATE workflow_enrollments 
            SET status = 'cancelled', cancelled_at = ?
            WHERE id = ?
        ");
        $stmt->execute([date('Y-m-d H:i:s'), $enrollment_id]);
        
        // Cancel pending step executions
        $stmt = $this->conn->prepare("
            UPDATE workflow_step_executions 
            SET status = 'cancelled'
            WHERE enrollment_id = ? AND status = 'pending'
        ");
        $stmt->execute([$enrollment_id]);
        
        return true;
    }
    
    /**
     * Check and trigger auto-enrollments for appointment bookings
     */
    public function checkAppointmentTriggers(int|string $booking_id): bool {
        // Get booking details
        $stmt = $this->conn->prepare("
            SELECT * FROM bookings WHERE id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$booking) {
            return false;
        }
        
        $client_id = array_string_value($booking, 'client_id');
        $appointment_type_id = array_string_value($booking, 'appointment_type_id');
        if ($client_id === '' || $appointment_type_id === '') {
            return false;
        }
        
        // Find workflows triggered by this appointment type
        $stmt = $this->conn->prepare("
            SELECT workflow_id FROM workflow_triggers 
            WHERE trigger_type = 'appointment_booking'
            AND appointment_type_id = ?
            AND is_active = 1
        ");
        $stmt->execute([$appointment_type_id]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($triggers as $trigger) {
            $workflow_id = array_string_value($trigger, 'workflow_id');
            if ($workflow_id !== '') {
                $this->enrollClient($workflow_id, $client_id);
            }
        }
        
        return true;
    }
    
    /**
     * Check and trigger auto-enrollments for form submissions
     */
    public function checkFormTriggers(int|string $form_submission_id): bool {
        // Get form submission details
        $stmt = $this->conn->prepare("
            SELECT * FROM form_submissions WHERE id = ?
        ");
        $stmt->execute([$form_submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$submission) {
            return false;
        }
        
        $client_id = array_string_value($submission, 'client_id');
        $template_id = array_string_value($submission, 'template_id');
        if ($client_id === '' || $template_id === '') {
            return false;
        }
        
        // Find workflows triggered by this form template
        $stmt = $this->conn->prepare("
            SELECT workflow_id FROM workflow_triggers 
            WHERE trigger_type = 'form_submission'
            AND form_template_id = ?
            AND is_active = 1
        ");
        $stmt->execute([$template_id]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($triggers as $trigger) {
            $workflow_id = array_string_value($trigger, 'workflow_id');
            if ($workflow_id !== '') {
                $this->enrollClient($workflow_id, $client_id);
            }
        }
        
        return true;
    }
}
?>
