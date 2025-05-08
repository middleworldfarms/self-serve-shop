<?php
/**
 * Create a WooCommerce order from a successful payment or account funds
 */
function create_woocommerce_order($payment_intent_id = null, $manual_order_data = null) {
    // If manual order data is provided (for Woo Funds, cash, etc)
    if ($manual_order_data) {
        // Use the WooCommerce REST API to create the order
        $order_data = create_order_via_api_manual($manual_order_data);

        if (isset($order_data['id'])) {
            return $order_data['id'];
        }
        return false;
    }

    // Stripe flow (default)
    if (empty($payment_intent_id)) {
        return false;
    }

    try {
        // Only load Stripe library for card payments
        require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
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
 * Create an order via the WooCommerce REST API (Stripe)
 */
function create_order_via_api($payment_intent, $customer_name, $customer_email) {
    if (!isset($_SESSION['cart_items']) || empty($_SESSION['cart_items'])) {
        error_log("No cart items found in session");
        return false;
    }

    $line_items = [];
    foreach ($_SESSION['cart_items'] as $item) {
        $line_items[] = [
            'product_id' => $item['id'],
            'quantity' => $item['quantity']
        ];
    }

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

    $response = wp_api_request('POST', 'orders', $order);

    return $response;
}

/**
 * Create an order via the WooCommerce REST API (Manual, e.g. Woo Funds)
 */
function create_order_via_api_manual($manual_order_data) {
    // You must provide all required order fields in $manual_order_data
    $response = wp_api_request('POST', 'orders', $manual_order_data);
    return $response;
}

/**
 * Make a request to the WooCommerce REST API
 */
function wp_api_request($method, $endpoint, $data = null) {
    if (!function_exists('get_settings')) {
        require_once dirname(__DIR__) . '/config.php';
    }
    $settings = get_settings();
    $wc_url = rtrim($settings['woo_shop_url'] ?? 'https://www.middleworldfarms.org', '/');
    $wc_key = $settings['woo_consumer_key'] ?? '';
    $wc_secret = $settings['woo_consumer_secret'] ?? '';

    $url = $wc_url . '/wp-json/wc/v3/' . $endpoint;

    $auth = base64_encode($wc_key . ':' . $wc_secret);

    $headers = [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    error_log("WooCommerce API endpoint: $url");

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers
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