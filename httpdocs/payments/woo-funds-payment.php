<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

function processWooFundsPayment($order_id, $amount, $customer_email) {
    global $db;
    
    try {
        // Try to get WooCommerce site URL from constants first, then settings
        $woocommerce_site_url = defined('WOO_SITE_URL') ? WOO_SITE_URL : null;
        
        // If not defined in constants, try to get from database
        if (empty($woocommerce_site_url)) {
            $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'woocommerce_site_url'");
            $woocommerce_site_url = $stmt->fetchColumn();
        }
        
        if (empty($woocommerce_site_url)) {
            return [
                'success' => false,
                'error' => 'WooCommerce site URL not configured'
            ];
        }
        
        // Try to get API key from constants first, then settings
        $api_key = defined('WOO_FUNDS_API_KEY') ? WOO_FUNDS_API_KEY : null;
        
        // If not defined in constants, try to get from database
        if (empty($api_key)) {
            $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'woo_funds_api_key'");
            $api_key = $stmt->fetchColumn();
        }
        
        // Rest of the function remains the same...
        $endpoint = rtrim($woocommerce_site_url, '/') . '/wp-json/middleworld/v1/funds';
        
        // Prepare the request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'action' => 'deduct',
            'email' => $customer_email,
            'amount' => $amount,
            'order_id' => $order_id,
            'description' => 'Self-serve shop purchase #' . $order_id
        ]));
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-WC-API-Key: ' . $api_key
        ]);
        
        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        
        if ($err) {
            throw new Exception("cURL Error: " . $err);
        }
        
        $result = json_decode($response, true);
        
        if (!$result) {
            throw new Exception("Invalid response from WooCommerce site");
        }
        
        if (isset($result['success']) && $result['success'] === true) {
            return [
                'success' => true,
                'transaction_id' => $result['transaction_id'] ?? 'wf-' . time(),
                'new_balance' => $result['new_balance'] ?? null
            ];
        } else {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Insufficient funds or account not found'
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Payment processing error: ' . $e->getMessage()
        ];
    }
}