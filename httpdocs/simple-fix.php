<?php
// Super Simple Login Fix

// Load config
require_once 'config.php';

echo "<h1>Simple Self-Serve Shop Fix</h1>";

try {
    // Connect to database
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, 
        DB_USER, 
        DB_PASS
    );
    
    echo "<p>Connected to database successfully!</p>";
    
    // Check if self-serve shop uses its own users table
    $tables = $db->query("SHOW TABLES");
    $user_table = '';
    $users_found = false;
    
    echo "<h2>Available Tables:</h2><ul>";
    
    while ($row = $tables->fetch(PDO::FETCH_NUM)) {
        $table = $row[0];
        echo "<li>{$table}</li>";
        
        // Look for possible user tables
        if (strpos($table, 'user') !== false || 
            strpos($table, 'admin') !== false || 
            $table == TABLE_PREFIX . 'users') {
            $user_table = $table;
            $users_found = true;
        }
    }
    
    echo "</ul>";
    
    if ($users_found) {
        echo "<p>Found possible user table: {$user_table}</p>";
        
        // Add direct admin link setup
        echo "<h2>Direct Admin Session Setup</h2>";
        echo "<p>Click to create an admin session:</p>";
        
        // Start session
        session_start();
        
        // Set admin session variables
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = 'emergency';
        
        echo "<p style='color:green'>Emergency admin session created!</p>";
        echo "<p><a href='admin/settings.php' style='padding:10px; background:green; color:white; text-decoration:none;'>Go to Admin Settings</a></p>";
        
        // Update WooCommerce API key
        echo "<h2>Update API Key</h2>";
        echo "<p>Setting API key in database:</p>";
        
        // Update config.php
        $config_path = __DIR__ . '/config.php';
        $config_content = file_get_contents($config_path);
        
        // Replace API key line
        $new_api_key = 'mwf_f27ef26753e7674eee61';
        $config_content = preg_replace(
            "/if \(!defined\('WOO_FUNDS_API_KEY'\)\) define\('WOO_FUNDS_API_KEY', '.*?'\);/", 
            "if (!defined('WOO_FUNDS_API_KEY')) define('WOO_FUNDS_API_KEY', '{$new_api_key}');",
            $config_content
        );
        
        // Also enable WooCommerce funds
        $config_content = preg_replace(
            "/if \(!defined\('ENABLE_WOO_FUNDS_PAYMENT'\)\) define\('ENABLE_WOO_FUNDS_PAYMENT', false\);/",
            "if (!defined('ENABLE_WOO_FUNDS_PAYMENT')) define('ENABLE_WOO_FUNDS_PAYMENT', true);",
            $config_content
        );
        
        // Save updated config
        if (file_put_contents($config_path, $config_content)) {
            echo "<p style='color:green'>Updated config.php with API key: {$new_api_key}</p>";
            echo "<p style='color:green'>Enabled WooCommerce Funds payment method</p>";
        } else {
            echo "<p style='color:red'>Failed to update config.php</p>";
        }
    } else {
        echo "<p>Could not find user tables. Let's create a session anyway.</p>";
        
        // Start session
        session_start();
        
        // Set admin session variables
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = 'emergency';
        
        echo "<p style='color:green'>Emergency admin session created!</p>";
        echo "<p><a href='admin/settings.php' style='padding:10px; background:green; color:white; text-decoration:none;'>Go to Admin Settings</a></p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>