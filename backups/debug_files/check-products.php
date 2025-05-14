<?php
require_once 'config.php';

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Count products
    $count = $db->query("SELECT COUNT(*) FROM sss_products")->fetchColumn();
    echo "<h2>Products in Database: $count</h2>";
    
    // Check products with WooCommerce IDs
    $woo_count = $db->query("SELECT COUNT(*) FROM sss_products WHERE woocommerce_id IS NOT NULL AND woocommerce_id > 0")->fetchColumn();
    echo "<h3>Products with WooCommerce IDs: $woo_count</h3>";
    
    // List some sample products
    echo "<h3>Sample Products:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Name</th><th>WooCommerce ID</th><th>woo_product_id</th></tr>";
    
    $products = $db->query("SELECT id, name, woocommerce_id, woo_product_id FROM sss_products LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($products as $p) {
        echo "<tr>";
        echo "<td>{$p['id']}</td>";
        echo "<td>{$p['name']}</td>";
        echo "<td>{$p['woocommerce_id']}</td>";
        echo "<td>{$p['woo_product_id']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>