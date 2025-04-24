<?php
// Updated api-test.php to test a wider range of IDs
require_once 'config.php';
require_once 'payments/process-payment.php';

// Get settings
$settings = get_settings();

// Build API URL
$api_url = $settings['woo_shop_url'] ?? 'https://middleworldfarms.org';
$api_url = rtrim($api_url, '/') . '/wp-json/middleworld/v1/funds';
$api_key = $settings['woo_funds_api_key'] ?? '';

echo "<h1>API Connection Test - More User IDs</h1>";
echo "<p>Testing connection to: {$api_url}</p>";
echo "<p>Using API Key: " . substr($api_key, 0, 5) . "..." . substr($api_key, -5) . "</p>";

// Try more user IDs - test 1-30 to find your account
$user_ids_to_try = range(1, 30);

foreach ($user_ids_to_try as $user_id) {
    $request_data = [
        'action' => 'check',
        'email' => 'middleworldfarms@gmail.com',
        'amount' => 0.01,
        'user_id' => $user_id
    ];
    
    echo "<h2>Testing User ID: {$user_id}</h2>";
    
    // Make API request
    $response = wp_remote_post($api_url, [
        'timeout' => 45,
        'headers' => [
            'Content-Type' => 'application/json',
            'X-WC-API-Key' => $api_key
        ],
        'body' => json_encode($request_data)
    ]);
    
    // Check if this user has funds
    if (is_array($response) && !empty($response['body'])) {
        $body = json_decode($response['body'], true);
        if (!empty($body['current_balance']) && $body['current_balance'] > 0) {
            echo "<p style='color:green; font-weight:bold;'>✅ FOUND ACCOUNT WITH FUNDS!</p>";
            echo "<p>User ID {$user_id} has a balance of {$body['current_balance']}</p>";
            echo "<p>This is the correct user ID to use for account credit payments!</p>";
            break;  // Stop once we find the account with funds
        }
    }
    
    // Only show full response if requested
    if (isset($_GET['debug'])) {
        echo "<p>Response:</p><pre>";
        print_r($response);
        echo "</pre>";
    } else {
        echo "<p>Balance for User ID {$user_id}: 0</p>";
    }
    
    echo "<hr>";
}

// For SQL query reference (using the correct prefix):
echo "<h3>SQL Query Reference:</h3>";
echo "<pre>";
echo "SELECT u.ID, u.user_email, u.user_login, m.meta_value as balance
FROM D6sPMX_users u
JOIN D6sPMX_usermeta m ON u.ID = m.user_id
WHERE m.meta_key = 'woo_funds_balance' 
AND m.meta_value > 0;";
echo "</pre>";

// Direct test for user ID 26
echo "<h1>API Connection Test - Direct User ID</h1>";
echo "<p>Testing connection to: {$api_url}</p>";
echo "<p>Using API Key: " . substr($api_key, 0, 5) . "..." . substr($api_key, -5) . "</p>";

// Test directly with user ID 26
$request_data = [
    'action' => 'check',
    'email' => 'middleworldfarms@gmail.com',
    'amount' => 0.01,
    'user_id' => 26
];

echo "<h2>Testing User ID: 26</h2>";
echo "<p>Request data:</p><pre>";
print_r($request_data);
echo "</pre>";

// Make API request
$response = wp_remote_post($api_url, [
    'timeout' => 45,
    'headers' => [
        'Content-Type' => 'application/json',
        'X-WC-API-Key' => $api_key
    ],
    'body' => json_encode($request_data)
]);

echo "<h2>Response:</h2><pre>";
print_r($response);
echo "</pre>";