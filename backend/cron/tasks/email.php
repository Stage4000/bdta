<?php
/**
 * Email Task Handler (Legacy/Generic)
 * 
 * This is a compatibility handler for tasks with the generic 'email' task_type.
 * It delegates to ScheduledEmailSenderTask for backward compatibility.
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
        // Delegate to ScheduledEmailSenderTask
        $sender = new ScheduledEmailSenderTask($this->conn, $this->task);
        return $sender->execute();
    }
}
