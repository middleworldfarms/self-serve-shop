<?php
require_once __DIR__ . '/../config.php';

// Make sure we have the database connection
if (!isset($db)) {
    die("Database connection not available.");
}

echo "Starting payment system setup...<br>";

try {
    // Add GoCardless settings if they don't exist
    $stmt = $db->prepare("INSERT IGNORE INTO self_serve_settings (setting_name, setting_value) VALUES
    ('enable_gocardless', '0'),
    ('gocardless_access_token', ''),
    ('gocardless_environment', 'sandbox'),
    ('gocardless_webhook_secret', '')");
    $stmt->execute();
    echo "GoCardless settings added.<br>";

    // Add WooFunds settings if they don't exist
    $stmt = $db->prepare("INSERT IGNORE INTO self_serve_settings (setting_name, setting_value) VALUES
    ('enable_woo_funds', '0'),
    ('woocommerce_site_url', 'https://middleworldfarms.org'),
    ('woo_funds_api_key', '')");
    $stmt->execute();
    echo "WooCommerce Funds settings added.<br>";

    // Create customer_payment_methods table if it doesn't exist
    $stmt = $db->prepare("CREATE TABLE IF NOT EXISTS customer_payment_methods (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        method_details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (customer_id, payment_method)
    )");
    $stmt->execute();
    echo "Customer payment methods table created.<br>";

    echo "<br>Payment system setup completed successfully!";

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}