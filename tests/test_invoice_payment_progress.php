#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/invoice_status.php';

function assertInvoicePaymentProgress(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $invoice = [
        'total_amount' => 120.00,
        'status' => 'sent',
    ];

    $partial = bdta_invoice_calculate_payment_progress($invoice, 45.25);
    assertInvoicePaymentProgress($partial['status'] === 'partial', 'Expected partial payments to set partial invoice status.');
    assertInvoicePaymentProgress($partial['paid_total'] === 45.25, 'Expected paid total to match the recorded payment amount.');
    assertInvoicePaymentProgress($partial['remaining_amount'] === 74.75, 'Expected remaining balance to be reduced by the partial payment.');

    $paid = bdta_invoice_calculate_payment_progress($invoice, 120.00);
    assertInvoicePaymentProgress($paid['status'] === 'paid', 'Expected full payments to set paid invoice status.');
    assertInvoicePaymentProgress($paid['remaining_amount'] === 0.00, 'Expected fully paid invoices to have no remaining balance.');

    $capped = bdta_invoice_calculate_payment_progress($invoice, 200.00);
    assertInvoicePaymentProgress($capped['paid_total'] === 120.00, 'Expected paid totals to be capped at the invoice total.');
    assertInvoicePaymentProgress($capped['remaining_amount'] === 0.00, 'Expected capped overpayments to leave no remaining balance.');

    $conn = new PDO('sqlite::memory:');
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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
            status TEXT NOT NULL
        )
    ");

    $recorded_invoice = [
        'id' => 101,
        'total_amount' => 100.00,
        'status' => 'sent',
    ];
    $conn->prepare("
        INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([101, 35.50, '2026-04-17', 'cash', null, null]);
    $recorded_summary = bdta_invoice_get_payment_summary($conn, $recorded_invoice);
    assertInvoicePaymentProgress($recorded_summary['status'] === 'partial', 'Expected recorded ledger payments to produce partial status.');
    assertInvoicePaymentProgress($recorded_summary['paid_total'] === 35.50, 'Expected recorded ledger payments to contribute to paid totals.');
    assertInvoicePaymentProgress($recorded_summary['remaining_amount'] === 64.50, 'Expected recorded ledger payments to reduce remaining balance.');

    $installment_invoice = [
        'id' => 202,
        'total_amount' => 120.00,
        'status' => 'sent',
    ];
    $installments = [
        ['amount' => 40.00, 'status' => 'paid'],
        ['amount' => 30.00, 'status' => 'unpaid'],
        ['amount' => 20.00, 'status' => 'paid'],
    ];
    $installment_summary = bdta_invoice_get_payment_summary($conn, $installment_invoice, $installments);
    assertInvoicePaymentProgress($installment_summary['status'] === 'partial', 'Expected paid installments to contribute to partial invoice status.');
    assertInvoicePaymentProgress($installment_summary['paid_total'] === 60.00, 'Expected only paid installments to contribute to paid totals.');
    assertInvoicePaymentProgress($installment_summary['remaining_amount'] === 60.00, 'Expected paid installments to reduce remaining balance.');

    $legacy_paid_invoice = [
        'id' => 303,
        'total_amount' => 75.00,
        'status' => 'paid',
        'payment_method' => 'cash',
    ];
    $legacy_summary = bdta_invoice_get_payment_summary($conn, $legacy_paid_invoice);
    assertInvoicePaymentProgress($legacy_summary['status'] === 'paid', 'Expected legacy paid invoices without ledger rows to remain paid.');
    assertInvoicePaymentProgress($legacy_summary['paid_total'] === 75.00, 'Expected legacy paid invoices without ledger rows to report a fully paid total.');
    assertInvoicePaymentProgress($legacy_summary['remaining_amount'] === 0.00, 'Expected legacy paid invoices without ledger rows to have no remaining balance.');

    $conn->prepare("
        INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([404, 100.00, '2026-04-22', 'cash', null, null]);
    $manual_paid_invoice = [
        'id' => 404,
        'total_amount' => 300.00,
        'status' => 'paid',
        'payment_method' => 'cash',
    ];
    $manual_paid_summary = bdta_invoice_get_payment_summary($conn, $manual_paid_invoice);
    assertInvoicePaymentProgress($manual_paid_summary['status'] === 'paid', 'Expected admin-closed paid invoices to retain the paid status.');
    assertInvoicePaymentProgress($manual_paid_summary['paid_total'] === 100.00, 'Expected admin-closed paid invoices to preserve the actual collected amount.');
    assertInvoicePaymentProgress($manual_paid_summary['remaining_amount'] === 0.00, 'Expected admin-closed paid invoices to stop showing a balance due.');
    assertInvoicePaymentProgress($manual_paid_summary['closed_balance_amount'] === 200.00, 'Expected admin-closed paid invoices to expose the closed balance separately.');

    $conn->prepare("
        INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([405, 60.00, '2026-04-23', 'bank_transfer', null, null]);
    $settled_invoice = [
        'id' => 405,
        'total_amount' => 180.00,
        'status' => 'settled',
        'payment_method' => 'bank_transfer',
    ];
    $settled_summary = bdta_invoice_get_payment_summary($conn, $settled_invoice);
    assertInvoicePaymentProgress($settled_summary['status'] === 'settled', 'Expected settled invoices to retain the settled status.');
    assertInvoicePaymentProgress($settled_summary['paid_total'] === 60.00, 'Expected settled invoices to preserve the actual collected amount.');
    assertInvoicePaymentProgress($settled_summary['remaining_amount'] === 0.00, 'Expected settled invoices to stop showing a balance due.');
    assertInvoicePaymentProgress(!bdta_invoice_is_payable($settled_invoice), 'Expected settled invoices to be treated as closed.');

    echo "Invoice payment progress tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
