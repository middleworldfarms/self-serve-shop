<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once 'includes/create_woocommerce_order.php';

function processPayPalPayment($order_id, $amount, $paypal_order_id) {
    global $db;
    
    try {
        // Get PayPal credentials from settings
        $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name IN ('paypal_client_id', 'paypal_secret')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $client_id = $settings['paypal_client_id'] ?? '';
        $client_secret = $settings['paypal_secret'] ?? '';
        
        // Use REST API directly since the SDK is abandoned
        $ch = curl_init();
        
        // Get access token
        curl_setopt($ch, CURLOPT_URL, 'https://api.paypal.com/v1/oauth2/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
        
        $headers = array();
        $headers[] = 'Accept: application/json';
        $headers[] = 'Accept-Language: en_US';
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            throw new Exception('PayPal Error: ' . curl_error($ch));
        }
        
        $auth = json_decode($result);
        $access_token = $auth->access_token;
        
        // Capture the order payment
        curl_setopt($ch, CURLOPT_URL, "https://api.paypal.com/v2/checkout/orders/{$paypal_order_id}/capture");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        
        $headers = array();
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Authorization: Bearer ' . $access_token;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('PayPal Error: ' . curl_error($ch));
        }
        
        $response = json_decode($result);
        
        if ($response->status === 'COMPLETED') {
            create_woocommerce_order($order_id); // or pass $order_number, or adapt as needed
            return [
                'success' => true,
                'transaction_id' => $response->id,
                'status' => $response->status
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Payment failed: ' . $response->status
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Payment processing error: ' . $e->getMessage()
        ];
    }
}
