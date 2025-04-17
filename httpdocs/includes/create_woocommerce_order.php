<?php
/**
 * Create a WooCommerce order from a successful payment
 */
function create_woocommerce_order($payment_intent_id) {
    if (empty($payment_intent_id)) {
        return false;
    }
    
    // Load Stripe PHP library
    require_once 'vendor/autoload.php';
    
    try {
        // Set Stripe API key
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        
        // Retrieve the payment intent to verify payment status
        $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
        
        // Check if payment was successful
        if ($payment_intent->status !== 'succeeded') {
            error_log("Payment not successful: " . $payment_intent_id);
            return false;
        }
        
        // Get customer information from payment intent
        $customer_name = $payment_intent->metadata->customer_name ?? 'Guest';
        $customer_email = $payment_intent->receipt_email ?? '';
        
        // Use the WooCommerce REST API to create the order
        $order_data = create_order_via_api($payment_intent, $customer_name, $customer_email);
        
        if (isset($order_data['id'])) {
            return $order_data['id'];
        }
        
        return false;
    } catch (\Exception $e) {
        error_log("Error creating WooCommerce order: " . $e->getMessage());
        return false;
    }
}

/**
 * Create an order via the WooCommerce REST API
 */
function create_order_via_api($payment_intent, $customer_name, $customer_email) {
    // Check if we have cart items in the session
    if (!isset($_SESSION['cart_items']) || empty($_SESSION['cart_items'])) {
        error_log("No cart items found in session");
        return false;
    }
    
    // Get cart items
    $line_items = [];
    foreach ($_SESSION['cart_items'] as $item) {
        $line_items[] = [
            'product_id' => $item['id'],
            'quantity' => $item['quantity']
        ];
    }
    
    // Prepare order data
    $order = [
        'payment_method' => 'stripe',
        'payment_method_title' => 'Credit Card (Stripe)',
        'set_paid' => true,
        'billing' => [
            'first_name' => $customer_name,
            'email' => $customer_email
        ],
        'shipping' => [
            'first_name' => $customer_name
        ],
        'line_items' => $line_items,
        'meta_data' => [
            [
                'key' => '_stripe_payment_intent',
                'value' => $payment_intent->id
            ],
            [
                'key' => '_self_serve_purchase',
                'value' => 'yes'
            ]
        ]
    ];
    
    // Make the API request to WooCommerce
    $response = wp_api_request('POST', 'orders', $order);
    
    return $response;
}

/**
 * Make a request to the WooCommerce REST API
 */
function wp_api_request($method, $endpoint, $data = null) {
    $url = WC_STORE_URL . '/wp-json/wc/v3/' . $endpoint;
    
    $args = [
        'method' => $method,
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode(WC_CONSUMER_KEY . ':' . WC_CONSUMER_SECRET)
        ]
    ];
    
    if ($data !== null && ($method === 'POST' || $method === 'PUT')) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = json_encode($data);
    }
    
    // Make the API request using cURL
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode(WC_CONSUMER_KEY . ':' . WC_CONSUMER_SECRET),
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);
    
    if ($data !== null && ($method === 'POST' || $method === 'PUT')) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $response = curl_exec($curl);
    $err = curl_error($curl);
    
    curl_close($curl);
    
    if ($err) {
        error_log("cURL Error: " . $err);
        return false;
    }
    
    return json_decode($response, true);
}