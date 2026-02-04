<?php
/**
 * Workflow Helper Class
 * Provides methods for managing workflows and enrollments
 */

class WorkflowHelper {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    /**
     * Enroll a client in a workflow
     */
    public function enrollClient($workflow_id, $client_id, $enrolled_by = null) {
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
        $enrollment_id = $this->conn->lastInsertId();
        
        // Schedule all workflow steps
        $this->scheduleWorkflowSteps($enrollment_id);
        
        return ['success' => true, 'enrollment_id' => $enrollment_id];
    }
    
    /**
     * Schedule workflow steps for an enrollment
     */
    private function scheduleWorkflowSteps($enrollment_id) {
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
        $stmt->execute([$enrollment['workflow_id']]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $enrollment_time = strtotime($enrollment['enrolled_at']);
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
                $step['id'],
                date('Y-m-d H:i:s', $scheduled_time)
            ]);
            
            $previous_step_time = $scheduled_time;
        }
        
        return true;
    }
    
    /**
     * Calculate when a step should be scheduled
     */
    private function calculateScheduledTime($step, $enrollment_time, $previous_step_time = null) {
        switch ($step['delay_type']) {
            case 'immediate':
                return time();
            
            case 'after_enrollment':
                // Delay from enrollment time
                $delay_minutes = $this->parseDelayValue($step['delay_value']);
                return $enrollment_time + ($delay_minutes * 60);
            
            case 'after_previous':
                // Delay from previous step
                $delay_minutes = $this->parseDelayValue($step['delay_value']);
                $base_time = $previous_step_time ?? $enrollment_time;
                return $base_time + ($delay_minutes * 60);
            
            case 'specific_date':
                // Specific date and time
                if ($step['scheduled_date']) {
                    return strtotime($step['scheduled_date']);
                }
                return $enrollment_time;
            
            default:
                return $enrollment_time;
        }
    }
    
    /**
     * Parse delay value (e.g., "3 days", "2 hours", "30 minutes")
     */
    private function parseDelayValue($delay_value) {
        if (empty($delay_value)) {
            return 0;
        }
        
        // Parse format like "3 days", "2 hours", "30 minutes"
        if (preg_match('/(\d+)\s*(minute|hour|day|week)s?/i', $delay_value, $matches)) {
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
     * Cancel an enrollment
     */
    public function cancelEnrollment($enrollment_id) {
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
    public function checkAppointmentTriggers($booking_id) {
        // Get booking details
        $stmt = $this->conn->prepare("
            SELECT * FROM bookings WHERE id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking || !$booking['client_id']) {
            return false;
        }
        
        // Find workflows triggered by this appointment type
        $stmt = $this->conn->prepare("
            SELECT workflow_id FROM workflow_triggers 
            WHERE trigger_type = 'appointment_booking'
            AND appointment_type_id = ?
            AND is_active = 1
        ");
        $stmt->execute([$booking['appointment_type_id']]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($triggers as $trigger) {
            $this->enrollClient($trigger['workflow_id'], $booking['client_id']);
        }
        
        return true;
    }
    
    /**
     * Check and trigger auto-enrollments for form submissions
     */
    public function checkFormTriggers($form_submission_id) {
        // Get form submission details
        $stmt = $this->conn->prepare("
            SELECT * FROM form_submissions WHERE id = ?
        ");
        $stmt->execute([$form_submission_id]);
        $submission = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$submission || !$submission['client_id']) {
            return false;
        }
        
        // Find workflows triggered by this form template
        $stmt = $this->conn->prepare("
            SELECT workflow_id FROM workflow_triggers 
            WHERE trigger_type = 'form_submission'
            AND form_template_id = ?
            AND is_active = 1
        ");
        $stmt->execute([$submission['template_id']]);
        $triggers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($triggers as $trigger) {
            $this->enrollClient($trigger['workflow_id'], $submission['client_id']);
        }
        
        return true;
    }
}
?>
