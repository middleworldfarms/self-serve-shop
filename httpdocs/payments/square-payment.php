<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once 'includes/create_woocommerce_order.php';

function processSquarePayment($order_id, $amount, $currency = 'GBP', $source_id) {
    global $db;
    
    try {
        // Get Square API credentials from settings
        $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name IN ('square_app_id', 'square_access_token')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Initialize Square client
        $square_config = new \Square\Configuration();
        $square_config->setAccessToken($settings['square_access_token'] ?? '');
        $square_config->setEnvironment(\Square\Environment::PRODUCTION); // or SANDBOX for testing
        
        $square_client = new \Square\SquareClient($square_config);
        $payments_api = $square_client->getPaymentsApi();
        
        // Convert amount to cents (Square requires amounts in smallest denomination)
        $amount_in_cents = (int)($amount * 100);
        
        // Create a money object
        $money = new \Square\Models\Money();
        $money->setAmount($amount_in_cents);
        $money->setCurrency($currency);
        
        // Create payment request
        $payment_body = new \Square\Models\CreatePaymentRequest(
            $source_id,
            uniqid('', true), // Unique idempotency key
            $money
        );
        
        $payment_body->setReferenceId((string)$order_id);
        $payment_body->setNote('Self-serve shop purchase');
        
        // Process payment
        $response = $payments_api->createPayment($payment_body);
        
        if ($response->isSuccess()) {
            $payment = $response->getResult()->getPayment();
            create_woocommerce_order($order_id); // or pass $order_number, or adapt as needed
            return [
                'success' => true,
                'transaction_id' => $payment->getId(),
                'status' => $payment->getStatus()
            ];
        } else {
            $errors = $response->getErrors();
            return [
                'success' => false,
                'error' => $errors[0]->getDetail()
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Payment processing error: ' . $e->getMessage()
        ];
    }
}
