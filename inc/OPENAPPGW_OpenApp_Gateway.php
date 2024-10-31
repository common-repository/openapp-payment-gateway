<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OPENAPPGW_CustomProduct {
    private $name;
    private $shippingClass;
    private $weight;
    private $needsShipping;

    public function __construct($productArray) {
        $this->name = isset($productArray['name']) ? $productArray['name'] : '';
        $this->shippingClass = isset($productArray['shippingClass']) ? $productArray['shippingClass'] : '';
        $this->weight = isset($productArray['weight']) ? $productArray['weight'] : 0;
        $this->needsShipping = isset($productArray['needsShipping']) ? $productArray['needsShipping'] : false;
    }

    public function get_name() {
        return $this->name;
    }
    public function get_weight() {
        return $this->weight;
    }
    public function needs_shipping(){
        return $this->needsShipping;
    }
    public function get_shipping_class(){
        return $this->shippingClass;
    }
}

class OPENAPPGW_OpenApp_Gateway extends WC_Payment_Gateway {
    protected $api_key;
    protected $secret;
    protected $merchant_id;
    protected $profile_id;
    protected $open_app_url;

    protected $payment_method_title;

    private $basket_change_triggered = false;

    private $cart_id_to_process = null;

    private $supported_country = 'PL';

    private $shipping_methods;

    public function __construct() {
        $this->id = 'openapp';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('OpenApp Gateway', 'openapp-gateway-for-woocommerce');
        $this->method_description = __('Convenient online payments through a mobile app', 'openapp-gateway-for-woocommerce');
        $this->payment_method_title = 'OpenApp';
        // other fields
        $this->api_key = $this->get_option('api_key');
        $this->secret = $this->get_option('secret');
        $this->merchant_id = $this->get_option('merchant_id');
        $this->profile_id = $this->get_option('profile_id');
        $this->open_app_url = $this->get_option('open_app_url');

        // Gateways can support subscriptions, refunds, saved payment methods.
        $this->supports = array(
            'products'
        );

        $this->shipping_methods = array(
            ''                      => __('Disabled','openapp-gateway-for-woocommerce'),
            'INPOST_APM'            => __('InPost Paczkomat','openapp-gateway-for-woocommerce'),
            'ORLEN_APM'             => __('Delivery with Orlen Paczka','openapp-gateway-for-woocommerce'),
            'POCZTA_POLSKA_APM'     => __('Delivery with Poczta Polska pickup (machines, pickup points)','openapp-gateway-for-woocommerce'),
            'DHL_PICKUP'            => __('Pickup from DHL pickup point','openapp-gateway-for-woocommerce'),
            'DPD_PICKUP'            => __('Pickup from DPD pickup point','openapp-gateway-for-woocommerce'),
            'INSTORE_PICKUP'        => __('Self-pickup by customer in your store','openapp-gateway-for-woocommerce'),
            'INPOST_COURIER'        => __('Delivery with InPost courier','openapp-gateway-for-woocommerce'),
            'DHL_COURIER'           => __('Delivery using DHL courier','openapp-gateway-for-woocommerce'),
            'DPD_COURIER'           => __('Delivery with DPD courier','openapp-gateway-for-woocommerce'),
            'UPS_COURIER'           => __('Delivery with UPS courier','openapp-gateway-for-woocommerce'),
            'FEDEX_COURIER'         => __('Delivery with FEDEX courier','openapp-gateway-for-woocommerce'),
            'GLS_COURIER'           => __('Delivery with GLS courier','openapp-gateway-for-woocommerce'),
            'POCZTEX_COURIER'       => __('Delivery with POCZTEX courier','openapp-gateway-for-woocommerce'),
            'GEIS_COURIER'          => __('Delivery with GEIS courier','openapp-gateway-for-woocommerce'),
            'ELECTRONIC'            => __('Electronic delivery, eg. license, cinema tickets','openapp-gateway-for-woocommerce')
        );



        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();
        $this->title = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        add_filter('woocommerce_shipping_instance_form_fields_flat_rate', array($this,'add_special_key_field_to_methods'));
        add_filter('woocommerce_shipping_instance_form_fields_free_shipping', array($this,'add_special_key_field_to_methods'));
        add_filter('woocommerce_shipping_instance_form_fields_local_pickup', array($this,'add_special_key_field_to_methods'));

        // integration for: https://pl.wordpress.org/plugins/inpost-paczkomaty/
        add_filter( 'woocommerce_shipping_instance_form_fields_inpost_paczkomaty',array($this,'add_special_key_field_to_methods'));

        // integration for: https://pl.wordpress.org/plugins/flexible-shipping/
        add_filter( 'woocommerce_shipping_instance_form_fields_flexible_shipping_single',array($this,'add_special_key_field_to_methods'));

        // @TODO - maybe move to other place (?)
        add_action('oa_update_cart_in_db', array($this, 'store_cart_in_db'), 10, 1);

        add_action('shutdown', array($this, 'on_shutdown'), 21);
    }


    public function init() {

        // Check if the payment gateway is enabled
        if ( ! $this->is_available() ) {
            return;
        }

        // If the 'q' parameter is set (calling static file), return immediately
        $callingStaticFile = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : null;

        if (!empty($callingStaticFile)) {
            return;
        }

        // If the request is a WP cron job, return immediately
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }

        // actions for wp-admin
        $this->initAdminActions();

        if ( is_admin() ) {
            return;
        }

        // @TODO - move to other place (?)
        add_action('wp_enqueue_scripts', array($this,'oa_plugin_enqueue_scripts'));

        $this->registerAPIRoutes();
        $this->registerCartStorage();
        $this->registerOaOrder();

