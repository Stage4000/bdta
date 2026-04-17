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

    echo "Invoice payment progress tests passed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
