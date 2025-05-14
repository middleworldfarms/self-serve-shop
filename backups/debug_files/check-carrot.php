<?php
require_once 'config.php';

try {
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    
    echo "<h1>Carrot Product Check</h1>";
    
    // Check by name
    $stmt = $db->prepare("SELECT id, name, woocommerce_id FROM sss_products WHERE name LIKE '%carrot%'");
    $stmt->execute();
    $carrots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($carrots)) {
        echo "<p>No products found with 'carrot' in the name.</p>";
    } else {
        echo "<h2>Found " . count($carrots) . " carrot products:</h2>";
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>Name</th><th>WooCommerce ID</th></tr>";
        
        foreach ($carrots as $product) {
            echo "<tr>";
            echo "<td>{$product['id']}</td>";
            echo "<td>{$product['name']}</td>";
            echo "<td>{$product['woocommerce_id']}</td>";
            echo "</tr>";
        }
        
        echo "</table>";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>