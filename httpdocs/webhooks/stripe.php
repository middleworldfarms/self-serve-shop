<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

// Get Stripe credentials from settings
try {
    $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'stripe_webhook_secret'");
    $webhook_secret = $stmt->fetchColumn();

    \Stripe\Stripe::setApiKey($db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'stripe_secret_key'")->fetchColumn());

    $payload = @file_get_contents('php://input');
    $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

    try {
        $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        
        // Handle the event
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                $order_id = $paymentIntent->metadata->order_id ?? null;
                
                if ($order_id) {
                    // Update order status
                    $stmt = $db->prepare("UPDATE orders SET payment_status = 'paid', transaction_id = ? WHERE id = ?");
                    $stmt->execute([$paymentIntent->id, $order_id]);
                }
                break;
                
            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                $order_id = $paymentIntent->metadata->order_id ?? null;
                
                if ($order_id) {
                    // Update order status
                    $stmt = $db->prepare("UPDATE orders SET payment_status = 'failed', transaction_id = ? WHERE id = ?");
                    $stmt->execute([$paymentIntent->id, $order_id]);
                }
                break;
        }
        
        http_response_code(200);
        echo json_encode(['status' => 'success']);
        
    } catch(\UnexpectedValueException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payload']);
        exit();
    } catch(\Stripe\Exception\SignatureVerificationException $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid signature']);
        exit();
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit();
}
