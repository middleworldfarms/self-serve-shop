<?php
/*
Plugin Name: Middleworld Farms Integration
Description: Provides custom REST API endpoints for Laravel admin and self-serve shop integration.
Version: 1.0
Author: Middleworld Farms
Text Domain: mwf-integration
*/

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// Define plugin constants
define('MWF_INTEGRATION_VERSION', '1.0');
define('MWF_INTEGRATION_PATH', plugin_dir_path(__FILE__));

/**
 * Register the REST API endpoints
 */
add_action('rest_api_init', function () {
    register_rest_route('mwf/v1', '/funds', [
        'methods' => 'POST',
        'callback' => 'mwf_process_funds_request',
        'permission_callback' => '__return_true'
    ]);
    register_rest_route('mwf/v1', '/funds/add', [
        'methods' => 'POST',
        'callback' => 'mwf_add_funds_request',
        'permission_callback' => 'mwf_api_permissions_check'
    ]);
    register_rest_route('mwf/v1', '/create-order', [
        'methods' => 'POST',
        'callback' => 'mwf_create_order_endpoint',
        'permission_callback' => '__return_true'
    ]);
});

/**
 * API key permissions check for admin endpoints
 */
function mwf_api_permissions_check($request) {
    $api_key = $request->get_header('X-WC-API-Key');
    $stored_api_key = get_option('mwf_funds_api_key', 'Ffsh8yhsuZEGySvLrP0DihCDDwhPwk4h');
    return $api_key === $stored_api_key;
}

/**
 * Process funds API requests (check/deduct)
 */
function mwf_process_funds_request($request) {
    $params = $request->get_json_params();
    $action = $params['action'] ?? '';
    $email = $params['email'] ?? '';
    $amount = floatval($params['amount'] ?? 0);
    $order_id = $params['order_id'] ?? '';
    $description = $params['description'] ?? 'Self-serve shop purchase';

    // API key authentication
    $api_key = $request->get_header('X-WC-API-Key');
    $stored_api_key = get_option('mwf_funds_api_key', 'Ffsh8yhsuZEGySvLrP0DihCDDwhPwk4h');
    if ($api_key !== $stored_api_key) {
        return ['success' => false, 'message' => 'Invalid API key'];
    }

    $user = get_user_by('email', $email);
    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }

    $current_balance = mwf_get_user_balance($user->ID);

    if ($action === 'check') {
        return [
            'success' => true,
            'has_funds' => ($current_balance >= $amount),
            'current_balance' => $current_balance
        ];
    } elseif ($action === 'deduct' && $amount > 0) {
        if ($current_balance < $amount) {
            return [
                'success' => false,
                'message' => 'Insufficient funds',
                'current_balance' => $current_balance
            ];
        }
        $new_balance = $current_balance - $amount;
        update_user_meta($user->ID, 'account_funds', $new_balance);
        $transaction_id = mwf_record_transaction($user->ID, 'deduct', $amount, $order_id, $description);
        return [
            'success' => true,
            'transaction_id' => $transaction_id,
            'new_balance' => $new_balance
        ];
    }

    return ['success' => false, 'message' => 'Invalid action'];
}

/**
 * Add funds via API (admin only)
 */
function mwf_add_funds_request($request) {
    $params = $request->get_json_params();
    if (!isset($params['email']) || !isset($params['amount'])) {
        return new WP_Error('missing_parameters', 'Required parameters are missing', array('status' => 400));
    }
    $email = sanitize_email($params['email']);
    $amount = floatval($params['amount']);
    if ($amount <= 0) {
        return new WP_Error('invalid_amount', 'Amount must be greater than zero', array('status' => 400));
    }
    $order_id = isset($params['order_id']) ? sanitize_text_field($params['order_id']) : '';
    $description = isset($params['description']) ? sanitize_text_field($params['description']) : 'Manual credit';

    $user = get_user_by('email', $email);
    if (!$user) {
        return array('success' => false, 'error' => 'User not found');
    }
    $current_balance = mwf_get_user_balance($user->ID);
    $new_balance = $current_balance + $amount;
    update_user_meta($user->ID, 'account_funds', $new_balance);
    $transaction_id = mwf_record_transaction($user->ID, 'credit', $amount, $order_id, $description);

    return array(
        'success' => true,
        'transaction_id' => $transaction_id,
        'previous_balance' => $current_balance,
        'new_balance' => $new_balance
    );
}

/**
 * Get user balance with fallback for new users
 */
function mwf_get_user_balance($user_id) {
    $balance = get_user_meta($user_id, 'account_funds', true);
    if ($balance === '' || $balance === false) {
        $balance = 0;
        update_user_meta($user_id, 'account_funds', $balance);
    }
    return floatval($balance);
}

/**
 * Record a funds transaction
 */
