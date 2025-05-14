<?php
require_once 'config.php';

echo "<h1>Cart Debug Information</h1>";

// Display current cart contents
echo "<h2>Current Cart Contents:</h2>";
echo "<pre>";
print_r($_SESSION['cart'] ?? []);
print_r($_SESSION['cart_items'] ?? []);
echo "</pre>";

// Check the add to cart function
echo "<h2>Add to Cart Function:</h2>";

// Find the add to cart function
$add_to_cart_file = __DIR__ . '/includes/cart_functions.php';
if (file_exists($add_to_cart_file)) {
    $code = file_get_contents($add_to_cart_file);
    if (preg_match('/function\s+add_to_cart\s*\([^)]*\)\s*{(.+?)}(?=\s*function|\s*$)/s', $code, $matches)) {
        echo "<pre>" . htmlspecialchars($matches[0]) . "</pre>";
    } else {
        echo "<p>Could not find add_to_cart function in cart_functions.php</p>";
    }
} else {
    echo "<p>cart_functions.php file not found</p>";
}

// Create a fixed add to cart function that includes WooCommerce ID
echo "<h2>Fixed Add to Cart Function:</h2>";
echo "<pre>
function add_to_cart(\$product_id, \$quantity = 1) {
    global \$db;
    
    // Get product details
    \$stmt = \$db->prepare(\"SELECT id, name, price, woocommerce_id FROM sss_products WHERE id = ?\");
    \$stmt->execute([\$product_id]);
    \$product = \$stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!\$product) {
        return false;
    }
    
    // Initialize cart if needed
    if (!isset(\$_SESSION['cart'])) {
        \$_SESSION['cart'] = [];
    }
    if (!isset(\$_SESSION['cart_items'])) {
        \$_SESSION['cart_items'] = [];
    }
    
    // Add or update cart item
    if (isset(\$_SESSION['cart'][\$product_id])) {
        \$_SESSION['cart'][\$product_id] += \$quantity;
    } else {
        \$_SESSION['cart'][\$product_id] = \$quantity;
        
        // Add product details to cart_items
        \$_SESSION['cart_items'][\$product_id] = [
            'id' => \$product['id'],
            'name' => \$product['name'],
            'price' => \$product['price'],
            'quantity' => \$quantity,
            'woocommerce_id' => \$product['woocommerce_id'] // Important: Store WooCommerce ID
        ];
    }
    
    return true;
}
</pre>";

// Check add_to_cart usage
$files = glob(__DIR__ . '/**/*.php');
$usages = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    if (preg_match_all('/add_to_cart\s*\(([^)]+)\)/', $content, $matches, PREG_SET_ORDER)) {
        $file_relative = str_replace(__DIR__ . '/', '', $file);
        $usages[$file_relative] = $matches;
    }
}

echo "<h2>add_to_cart Usage in Files:</h2>";
echo "<ul>";
foreach ($usages as $file => $matches) {
    echo "<li>$file<ul>";
    foreach ($matches as $match) {
        echo "<li>" . htmlspecialchars($match[0]) . "</li>";
    }
    echo "</ul></li>";
}
echo "</ul>";

// Check cash payment integration with WooCommerce
echo "<h2>Cash Payment Processing:</h2>";
$cash_payment_file = __DIR__ . '/payments/cash-payment.php';
if (file_exists($cash_payment_file)) {
    $code = file_get_contents($cash_payment_file);
    echo "<pre>" . htmlspecialchars(substr($code, 0, 1000)) . "...</pre>";
    
    // Check if the cash payment function calls create_woocommerce_order
    if (strpos($code, 'create_woocommerce_order') !== false) {
        echo "<p>✅ Cash payment function calls create_woocommerce_order</p>";
    } else {
        echo "<p>❌ Cash payment function does NOT call create_woocommerce_order</p>";
    }
    
    // Check if the cash payment function gets WooCommerce IDs
    if (strpos($code, 'woocommerce_id') !== false) {
        echo "<p>✅ Cash payment function references woocommerce_id</p>";
    } else {
        echo "<p>❌ Cash payment function does NOT reference woocommerce_id</p>";
    }
}
?>