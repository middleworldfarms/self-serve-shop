<?php
/**
 * Helper function to get WooCommerce API credentials
 */
function get_woo_credentials() {
    $settings = get_settings();
    
    return [
        'url' => $settings['woo_shop_url'] ?? '',
        'consumer_key' => $settings['woo_consumer_key'] ?? '',
        'consumer_secret' => $settings['woo_consumer_secret'] ?? ''
    ];
}