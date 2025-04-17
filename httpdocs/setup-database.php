<?php
// filepath: /var/www/vhosts/middleworld.farm/httpdocs/setup-database.php
require_once 'config.php';

try {
    // Connect to the database
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
        DB_USER, 
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h1>Database Setup</h1>";
    
    // Create users table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `email` VARCHAR(100) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `role` VARCHAR(20) NOT NULL DEFAULT 'admin',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "<p>✅ Users table created successfully</p>";
    
    // Create orders table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `orders` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_number` VARCHAR(50) NOT NULL,
            `customer_name` VARCHAR(100),
            `customer_email` VARCHAR(100),
            `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            `payment_method` VARCHAR(50),
            `payment_status` VARCHAR(30) DEFAULT 'pending',
            `order_status` VARCHAR(30) DEFAULT 'new',
            `order_notes` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "<p>✅ Orders table created successfully</p>";
    
    // Create order_logs table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `order_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NULL,
            `log_type` VARCHAR(30) NOT NULL,
            `message` TEXT NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "<p>✅ Order logs table created successfully</p>";
    
    // Create self_serve_settings table
    $db->exec("
        CREATE TABLE IF NOT EXISTS `self_serve_settings` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `setting_name` VARCHAR(100) NOT NULL UNIQUE,
            `setting_value` TEXT,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "<p>✅ Settings table created successfully</p>";
    
    // Insert default admin user
    $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
    $db->exec("
        INSERT IGNORE INTO `users` (`username`, `email`, `password`, `role`)
        VALUES ('admin', 'admin@example.com', '$password_hash', 'admin')
    ");
    echo "<p>✅ Default admin user created (Username: admin, Password: admin123)</p>";
    
    // Insert default settings
    $settings = [
        ['shop_name', 'Middle World Farm Shop'],
        ['shop_organization', 'Middle World Farms CIC'],
        ['currency_symbol', '£'],
        ['primary_color', '#4CAF50'],
        ['secondary_color', '#388E3C'],
        ['background_color', '#f5f5f5'],
        ['text_color', '#333333']
    ];
    
    $insert = $db->prepare("INSERT IGNORE INTO `self_serve_settings` (`setting_name`, `setting_value`) VALUES (?, ?)");
    foreach ($settings as $setting) {
        $insert->execute($setting);
    }
    echo "<p>✅ Default settings created</p>";
    
    echo "<p>✅ <strong>Database setup complete!</strong> You can now <a href='admin/index.php'>login to the admin panel</a> using the default credentials.</p>";

} catch(PDOException $e) {
    echo "<h1>Database Setup Error</h1>";
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}