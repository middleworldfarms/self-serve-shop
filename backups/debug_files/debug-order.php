<?php
// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>Order Processing Debug</h1>";

// Create database connection explicitly
require_once 'config.php';

// Create PDO connection manually
try {
    // Create database connection
    echo "<p>Creating database connection...</p>";
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get a product with WooCommerce ID
    echo "<p>Looking for a product with WooCommerce ID...</p>";
    $stmt = $db->query("SELECT id, name, price, woocommerce_id FROM sss_products WHERE woocommerce_id IS NOT NULL LIMIT 1");
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        die("<p>No products found with WooCommerce ID. Please check your products table.</p>");
    }
    
    echo "<p>Using product: {$product['name']} (ID: {$product['id']}, WooCommerce ID: {$product['woocommerce_id']})</p>";
    
    // Build a test order
    $order_details = [
        'customer_name' => 'Test Debug',
        'customer_email' => '',
        'total_amount' => $product['price'],
        'items' => [
            [
                'id' => $product['id'],
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => 1,
                'woocommerce_id' => $product['woocommerce_id']
            ]
        ]
    ];
    
    echo "<p>Created test order details</p>";
    echo "<pre>" . htmlspecialchars(json_encode($order_details, JSON_PRETTY_PRINT)) . "</pre>";
    
    // Process payment
    require_once 'payments/cash-payment.php';
    
    echo "<p>Calling process_cash_payment directly...</p>";
    $payment_result = process_cash_payment($order_details);
    
    echo "<p>Payment Result:</p>";
    echo "<pre>" . htmlspecialchars(json_encode($payment_result, JSON_PRETTY_PRINT)) . "</pre>";
    
    // If successful, check the created order
    if ($payment_result['success']) {
        $order_id = $payment_result['order_id'];
        
        // Check order in database
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<p>Order created in database:</p>";
        echo "<pre>" . htmlspecialchars(json_encode($order, JSON_PRETTY_PRINT)) . "</pre>";
        
        // Check order items
        $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p>Order items in database:</p>";
        echo "<pre>" . htmlspecialchars(json_encode($items, JSON_PRETTY_PRINT)) . "</pre>";
        
        // Create WooCommerce order
        echo "<p>Creating WooCommerce order...</p>";
        require_once 'includes/create_woocommerce_order.php';
        $woo_order_id = create_woocommerce_order($order_id);
        
        if ($woo_order_id) {
            echo "<p>✅ SUCCESS! WooCommerce order #{$woo_order_id} created.</p>";
        } else {
            echo "<p>❌ Failed to create WooCommerce order.</p>";
        }
    } else {
        echo "<p>❌ Failed to create local order: " . ($payment_result['error'] ?? 'Unknown error') . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p>ERROR: " . $e->getMessage() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
    echo "<p>Trace: <pre>" . $e->getTraceAsString() . "</pre></p>";
}
?>