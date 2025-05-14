<?php
require_once 'config.php';

echo "<h1>Index.php Cart Code Analysis</h1>";

$index_file = __DIR__ . '/index.php';
$content = file_get_contents($index_file);

// Find the add to cart logic
echo "<h2>Add to Cart Logic in index.php:</h2>";

$cart_pattern = '/if\s*\(\s*isset\s*\(\s*\$_POST\s*\[\s*[\'"]add_to_cart[\'"]\s*\]\s*\)\s*\)(.*?)}/s';
if (preg_match($cart_pattern, $content, $matches)) {
    echo "<pre>" . htmlspecialchars($matches[0]) . "</pre>";
    
    echo "<h3>This is where we need to add the WooCommerce ID</h3>";
    
    // Locate the cart_items section
    $cart_items_pattern = '/\$_SESSION\s*\[\s*[\'"]cart_items[\'"]\s*\]\s*\[\s*\$product_id\s*\]\s*=\s*\[(.*?)\]/s';
    if (preg_match($cart_items_pattern, $matches[0], $cart_items_match)) {
        echo "<p>Current cart_items array:</p>";
        echo "<pre>" . htmlspecialchars($cart_items_match[0]) . "</pre>";
        
        echo "<p>We need to add the WooCommerce ID to this array.</p>";
    }
} else {
    echo "<p>Could not find add_to_cart logic in index.php</p>";
}

// Create a patch file
$patch_code = <<<'EOD'
<?php
// filepath: /var/www/vhosts/middleworld.farm/httpdocs/fix-cart-woo-id.php

// First, make a backup of index.php
$index_file = __DIR__ . '/index.php';
$backup_file = __DIR__ . '/index.php.bak.' . date('YmdHis');
copy($index_file, $backup_file);

echo "<h1>Adding WooCommerce ID to Cart</h1>";
echo "<p>Created backup: " . basename($backup_file) . "</p>";

// Get index.php content
$content = file_get_contents($index_file);

// Replace cart_items array to include woocommerce_id
$cart_items_pattern = '/\'id\'\s*=>\s*\$product\[\'id\'\],\s*\'name\'\s*=>\s*\$product\[\'name\'\],\s*\'price\'\s*=>\s*\$product\[\'price\'\],\s*\'quantity\'\s*=>\s*\$quantity/';
$replacement = "'id' => \$product['id'], 'name' => \$product['name'], 'price' => \$product['price'], 'quantity' => \$quantity, 'woocommerce_id' => \$product['woocommerce_id']";

$new_content = preg_replace($cart_items_pattern, $replacement, $content);

if ($new_content != $content) {
    // Save modified content
    file_put_contents($index_file, $new_content);
    echo "<p>✅ Successfully updated index.php to include WooCommerce ID in cart items!</p>";
    
    // Also check get_product_details function to ensure it returns woocommerce_id
    $product_file = __DIR__ . '/includes/get_products.php';
    if (file_exists($product_file)) {
        $product_content = file_get_contents($product_file);
        $pattern = '/\'id\'\s*=>\s*\$product\[\'id\'\],\s*\'name\'\s*=>\s*\$product\[\'name\'\],\s*\'price\'\s*=>/';
        $replacement = "'id' => \$product['id'], 'name' => \$product['name'], 'woocommerce_id' => \$product['woocommerce_id'], 'price' =>";
        
        $new_product_content = preg_replace($pattern, $replacement, $product_content);
        
        if ($new_product_content != $product_content) {
            $backup_product = $product_file . '.bak.' . date('YmdHis');
            copy($product_file, $backup_product);
            file_put_contents($product_file, $new_product_content);
            echo "<p>✅ Also updated get_products.php to return WooCommerce ID!</p>";
        }
    }
    
    echo "<p>Now try adding products to your cart and making a cash payment.</p>";
    echo "<p>The WooCommerce ID will be included and orders should sync correctly.</p>";
} else {
    echo "<p>❌ Could not update index.php - pattern not found.</p>";
    echo "<p>You may need to manually add the WooCommerce ID to the cart items array.</p>";
    
    echo "<h2>Manual Instructions:</h2>";
    echo "<p>Find the code in index.php that adds items to the cart, and add 'woocommerce_id' => \$product['woocommerce_id'] to the array.</p>";
}
EOD;

file_put_contents("fix-cart-woo-id.php", $patch_code);
echo "<p><a href='fix-cart-woo-id.php'>Fix the Add to Cart Code</a></p>";
?>