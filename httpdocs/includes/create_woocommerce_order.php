<?php
require_once __DIR__ . '/get-woo-credentials.php';

/**
 * Create a WooCommerce order via the REST API
 */
function create_woocommerce_order($order_id, $order_data = null) {
    global $db;
    
    // Add detailed logging for troubleshooting
    error_log("Creating WooCommerce order for local order #{$order_id}");
    
    try {
        // If no order data is provided, build it from the database
        if ($order_data === null) {
            // Get order details
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                error_log("Order not found: $order_id");
                return false;
            }
            
            // Try to get order items from order_items table
            $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $stmt->execute([$order_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no items found in order_items, try JSON in orders table
            if (empty($items) && !empty($order['items'])) {
                $items = json_decode($order['items'], true) ?: [];
            }
            
            // Build line items with WooCommerce IDs
            $line_items = [];
            foreach ($items as $item) {
                $product_id = $item['product_id'] ?? $item['id'] ?? null;
                
                if ($product_id) {
                    // Get WooCommerce ID
                    $stmt = $db->prepare("SELECT woocommerce_id FROM sss_products WHERE id = ?");
                    $stmt->execute([$product_id]);
                    $woo_id = $stmt->fetchColumn();
                    
                    if ($woo_id) {
                        $line_items[] = [
                            'product_id' => (int)$woo_id,
                            'quantity' => (int)($item['quantity'] ?? 1)
                        ];
                    }
                }
            }
            
            // Build order data
            $order_data = [
                'payment_method' => $order['payment_method'],
                'payment_method_title' => ucfirst($order['payment_method']),
                'set_paid' => true,
                'status' => 'processing',
                'billing' => [
                    'first_name' => $order['customer_name'] ?: 'Cash Customer',
                    'email' => $order['customer_email'] ?: 'guest@example.com'
                ],
                'line_items' => $line_items
            ];
        }
        
        // Get API credentials
        $settings = get_settings();
        $woo_url = $settings['woo_shop_url'] ?? '';
        $wc_key = $settings['woo_consumer_key'] ?? '';
        $wc_secret = $settings['woo_consumer_secret'] ?? '';
        
        // Debug API settings
        error_log("WooCommerce API settings - URL: " . substr($woo_url, 0, 30) . "..., Key: " . substr($wc_key, 0, 5) . "...");
        
        if (empty($wc_key) || empty($wc_secret) || empty($woo_url)) {
            error_log("Missing WooCommerce API credentials");
            return false;
        }
        
        // Ensure URL format is correct
        $woo_url = rtrim($woo_url, '/');
        
        // Set up API request
        $endpoint = "$woo_url/wp-json/wc/v3/orders";
        $auth = base64_encode($wc_key . ':' . $wc_secret);
        
        // Set up cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Basic ' . $auth
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For test environments
        
        // Execute request
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        // Log detailed API response
        error_log("WooCommerce API response - Status: $status, Error: $error");
        error_log("Response: " . substr($response, 0, 500)); // Log first 500 chars
        
        if ($error) {
            error_log("cURL Error: $error");
            return false;
        }
        
        if ($status < 200 || $status >= 300) {
            error_log("API Error (Status $status): $response");
            return false;
        }
        
        // Parse response
        $data = json_decode($response, true);
        $woo_order_id = $data['id'] ?? null;
        
        if ($woo_order_id) {
            error_log("WooCommerce order #{$woo_order_id} created successfully");
            
            // Update local order with WooCommerce ID
            try {
                $stmt = $db->prepare("UPDATE orders SET woocommerce_order_id = ? WHERE id = ?");
                $stmt->execute([$woo_order_id, $order_id]);
            } catch (Exception $e) {
                error_log("Failed to update order with WooCommerce ID: " . $e->getMessage());
                // Continue anyway - the order was created successfully
            }
            
            return $woo_order_id;
        }
        
        error_log("Failed to get WooCommerce order ID from response");
        return false;
        
    } catch (Exception $e) {
        error_log("Exception in create_woocommerce_order: " . $e->getMessage());
        return false;
    }
}