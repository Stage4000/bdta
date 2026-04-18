#!/usr/bin/env php
<?php

require_once dirname(__DIR__) . '/backend/includes/database.php';
require_once dirname(__DIR__) . '/backend/includes/invoice_status.php';

function assertInvoiceIncomeReporting(bool $condition, string $message): void
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
            client_id INTEGER,
            issue_date TEXT,
            subtotal REAL DEFAULT 0,
            tax_amount REAL DEFAULT 0,
            total_amount REAL NOT NULL,
            status TEXT NOT NULL,
            payment_date TEXT,
            payment_method TEXT
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
        INSERT INTO invoices (id, invoice_number, client_id, issue_date, subtotal, tax_amount, total_amount, status, payment_date, payment_method)
        VALUES
            (1, 'INV-PARTIAL', 10, '2026-02-20', 200.00, 0.00, 200.00, 'paid', '2026-04-03', 'cash'),
            (2, 'INV-INSTALLMENT', 20, '2026-02-25', 100.00, 0.00, 100.00, 'sent', NULL, NULL),
            (3, 'INV-LEGACY', 30, '2026-03-01', 75.00, 0.00, 75.00, 'paid', '2026-03-10', 'check'),
            (4, 'INV-DRAFT', 40, '2026-03-02', 99.00, 0.00, 99.00, 'draft', '2026-03-12', 'cash'),
            (5, 'INV-LEGACY-INSTALLMENT-GAP', 50, '2026-03-03', 60.00, 0.00, 60.00, 'paid', '2026-03-18', 'bank_transfer')
    ");
    $conn->exec("
        INSERT INTO invoice_payments (invoice_id, amount, payment_date, payment_method, stripe_payment_intent_id, notes)
        VALUES
            (1, 100.00, '2026-03-01', 'cash', NULL, NULL),
            (1, 100.00, '2026-04-03', 'cash', NULL, NULL)
    ");
    $conn->exec("
        INSERT INTO invoice_installments (invoice_id, amount, status, payment_date, payment_method)
        VALUES
            (2, 50.00, 'paid', '2026-03-05', 'cash'),
            (2, 50.00, 'paid', '2026-03-20', 'cash'),
            (5, 60.00, 'paid', NULL, 'bank_transfer')
    ");

    $march_events = bdta_invoice_get_income_events($conn, '2026-03-01', '2026-03-31');
    assertInvoiceIncomeReporting(count($march_events) === 5, 'Expected March income reporting to return actual payment events plus eligible legacy paid invoices.');

    $march_totals = [];
    foreach ($march_events as $event) {
        $march_totals[$event['payment_date']] = ($march_totals[$event['payment_date']] ?? 0) + $event['amount'];
    }

    assertInvoiceIncomeReporting(($march_totals['2026-03-01'] ?? 0.0) === 100.00, 'Expected partial invoice payments to be reported on their actual payment date.');
    assertInvoiceIncomeReporting(($march_totals['2026-03-05'] ?? 0.0) === 50.00, 'Expected paid installments to be reported on their payment date.');
    assertInvoiceIncomeReporting(($march_totals['2026-03-10'] ?? 0.0) === 75.00, 'Expected legacy paid invoices without ledger rows to remain reportable.');
    assertInvoiceIncomeReporting(($march_totals['2026-03-18'] ?? 0.0) === 60.00, 'Expected legacy invoice fallback to remain reportable when paid installments lack a payment date.');
    assertInvoiceIncomeReporting(($march_totals['2026-03-20'] ?? 0.0) === 50.00, 'Expected multiple paid installments to be reported separately.');
    assertInvoiceIncomeReporting(!isset($march_totals['2026-04-03']), 'Expected out-of-range payments to be excluded.');
    assertInvoiceIncomeReporting(array_sum($march_totals) === 335.00, 'Expected March totals to be based on collected amounts, not full invoice totals on the final payment date.');

    $april_events = bdta_invoice_get_income_events($conn, '2026-04-01', '2026-04-30');
    assertInvoiceIncomeReporting(count($april_events) === 1, 'Expected April to include only the final partial payment event.');
    assertInvoiceIncomeReporting($april_events[0]['amount'] === 100.00, 'Expected the remaining partial payment amount to be reported in April.');
    assertInvoiceIncomeReporting($april_events[0]['source'] === 'payment', 'Expected recorded payment rows to remain the source of truth when present.');

    echo "Invoice income reporting tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
