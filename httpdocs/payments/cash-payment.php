<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../debug-logger.php';

/**
 * Process a cash payment - simplified version without WooCommerce integration
 */
function process_cash_payment($order_details) {
    global $db;

    debug_log('CASH PAYMENT STARTED', $order_details);
    
    try {
        // Generate a unique order number
        $order_number = 'CASH-' . date('Ymd') . '-' . rand(1000, 9999);
        
        // Create local order
        $stmt = $db->prepare("
            INSERT INTO orders (
                order_number, 
                payment_method, 
                customer_name, 
                customer_email,
                total_amount, 
                payment_status,
                order_status
            ) VALUES (?, 'cash', ?, ?, ?, 'completed', 'completed')
        ");
        $stmt->execute([
            $order_number, 
            $order_details['customer_name'], 
            $order_details['customer_email'] ?? '',
            $order_details['total_amount']
        ]);
        $local_order_id = $db->lastInsertId();
        
        // Store items as JSON in the orders table
        $items_json = json_encode($order_details['items']);
        $stmt = $db->prepare("UPDATE orders SET items = ? WHERE id = ?");
        $stmt->execute([$items_json, $local_order_id]);
        
        // Also add items to order_items table for reporting
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
        
        // Return success with order details
        return [
            'success' => true,
            'order_id' => $local_order_id,
            'order_number' => $order_number,
            'payment_method' => 'cash',
            'payment_method_title' => 'Cash Payment'
        ];
        
    } catch (PDOException $e) {
        error_log("Cash payment error: " . $e->getMessage());
        return [
            'success' => false,
            'error' => "Error processing payment: " . $e->getMessage()
        ];
    }
}