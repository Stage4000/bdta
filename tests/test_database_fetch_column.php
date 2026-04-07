#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';

function assertDatabaseFetchColumn(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SafePDOStatement::class]);

$null_stmt = $pdo->query('SELECT MAX(value) FROM (SELECT 1 AS value WHERE 0)');
if (!$null_stmt instanceof PDOStatement) {
    fwrite(STDERR, "Expected empty aggregate query to return a statement.\n");
    exit(1);
}
$null_value = $null_stmt->fetchColumn();
assertDatabaseFetchColumn($null_value === null, 'Expected empty aggregate fetchColumn() result to remain null.');

$value_stmt = $pdo->query('SELECT 42');
if (!$value_stmt instanceof PDOStatement) {
    fwrite(STDERR, "Expected scalar query to return a statement.\n");
    exit(1);
}
$value = $value_stmt->fetchColumn();
assertDatabaseFetchColumn(is_scalar($value) && (string) $value === '42', 'Expected scalar fetchColumn() result to be returned unchanged.');

$empty_stmt = $pdo->query('SELECT 42 WHERE 0');
if (!$empty_stmt instanceof PDOStatement) {
    fwrite(STDERR, "Expected empty-row query to return a statement.\n");
    exit(1);
}
$empty_value = $empty_stmt->fetchColumn();
assertDatabaseFetchColumn($empty_value === false, 'Expected fetchColumn() to return false when no rows are available.');

echo "SafePDOStatement fetchColumn regression test passed.\n";
