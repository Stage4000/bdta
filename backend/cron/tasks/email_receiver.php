<?php
/**
 * Email Receiver Task
 * Fetches incoming emails from IMAP server and stores them in the database
 */

require_once dirname(dirname(__DIR__)) . '/includes/imap_receiver.php';

class EmailReceiverTask {
    public function __construct() {
    }
    
    /**
     * @return array{success: bool, message: string, items_processed: int, errors: list<string>}
     */
    public function execute(): array {
        try {
            $receiver = new ImapEmailReceiver();
            $result = $receiver->fetchEmails();
            
            $message = $result['message'];
            if (!empty($result['errors'])) {
                $message .= ' with ' . count($result['errors']) . ' error(s)';
            }
            
            return [
                'success' => $result['success'],
                'message' => $message,
                'items_processed' => $result['items_processed'],
                'errors' => $result['errors']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'items_processed' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }
}
