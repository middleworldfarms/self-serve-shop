<?php
require_once 'config.php';
require_once 'payments/cash-payment.php';

// Get a product to test with
$db = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
    DB_USER,
    DB_PASS
);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $db->query("SELECT id, name, price, woocommerce_id FROM sss_products 
                   WHERE woocommerce_id IS NOT NULL LIMIT 1");
$product = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h1>Testing Cash Payment</h1>";

if (!$product) {
    die("No products with WooCommerce ID found!");
}
 
// Create test order
$order_details = [
    'customer_name' => 'Test Customer',
    'customer_email' => 'test@example.com',
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

$result = process_cash_payment($order_details);

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