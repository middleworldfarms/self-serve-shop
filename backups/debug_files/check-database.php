<?php
require_once 'config.php';

echo "<h1>Database Structure Check</h1>";

try {
    // Check if the woocommerce_order_id column exists
    $result = $db->query("SHOW COLUMNS FROM orders LIKE 'woocommerce_order_id'");
    $column_exists = ($result->rowCount() > 0);
    
    if ($column_exists) {
        echo "<p>✅ The 'woocommerce_order_id' column exists in the orders table.</p>";
    } else {
        echo "<p>❌ The 'woocommerce_order_id' column does NOT exist in the orders table.</p>";
        
        // Try to add it with better error reporting
        echo "<p>Attempting to add the column now...</p>";
        try {
            $db->exec("ALTER TABLE orders ADD COLUMN woocommerce_order_id BIGINT NULL");
            echo "<p>✅ Successfully added the column!</p>";
        } catch (PDOException $e) {
            echo "<p>Error adding column: " . $e->getMessage() . "</p>";
        }
    }
    
    // Show the full table structure
    echo "<h2>Full Orders Table Structure:</h2>";
    $result = $db->query("DESCRIBE orders");
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>