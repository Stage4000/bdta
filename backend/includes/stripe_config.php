<?php
/**
 * Brook's Dog Training Academy - Stripe Configuration
 * 
 * This file dynamically loads Stripe configuration from the database settings.
 * Update settings in Admin Panel > Settings > Payment
 * 
 * Install Stripe PHP SDK with: composer require stripe/stripe-php
 */

require_once __DIR__ . '/settings.php';

// Get Stripe configuration from settings
$stripe_config = Settings::getStripeConfig();

if ($stripe_config) {
    define('STRIPE_PUBLISHABLE_KEY', $stripe_config['publishable_key']);
    define('STRIPE_SECRET_KEY', $stripe_config['secret_key']);
    define('STRIPE_CURRENCY', $stripe_config['currency']);
    define('STRIPE_MODE', $stripe_config['mode']);
} else {
    // Stripe is disabled
    define('STRIPE_PUBLISHABLE_KEY', '');
    define('STRIPE_SECRET_KEY', '');
    define('STRIPE_CURRENCY', 'usd');
    define('STRIPE_MODE', 'test');
}

// Initialize Stripe library if available and configured
if (file_exists(__DIR__ . '/../vendor/autoload.php') && !empty(STRIPE_SECRET_KEY)) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('\Stripe\Stripe')) {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    }
}

/**
 * Check if Stripe is enabled and configured
 */
function isStripeEnabled(): bool {
    return Settings::get('stripe_enabled', false) && STRIPE_SECRET_KEY !== '';
}

/**
 * Create a Stripe payment intent
 *
 * @param array<string, scalar> $metadata
 * @return array<string, scalar>
 */
