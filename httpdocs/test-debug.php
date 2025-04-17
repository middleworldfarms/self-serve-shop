<?php
// Display all PHP errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>PHP Info and Diagnostics</h1>";

// 1. Basic PHP info
echo "<h2>PHP Version</h2>";
echo "PHP Version: " . phpversion() . "<br>";

// 2. Test file permissions
echo "<h2>File Permissions</h2>";
$test_dirs = [
    '/var/www/vhosts/middleworldfarms.org/self-serve-shop/',
    '/var/www/vhosts/middleworldfarms.org/self-serve-shop/images/',
    '/var/www/vhosts/middleworldfarms.org/self-serve-shop/uploads/'
];

foreach ($test_dirs as $dir) {
    echo "Directory $dir: " . (is_dir($dir) ? "Exists" : "Does not exist") . 
         " - Readable: " . (is_readable($dir) ? "Yes" : "No") .
         " - Writable: " . (is_writable($dir) ? "Yes" : "No") . "<br>";
}

// 3. Test includes
echo "<h2>Including Files</h2>";
try {
    echo "Trying to include config.php...<br>";
    include_once('config.php');
    echo "Config loaded successfully!<br>";
    
    // Check if we can read the SHOP_LOGO constant
    if (defined('SHOP_LOGO')) {
        echo "SHOP_LOGO is defined as: " . SHOP_LOGO . "<br>";
        $logo_path = $_SERVER['DOCUMENT_ROOT'] . SHOP_LOGO;
        echo "Full logo path: " . $logo_path . "<br>";
        echo "Logo file exists: " . (file_exists($logo_path) ? "Yes" : "No") . "<br>";
    } else {
        echo "SHOP_LOGO constant is not defined<br>";
    }
} catch (Exception $e) {
    echo "Error including config.php: " . $e->getMessage() . "<br>";
}

// 4. Test the localize_image_url function if it exists
echo "<h2>Functions</h2>";
if (function_exists('localize_image_url')) {
    echo "localize_image_url function exists<br>";
} else {
    echo "localize_image_url function does not exist<br>";
}

// 5. Test database connectivity if relevant
echo "<h2>Database</h2>";
try {
    if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASSWORD') && defined('DB_NAME')) {
        echo "Database constants are defined<br>";
        echo "Attempting connection...<br>";
        $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASSWORD);
        echo "Database connection successful!<br>";
    } else {
        echo "Database constants not fully defined<br>";
    }
} catch (PDOException $e) {
    echo "Database connection error: " . $e->getMessage() . "<br>";
}

echo "<h2>Test Complete</h2>";
