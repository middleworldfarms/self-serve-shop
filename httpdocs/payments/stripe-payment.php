<?php
// filepath: /var/www/vhosts/middleworld.farm/httpdocs/payments/stripe-payment.php

require_once __DIR__ . '/../config.php';

// Check if vendor directory is at project root or one level up
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
} else {
    die("Stripe library not found. Please install using: composer require stripe/stripe-php");
}

require_once 'includes/create_woocommerce_order.php';

// Now use the Stripe library
function processStripePayment($order_id, $amount, $params = []) {
    global $db;
    
    try {
        $settings = get_settings();
        
        // Get Stripe API key based on test mode setting
        $test_mode = isset($settings['stripe_test_mode']) && $settings['stripe_test_mode'] == '1';
        $stripe_secret = $test_mode ? 
            ($settings['stripe_test_secret_key'] ?? '') : 
            ($settings['stripe_secret_key'] ?? '');
        
        if (empty($stripe_secret)) {
            return [
                'success' => false,
                'error' => 'Stripe API key not configured'
            ];
        }
        
        // Initialize Stripe
        \Stripe\Stripe::setApiKey($stripe_secret);
        
        // Get payment method ID from params
        $payment_method_id = $params['stripe_token'] ?? $params['payment_intent_id'] ?? '';
        
        if (empty($payment_method_id)) {
            return [
                'success' => false,
                'error' => 'Payment method ID missing'
            ];
        }
        
        // Convert amount to cents (Stripe uses smallest currency unit)
        $amount_in_cents = round($amount * 100);
        
        // Create a PaymentIntent
        $intent = \Stripe\PaymentIntent::create([
            'amount' => $amount_in_cents,
            'currency' => 'gbp',
            'payment_method' => $payment_method_id,
            'confirmation_method' => 'manual',
            'confirm' => true,
            'description' => "Order #$order_id",
            'metadata' => [
                'order_id' => $order_id
            ]
        ]);
        
        // Check if payment requires additional action
        if ($intent->status === 'requires_action' && 
            $intent->next_action->type === 'use_stripe_sdk') {
                
            // Tell the client to handle the action
            return [
                'success' => false,
                'requires_action' => true,
                'payment_intent_client_secret' => $intent->client_secret,
                'error' => 'This payment requires additional authentication'
            ];
        } else if ($intent->status === 'succeeded') {
            // Payment succeeded
            create_woocommerce_order($order_id); // or pass $order_number, or adapt as needed
            return [
                'success' => true,
                'transaction_id' => $intent->id
            ];
        } else {
            // Invalid status
            return [
                'success' => false,
                'error' => 'Payment failed: ' . $intent->status
            ];
        }
    } catch (\Stripe\Exception\ApiErrorException $e) {
        // Catch Stripe errors
        return [
            'success' => false,
            'error' => 'Stripe error: ' . $e->getMessage()
        ];
    } catch (\Exception $e) {
        // Catch any other errors
        return [
            'success' => false,
            'error' => 'Error: ' . $e->getMessage()
        ];
    }
}
