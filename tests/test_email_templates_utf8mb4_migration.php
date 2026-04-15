#!/usr/bin/env php
<?php
/**
 * Verify email_templates MySQL utf8mb4 conversion SQL stays present for emoji-safe template storage.
 */

require_once dirname(__DIR__) . '/backend/includes/database.php';

echo "=== email_templates utf8mb4 migration SQL test ===\n\n";

$reflection = new ReflectionClass(Database::class);
$migration_sql = $reflection->getReflectionConstant('MYSQL_EMAIL_TEMPLATES_UTF8MB4_SQL');

if (!$migration_sql instanceof ReflectionClassConstant) {
    throw new RuntimeException('Unable to inspect email_templates utf8mb4 migration SQL constant.');
}

$migration_value = $migration_sql->getValue();

if (!is_string($migration_value)) {
    throw new RuntimeException('email_templates utf8mb4 migration SQL constant should resolve to a string.');
}

if (!str_contains($migration_value, 'ALTER TABLE email_templates CONVERT TO CHARACTER SET utf8mb4')) {
    throw new RuntimeException('email_templates utf8mb4 migration SQL should convert the table to utf8mb4 for emoji-safe template storage.');
}

$database_source = file_get_contents(dirname(__DIR__) . '/backend/includes/database.php');
if (!is_string($database_source)) {
    throw new RuntimeException('Unable to inspect database migration source.');
}

if (preg_match('/tableCollation\(\'email_templates\'\)/', $database_source) !== 1) {
    throw new RuntimeException('email_templates migration should check the current table collation before applying the utf8mb4 conversion.');
}

if (preg_match('/exec\\(self::MYSQL_EMAIL_TEMPLATES_UTF8MB4_SQL\\)/', $database_source) !== 1) {
    throw new RuntimeException('email_templates migration should execute the utf8mb4 conversion SQL when needed.');
}

echo "✓ email_templates utf8mb4 migration SQL remains available\n";
echo "\nAll email_templates utf8mb4 migration SQL tests passed!\n";
