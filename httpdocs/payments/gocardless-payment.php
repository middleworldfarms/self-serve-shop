<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once 'includes/create_woocommerce_order.php';

function processGoCardlessPayment($order_id, $amount, $payment_data) {
    global $db;
    
    try {
        // Get GoCardless API credentials from settings
        $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name IN ('gocardless_access_token', 'gocardless_environment')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $access_token = $settings['gocardless_access_token'] ?? '';
        $environment = $settings['gocardless_environment'] ?? 'sandbox';
        
        // Initialize GoCardless client
        $client = new \GoCardlessPro\Client([
            'access_token' => $access_token,
            'environment'  => $environment === 'live' ? \GoCardlessPro\Environment::LIVE : \GoCardlessPro\Environment::SANDBOX
        ]);
        
        // Handle different payment flows
        if (!empty($payment_data['redirect_flow_id'])) {
            // Complete the redirect flow to confirm the mandate
            $redirect_flow = $client->redirectFlows()->complete(
                $payment_data['redirect_flow_id'],
                ['session_token' => session_id()]
            );
            
            // Store the customer and mandate IDs for future use
            $mandate_id = $redirect_flow->links->mandate;
            $customer_id = $redirect_flow->links->customer;
            
            // Save mandate and customer IDs for this user
            if (!empty($payment_data['customer_id'])) {
                $stmt = $db->prepare("INSERT INTO customer_payment_methods (customer_id, payment_method, method_details) VALUES (?, 'gocardless', ?)");
                $stmt->execute([$payment_data['customer_id'], json_encode(['mandate_id' => $mandate_id, 'customer_id' => $customer_id])]);
            }
            
            // Create a payment using this mandate
            $payment = $client->payments()->create([
                'amount' => (int)($amount * 100), // amount in pence/cents
                'currency' => 'GBP',
                'links' => [
                    'mandate' => $mandate_id
                ],
                'metadata' => [
                    'order_id' => (string)$order_id
                ],
                'description' => 'Self-serve shop order #' . $order_id
            ]);
            
            create_woocommerce_order($order_id);
            
            return [
                'success' => true,
                'transaction_id' => $payment->id,
                'status' => $payment->status
            ];
        } 
        elseif (!empty($payment_data['mandate_id'])) {
            // Use existing mandate
            $payment = $client->payments()->create([
                'amount' => (int)($amount * 100), // amount in pence/cents
                'currency' => 'GBP',
                'links' => [
                    'mandate' => $payment_data['mandate_id']
                ],
                'metadata' => [
                    'order_id' => (string)$order_id
                ],
                'description' => 'Self-serve shop order #' . $order_id
            ]);
            
            create_woocommerce_order($order_id);
            
            return [
                'success' => true,
                'transaction_id' => $payment->id,
                'status' => $payment->status
            ];
        }
        else {
            // Start a new redirect flow to get customer's bank details
            $redirect_flow = $client->redirectFlows()->create([
                'description' => 'Self-serve shop payment',
                'session_token' => session_id(),
                'success_redirect_url' => rtrim(SITE_URL, '/') . '/checkout.php?gocardless_complete=1&order_id=' . $order_id,
                'prefilled_customer' => [
                    'email' => $payment_data['email'] ?? '',
                    'given_name' => $payment_data['first_name'] ?? '',
                    'family_name' => $payment_data['last_name'] ?? ''
                ]
            ]);
            
            // Store the flow ID in the session
            $_SESSION['gocardless_flow_id'] = $redirect_flow->id;
            $_SESSION['gocardless_order_id'] = $order_id;
            
            return [
                'success' => false,
                'redirect' => $redirect_flow->redirect_url
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Payment processing error: ' . $e->getMessage()
        ];
    }
}

// Add these settings to your database
$stmt = $db->prepare("INSERT INTO self_serve_settings (setting_name, setting_value) VALUES
('enable_gocardless', '0'),
('gocardless_access_token', ''),
('gocardless_environment', 'sandbox'),
('gocardless_webhook_secret', '')");
$stmt->execute();

// Create a table to store customer payment methods
$stmt = $db->prepare("CREATE TABLE IF NOT EXISTS customer_payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    method_details TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (customer_id, payment_method)
)");
$stmt->execute();