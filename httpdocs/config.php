<?php
// Ensure proper character encoding
mb_internal_encoding('UTF-8');
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

// Prevent multiple inclusion
if (defined('CONFIG_LOADED')) {
    return;
}
define('CONFIG_LOADED', true);

// Database configuration (standalone)
if (!defined('DB_TYPE')) define('DB_TYPE', 'mysql');
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'self-serv-shop');
if (!defined('DB_USER')) define('DB_USER', 'martin-sell-serve-shop');
if (!defined('DB_PASS')) define('DB_PASS', 'g78t~H9s1');
if (!defined('TABLE_PREFIX')) define('TABLE_PREFIX', '');

// Shop settings
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', '£');
if (!defined('SHOP_NAME')) define('SHOP_NAME', 'Middle World Farm Shop');
if (!defined('SHOP_ORGANIZATION')) define('SHOP_ORGANIZATION', 'Middle World Farms CIC');
if (!defined('SHOP_LOGO')) define('SHOP_LOGO', '/uploads/test-image.jpg');
if (!defined('SHOP_URL')) define('SHOP_URL', 'https://www.middleworld.farm/');
if (!defined('SHOP_DESCRIPTION')) define('SHOP_DESCRIPTION', 'Farm to fork shop open 6 days a week 10am till 9 pm');
if (!defined('SHOP_ADDRESS')) define('SHOP_ADDRESS', 'Middle World Farm, Bardney Road, Washingbourgh, Lincoln, LN4 1AQ');
if (!defined('SHOP_PHONE')) define('SHOP_PHONE', '01522 449610');
if (!defined('SHOP_EMAIL')) define('SHOP_EMAIL', 'middleworldfarms@gmail.com');

// Payment settings
if (!defined('ENABLE_MANUAL_PAYMENT')) define('ENABLE_MANUAL_PAYMENT', true);
if (!defined('PAYMENT_INSTRUCTIONS')) define('PAYMENT_INSTRUCTIONS', 'Please pay at the honor box.');
if (!defined('ENABLE_STRIPE_PAYMENT')) define('ENABLE_STRIPE_PAYMENT', false);
if (!defined('STRIPE_PUBLIC_KEY')) define('STRIPE_PUBLIC_KEY', '');
if (!defined('STRIPE_SECRET_KEY')) define('STRIPE_SECRET_KEY', '');
if (!defined('STRIPE_WEBHOOK_SECRET')) define('STRIPE_WEBHOOK_SECRET', '');
if (!defined('ENABLE_PAYPAL_PAYMENT')) define('ENABLE_PAYPAL_PAYMENT', false);
if (!defined('PAYPAL_CLIENT_ID')) define('PAYPAL_CLIENT_ID', '');
if (!defined('PAYPAL_SECRET')) define('PAYPAL_SECRET', '');
if (!defined('ENABLE_BANK_TRANSFER')) define('ENABLE_BANK_TRANSFER', false);
if (!defined('BANK_DETAILS')) define('BANK_DETAILS', '');
if (!defined('ENABLE_WOO_FUNDS_PAYMENT')) define('ENABLE_WOO_FUNDS_PAYMENT', false);
if (!defined('WOO_SITE_URL')) define('WOO_SITE_URL', 'https://middleworldfarms.org');
if (!defined('WOO_FUNDS_API_KEY')) define('WOO_FUNDS_API_KEY', '');
if (!defined('ENABLE_GOCARDLESS_PAYMENT')) define('ENABLE_GOCARDLESS_PAYMENT', false);
if (!defined('GOCARDLESS_ACCESS_TOKEN')) define('GOCARDLESS_ACCESS_TOKEN', '');
if (!defined('GOCARDLESS_ENVIRONMENT')) define('GOCARDLESS_ENVIRONMENT', 'sandbox');
if (!defined('GOCARDLESS_WEBHOOK_SECRET')) define('GOCARDLESS_WEBHOOK_SECRET', '');
if (!defined('ENABLE_APPLE_PAY_PAYMENT')) define('ENABLE_APPLE_PAY_PAYMENT', false);
if (!defined('ENABLE_GOOGLE_PAY_PAYMENT')) define('ENABLE_GOOGLE_PAY_PAYMENT', false);
if (!defined('APPLE_MERCHANT_ID')) define('APPLE_MERCHANT_ID', '');
if (!defined('GOOGLE_MERCHANT_ID')) define('GOOGLE_MERCHANT_ID', '');
if (!defined('WOO_FUNDS_TEST_MODE')) define('WOO_FUNDS_TEST_MODE', '1'); // or '0' for live