        // Check if the OALogin is enabled
        if ('yes' === $this->get_option('oalogin_enabled', 'no')) {
            $this->registerOaLogin();
        }

    }

    public function initAdminActions(){
        add_action('woocommerce_order_status_changed', array($this, 'oa_status_changed'), 10, 4);
        add_action('current_screen', array($this,'sse_support_script') );
        add_action('wp_ajax_test_sse_support', array($this,'test_sse_support_callback'));
        add_action('wp_ajax_sse_save_test_result', array($this,'handle_sse_save_test_result'));

        add_filter('rest_pre_serve_request', array($this, 'prevent_caching'), 10, 4);
    }

    /**
     * ===========================================================================
     * 1. WooCommerce Payment gateway
     * ===========================================================================
     */
    public function init_form_fields(){
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable','openapp-gateway-for-woocommerce'),
                'label'       => __('Enable OpenApp Gateway','openapp-gateway-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no'
            ),
            'title' => array(
                'title'       => __('Title','openapp-gateway-for-woocommerce'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.','openapp-gateway-for-woocommerce'),
                'default'     => __('OpenApp Payment','openapp-gateway-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description','openapp-gateway-for-woocommerce'),
                'type'        => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.','openapp-gateway-for-woocommerce'),
                'default'     => __('Pay with OpenApp payment gateway.','openapp-gateway-for-woocommerce'),
            ),
            //...other settings
            'api_key' => array(
                'title'       => __('API Key','openapp-gateway-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Enter your OpenApp API key here.','openapp-gateway-for-woocommerce'),
                'default'     => '',
            ),
            'secret' => array(
                'title'       => __('Secret','openapp-gateway-for-woocommerce'),
                'type'        => 'password',
                'description' => __('Enter your OpenApp secret here.','openapp-gateway-for-woocommerce'),
                'default'     => '',
            ),
            'merchant_id' => array(
                'title'       => __('Merchant ID','openapp-gateway-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Enter your OpenApp merchant ID here.','openapp-gateway-for-woocommerce'),
                'default'     => '',
            ),
            'profile_id' => array(
                'title'       => __('Profile ID','openapp-gateway-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Enter your OpenApp profile ID here.','openapp-gateway-for-woocommerce'),
                'default'     => '',
            ),
            'open_app_url' => array(
                'title'       => __('API Base Url','openapp-gateway-for-woocommerce'),
                'type'        => 'text',
                'description' => __('Enter OpenApp API base url here.','openapp-gateway-for-woocommerce'),
                'default'     => 'https://api.uat.open-pay.com/merchant',
            ),
            'interval_time' => array(
                'title'       => __('Interval Time','openapp-gateway-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Select the interval time for order redirection checking (AJAX polling). A smaller interval provides a faster redirection experience but may increase server load.','openapp-gateway-for-woocommerce'),
                'default'     => '8500',
                'options'     => array(
                    '2000'  => __('2 seconds','openapp-gateway-for-woocommerce'),
                    '5000'  => __('5 seconds','openapp-gateway-for-woocommerce'),
                    '8500'  => __('8.5 seconds','openapp-gateway-for-woocommerce')
                ),
            ),
            'sse_enabled' => array(
                'title'       => __('Server-Sent Events (SSE)','openapp-gateway-for-woocommerce'),
                'label'       => __('Enable SSE for Order Redirection','openapp-gateway-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => sprintf(
                    __('When enabled, the plugin utilizes Server-Sent Events (SSE) to manage order check and thank you page redirection. This method is more efficient than traditional AJAX polling. Before enabling SSE, you can check your server\'s compatibility by clicking on the link: %s', 'openapp-gateway-for-woocommerce'),
                    '<a href="#" id="test-sse-button">' . __('Test SSE Support', 'openapp-gateway-for-woocommerce') . '</a><span id="ct-sse-test-result" style="margin-left:5px;"></span>'
                ),
                'default'     => 'no',
            ),
            'order_status' => array(
                'title'       => __('Order Status','openapp-gateway-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Select the default order status for new orders, example: Processing','openapp-gateway-for-woocommerce'),
                'default'     => 'wc-processing',
                'options'     => wc_get_order_statuses()
            ),
            'basket_sync' => array(
                'title'       => __('Cart synchronization','openapp-gateway-for-woocommerce'),
                'label'       => __('Enable cart synchronization','openapp-gateway-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => __('Activating this option will synchronize every change made to the WooCommerce shopping cart with the OpenApp mobile app in real-time.','openapp-gateway-for-woocommerce'),
                'default'     => 'no',
            ),
            'oalogin_enabled' => array(
                'title'       => __('Enable/Disable OALogin','openapp-gateway-for-woocommerce'),
                'label'       => __('Enable OALogin','openapp-gateway-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => __('If checked, the OALogin functionality will be enabled. Uncheck this option to disable OALogin.','openapp-gateway-for-woocommerce'),
                'default'     => 'yes',
            ),
            'validation_enabled' => array(
                'title'       => __('Enable/Disable Validation','openapp-gateway-for-woocommerce'),
                'label'       => __('Enable request validation','openapp-gateway-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => __('If checked, the plugin will validate OpenApp incoming requests. Disable this option only for testing purposes.','openapp-gateway-for-woocommerce'),
                'default'     => 'yes',
            ),
            'debug' => array(
                'title'       => __('Debug','openapp-gateway-for-woocommerce'),
                'label'       => __('Enable logging','openapp-gateway-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => __('If checked, the plugin will log debug information to the PHP error log.','openapp-gateway-for-woocommerce'),
                'default'     => 'no',
            ),
        );
    }

    public function payment_fields() {}

    public function process_payment( $order_id ) {
        global $woocommerce;

        $order = wc_get_order( $order_id );
        $woocommerce->cart->empty_cart();
        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order )
        );
    }
    public function is_available() {
        $is_available = ('yes' === $this->get_option('enabled', 'no'));
        return $is_available;
    }

    public static function is_sse_enabled() {
        // Retrieve the SSE support status from the transient
        $sseSupportStatus = get_transient('openappgw_sse_supported');

        // Check if the server supports SSE
        $serverSupportsSSE = ($sseSupportStatus !== false && $sseSupportStatus === "1");

        // Retrieve the WooCommerce option and check if SSE is enabled
        $options = get_option('woocommerce_openapp_settings');
        $sseEnabled = isset($options['sse_enabled']) && $options['sse_enabled'] === 'yes';

        // Return true only if both server supports SSE and the SSE option is enabled
        return $serverSupportsSSE && $sseEnabled;
    }

    public function sse_support_script() {
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : '';
        $section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : '';

        if (!empty($page) && !empty($tab) && !empty($section) &&
            $page === 'wc-settings' &&
            $tab === 'checkout' &&
            $section === 'openapp') {
            add_action( 'admin_enqueue_scripts', array($this,'enqueue_sse_test_script') );
        }
    }

    public function enqueue_sse_test_script() {
        wp_enqueue_script( 'openappgw-admin-sse-test', OPENAPPGW_PLUGIN_DIR_URL . 'assets/js/sse-test.js', array(), OPENAPPGW_WOOCOMMERCE_GATEWAY, true );

        wp_localize_script('openappgw-admin-sse-test', 'sseTestParams', array(
            'ajaxUrlTest' => admin_url('admin-ajax.php?action=test_sse_support&security=' . wp_create_nonce('openappgw_sse_support_nonce')),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('openappgw_sse_support_nonce')
        ));

    }

    public function test_sse_support_callback() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this feature.'));
        }

        check_ajax_referer('openappgw_sse_support_nonce', 'security');

        // Set the headers for SSE
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // Run a loop to send messages periodically
        for ($i = 0; $i < 3; $i++) {
            echo "event: test\n";
            echo "data: " . wp_json_encode(array('message' => 'SSE test message')) . "\n\n";
            // Flush the output buffer to the client
            if (ob_get_level() > 0) ob_flush();
            flush();
            // Sleep for a second to simulate a delay between messages
            sleep(1);
        }

        // Close the connection after the test
        echo "event: close\n";
        echo "data: " . wp_json_encode(array('message' => 'Test complete')) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();

        // Terminate the script without displaying the shutdown HTML
        exit(0);
    }

    public function handle_sse_save_test_result() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this feature.'));
        }

        check_ajax_referer('openappgw_sse_support_nonce', 'security');

        // Directly compare with the string 'true' or 'false'
        // Compare with the string 'true'
        $sseSupported = (isset($_POST['sseSupported']) && $_POST['sseSupported'] === 'true');
        // Cast the boolean to an integer (true to 1, false to 0)
        $sseSupportedInt = (int) $sseSupported;

        set_transient('openappgw_sse_supported', $sseSupportedInt, 0);

        wp_send_json_success(__('SSE support status updated.','openapp-gateway-for-woocommerce'));
    }

    public function prevent_caching($served, $result, $request, $server) {
        $route = $request->get_route();

        if (strpos($route, '/openapp/') !== false) {
            header("Cache-Control: no-cache, no-store, must-revalidate");
            header("Pragma: no-cache");
            header("Expires: 0");
        }

        return $served;
    }

    /**
     * Shipping methods
     */

    private function convertToCents($value) {
        // First, replace comma with a dot if comma exists
        if (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }

        // Now convert to float, multiply by 100, and round
        return intval(round(floatval($value) * 100));
    }

    private function rebuild_cart_contents_with_data($cart_data) {
        // Extract simplified cart contents and cart contents data from $cart_data
        $simplified_cart_contents = $cart_data['cart_contents'];
        $cart_contents_data = $cart_data['cart_contents_data'];

        $rebuilt_cart_contents = [];

        foreach ($simplified_cart_contents as $cart_item_key => $simplified_cart_item) {
            // If there is corresponding 'data' for this cart item, add it back
            if (isset($cart_contents_data[$cart_item_key])) {
                $customProduct = new OPENAPPGW_CustomProduct($cart_contents_data[$cart_item_key]);
                $simplified_cart_item['data'] = $customProduct;
            }

            // Add the rebuilt cart item to the array
            $rebuilt_cart_contents[$cart_item_key] = $simplified_cart_item;
        }

        return $rebuilt_cart_contents;
    }

    public function calculate_shipping_cost_for_cart($cart_contents_record, $method, $basket_value) {

        try {
            $woocommerce = WC();

            if ( ! isset($woocommerce->session)) {
                return null;
            }

            if ( null === $woocommerce->cart ) {
                $woocommerce->cart = new WC_Cart();
            }

            $woocommerce->cart->empty_cart();

            $cart_data = $this->rebuild_cart_contents_with_data($cart_contents_record);

            foreach ($cart_data as $item) {
                $_product = $this->get_product_object($item);
                if(is_null($_product)){
                    continue;
                }

                $product_id = $_product->get_id();
                $quantity = $item['quantity'];
                $woocommerce->cart->add_to_cart($product_id, $quantity);
            }

            $package = array(
                'destination' => array(
                    'country' => $this->supported_country,
                ),
                'contents' => $cart_data,
                'applied_coupon' => [],
                'contents_cost' => $basket_value
            );

            $method->calculate_shipping($package);

            $rates = $method->rates;
            $total_shipping_cost = 0;

            foreach ($rates as $rate_id => $rate) {
                $rate_cost = $rate->get_cost();
                $total_shipping_cost += $rate_cost;
            }

            return $this->convertToCents($total_shipping_cost);
        } catch (Exception $e) {
            // Handle exception
            $this->log_debug('Error occurred: ' . $e->getMessage());
            // You can return a specific value or even re-throw the exception
            return null; // Or any other appropriate response
        }
    }

    public function get_available_shipping_methods($country_code, $basket_value, $cart_contents_record = array()) {
        $cheapest_methods = array();

        // Check if WooCommerce is activated
        if (class_exists('WC_Shipping_Zones')) {
            // Get all the shipping zones
            $zones = WC_Shipping_Zones::get_zones();

            foreach ($zones as $zone) {

                $matching_locations = array_filter($zone['zone_locations'], function($location) use ($country_code) {
                    return $location->code == $country_code;
                });

                if (!empty($matching_locations)) {

                    $shipping_zone = new WC_Shipping_Zone($zone['id']);
                    $methods = $shipping_zone->get_shipping_methods(true);

                    foreach ($methods as $method) {
                        $oa_shipping_method_key = $method->get_option('oa_shipping_mapping');

                        if(!$oa_shipping_method_key){
                            continue;
                        }

                        $cost = $this->calculate_shipping_cost_for_cart($cart_contents_record, $method, $basket_value);


                        if(is_null($cost)){
                            continue;
                        }

                        $min_amount = $this->convertToCents($method->get_option('min_amount'));

                        if (!$min_amount || $basket_value >= $min_amount) {
                            if (!isset($cheapest_methods[$oa_shipping_method_key]) || $cost < $cheapest_methods[$oa_shipping_method_key]['cost']) {
                                $cheapest_methods[$oa_shipping_method_key] = array(
                                    'key' => $oa_shipping_method_key,
                                    'cost' => (int) $cost
                                );
                            }
                        }
                    }
                }
            }
        }

        $enabled_methods = array_values($cheapest_methods);
        return $enabled_methods;
    }

    public function add_special_key_field_to_methods($fields) {

        $fields['oa_shipping_mapping'] = array(
            'title'         => __('OpenApp mapping', 'openapp-gateway-for-woocommerce'),
            'type'          => 'select',
            'description'   => __('Select shipping method used in OpenApp mobile app', 'openapp-gateway-for-woocommerce'),
            'options'       => $this->shipping_methods,
            'default'       => '',
        );

        return $fields;
    }


    /**
     * ===========================================================================
     * 2. Initialize functionality
     * ===========================================================================
     */
    private function registerAPIRoutes(){
        add_filter( 'woocommerce_is_rest_api_request',  array($this,'exclude_specific_endpoint_from_rest') );

        add_action('rest_api_init', array($this, 'register_openapp_routes_external'));
        add_action('rest_api_init', array($this, 'register_openapp_routes_internal'));
    }
    private function registerCartStorage(){
        add_action('woocommerce_cart_loaded_from_session', array($this,'store_previous_cart_hash_in_session'), 99, 1);
        add_action('woocommerce_cart_updated', array($this, 'store_cart_in_db'));
    }

    private function registerOaOrder(){
        add_action('woocommerce_after_cart_table', array($this, 'openapp_qr_order_as_action'), 1);
        add_action('woocommerce_review_order_before_payment', array($this, 'openapp_qr_order_as_action'), 1);
        add_action('wp', array($this,'register_oa_order_shortcode'));
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'oa_reset_order_key'), 2);
    }

    private function registerOaLogin(){
        add_action('wp_loaded', array($this, 'set_guest_session'));
        add_action('init', array($this,'oa_custom_login'));

        add_action('woocommerce_login_form_end', array($this,'openapp_qr_login_as_action'));
        add_action('wp', array($this,'register_oa_login_shortcode'));
    }

    /**
     * ===========================================================================
     * 3. registerAPIRoutes
     * ===========================================================================
     */

    public function exclude_specific_endpoint_from_rest( $is_rest_api_request ) {
        if ( empty( $_SERVER['REQUEST_URI'] ) ) {
            return $is_rest_api_request;
        }

        // Sanitize the REQUEST_URI
        $uri = sanitize_text_field( $_SERVER['REQUEST_URI'] );

        $uri_path = wp_parse_url($uri, PHP_URL_PATH);

        // Check if the path matches your specific endpoint
        if ( strpos($uri_path, '/openapp/v1/basket') !== false ) {
            return false;
        }

        return $is_rest_api_request;
    }

    public function register_openapp_routes_external() {

        register_rest_route(
            'openapp/v1',
            '/basket',
            array(
                'methods' => 'GET',
                'callback' => array($this,'retrieve_basket'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'basketId' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return !empty($param);
                        }
                    ),
                ),
            )
        );

        register_rest_route('openapp/v1', '/order', array(
            'methods' => 'POST',
            'callback' => array($this,'create_new_wc_order'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('openapp/v1', '/basket_recalculate', array(
            'methods' => 'POST',
            'callback' => array($this,'basket_recalculate'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route( 'openapp/v1', '/identity', array(
            'methods' => 'POST',
            'callback' => array($this,'handle_identity'),
            'permission_callback' => '__return_true',
        ) );
    }

    public function register_openapp_routes_internal(){
        register_rest_route('openapp/v1', '/qr_code', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_qr_code_data'),
            'args' => array(
                'cart_id' => array(
                    'required' => true
                )
            ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('openapp/v1', '/oa_redirection', array(
            'methods' => 'GET',
            'callback' => array($this, 'oa_check_order_redirection'),
            'args' => array(
                'cart_id' => array(
                    'required' => true
                )
            ),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('openapp/v1', '/oa_login', array(
            'methods' => 'GET',
            'callback' => array($this, 'oa_check_login'),
            'args' => array(
                'cart_id' => array(
                    'required' => true
                )
            ),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * ===========================================================================
     * 4. registerCartStorage
     * ===========================================================================
     */

    private function generate_cart_hash(){
        $session_id = $this->get_session_id();
        $cart_contents = null;

        if ( function_exists('WC') && isset(WC()->cart) ) {
            $cart_contents = WC()->cart->get_cart_contents();
        }

        // Include the session_id when generating the hash
        $hash = md5(wp_json_encode($cart_contents) . $session_id);
        return $hash;
    }

    public function store_previous_cart_hash_in_session($cart){
        if ($this->is_request_empty()) {
            return;
        }

        if($this->is_heartbeat()){
            return;
        }

        $new_hash = $this->generate_cart_hash();
        $old_hash = WC()->session->get('previous_cart_hash');

        if ($old_hash !== $new_hash) {
            // Update session with new hash
            WC()->session->set('previous_cart_hash', $new_hash);
        }
    }


    private function is_request_empty() {
        return empty($_REQUEST);
    }

    private function generate_oa_basket_id($length = 10) {
        $bytes = random_bytes(ceil($length / 2));
        return substr(bin2hex($bytes), 0, $length);
    }

    private function get_unique_oa_basket_id(){
        global $wpdb;
        $max_attempts = 3;
        $attempts = 0;

        do {
            $cart_id = $this->generate_oa_basket_id();
            $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE cart_id = %s", $cart_id));
            $attempts++;

            if ($attempts >= $max_attempts && $existing_row) {
                // Log an error if unable to generate a unique cart_id
                error_log('OA: Unable to generate unique cart_id after ' . $max_attempts . ' attempts.');
                return false;
            }
        } while ($existing_row);

        return $cart_id;
    }

    // asynchronous function (in theory: page can be refreshed faster then function completion)
    public function store_cart_in_db($force_rebuild = false)
    {
        if ($this->is_request_empty() && !$force_rebuild) {
            return;
        }

        $is_internal_api = isset($_SERVER['HTTP_X_WP_INTERNAL']) && sanitize_text_field($_SERVER['HTTP_X_WP_INTERNAL']) == 'true';

        if($is_internal_api){
            return;
        }

        $start_time = microtime(true);

        $new_hash = $this->generate_cart_hash();
        $old_hash = WC()->session->get('previous_cart_hash');

        if(!$force_rebuild && ($old_hash === $new_hash)){
           return;
        }



        global $wpdb;

        $session_id = $this->get_session_id();
        $hashed_session_id = hash('md5', $session_id);
        $applied_coupons = WC()->cart->get_applied_coupons();
        $coupon_data = array();

        foreach ($applied_coupons as $coupon_code) {
            $discount_amount = WC()->cart->get_coupon_discount_amount($coupon_code, WC()->cart->display_cart_ex_tax);
            $coupon_data[] = array(
                'code' => $coupon_code,
                'discount_amount' => $discount_amount,
            );
        }

        if(is_user_logged_in()) {
            $userId = strval(get_current_user_id());
        } else {
            $userId = md5(uniqid(microtime() . wp_rand(), true));
        }

        $my_cart = WC()->cart->get_cart();
        $cart_content_data = $this->get_cart_contents_data($my_cart);
        $cart_content = $this->get_simplified_cart_contents($my_cart);

        $cart_data = array(
            'cart_contents' => $cart_content,
            'coupon_data' => $coupon_data,
            'user_id' => $userId,
            'cart_contents_data' => $cart_content_data
        );

        $cart_data_serialized = maybe_serialize($cart_data);

        $existing_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE cart_session_id = %s", $hashed_session_id));

        if ($existing_row) {
            $wpdb->update(
                $wpdb->prefix . "oa_woocommerce_persistent_cart",
                array('cart_contents' => $cart_data_serialized, 'cart_expiry' => time() + 60 * 60 * 24 * 30),  // expires in 30 days
                array('cart_session_id' => $hashed_session_id),
                array('%s', '%s', '%d'),
                array('%s')
            );

            if (isset($existing_row->cart_id)) {
                $this->cart_id_to_process = $existing_row->cart_id;
                $this->basket_change_triggered = true;
            }
        } else {
            try {
                $cart_id = $this->get_unique_oa_basket_id();
                // set cart_id on new record creation
                $wpdb->insert(
                    $wpdb->prefix . "oa_woocommerce_persistent_cart",
                    array('cart_id' => $cart_id, 'cart_contents' => $cart_data_serialized, 'cart_session_id' => $hashed_session_id, 'cart_expiry' => time() + 60 * 60 * 24 * 30),  // expires in 30 days
                    array('%s', '%s', '%s', '%d')
                );
            } catch (Exception $e) {
                return;
            }
        }

        // End timing
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 4);
        $this->log_debug("store_cart_in_db: ".$execution_time);

    }

    public function on_shutdown() {

        if ('no' === $this->get_option('basket_sync', 'no')) {
            return;
        }

        if ($this->basket_change_triggered && $this->cart_id_to_process) {
            $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'CLI or unknown';
            $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'Unknown method';

            // Check for a POST request applying a coupon
            if ($method === 'POST' && strpos($uri, 'wc-ajax=apply_coupon') !== false) {
                return; // Exit early if applying a coupon
            }

            // Continue with POST requests

            // Check for specific GET requests
            if ($method === 'GET') {
                if (!(strpos($uri, 'removed_item') !== false || strpos($uri, 'undo_item') !== false)) {
                    return; // Exit early for GET requests that do NOT involve removing or undoing items
                }
            }

            remove_action('woocommerce_cart_updated', array($this, 'store_cart_in_db'));
            $this->oa_basket_changed($this->cart_id_to_process);
            add_action('woocommerce_cart_updated', array($this, 'store_cart_in_db'));

            $this->basket_change_triggered = false;
            $this->cart_id_to_process = null;
        }
    }

    private function oa_basket_changed($basketId){
        if(!$basketId){
            return;
        }

        $endpoint = '/v1/basket/change';
        $method = 'POST';
        $url = $this->open_app_url . $endpoint;
        $context = 'openapp_basket_changed';

        $start_time = microtime(true);

        // Retrieve the basket data from your database
        $basket_data = $this->get_basket_data($basketId);

        if (empty($basket_data)) {
            return new WP_Error('basket_not_found', 'Basket not found', array('status' => 404));
        }

        $body = wp_json_encode($basket_data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $setupData = $this->setupRequest($endpoint, $method, $body, $context);
        $response = $this->executeRequest($url, $setupData, $context);

        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 4);
        $this->log_debug("oa_basket_changed: ".$execution_time);

    }

    public function oa_status_changed($order_id, $from_status, $to_status, $order){
        $endpoint = '/v1/orders/multiFulfillment';
        $method = 'POST';
        $url = $this->open_app_url . $endpoint ;
        $context = 'openapp_basket_fulfillment';


        // Mapping WooCommerce statuses to the ones expected by OpenApp
        $status_mapping = array(
            'completed' => 'DELIVERED',
            'processing' => 'FULFILLED',
            'shipped' => 'SHIPPED',
            'ready-for-pickup' => 'READY_FOR_PICKUP',
            'cancelled' => 'CANCELLED_MERCHANT'
        );

        // Check if the changed status exists in our mapping
        if (!isset($status_mapping[$to_status])) {
            return; // Exit if we don't need to send data for this status
        }
        // Retrieve oaOrderId from the order's post meta
        $oaOrderId = get_post_meta($order_id, 'oaOrderId', true);

        if (!$oaOrderId) {
            return;
        }

        /**
         * get shipping method
         */
        $order = wc_get_order($order_id);
        if ( ! is_a( $order, 'WC_Order' ) ) {
            return;
        }

        $shipments = [];

        // Get the shipping methods from the order
        $shipping_methods = $order->get_shipping_methods();

        foreach ($shipping_methods as $shipping_method) {
            // Construct each shipment based on the shipping method
            $method_id = $shipping_method->get_method_id();
            $shipment = [
                'shipmentId' => $order_id .'_'. $method_id,
                'status' => $status_mapping[$to_status],
                'notes' => '',
                'operator' => $method_id,
            ];

            $shipments[] = $shipment;
        }

        // Prepare the order data for the API request
        $order_data = [
            'oaOrderId' => $oaOrderId,
            'shopOrderId' => (string)$order_id,
            'shipments' => $shipments
        ];

        $body = $this->encodeJsonResponse($order_data);
        $setupData = $this->setupRequest($endpoint, $method, $body, $context);
        $response = $this->executeRequest($url, $setupData, $context);
    }

    private function setupRequest($endpoint, $method, $body, $context) {
        $timestamp = (int) (microtime(true) * 1000);
        $nonce = $timestamp;
        $path = strtoupper($endpoint);

        $bodyHash = hash('sha256', $body, true);
        $bodyHashBase64 = $this->base64Encode($bodyHash);
        $hmacSignature = $this->generateHmacSignature($method, $path, $timestamp, $nonce, $bodyHashBase64, null, null, $context);
        $authHeader = "hmac v1$" . $this->api_key . "$" . $method . "$" . $path . "$" . $timestamp . "$" . $nonce;

        $this->openappgw_custom_log("Request Authorization Header: " . $authHeader, $context);
        $this->openappgw_custom_log("myBody: ". var_export($body, true), $context);

        return [
            'headers' => array(
                'Content-Type' => 'application/json',
                'authorization' => sanitize_text_field($authHeader),
                'x-app-signature' => sanitize_text_field($hmacSignature)
            ),
            'body' => $body
        ];
    }

    private function executeRequest($url, $setupData, $context) {
        $response = wp_remote_post($url, array(
            'headers' => $setupData['headers'],
            'body' => $setupData['body'],
            'timeout'       => 10,
            'blocking' => false, // true (for debugging)
        ));

        $responseBody = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        // Logging response
        $this->openappgw_custom_log("RESPONSE:", $context);
        $this->openappgw_custom_log(print_r($responseBody, true), $context);

        if (200 === $http_code) {
            // Successful HTTP response handling
        } else {
            // Handle other HTTP response codes
        }

        return $response;
    }



    /**
     * ===========================================================================
     * 5. registerQRCodes
     * ===========================================================================
     */

    public function openapp_qr_order_as_action(){
        echo wp_kses_post($this->openapp_qr_order());
    }

    public function openapp_qr_login_as_action() {
        echo wp_kses_post($this->openapp_qr_login());
    }

    /**
     * ===========================================================================
     * 6. registerShortcodes
     * ===========================================================================
     */

    public function register_oa_order_shortcode() {
        $is_internal_api = isset($_SERVER['HTTP_X_WP_INTERNAL']) && sanitize_text_field($_SERVER['HTTP_X_WP_INTERNAL']) == 'true';

        if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || $is_internal_api) {
            return;
        }

        // enable only on page OR post
        if(is_page() || is_single() || is_archive()){
            $sanitized_uri = filter_var($_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL);
            $this->log_debug('Shortcode triggered by ' . $sanitized_uri);

            add_shortcode('openapp_qr_order', array($this, 'openapp_qr_order'));
        }
    }

    public function register_oa_login_shortcode(){
        $is_internal_api = isset($_SERVER['HTTP_X_WP_INTERNAL']) && sanitize_text_field($_SERVER['HTTP_X_WP_INTERNAL']) == 'true';

        if (is_admin() || (defined('DOING_CRON') && DOING_CRON) || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || $is_internal_api) {
            return;
        }

        // enable only on page OR post
        if(is_page() || is_single() || is_archive()){
            $sanitized_uri = filter_var($_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL);
            $this->log_debug('Shortcode triggered by ' . $sanitized_uri);

            add_shortcode('openapp_qr_login', array($this, 'openapp_qr_login'));
        }
    }

    public function oa_plugin_enqueue_scripts() {
        // Register the script
        wp_register_script('openappgw-js', 'https://static.prd.open-pay.com/dist/openapp.min.0.0.4.js', array('jquery'), '0.0.4', false);
    }


    /**
     * ===========================================================================
     * 7. registerOaOrder
     * ===========================================================================
     */


    public function oa_check_order_redirection(WP_REST_Request $request)
    {

        $cart_id = sanitize_text_field($request->get_param('cart_id'));
        $useSSE = sanitize_text_field($request->get_param('use_sse'));

        $response = $this->createResponse(['redirect' => false]);

        $thank_you_url = $this->get_thank_you_url($cart_id);

        if ($thank_you_url) {
            $response = $this->createResponse(['redirect' => true, 'url' => $thank_you_url]);
        }

        if($useSSE){
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');

            // set_time_limit(0);
            ignore_user_abort(true);

            $lastEventId = 0;

            while (true) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();

                $thank_you_url = $this->get_thank_you_url($cart_id);
                $lastEventId++;

                $this->openappgw_custom_log($lastEventId . ' ' . $thank_you_url, 'sse');

                if ($thank_you_url) {
                    echo "id: " . esc_html($lastEventId) . "\n";
                    echo "event: orderUpdate\n";
                    echo 'data: ' . wp_json_encode(['redirect' => true, 'url' => $thank_you_url]) . "\n\n";
                } else {
                    echo "id: " . esc_html($lastEventId) . "\n";
                    echo "event: orderUpdate\n";
                    echo 'data: ' . wp_json_encode(['redirect' => false]) . "\n\n";
                }

                flush();

                // Break the loop if a URL is found
                if ($thank_you_url) {
                    break;
                }

                sleep(3);
            }

        } else {
            return $response;
        }
    }

    public function get_thank_you_url($cart_id) {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT order_key FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE cart_id = %s", $cart_id)
        );

        if(!empty($result) && $result->order_key !== NULL) {
            // order_key column contains order_number
            $order_id = $result->order_key;
            if ($order_id) {
                $order_key = null;
                $order = wc_get_order($order_id);
                if ($order && !is_wp_error($order)) {
                    $order_key = $order->get_order_key();
                }

                if(!is_null($order_key)){
                    $checkout_url = trailingslashit(wc_get_checkout_url()); // ensures that URL ends with a slash
                    $thank_you_url = add_query_arg(['key' => $order_key, 'order-received' => $order_id], $checkout_url . 'order-received/');

                    // Reset order key
                    // $this->oa_reset_order_key($order_id, false);

                    // Validate the URL
                    if (filter_var($thank_you_url, FILTER_VALIDATE_URL) !== false) {
                        // The URL is valid, proceed with the redirect
                        return $thank_you_url;
                    }
                }
            }
        }

        return false;
    }

    public function oa_reset_order_key($order_id, $assignOrder = true) {
        global $wpdb;

        $this->log_debug("reset order key");

        // Set the order_key back to NULL
        $wpdb->update(
            $wpdb->prefix . "oa_woocommerce_persistent_cart",
            ['order_key' => NULL],
            ['order_key' => $order_id]  // Where order_key matches
        );

        if($assignOrder){
            // assign guest order to customer
            $order = wc_get_order($order_id);
            $user_email = $order->get_billing_email();
            $user = get_user_by('email', $user_email);

            if (!$user) {
                $random_password = wp_generate_password(16, true);
                $user_id = $this->createWpUser($user_email, $random_password);

                if (is_wp_error($user_id)) {

                }
            } else {
                $user_id = $user->ID;
            }
            $order->set_customer_id($user_id);
            $order->save();


            // Trigger status update in API
            $current_status = $order->get_status();
            $this->oa_status_changed($order_id, $current_status, $current_status, $order);
        }

    }

    /**
     * ===========================================================================
     * 8. registerOaLogin
     * ===========================================================================
     */

    public function set_guest_session(){

        $oaSetGuestSession = filter_input(INPUT_GET, 'oa-set-guest-session', FILTER_SANITIZE_NUMBER_INT);

        if($oaSetGuestSession && $oaSetGuestSession === '1'){
            if (!is_user_logged_in()) {
                if ( isset( WC()->session ) ) {
                    if ( !WC()->session->has_session() ) {
                        $this->log_debug("set_session_for_guest");
                        WC()->session->set_customer_session_cookie(true);

                        // user session is active
                        // rebuild data
                        $this->store_cart_in_db(true);
                    }
                }
            }
            $redirect_url = wp_get_referer() ? wp_get_referer() : home_url();
            wp_safe_redirect($redirect_url.'?oa-initialized=1');
            exit;
        }
    }


    public function oa_check_login(WP_REST_Request $request)
    {
        $cart_id = sanitize_text_field($request->get_param('cart_id'));

        $this->log_debug("oa_check_login");

        if (!empty($cart_id)) {
            global $wpdb;
            $cart_data = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE cart_id = %s", $cart_id)
            );


            if (!is_null($cart_data) && isset($cart_data->oa_auth_token) && !is_null($cart_data->oa_auth_token)) {
                $args = array(
                    'meta_key'     => 'oa_auth_token',
                    'meta_value'   => $cart_data->oa_auth_token,
                    'meta_compare' => '=',
                    'number'       => 1,
                );

                $users = get_users($args);

                if (!empty($users) && $user = $users[0]) {
                    $this->log_debug("oa_check_login: ". $user->user_email);

                    return $this->createResponse(['should_login' => true, 'redirect_url' => '/?oa-custom-login=1']);
                }
            }
        }

        return $this->createResponse(['should_login' => false]);
    }

    public function oa_custom_login(){
        $oaCustomLogin = filter_input(INPUT_GET, 'oa-custom-login', FILTER_SANITIZE_NUMBER_INT);

        if($oaCustomLogin && $oaCustomLogin === "1"){

            $this->log_debug("INIT oa-custom-login..");

            // Redirect to my account page
            $myaccount_page_id = get_option( 'woocommerce_myaccount_page_id' );
            $myaccount_url = get_permalink( $myaccount_page_id );

            if ( is_user_logged_in() ) {
                // do nothing (?)
            }

            $sessionId = $this->get_session_id();
            $sessionId = $this->get_cart_id_by_session($sessionId);

            // check if oauth_token exists
            // if yes - use associated email to login current user
            global $wpdb;

            $cart_data = $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE cart_id = %s", $sessionId)
            );


            if (is_null($cart_data)) {
                return new WP_REST_Response( "Session do not exists", 500 );
            }

            if(isset($cart_data->oa_auth_token) && !is_null($cart_data->oa_auth_token)){
                $args = array(
                    'meta_key'     => 'oa_auth_token',
                    'meta_value'   => $cart_data->oa_auth_token,
                    'meta_compare' => '=',
                    'number'       => 1,
                );

                $users = get_users($args);

                if (!empty($users)) {
                    $user = $users[0];

                    if ($user) {
                        wp_clear_auth_cookie();
                        wp_set_current_user($user->ID, $user->user_login);
                        wp_set_auth_cookie($user->ID);
                        do_action('wp_login', $user->user_login, $user);

                        $this->log_debug("oa-custom-login: ". $user->user_email);

                        // clear auth token
                        $wpdb->query(
                            $wpdb->prepare("UPDATE " . $wpdb->prefix . "oa_woocommerce_persistent_cart SET oa_auth_token = NULL WHERE cart_id = %s", $sessionId)
                        );


                        // Update last login time
                        update_user_meta( $user->ID, 'oa_last_login', time() );

                        // Redirect to account page if the login cookie is already set.
                        wp_safe_redirect($myaccount_url);
                        exit;
                    }
                }
            }

            wp_safe_redirect($myaccount_url);
            exit;
        }
    }


    /**
     * ===========================================================================
     * 9. HMAC Authentication
     * ===========================================================================
     */

    private function hmacSignatureIsValid($authorizationHeader, $serverSignature, $responseBodyHashBase64 = null){
        // Split the HMAC header into its parts
        list($version, $api_key, $method, $path, $timestamp, $nonce) = explode('$', $authorizationHeader);

        // Check if the timestamp is within the allowed 60 seconds window
        $currentTimestamp = round(microtime(true) * 1000); // Current time in milliseconds
        $diff = abs($currentTimestamp - $timestamp);

        if ($diff > 60000) {
            return false; // Reject if the timestamp difference is greater than 60 seconds
        }

        $hmacSignature = $this->generateHmacSignature($method, $path, $timestamp, $nonce, $responseBodyHashBase64, $api_key, $this->secret);

        if(hash_equals($serverSignature, $hmacSignature)){
            return true;
        } else {
            return false;
        }
    }

    protected function generateHmacSignature($method, $path, $timestamp, $nonce, $content = null, $api_key = null, $secret = null, $log_to_context = '') {
        if(!$api_key){
            $api_key = $this->api_key;
        }
        if(!$secret){
            $secret = $this->secret;
        }

        $stringToSign = "v1$"."$api_key$" . "$method$" . "$path$" . "$timestamp$" . "$nonce";

        if ($content) {
            $stringToSign .= "$".$content;
        }

        if($log_to_context){
            $this->openappgw_custom_log("STRING_TO_SIGN: " . $stringToSign, $log_to_context);
        }


        $hmacHash = hash_hmac('sha256', $stringToSign, $secret, true);
        $hmacSignature = $this->base64Encode($hmacHash);

        return $hmacSignature;
    }


    private function calculate_server_authorization($headers, $responseBody = null) {
        $authorization = isset($headers["authorization"]) ? $headers["authorization"][0] : null;

        if(!is_null($authorization)) {
            $components = explode('$', $authorization);

            if (count($components) < 6) {
                // The authorization string doesn't contain as many components as we're expecting.
                return null;
            }


            $timestamp = $components[4];
            $nonce = $components[5];

            $calculatedSignature = $this->generateHmacResponseSignature($timestamp, $nonce, $responseBody, $authorization);

            $context = 'openapp_hmac_response';
            $this->openappgw_custom_log("calculatedSignature: ". $calculatedSignature, $context);

            return "hmac v1". "$"."$timestamp"."$".$nonce."$"."$calculatedSignature";
        }

        return null;
    }

    protected function calculate_x_server_auth($headers, $responseBody = null) {
        $authorization = isset($headers["x-server-authorization"]) ? $headers["x-server-authorization"] : null;

        if(!is_null($authorization)) {
            $components = explode('$', $authorization);

            if (count($components) < 4) {
                // The authorization string doesn't contain as many components as we're expecting.
                return null;
            }

            $timestamp = $components[1];
            $nonce = $components[2];

            $calculatedSignature = $this->generateHmacResponseSignature($timestamp, $nonce, $responseBody, $authorization);

            $context = 'openapp_hmac_response';
            $this->openappgw_custom_log("calculatedSignature X-Server-Auth: ". $calculatedSignature, $context);

            $hmacXServerHeader = "hmac v1". "$"."$timestamp"."$".$nonce."$"."$calculatedSignature";

            return $hmacXServerHeader;
        }

        return null;
    }


    protected function generateHmacResponseSignature($timestamp,$nonce,$responseBody = null, $authorization = null, $printDebug = false) {

        $stringToSign =  "v1$"."$timestamp"."$"."$nonce";

        if (!is_null($responseBody)) {
            // FIX (encode body to convert backslash characters)
            $responseBody = wp_json_encode($responseBody);

            $responseBodyHash = hash('sha256', $responseBody, true);

            $responseBodyHashBase64 = $this->base64Encode($responseBodyHash);
            $stringToSign .= "$".$responseBodyHashBase64;
        }


        $context = 'openapp_hmac_response';
        $this->openappgw_custom_log("GENERATE_HMAC_RESPONSE_SIGNATURE", $context);
        $this->openappgw_custom_log("Request Authorization Header: ". $authorization, $context);
        $this->openappgw_custom_log("Timestamp: ".$timestamp, $context);
        $this->openappgw_custom_log("Nonce: ".$nonce, $context);
        $this->openappgw_custom_log("responseBody: ". var_export($responseBody, true), $context);
        if(isset($responseBodyHash)){
            $this->openappgw_custom_log("responseHash: ".$responseBodyHash, $context);
        }
        if(isset($responseBodyHashBase64)){
            $this->openappgw_custom_log("responseBodyHashBase64: ".$responseBodyHashBase64, $context);
        }

        $this->openappgw_custom_log("stringToSign: ".$stringToSign, $context);
        $this->openappgw_custom_log("-----------------------", $context);

        $hmacHash = hash_hmac('sha256', $stringToSign, $this->secret, true);
        $hmacHashBase64 = $this->base64Encode($hmacHash);

        if($printDebug){
            // Output
            var_dump("GENERATING X-Server-Authorization Header");
            var_dump("Secret: ". $this->mask_secret($this->secret));
            var_dump("bodyDigest: " . $responseBodyHashBase64);
            var_dump("Nonce: " . $nonce);
            var_dump("Signature: " . $hmacHashBase64 );
            var_dump("Timestamp: " . $timestamp);
            var_dump("StringToSign: " . $stringToSign);
        }

        return $hmacHashBase64;
    }



    private function isRequestValid($headers, $body = null) {
        if (!isset($headers['authorization'][0]) || !isset($headers['x_app_signature'][0])) {
            return false;
        }

        // Check the value of the validation_enabled option
        if ('no' === $this->get_option('validation_enabled', 'yes')) {
            return true;
        }

        $validHmac = $this->hmacSignatureIsValid($headers['authorization'][0], $headers['x_app_signature'][0], $body);

        $context = 'openapp_hmac_response';
        $this->openappgw_custom_log("isRequestValid Auth: ". var_export($headers['authorization'][0], true), $context);
        $this->openappgw_custom_log("isRequestValid X-App-Signature: ". var_export($headers['x_app_signature'][0], true), $context);
        $this->openappgw_custom_log("isRequestValid Body: ". var_export($body, true), $context);
        $this->openappgw_custom_log("isRequestValid: ". var_export($validHmac, true), $context);
        $this->openappgw_custom_log("---------------------", $context);

        return $validHmac;
    }


    /**
     * ===========================================================================
     * 10. REST API ENDPOINTS
     * ===========================================================================
     */

    public function retrieve_basket( WP_REST_Request $request) {
        // Fetch the basketId from the request
        $basketId = $request->get_param('basketId');

        // Fetch the headers from the request
        $headers = $request->get_headers();

        // logger
        $context = 'openapp_retrieve_basket';
        $this->openappgw_custom_log("Basket ID: ".print_r($basketId, true), $context);
        $this->openappgw_custom_log("Headers: ".print_r($this->encodeJsonResponse($headers), true), $context); // Logs the headers

        // Verify HMAC
        $validHmac = $this->isRequestValid($headers);

        if(!$validHmac){
            return new WP_Error('invalid_auth', 'Unauthorized request', array('status' => 403));
        }

        // Retrieve the basket data from your database
        $response = $this->get_basket_data($basketId);

        if (empty($response)) {
            return new WP_Error('basket_not_found', 'Basket not found', array('status' => 404));
        }


        // Create WP_REST_Response object
        $wpRestResponse = new WP_REST_Response($response, 200);

        $this->openappgw_custom_log("responseBody: ". var_export($response, true), $context);

        // Calculate X-Server-Authorization and set the header if it exists
        $expectedXServerAuth = $this->calculate_server_authorization($headers, $response);

        if($expectedXServerAuth !== null) {
            $this->openappgw_custom_log("X-Server-Authorization: ".$expectedXServerAuth, $context);
            $wpRestResponse->set_headers(['X-Server-Authorization' => $expectedXServerAuth]);
        }

        $this->openappgw_custom_log("------------------------------------", $context);

        return $wpRestResponse;
    }

    public function basket_recalculate(WP_REST_Request $request) {
        // Get the data from the request
        $data = $request->get_json_params();

        // Fetch the headers from the request
        $headers = $request->get_headers();

        // logger
        $context = 'openapp_basket_recalculate';
        $this->openappgw_custom_log(print_r($data, true), $context);
        $this->openappgw_custom_log(print_r($this->get_values_and_types($data), true), $context);
        $this->openappgw_custom_log("Headers: ".print_r($this->encodeJsonResponse($headers), true), $context); // Logs the headers

        // Verify HMAC
        $body = $request->get_body();
        $bodyHash = hash('sha256', $body, true);
        $responseBodyHashBase64 = $this->base64Encode($bodyHash);

        $validHmac = $this->isRequestValid($headers, $responseBodyHashBase64);

        if(!$validHmac){
            return new WP_Error('invalid_auth', 'Unauthorized request', array('status' => 403));
        }

        $currency = get_woocommerce_currency();
        /**
         * @TODO - use new method 'get_available_shipping_methods'
         */
        $deliveryOptions = $this->get_delivery_options();

        // Initialize the output data with some values from the input
        $output = array(
            'id' => $data['id'],
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 day')),
            'price' => array(
                'currency' => $currency,
                'discounts' => array(),
                'basketValue' => 0
            ),
            'deliveryOptions' => $deliveryOptions,
            'products' => array()
        );

        if(isset($data['loggedUser'])){
            $output['loggedUser'] = $data['loggedUser'];
        }


        // fake order
        $order = wc_create_order();

        // For each product in the products list
        foreach ($data['products'] as $product) {
            // Get the product details using the WooCommerce API
            $wc_product = wc_get_product($product['id']);

            if(!$wc_product){
                continue;
            }

            $quantity = $product['quantity'];
            $error = null;

            // Get the stock quantity
            if ($wc_product && $wc_product->managing_stock()) {
                $stock_quantity = $wc_product->get_stock_quantity();
                // If the product is not in stock, add an "OUT_OF_STOCK" error
                if (!$wc_product->is_in_stock()) {
                    $error = "OUT_OF_STOCK";
                    $quantity = 0;
                }
                // If the requested quantity is greater than the stock quantity, set the quantity to the stock quantity and add an error
                else if ($quantity > $stock_quantity) {
                    $quantity = $stock_quantity;
                    $error = "QUANTITY_TOO_BIG";
                }
            }

            // @TODO - verify logic of this calculation (!)
            $output['products'][] = $this->create_product_output_array($wc_product, $quantity, $error);

            // sum basketValue
            $unit_price = round($wc_product->get_price() * 100);
            $line_price = $unit_price * $quantity;
            $output['price']['basketValue'] += $line_price;

            $order->add_product($wc_product, $quantity);
        }

        foreach ($data['price']['discounts'] as $discount) {
            if ($this->validate_discount($discount)) {
                $coupon = new WC_Coupon($discount['code']);

                // Create an instance of the WC_Discounts class.
                $discounts = new WC_Discounts( $order );

                // Check if the coupon is valid for this order.
                $valid = $discounts->is_coupon_valid( $coupon );

                if ( is_wp_error( $valid ) ) {
                    // The coupon is not valid. You can log the error message if you wish
                    // error_log( $valid->get_error_message() );
                } else {
                    $order->apply_coupon($coupon->get_code());
                    $order->calculate_totals();
                    $discount_value = round($order->get_total_discount() * 100);

                    // Add the discount to the output
                    $output['price']['discounts'][] = array(
                        'code' => $discount['code'],
                        'value' => $discount_value
                    );
                    // Subtract the discount value from the basket value
                    $output['price']['basketValue'] -= $discount_value;
                    // Remove the coupon from the order
                    $order->remove_coupon($coupon->get_code());
                }
            }
        }

        $this->openappgw_custom_log("RESPONSE:", $context);
        $this->openappgw_custom_log(print_r($output, true), $context);
        $this->openappgw_custom_log("------------------------------------", $context);

        $wpRestResponse = new WP_REST_Response($output, 200);

        // Calculate X-Server-Authorization and set the header if it exists
        $expectedXServerAuth = $this->calculate_server_authorization($headers, $output);
        // Set X-Server-Authorization header if it exists
        if($expectedXServerAuth !== null) {
            $this->openappgw_custom_log("X-Server-Authorization: ".$expectedXServerAuth, $context);
            $wpRestResponse->set_headers(['X-Server-Authorization' => $expectedXServerAuth]);
        }

        // Return the output as a WP_REST_Response
        return $wpRestResponse;
    }


    public function create_new_wc_order(WP_REST_Request $request) {
        // Parse and validate request body
        $data = $request->get_json_params();

        // Fetch the headers from the request
        $headers = $request->get_headers();

        // logger
        $context = 'openapp_place_order';
        $this->openappgw_custom_log(print_r($data, true), $context);
        $this->openappgw_custom_log(print_r($this->get_values_and_types($data), true), $context);
        $this->openappgw_custom_log("Headers: ".print_r($this->encodeJsonResponse($headers), true), $context); // Logs the headers

        if (empty($data['oaOrderId'])) {
            return new WP_Error('missing_oaOrderId', 'OA Order ID is required', array('status' => 400));
        }

        $body = $request->get_body();
        $bodyHash = hash('sha256', $body, true);
        $responseBodyHashBase64 = $this->base64Encode($bodyHash);

        $validHmac = $this->isRequestValid($headers, $responseBodyHashBase64);

        if(!$validHmac){
            return new WP_Error('invalid_auth', 'Unauthorized request', array('status' => 403));
        }

        $order = wc_create_order();

        $cart_id = $data['basket']['id'];

        foreach ($data['basket']['products'] as $product) {
            $product_to_add = wc_get_product( $product['id'] );

            if (!$product_to_add) {
                return new WP_REST_Response( array(
                    'message' => 'Error: Product with id ' . $product['id'] . ' does not exist',
                ), 400 );
            }
            // Check stock quantity
            /*
            if($product_to_add->get_stock_quantity() < $product['quantity']) {
                return new WP_REST_Response( array(
                    'message' => 'Error: Not enough quantity for product with id ' . $product['id'],
                ), 400 );
            }
            */

            $order->add_product( $product_to_add, $product['quantity'] );
        }

        // Here, we apply the discounts
        if (!empty($data['basket']['price']['discounts'])) {
            foreach ($data['basket']['price']['discounts'] as $discount) {
                // You could implement a function that validates the discount before applying it
                if ($this->validate_discount($discount)) {
                    $coupon = new WC_Coupon($discount['code']);

                    // Create an instance of the WC_Discounts class.
                    $discounts = new WC_Discounts( $order );

                    // Check if the coupon is valid for this order.
                    $valid = $discounts->is_coupon_valid( $coupon );

                    if ( is_wp_error( $valid ) ) {
                        // The coupon is not valid. You can log the error message if you wish
                        // error_log( $valid->get_error_message() );
                    } else {
                        $order->apply_coupon($coupon->get_code());
                    }
                }
            }
        }

        /**
         * Save data start
         */
        // Update order details
        if (!empty($data['billingDetails'])) {
            $order->set_billing_company($data['billingDetails']['companyName']);
            $order->set_billing_first_name($data['billingDetails']['firstName']);
            $order->set_billing_last_name($data['billingDetails']['lastName']);
            $order->set_billing_address_1($data['billingDetails']['street']);

            $billing_address_1 = $data['billingDetails']['street'] . ' ' .$data['billingDetails']['streetNo'];
            if(!empty($data['billingDetails']['apartmentNo'])){
                $billing_address_1 .= ' / ' . $data['billingDetails']['apartmentNo'];
            }
            $order->set_billing_address_1($billing_address_1);
            $order->set_billing_city($data['billingDetails']['city']);
            $order->set_billing_postcode($data['billingDetails']['postalCode']);
            $order->set_billing_country($data['billingDetails']['country']);
            $order->set_billing_phone($data['billingDetails']['phoneNumber']);

            if(!empty($data['billingDetails']['notes'])){
                $order->set_customer_note($data['billingDetails']['notes']);
            }
        }


        // Save email
        $order->set_billing_email($data['deliveryDetails']['email']);

        // Set payment method
        $order->set_payment_method($this->id);
        $order->set_payment_method_title($this->payment_method_title);

        // Update shipping details
        // Concatenate street number and apartment number
        $address_line_1 = $data['deliveryDetails']['street'] . ' ' .$data['deliveryDetails']['streetNo'];
        if(!empty($data['deliveryDetails']['apartmentNo'])){
            $address_line_1 .= ' / ' . $data['deliveryDetails']['apartmentNo'];
        }
        $order->set_shipping_address_1($address_line_1);

        // $order->set_shipping_address_2('');
        $order->set_shipping_company($data['deliveryDetails']['companyName']);
        $order->set_shipping_first_name($data['deliveryDetails']['firstName']);
        $order->set_shipping_last_name($data['deliveryDetails']['lastName']);
        $order->set_shipping_phone($data['deliveryDetails']['phoneNumber']);
        $order->set_shipping_city($data['deliveryDetails']['city']);
        $order->set_shipping_postcode($data['deliveryDetails']['postalCode']);
        $order->set_shipping_country($data['deliveryDetails']['country']);

        // Paczkomaty
        if (isset($data['deliveryDetails']['method']) && $data['deliveryDetails']['method'] === 'INPOST_APM') {
            if(isset($data['deliveryDetails']['id'])) {
                $parcelLockerId = $data['deliveryDetails']['id'];
                $order->set_shipping_company($parcelLockerId);

                // Save the Parcel Locker ID
                update_post_meta($order->get_id(), 'paczkomat_key', $parcelLockerId);

                // Construct and save the full name and address of the parcel locker
                $parcelLockerFull = $data['deliveryDetails']['id'] . ', ' .
                    $data['deliveryDetails']['street'] . ' ' .
                    $data['deliveryDetails']['streetNo'] . ', ' .
                    $data['deliveryDetails']['postalCode'] . ' ' .
                    $data['deliveryDetails']['city'];
                update_post_meta($order->get_id(), 'Wybrany paczkomat', $parcelLockerFull);
            }
        }


        // save Notes + TaxID
        $taxIdNote = "";
        if(!empty($data['billingDetails']['taxId'])){
            $taxId = $data['billingDetails']['taxId'];
            $taxIdNote = "TaxId: ".$taxId . "\n";
            // Save NIP for Baselinker
            update_post_meta($order->get_id(), '_billing_nip', $taxId);
        }

        $deliveryNotes = isset($data['deliveryDetails']['notes']) ? $data['deliveryDetails']['notes'] : '';
        $billingNotes = isset($data['billingDetails']['notes']) ? $data['billingDetails']['notes'] : '';

        $order->add_order_note($taxIdNote . $deliveryNotes . "\n" . $billingNotes);

        // save note2
        $note2 = "OA Order ID: " . $data['oaOrderId'] . "\n";
        $note2 .= "Payment Currency: " . $data['paymentDetails']['currency'] . "\n";
        $note2 .= "Payment Amount: " . $data['paymentDetails']['amount'] . "\n";
        $note2 .= "Delivery Type: " . $data['deliveryDetails']['type'];
        $order->add_order_note($note2);

        // Update orderOAID
        update_post_meta($order->get_id(), 'oaOrderId', $data['oaOrderId']);


        /**
         * Save data end
         */

        // Delivery method
        $deliveryMethodKey = isset($data['deliveryDetails']['method']) ? $data['deliveryDetails']['method'] : null;
        $deliveryMethodName = isset($this->shipping_methods[$deliveryMethodKey]) ? $this->shipping_methods[$deliveryMethodKey] : $deliveryMethodKey;

        // Add the shipping method to the WooCommerce order
        $deliveryCost = isset($data['basket']['price']['deliveryCost']) ? $data['basket']['price']['deliveryCost'] : 0;
        $deliveryCost = $deliveryCost / 100; // converts groszy to zloty (adjust as necessary)

        // Baselinker - shipping method Paczkomaty
        $shippingItem = new WC_Order_Item_Shipping();
        $shippingItem->set_method_title($deliveryMethodName);
        $shippingItem->set_method_id($deliveryMethodKey);
        $shippingItem->set_total( $deliveryCost);

        $calculate_tax_for = array(
            'country' => $this->supported_country,
        );

        $taxesAmount = 0;

        if (wc_tax_enabled()) {
            $tax_rates = WC_Tax::find_shipping_rates($calculate_tax_for);
            $taxes = WC_Tax::calc_tax($deliveryCost, $tax_rates, true);
            $taxesAmount = array_sum($taxes); // Calculating sums of all taxes
        }

        $netDeliveryCost = $deliveryCost - $taxesAmount;
        $shippingItem->set_total($netDeliveryCost);
        $order->add_item($shippingItem);

        $order->calculate_totals();

        $order_id = $order->save();

        if($order_id){
            $max_return_days = 30;

            $response = array(
                "shopOrderId" => (string)$order_id,
                "returnPolicy" => array(
                    "maxReturnDays" => $max_return_days,
                )
            );

            // Increment order_count for the basket in your custom table
            global $wpdb;

            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE " . $wpdb->prefix . "oa_woocommerce_persistent_cart SET order_count = order_count + 1, order_key = %s, oaOrderId = %s WHERE cart_id = %s",
                    $order_id,
                    $data['oaOrderId'],
                    $cart_id
                )
            );


            /**
             * Once all processing is complete, update the order status
             */
            // Fetch the selected order status from the plugin settings
            $selected_status = $this->get_option('order_status', 'wc-processing');
            $selected_status_val = str_replace('wc-', '', $selected_status);

            // Update order status
            $order->update_status($selected_status_val);

            // Create WP_REST_Response object
            $wpRestResponse = new WP_REST_Response( $response, 200 );


            // Calculate X-Server-Authorization and set the header if it exists
            $expectedXServerAuth = $this->calculate_server_authorization($headers, $response);
            // Set X-Server-Authorization header if it exists
            if($expectedXServerAuth !== null) {
                $this->openappgw_custom_log("X-Server-Authorization: ".$expectedXServerAuth, $context);
                $wpRestResponse->set_headers(['X-Server-Authorization' => $expectedXServerAuth]);
            }

            $this->openappgw_custom_log("------------------------------------", $context);

            return $wpRestResponse;

        }else{
            return new WP_REST_Response( 'Error in Order Creation', 500 );
        }
    }

    public function handle_identity( WP_REST_Request $request ) {
        // Parse and validate request body
        $data = $request->get_json_params();

        // Fetch the headers from the request
        $headers = $request->get_headers();

        // logger
        $context = 'openapp_identity';
        $this->openappgw_custom_log(print_r($data, true), $context);
        $this->openappgw_custom_log(print_r($this->get_values_and_types($data), true), $context);
        $this->openappgw_custom_log("Headers: ".print_r($this->encodeJsonResponse($headers), true), $context); // Logs the headers

        $this->openappgw_custom_log("------------------------------------", $context);

        $body = $request->get_body();
        $bodyHash = hash('sha256', $body, true);
        $responseBodyHashBase64 = $this->base64Encode($bodyHash);

        $validHmac = $this->isRequestValid($headers, $responseBodyHashBase64);

        if(!$validHmac){
            return new WP_Error('invalid_auth', 'Unauthorized request', array('status' => 403));
        }

        // Sanitize and get the parameters
        $email = sanitize_email($data[ 'email' ]);
        $token = sanitize_text_field($data[ 'token' ]);


        if(empty($email) || empty($token)){
            return new WP_Error('missing_email_token', 'Incorrect parameters', array('status' => 400));
        }
        // check if token (cart_id) exists
        global $wpdb;

        $cart_data = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE cart_id = %s", $token)
        );

        if (is_null($cart_data)) {
            return new WP_REST_Response( "Session do not exists", 500 );
        }

        // check if token exists
        $auth_token = wp_generate_password(32, true, false);  // generates a 64-character random string

        // Get user by email
        $user = get_user_by( 'email', $email );

        if ( $user ) {
            $this->destroy_user_sessions( $user->ID );
            // If the user already exists, just update the token
            update_user_meta( $user->ID, 'oa_auth_token', $auth_token );

            // Update oa_auth_token
            $wpdb->query(
                $wpdb->prepare("UPDATE " . $wpdb->prefix . "oa_woocommerce_persistent_cart SET oa_auth_token = %s WHERE cart_id = %s", $auth_token, $token)
            );


            // update_user_meta( $user->ID, 'oa_last_login', time() );

        } else {
            // If the user does not exist, create a new user
            $random_password = wp_generate_password( 16, true );
            $user_id = $this->createWpUser($email, $random_password);

            // Check if the user was created successfully
            if ( ! is_wp_error( $user_id ) ) {
                $this->destroy_user_sessions( $user_id );
                // Update the token
                update_user_meta( $user_id, 'oa_auth_token', $auth_token );
                // Update oa_auth_token
                $wpdb->query(
                    $wpdb->prepare("UPDATE " . $wpdb->prefix . "oa_woocommerce_persistent_cart SET oa_auth_token = %s WHERE cart_id = %s", $auth_token, $token)
                );

            } else {
                // Handle error
                // For example, return a REST response with an error message
                return new WP_REST_Response( "Unable to create user.", 500 );
            }
        }

        $wpRestResponse = new WP_REST_Response( null, 200 );

        // Calculate X-Server-Authorization and set the header if it exists
        $expectedXServerAuth = $this->calculate_server_authorization($headers, null);

        if($expectedXServerAuth !== null) {
            $this->openappgw_custom_log("X-Server-Authorization: ".$expectedXServerAuth, $context);
            $wpRestResponse->set_headers(['X-Server-Authorization' => $expectedXServerAuth]);
        }

        return $wpRestResponse;
    }

    /**
     * ===========================================================================
     * 11. Render QR codes
     * ===========================================================================
     */

    public function openapp_qr_order($atts = array()){
        $sanitized_uri = filter_var($_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL);
        $this->log_debug('openapp_qr_order triggered at ' . gmdate('Y-m-d H:i:s') . ' by ' . $sanitized_uri);


        // parse the shortcode attributes
        $args = shortcode_atts(array(
            'lang' => sanitize_text_field(get_bloginfo('language')),
        ), $atts, 'openapp_qr_order');

        wp_enqueue_script('openappgw-js');

        global $woocommerce;
        $sessionId = $this->get_session_id();
        $cart_is_empty = $woocommerce->cart->is_empty();

        // Enqueue the script (only if it hasn't been enqueued already)
        if(!wp_script_is('openappgw-js-2', 'enqueued')) {
            wp_enqueue_script('openappgw-js-2', OPENAPPGW_PLUGIN_DIR_URL . 'assets/js/openapp-shortcodes-1.js', array('jquery'), OPENAPPGW_WOOCOMMERCE_GATEWAY, true);

            wp_enqueue_style('openapp-css', OPENAPPGW_PLUGIN_DIR_URL . 'assets/css/openapp-shortcode.css', array(), OPENAPPGW_WOOCOMMERCE_GATEWAY);

            // Pass PHP variables to JavaScript
            $localize_data = array(
                'baseUrl' => $this->get_base_url(),
                'cartId' => $this->get_cart_id_by_session($sessionId),
                'errorTextMessage' => __('OpenApp QR Code. Your cart is empty!','openapp-gateway-for-woocommerce'),
                'cartIsEmpty' => $cart_is_empty,
                'intervalTime' => $this->get_option('interval_time'),
                'sseEnabled' => false
            );

            if($this->is_sse_enabled()){
                $localize_data['sseEnabled'] = true;
            }

            wp_localize_script('openappgw-js-2', 'openappVars', $localize_data);
        }

        ob_start(); // Start capturing output into a buffer
        // Your CSS
        $output = '';

        $output .= '<div class="OpenAppCheckout-loading OpenAppCheckoutOrder" data-letter="C" data-style=""
data-merchantId="" data-integrationProfileId="" data-basketId="" data-basketValue="" data-basketCurrency="" data-uniqueProductsCount="" 
data-lang="'. $args['lang'] .'"
></div>';


       if(is_null($sessionId)){
           return "";
       }

        ob_end_clean(); // End capture and discard buffer

        $output = $this->minify_string($output);

        return $output; // Shortcodes in WordPress MUST return content
    }


    private function minify_string($string) {
        // Split the string into an array of lines
        $lines = explode("\n", $string);

        // Trim whitespace from each line
        $trimmed_lines = array_map('trim', $lines);

        // Filter out empty lines
        $non_empty_lines = array_filter($trimmed_lines);

        // Join the lines back into a single string
        return implode(' ', $non_empty_lines);
    }

    public function openapp_qr_login($atts = array()){
        $sanitized_uri = filter_var($_SERVER['REQUEST_URI'], FILTER_SANITIZE_URL);
        $this->log_debug('openapp_qr_login triggered at ' . gmdate('Y-m-d H:i:s') . ' by ' . $sanitized_uri);

        if (is_user_logged_in()) {
           return '<p><strong>OA login:</strong> user already login <a href="' . wp_logout_url( home_url()) .'" title="Logout">'. __('Logout'). '</a></p>';
        }

        if ( is_admin() ) {
            return;
        }

        // parse the shortcode attributes
        $args = shortcode_atts(array(
            'lang' => sanitize_text_field(get_bloginfo('language')),
        ), $atts, 'openapp_qr_login');

        wp_enqueue_script('openappgw-js');

        $sessionId = $this->get_session_id();
        $token = $this->get_cart_id_by_session($sessionId);
        $nonce = wp_create_nonce('wp_rest');

        // Enqueue the script (only if it hasn't been enqueued already)
        if(!wp_script_is('openappgw-js-3', 'enqueued')) {
            wp_enqueue_script('openappgw-js-3', OPENAPPGW_PLUGIN_DIR_URL . 'assets/js/openapp-shortcodes-2.js', array('jquery'), OPENAPPGW_WOOCOMMERCE_GATEWAY, true);

            wp_enqueue_style('openapp-css', OPENAPPGW_PLUGIN_DIR_URL . 'assets/css/openapp-shortcode.css', array(), OPENAPPGW_WOOCOMMERCE_GATEWAY);

            // Pass PHP variables to JavaScript
            $localize_data = array(
                'baseUrl' => esc_url($this->get_base_url()),
                'cartId' => sanitize_text_field($token),
                'errorTextMessage' => esc_html__('OpenApp QR Login. Something went wrong.', 'openapp-gateway-for-woocommerce')
            );
            wp_localize_script('openappgw-js-3', 'openappVars2', $localize_data);
        }

        $sessionActive = $this->user_session_is_active($token);

        if(!$sessionActive || is_null($token)){
            $activateLoginButtonHtml = '';

            $loginIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-login" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
  <path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" />
  <path d="M20 12h-13l3 -3m0 6l-3 -3" />
</svg>';

            $activateLoginButtonHtml .= '<p><a href="/?oa-set-guest-session=1" class="OpenAppButton" id="jsOaCheckSession2">' . __('Login via OpenApp') . $loginIcon . '</a></p>';

            return $activateLoginButtonHtml;
        }

        ob_start(); // Start capturing output into a buffer

        $output = '';

        $output .= '<div class="OpenAppCheckout-loading OpenAppCheckoutLogin" data-letter="I" data-version="1" data-merchantId="" data-integrationProfileId="" data-token="" data-lang="'. $args['lang'] .'"></div>';

        ob_end_clean(); // End capture and discard buffer
        return $output; // Shortcodes in WordPress MUST return content
    }


    /**
     * ===========================================================================
     * 12. Functions for: oa_woocommerce_persistent_cart
     * ===========================================================================
     */

    private function get_product_object($item){
       if(is_null($item)){
           return null;
       }

        if ($item['variation_id'] > 0) {
            $_product = wc_get_product($item['variation_id']);
        } else {
            $_product = wc_get_product($item['product_id']);
        }

        return $_product;
    }

    private function get_simplified_cart_contents($my_cart) {
        $simplified_cart_contents = [];

        foreach ($my_cart as $cart_item_key => $cart_item) {
            // Copy the cart item, but exclude the 'data' field
            $simplified_cart_item = $cart_item;
            unset($simplified_cart_item['data']);
            $simplified_cart_contents[$cart_item_key] = $simplified_cart_item;
        }

        return $simplified_cart_contents;
    }

    function filterProductDataProperty($product) {
        $productName = $product->get_name();
        $shippingClass = $product->get_shipping_class();
        $weight = $product->get_weight();
        $needsShipping = $product->needs_shipping();
        $customProduct = array(
            'name' => $productName,
            'shippingClass' => $shippingClass,
            'weight' => $weight,
            'needsShipping' => $needsShipping
        );
        return $customProduct;
    }

    private function get_cart_contents_data($cart_contents) {
        // error_log(wp_json_encode($cart_contents, JSON_PRETTY_PRINT));

        $cart_contents_data = [];

        foreach ($cart_contents as $cart_item_key => $cart_item) {
            if (isset($cart_item['data'])) {
                $cart_contents_data[$cart_item_key] = $this->filterProductDataProperty($cart_item['data']);
            }
        }

        return $cart_contents_data;
    }

    public function get_cart_id_by_session($session_id) {
        global $wpdb;

        if(is_null($session_id)){
            return null;
        }

        // $this->log_debug("SESSION_ID:".$session_id);

        // Hashing session ID
        $hashed_session_id = hash('md5', $session_id);

        // Preparing SQL query
        $cart_id = $wpdb->get_var($wpdb->prepare("SELECT cart_id FROM {$wpdb->prefix}oa_woocommerce_persistent_cart WHERE cart_session_id = %s", $hashed_session_id));


        if(is_null($cart_id)){
            // cart is empty.. @TODO ?
            // return $hashed_session_id;
        }

        return $cart_id;
    }


    private function get_basket_data($cart_id) {
        global $wpdb;

        $cart_data = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . $wpdb->prefix . "oa_woocommerce_persistent_cart WHERE cart_id = %s", $cart_id)
        );

        if (is_null($cart_data)) {
            return array();
        }

        $cart_contents_record = maybe_unserialize($cart_data->cart_contents);

        $cart_contents = $cart_contents_record['cart_contents'];
        $coupons = $cart_contents_record['coupon_data'];
        $userId = $cart_contents_record['user_id'];

        $products = array();

        foreach($cart_contents as $item_key => $item) {
            $product_data = $this->create_product_output_array_from_cart($item);
            if(!empty($product_data)){
                array_push($products, $product_data);
            }
        }

        // Assuming the price is in PLN and we need to convert it into grosz
        $total = array_sum(array_map(function($product) {
            return $product['linePrice'];
        }, $products));

        $discounts = $this->get_discounts($coupons);
        $deliveryOptions = $this->get_available_shipping_methods($this->supported_country, $total, $cart_contents_record);

        // Get currency - assuming all products in the cart have the same currency
        $currency = get_woocommerce_currency();

        $basket_data = array(
            'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 day')),
            'price' => array(
                'currency' => $currency,
                'basketValue' => round($total,2),
                'discounts' => $discounts
            ),
            'deliveryOptions' => $deliveryOptions,
            'products' => $products,
            'loggedUser' => $userId
        );

        // Prepare the response
        $response = array(
            'id' => $cart_id,
        );
        $response = array_merge($response, $basket_data);

        return $response;
    }



    public function get_qr_code_data(WP_REST_Request $request){
        $cart_id = $request->get_param('cart_id');

        if(empty($cart_id)){
            $cart_data = array(
                'merchant_id' => $this->merchant_id,
                'profile_id' => $this->profile_id,
                'cart_id' => $cart_id,
                'total_value' => 0,
                'currency' => '',
                'unique_products_count' => '',
            );
            $wpRestResponse = new WP_REST_Response( $cart_data, 200 );
            return $wpRestResponse;
        }

        global $wpdb;

        // Retrieve cart contents
        $serialized_cart_contents = $wpdb->get_var($wpdb->prepare("SELECT cart_contents FROM {$wpdb->prefix}oa_woocommerce_persistent_cart WHERE cart_id = %s", $cart_id));


        if (!$serialized_cart_contents) {
            // for login - we allow empty cart
            // return null;
        }

        // Unserialize the cart contents
        $cart_data = maybe_unserialize($serialized_cart_contents);

        if(empty($cart_data)){
            return null;
        }

        $cart_contents = $cart_data['cart_contents'];

        if(empty($cart_contents)){
            // $this->store_cart_in_db(true);
            // for login - we allow empty cart
            // return null;
        }

        // Initialize values
        $unique_products_count = 0;

        $products = array();
        foreach($cart_contents as $item_key => $item) {
            $product_data = $this->create_product_output_array_from_cart($item);
            if(!empty($product_data)){
                array_push($products, $product_data);
                $unique_products_count++;
            }
        }

        $total_value = array_sum(array_map(function($product) {
            return $product['linePrice'];
        }, $products));


        // Get currency - assuming all products in the cart have the same currency
        $currency = get_woocommerce_currency();

        $cart_data = array(
            'merchant_id' => $this->merchant_id,
            'profile_id' => $this->profile_id,
            'cart_id' => $cart_id,
            'total_value' => $total_value,
            'currency' => $currency,
            'unique_products_count' => $unique_products_count,
        );

        // wp_send_json($cart_data);
        $wpRestResponse = new WP_REST_Response( $cart_data, 200 );
        return $wpRestResponse;

    }


    /**
     * ===========================================================================
     * 13. HELPERS
     * ===========================================================================
     */

    private function get_base_url() {
        return rtrim(site_url(), '/');
    }

    private function openappgw_custom_log($message, $context = ''){
        if ( function_exists( 'openappgw_custom_log' ) ) {
            openappgw_custom_log($message, $context);
        }
    }

    private function get_values_and_types($data) {
        if(is_array($data) || is_object($data)) {
            foreach($data as $key => $value) {
                if(is_array($value) || is_object($value)) {
                    $data[$key] = $this->get_values_and_types($value); // Recursive call for nested array or objects
                } else {
                    $data[$key] = array('value' => $value, 'type' => gettype($value));
                }
            }
        } else {
            $data = array('value' => $data, 'type' => gettype($data));
        }
        return $data;
    }

    private function destroy_user_sessions( $user_id ) {
        $sessions = WP_Session_Tokens::get_instance( $user_id );
        $sessions->destroy_all();
    }

    private function get_session_id(){
        $sessionId = null;
        if (isset(WC()->session)) {
            $sessionId = WC()->session->get_customer_id();
        }

        return $sessionId;
    }

    private function createResponse($data, $status = 200)
    {
        return new WP_REST_Response($data, $status);
    }

    /** @TODO */
    private function validate_discount($discount) {
        // Implement your validation logic here...
        // You could check if a coupon with the given code exists,
        // if it's still valid (not expired), etc.

        // For now, let's say all discounts are valid
        return true;
    }


    protected function encodeJsonResponse($response)
    {
        // return json_encode($response);
        return wp_json_encode($response, JSON_UNESCAPED_SLASHES);
    }


    private function create_product_output_array($_product, $quantity, $error = null) {
        $product_url = wp_get_attachment_url($_product->get_image_id());

        $unit_price = round($_product->get_price() * 100);
        $line_price = round($_product->get_price() * $quantity * 100);
        $originalUnitPrice = round($_product->get_price() * 100);
        $originalLinePrice = round($_product->get_price() * $quantity * 100);

        $product_data =  array(
            'ean' => $_product->get_sku(),
            'id' => (string)$_product->get_id(),
            'name' => $_product->get_name(),
            'images' => array($product_url),
            'quantity' => $quantity,
            'unitPrice' => $unit_price,
            'linePrice' => $line_price,
            'originalUnitPrice' => $originalUnitPrice,
            'originalLinePrice' => $originalLinePrice
        );
        if($error){
            $product_data['error'] = $error;
        }

        return $product_data;

    }

    private function create_product_output_array_from_cart($item) {
        // Important! Return main product or variation
        $_product = $this->get_product_object($item);

        if(is_null($_product)){
            return array();
        }

        $product_url = wp_get_attachment_url($_product->get_image_id());

        // Unit prices before discount
        $original_unit_price_exclusive = $item['line_subtotal'] / $item['quantity'];
        $original_unit_tax = $item['line_subtotal_tax'] / $item['quantity'];
        $original_unit_price_inclusive = $original_unit_price_exclusive + $original_unit_tax;

        // Unit prices after discount
        $unit_price_exclusive = $item['line_total'] / $item['quantity'];
        $unit_tax = $item['line_tax'] / $item['quantity'];
        $unit_price_inclusive = $unit_price_exclusive + $unit_tax;

        // Line prices (for all quantities)
        $line_price_exclusive = $item['line_total'];
        $line_tax = $item['line_tax'];
        $line_price_inclusive = $line_price_exclusive + $line_tax;

        // Original line prices (for all quantities) before discount
        $original_line_price_exclusive = $item['line_subtotal'];
        $original_line_tax = $item['line_subtotal_tax'];
        $original_line_price_inclusive = $original_line_price_exclusive + $original_line_tax;

        $product_data = array(
            'ean' => $_product->get_sku(),
            'id' => (string)$_product->get_id(),
            'name' => $_product->get_name(),
            'images' => array($product_url),
            'quantity' => $item['quantity'],
            'unitPrice' => round($unit_price_inclusive * 100),  // Converted to grosz
            'linePrice' => round($line_price_inclusive * 100),
            'originalUnitPrice' => round($original_unit_price_inclusive * 100),
            'originalLinePrice' => round($original_line_price_inclusive * 100),
        );

        return $product_data;
    }

    private function get_delivery_options() {
        $methods = $this->shipping_methods;

        $enabled_methods = array();

        foreach ($methods as $key => $method) {
            if ('yes' === get_option('woocommerce_openapp_' . $key . '_enabled')) {
                $enabled_methods[] = array(
                    'key' => $key,
                    // convert cost from decimal format to minor currency unit (cents)
                    'cost' => (int) (get_option('woocommerce_openapp_' . $key . '_cost') * 100)
                );
            }
        }

        return $enabled_methods;
    }


    private function get_discounts($coupons) {
        $discounts = array();

        foreach($coupons as $coupon) {
            $discounts[] = array(
                'code' => $coupon['code'],
                'value' => round($coupon['discount_amount'] * 100)
            );
        }

        return $discounts;
    }

    private function user_session_is_active($token){

        $woocommerceSessionActive = false;

        if (isset(WC()->session)) {
            if (WC()->session->has_session()) {
                $woocommerceSessionActive = true;
            }
        }

        if($woocommerceSessionActive && !is_null($token)){
            return true;
        }

        return false;
    }

    private function is_heartbeat() {
        $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS);
        return ($action == 'heartbeat');
    }

    private function base64Encode($data) {
        return base64_encode($data);
    }

    private function createWpUser($email, $random_password) {
        $user_id = wc_create_new_customer($email, '', $random_password);

        if (is_wp_error($user_id)) {
            // Handle the error.
            //echo 'Unable to create a new user: ' . $user_id->get_error_message();
            // return $user_id;
        }

        return $user_id;
    }

    /**
     * ===========================================================================
     * 14. TESTING
     * ===========================================================================
     */

    public function log_debug($message) {
        if ('yes' === $this->get_option('debug', 'no')) {
            if (is_array($message)) {
                $message = print_r($message, true);
            }
            $timestamp = gmdate('Y-m-d H:i:s');
            error_log("[OpenApp {$timestamp}] " . $message);
        }
    }

    private function mask_secret($secret){
        $start = substr($secret, 0, 4); // Get the first four characters
        $end = substr($secret, -4); // Get the last four characters
        $masked = str_repeat('*', strlen($secret) - 8); // Generate a string of '*' with the same length as the remaining characters

        return $start . $masked . $end; // Return the masked secret
    }


}
