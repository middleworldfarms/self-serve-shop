<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once 'includes/create_woocommerce_order.php';

function processAppleGooglePay($order_id, $amount, $token, $paymentMethod = 'apple_pay') {
    global $db;
    
    // For Apple Pay/Google Pay, we'll use Stripe as the payment processor
    // Get Stripe credentials from settings
    $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'stripe_secret_key'");
    $stripe_secret_key = $stmt->fetchColumn();
    
    // Set up Stripe
    \Stripe\Stripe::setApiKey($stripe_secret_key);
    
    try {
        // Create a payment method using the token
        $payment_intent = \Stripe\PaymentIntent::create([
            'amount' => (int)($amount * 100), // In cents
            'currency' => 'gbp',
            'payment_method_data' => [
                'type' => 'card',
                'card' => [
                    'token' => $token
                ]
            ],
            'confirmation_method' => 'manual',
            'confirm' => true,
            'description' => "Order: $order_id",
            'metadata' => [
                'order_id' => $order_id
            ]
        ]);
        
        if ($payment_intent->status === 'succeeded') {
            create_woocommerce_order($order_id);
            return [
                'success' => true,
                'transaction_id' => $payment_intent->id,
                'status' => $payment_intent->status
            ];
        } else if ($payment_intent->status === 'requires_action') {
            return [
                'success' => false,
                'requires_action' => true,
                'client_secret' => $payment_intent->client_secret
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Payment failed: ' . $payment_intent->status
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Payment processing error: ' . $e->getMessage()
        ];
    }
}
