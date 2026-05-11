#!/usr/bin/env php
<?php

function bdta_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$path = dirname(__DIR__) . '/client/appointment_types_edit.php';
$contents = file_get_contents($path);

if ($contents === false) {
    fwrite(STDERR, "Test setup failed: unable to read appointment_types_edit.php\n");
    exit(1);
}

$matched = preg_match(
    '/\$stmt = \$conn->prepare\("\s*INSERT INTO appointment_types\s*\((?<columns>[\s\S]*?)\)\s*VALUES\s*\((?<placeholders>[\s\S]*?)\)\s*"\s*\);\s*\/\/ Keep this value list in the same order as the INSERT columns above\.\s*\$stmt->execute\(\[(?<values>[\s\S]*?)\]\);/s',
    $contents,
    $matches
);

if ($matched !== 1) {
    fwrite(STDERR, "Could not locate the appointment type create INSERT statement.\n");
    exit(1);
}

$normalized_columns = preg_replace('/\s+/', ' ', $matches['columns']);
if (!is_string($normalized_columns)) {
    fwrite(STDERR, "Appointment type create INSERT columns could not be normalized.\n");
    exit(1);
}

$columns = preg_split('/\s*,\s*/', trim($normalized_columns));
if (!is_array($columns)) {
    fwrite(STDERR, "Appointment type create INSERT columns could not be split.\n");
    exit(1);
}
$columns = array_values(array_filter($columns, static fn(string $column): bool => $column !== ''));
$placeholder_count = preg_match_all('/\?/', $matches['placeholders']);
$value_count = preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*/', $matches['values']);

bdta_assert(
    $placeholder_count === $value_count,
    sprintf(
        'Appointment type create INSERT placeholder count mismatch: %d placeholders for %d bound values.',
        $placeholder_count,
        $value_count
    )
);

bdta_assert(
    count($columns) === $value_count,
    sprintf(
        'Appointment type create INSERT column count mismatch: %d columns for %d bound values.',
        count($columns),
        $value_count
    )
);

echo "Appointment type create INSERT SQL is aligned.\n";
