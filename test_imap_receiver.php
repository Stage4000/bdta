#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/imap_receiver.php';

echo "=== IMAP Receiver Test ===\n\n";

$suffix = bin2hex(random_bytes(4));
$client_email = 'Case.Client.' . $suffix . '@example.invalid';
$client_email_lower = strtolower($client_email);
$message_id = '<imap-client-' . $suffix . '@example.invalid>';
$unmatched_message_id = '<imap-unmatched-' . $suffix . '@example.invalid>';
$fallback_subject = 'IMAP fallback ' . $suffix;
$fallback_received_at = '2026-03-16 14:30:00';
$client_id = 0;

try {
    $db = new Database();
    $conn = $db->getConnection();
    $receiver = new ImapEmailReceiver();

    $conn->query("SELECT message_id FROM client_emails LIMIT 1");
    $conn->query("SELECT message_id FROM unmatched_emails LIMIT 1");
    echo "✓ Email tables include message_id columns\n";

    $stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone, address, created_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'IMAP Receiver Test ' . $suffix,
        $client_email,
        '555-0100',
        '123 Test Street',
        currentUtcDateTime()
    ]);
    $client_id = safe_int($conn->lastInsertId());

    $find_client = new ReflectionMethod(ImapEmailReceiver::class, 'findClientByEmail');
    $find_client->setAccessible(true);
    $matched_client_id = $find_client->invoke($receiver, $client_email_lower);

    if (!is_int($matched_client_id) || $matched_client_id !== $client_id) {
        throw new RuntimeException('Expected case-insensitive client email lookup to return the client ID.');
    }
    echo "✓ Client lookup matches inbound emails case-insensitively\n";

    $duplicate_check = new ReflectionMethod(ImapEmailReceiver::class, 'isDuplicateEmail');
    $duplicate_check->setAccessible(true);

    $stmt = $conn->prepare("
        INSERT INTO client_emails (
            client_id, direction, status, message_id, from_email, to_email,
            subject, body_html, body_text, sent_at, created_at, updated_at
        ) VALUES (?, 'incoming', 'received', ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $client_id,
        $message_id,
        'sender.' . $suffix . '@example.invalid',
        Settings::get('imap_username', 'inbox@example.invalid'),
        'IMAP duplicate test',
        '<p>Body</p>',
        'Body',
        $fallback_received_at,
        $fallback_received_at,
        currentUtcDateTime()
    ]);

    if ($duplicate_check->invoke($receiver, $message_id, 'other@example.invalid', 'Other Subject', '2026-03-01 00:00:00') !== true) {
        throw new RuntimeException('Expected existing client email message_id to be treated as duplicate.');
    }
    echo "✓ Existing client emails are deduplicated by message_id\n";

    $stmt = $conn->prepare("
        INSERT INTO unmatched_emails (
            message_id, from_email, from_name, to_email, subject,
            body_html, body_text, received_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $unmatched_message_id,
        'unknown.' . $suffix . '@example.invalid',
        'Unknown Sender',
        Settings::get('imap_username', 'inbox@example.invalid'),
        'Unmatched duplicate test',
        '<p>Body</p>',
        'Body',
        $fallback_received_at,
        currentUtcDateTime()
    ]);

    if ($duplicate_check->invoke($receiver, $unmatched_message_id, 'other@example.invalid', 'Other Subject', '2026-03-01 00:00:00') !== true) {
        throw new RuntimeException('Expected existing unmatched email message_id to be treated as duplicate.');
    }
    echo "✓ Existing unmatched emails are deduplicated by message_id\n";

    if ($duplicate_check->invoke($receiver, '', 'fallback.' . $suffix . '@example.invalid', $fallback_subject, $fallback_received_at) !== false) {
        throw new RuntimeException('Expected fallback duplicate check to ignore unmatched subjects before data exists.');
    }

    $stmt = $conn->prepare("
        INSERT INTO unmatched_emails (
            from_email, from_name, to_email, subject,
            body_html, body_text, received_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        'fallback.' . $suffix . '@example.invalid',
        'Fallback Sender',
        Settings::get('imap_username', 'inbox@example.invalid'),
        $fallback_subject,
        '<p>Body</p>',
        'Body',
        $fallback_received_at,
        currentUtcDateTime()
    ]);

    if ($duplicate_check->invoke($receiver, '', 'fallback.' . $suffix . '@example.invalid', $fallback_subject, $fallback_received_at) !== true) {
        throw new RuntimeException('Expected subject/date fallback duplicate check to remain active when message_id is unavailable.');
    }
    echo "✓ Fallback duplicate detection still works without a message_id\n";

    echo "\n=== All IMAP Receiver Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . "\n";
    echo "  Line: " . $e->getLine() . "\n";
    exit(1);
} finally {
    if (isset($conn) && $conn instanceof PDO) {
        $cleanup_client_email = $conn->prepare("DELETE FROM client_emails WHERE message_id = ?");
        $cleanup_client_email->execute([$message_id]);

        $cleanup_unmatched_email = $conn->prepare("DELETE FROM unmatched_emails WHERE message_id = ?");
        $cleanup_unmatched_email->execute([$unmatched_message_id]);

        $cleanup_fallback = $conn->prepare("DELETE FROM unmatched_emails WHERE subject = ? OR from_email = ?");
        $cleanup_fallback->execute([$fallback_subject, 'fallback.' . $suffix . '@example.invalid']);

        if ($client_id > 0) {
            $cleanup_client = $conn->prepare("DELETE FROM clients WHERE id = ?");
            $cleanup_client->execute([$client_id]);
        }
    }
}
