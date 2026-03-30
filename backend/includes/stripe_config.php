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
function createStripeRefund(string $payment_intent_id, ?float $amount = null, array $metadata = []): array
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

    $ch = curl_init('https://api.stripe.com/v1/refunds');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post_fields),
        CURLOPT_USERPWD => scalar_string(STRIPE_SECRET_KEY) . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
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

    return [
        'success' => true,
        'refund_id' => array_string_value($refund, 'id'),
        'status' => array_string_value($refund, 'status'),
    ];
}
