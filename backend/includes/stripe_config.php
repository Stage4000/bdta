<?php
/**
 * Brook's Dog Training Academy - Stripe Configuration
 * 
 * Install Stripe PHP SDK with: composer require stripe/stripe-php
 */

// Stripe API keys (use environment variables in production!)
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_YOUR_PUBLISHABLE_KEY'); // Replace with your key
define('STRIPE_SECRET_KEY', 'sk_test_YOUR_SECRET_KEY'); // Replace with your key

// Currency
define('STRIPE_CURRENCY', 'usd');

// Initialize Stripe library if available
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
}

/**
 * Create a Stripe payment intent
 */
function createPaymentIntent($amount, $description, $metadata = []) {
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
