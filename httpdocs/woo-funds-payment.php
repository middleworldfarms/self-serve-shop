function processWooFundsPayment($order_id, $amount, $customer_email, $password = null) {
    global $db;
    $settings = get_settings();
    
    $api_url = $settings['woo_funds_api_url'] ?? $settings['woo_shop_url'] ?? '';
    $consumer_key = $settings['woo_funds_consumer_key'] ?? $settings['woo_consumer_key'] ?? '';
    $consumer_secret = $settings['woo_funds_consumer_secret'] ?? $settings['woo_consumer_secret'] ?? '';
    
    if (empty($api_url) || empty($consumer_key) || empty($consumer_secret)) {
        return ['success' => false, 'error' => 'WooCommerce API not properly configured'];
    }
    
    // First step: Validate customer credentials
    $validation_url = rtrim($api_url, '/') . '/wp-json/self-serve-shop/v1/validate-customer';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $validation_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $consumer_key . ":" . $consumer_secret);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'email' => $customer_email,
        'password' => $password
    ]));
    
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    
    if ($err) {
        return ['success' => false, 'error' => "Connection error: $err"];
    }
    
    $result = json_decode($response, true);
    
    if (!isset($result['success']) || $result['success'] !== true) {
        return ['success' => false, 'error' => $result['message'] ?? 'Authentication failed'];
    }
    
    // Now process the actual payment...
    // Rest of your payment processing code
}