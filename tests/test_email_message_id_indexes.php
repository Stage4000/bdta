#!/usr/bin/env php
<?php
/**
 * Verify MySQL message_id index SQL stays within safe utf8mb4 prefix lengths.
 */

require_once dirname(__DIR__) . '/backend/includes/database.php';

echo "=== Email message_id index SQL test ===\n\n";

$reflection = new ReflectionClass(Database::class);
$client_index_sql = $reflection->getReflectionConstant('MYSQL_CLIENT_EMAILS_MESSAGE_ID_INDEX_SQL');
$unmatched_index_sql = $reflection->getReflectionConstant('MYSQL_UNMATCHED_EMAILS_MESSAGE_ID_INDEX_SQL');

if (!$client_index_sql instanceof ReflectionClassConstant || !$unmatched_index_sql instanceof ReflectionClassConstant) {
    throw new RuntimeException('Unable to inspect MySQL message_id index SQL constants.');
}

$client_index_value = $client_index_sql->getValue();
$unmatched_index_value = $unmatched_index_sql->getValue();

if (!is_string($client_index_value) || $client_index_value !== 'CREATE INDEX idx_client_emails_message_id ON client_emails(direction(16), message_id(170))') {
    throw new RuntimeException('client_emails message_id index SQL should use safe prefixes for direction and message_id.');
}

if (!is_string($unmatched_index_value) || $unmatched_index_value !== 'CREATE INDEX idx_unmatched_emails_message_id ON unmatched_emails(message_id(170))') {
    throw new RuntimeException('unmatched_emails message_id index SQL should use a safe utf8mb4 prefix length for message_id.');
}

echo "✓ client_emails message_id index uses safe composite prefixes\n";
echo "✓ unmatched_emails message_id index uses a safe utf8mb4 prefix length\n";
echo "\nAll email message_id index SQL tests passed!\n";
