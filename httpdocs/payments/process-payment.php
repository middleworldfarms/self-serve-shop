<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/logger.php';

function wp_remote_post($url, $args = []) {
    $method = $args['method'] ?? 'POST';
    $headers = $args['headers'] ?? [];
    $timeout = $args['timeout'] ?? 30;
    $body = $args['body'] ?? '';
    
    $ch = curl_init($url);
    
    // Set method
    curl_setopt($ch, CURLOPT_POST, true);
    
    // Set headers
    $curl_headers = [];
    foreach ($headers as $key => $value) {
        $curl_headers[] = "$key: $value";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curl_headers);
    
    // Set timeout
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    
    // Set body
    if (!empty($body)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    
    // Other options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Execute request
    $response_body = curl_exec($ch);
    $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        return [
            'body' => null,
            'response' => [
                'code' => 0,
                'message' => $curl_error
            ],
            'error' => $curl_error
        ];
    }
    
    return [
        'body' => $response_body,
        'response' => [
            'code' => $status_code
        ]
    ];
}

function processPayment($order_id, $amount, $payment_method, $payment_data = []) {
    switch ($payment_method) {
        case 'manual':
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => null
            ]);
            
            // Get order items and customer info for WooCommerce
            $items = get_order_items($order_id);
            $customer_info = get_order_customer($order_id);
            
            // Sync to WooCommerce
            sync_to_woocommerce($order_id, $amount, $payment_method, $items, $customer_info);
            
            return ['success' => true, 'method' => 'manual'];
            
        case 'stripe':
            require_once __DIR__ . '/stripe-payment.php';
            $result = processStripePayment($order_id, $amount, $payment_data['payment_intent_id']);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => $payment_data['payment_intent_id']
            ]);
            
            // Get order items and customer info for WooCommerce
            $items = get_order_items($order_id);
            $customer_info = get_order_customer($order_id);
            
            // Sync to WooCommerce
            sync_to_woocommerce($order_id, $amount, $payment_method, $items, $customer_info);
            
            return $result;
            
        case 'paypal':
            require_once __DIR__ . '/paypal-payment.php';
            $result = processPayPalPayment($order_id, $amount, $payment_data['order_id']);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => $payment_data['order_id']
            ]);
            
            // Get order items and customer info for WooCommerce
            $items = get_order_items($order_id);
            $customer_info = get_order_customer($order_id);
            
            // Sync to WooCommerce
            sync_to_woocommerce($order_id, $amount, $payment_method, $items, $customer_info);
            
            return $result;
            
        case 'woo_funds':
            $settings = get_settings();
            
            // Build proper API endpoint URL
            $api_url = $settings['woo_shop_url'] ?? 'https://middleworldfarms.org';
            $api_url = rtrim($api_url, '/') . '/wp-json/middleworld/v1/funds';
            
            // Use configured API key from settings
            $api_key = $settings['woo_funds_api_key'] ?? 'Ffsh8yhsuZEGySvLrP0DihCDDwhPwk4h';
            
            // Debug logging
            error_log("WooFunds API call: $api_url with key length: " . strlen($api_key));
            
            // Prepare request data
            $request_data = [
                'action' => 'deduct',
                'email' => $payment_data['customer_email'],
                'amount' => $amount,
                'order_id' => $order_id,
                'description' => 'Self-serve shop purchase'
            ];
            
            // Make API request with proper headers
            $response = wp_remote_post($api_url, [
                'timeout' => 45,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WC-API-Key' => $api_key
                ],
                'body' => json_encode($request_data)
            ]);
            
            // Process response
            $result = [
                'success' => false,
                'error' => 'Connection failed to store API'
            ];
            
            if (is_array($response) && isset($response['body'])) {
                $body = json_decode($response['body'], true);
                
                if (!empty($body)) {
                    if (isset($body['success']) && $body['success'] === true) {
                        $result = [
                            'success' => true,
                            'transaction_id' => $body['transaction_id'] ?? '',
                            'new_balance' => $body['new_balance'] ?? 0
                        ];
                        
                        // Store balance in session for display on confirmation page
                        $_SESSION['woo_funds_balance'] = $body['new_balance'];
                        $_SESSION['payment_method'] = 'woo_funds';
                    } else {
                        $result['error'] = $body['error'] ?? $body['message'] ?? 'Unknown error processing account credit payment';
                    }
                }
            }
            
            return $result;
            
        case 'gocardless':
            require_once __DIR__ . '/gocardless-payment.php';
            $result = processGoCardlessPayment($order_id, $amount, $payment_data);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => null
            ]);
            
            // Get order items and customer info for WooCommerce
            $items = get_order_items($order_id);
            $customer_info = get_order_customer($order_id);
            
            // Sync to WooCommerce
            sync_to_woocommerce($order_id, $amount, $payment_method, $items, $customer_info);
            
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
            
            // Get order items and customer info for WooCommerce
            $items = get_order_items($order_id);
            $customer_info = get_order_customer($order_id);
            
            // Sync to WooCommerce
            sync_to_woocommerce($order_id, $amount, $payment_method, $items, $customer_info);
            
            return $result;
            
        default:
            return ['success' => false, 'error' => 'Unknown payment method'];
    }
}

