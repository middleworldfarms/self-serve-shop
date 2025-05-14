<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/create_woocommerce_order.php';

/**
 * Process a payment using WooCommerce account funds
 */
function process_woo_funds_payment($order_details) {
    global $db;
    
    // Log the start of the process
    error_log("Starting MWF account funds payment: " . json_encode([
        'email' => $order_details['auth']['email'] ?? 'not provided',
        'amount' => $order_details['total_amount']
    ]));
    
    // Validate required fields
    if (empty($order_details['auth']['email']) || empty($order_details['auth']['password'])) {
        return [
            'success' => false,
            'error' => 'Please provide both email and password for your account.'
        ];
    }
    
    try {
        // First verify the customer credentials and check available funds
        $verify_result = verifyAndCheckBalance(
            $order_details['auth']['email'],
            $order_details['auth']['password'],
            $order_details['total_amount']
        );
        
        // If verification fails, return the error
        if (!$verify_result['success']) {
            return $verify_result;
        }
        
        // Verification successful, now process the payment
        
        // Generate a unique order number
        $order_number = 'FUNDS-' . date('Ymd') . '-' . rand(1000, 9999);
        
        // Create local order
        $stmt = $db->prepare("
            INSERT INTO orders (
                order_number, 
                payment_method, 
                customer_name, 
                customer_email,
                total_amount, 
                payment_status,
                order_status,
                items
            ) VALUES (?, 'woo_funds', ?, ?, ?, 'completed', 'completed', ?)
        ");
        
        $stmt->execute([
            $order_number, 
            $order_details['customer_name'] ?? 'Account Customer', 
            $order_details['auth']['email'],
            $order_details['total_amount'],
            json_encode($order_details['items'])
        ]);
        $local_order_id = $db->lastInsertId();
        
        // Store in order_items table as well
        foreach ($order_details['items'] as $item) {
            $stmt = $db->prepare("
                INSERT INTO order_items (order_id, product_id, product_name, quantity, price)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $local_order_id,
                $item['id'],
                $item['name'],
                $item['quantity'],
                $item['price']
            ]);
        }
        
        // Process the actual deduction from the account
        $deduction_result = deductFunds(
            $order_details['auth']['email'],
            $order_details['total_amount'],
            $local_order_id
        );
        
        if (!$deduction_result['success']) {
            return $deduction_result;
        }
        
        // Create WooCommerce order
        $woo_order_id = create_woocommerce_order($local_order_id);
        
        // Return success with order details
        return [
            'success' => true,
            'order_id' => $local_order_id,
            'order_number' => $order_number,
            'payment_method' => 'woo_funds',
            'transaction_id' => $deduction_result['transaction_id'] ?? null,
            'new_balance' => $deduction_result['new_balance'] ?? null,
            'woocommerce_order_id' => $woo_order_id
        ];
        
    } catch (Exception $e) {
        error_log("Error in account funds payment: " . $e->getMessage());
        return [
            'success' => false,
            'error' => "Error processing payment: " . $e->getMessage()
        ];
    }
}

/**
 * Verify user and check available funds
 */
function verifyAndCheckBalance($email, $password, $amount) {
    // Get API settings
    $settings = get_settings();
    $woo_url = $settings['woo_shop_url'] ?? '';
    $api_key = $settings['woo_funds_api_key'] ?? '';
    
    if (empty($woo_url) || empty($api_key)) {
        return [
            'success' => false,
            'error' => 'Account credit payment system is not properly configured.'
        ];
    }
    
    // Ensure URL format is correct
    $woo_url = rtrim($woo_url, '/');
    $endpoint = "$woo_url/wp-json/mwf/v1/funds";
    
    // Set up API request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'check',
        'email' => $email,
        'password' => $password,  // Include password in the API call
        'amount' => $amount
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-WC-API-Key: ' . $api_key  // CHANGED FROM X-API-Key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Execute request with error logging
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    
    error_log("Account funds check - HTTP Code: $http_code");
    error_log("Account funds check - Response: " . substr($response, 0, 200));
    if ($curl_error) {
        error_log("Account funds check - cURL Error: $curl_error");
    }
    
    curl_close($ch);
    
    if ($curl_error) {
        return [
            'success' => false,
            'error' => "Connection error: $curl_error"
        ];
    }
    
    // Handle HTTP errors
    if ($http_code >= 400) {
        return [
            'success' => false,
            'error' => "Server error: HTTP $http_code"
        ];
    }
    
    // Parse response
    $data = json_decode($response, true);
    
    if (!isset($data['success'])) {
        return [
            'success' => false,
            'error' => 'Invalid response from account system'
        ];
    }
    
    if (!$data['success']) {
        return [
            'success' => false,
            'error' => $data['message'] ?? 'Unknown error'
        ];
    }
    
    // Check if user has sufficient funds
    if (isset($data['has_funds']) && !$data['has_funds']) {
        return [
            'success' => false,
            'error' => 'Insufficient funds in your account'
        ];
    }
    
    // Success, return balance
    return [
        'success' => true,
        'balance' => $data['current_balance'] ?? 0
    ];
}

/**
 * Deduct funds from account
 */
function deductFunds($email, $amount, $order_id) {
    // Get API settings
    $settings = get_settings();
    $woo_url = $settings['woo_shop_url'] ?? '';
    $api_key = $settings['woo_funds_api_key'] ?? '';
    
    // Ensure URL format is correct
    $woo_url = rtrim($woo_url, '/');
    $endpoint = "$woo_url/wp-json/mwf/v1/funds";
    
    // Set up API request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'deduct',
        'email' => $email,
        'amount' => $amount,
        'order_id' => $order_id,
        'description' => 'Self-serve shop purchase'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-WC-API-Key: ' . $api_key  // CHANGED FROM X-API-Key
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Execute request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    error_log("Account funds deduction response: $response");
    
    if ($curl_error) {
        return [
            'success' => false,
            'error' => "Connection error: $curl_error"
        ];
    }
    
    // Handle HTTP errors
    if ($http_code >= 400) {
        return [
            'success' => false,
            'error' => "Server error: HTTP $http_code"
        ];
    }
    
    // Parse response
    $data = json_decode($response, true);
    
    if (!isset($data['success'])) {
        return [
            'success' => false,
            'error' => 'Invalid response from account system'
        ];
    }
    
    if (!$data['success']) {
        return [
            'success' => false,
            'error' => $data['message'] ?? 'Unknown error'
        ];
    }
    
    // Success, return transaction info
    return [
        'success' => true,
        'transaction_id' => $data['transaction_id'] ?? null,
        'new_balance' => $data['new_balance'] ?? null
    ];
}
?>