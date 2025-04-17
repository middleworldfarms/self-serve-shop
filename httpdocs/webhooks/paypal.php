<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

try {
    $payload = @file_get_contents('php://input');
    $data = json_decode($payload);
    
    if ($data && $data->event_type === 'PAYMENT.CAPTURE.COMPLETED') {
        $transaction_id = $data->resource->id;
        $custom_id = $data->resource->custom_id ?? null; // This would be your order ID if you set it
        
        if ($custom_id) {
            // Update order status
            $stmt = $db->prepare("UPDATE orders SET payment_status = 'paid', transaction_id = ? WHERE id = ?");
            $stmt->execute([$transaction_id, $custom_id]);
        }
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit();
}
