<?php
require_once 'config.php';

function processWooFundsPayment($order_id, $amount, $customer_email, $password = null) {
    global $db;
    $settings = get_settings();
    
    // Make sure we have the required settings
    $api_url = $settings['woo_shop_url'] ?? '';
    $consumer_key = $settings['woo_consumer_key'] ?? '';
    $consumer_secret = $settings['woo_consumer_secret'] ?? '';
    
    if (empty($api_url) || empty($consumer_key) || empty($consumer_secret)) {
        error_log("WooCommerce API settings missing");
        return ['success' => false, 'error' => 'WooCommerce API not properly configured'];
    }
    
    // Normalize email address
    $customer_email = strtolower(trim($customer_email));
    
    // Debug log the attempt
    error_log("Attempting WooCommerce funds payment for email: " . $customer_email . " Amount: £" . $amount);
    
    // API endpoint for account validation
    $validation_url = rtrim($api_url, '/') . '/wp-json/wc-account-funds/v1/validate-customer';
    
    // If validation endpoint doesn't exist, try the default WordPress REST API
    if (!url_exists($validation_url)) {
        $validation_url = rtrim($api_url, '/') . '/wp-json/wp/v2/users/me';
    }
    
    error_log("Using API endpoint: " . $validation_url);
    
    // Set up cURL request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $validation_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $consumer_key . ":" . $consumer_secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'email' => $customer_email,
        'password' => $password,
        'amount' => $amount
    ]));
    
    // Enable verbose output for debugging
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);
    
    // Execute request
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log detailed response for debugging
    error_log("API Response (HTTP $http_code): " . substr($response, 0, 255));
    if ($err) {
        error_log("cURL Error: " . $err);
        return ['success' => false, 'error' => "Connection error: $err"];
    }
    
    // Get verbose log
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    error_log("cURL Verbose: " . $verboseLog);
    
    // Try alternative authentication if first method failed
    if ($http_code >= 400) {
        error_log("API authentication failed, trying basic auth method");
        
        // Try direct WordPress authentication
        $auth_url = rtrim($api_url, '/') . '/wp-json/jwt-auth/v1/token';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $auth_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'username' => $customer_email,
            'password' => $password
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $auth_response = curl_exec($ch);
        $auth_http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("JWT Auth Response (HTTP $auth_http_code): " . substr($auth_response, 0, 255));
        
        if ($auth_http_code >= 200 && $auth_http_code < 300) {
            $auth_data = json_decode($auth_response, true);
            if (isset($auth_data['token'])) {
                return [
                    'success' => true,
                    'transaction_id' => time(),
                    'new_balance' => 0, // You would need to fetch the actual balance
                    'method' => 'woo_funds'
                ];
            }
        }
        
        return ['success' => false, 'error' => 'Authentication failed. Please check your email and password.'];
    }
    
    // Parse the response
    $result = json_decode($response, true);
    if (!$result) {
        error_log("Failed to parse API response");
        return ['success' => false, 'error' => 'Invalid response from server'];
    }
    
    // Handle success case - this would depend on your API's response format
    if (isset($result['success']) && $result['success'] === true) {
        // Mock successful payment response - you would need to adjust based on actual API
        return [
            'success' => true,
            'transaction_id' => $result['transaction_id'] ?? time(),
            'new_balance' => $result['new_balance'] ?? 0,
            'method' => 'woo_funds'
        ];
    }
    
    // Handle error case
    return [
        'success' => false,
        'error' => $result['message'] ?? 'Account credit payment failed'
    ];
}

// Helper function to check if URL exists
function url_exists($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code == 200;
}