<?php
// filepath: /var/www/vhosts/middleworld.farm/httpdocs/cash-payment-simple.php
require_once 'config.php';
require_once 'includes/create_woocommerce_order.php';

function process_cash_payment_simple($order_details) {
    global $db;
    
    try {
        // Generate a unique order number
        $order_number = 'CASH-' . date('Ymd') . '-' . rand(1000, 9999);
        
        // Create local order with correct column names
        $stmt = $db->prepare("
            INSERT INTO orders (
                order_number, 
                payment_method, 
                customer_name, 
                total_amount, 
                payment_status,
                order_status
            ) VALUES (?, 'cash', ?, ?, 'completed', 'completed')
        ");
        $stmt->execute([$order_number, $order_details['customer_name'], $order_details['total_amount']]);
        $local_order_id = $db->lastInsertId();
        
        // Create WooCommerce order
        $line_items = [];
        foreach ($order_details['items'] as $item) {
            $stmt = $db->prepare("SELECT woocommerce_id FROM sss_products WHERE id = ?");
            $stmt->execute([$item['id']]);
            $woo_id = $stmt->fetchColumn();
            
            if ($woo_id) {
                $line_items[] = [
                    'product_id' => (int)$woo_id,
                    'quantity' => (int)$item['quantity']
                ];
            }
        }
        
        $woo_order_data = [
            'payment_method' => 'cash',
            'payment_method_title' => 'Cash Payment',
            'set_paid' => true,
            'status' => 'processing',
            'billing' => [
                'first_name' => $order_details['customer_name'] ?? 'Cash Customer'
            ],
            'line_items' => $line_items
        ];
        
        $woo_order_id = create_woocommerce_order(null, $woo_order_data);
        
        return [
            'success' => true,
            'order_id' => $local_order_id,
            'woocommerce_order_id' => $woo_order_id
        ];
    } catch (PDOException $e) {
        error_log("Cash payment error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Test the function
$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
    DB_USER,
    DB_PASS
);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Get a test product
$stmt = $db->query("SELECT id, name, price, woocommerce_id FROM sss_products WHERE woocommerce_id IS NOT NULL LIMIT 1");
$product = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h1>Simple Cash Payment Test</h1>";

if (!$product) {
    die("No products with WooCommerce ID found!");
}

// Create test order
$order_details = [
    'customer_name' => 'Test Customer',
    'total_amount' => $product['price'],
    'items' => [
        [
            'id' => $product['id'],
            'name' => $product['name'],
            'price' => $product['price'],
            'quantity' => 1
        ]
    ]
];

$result = process_cash_payment_simple($order_details);

echo "<pre>";
print_r($result);
echo "</pre>";

if ($result['success']) {
    echo "<p>✅ Cash payment successful!</p>";
    echo "<p>Local Order ID: {$result['order_id']}</p>";
    echo "<p>WooCommerce Order ID: {$result['woocommerce_order_id']}</p>";
} else {
    echo "<p>❌ Cash payment failed!</p>";
    echo "<p>Error: {$result['error']}</p>";
}