function createPaymentIntent(int|float $amount, string $description, array $metadata = []): array {
    if (!isStripeEnabled()) {
        return [
            'success' => false,
            'error' => 'Stripe is not enabled or configured'
        ];
    }
    if (!class_exists('\Stripe\PaymentIntent')) {
        return [
            'success' => false,
            'error' => 'Stripe PHP SDK is not installed'
        ];
    }
    
    try {
        $intent = \Stripe\PaymentIntent::create([
            'amount' => $amount * 100, // Stripe uses cents
            'currency' => STRIPE_CURRENCY,
            'description' => $description,
            'metadata' => $metadata,
            'automatic_payment_methods' => ['enabled' => true]
        ]);
        $client_secret = is_object($intent) ? scalar_string($intent->client_secret ?? '') : '';
        $payment_intent_id = is_object($intent) ? scalar_string($intent->id ?? '') : '';
        
        return [
            'success' => true,
            'client_secret' => $client_secret,
            'payment_intent_id' => $payment_intent_id
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Verify a payment intent
 *
 * @return array<string, scalar>
 */
function verifyPaymentIntent(string $payment_intent_id): array {
    if (!isStripeEnabled()) {
        return [
            'success' => false,
            'error' => 'Stripe is not enabled or configured'
        ];
    }
    if (!class_exists('\Stripe\PaymentIntent')) {
        return [
            'success' => false,
            'error' => 'Stripe PHP SDK is not installed'
        ];
    }
    
    try {
        $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
        $status = is_object($intent) ? scalar_string($intent->status ?? '') : '';
        $amount_paid = is_object($intent) ? safe_float($intent->amount ?? 0) / 100 : 0.0;
        return [
            'success' => true,
            'status' => $status,
            'amount' => $amount_paid
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * @param array<string, scalar> $metadata
 * @return array<string, scalar>
 */
function createStripeRefund(string $payment_intent_id, ?float $amount = null, array $metadata = [], ?string $idempotency_key = null): array
{
    if (!isStripeEnabled()) {
        return [
            'success' => false,
            'error' => 'Stripe is not enabled or configured'
        ];
    }

    $payment_intent_id = trim($payment_intent_id);
    if ($payment_intent_id === '') {
        return [
            'success' => false,
            'error' => 'Missing Stripe payment intent'
        ];
    }

    $post_fields = [
        'payment_intent' => $payment_intent_id,
    ];

    if ($amount !== null) {
        $amount_cents = (int) round($amount * 100, 0);
        if ($amount_cents <= 0) {
            return [
                'success' => false,
                'error' => 'Refund amount must be greater than zero'
            ];
        }

        $post_fields['amount'] = $amount_cents;
    }

    foreach ($metadata as $key => $value) {
        $post_fields['metadata[' . $key . ']'] = scalar_string($value);
    }

    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    if ($idempotency_key !== null) {
        if (preg_match('/^bdta-refund-[a-f0-9]{64}$/', $idempotency_key) !== 1) {
            return ['success' => false, 'error' => 'Invalid refund idempotency key'];
        }
        $headers[] = 'Idempotency-Key: ' . $idempotency_key;
    }
    $ch = curl_init('https://api.stripe.com/v1/refunds');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post_fields),
        CURLOPT_USERPWD => scalar_string(STRIPE_SECRET_KEY) . ':',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $curl_error = curl_error($ch);
        curl_close($ch);

        return [
            'success' => false,
            'error' => $curl_error
        ];
    }

    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $refund = decode_json_assoc(scalar_string($response));
    if ($http_code < 200 || $http_code >= 300 || array_string_value($refund, 'id') === '') {
        $refund_error = is_array($refund['error'] ?? null) ? $refund['error'] : [];
        return [
            'success' => false,
            'error' => array_string_value($refund_error, 'message', 'Unable to create Stripe refund')
        ];
    }

    if (!in_array(array_string_value($refund, 'status'), ['succeeded', 'pending', 'requires_action'], true)) {
        return ['success' => false, 'error' => 'Refund outcome requires reconciliation'];
    }

    return [
        'success' => true,
        'refund_id' => array_string_value($refund, 'id'),
        'status' => array_string_value($refund, 'status'),
    ];
}

/**
 * Read-only recovery after Stripe's idempotency retention window. An absent or
 * ambiguous match must never be interpreted as permission to create a refund.
 *
 * @return array<string, scalar>
 */
function findStripeRefundForOperation(string $payment_intent_id, string $operation_key, float $amount, string $currency): array
{
    if (!isStripeEnabled()) {
        return ['success' => false, 'error' => 'Stripe is not enabled or configured'];
    }
    $cursor = '';
    $match = [];
    // Bound read-only recovery; unusually long histories require manual review.
    for ($page = 0; $page < 100; $page++) {
        $params = ['payment_intent' => $payment_intent_id, 'limit' => 100];
        if ($cursor !== '') $params['starting_after'] = $cursor;
        $ch = curl_init('https://api.stripe.com/v1/refunds?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => scalar_string(STRIPE_SECRET_KEY) . ':',
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = decode_json_assoc(scalar_string($response));
        if ($response === false || $http_code < 200 || $http_code >= 300 || !is_array($body['data'] ?? null)) {
            return ['success' => false, 'error' => 'Unable to reconcile Stripe refund; no new refund was sent'];
        }
        $last_id = '';
        foreach ($body['data'] as $refund) {
            if (!is_array($refund)) return ['success' => false, 'error' => 'Invalid refund reconciliation response'];
            $last_id = array_string_value($refund, 'id');
            $metadata = is_array($refund['metadata'] ?? null) ? $refund['metadata'] : [];
            if (array_string_value($metadata, 'bdta_refund_operation') !== $operation_key) continue;
            if ($match !== [] || $last_id === '' || array_string_value($refund, 'payment_intent') !== $payment_intent_id
                || safe_int($refund['amount'] ?? 0) !== (int) round($amount * 100)
                || array_string_value($refund, 'currency') !== $currency
                || !in_array(array_string_value($refund, 'status'), ['succeeded', 'pending', 'requires_action'], true)) {
                return ['success' => false, 'error' => 'Ambiguous Stripe refund; manual reconciliation required'];
            }
            $match = ['success' => true, 'refund_id' => $last_id, 'status' => array_string_value($refund, 'status')];
        }
        $has_more = $body['has_more'] ?? null;
        if ($has_more === false) {
            return $match !== [] ? $match : ['success' => false, 'error' => 'No matching Stripe refund; manual reconciliation required'];
        }
        if ($has_more !== true || $last_id === '' || $last_id === $cursor) break;
        $cursor = $last_id;
    }
    return ['success' => false, 'error' => 'Incomplete Stripe refund reconciliation; manual review required'];
}
