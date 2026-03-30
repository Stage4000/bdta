#!/usr/bin/env php
<?php

require_once __DIR__ . '/backend/includes/config.php';
require_once __DIR__ . '/backend/includes/invoice_status.php';

echo "=== Invoice Void and Refund Test ===\n\n";

$db = new Database();
$conn = $db->getConnection();

$client_id = 0;
$time_entry_id = 0;
$void_invoice_id = 0;
$paid_invoice_id = 0;
$installment_id = 0;

try {
    $unique_token = 'invoice-void-refund-' . bin2hex(random_bytes(4));

    $client_stmt = $conn->prepare("
        INSERT INTO clients (name, email, phone)
        VALUES (?, ?, ?)
    ");
    $client_stmt->execute(['Invoice Lifecycle ' . $unique_token, $unique_token . '@example.com', '555-0110']);
    $client_id = safe_int($conn->lastInsertId());

    $time_entry_stmt = $conn->prepare("
        INSERT INTO time_entries (client_id, service_type, description, date, start_time, end_time, duration_minutes, hourly_rate, total_amount, billable, invoiced)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $time_entry_stmt->execute([$client_id, 'Private Training', 'Voidable entry', '2026-03-10', '09:00:00', '10:00:00', 60, 100.00, 100.00, 1, 1]);
    $time_entry_id = safe_int($conn->lastInsertId());

    $invoice_stmt = $conn->prepare("
        INSERT INTO invoices (invoice_number, client_id, issue_date, due_date, subtotal, tax_amount, total_amount, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $invoice_stmt->execute(['INV-VOID-' . $unique_token, $client_id, '2026-03-10', '2026-04-10', 100.00, 0.00, 100.00, 'sent']);
    $void_invoice_id = safe_int($conn->lastInsertId());

    $item_stmt = $conn->prepare("
        INSERT INTO invoice_items (invoice_id, item_type, reference_id, description, quantity, rate, amount)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $item_stmt->execute([$void_invoice_id, 'time_entry', $time_entry_id, 'Training session', 1, 100.00, 100.00]);

    $installment_stmt = $conn->prepare("
        INSERT INTO invoice_installments (invoice_id, installment_number, amount, due_date, status)
        VALUES (?, ?, ?, ?, ?)
    ");
    $installment_stmt->execute([$void_invoice_id, 1, 100.00, '2026-04-10', 'unpaid']);
    $installment_id = safe_int($conn->lastInsertId());

    bdta_void_invoice($conn, $void_invoice_id, 'Client requested a corrected invoice');

    $voided_invoice_stmt = $conn->prepare("SELECT status, void_reason, voided_at FROM invoices WHERE id = ?");
    $voided_invoice_stmt->execute([$void_invoice_id]);
    $voided_invoice = assoc_row($voided_invoice_stmt->fetch(PDO::FETCH_ASSOC));
    if (array_string_value($voided_invoice, 'status') !== 'void') {
        throw new RuntimeException('Voided invoice did not receive void status');
    }
    if (array_string_value($voided_invoice, 'void_reason') !== 'Client requested a corrected invoice') {
        throw new RuntimeException('Void reason was not stored');
    }
    if (array_string_value($voided_invoice, 'voided_at') === '') {
        throw new RuntimeException('Voided timestamp was not stored');
    }
    echo "✓ Unpaid invoices can be voided with a reason\n";

    $time_entry_status_stmt = $conn->prepare("SELECT invoiced FROM time_entries WHERE id = ?");
    $time_entry_status_stmt->execute([$time_entry_id]);
    if (safe_int($time_entry_status_stmt->fetchColumn()) !== 0) {
        throw new RuntimeException('Voiding did not release linked time entries');
    }
    echo "✓ Voiding releases linked time entries for reinvoicing\n";

    $installment_status_stmt = $conn->prepare("SELECT status FROM invoice_installments WHERE id = ?");
    $installment_status_stmt->execute([$installment_id]);
    if (scalar_string($installment_status_stmt->fetchColumn()) !== 'cancelled') {
        throw new RuntimeException('Voiding did not cancel unpaid installments');
    }
    echo "✓ Voiding cancels unpaid installments\n";

    $invoice_stmt->execute(['INV-REFUND-' . $unique_token, $client_id, '2026-03-11', '2026-04-11', 100.00, 0.00, 100.00, 'paid']);
    $paid_invoice_id = safe_int($conn->lastInsertId());
    $conn->prepare("
        UPDATE invoices
        SET payment_method = 'cash',
            payment_date = '2026-03-11'
        WHERE id = ?
    ")->execute([$paid_invoice_id]);

    $first_refund = bdta_record_invoice_refund(
        $conn,
        $paid_invoice_id,
        40.00,
        '2026-03-12',
        'cash',
        'Partial refund for missed session'
    );
    if (safe_float($first_refund['refunded_total'] ?? 0) !== 40.00 || scalar_string($first_refund['status'] ?? '') !== 'paid') {
        throw new RuntimeException('Partial refund did not preserve the paid invoice status');
    }
    echo "✓ Partial refunds are recorded without reopening the invoice\n";

    $second_refund = bdta_record_invoice_refund(
        $conn,
        $paid_invoice_id,
        60.00,
        '2026-03-13',
        'cash',
        'Final refund'
    );
    if (safe_float($second_refund['refunded_total'] ?? 0) !== 100.00 || scalar_string($second_refund['status'] ?? '') !== 'refunded') {
        throw new RuntimeException('Full refund did not move the invoice to refunded status');
    }
    echo "✓ Full refunds move invoices to refunded status\n";

    $refund_total = bdta_invoice_get_refunded_total($conn, $paid_invoice_id);
    if ($refund_total !== 100.00) {
        throw new RuntimeException('Refund history total is incorrect');
    }

    $refund_rows = bdta_invoice_get_refunds($conn, $paid_invoice_id);
    if (count($refund_rows) !== 2) {
        throw new RuntimeException('Refund history rows were not stored');
    }
    echo "✓ Refund history is retained for bookkeeping records\n";

    $final_invoice_stmt = $conn->prepare("SELECT status FROM invoices WHERE id = ?");
    $final_invoice_stmt->execute([$paid_invoice_id]);
    if (scalar_string($final_invoice_stmt->fetchColumn()) !== 'refunded') {
        throw new RuntimeException('Refunded invoice status was not persisted');
    }

    echo "\n=== All Tests Passed! ===\n";
} catch (Throwable $e) {
    echo "\n✗ Test failed: " . $e->getMessage() . "\n";
    exit(1);
} finally {
    if ($paid_invoice_id > 0) {
        $conn->prepare("DELETE FROM invoice_refunds WHERE invoice_id = ?")->execute([$paid_invoice_id]);
    }
    if ($void_invoice_id > 0) {
        $conn->prepare("DELETE FROM invoice_installments WHERE invoice_id = ?")->execute([$void_invoice_id]);
    }
    foreach ([$void_invoice_id, $paid_invoice_id] as $invoice_id) {
        if ($invoice_id > 0) {
            $conn->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$invoice_id]);
            $conn->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoice_id]);
        }
    }
    if ($time_entry_id > 0) {
        $conn->prepare("DELETE FROM time_entries WHERE id = ?")->execute([$time_entry_id]);
    }
    if ($client_id > 0) {
        $conn->prepare("DELETE FROM clients WHERE id = ?")->execute([$client_id]);
    }
}
