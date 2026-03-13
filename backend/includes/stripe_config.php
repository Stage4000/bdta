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
