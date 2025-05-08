<?php
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/create_woocommerce_order.php';
require_once __DIR__ . '/../includes/get_products.php';

// Get form data
$customer_email = $_POST['email'] ?? $_POST['woo_funds_email'] ?? '';
$customer_password = $_POST['woo_funds_password'] ?? '';
$amount = $_POST['amount'] ?? $_SESSION['cart_total'] ?? 0;

// Debug what we're sending
error_log("Direct funds payment: email=$customer_email, password=******, amount=$amount");

// Validate inputs
if (empty($customer_email) || empty($customer_password) || empty($amount)) {
    exit('Account credit payment failed: Missing required information (email, password, or amount).');
}

// Get the API credentials
$settings = get_settings();
$woocommerce_site_url = $settings['woo_shop_url'] ?? '';
$api_key = $settings['woo_funds_api_key'] ?? '';

if (empty($woocommerce_site_url) || empty($api_key)) {
    exit('Account credit payment failed: Missing API credentials.');
}

// Prepare cart_items session for Woo sync WITH PRODUCT DETAILS
$_SESSION['cart_items'] = [];
foreach ($_SESSION['cart'] as $product_id => $quantity) {
    // Get complete product details including name and price
    $product = get_product_details($product_id);
    
    $_SESSION['cart_items'][] = [
        'id' => $product_id,
        'name' => $product['name'],
        'price' => $product['price'],
        'quantity' => $quantity
    ];
}

// Call the API directly
$endpoint = rtrim($woocommerce_site_url, '/') . '/wp-json/mwf/v1/funds';
error_log("Making API call: endpoint=$endpoint, email=$customer_email, amount=$amount");

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'action' => 'deduct',
    'email' => $customer_email,
    'password' => $customer_password,
    'amount' => $amount
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'X-WC-API-Key: ' . $api_key
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

// Debug the API response
error_log("API Response: status=$http_code, response=$response, error=$err");

if ($err) {
    exit('Account credit payment failed: ' . $err);
}

if ($http_code !== 200) {
    exit('Account credit payment failed: HTTP error ' . $http_code . ': ' . $response);
}

$result = json_decode($response, true);
if (!$result || empty($result['success'])) {
    exit('Account credit payment failed: ' . ($result['error'] ?? 'Unknown error'));
}

// If we get here, payment was successful
$transaction_id = $result['transaction_id'] ?? '';
$new_balance = $result['new_balance'] ?? 0;

// Debugging line after all API calls
error_log("API Response successful. Transaction ID: $transaction_id, New balance: $new_balance");

// Build WooCommerce order data
$manual_order_data = [
    'payment_method' => 'woo_funds',
    'payment_method_title' => 'Account Funds',
    'set_paid' => true,
    'billing' => [
        'first_name' => $_POST['name'] ?? 'Customer',
        'email' => $customer_email
    ],
    'shipping' => [
        'first_name' => $_POST['name'] ?? 'Customer'
    ],
    'line_items' => $_SESSION['cart_items'],
    'meta_data' => [
        [
            'key' => '_self_serve_purchase',
            'value' => 'yes'
        ],
        [
            'key' => '_woo_funds_transaction_id',
            'value' => $transaction_id
        ]
    ]
];

// Sync order to WooCommerce
$order_id = create_woocommerce_order(null, $manual_order_data);

// Also store order in local database so email receipt works
try {
    // Generate a unique order number if needed
    $order_number = time() . rand(1000, 9999);
    
    // Connect to the database
    $db = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
        DB_USER,
        DB_PASS
    );
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Insert the order into database
    $stmt = $db->prepare("
        INSERT INTO orders (
            order_number, customer_name, customer_email, 
            total_amount, payment_method, payment_status, 
            items, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        $order_number,
        $_POST['name'] ?? 'Customer', 
        $customer_email,
        $amount,
        'woo_funds',
        'paid',
        json_encode($_SESSION['cart_items'])
    ]);
    
    // Get local database ID and use it
    $local_order_id = $db->lastInsertId();
    $_SESSION['last_order_id'] = $local_order_id;
    $order_id = $local_order_id; // Replace WooCommerce ID with local ID
    
    error_log("Account funds order saved to local database: $local_order_id");
} catch (PDOException $e) {
    error_log("Failed to save order to local database: " . $e->getMessage());
}

// Generate a unique order number if needed
if (!$order_id) {
    $order_id = time() . rand(1000, 9999);
}

// Store last order id in session
$_SESSION['last_order_id'] = $order_id;
$_SESSION['woo_funds_balance'] = $new_balance;
$_SESSION['payment_method'] = 'woo_funds'; // Make sure payment method is set!

// Clear cart
$_SESSION['cart'] = [];

// Log what we're about to do - add more info
error_log("About to redirect to confirmation page. Order ID: $order_id, Session ID: " . session_id());

// Make sure we have a clean output buffer (no whitespace or other output)
if (ob_get_level()) ob_end_clean();

// Redirect to confirmation page with absolute path and debug info
header("Location: /order_confirmation.php?order_id=" . $order_id . "&debug=1");
exit; // Make sure this exit is here!
