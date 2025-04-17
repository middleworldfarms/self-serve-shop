<?php
require_once 'includes/logger.php';

// After you've successfully created the order and have an $order_id
if ($order_id) {
    // Log the order creation
    log_order_event(
        $order_id, 
        'order_created', 
        [
            'total' => $cart_total,
            'item_count' => count($cart_items),
            'payment_method' => PAYMENT_PROVIDER
        ]
    );
    
    // Additional logging for specific payment methods
    if (PAYMENT_PROVIDER === 'manual') {
        log_order_event($order_id, 'manual_payment_selected', 'Customer chose to pay manually');
    } else if (PAYMENT_PROVIDER === 'stripe') {
        log_order_event($order_id, 'stripe_payment_initiated', 'Redirecting to Stripe payment');
    }
}