function mwf_record_transaction($user_id, $type, $amount, $order_id, $description) {
    $transaction_id = $type . '-' . time() . '-' . rand(1000, 9999);
    $transaction_data = array(
        'transaction_id' => $transaction_id,
        'type' => $type,
        'amount' => $amount,
        'order_id' => $order_id,
        'description' => $description,
        'date' => current_time('mysql')
    );
    add_user_meta($user_id, 'woo_funds_transaction', $transaction_data);
    do_action('mwf_funds_transaction_recorded', $user_id, $transaction_data);
    return $transaction_id;
}

/**
 * Create order endpoint for self-serve shop
 */
function mwf_create_order_endpoint($request) {
    $data = $request->get_params();
    $secret_key = $data['secret_key'] ?? '';
    if ($secret_key !== get_option('sss_secret_key', 'your-custom-secret-key')) {
        return new WP_Error('unauthorized', 'Invalid secret key', ['status' => 401]);
    }
    $order = wc_create_order();
    $order->set_billing_email($data['customer_email'] ?? '');
    $order->set_billing_first_name($data['customer_name'] ?? 'Guest');
    foreach ($data['items'] as $item) {
        $product_id = $item['woo_product_id'] ?? 0;
        if (!$product_id && !empty($item['sku'])) {
            $product_id = wc_get_product_id_by_sku($item['sku']);
        }
        if ($product_id && $product = wc_get_product($product_id)) {
            $order->add_product($product, $item['quantity']);
        } else {
            $order->add_item([
                'name' => $item['name'],
                'qty' => $item['quantity'],
                'total' => $item['price'] * $item['quantity']
            ]);
        }
    }
    $order->set_payment_method('manual');
    $order->set_payment_method_title('Self-Serve Shop Honor Box');
    $order->update_status('completed');
    $order->calculate_totals();
    $order->add_order_note("Order created from Self-Serve Shop");
    $order->save();
    return [
        'success' => true,
        'order_id' => $order->get_id()
    ];
}

/**
 * Admin menu and settings page
 */
add_action('admin_menu', 'mwf_funds_admin_menu');
function mwf_funds_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'MWF Integration',
        'MWF Integration',
        'manage_options',
        'mwf-funds-settings',
        'mwf_funds_settings_page'
    );
    add_submenu_page(
        'woocommerce',
        'MWF Logs',
        'MWF Logs',
        'manage_options',
        'mwf-funds-logs',
        'mwf_funds_logs_page'
    );
}

