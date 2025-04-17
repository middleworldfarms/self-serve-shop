<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

try {
    $payload = @file_get_contents('php://input');
    $data = json_decode($payload);
    
    if ($data && isset($data->type) && $data->type === 'payment.updated') {
        $payment = $data->data->object->payment;
        
        if ($payment->status === 'COMPLETED') {
            $order_id = $payment->reference_id ?? null;
            $transaction_id = $payment->id;
            
            if ($order_id) {
                // Update order status
                $stmt = $db->prepare("UPDATE orders SET payment_status = 'paid', transaction_id = ? WHERE id = ?");
                $stmt->execute([$transaction_id, $order_id]);
            }
        }
    }
    
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit();
}
