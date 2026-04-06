#!/usr/bin/env php
<?php
/**
 * Verify client_emails MySQL utf8mb4 conversion SQL stays present for emoji-safe email history logging.
 */

require_once dirname(__DIR__) . '/backend/includes/database.php';

echo "=== client_emails utf8mb4 migration SQL test ===\n\n";

$reflection = new ReflectionClass(Database::class);
$migration_sql = $reflection->getReflectionConstant('MYSQL_CLIENT_EMAILS_UTF8MB4_SQL');

if (!$migration_sql instanceof ReflectionClassConstant) {
    throw new RuntimeException('Unable to inspect client_emails utf8mb4 migration SQL constant.');
}

$migration_value = $migration_sql->getValue();

if (!is_string($migration_value) || $migration_value !== 'ALTER TABLE client_emails CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci') {
    throw new RuntimeException('client_emails utf8mb4 migration SQL should convert the table to utf8mb4 for emoji-safe email logging.');
}

echo "✓ client_emails utf8mb4 migration SQL remains available\n";
echo "\nAll client_emails utf8mb4 migration SQL tests passed!\n";
