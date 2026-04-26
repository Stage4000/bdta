<?php

function bdta_normalize_invoice_due_timing(mixed $value, string $default = 'after'): string
{
    $timing = strtolower(trim(scalar_string($value)));
    $default = strtolower(trim($default));
    $default = in_array($default, ['before', 'after'], true) ? $default : 'after';

    return in_array($timing, ['before', 'after'], true) ? $timing : $default;
}

function bdta_calculate_invoice_due_date(string $appointment_date, int $invoice_due_days, string $invoice_due_timing = 'after'): string
{
    $invoice_due_days = max(0, $invoice_due_days);
    $invoice_due_timing = bdta_normalize_invoice_due_timing($invoice_due_timing);
    $modifier = $invoice_due_timing === 'before'
        ? "-{$invoice_due_days} days"
        : "+{$invoice_due_days} days";

    return date('Y-m-d', safe_timestamp(strtotime($appointment_date . ' ' . $modifier)));
}
