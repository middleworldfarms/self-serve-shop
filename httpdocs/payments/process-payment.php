<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once '../includes/logger.php';

function processPayment($order_id, $amount, $payment_method, $payment_data = []) {
    switch ($payment_method) {
        case 'manual':
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => null
            ]);
            return ['success' => true, 'method' => 'manual'];
            
        case 'stripe':
            require_once __DIR__ . '/stripe-payment.php';
            $result = processStripePayment($order_id, $amount, $payment_data['payment_intent_id']);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => $payment_data['payment_intent_id']
            ]);
            return $result;
            
        case 'paypal':
            require_once __DIR__ . '/paypal-payment.php';
            $result = processPayPalPayment($order_id, $amount, $payment_data['order_id']);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => $payment_data['order_id']
            ]);
            return $result;
            
        case 'woo_funds':
            require_once __DIR__ . '/woo-funds-payment.php';
            $result = processWooFundsPayment($order_id, $amount, $payment_data['customer_email']);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => $payment_data['customer_email']
            ]);
            return $result;
            
        case 'gocardless':
            require_once __DIR__ . '/gocardless-payment.php';
            $result = processGoCardlessPayment($order_id, $amount, $payment_data);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => null
            ]);
            return $result;
            
        case 'apple_pay':
        case 'google_pay':
            require_once __DIR__ . '/wallet-payment.php';
            $result = processAppleGooglePay($order_id, $amount, $payment_data['token'], $payment_method);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => $payment_data['token']
            ]);
            return $result;
            
        default:
            return ['success' => false, 'error' => 'Unknown payment method'];
    }
}
