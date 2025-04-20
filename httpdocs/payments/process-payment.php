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
            require_once __DIR__ . '/woo-funds-payment.php';
            $result = processWooFundsPayment($order_id, $amount, $payment_data['customer_email']);
            log_order_event($order_id, 'payment_completed', [
                'method' => $payment_method,
                'amount' => $amount,
                'transaction_id' => $payment_data['customer_email']
            ]);
            
            // Get order items and customer info for WooCommerce
            $items = get_order_items($order_id);
            $customer_info = get_order_customer($order_id);
            
            // Sync to WooCommerce
            sync_to_woocommerce($order_id, $amount, $payment_method, $items, $customer_info);
            
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
    $stmt = $db->prepare("SELECT oi.*, p.name, p.woo_product_id FROM order_items oi 
                          JOIN sss_products p ON oi.product_id = p.id 
                          WHERE oi.order_id = ?");
    $stmt->execute([$order_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_order_customer($order_id) {
    global $db;
    $stmt = $db->prepare("SELECT customer_name as name, customer_email as email FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
