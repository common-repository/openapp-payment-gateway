<?php
/**
 * Plugin Name: OpenApp Gateway for woocommerce
 * Plugin URI: https://open-app.com/
 * Description: This plugin adds OpenApp as a payment gateway in WooCommerce.
 * Author: OpenApp
 * Version: 1.41
 * WC requires at least: 8.3
 * WC tested up to: 9.1
 * Tested up to: 6.6
 * License: GPLv2 or later
 * Text Domain: openapp-gateway-for-woocommerce
 *
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
define('OPENAPPGW_WOOCOMMERCE_GATEWAY', '0.0.32');
define('OPENAPPGW_DB_VERSION', '1.03');

define('OPENAPPGW_MAIN_FILE', __FILE__);
define('OPENAPPGW_PLUGIN_DIR_PATH', plugin_dir_path(OPENAPPGW_MAIN_FILE));
define('OPENAPPGW_PLUGIN_DIR_URL', plugin_dir_url(OPENAPPGW_MAIN_FILE));


require_once(plugin_dir_path(__FILE__) . 'inc/helpers.php');


add_action( 'plugins_loaded', 'openappgw_init_gateway_class');
function openappgw_init_gateway_class() {

    if ( class_exists( 'WooCommerce' ) ) {
        if (!class_exists('OPENAPPGW_OpenApp_Gateway')) {
            require_once(plugin_dir_path(__FILE__) . 'inc/OPENAPPGW_OpenApp_Gateway.php');
            $openAppGateway = new OPENAPPGW_OpenApp_Gateway();

            $openAppGateway->init();
        }
    }
}
