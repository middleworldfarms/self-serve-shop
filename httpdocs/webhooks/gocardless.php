<?php
// Create file: /var/www/vhosts/middleworldfarms.org/self-serve-shop/webhooks/gocardless.php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';

try {
    // Get webhook secret
    $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'gocardless_webhook_secret'");
    $webhook_secret = $stmt->fetchColumn();
    
    // Get JSON payload and signature header
    $payload = file_get_contents('php://input');
    $signature_header = $_SERVER['HTTP_WEBHOOK_SIGNATURE'] ?? '';
    
    // Verify webhook signature
    $calculated_signature = hash_hmac('sha256', $payload, $webhook_secret);
    if ($signature_header !== $calculated_signature) {
        http_response_code(401);
        exit('Invalid signature');
    }
    
    // Parse events
    $events = json_decode($payload, true);
    
    if (isset($events['events']) && is_array($events['events'])) {
        foreach ($events['events'] as $event) {
            switch ($event['resource_type']) {
                case 'payments':
                    $payment_id = $event['links']['payment'];
                    
                    // Get access token
                    $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'gocardless_access_token'");
                    $access_token = $stmt->fetchColumn();
                    $stmt = $db->query("SELECT setting_value FROM self_serve_settings WHERE setting_name = 'gocardless_environment'");
                    $environment = $stmt->fetchColumn();
                    
                    // Initialize GoCardless client
                    $client = new \GoCardlessPro\Client([
                        'access_token' => $access_token,
                        'environment'  => $environment === 'live' ? \GoCardlessPro\Environment::LIVE : \GoCardlessPro\Environment::SANDBOX
                    ]);
                    
                    // Get payment details to find order ID
                    $payment = $client->payments()->get($payment_id);
                    $order_id = $payment->metadata['order_id'] ?? null;
                    
                    if ($order_id) {
                        switch ($event['action']) {
                            case 'confirmed':
                                // Payment successful
                                $stmt = $db->prepare("UPDATE orders SET payment_status = 'paid', transaction_id = ? WHERE id = ?");
                                $stmt->execute([$payment_id, $order_id]);
                                break;
                                
                            case 'failed':
                            case 'cancelled':
                                // Payment failed
                                $stmt = $db->prepare("UPDATE orders SET payment_status = 'failed', transaction_id = ? WHERE id = ?");
                                $stmt->execute([$payment_id, $order_id]);
                                break;
                                
                            case 'charged_back':
                                // Payment charged back
                                $stmt = $db->prepare("UPDATE orders SET payment_status = 'refunded', transaction_id = ? WHERE id = ?");
                                $stmt->execute([$payment_id, $order_id]);
                                break;
                        }
                    }
                    break;
                    
                // Add more event types as needed (e.g., mandates)
            }
        }
    }
    
    http_response_code(200);
    echo 'Webhook processed';
} catch (Exception $e) {
    http_response_code(500);
    error_log('GoCardless webhook error: ' . $e->getMessage());
    echo 'Error processing webhook';
}