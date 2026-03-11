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
        if ($enrollment_time === false) {
            $enrollment_time = time();
        }
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
                return $enrollment_time;
            
            case 'after_enrollment':
                // Delay from enrollment time
                $delay_seconds = $this->parseDelayValue($step['delay_value'], $enrollment_time);
                if ($delay_seconds === null) {
                    return $enrollment_time;
                }
                return $enrollment_time + $delay_seconds;
            
            case 'after_previous':
                // Delay from previous step
                $base_time = $previous_step_time ?? $enrollment_time;
                $delay_seconds = $this->parseDelayValue($step['delay_value'], $base_time);
                if ($delay_seconds === null) {
                    return $base_time;
                }
                return $base_time + $delay_seconds;
            
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
     * Parse delay value into seconds (supports common shorthand)
     */
    private function parseDelayValue($delay_value, $reference_time = null) {
        if (empty($delay_value)) {
            return 0;
        }
        
        // Use provided reference time to anchor relative calculations (defaults for backward compatibility)
        $reference_time = $reference_time ?? time();
        
        // Parse format like "3 days", "2 hours", "30 minutes"
        if (preg_match('/(\d+)\s*(minute|hour|day|week)s?/i', $delay_value, $matches)) {
            $amount = intval($matches[1]);
            $unit = strtolower($matches[2]);
            
            switch ($unit) {
                case 'minute':
                    return $amount * 60;
                case 'hour':
                    return $amount * 60 * 60;
                case 'day':
                    return $amount * 60 * 60 * 24;
                case 'week':
                    return $amount * 60 * 60 * 24 * 7;
            }
        }
        
        // Shorthand units (e.g., 2h, 30m, 1d, 1w)
        if (preg_match('/^\s*(\d+)\s*(h|hr|hrs|m|min|mins|d|day|days|w|week|weeks)\s*$/i', $delay_value, $m)) {
            $unit = strtolower($m[2]);
            $unit_map = [
                'h' => 3600, 'hr' => 3600, 'hrs' => 3600,
                'm' => 60, 'min' => 60, 'mins' => 60,
                'd' => 86400, 'day' => 86400, 'days' => 86400,
                'w' => 604800, 'week' => 604800, 'weeks' => 604800,
            ];
            if (!isset($unit_map[$unit])) {
                error_log("workflow_helper: unrecognized delay unit '{$unit}' in '{$delay_value}'");
                return null;
            }
            return intval($m[1]) * $unit_map[$unit];
        }
        
        // If just a number, assume minutes
        if (is_numeric($delay_value)) {
            return intval($delay_value) * 60;
        }
        
        // Fallback to strtotime for natural language (e.g., "tomorrow", "next week")
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\s-]*$/', $delay_value)) {
            $expression = trim($delay_value);
            if ($expression === '') {
                return 0;
            }
            $probe = strtotime($expression, $reference_time);
            if ($probe !== false) {
                if ($probe < $reference_time) {
                    $probe_readable = date('c', $probe);
                    $reference_readable = date('c', $reference_time);
                    $delay_safe = json_encode($delay_value);
                    error_log("workflow_helper: delay_value {$delay_safe} resolved to past time ({$probe_readable}) relative to {$reference_readable}; skipping scheduling.");
                    return null;
                }
                return $probe - $reference_time;
            }
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
