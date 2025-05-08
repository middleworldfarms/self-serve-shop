<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/create_woocommerce_order.php';

// Prepare cart_items session for Woo sync
$_SESSION['cart_items'] = [];
foreach ($_SESSION['cart'] as $product_id => $quantity) {
    $_SESSION['cart_items'][] = [
        'id' => $product_id,
        'quantity' => $quantity
    ];
}

$customer_name = $_POST['name'] ?? 'Guest';
$customer_email = $_POST['email'] ?? '';
$amount = $_POST['amount'] ?? 0;

// Deduct funds from remote Woo Funds plugin
$deduct_result = processWooFundsDeduction($customer_email, $amount);
if (!$deduct_result['success']) {
    exit('Insufficient funds or error processing payment: ' . htmlspecialchars($deduct_result['error']));
}

// Build WooCommerce order data
$manual_order_data = [
    'payment_method' => 'woo_funds', // Use your gateway's slug
    'payment_method_title' => 'Account Funds',
    'set_paid' => true,
    'billing' => [
        'first_name' => $customer_name,
        'email' => $customer_email
    ],
    'shipping' => [
        'first_name' => $customer_name
    ],
    'line_items' => $_SESSION['cart_items'],
    'meta_data' => [
        [
            'key' => '_self_serve_purchase',
            'value' => 'yes'
        ],
        [
            'key' => '_woo_funds_transaction_id',
            'value' => $deduct_result['transaction_id'] ?? ''
        ]
    ]
];

// Sync order to WooCommerce
$order_id = create_woocommerce_order(null, $manual_order_data);

// Redirect to order confirmation page
if ($order_id) {
    // Save order ID in session for confirmation page
    $_SESSION['last_order_id'] = $order_id;
    // Add email_sent=1 to the URL
    header("Location: /order_confirmation.php?order_id=" . urlencode($order_id) . "&email_sent=1");
    exit;
} else {
    exit('Order failed to sync to WooCommerce.');
}

// --- Deduct funds helper ---
function processWooFundsDeduction($customer_email, $amount) {
    $settings = get_settings();
    $woocommerce_site_url = $settings['woo_shop_url'] ?? '';
    $api_key = $settings['woo_funds_api_key'] ?? '';

    if (empty($woocommerce_site_url) || empty($api_key)) {
        return [
            'success' => false,
            'error' => 'WooCommerce site URL or API key not configured'
        ];
    }

    $endpoint = rtrim($woocommerce_site_url, '/') . '/wp-json/mwf/v1/funds';

    error_log("Deducting funds: endpoint=$endpoint, email=$customer_email, amount=$amount, api_key=$api_key");

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'deduct',
        'email' => $customer_email,
        'amount' => $amount
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-WC-API-Key: ' . $api_key
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        return [
            'success' => false,
            'error' => "cURL Error: $err"
        ];
    }

    if ($http_code !== 200) {
        return [
            'success' => false,
            'error' => "HTTP error: $http_code, response: $response"
        ];
    }

    $result = json_decode($response, true);
    if (!$result || empty($result['success'])) {
        return [
            'success' => false,
            'error' => $result['error'] ?? 'Unknown error'
        ];
    }
    return [
        'success' => true,
        'transaction_id' => $result['transaction_id'] ?? null,
        'new_balance' => $result['new_balance'] ?? null
    ];
}