// Branding settings
if (!defined('PRIMARY_COLOR')) define('PRIMARY_COLOR', '#4caf50');
if (!defined('SECONDARY_COLOR')) define('SECONDARY_COLOR', '#305a32');
if (!defined('ACCENT_COLOR')) define('ACCENT_COLOR', '#3de60f');
if (!defined('TEXT_COLOR')) define('TEXT_COLOR', '#333333');
if (!defined('BACKGROUND_COLOR')) define('BACKGROUND_COLOR', '#f5f5f5');
if (!defined('CUSTOM_HEADER')) define('CUSTOM_HEADER', '');
if (!defined('CUSTOM_FOOTER')) define('CUSTOM_FOOTER', '');

// Start session if not started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Helper function for currency display
if (!function_exists('display_currency')) {
    function display_currency($amount) {
        $currency_symbol = get_setting('currency_symbol', '£'); // Fetch dynamically from settings
        return $currency_symbol . number_format((float)$amount, 2);
    }
}

/**
 * Fixes image paths to consistently use admin/uploads directory
 * @param string $path The image path to normalize
 * @return string The normalized path
 */
function normalize_image_path($path) {
    if (empty($path)) {
        return 'admin/uploads/Shopping bag.png'; // Default image
    }
    $filename = basename($path);
    return 'admin/uploads/' . $filename;
}

/**
 * Ensures all image paths use admin/uploads
 */
function get_image_path($path = '') {
    if (empty($path) || !preg_match('/\.(jpg|jpeg|png|gif)$/i', $path)) {
        return '/admin/uploads/Shopping bag.png';
    }
    if (strpos($path, '/admin/uploads/') === 0) {
        return $path;
    }
    $filename = basename($path);
    return '/admin/uploads/' . $filename;
}

/**
 * Process image URLs to avoid CORS issues
 * @param string $url The original image URL
 * @return string The processed URL (proxied if external)
 */
function process_image_url($url) {
    // For null or empty URLs
    if (empty($url)) {
        return '/admin/uploads/Shopping bag.png';
    }
    
    // Process middleworldfarms.org URLs through the image proxy
    if (strpos($url, 'middleworldfarms.org') !== false) {
        return '/image-proxy.php?url=' . urlencode($url);
    }
    
    // Return as is for local images
    return $url;
}

/**
 * Standardize image paths for uploads
 * This function won't break existing paths but helps organize new uploads
 */
function organize_image_path($image_path, $type = 'products') {
    // Don't modify paths that are already organized
    if (strpos($image_path, 'uploads/icons/') !== false || 
        strpos($image_path, 'uploads/logos/') !== false || 
        strpos($image_path, 'uploads/products/') !== false) {
        return $image_path;
    }
    
    // Get just the filename
    $filename = basename($image_path);
    
    // Determine type if not specified
    if ($type === 'auto') {
        if (strpos(strtolower($filename), 'logo') !== false) {
            $type = 'logos';
        } elseif (strpos(strtolower($filename), 'icon') !== false || 
                 strpos(strtolower($filename), 'placeholder') !== false) {
            $type = 'icons';
        } else {
            $type = 'products';
        }
    }
    
    return "uploads/$type/$filename";
}

/**
 * Universal settings loader for both admin and customer
 */
function get_settings() {
    static $settings = null;
    if ($settings !== null) return $settings;

    try {
        $db = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
            DB_USER,
            DB_PASS
        );
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $db->query("SELECT setting_name, setting_value FROM self_serve_settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_name']] = $row['setting_value'];
        }
        return $settings;
    } catch (Exception $e) {
        error_log("Settings load error: " . $e->getMessage());
        return [];
    }
}

/**
 * Get a specific setting value with fallback
 * @param string $key The setting key to retrieve
 * @param mixed $default Default value if setting doesn't exist
 * @return mixed The setting value or default
 */
function get_setting($key, $default = null) {
    $settings = get_settings();
    return isset($settings[$key]) ? $settings[$key] : $default;
}