function mwf_funds_settings_page() {
    if (isset($_POST['mwf_funds_save_settings'])) {
        check_admin_referer('mwf_funds_settings');
        $api_key = sanitize_text_field($_POST['mwf_funds_api_key']);
        update_option('mwf_funds_api_key', $api_key);
        $enable_logging = sanitize_text_field($_POST['mwf_funds_enable_logging'] ?? 'no');
        update_option('mwf_funds_enable_logging', $enable_logging);
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
    if (isset($_POST['mwf_funds_regenerate_key'])) {
        check_admin_referer('mwf_funds_settings');
        $api_key = wp_generate_password(32, false);
        update_option('mwf_funds_api_key', $api_key);
        echo '<div class="notice notice-success"><p>API key regenerated successfully!</p></div>';
    }
    $api_key = get_option('mwf_funds_api_key', '');
    if (empty($api_key)) {
        $api_key = wp_generate_password(32, false);
        update_option('mwf_funds_api_key', $api_key);
    }
    $enable_logging = get_option('mwf_funds_enable_logging', 'yes');
    update_option('sss_secret_key', 'your-custom-secret-key');
    ?>
    <div class="wrap">
        <h1>MWF Integration Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('mwf_funds_settings'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">API Key</th>
                    <td>
                        <input type="text" size="40" name="mwf_funds_api_key" value="<?php echo esc_attr($api_key); ?>" />
                        <p class="description">This key is used to authenticate API requests from your self-serve shop or Laravel admin.</p>
                        <input type="submit" name="mwf_funds_regenerate_key" class="button" value="Regenerate Key" />
                    </td>
                </tr>
                <tr>
                    <th scope="row">Enable Logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="mwf_funds_enable_logging" value="yes" <?php checked('yes', $enable_logging); ?> />
                            Enable transaction and error logging
                        </label>
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

function mwf_funds_logs_page() {
    if (isset($_POST['mwf_clear_logs']) && check_admin_referer('mwf_clear_logs')) {
        update_option('mwf_funds_logs', array());
        update_option('mwf_funds_error_logs', array());
        echo '<div class="notice notice-success"><p>Logs cleared successfully!</p></div>';
    }
    $info_logs = get_option('mwf_funds_logs', array());
    $error_logs = get_option('mwf_funds_error_logs', array());
    ?>
    <div class="wrap">
        <h1>MWF Logs</h1>
        <form method="post" action="">
            <?php wp_nonce_field('mwf_clear_logs'); ?>
            <p>
                <input type="submit" name="mwf_clear_logs" class="button" value="Clear All Logs" onclick="return confirm('Are you sure you want to clear all logs?');" />
            </p>
        </form>
        <h2>Error Logs</h2>
        <?php if (empty($error_logs)): ?>
            <p>No error logs found.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Message</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($error_logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['time']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
                            <td><pre><?php echo esc_html(print_r($log['data'], true)); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <h2>Transaction Logs</h2>
        <?php if (empty($info_logs)): ?>
            <p>No transaction logs found.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Message</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($info_logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['time']); ?></td>
                            <td><?php echo esc_html($log['message']); ?></td>
                            <td><pre><?php echo esc_html(print_r($log['data'], true)); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Logging functions (same as before)
 */
function mwf_log_error($message, $data = array()) {
    $log_enabled = get_option('mwf_funds_enable_logging', 'yes');
    if ($log_enabled !== 'yes') return;
    $log_entry = array(
        'time' => current_time('mysql'),
        'message' => $message,
        'data' => $data,
        'type' => 'error'
    );
    $logs = get_option('mwf_funds_error_logs', array());
    array_unshift($logs, $log_entry);
    if (count($logs) > 100) $logs = array_slice($logs, 0, 100);
    update_option('mwf_funds_error_logs', $logs);
}
function mwf_log($message, $data = array()) {
    $log_enabled = get_option('mwf_funds_enable_logging', 'yes');
    if ($log_enabled !== 'yes') return;
    $log_entry = array(
        'time' => current_time('mysql'),
        'message' => $message,
        'data' => $data,
        'type' => 'info'
    );
    $logs = get_option('mwf_funds_logs', array());
    array_unshift($logs, $log_entry);
    if (count($logs) > 100) $logs = array_slice($logs, 0, 100);
    update_option('mwf_funds_logs', $logs);
}

/**
 * Shortcode for user funds
 */
add_shortcode('mwf_user_funds', 'mwf_user_funds_shortcode');
function mwf_user_funds_shortcode($atts) {
    if (!is_user_logged_in()) return '<p>Please log in to view your balance.</p>';
    $user_id = get_current_user_id();
    $balance = mwf_get_user_balance($user_id);
    $output = '<div class="mwf-user-funds">';
    $output .= '<h3>Your Current Balance</h3>';
    $output .= '<p class="mwf-balance">' . wc_price($balance) . '</p>';
    if (isset($atts['show_history']) && $atts['show_history'] == 'yes') {
        $transactions = get_user_meta($user_id, 'woo_funds_transaction');
        if (!empty($transactions)) {
            $output .= '<h4>Transaction History</h4>';
            $output .= '<table class="mwf-transactions">';
            $output .= '<thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Amount</th></tr></thead>';
            $output .= '<tbody>';
            usort($transactions, function($a, $b) {
                return strtotime($b['date']) - strtotime($a['date']);
            });
            foreach ($transactions as $transaction) {
                $output .= '<tr>';
                $output .= '<td>' . date('Y-m-d H:i', strtotime($transaction['date'])) . '</td>';
                $output .= '<td>' . ucfirst($transaction['type']) . '</td>';
                $output .= '<td>' . esc_html($transaction['description']) . '</td>';
                if ($transaction['type'] == 'credit') {
                    $output .= '<td class="mwf-credit">+' . wc_price($transaction['amount']) . '</td>';
                } else {
                    $output .= '<td class="mwf-debit">-' . wc_price($transaction['amount']) . '</td>';
                }
                $output .= '</tr>';
            }
            $output .= '</tbody></table>';
        } else {
            $output .= '<p>No transactions found.</p>';
        }
    }
    $output .= '</div>';
    $output .= '<style>
        .mwf-user-funds { margin: 20px 0; }
        .mwf-balance { font-size: 1.5em; font-weight: bold; }
        .mwf-transactions { width: 100%; border-collapse: collapse; }
        .mwf-transactions th, .mwf-transactions td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        .mwf-credit { color: green; }
        .mwf-debit { color: red; }
    </style>';
    return $output;
}

/**
 * WooCommerce My Account integration
 */
add_filter('woocommerce_account_menu_items', 'mwf_add_funds_menu_item');
function mwf_add_funds_menu_item($menu_items) {
    $menu_items['funds'] = 'My Funds';
    return $menu_items;
}
add_action('init', 'mwf_add_funds_endpoint');
function mwf_add_funds_endpoint() {
    add_rewrite_endpoint('funds', EP_ROOT | EP_PAGES);
}
add_action('woocommerce_account_funds_endpoint', 'mwf_funds_endpoint_content');
function mwf_funds_endpoint_content() {
    echo do_shortcode('[mwf_user_funds show_history="yes"]');
}

/**
 * Clean up on uninstall
 */
register_uninstall_hook(__FILE__, 'mwf_uninstall');
function mwf_uninstall() {
    if (get_option('mwf_funds_delete_data_on_uninstall') !== 'yes') return;
    delete_option('mwf_funds_api_key');
    delete_option('mwf_funds_enable_logging');
    delete_option('mwf_funds_logs');
    delete_option('mwf_funds_error_logs');
    delete_option('mwf_funds_delete_data_on_uninstall');
    global $wpdb;
    $wpdb->delete($wpdb->usermeta, array('meta_key' => 'account_funds'));
    $wpdb->delete($wpdb->usermeta, array('meta_key' => 'woo_funds_transaction'));
}