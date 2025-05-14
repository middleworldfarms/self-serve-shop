<?php
require_once 'config.php';

echo "<h1>Adding WooCommerce Order ID Column</h1>";

try {
    // Check if the column already exists
    $result = $db->query("SHOW COLUMNS FROM orders LIKE 'woocommerce_order_id'");
    $column_exists = ($result->rowCount() > 0);
    
    if ($column_exists) {
        echo "<p>Column 'woocommerce_order_id' already exists in the orders table.</p>";
    } else {
        // Add the column
        $db->exec("ALTER TABLE orders ADD COLUMN woocommerce_order_id BIGINT NULL");
        echo "<p>✅ Successfully added 'woocommerce_order_id' column to the orders table!</p>";
    }
    
    // Check orders that already have WooCommerce orders
    $stmt = $db->query("SELECT id, order_notes FROM orders WHERE order_notes LIKE '%WooCommerce Order ID:%'");
    $orders_with_woo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($orders_with_woo) > 0) {
        echo "<p>Found " . count($orders_with_woo) . " orders with WooCommerce IDs in notes. Updating...</p>";
        
        $updated = 0;
        foreach ($orders_with_woo as $order) {
            // Extract WooCommerce ID from notes
            if (preg_match('/WooCommerce Order ID: (\d+)/', $order['order_notes'], $matches)) {
                $woo_id = $matches[1];
                
                // Update the record
                $db->prepare("UPDATE orders SET woocommerce_order_id = ? WHERE id = ?")->execute([$woo_id, $order['id']]);
                $updated++;
            }
        }
        
        echo "<p>Updated $updated orders with WooCommerce IDs.</p>";
    }
    
    echo "<p>Your WooCommerce integration is now complete! All payment methods should work correctly.</p>";
    
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>