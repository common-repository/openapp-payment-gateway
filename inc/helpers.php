<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Helpers
 */


function openappgw_activate() {
    if ( !class_exists( 'WooCommerce' ) || version_compare( get_option('woocommerce_version'), '8.0', '<' ) ) {
        deactivate_plugins(plugin_basename(OPENAPPGW_MAIN_FILE));
        wp_die(__('This plugin requires WooCommerce 8.0 or higher', 'openapp-gateway-for-woocommerce'), 'Plugin dependency check', array('back_link' => true));
    }

    // create custom db table
    openappgw_create_db_table();

}
register_activation_hook(OPENAPPGW_MAIN_FILE, 'openappgw_activate');



function openappgw_create_db_table()
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table_name = $wpdb->prefix . 'oa_woocommerce_persistent_cart';
    $installed_ver = get_option( "openappgw_db_version" );


    if ($installed_ver != OPENAPPGW_DB_VERSION) {
        $sql = "CREATE TABLE $table_name (
            cart_id CHAR(32) NOT NULL,
            cart_contents LONGTEXT NOT NULL,            
            cart_session_id CHAR(32) NOT NULL,
            cart_expiry BIGINT UNSIGNED NOT NULL,
            last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            order_count INT DEFAULT 0,
            order_key tinytext NULL,            
            oaOrderId CHAR(64) DEFAULT NULL,
            oa_auth_token CHAR(32) DEFAULT NULL,
            oa_last_login TIMESTAMP NULL,
            PRIMARY KEY  (cart_id),
            UNIQUE KEY idx_cart_session_id (cart_session_id),
            INDEX session_id_index (cart_session_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        update_option( "openappgw_db_version", OPENAPPGW_DB_VERSION );
    }
}


/**
 * Do not wait for plugin re-activation.
 * Just check if update is needed and process
 */

function openappgw_update_check() {
    $installed_ver = get_option('openappgw_db_version');

    if ($installed_ver != OPENAPPGW_DB_VERSION) {
        openappgw_update_db($installed_ver);
        // update_option('openappgw_db_version', OPENAPPGW_DB_VERSION);
    }
}
add_action('admin_init', 'openappgw_update_check');

function openappgw_update_db($version) {

    // triggering create_table - will update new columns (new indexes)
    if ($version < '1.03') {
        openappgw_create_db_table();
    }
}

/**
 * Configure gateway
 */


add_filter( 'woocommerce_payment_gateways', 'openappgw_add_openapp_gateway' );
function openappgw_add_openapp_gateway( $gateways ) {
    $gateways[] = 'OPENAPPGW_OpenApp_Gateway';
    return $gateways;
}

/**
 * do not display it on checkout as 'Payment option'
 */
add_filter( 'woocommerce_available_payment_gateways', 'openappgw_filter_gateways', 1);

function openappgw_filter_gateways( $gateways ){
    unset($gateways['openapp']);
    return $gateways;
}


/**
 * DB cleanups
 */

add_action('init', 'openappgw_cleanup_action');

function openappgw_cleanup_action() {
    add_action('openapp_cleanup_old_carts', 'openappgw_perform_cleanup_old_carts');
}

function openappgw_perform_cleanup_old_carts() {
    global $wpdb;

    // Calculate the timestamp for 30 days ago
    $thirty_days_ago = strtotime('-30 days');

    // Delete rows where last_update is older than 30 days
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE UNIX_TIMESTAMP(last_update) < %d",
            $thirty_days_ago
        )
    );
}

function openappgw_schedule_cleanup() {
    if ( class_exists( 'WooCommerce' ) ) {
        if (!as_next_scheduled_action('openapp_cleanup_old_carts')) {
            as_schedule_recurring_action(strtotime('tomorrow midnight'), DAY_IN_SECONDS, 'openapp_cleanup_old_carts');
        }
    }
}

add_action('admin_init', 'openappgw_schedule_cleanup');


/**
 * Deactivate
 */

register_deactivation_hook(OPENAPPGW_MAIN_FILE, 'openappgw_cleanup_on_deactivation');

function openappgw_cleanup_on_deactivation() {
    if ( class_exists( 'WooCommerce' ) ) {
        // Clear scheduled action for cart cleanup
        $hook = 'openapp_cleanup_old_carts';
        as_unschedule_all_actions($hook);
    }
}
