<?php
// Create a simple debug log file that we control
$log_file = __DIR__ . '/woo-debug.log';
file_put_contents($log_file, date('[Y-m-d H:i:s]') . " Starting WooCommerce debug session\n", FILE_APPEND);

// Create a function for logging
function woo_log($message, $data = null) {
    global $log_file;
    $output = date('[Y-m-d H:i:s]') . " $message";
    if ($data !== null) {
        $output .= "\n" . print_r($data, true);
    }
    file_put_contents($log_file, $output . "\n\n", FILE_APPEND);
}

// Modify create_woocommerce_order.php to use this log
require_once 'includes/create_woocommerce_order.php';

// Test it with a simple order
require_once 'config.php';
try {
    // Create DB connection
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get a test product
    $stmt = $db->query("SELECT id, name, price, woocommerce_id FROM sss_products WHERE woocommerce_id IS NOT NULL LIMIT 1");
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    woo_log("Testing with product", $product);
    
    // Show the current settings
    $settings = get_settings();
    woo_log("WooCommerce API settings", [
        'url' => $settings['woo_shop_url'] ?? 'not set',
        'key' => substr($settings['woo_consumer_key'] ?? 'not set', 0, 5) . '...',
        'secret' => substr($settings['woo_consumer_secret'] ?? 'not set', 0, 5) . '...'
    ]);
    
    // Try to create a WooCommerce order
    woo_log("Attempting to create a WooCommerce order directly");
    
    // Build order data
    $order_data = [
        'payment_method' => 'cash',
        'payment_method_title' => 'Cash Payment',
        'set_paid' => true,
        'status' => 'processing',
        'billing' => [
            'first_name' => 'Test Logger',
            'email' => 'test@example.com'
        ],
        'line_items' => [
            [
                'product_id' => (int)$product['woocommerce_id'], 
                'quantity' => 1
            ]
        ]
    ];
    
    // Create a test order in the local database first
    $stmt = $db->prepare("INSERT INTO orders (order_number, payment_method, customer_name, customer_email, total_amount, payment_status, order_status) VALUES (?, 'cash', ?, ?, ?, 'completed', 'completed')");
    $stmt->execute(['TEST-' . date('YmdHis'), 'Test Logger', 'test@example.com', $product['price']]);
    $test_order_id = $db->lastInsertId();
    
    woo_log("Created test order #{$test_order_id} in local database");
    
    // Try create_woocommerce_order
    $woo_order_id = create_woocommerce_order($test_order_id, $order_data);
    
    if ($woo_order_id) {
        woo_log("SUCCESS: Created WooCommerce order #{$woo_order_id}");
    } else {
        woo_log("FAILED: Could not create WooCommerce order");
    }
    
} catch (Exception $e) {
    woo_log("ERROR: " . $e->getMessage());
}

// Display log on screen
echo "<h1>WooCommerce Debug Log</h1>";
echo "<pre>";
echo file_get_contents($log_file);
echo "</pre>";
?>