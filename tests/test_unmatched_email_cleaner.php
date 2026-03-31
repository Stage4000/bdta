#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once dirname(__DIR__) . '/backend/includes/config.php';
require_once dirname(__DIR__) . '/backend/cron/tasks/unmatched_email_cleaner.php';

echo "=== Unmatched Email Cleaner Test ===\n\n";

$suffix = bin2hex(random_bytes(4));
$valid_id = 0;
$invalid_ids = [];

try {
    $db = new Database();
    $conn = $db->getConnection();

    $insert = $conn->prepare("
        INSERT INTO unmatched_emails (
            from_email, from_name, to_email, subject,
            body_html, body_text, received_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $insert->execute([
        'valid.' . $suffix . '@example.invalid',
        'Valid Sender',
        'inbox@example.invalid',
        'Valid email ' . $suffix,
        '<p>Valid</p>',
        'Valid',
        '2026-03-17 12:00:00',
        currentUtcDateTime()
    ]);
    $valid_id = safe_int($conn->lastInsertId());

    $insert->execute([
        'invalid.' . $suffix . '@example.invalid',
        'Invalid Sender',
        'inbox@example.invalid',
        'Invalid email ' . $suffix,
        '<p>Invalid</p>',
        'Invalid',
        null,
        currentUtcDateTime()
    ]);
    $invalid_ids[] = safe_int($conn->lastInsertId());

    $insert->execute([
        'blank.' . $suffix . '@example.invalid',
        'Blank Timestamp Sender',
        'inbox@example.invalid',
        'Blank timestamp email ' . $suffix,
        '<p>Blank timestamp</p>',
        'Blank timestamp',
        '',
        currentUtcDateTime()
    ]);
    $invalid_ids[] = safe_int($conn->lastInsertId());

    $task = new UnmatchedEmailCleanerTask();
    $result = $task->execute();

    if (!$result['success']) {
        throw new RuntimeException('Cleaner task did not report success.');
    }
    if (($result['items_processed'] ?? 0) < count($invalid_ids)) {
        throw new RuntimeException('Cleaner task did not delete all malformed emails.');
    }

    $placeholders = implode(', ', array_fill(0, count($invalid_ids) + 1, '?'));
    $check = $conn->prepare("SELECT id, received_at FROM unmatched_emails WHERE id IN ($placeholders) ORDER BY id ASC");
    $check->execute(array_merge([$valid_id], $invalid_ids));
    $rows = $check->fetchAll(PDO::FETCH_ASSOC);

    $remaining_ids = array_map(static fn(array $row): int => safe_int($row['id'] ?? 0), $rows);

    if (!in_array($valid_id, $remaining_ids, true)) {
        throw new RuntimeException('Cleaner task deleted a valid unmatched email.');
    }
    foreach ($invalid_ids as $invalid_id) {
        if (in_array($invalid_id, $remaining_ids, true)) {
            throw new RuntimeException('Cleaner task failed to delete an unmatched email without a timestamp.');
        }
    }

    echo "✓ Cleaner task removes unmatched emails without timestamps\n";
    echo "\n=== All Unmatched Email Cleaner Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        foreach ($invalid_ids as $invalid_id) {
            if ($invalid_id <= 0) {
                continue;
            }
            $cleanup_invalid = $conn->prepare("DELETE FROM unmatched_emails WHERE id = ?");
            $cleanup_invalid->execute([$invalid_id]);
        }
        if ($valid_id > 0) {
            $cleanup_valid = $conn->prepare("DELETE FROM unmatched_emails WHERE id = ?");
            $cleanup_valid->execute([$valid_id]);
        }
    }
}
