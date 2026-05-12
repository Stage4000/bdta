#!/usr/bin/env php
<?php
/**
 * Verify public-access token columns remain MySQL-indexable for both fresh installs and migrations.
 */

require_once dirname(__DIR__) . '/backend/includes/database.php';

echo "=== Public access token schema test ===\n\n";

/**
 * @param ReflectionClassConstant|false $constant
 */
function assertTokenSchemaConstant(ReflectionClassConstant|false $constant, string $name, string $expected): void
{
    if (!$constant instanceof ReflectionClassConstant) {
        throw new RuntimeException('Unable to inspect Database::' . $name . '; the token schema constant must remain defined.');
    }

    $value = $constant->getValue();
    if (!is_string($value) || $value !== $expected) {
        throw new RuntimeException('Database::' . $name . ' should resolve to "' . $expected . '".');
    }
}

function assertTokenSchemaSource(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(Database::class);

assertTokenSchemaConstant(
    $reflection->getReflectionConstant('MYSQL_PUBLIC_ACCESS_TOKEN_COLUMN_TYPE'),
    'MYSQL_PUBLIC_ACCESS_TOKEN_COLUMN_TYPE',
    'VARCHAR(32)'
);
assertTokenSchemaConstant(
    $reflection->getReflectionConstant('MYSQL_FORM_SUBMISSIONS_ACCESS_TOKEN_INDEX_SQL'),
    'MYSQL_FORM_SUBMISSIONS_ACCESS_TOKEN_INDEX_SQL',
    'CREATE UNIQUE INDEX idx_form_submissions_access_token ON form_submissions(access_token)'
);
assertTokenSchemaConstant(
    $reflection->getReflectionConstant('MYSQL_QUOTES_ACCESS_TOKEN_INDEX_SQL'),
    'MYSQL_QUOTES_ACCESS_TOKEN_INDEX_SQL',
    'CREATE UNIQUE INDEX idx_quotes_access_token ON quotes(access_token)'
);
assertTokenSchemaConstant(
    $reflection->getReflectionConstant('MYSQL_BOOKINGS_ICAL_TOKEN_INDEX_SQL'),
    'MYSQL_BOOKINGS_ICAL_TOKEN_INDEX_SQL',
    'CREATE UNIQUE INDEX idx_bookings_ical_token ON bookings(ical_token)'
);

$database_source = file_get_contents(dirname(__DIR__) . '/backend/includes/database.php');
if (!is_string($database_source)) {
    throw new RuntimeException('Unable to inspect database schema source.');
}

assertTokenSchemaSource(
    preg_match('/CREATE TABLE IF NOT EXISTS form_submissions[\s\S]*?access_token VARCHAR\(32\)/', $database_source) === 1,
    'form_submissions should define access_token as VARCHAR(32) for fresh MySQL installs.'
);
assertTokenSchemaSource(
    preg_match('/CREATE TABLE IF NOT EXISTS quotes[\s\S]*?access_token VARCHAR\(32\)/', $database_source) === 1,
    'quotes should define access_token as VARCHAR(32) for fresh MySQL installs.'
);
assertTokenSchemaSource(
    preg_match('/CREATE TABLE IF NOT EXISTS bookings[\s\S]*?ical_token VARCHAR\(32\)/', $database_source) === 1,
    'bookings should define ical_token as VARCHAR(32) for fresh MySQL installs.'
);

assertTokenSchemaSource(
    preg_match('/ALTER TABLE form_submissions ADD COLUMN access_token VARCHAR\(32\)/', $database_source) === 1,
    'form_submissions migration should add access_token as VARCHAR(32).'
);
assertTokenSchemaSource(
    preg_match('/ALTER TABLE quotes ADD COLUMN access_token VARCHAR\(32\)/', $database_source) === 1,
    'quotes migration should add access_token as VARCHAR(32).'
);
assertTokenSchemaSource(
    preg_match('/ALTER TABLE bookings ADD COLUMN ical_token VARCHAR\(32\)/', $database_source) === 1,
    'bookings migration should add ical_token as VARCHAR(32).'
);

assertTokenSchemaSource(
    preg_match('/ensureIndexedTokenColumn\(\s*[\'"]form_submissions[\'"]\s*,\s*[\'"]access_token[\'"]\s*\)/', $database_source) === 1,
    'form_submissions migration should normalize the token column before creating its unique index.'
);
assertTokenSchemaSource(
    preg_match('/ensureIndexedTokenColumn\(\s*[\'"]quotes[\'"]\s*,\s*[\'"]access_token[\'"]\s*\)/', $database_source) === 1,
    'quotes migration should normalize the token column before creating its unique index.'
);
assertTokenSchemaSource(
    preg_match('/ensureIndexedTokenColumn\(\s*[\'"]bookings[\'"]\s*,\s*[\'"]ical_token[\'"]\s*\)/', $database_source) === 1,
    'bookings migration should normalize the token column before creating its unique index.'
);

assertTokenSchemaSource(
    preg_match('/execSQL\(\s*self::MYSQL_FORM_SUBMISSIONS_ACCESS_TOKEN_INDEX_SQL\s*\)/', $database_source) === 1,
    'form_submissions should create its unique token index from the dedicated MySQL SQL constant.'
);
assertTokenSchemaSource(
    preg_match('/execSQL\(\s*self::MYSQL_QUOTES_ACCESS_TOKEN_INDEX_SQL\s*\)/', $database_source) === 1,
    'quotes should create their unique token index from the dedicated MySQL SQL constant.'
);
assertTokenSchemaSource(
    preg_match('/execSQL\(\s*self::MYSQL_BOOKINGS_ICAL_TOKEN_INDEX_SQL\s*\)/', $database_source) === 1,
    'bookings should create their unique iCal token index from the dedicated MySQL SQL constant.'
);

echo "✓ public-access token columns stay indexable on MySQL\n";
echo "✓ public-access token indexes stay centralized in dedicated SQL constants\n";
echo "\nAll public access token schema tests passed!\n";
