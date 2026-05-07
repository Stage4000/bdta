#!/usr/bin/env php
<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

function assertInvoicePayReturn(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$source = file_get_contents(dirname(__DIR__) . '/portal/invoice_pay_return.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('Unable to read portal/invoice_pay_return.php');
}

assertInvoicePayReturn(
    str_contains($source, '$requested_invoice_id = safe_int($_GET[\'id\'] ?? 0);'),
    'Expected invoice pay return handler to normalize the requested invoice id before redirect decisions.'
);
assertInvoicePayReturn(
    str_contains($source, '$fallback_location = $requested_invoice_id > 0'),
    'Expected missing session_id handling to derive a portal invoice fallback location.'
);
assertInvoicePayReturn(
    str_contains($source, "? 'invoice_view.php?id=' . \$requested_invoice_id"),
    'Expected portal return fallback to preserve the requested invoice id.'
);
assertInvoicePayReturn(
    str_contains($source, "if (\$token !== '') {"),
    'Expected missing session_id handling to branch on guest pay tokens.'
);
assertInvoicePayReturn(
    str_contains($source, "\$fallback_location = 'invoice_pay.php?token=' . urlencode(\$token);"),
    'Expected guest Stripe returns without session_id to go back to the guest invoice page.'
);
assertInvoicePayReturn(
    str_contains($source, "header('Location: ' . \$fallback_location);"),
    'Expected missing session_id handling to redirect using the preserved fallback location.'
);

echo "Invoice pay return regression test passed.\n";
