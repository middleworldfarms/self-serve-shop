<?php
// filepath: /var/www/vhosts/middleworld.farm/httpdocs/insert-woo-id.php

echo "<h1>Manual Cart Fix Helper</h1>";

$index_file = __DIR__ . '/index.php';
$backup_file = __DIR__ . '/index.php.bak.' . date('YmdHis');
copy($index_file, $backup_file);

echo "<p>Created backup: " . basename($backup_file) . "</p>";

$content = file_get_contents($index_file);

// First attempt - try to find the cart_items assignment by looking for 'quantity' => $quantity
$pattern = '/\'quantity\'\s*=>\s*\$quantity(,|\s*\])/';
$replacement = "'quantity' => \$quantity, 'woocommerce_id' => \$product['woocommerce_id']$1";

$new_content = preg_replace($pattern, $replacement, $content);

if ($new_content != $content) {
    file_put_contents($index_file, $new_content);
    echo "<p style='color:green'>✅ Success! Added woocommerce_id to cart items in index.php</p>";
    echo "<p>Now try a cash payment again - it should appear in WooCommerce</p>";
} else {
    // Second attempt - look for common variations
    $pattern = '/\'price\'\s*=>\s*(\$[^,]+)(,|\s*\])/';
    $replacement = "'price' => $1, 'woocommerce_id' => \$product['woocommerce_id']$2";
    
    $new_content = preg_replace($pattern, $replacement, $content);
    
    if ($new_content != $content) {
        file_put_contents($index_file, $new_content);
        echo "<p style='color:green'>✅ Success! Added woocommerce_id after price in cart items</p>";
    } else {
        echo "<h2 style='color:red'>❌ Could not automatically add woocommerce_id</h2>";
        echo "<p>Please manually edit index.php and find the cart_items assignment.</p>";
    }
}

// Also update the get_products.php file
$product_file = __DIR__ . '/includes/get_products.php';
if (file_exists($product_file)) {
    $product_content = file_get_contents($product_file);
    $backup_product = $product_file . '.bak.' . date('YmdHis');
    copy($product_file, $backup_product);
    
    // Replace product details array
    $pattern = '/return\s*\[\s*\'id\'\s*=>\s*\$product\[\'id\'\](.*?)\'price\'\s*=>\s*([^,]+)/s';
    $replacement = "return ['id' => \$product['id']$1'woocommerce_id' => \$product['woocommerce_id'], 'price' => $2";
    
    $new_product_content = preg_replace($pattern, $replacement, $product_content);
    
    if ($new_product_content != $product_content) {
        file_put_contents($product_file, $new_product_content);
        echo "<p style='color:green'>✅ Updated get_products.php to include woocommerce_id</p>";
    } else {
        echo "<p style='color:orange'>⚠️ Could not update get_products.php automatically</p>";
    }
}