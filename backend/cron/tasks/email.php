<?php
/**
 * Email Task Handler (Legacy/Generic)
 * 
 * This handler supports the generic 'email' task_type for backward compatibility.
 * It intelligently delegates execution based on the task name:
 * - Tasks with "receive", "imap", or "fetch" in the name → EmailReceiverTask
 * - All other tasks → ScheduledEmailSenderTask
 * 
 * Note: New tasks should use specific task types:
 * - 'scheduled_email_sender' for sending scheduled emails
 * - 'email_receiver' for receiving emails via IMAP
 */

require_once __DIR__ . '/scheduled_email_sender.php';

class EmailTask {
    private $conn;
    private $task;
    
    public function __construct($conn, $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    public function execute() {
        // Determine which handler to use based on task name
        $task_name_lower = strtolower($this->task['task_name'] ?? '');
        
        // Check if this is an IMAP/receive emails task
        if (strpos($task_name_lower, 'receive') !== false || 
            strpos($task_name_lower, 'imap') !== false ||
            strpos($task_name_lower, 'fetch') !== false) {
            // Delegate to EmailReceiverTask
            require_once __DIR__ . '/email_receiver.php';
            $handler = new EmailReceiverTask($this->conn, $this->task);
            return $handler->execute();
        }
        
        // Default: delegate to ScheduledEmailSenderTask
        $sender = new ScheduledEmailSenderTask($this->conn, $this->task);
        return $sender->execute();
    }
}
