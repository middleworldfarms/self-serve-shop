<?php
require_once 'config.php';

try {
    // Check if cart exists and has items
    if (!isset($_SESSION['cart']) || empty($_SESSION['cart']) || !isset($_SESSION['cart_total'])) {
        throw new Exception('Invalid cart');
    }

    // Get the JSON payload
    $json_data = file_get_contents('php://input');
    $data = json_decode($json_data, true);

    // Check required fields
    if (!isset($data['name']) || !isset($data['email'])) {
        throw new Exception('Missing required fields');
    }

    // Get the payment method from the form
    $payment_method = $_POST['payment_method'] ?? 'manual';

    // Initialize variables
    $payment_status = 'pending';
    $payment_error = '';
    $client_secret = '';

    // Process based on payment method
    if ($payment_method === 'stripe' && ENABLE_STRIPE_PAYMENT) {
        // Stripe payment processing
        require_once 'vendor/autoload.php'; // Include Stripe PHP library
        
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        
        try {
            // Create a PaymentIntent
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $_SESSION['cart_total'] * 100, // amount in cents
                'currency' => strtolower(CURRENCY), // change to your currency
                'description' => 'Middle World Farms - Self-Serve Purchase',
                'payment_method' => $_POST['stripeToken'],
                'confirmation_method' => 'manual',
                'confirm' => true,
            ]);
            
            // Payment succeeded
            $payment_status = 'paid';
            $client_secret = $intent->client_secret;
        } catch (\Stripe\Exception\CardException $e) {
            // Card was declined
            $payment_status = 'failed';
            $payment_error = $e->getMessage();
        } catch (Exception $e) {
            // Other error
            $payment_status = 'failed';
            $payment_error = 'An error occurred with your payment.';
        }
    } elseif ($payment_method === 'paypal' && ENABLE_PAYPAL_PAYMENT) {
        // PayPal payment processing
        $paypal_order_id = $_POST['paypal_order_id'] ?? '';
        
        if (!empty($paypal_order_id)) {
            // PayPal payment was successful
            $payment_status = 'paid';
        } else {
            // PayPal payment failed or wasn't completed
            $payment_status = 'failed';
            $payment_error = 'PayPal payment was not completed.';
        }
    } elseif ($payment_method === 'square' && ENABLE_SQUARE_PAYMENT) {
        // Square payment processing
        $square_nonce = $_POST['square_nonce'] ?? '';
        
        if (!empty($square_nonce)) {
            // Square payment was successful
            $payment_status = 'paid';
        } else {
            // Square payment failed
            $payment_status = 'failed';
            $payment_error = 'Square payment was not completed.';
        }
    } elseif ($payment_method === 'bank' && ENABLE_BANK_TRANSFER) {
        // Bank transfer - always pending until manually verified
        $payment_status = 'pending';
        $payment_reference = BANK_REFERENCE_PREFIX . time();
        // Store the reference for later matching
        $_SESSION['bank_reference'] = $payment_reference;
    } elseif ($payment_method === 'wallet' && (ENABLE_GOOGLE_PAY || ENABLE_APPLE_PAY)) {
        // Google Pay or Apple Pay processing
        // These typically use Stripe as the backend processor
        $payment_status = 'paid';
    } else {
        // Manual payment (honor box)
        $payment_status = 'pending';
    }

    // Save order with payment method and status
    $sql = "INSERT INTO orders (name, email, phone, address, total, payment_method, payment_status, order_notes, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $data['name'], 
        $data['email'], 
        $data['phone'] ?? '', 
        $data['address'] ?? '', 
        $_SESSION['cart_total'], 
        $payment_method, 
        $payment_status, 
        $data['notes'] ?? ''
    ]);

    // Get the order ID
    $order_id = $db->lastInsertId();

    // Add to process_payment.php after order is created
    $order_details = "Order #$order_id\n";
    $order_details .= "Total: " . CURRENCY_SYMBOL . number_format($_SESSION['cart_total'], 2) . "\n";
    $order_details .= "Payment Method: $payment_method\n";
    $order_details .= "Status: $payment_status\n";

    $to = $data['email'];
    $subject = "Order Confirmation - " . SHOP_NAME;
    $message = "Thank you for your order!\n\n$order_details";
    $headers = "From: " . SHOP_EMAIL;

    mail($to, $subject, $message, $headers);

    // Return response based on the payment status
    $response = [
        'status' => $payment_status,
        'order_id' => $order_id
    ];

    // Add client secret for Stripe if available
    if (!empty($client_secret)) {
        $response['clientSecret'] = $client_secret;
    }

    // Add error message if there was a problem
    if (!empty($payment_error)) {
        $response['error'] = $payment_error;
    }

    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Update the processPayment function in process-payment.php

function processPayment($order_id, $amount, $payment_method, $payment_data = []) {
    switch ($payment_method) {
        // Existing payment methods
        case 'manual':
            return ['success' => true, 'method' => 'manual'];
            
        case 'stripe':
            require_once __DIR__ . '/stripe-payment.php';
            return processStripePayment($order_id, $amount, $payment_data['payment_intent_id']);
            
        case 'apple_pay':
        case 'google_pay':
            require_once __DIR__ . '/wallet-payment.php';
            return processAppleGooglePay($order_id, $amount, $payment_data['token'], $payment_method);
            
        // New WooCommerce Funds payment method
        case 'woo_funds':
            require_once __DIR__ . '/woo-funds-payment.php';
            return processWooFundsPayment($order_id, $amount, $payment_data['customer_email']);
            
        case 'gocardless':
            require_once __DIR__ . '/gocardless-payment.php';
            return processGoCardlessPayment($order_id, $amount, $payment_data);
            
        default:
            return ['success' => false, 'error' => 'Unknown payment method'];
    }
}
?>