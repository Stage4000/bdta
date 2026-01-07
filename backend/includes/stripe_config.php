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
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
}

/**
 * Check if Stripe is enabled and configured
 */
function isStripeEnabled() {
    return Settings::get('stripe_enabled', false) && !empty(STRIPE_SECRET_KEY);
}

/**
 * Create a Stripe payment intent
 */
function createPaymentIntent($amount, $description, $metadata = []) {
    if (!isStripeEnabled()) {
        return [
            'success' => false,
            'error' => 'Stripe is not enabled or configured'
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
        
        return [
            'success' => true,
            'client_secret' => $intent->client_secret,
            'payment_intent_id' => $intent->id
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
 */
function verifyPaymentIntent($payment_intent_id) {
    if (!isStripeEnabled()) {
        return [
            'success' => false,
            'error' => 'Stripe is not enabled or configured'
        ];
    }
    
    try {
        $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
        return [
            'success' => true,
            'status' => $intent->status,
            'amount' => $intent->amount / 100
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>
