<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

function processStripePayment($order_id, $amount, $payment_intent_id) {
    global $db;
    
    // Get Stripe credentials from settings
    $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'stripe_secret_key'");
    $stripe_secret_key = $stmt->fetchColumn();
    
    // Set up Stripe
    $stripe = new \Stripe\StripeClient($stripe_secret_key);
    
    try {
        // Retrieve the payment intent
        $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
        
        // If already successful, return the success response
        if ($payment_intent->status === 'succeeded') {
            return [
                'success' => true,
                'transaction_id' => $payment_intent->id,
                'status' => $payment_intent->status
            ];
        }
        
        // Otherwise, confirm the payment intent if needed
        if ($payment_intent->status === 'requires_confirmation') {
            $payment_intent->confirm();
        }
        
        // Check final status
        if ($payment_intent->status === 'succeeded') {
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
