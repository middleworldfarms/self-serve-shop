<?php
// Enable full error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Safe Column Addition</h1>";

try {
    // Manual database connection
    $db_host = 'localhost';
    $db_name = 'self-serv-shop';
    $db_user = 'martin-sell-serve-shop';
    $db_pass = 'g78t~H9s1';
    
    echo "<p>Connecting to database...</p>";
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    
    // Check connection
    if ($conn->connect_error) {
        die("<p>Connection failed: " . $conn->connect_error . "</p>");
    }
    
    echo "<p>Connected successfully. Checking if column exists...</p>";
    
    // Check if column exists
    $result = $conn->query("SHOW COLUMNS FROM orders LIKE 'woocommerce_order_id'");
    
    if ($result->num_rows > 0) {
        echo "<p>Column already exists.</p>";
    } else {
        echo "<p>Adding column...</p>";
        
        // Create column
        if ($conn->query("ALTER TABLE orders ADD COLUMN woocommerce_order_id BIGINT NULL") === TRUE) {
            echo "<p>✅ Column added successfully!</p>";
        } else {
            echo "<p>❌ Error adding column: " . $conn->error . "</p>";
        }
    }
    
    $conn->close();
    echo "<p>Connection closed.</p>";
    
} catch (Exception $e) {
    echo "<p>Error: " . $e->getMessage() . "</p>";
}

echo "<p>Script completed.</p>";
?>