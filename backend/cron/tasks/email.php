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

/**
 * @phpstan-type TaskRow array<string, mixed>
 * @phpstan-type TaskResult array{success: bool, message: string, items_processed: int, errors?: list<string>}
 */
class EmailTask {
    private PDO $conn;
    /** @var TaskRow */
    private array $task;
    
    /**
     * @param TaskRow $task
     */
    public function __construct(PDO $conn, array $task) {
        $this->conn = $conn;
        $this->task = $task;
    }
    
    /**
     * @return TaskResult
     */
    public function execute(): array {
        // Determine which handler to use based on task name
        $task_name_lower = strtolower($this->task['task_name'] ?? '');
        
        // Check if this is an IMAP/receive emails task
        // Use word boundary matching to avoid false positives (e.g., "receipts" matching "receive")
        if (preg_match('/\b(receive|imap|fetch)\b/', $task_name_lower)) {
            // Delegate to EmailReceiverTask
            require_once __DIR__ . '/email_receiver.php';
            $handler = new EmailReceiverTask();
            return $handler->execute();
        }
        
        // Default: delegate to ScheduledEmailSenderTask
        $sender = new ScheduledEmailSenderTask($this->conn);
        return $sender->execute();
    }
}
