#!/usr/bin/env php
<?php

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/invoice_time_entries.php';

echo "=== Invoice Time Entry Helper Test ===\n\n";

$db = new Database();
$conn = $db->getConnection();

$client_id = 0;
$time_entry_ids = [];

try {
    $parsed_ids = bdta_parse_time_entry_ids(['5', '2', '5', '0', '-1', 'abc']);
    if ($parsed_ids !== [5, 2]) {
        throw new RuntimeException('Failed to parse and deduplicate time entry ids');
    }
    echo "✓ Time entry ids are parsed and deduplicated\n";

    $client_stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone)
        VALUES (?, ?, ?)
    ");
    $unique_token = 'invoice-time-entry-' . bin2hex(random_bytes(4));
    $client_name = 'Invoice Helper ' . $unique_token;
    $client_email = $unique_token . '@example.com';
    $client_stmt->execute([$client_name, $client_email, '555-0101']);
    $client_id = safe_int($conn->lastInsertId());

    $time_entry_stmt = $conn->prepare("
        INSERT INTO time_entries (client_id, service_type, description, date, start_time, end_time, duration_minutes, hourly_rate, total_amount, billable, invoiced)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $time_entry_stmt->execute([$client_id, 'Private Training', 'Initial consult', '2026-03-01', '09:00:00', '10:30:00', 90, 120.00, 180.00, 1, 0]);
    $invoiceable_time_entry_id = safe_int($conn->lastInsertId());
    $time_entry_ids[] = $invoiceable_time_entry_id;

    $time_entry_stmt->execute([$client_id, 'Follow Up', 'Already invoiced entry', '2026-03-02', '11:00:00', '12:00:00', 60, 110.00, 110.00, 1, 1]);
    $time_entry_ids[] = safe_int($conn->lastInsertId());

    $time_entry_stmt->execute([$client_id, 'Admin Work', 'Non-billable entry', '2026-03-03', '13:00:00', '13:30:00', 30, 0.00, 0.00, 0, 0]);
    $time_entry_ids[] = safe_int($conn->lastInsertId());

    $invoiceable_entries = bdta_get_invoiceable_time_entries($conn, $time_entry_ids, $client_id);
    if (count($invoiceable_entries) !== 1) {
        throw new RuntimeException('Expected exactly one invoiceable time entry');
    }
    echo "✓ Only billable, uninvoiced time entries are returned\n";

    $description = bdta_build_time_entry_invoice_description($invoiceable_entries[0]);
    if (strpos($description, 'Private Training') === false || strpos($description, 'Initial consult') === false) {
        throw new RuntimeException('Time entry invoice description did not include expected details');
    }
    echo "✓ Time entry descriptions are formatted for invoice line items\n";

    bdta_mark_time_entries_invoiced($conn, [$invoiceable_time_entry_id], $client_id);
    $status_stmt = $conn->prepare("SELECT invoiced FROM time_entries WHERE id = ?");
    $status_stmt->execute([$invoiceable_time_entry_id]);
    if (safe_int($status_stmt->fetchColumn()) !== 1) {
        throw new RuntimeException('Failed to mark invoiceable time entry as invoiced');
    }
    echo "✓ Invoiceable time entries can be marked invoiced\n";

    echo "\n=== All Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "\n✗ Test failed: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    foreach ($time_entry_ids as $time_entry_id) {
        if ($time_entry_id > 0) {
            $cleanup_stmt = $conn->prepare("DELETE FROM time_entries WHERE id = ?");
            $cleanup_stmt->execute([$time_entry_id]);
        }
    }

    if ($client_id > 0) {
        $cleanup_client_stmt = $conn->prepare("DELETE FROM clients WHERE id = ?");
        $cleanup_client_stmt->execute([$client_id]);
    }
}
