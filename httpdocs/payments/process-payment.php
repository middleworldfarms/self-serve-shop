<?php
// Core requirements
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/create_woocommerce_order.php';

// Include active payment methods
require_once __DIR__ . '/cash-payment.php';
require_once __DIR__ . '/woo-funds-payment.php';

// Simple logger function
function log_payment($message, $data = []) {
    error_log("PAYMENT: $message " . json_encode($data));
}

/**
 * Process a payment based on the selected payment method
 */
function process_payment($payment_method, $order_details) {
    log_payment("Starting payment processing", ["method" => $payment_method]);
    
    // Process based on payment method
    switch ($payment_method) {
        case 'cash':
        case 'manual':
            log_payment("Processing cash payment");
            $result = process_cash_payment($order_details);
            return ensure_woocommerce_order($result, $order_details);
            
        case 'woo_funds':
            log_payment("Processing account credit payment");
            if (!file_exists(__DIR__ . '/woo-funds-payment.php')) {
                return [
                    'success' => false,
                    'error' => 'Account credit payment processor not available'
                ];
            }
            $result = process_woo_funds_payment($order_details);
            return ensure_woocommerce_order($result, $order_details);
            
        case 'stripe':
        case 'card':
        case 'google_pay':
        case 'apple_pay':
            // All these methods route to Stripe
            log_payment("Processing Stripe payment", ["subtype" => $payment_method]);
            // If you have a stripe-payment.php file, include it
            if (file_exists(__DIR__ . '/stripe-payment.php')) {
                require_once __DIR__ . '/stripe-payment.php';
                $result = process_stripe_payment($order_details);
                return ensure_woocommerce_order($result, $order_details);
            } else {
                return [
                    'success' => false,
                    'error' => 'Stripe payment processor not available'
                ];
            }
            
        default:
            log_payment("Invalid payment method", ["method" => $payment_method]);
            return [
                'success' => false,
                'error' => 'Invalid payment method: ' . htmlspecialchars($payment_method)
            ];
    }
}

/**
 * Ensure a WooCommerce order is created for this payment
 */
function ensure_woocommerce_order($payment_result, $order_details) {
    // Only proceed if payment was successful
    if (!$payment_result['success']) {
        return $payment_result;
    }
    
    // Get order ID from payment result
    $order_id = $payment_result['order_id'] ?? null;
    if (!$order_id) {
        return $payment_result;
    }
    
    // Create WooCommerce order
    $woo_order_id = create_woocommerce_order($order_id);
    
    // Add WooCommerce order ID to result
    $payment_result['woocommerce_order_id'] = $woo_order_id;
    
    return $payment_result;
}
