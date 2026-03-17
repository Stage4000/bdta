<?php
/**
 * Delete malformed unmatched email rows that are missing their received timestamp.
 */

require_once dirname(dirname(__DIR__)) . '/includes/config.php';

class UnmatchedEmailCleanerTask {
    public function __construct() {
    }

    /**
     * @return array{success: bool, message: string, items_processed: int, errors: list<string>}
     */
    public function execute(): array {
        try {
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("
                DELETE FROM unmatched_emails
                WHERE received_at IS NULL OR TRIM(received_at) = ''
            ");
            $stmt->execute();
            $deleted = (int) $stmt->rowCount();

            return [
                'success' => true,
                'message' => $deleted > 0
                    ? "Deleted {$deleted} unmatched email(s) without timestamps."
                    : 'No unmatched emails without timestamps were found.',
                'items_processed' => $deleted,
                'errors' => []
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage(),
                'items_processed' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
    }
}