function sync_to_woocommerce($order_id, $amount, $payment_method, $items, $customer_info = []) {
    global $db;
    
    // Get WooCommerce API settings
    $settings = get_settings();
    $woo_consumer_key = $settings['woo_consumer_key'] ?? '';
    $woo_consumer_secret = $settings['woo_consumer_secret'] ?? '';
    
    // Skip if WooCommerce integration isn't configured
    if (empty($woo_consumer_key) || empty($woo_consumer_secret)) {
        log_order_event($order_id, 'woo_sync_skipped', [
            'reason' => 'WooCommerce API credentials not configured'
        ]);
        return false;
    }
    
    // Format data for WooCommerce
    $woo_order = [
        'status' => 'completed',
        'payment_method' => $payment_method,
        'payment_method_title' => ucfirst($payment_method) . ' (Self-Serve Shop)',
        'set_paid' => true,
        'billing' => [
            'first_name' => $customer_info['name'] ?? 'Walk-in Customer',
            'email' => $customer_info['email'] ?? 'selfserve@middleworld.farm',
        ],
        'line_items' => [],
        'meta_data' => [
            [
                'key' => '_self_serve_shop_order',
                'value' => true
            ],
            [
                'key' => '_self_serve_shop_order_id',
                'value' => $order_id
            ]
        ]
    ];
    
    // Add products from order
    foreach ($items as $item) {
        $woo_order['line_items'][] = [
            'product_id' => $item['woo_product_id'] ?? 0,
            'quantity' => $item['quantity'],
            'subtotal' => $item['price'] * $item['quantity']
        ];
    }
    
    // Send to WooCommerce
    $woo_url = 'https://middleworldfarms.org/wp-json/wc/v3/orders';
    $ch = curl_init($woo_url);
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($woo_order));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($woo_consumer_key . ':' . $woo_consumer_secret)
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code >= 200 && $http_code < 300) {
        $response_data = json_decode($response, true);
        log_order_event($order_id, 'woo_sync_success', [
            'woo_order_id' => $response_data['id'] ?? 'unknown'
        ]);
        return true;
    } else {
        log_order_event($order_id, 'woo_sync_failed', [
            'http_code' => $http_code,
            'response' => substr($response, 0, 255)
        ]);
        return false;
    }
}

function get_order_items($order_id) {
    global $db;
    
    // Check if the cart items are stored in the session
    if (isset($_SESSION['cart_items'])) {
        return $_SESSION['cart_items']; 
    }
    
    // If not in session, try to get from orders table directly
    // This assumes orders table has a 'items' JSON column or similar
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order && isset($order['items'])) {
        // If items are stored as JSON
        if (is_string($order['items'])) {
            return json_decode($order['items'], true);
        }
        return $order['items'];
    }
    
    // Fallback to cart session if we have it
    if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
        require_once __DIR__ . '/../includes/get_products.php';
        $items = [];
        
        foreach ($_SESSION['cart'] as $product_id => $quantity) {
            $product = get_product_details($product_id);
            $items[] = [
                'product_id' => $product_id,
                'woo_product_id' => $product['woo_product_id'] ?? 0,
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $quantity
            ];
        }
        
        return $items;
    }
    
    // Last resort - empty array
    return [];
}

function get_order_customer($order_id) {
    global $db;
    $stmt = $db->prepare("SELECT customer_name as name, customer_email as email FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
