#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/invoice_status.php';

function assertInvoiceAdminCloseout(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $conn = new PDO('sqlite::memory:');
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("
        CREATE TABLE invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_number TEXT,
            total_amount REAL NOT NULL,
            status TEXT NOT NULL,
            due_date TEXT NOT NULL,
            payment_method TEXT,
            payment_date TEXT,
            updated_at TEXT
        )
    ");
    $conn->exec("
        CREATE TABLE invoice_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            payment_date TEXT NOT NULL,
            payment_method TEXT,
            stripe_payment_intent_id TEXT,
            notes TEXT
        )
    ");
    $conn->exec("
        CREATE TABLE invoice_installments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            status TEXT NOT NULL,
            payment_date TEXT,
            payment_method TEXT
        )
    ");
    $conn->exec("
        CREATE TABLE invoice_refunds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            refund_date TEXT NOT NULL,
            refund_method TEXT,
            stripe_refund_id TEXT,
            notes TEXT
        )
    ");

    $conn->exec("
        INSERT INTO invoices (id, invoice_number, total_amount, status, due_date, payment_method, payment_date, updated_at)
        VALUES
            (1, 'INV-CLOSE-SETTLED', 300.00, 'partial', '2026-05-10', NULL, NULL, NULL),
            (2, 'INV-CLOSE-PAID', 250.00, 'partial', '2026-05-11', NULL, NULL, NULL),
            (3, 'INV-NO-PAYMENTS', 90.00, 'sent', '2026-05-12', NULL, NULL, NULL)
    ");
    $conn->exec("
        INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes)
        VALUES
            (1, 100.00, '2026-05-01', 'cash', NULL, NULL),
            (2, 75.00, '2026-05-02', 'bank_transfer', NULL, NULL)
    ");

    $updated_due_date = bdta_update_invoice_due_date($conn, 1, '2026-06-15');
    assertInvoiceAdminCloseout($updated_due_date['due_date'] === '2026-06-15', 'Expected the due-date helper to return the updated due date.');
    $stored_due_date = scalar_string($conn->query("SELECT due_date FROM invoices WHERE id = 1")->fetchColumn());
    assertInvoiceAdminCloseout($stored_due_date === '2026-06-15', 'Expected the updated due date to be stored.');

    $settled_result = bdta_close_invoice_at_current_amount($conn, 1, 'settled');
    assertInvoiceAdminCloseout($settled_result['status'] === 'settled', 'Expected closeout helper to support the settled status.');
    assertInvoiceAdminCloseout($settled_result['paid_total'] === 100.00, 'Expected settled invoices to preserve the actual collected amount.');
    assertInvoiceAdminCloseout($settled_result['remaining_amount'] === 0.00, 'Expected settled invoices to stop showing a balance due.');
    assertInvoiceAdminCloseout(safe_float($settled_result['closed_balance_amount'] ?? -1) === 200.00, 'Expected settled invoices to expose the waived balance.');

    $paid_result = bdta_close_invoice_at_current_amount($conn, 2, 'paid');
    assertInvoiceAdminCloseout($paid_result['status'] === 'paid', 'Expected closeout helper to support paid-at-current-amount.');
    assertInvoiceAdminCloseout($paid_result['paid_total'] === 75.00, 'Expected paid-at-current-amount invoices to preserve the actual collected amount.');
    assertInvoiceAdminCloseout($paid_result['remaining_amount'] === 0.00, 'Expected paid-at-current-amount invoices to stop showing a balance due.');
    assertInvoiceAdminCloseout(safe_float($paid_result['closed_balance_amount'] ?? -1) === 175.00, 'Expected paid-at-current-amount invoices to expose the waived balance.');

    $settled_invoice_row = $conn->query("SELECT status FROM invoices WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    assertInvoiceAdminCloseout(is_array($settled_invoice_row) && scalar_string($settled_invoice_row['status'] ?? '') === 'settled', 'Expected settled status to persist to the invoice row.');

    $refund_progress = bdta_record_invoice_refund($conn, 1, 40.00, '2026-05-03', 'cash', 'Partial refund after settlement');
    assertInvoiceAdminCloseout($refund_progress['status'] === 'settled', 'Expected partial refunds on settled invoices to preserve settled status.');
    assertInvoiceAdminCloseout($refund_progress['remaining_amount'] === 60.00, 'Expected refund limits to be based on collected funds, not the original invoice total.');

    $threw_on_excess_refund = false;
    try {
        bdta_record_invoice_refund($conn, 1, 61.00, '2026-05-04', 'cash', 'Too much refund');
    } catch (RuntimeException $e) {
        $threw_on_excess_refund = str_contains($e->getMessage(), 'remaining paid balance');
    }
    assertInvoiceAdminCloseout($threw_on_excess_refund, 'Expected refunds above the collected balance to be rejected.');

    $final_refund = bdta_record_invoice_refund($conn, 1, 60.00, '2026-05-05', 'cash', 'Final refund');
    assertInvoiceAdminCloseout($final_refund['status'] === 'refunded', 'Expected settled invoices to move to refunded once collected funds are fully refunded.');

    $threw_on_missing_payments = false;
    try {
        bdta_close_invoice_at_current_amount($conn, 3, 'paid');
    } catch (RuntimeException $e) {
        $threw_on_missing_payments = str_contains($e->getMessage(), 'partial payments');
    }
    assertInvoiceAdminCloseout($threw_on_missing_payments, 'Expected closeout helper to reject invoices with no recorded payments.');

    echo "Invoice admin closeout tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
