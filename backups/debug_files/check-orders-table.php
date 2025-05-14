<?php
require_once 'config.php';

echo "<h1>Orders Database Check</h1>";

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if orders table exists
    $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h2>Database Tables</h2>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
    
    // If orders table doesn't exist, create it
    if (!in_array('orders', $tables)) {
        echo "<p>Creating 'orders' table...</p>";
        
        $db->exec("CREATE TABLE `orders` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `payment_method` varchar(50) NOT NULL,
          `customer_name` varchar(255) DEFAULT NULL,
          `customer_email` varchar(255) DEFAULT NULL,
          `total_amount` decimal(10,2) NOT NULL,
          `status` varchar(50) NOT NULL DEFAULT 'pending',
          `woocommerce_order_id` int(11) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        echo "<p>✅ Orders table created successfully!</p>";
    } else {
        echo "<p>✅ Orders table exists.</p>";
    }
    
    // If order_items table doesn't exist, create it
    if (!in_array('order_items', $tables)) {
        echo "<p>Creating 'order_items' table...</p>";
        
        $db->exec("CREATE TABLE `order_items` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `order_id` int(11) NOT NULL,
          `product_id` int(11) NOT NULL,
          `product_name` varchar(255) NOT NULL,
          `quantity` int(11) NOT NULL,
          `price` decimal(10,2) NOT NULL,
          PRIMARY KEY (`id`),
          KEY `order_id` (`order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        
        echo "<p>✅ Order items table created successfully!</p>";
    } else {
        echo "<p>✅ Order items table exists.</p>";
    }
    
    echo "<h2>✅ Database structure is now ready for cash payments!</h2>";
    echo "<p><a href='test-cash-payment.php'>Try the cash payment test again</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>Database error: " . $e->getMessage() . "</p>";
}
?>