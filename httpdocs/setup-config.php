<?php
// filepath: /var/www/vhosts/middleworldfarms.org/self-serve-shop/setup-config.php
// Database configuration setup wizard

// Check if config file already exists
if (file_exists('config.php') && filesize('config.php') > 0) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = false;
$config_content = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_type = isset($_POST['db_type']) ? $_POST['db_type'] : 'wordpress';
    $db_host = isset($_POST['db_host']) ? trim($_POST['db_host']) : '';
    $db_name = isset($_POST['db_name']) ? trim($_POST['db_name']) : '';
    $db_user = isset($_POST['db_user']) ? trim($_POST['db_user']) : '';
    $db_pass = isset($_POST['db_pass']) ? $_POST['db_pass'] : '';
    $table_prefix = isset($_POST['table_prefix']) ? trim($_POST['table_prefix']) : '';
    $site_title = isset($_POST['site_title']) ? trim($_POST['site_title']) : 'Self-Serve Shop';
    $currency = isset($_POST['currency']) ? trim($_POST['currency']) : 'GBP';
    $currency_symbol = isset($_POST['currency_symbol']) ? trim($_POST['currency_symbol']) : '£';
    
    // Validate inputs
    if (empty($db_host) || empty($db_name) || empty($db_user) || empty($site_title)) {
        $error = "All fields marked with * are required.";
    } else {
        // Test database connection
        try {
            $test_db = new PDO(
                "mysql:host={$db_host};dbname={$db_name}",
                $db_user,
                $db_pass
            );
            
            // If using WordPress, verify the table prefix
            if ($db_type === 'wordpress' && !empty($table_prefix)) {
                $query = "SHOW TABLES LIKE '{$table_prefix}posts'";
                $stmt = $test_db->query($query);
                if ($stmt->rowCount() === 0) {
                    $error = "Could not find WordPress tables with the prefix '{$table_prefix}'. Please check your prefix.";
                }
            }
            
            if (empty($error)) {
                // Generate config file content
                $config_content = "<?php\n";
                $config_content .= "// Database configuration\n";
                $config_content .= "define('DB_TYPE', '{$db_type}');\n";
                $config_content .= "define('DB_HOST', '{$db_host}');\n";
                $config_content .= "define('DB_NAME', '{$db_name}');\n";
                $config_content .= "define('DB_USER', '{$db_user}');\n";
                $config_content .= "define('DB_PASS', '{$db_pass}');\n\n";
                
                if ($db_type === 'wordpress') {
                    $config_content .= "// WordPress table prefix\n";
                    $config_content .= "define('TABLE_PREFIX', '{$table_prefix}');\n\n";
                    
                    $config_content .= "// WooCommerce API configuration\n";
                    $config_content .= "define('WC_CONSUMER_KEY', 'your_woocommerce_consumer_key');\n";
                    $config_content .= "define('WC_CONSUMER_SECRET', 'your_woocommerce_consumer_secret');\n";
                    $config_content .= "define('WC_STORE_URL', 'https://your-site-url.com');\n\n";
                } else {
                    $config_content .= "// Table prefix for standalone mode\n";
                    $config_content .= "define('TABLE_PREFIX', 'sss_');\n\n";
                }
                
                $config_content .= "// Stripe API configuration\n";
                $config_content .= "define('STRIPE_PUBLISHABLE_KEY', 'your_stripe_publishable_key');\n";
                $config_content .= "define('STRIPE_SECRET_KEY', 'your_stripe_secret_key');\n\n";
                
                $config_content .= "// General settings\n";
                $config_content .= "define('SITE_TITLE', '{$site_title}');\n";
                $config_content .= "define('CURRENCY', '{$currency}');\n";
                $config_content .= "define('CURRENCY_SYMBOL', '{$currency_symbol}');\n\n";
                
                $config_content .= "// Error reporting (set to 0 in production)\n";
                $config_content .= "error_reporting(E_ALL);\n";
                $config_content .= "ini_set('display_errors', 1);\n\n";
                
                $config_content .= "// Start session\n";
                $config_content .= "if (!session_id()) {\n";
                $config_content .= "    session_start();\n";
                $config_content .= "}\n";
                
                // Write config file
                if (file_put_contents('config.php', $config_content)) {
                    $success = true;
                    
                    // If standalone mode, create the tables
                    if ($db_type === 'standalone') {
                        try {
                            // Create products table
                            $test_db->exec("
                                CREATE TABLE IF NOT EXISTS `sss_products` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `name` varchar(255) NOT NULL,
                                  `description` text,
                                  `price` decimal(10,2) NOT NULL,
                                  `regular_price` decimal(10,2),
                                  `sale_price` decimal(10,2),
                                  `image` varchar(255),
                                  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
                                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                                  PRIMARY KEY (`id`)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
                            ");
                            
                            // Create users table
                            $test_db->exec("
                                CREATE TABLE IF NOT EXISTS `sss_users` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `username` varchar(50) NOT NULL,
                                  `password` varchar(255) NOT NULL,
                                  `email` varchar(100) NOT NULL,
                                  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
                                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `username` (`username`),
                                  UNIQUE KEY `email` (`email`)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
                            ");
                            
                            // Create orders table
                            $test_db->exec("
                                CREATE TABLE IF NOT EXISTS `sss_orders` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `order_number` varchar(50) NOT NULL,
                                  `total_amount` decimal(10,2) NOT NULL,
                                  `payment_method` varchar(50) NOT NULL,
                                  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
                                  `customer_name` varchar(100),
                                  `customer_email` varchar(100),
                                  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                  PRIMARY KEY (`id`),
                                  UNIQUE KEY `order_number` (`order_number`)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
                            ");
                            
                            // Create order items table
                            $test_db->exec("
                                CREATE TABLE IF NOT EXISTS `sss_order_items` (
                                  `id` int(11) NOT NULL AUTO_INCREMENT,
                                  `order_id` int(11) NOT NULL,
                                  `product_id` int(11) NOT NULL,
                                  `quantity` int(11) NOT NULL,
                                  `price` decimal(10,2) NOT NULL,
                                  PRIMARY KEY (`id`),
                                  KEY `order_id` (`order_id`),
                                  KEY `product_id` (`product_id`)
                                ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
                            ");
                            
                            // Create default admin user
                            $password_hash = password_hash('admin123', PASSWORD_DEFAULT);
                            $test_db->exec("
                                INSERT INTO `sss_users` (`username`, `password`, `email`, `role`) 
                                VALUES ('admin', '{$password_hash}', 'admin@example.com', 'admin');
                            ");
                        } catch (Exception $e) {
                            // Tables likely created, just continue
                        }
                    }
                } else {
                    $error = "Could not write to config.php. Please check file permissions.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database connection failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Configuration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        h1 {
            color: #2c3e50;
            border-bottom: 2px solid #eee;
            padding-bottom: 10px;
        }
        form {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background: #3498db;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background: #2980b9;
        }
        .error {
            background: #ffe6e6;
            color: #cc0000;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success {
            background: #e6ffe6;
            color: #006600;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .tabs {
            display: flex;
            margin-bottom: 20px;
        }
        .tab {
            padding: 10px 20px;
            cursor: pointer;
            background: #f1f1f1;
            border: 1px solid #ddd;
            border-radius: 4px 4px 0 0;
            margin-right: 5px;
        }
        .tab.active {
            background: #f9f9f9;
            border-bottom: 1px solid #f9f9f9;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        small {
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>
    <h1>Self-Serve Shop Setup</h1>
    
    <?php if ($success): ?>
        <div class="success">
            <p>Configuration successfully saved! You can now <a href="index.php">access your shop</a> or <a href="admin/index.php">login to admin panel</a>.</p>
            <p>If you selected standalone mode, your admin login is:</p>
            <ul>
                <li>Username: admin</li>
                <li>Password: admin123</li>
            </ul>
            <p><strong>Please change this password immediately after logging in!</strong></p>
        </div>
    <?php else: ?>
        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="tabs">
            <div class="tab active" data-tab="wordpress">WordPress Integration</div>
            <div class="tab" data-tab="standalone">Standalone Mode</div>
        </div>
        
        <form method="post" action="">
            <div id="wordpress" class="tab-content active">
                <p>Use this mode if you want to integrate with an existing WordPress site with WooCommerce.</p>
                <input type="hidden" name="db_type" value="wordpress">
                
                <div class="form-group">
                    <label for="db_host">Database Host *</label>
                    <input type="text" id="db_host" name="db_host" value="localhost" required>
                </div>
                
                <div class="form-group">
                    <label for="db_name">Database Name *</label>
                    <input type="text" id="db_name" name="db_name" required>
                </div>
                
                <div class="form-group">
                    <label for="db_user">Database Username *</label>
                    <input type="text" id="db_user" name="db_user" required>
                </div>
                
                <div class="form-group">
                    <label for="db_pass">Database Password</label>
                    <input type="password" id="db_pass" name="db_pass">
                </div>
                
                <div class="form-group">
                    <label for="table_prefix">WordPress Table Prefix *</label>
                    <input type="text" id="table_prefix" name="table_prefix" value="wp_" required>
                    <small>Usually wp_ unless changed during WordPress installation</small>
                </div>
            </div>
            
            <div id="standalone" class="tab-content">
                <p>Use this mode to run the shop without WordPress integration. The system will create its own tables.</p>
                <input type="hidden" name="db_type" value="standalone">
                
                <div class="form-group">
                    <label for="sa_db_host">Database Host *</label>
                    <input type="text" id="sa_db_host" name="db_host" value="localhost" required>
                </div>
                
                <div class="form-group">
                    <label for="sa_db_name">Database Name *</label>
                    <input type="text" id="sa_db_name" name="db_name" required>
                </div>
                
                <div class="form-group">
                    <label for="sa_db_user">Database Username *</label>
                    <input type="text" id="sa_db_user" name="db_user" required>
                </div>
                
                <div class="form-group">
                    <label for="sa_db_pass">Database Password</label>
                    <input type="password" id="sa_db_pass" name="db_pass">
                </div>
            </div>
            
            <h3>Shop Settings</h3>
            
            <div class="form-group">
                <label for="site_title">Shop Title *</label>
                <input type="text" id="site_title" name="site_title" value="Self-Serve Shop" required>
            </div>
            
            <div class="form-group">
                <label for="currency">Currency</label>
                <select id="currency" name="currency">
                    <option value="GBP">British Pound (GBP)</option>
                    <option value="EUR">Euro (EUR)</option>
                    <option value="USD">US Dollar (USD)</option>
                    <option value="CAD">Canadian Dollar (CAD)</option>
                    <option value="AUD">Australian Dollar (AUD)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="currency_symbol">Currency Symbol</label>
                <input type="text" id="currency_symbol" name="currency_symbol" value="£">
            </div>
            
            <button type="submit">Save Configuration</button>
        </form>
    <?php endif; ?>
    
    <script>
        // Handle tab switching
        document.addEventListener('DOMContentLoaded', function() {
            const tabs = document.querySelectorAll('.tab');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    // Update active tab
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Show corresponding content
                    const tabId = this.getAttribute('data-tab');
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                    document.getElementById(tabId).classList.add('active');
                    
                    // Update hidden field
                    document.querySelector('input[name="db_type"]').value = tabId;
                });
            });
            
            // Copy values between tabs
            const mirrorFields = [
                ['db_host', 'sa_db_host'],
                ['db_name', 'sa_db_name'],
                ['db_user', 'sa_db_user'],
                ['db_pass', 'sa_db_pass']
            ];
            
            mirrorFields.forEach(pair => {
                const field1 = document.getElementById(pair[0]);
                const field2 = document.getElementById(pair[1]);
                
                field1.addEventListener('input', function() {
                    field2.value = this.value;
                });
                
                field2.addEventListener('input', function() {
                    field1.value = this.value;
                });
            });
        });
    </script>
</body>
</html>