<?php
/**
 * Plugin Name: MiddleWorld Farms Credit System
 * Description: Integrates WooCommerce Funds with the self-serve shop
 * Version: 1.0
 * Author: MiddleWorld Farms
 */

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Register the REST API endpoints
add_action('rest_api_init', function () {
    register_rest_route('middleworld/v1', '/funds', array(
        'methods' => 'POST',
        'callback' => 'mwf_process_funds_request',
        'permission_callback' => 'mwf_api_permissions_check'
    ));
});

// Check API permissions
function mwf_api_permissions_check($request) {
    // Get the API key from the request headers
    $api_key = $request->get_header('X-WC-API-Key');
    
    // Validate against stored API key
    $valid_key = get_option('mwf_funds_api_key');
    
    return ($api_key === $valid_key);
}

// Process funds API requests
function mwf_process_funds_request($request) {
    $params = $request->get_json_params();
    
    // Validate required parameters
    if (!isset($params['action']) || !isset($params['email']) || !isset($params['amount'])) {
        return new WP_Error('missing_parameters', 'Required parameters are missing', array('status' => 400));
    }
    
    $action = $params['action'];
    $email = sanitize_email($params['email']);
    $amount = floatval($params['amount']);
    $order_id = sanitize_text_field($params['order_id'] ?? '');
    $description = sanitize_text_field($params['description'] ?? 'Self-serve shop transaction');
    
    // Find user by email
    $user = get_user_by('email', $email);
    
    if (!$user) {
        return array(
            'success' => false,
            'error' => 'User not found'
        );
    }
    
    // Get current balance (assuming you're using User Meta for storing funds)
    $current_balance = floatval(get_user_meta($user->ID, 'woo_funds_balance', true));
    
    if ($action === 'check') {
        // Just check if user has sufficient funds
        return array(
            'success' => true,
            'has_funds' => ($current_balance >= $amount),
            'current_balance' => $current_balance
        );
    } 
    else if ($action === 'deduct') {
        // Check if sufficient funds
        if ($current_balance < $amount) {
            return array(
                'success' => false,
                'error' => 'Insufficient funds',
                'current_balance' => $current_balance,
                'required' => $amount
            );
        }
        
        // Deduct funds
        $new_balance = $current_balance - $amount;
        update_user_meta($user->ID, 'woo_funds_balance', $new_balance);
        
        // Record the transaction
        $transaction_id = 'ssf-' . time() . '-' . rand(1000, 9999);
        
        // Store transaction in custom table or user meta
        add_user_meta($user->ID, 'woo_funds_transaction', array(
            'transaction_id' => $transaction_id,
            'type' => 'deduct',
            'amount' => $amount,
            'order_id' => $order_id,
            'description' => $description,
            'date' => current_time('mysql')
        ));
        
        return array(
            'success' => true,
            'transaction_id' => $transaction_id,
            'previous_balance' => $current_balance,
            'new_balance' => $new_balance
        );
    }
    
    return new WP_Error('invalid_action', 'Invalid action specified', array('status' => 400));
}

// Add admin page for settings
add_action('admin_menu', 'mwf_funds_admin_menu');

function mwf_funds_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'Self-Serve Shop Integration',
        'Self-Serve Shop',
        'manage_options',
        'mwf-funds-settings',
        'mwf_funds_settings_page'
    );
}

function mwf_funds_settings_page() {
    // Save settings
    if (isset($_POST['mwf_funds_save_settings'])) {
        check_admin_referer('mwf_funds_settings');
        
        $api_key = sanitize_text_field($_POST['mwf_funds_api_key']);
        update_option('mwf_funds_api_key', $api_key);
        
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
    
    // Get current settings
    $api_key = get_option('mwf_funds_api_key', '');
    if (empty($api_key)) {
        // Generate a random API key if none exists
        $api_key = wp_generate_password(32, false);
        update_option('mwf_funds_api_key', $api_key);
    }
    
    ?>
    <div class="wrap">
        <h1>Self-Serve Shop Integration</h1>
        <form method="post" action="">
            <?php wp_nonce_field('mwf_funds_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="text" size="40" name="mwf_funds_api_key" value="<?php echo esc_attr($api_key); ?>" />
                        <p class="description">This key is used to authenticate API requests from your self-serve shop.</p>
                    </td>
                </tr>
            </table>
            <p>
                <input type="submit" name="mwf_funds_save_settings" class="button button-primary" value="Save Settings" />
            </p>
        </form>
    </div>
    <?php
}

// Credit funds on WooCommerce Subscription renewal
add_action('woocommerce_subscription_renewal_payment_complete', function($subscription) {
    $user_id = $subscription->get_user_id();
    $amount = $subscription->get_total(); // Or set a fixed amount per plan

    if ($user_id && $amount > 0) {
        $current_funds = (float) get_user_meta($user_id, 'woo_funds_balance', true);
        update_user_meta($user_id, 'woo_funds_balance', $current_funds + $amount);

        // Log the credit transaction
        add_user_meta($user_id, 'woo_funds_transaction', array(
            'transaction_id' => 'credit-' . time() . '-' . rand(1000, 9999),
            'type' => 'credit',
            'amount' => $amount,
            'order_id' => $subscription->get_id(),
            'description' => 'Subscription renewal credit',
            'date' => current_time('mysql')
        ));
    }
});