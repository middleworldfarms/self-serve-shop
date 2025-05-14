<?php
require_once 'config.php';

echo "<h1>Add Column Using Config Settings</h1>";

try {
    // Use the PDO connection from config
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Add the column
    $db->exec("ALTER TABLE orders ADD COLUMN woocommerce_order_id BIGINT NULL");
    echo "<p>✅ Successfully added woocommerce_order_id column!</p>";
    
} catch (PDOException $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
    
    // If error contains "Duplicate column", it means the column already exists
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "<p>The column already exists, which is fine.</p>";
    }
}
?>