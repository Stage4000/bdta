<?php
/** Parse submitted dollars without accepting rounding, exponent notation or junk. */
function bdta_manual_payment_cents(string $amount): int
{
    if (!preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/D', $amount, $parts)) {
        throw new RuntimeException('Enter a payment amount with at most two decimal places.');
    }
    $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
    if ($cents <= 0) { throw new RuntimeException('Payment amount must be positive.'); }
    return $cents;
}

/** A fresh form is a fresh operation. The session secret is never sent to the browser.
 * @param array<string, mixed> $session
 */
function bdta_manual_payment_form_token(array &$session, int $invoice_id, int $installment_id, int $admin_id): string
{
    if (!isset($session['manual_payment_form_secret'])) {
        $session['manual_payment_form_secret'] = bin2hex(random_bytes(32));
    }
    $nonce = bin2hex(random_bytes(32));
    $binding = $nonce . ':' . $invoice_id . ':' . $installment_id . ':' . $admin_id;
    return $nonce . '.' . hash_hmac('sha256', $binding, scalar_string($session['manual_payment_form_secret']));
}

/** Verify issuance separately from CSRF; retain this token for a same-form retry.
 * @param array<string, mixed> $session
 */
function bdta_manual_payment_request_id(array $session, string $token, int $invoice_id, int $installment_id, int $admin_id): string
{
    if (!preg_match('/\A([a-f0-9]{64})\.([a-f0-9]{64})\z/D', $token, $parts)) {
        throw new RuntimeException('Payment form expired. Reload the payment form.');
    }
    $secret = scalar_string($session['manual_payment_form_secret'] ?? '');
    $binding = $parts[1] . ':' . $invoice_id . ':' . $installment_id . ':' . $admin_id;
    if ($secret === '' || !hash_equals(hash_hmac('sha256', $binding, $secret), $parts[2])) {
        throw new RuntimeException('Payment form expired. Reload the payment form.');
    }
    return $parts[1];
}

/** @return array{hash: string, cents: int} */
function bdta_manual_payment_payload(int $invoice_id, float $amount, string $method, string $date, ?int $admin_id, int $installment_id): array
{
    if (!is_finite($amount) || $amount <= 0 || $amount > 999999999.99
        || abs($amount * 100 - round($amount * 100)) > 0.0001
        || $invoice_id <= 0 || $installment_id < 0 || ($admin_id !== null && $admin_id <= 0)
        || !in_array($method, ['cash', 'check', 'bank_transfer', 'other'], true)) {
        throw new RuntimeException('Invalid manual payment payload.');
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) { throw new RuntimeException('Invalid payment date.'); }
    $cents = (int) round($amount * 100);
    $encoded = json_encode(['manual-payment-v1', $invoice_id, $installment_id, $admin_id, $cents, $method, $date], JSON_THROW_ON_ERROR);
    return ['hash' => hash('sha256', $encoded), 'cents' => $cents];
}
