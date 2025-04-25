<?php
require_once '../config.php';

// Create database tables
try {
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create orders table with new payment columns
    $db->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_number VARCHAR(20) NOT NULL UNIQUE,
            customer_name VARCHAR(100),
            customer_email VARCHAR(100),
            total_amount DECIMAL(10,2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            items TEXT,
            stripe_payment_id VARCHAR(100),
            paypal_payment_id VARCHAR(100),
            gocardless_payment_id VARCHAR(100),
            woo_funds_transaction_id VARCHAR(100),
            manual_payment_note TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
    
    // Create sss_products table
    $db->exec("
        CREATE TABLE IF NOT EXISTS sss_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            price DECIMAL(10,2) NOT NULL,
            image VARCHAR(255),
            status VARCHAR(20) DEFAULT 'active',
            sync_id INT,
            woo_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
    
    // Create settings table
    $db->exec("
        CREATE TABLE IF NOT EXISTS self_serve_settings (
            setting_id INT AUTO_INCREMENT PRIMARY KEY,
            setting_name VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
    
    // Insert new payment settings if not present
    $settings = [
        ['enable_square', '0'],
        ['enable_apple_pay', '0'],
        ['enable_google_pay', '0'],
        ['stripe_webhook_secret', ''],
        ['enable_manual_payment', '1'],
        ['enable_stripe', '1'],
        ['enable_paypal', '1'],
        ['enable_gocardless', '0'],
        ['enable_woo_funds', '1']
    ];
    foreach ($settings as $setting) {
        $stmt = $db->prepare("INSERT IGNORE INTO self_serve_settings (setting_name, setting_value) VALUES (?, ?)");
        $stmt->execute($setting);
    }
    
    // Create order_logs table
    $db->exec("
        CREATE TABLE IF NOT EXISTS order_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT,
            event_type VARCHAR(50) NOT NULL,
            details TEXT,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
    
    // Create users table
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB;
    ");
    
    echo "Database setup complete!";
} catch (PDOException $e) {
    die("Database setup failed: " . $e->getMessage());
}
?>