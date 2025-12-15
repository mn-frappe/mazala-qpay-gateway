<?php
/**
 * Plugin Name: Mazala QPay Gateway for WooCommerce
 * Description: qPay v2 WooCommerce Gateway with full eBarimt integration (Mongolian tax receipts)
 * Version: 1.2.0
 * Author: Mazala
 * Author URI: https://icloud.mn
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: qpay-gateway
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 5.0
 * WC tested up to: 9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Check if WooCommerce is active
 */
function mzqpay_is_woocommerce_active() {
    // Check if WooCommerce class exists or plugin is in active plugins list.
    $active_plugins = (array) get_option( 'active_plugins', array() );
    return class_exists( 'WooCommerce' ) || in_array( 'woocommerce/woocommerce.php', $active_plugins, true );
}

/**
 * Display admin notice if WooCommerce is not active
 */
function mzqpay_woocommerce_missing_notice() {
    ?>
    <div class="error notice is-dismissible">
        <p>
            <strong><?php esc_html_e( 'Mazala QPay Gateway', 'qpay-gateway' ); ?></strong>
            <?php esc_html_e( 'requires WooCommerce to be installed and active.', 'qpay-gateway' ); ?>
            <a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>">
                <?php esc_html_e( 'Install WooCommerce', 'qpay-gateway' ); ?>
            </a>
        </p>
    </div>
    <?php
}

/**
 * Deactivate the plugin if WooCommerce is not active
 */
function mzqpay_deactivate_self() {
    deactivate_plugins( plugin_basename( __FILE__ ) );
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is standard plugin deactivation pattern.
    if ( isset( $_GET['activate'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        unset( $_GET['activate'] );
    }
}

/**
 * Check WooCommerce dependency on plugin activation
 */
function mzqpay_check_woocommerce_on_activation() {
    if ( ! mzqpay_is_woocommerce_active() ) {
        mzqpay_deactivate_self();
        wp_die(
            esc_html__( 'Mazala QPay Gateway requires WooCommerce to be installed and active. Please install WooCommerce first.', 'qpay-gateway' ),
            esc_html__( 'Plugin Activation Error', 'qpay-gateway' ),
            array( 'back_link' => true )
        );
    }
    
    // Run plugin activation tasks (set defaults, schedule cron, etc.)
    mzqpay_run_activation_tasks();
}
register_activation_hook( __FILE__, 'mzqpay_check_woocommerce_on_activation' );

/**
 * Plugin activation tasks - set defaults, schedule cron jobs, create DB tables
 */
function mzqpay_run_activation_tasks() {
    // Set default options
    if ( get_option( 'mzqpay_mode' ) === false ) {
        update_option( 'mzqpay_mode', 'sandbox' );
    }
    if ( get_option( 'mzqpay_sandbox_client_id' ) === false ) {
        update_option( 'mzqpay_sandbox_client_id', 'TEST_MERCHANT' );
    }
    if ( get_option( 'mzqpay_sandbox_client_secret' ) === false ) {
        update_option( 'mzqpay_sandbox_client_secret', '123456' );
    }
    
    // Use Action Scheduler if available (preferred), otherwise WP Cron
    if ( class_exists( 'ActionScheduler' ) || function_exists( 'as_schedule_recurring_action' ) ) {
        if ( function_exists( 'as_next_scheduled_action' ) && ! as_next_scheduled_action( 'mzqpay_process_queue_hook' ) ) {
            as_schedule_recurring_action( time(), 300, 'mzqpay_process_queue_hook', array(), 'qpay-gateway' );
        }
    } else {
        if ( ! wp_next_scheduled( 'mzqpay_process_queue_hook' ) ) {
            // Register the custom schedule first
            add_filter( 'cron_schedules', 'mzqpay_add_cron_schedule' );
            wp_schedule_event( time(), 'mzqpay_every_five_minutes', 'mzqpay_process_queue_hook' );
        }
    }
}

/**
 * Add custom cron schedule
 */
function mzqpay_add_cron_schedule( $schedules ) {
    if ( ! isset( $schedules['mzqpay_every_five_minutes'] ) ) {
        $schedules['mzqpay_every_five_minutes'] = array(
            'interval' => 300,
            'display'  => esc_html__( 'Every 5 Minutes', 'qpay-gateway' )
        );
    }
    return $schedules;
}
add_filter( 'cron_schedules', 'mzqpay_add_cron_schedule' );

/**
 * Plugin deactivation tasks - clear scheduled jobs
 */
function mzqpay_run_deactivation_tasks() {
    // Clear Action Scheduler jobs
    if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_unschedule_all_actions' ) ) {
        as_unschedule_all_actions( 'mzqpay_process_queue_hook', array(), 'qpay-gateway' );
    }
    // Clear WP Cron
    $timestamp = wp_next_scheduled( 'mzqpay_process_queue_hook' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'mzqpay_process_queue_hook' );
    }
}
register_deactivation_hook( __FILE__, 'mzqpay_run_deactivation_tasks' );

/**
 * Initialize plugin only if WooCommerce is active
 */
function mzqpay_init_plugin() {
    if ( ! mzqpay_is_woocommerce_active() ) {
        add_action( 'admin_notices', 'mzqpay_woocommerce_missing_notice' );
        return;
    }
    
    // Load the rest of the plugin
    mzqpay_load_plugin();
}
add_action( 'plugins_loaded', 'mzqpay_init_plugin', 10 );

/**
 * Load plugin functionality after WooCommerce check passes
 */
function mzqpay_load_plugin() {
    // Define constants
    if ( ! defined( 'MZQPAY_PLUGIN_DIR' ) ) {
        define( 'MZQPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
    }
    if ( ! defined( 'MZQPAY_PLUGIN_URL' ) ) {
        define( 'MZQPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
    }
    if ( ! defined( 'MZQPAY_VERSION' ) ) {
        define( 'MZQPAY_VERSION', '1.2.0' );
    }
    if ( ! defined( 'MZQPAY_PLUGIN_FILE' ) ) {
        define( 'MZQPAY_PLUGIN_FILE', __FILE__ );
    }

/**
 * Declare HPOS (High-Performance Order Storage) and COT (Custom Order Tables) compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // HPOS / Custom Order Tables support
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        // Cart/Checkout Blocks support
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
    
    // Register with COT (Custom Order Tables) data store
    if (class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
        // Plugin is COT-ready - uses CRUD methods and order meta API
    }
});

/**
 * Extend Store API with MZQPay payment data (for headless/API-first setups)
 */
add_action('woocommerce_blocks_loaded', function() {
    if (function_exists('woocommerce_store_api_register_endpoint_data')) {
        woocommerce_store_api_register_endpoint_data([
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
            'namespace'       => 'qpay-gateway',
            'data_callback'   => function() {
                return [
                    'supports_qr_payment' => true,
                    'supports_bank_apps'  => true,
                    'supports_ebarimt'    => true,
                ];
            },
            'schema_callback' => function() {
                return [
                    'supports_qr_payment' => [
                        'description' => __('Whether QR code payment is supported', 'qpay-gateway'),
                        'type'        => 'boolean',
                        'context'     => ['view', 'edit'],
                        'readonly'    => true,
                    ],
                    'supports_bank_apps' => [
                        'description' => __('Whether bank app deep links are supported', 'qpay-gateway'),
                        'type'        => 'boolean',
                        'context'     => ['view', 'edit'],
                        'readonly'    => true,
                    ],
                    'supports_ebarimt' => [
                        'description' => __('Whether eBarimt tax receipts are supported', 'qpay-gateway'),
                        'type'        => 'boolean',
                        'context'     => ['view', 'edit'],
                        'readonly'    => true,
                    ],
                ];
            },
            'schema_type'     => ARRAY_A,
        ]);
    }
});

/**
 * Translations are automatically loaded by WordPress.org for hosted plugins.
 * No need for load_plugin_textdomain() - WordPress handles it automatically since 4.6.
 */

/**
 * Add Settings link to plugin action links (before Deactivate)
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=mzqpay');
    $settings_link = '<a href="' . esc_url($settings_url) . '">' . __('Settings', 'qpay-gateway') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
// Note: activation registered further below (schedules queue worker)

// Load fixtures class early (needed by gateway and admin)
require_once MZQPAY_PLUGIN_DIR . 'includes/class-mzqpay-fixtures.php';

// Include gateway class
add_action('plugins_loaded','mzqpay_init_gateway', 11);
function mzqpay_init_gateway(){
    if ( ! class_exists('WC_Payment_Gateway') ) return;
    require_once MZQPAY_PLUGIN_DIR . 'includes/class-mzqpay-gateway.php';
    // register
    add_filter('woocommerce_payment_gateways', function($methods){
        $methods[] = 'MZQPay_Payment_Gateway';
        return $methods;
    });
}

/**
 * Register WooCommerce Blocks integration
 */
add_action('woocommerce_blocks_loaded', function() {
    if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
        return;
    }
    
    require_once MZQPAY_PLUGIN_DIR . 'includes/class-mzqpay-blocks-support.php';
    
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function($payment_method_registry) {
            $payment_method_registry->register(new MZQPay_Blocks_Support());
        }
    );
});

/**
 * Return a gateway instance. Filterable for tests to inject mocks.
 */
function mzqpay_get_gateway(){
    return apply_filters('mzqpay_gateway_instance', new MZQPay_Payment_Gateway());
}

/**
 * Use WooCommerce Action Scheduler if available, fallback to WP Cron
 */
function mzqpay_uses_action_scheduler() {
    return class_exists('ActionScheduler') || function_exists('as_schedule_recurring_action');
}

/**
 * Queue processing handler - runs via scheduled task
 */
add_action('mzqpay_process_queue_hook', 'mzqpay_process_queue_handler');
function mzqpay_process_queue_handler(){
    $gateway = mzqpay_get_gateway();
    $gateway->process_queue_tasks();
}

/**
 * Enqueue a task onto the outbound queue
 */
function mzqpay_enqueue_task($task){
    $gateway = mzqpay_get_gateway();
    $gateway->enqueue_task($task);
}

// Admin order actions for invoice cancel and refund
add_filter('woocommerce_order_actions', 'mzqpay_order_actions');
function mzqpay_order_actions($actions){
    $actions['mzqpay_cancel_invoice'] = __('qPay Cancel Invoice','qpay-gateway');
    $actions['mzqpay_refund_payment'] = __('qPay Refund Payment','qpay-gateway');
    $actions['mzqpay_cancel_payment'] = __('qPay Cancel Payment (Card only)','qpay-gateway');
    $actions['mzqpay_cancel_ebarimt'] = __('qPay Cancel eBarimt','qpay-gateway');
    $actions['mzqpay_get_payment_status'] = __('qPay Get Payment Status','qpay-gateway');
    return $actions;
}

add_action('woocommerce_order_action_mzqpay_cancel_invoice', 'mzqpay_order_action_cancel');
function mzqpay_order_action_cancel($order){
    $gateway = mzqpay_get_gateway();
    $res = $gateway->cancel_invoice($order);
    if (is_wp_error($res)) $order->add_order_note('qPay cancel failed: ' . $res->get_error_message());
}

add_action('woocommerce_order_action_mzqpay_refund_payment', 'mzqpay_order_action_refund');
function mzqpay_order_action_refund($order){
    $gateway = mzqpay_get_gateway();
    $res = $gateway->refund_payment($order);
    if (is_wp_error($res)) $order->add_order_note('qPay refund failed: ' . $res->get_error_message());
}

add_action('woocommerce_order_action_mzqpay_cancel_payment', 'mzqpay_order_action_cancel_payment');
function mzqpay_order_action_cancel_payment($order){
    $gateway = mzqpay_get_gateway();
    $payment_id = $order->get_meta('_mzqpay_payment_id');
    if (empty($payment_id)) {
        $order->add_order_note('qPay cancel payment failed: No payment ID found');
        return;
    }
    $callback_url = get_home_url(null, '/?mzqpay_callback=1&order_id=' . $order->get_id());
    $res = $gateway->cancel_payment($payment_id, $callback_url, 'Admin cancel');
    if (is_wp_error($res)) {
        $order->add_order_note('qPay cancel payment failed: ' . $res->get_error_message());
    } else {
        $order->add_order_note('qPay payment cancellation initiated.');
    }
}

add_action('woocommerce_order_action_mzqpay_cancel_ebarimt', 'mzqpay_order_action_cancel_ebarimt');
function mzqpay_order_action_cancel_ebarimt($order){
    $gateway = mzqpay_get_gateway();
    $ebarimt_response = $order->get_meta('_mzqpay_ebarimt_response');
    if (empty($ebarimt_response)) {
        $order->add_order_note('qPay cancel eBarimt failed: No eBarimt response found');
        return;
    }
    $ebarimt_data = json_decode($ebarimt_response, true);
    $ebarimt_id = isset($ebarimt_data['id']) ? $ebarimt_data['id'] : (isset($ebarimt_data['ebarimt_id']) ? $ebarimt_data['ebarimt_id'] : '');
    if (empty($ebarimt_id)) {
        $order->add_order_note('qPay cancel eBarimt failed: No eBarimt ID found in response');
        return;
    }
    $res = $gateway->cancel_ebarimt($ebarimt_id);
    if (is_wp_error($res)) {
        $order->add_order_note('qPay cancel eBarimt failed: ' . $res->get_error_message());
    } else {
        $order->add_order_note('qPay eBarimt cancelled successfully.');
        $order->delete_meta_data('_mzqpay_ebarimt_response');
        $order->save();
    }
}

add_action('woocommerce_order_action_mzqpay_get_payment_status', 'mzqpay_order_action_get_payment_status');
function mzqpay_order_action_get_payment_status($order){
    $gateway = mzqpay_get_gateway();
    $payment_id = $order->get_meta('_mzqpay_payment_id');
    if (!empty($payment_id)) {
        $res = $gateway->get_payment($payment_id);
        if (is_wp_error($res)) {
            $order->add_order_note('qPay get payment status failed: ' . $res->get_error_message());
        } else {
            $order->add_order_note('qPay payment status: ' . wp_json_encode($res));
        }
        return;
    }
    // Try invoice-based check
    $invoice_id = $order->get_meta('_mzqpay_invoice_id');
    if (!empty($invoice_id)) {
        $res = $gateway->list_payments(array('object_type' => 'INVOICE', 'object_id' => $invoice_id));
        if (is_wp_error($res)) {
            $order->add_order_note('qPay get payment status failed: ' . $res->get_error_message());
        } else {
            $order->add_order_note('qPay payment list: ' . wp_json_encode($res));
        }
        return;
    }
    $order->add_order_note('qPay get payment status: No payment or invoice ID found');
}

// Admin management page: Outbound queue and token cache inspector
add_action('admin_menu', function(){
    add_management_page('Mazala qPay Queue','Mazala qPay Queue','manage_options','mzqpay-queue','mzqpay_queue_page');
});

// Enqueue admin scripts and styles for the MZQPay management page
add_action('admin_enqueue_scripts', function($hook){
    if ($hook !== 'tools_page_mzqpay-queue') return;
    wp_enqueue_style('mzqpay-admin', plugins_url('assets/css/wooqpay.min.css', __FILE__), array(), MZQPAY_VERSION);
    wp_enqueue_script('mzqpay-admin', plugins_url('assets/js/wooqpay-admin.min.js', __FILE__), array('jquery'), MZQPAY_VERSION, true);
    wp_localize_script('mzqpay-admin', 'mzqpay_admin', array('ajax_url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('mzqpay_admin')));
});

// Enqueue frontend styles for checkout/thank-you pages
add_action('wp_enqueue_scripts', function(){
    if (!is_checkout() && !is_wc_endpoint_url('order-received')) return;
    wp_enqueue_style('mzqpay-frontend', plugins_url('assets/css/wooqpay.min.css', __FILE__), array(), MZQPAY_VERSION);
});

// AJAX handler for Test Connection button
add_action('wp_ajax_mzqpay_test_connection', 'mzqpay_ajax_test_connection_handler');

function mzqpay_ajax_test_connection_handler() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Unauthorized'), 403);
    }
    
    check_ajax_referer('mzqpay_test_connection', 'nonce');
    
    $gateway = mzqpay_get_gateway();
    $creds = $gateway->get_credentials();
    $mode = $gateway->get_option('mode', 'sandbox');
    
    // Test authentication
    $response = wp_remote_post($creds['auth_url'], array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($creds['client_id'] . ':' . $creds['client_secret']),
            'Content-Type' => 'application/json',
        ),
        'timeout' => 30,
        'body' => '{}',
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => 'Connection failed: ' . $response->get_error_message()
        ));
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($code === 200 && !empty($body['access_token'])) {
        $mode_text = $mode === 'sandbox' ? 'Sandbox' : 'Production';
        wp_send_json_success(array(
            'message' => sprintf('API authenticated successfully (%s mode)', $mode_text),
            'mode' => $mode,
            'token_preview' => substr($body['access_token'], 0, 20) . '...'
        ));
    } else {
        $error_msg = isset($body['message']) ? $body['message'] : (isset($body['error']) ? $body['error'] : 'Unknown error');
        wp_send_json_error(array(
            'message' => sprintf('Auth failed (HTTP %d): %s', $code, $error_msg)
        ));
    }
}

// AJAX handler for retrying refunds (called from admin JS)
add_action('wp_ajax_mzqpay_retry_refunds', 'mzqpay_ajax_retry_refunds_handler');

function mzqpay_retry_refunds_exec($ids){
    $results = array();
    $gateway = mzqpay_get_gateway();
    foreach($ids as $id){
        $idn = intval($id);
        $res = $gateway->perform_refund_attempt($idn);
        if (is_wp_error($res)){
            $results[$idn] = array('ok'=>false,'error'=>$res->get_error_message());
        } else {
            $results[$idn] = array('ok'=>true);
        }
    }
    return $results;
}

function mzqpay_ajax_retry_refunds_handler(){
    if (!current_user_can('manage_options')) wp_send_json_error('unauthorized',403);
    check_ajax_referer('mzqpay_admin');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- array_map with intval below
    $ids = isset($_POST['ids']) ? array_map('intval', wp_unslash((array) $_POST['ids'])) : array();
    $results = mzqpay_retry_refunds_exec($ids);
    wp_send_json_success($results);
}

// DB installation for persistent queue
function mzqpay_install_db_table(){
        global $wpdb;
        $table_name = $wpdb->prefix . 'mzqpay_queue';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            type varchar(64) NOT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            payment_id varchar(128) DEFAULT NULL,
            payload longtext DEFAULT NULL,
            attempts int(11) DEFAULT 0,
            last_error text DEFAULT NULL,
            next_run datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY next_run (next_run)
        ) $charset_collate;";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // refunds table for idempotency and reconciliation
        $refunds_table = $wpdb->prefix . 'mzqpay_refunds';
        $sql2 = "CREATE TABLE $refunds_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned DEFAULT NULL,
            payment_id varchar(128) DEFAULT NULL,
            refund_id varchar(128) DEFAULT NULL,
            idempotency_key varchar(128) DEFAULT NULL,
            status varchar(32) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            last_error text DEFAULT NULL,
            response longtext DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY idempotency_key (idempotency_key)
        ) $charset_collate;";
        dbDelta($sql2);
}

function mzqpay_queue_page(){
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    // Handle actions
    if (isset($_POST['mzqpay_action']) && check_admin_referer('mzqpay_queue_action')){
        $gateway = mzqpay_get_gateway();
        if ($_POST['mzqpay_action'] === 'process_now'){
            $gateway->process_queue_tasks(100);
            echo '<div class="updated"><p>Processed up to 100 queued tasks.</p></div>';
        }
        if ($_POST['mzqpay_action'] === 'clear_queue'){
            $wpdb->query("TRUNCATE TABLE " . $wpdb->prefix . 'mzqpay_queue');
            echo '<div class="updated"><p>Queue cleared.</p></div>';
        }

        // Refunds admin actions
        if ($_POST['mzqpay_action'] === 'refund_retry' && !empty($_POST['refund_id'])){
            $rid = intval($_POST['refund_id']);
            $res = $gateway->perform_refund_attempt($rid);
            if (is_wp_error($res)){
                echo '<div class="error"><p>Refund retry failed: ' . esc_html($res->get_error_message()) . '</p></div>';
            } else {
                echo '<div class="updated"><p>Refund retry succeeded for id ' . esc_html($rid) . '.</p></div>';
            }
        }

        if ($_POST['mzqpay_action'] === 'refund_mark' && !empty($_POST['refund_id'])){
            $rid = intval($_POST['refund_id']);
            $table = $wpdb->prefix . 'mzqpay_refunds';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
            $wpdb->update($table, array('status'=>'manual','updated_at'=>current_time('mysql')), array('id'=>$rid));
            echo '<div class="updated"><p>Marked refund ' . esc_html($rid) . ' as manual.</p></div>';
        }

        if ($_POST['mzqpay_action'] === 'refund_export'){
            // export refunds as CSV
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mzqpay_refunds ORDER BY created_at DESC LIMIT %d", 200), ARRAY_A);
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="mzqpay_refunds.csv"');
            $out = fopen('php://output','w');
            fputcsv($out, array('id','order_id','payment_id','refund_id','idempotency_key','status','attempts','last_error','created_at','updated_at'));
            foreach($rows as $r) fputcsv($out, array($r['id'],$r['order_id'],$r['payment_id'],$r['refund_id'],$r['idempotency_key'],$r['status'],$r['attempts'],$r['last_error'],$r['created_at'],$r['updated_at']));
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Using php://output stream
            fclose($out);
            exit;
        }
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query
    $queue = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mzqpay_queue ORDER BY next_run ASC, id ASC LIMIT 200", ARRAY_A);
    echo '<div class="wrap"><h1>Mazala QPay Gateway - Outbound Queue</h1>';
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns safe HTML
    echo '<form method="post">' . wp_nonce_field('mzqpay_queue_action', '_wpnonce', true, false) . '<p>';
    echo '<button class="button button-primary" name="mzqpay_action" value="process_now">Process Queue Now</button> ';
    echo '<button class="button" name="mzqpay_action" value="clear_queue">Clear Queue</button>';
    echo '</p></form>';

    echo '<h2>Pending tasks</h2>';
    if (empty($queue)){
        echo '<p>No queued tasks.</p>';
    } else {
        echo '<table class="widefat"><thead><tr><th>#</th><th>Type</th><th>Order</th><th>Payment ID</th><th>Attempts</th><th>Payload</th></tr></thead><tbody>';
        foreach($queue as $i => $task){
            echo '<tr>';
            echo '<td>' . intval($i+1) . '</td>';
            echo '<td>' . esc_html(isset($task['type'])?$task['type']:'') . '</td>';
            echo '<td>' . esc_html(isset($task['order_id'])?$task['order_id']:'') . '</td>';
            echo '<td>' . esc_html(isset($task['payment_id'])?$task['payment_id']:'') . '</td>';
            echo '<td>' . esc_html(isset($task['attempts'])?intval($task['attempts']):0) . '</td>';
            echo '<td><pre style="max-width:600px;white-space:pre-wrap;">' . esc_html(wp_json_encode(isset($task['payload'])?$task['payload']:$task)) . '</pre></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h2>Token cache</h2>';
    // find transients for mzqpay_token_
    $like = $wpdb->esc_like('_transient_mzqpay_token_') . '%';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transient lookup
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    if (empty($rows)){
        echo '<p>No cached tokens found.</p>';
    } else {
        echo '<table class="widefat"><thead><tr><th>Key</th><th>Value</th><th>Timeout</th></tr></thead><tbody>';
        foreach($rows as $r){
            $key = $r->option_name;
            $val = maybe_unserialize($r->option_value);
            $display_val = is_array($val) ? wp_json_encode($val) : $val;
            $timeout_key = str_replace('_transient_','_transient_timeout_',$key);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transient lookup
            $timeout_row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $timeout_key));
            $timeout = $timeout_row ? intval($timeout_row->option_value) : 0;
            $remaining = $timeout > time() ? ($timeout - time()) . 's' : 'expired';
            echo '<tr><td>' . esc_html($key) . '</td><td><pre style="max-width:600px;white-space:pre-wrap;">' . esc_html($display_val) . '</pre></td><td>' . esc_html($remaining) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';

    // Refunds management
    echo '<div class="wrap"><h1>Mazala QPay Gateway - Refunds</h1>';
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns safe HTML
    echo '<form method="post">' . wp_nonce_field('mzqpay_queue_action', '_wpnonce', true, false) . '<p>';
    echo '<button class="button" name="mzqpay_action" value="refund_export">Export refunds (CSV)</button>';
    echo ' <button id="mzqpay_retry_selected" class="button" type="button">Retry Selected (AJAX)</button>';
    echo '</p></form>';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query
    $refunds = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}mzqpay_refunds ORDER BY created_at DESC LIMIT %d", 200), ARRAY_A);
    if (empty($refunds)){
        echo '<p>No refunds found.</p>';
    } else {
        echo '<table class="widefat"><thead><tr><th>#</th><th>Order</th><th>Payment</th><th>Status</th><th>Attempts</th><th>Last Error</th><th>Actions</th></tr></thead><tbody>';
        foreach($refunds as $i => $r){
            $rid = intval($r['id']);
            echo '<tr data-refund-id="' . esc_attr($rid) . '">';
            echo '<td><input type="checkbox" class="mzqpay-refund-select" value="' . esc_attr($rid) . '" /></td>';
            echo '<td>' . intval($r['id']) . '</td>';
            echo '<td>' . esc_html($r['order_id']) . '</td>';
            echo '<td>' . esc_html($r['payment_id']) . '</td>';
            echo '<td class="mzqpay-status">' . esc_html($r['status']) . '</td>';
            echo '<td>' . esc_html(intval($r['attempts'])) . '</td>';
            echo '<td><pre style="white-space:pre-wrap;">' . esc_html(substr($r['last_error'],0,200)) . '</pre></td>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns safe HTML
            echo '<td><form method="post" style="display:inline-block;margin:0 4px;">' . wp_nonce_field('mzqpay_queue_action', '_wpnonce', true, false) . '<input type="hidden" name="refund_id" value="' . esc_attr($rid) . '"><button class="button" name="mzqpay_action" value="refund_retry">Retry</button></form>';
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field returns safe HTML
            echo '<form method="post" style="display:inline-block;margin:0 4px;">' . wp_nonce_field('mzqpay_queue_action', '_wpnonce', true, false) . '<input type="hidden" name="refund_id" value="' . esc_attr($rid) . '"><button class="button" name="mzqpay_action" value="refund_mark">Mark Manual</button></form>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    // Fixtures Status Section
    echo '<div class="wrap"><h1>Mazala QPay Gateway - Fixtures Status</h1>';
    if (class_exists('MZQPay_Fixtures')) {
        $stats = MZQPay_Fixtures::get_stats();
        $available = MZQPay_Fixtures::is_available();
        
        if ($available) {
            echo '<div class="notice notice-success"><p><strong>✓ Fixtures data loaded successfully</strong></p></div>';
            echo '<table class="widefat" style="max-width:600px;"><thead><tr><th>Data Type</th><th>Count</th></tr></thead><tbody>';
            echo '<tr><td>🏦 Banks</td><td>' . intval($stats['banks']) . '</td></tr>';
            echo '<tr><td>📍 Districts</td><td>' . intval($stats['districts']) . '</td></tr>';
            echo '<tr><td>🏷️ GS1 Classification Codes</td><td>' . number_format(intval($stats['gs1_codes'])) . '</td></tr>';
            echo '<tr><td>💱 Currencies</td><td>' . intval($stats['currencies']) . '</td></tr>';
            echo '<tr><td>📋 VAT Zero Codes (0%)</td><td>' . intval($stats['vat_zero']) . '</td></tr>';
            echo '<tr><td>📋 VAT Exempt Codes</td><td>' . intval($stats['vat_exempt']) . '</td></tr>';
            echo '<tr><td>⚠️ Error Messages</td><td>' . intval($stats['errors']) . '</td></tr>';
            echo '</tbody></table>';
            echo '<p><small>JSON Path: <code>' . esc_html($stats['json_path']) . '</code></small></p>';
            
            // Show supported currencies
            $currencies = MZQPay_Fixtures::get_currencies();
            echo '<h3>Supported Currencies</h3>';
            echo '<p>';
            foreach ($currencies as $code => $cur) {
                echo '<span class="currency-badge" style="display:inline-block;background:#f0f0f0;padding:4px 8px;margin:2px;border-radius:4px;"><strong>' . esc_html($code) . '</strong> - ' . esc_html($cur['name']) . '</span> ';
            }
            echo '</p>';
        } else {
            echo '<div class="notice notice-error"><p><strong>✗ Fixtures data not available</strong></p>';
            echo '<p>Please ensure the QPayAPIv2_parsed.json file exists in the docs plugin folder.</p></div>';
        }
    } else {
        echo '<div class="notice notice-warning"><p>MZQPay_Fixtures class not loaded.</p></div>';
    }
    echo '</div>';
}

// Add per-product GS1 metadata fields in product admin
add_action('woocommerce_product_options_general_product_data', 'mzqpay_product_fields');
function mzqpay_product_fields(){
    echo '<div class="options_group">';
    // GS1 code override
    woocommerce_wp_text_input( array(
        'id' => '_mzqpay_gs1_code',
        'label' => __('qPay GS1 code','qpay-gateway'),
        'description' => __('Optional: override GS1 classification code for this product (used for eBarimt).','qpay-gateway'),
        'desc_tip' => true,
    ) );

    // disable automatic mapping
    woocommerce_wp_checkbox( array(
        'id' => '_mzqpay_gs1_disable_map',
        'label' => __('Disable qPay GS1 auto-mapping','qpay-gateway'),
        'description' => __('If checked, the gateway will not attempt automatic GS1 mapping for this product.','qpay-gateway'),
    ) );

    // VAT code for special tax treatment
    $vat_options = array('' => __('Standard VAT (10%)', 'qpay-gateway'));
    if (class_exists('MZQPay_Fixtures')) {
        $vat_zero = MZQPay_Fixtures::get_vat_zero_codes();
        $vat_exempt = MZQPay_Fixtures::get_vat_exempt_codes();
        foreach ($vat_zero as $code => $vat) {
            $vat_options[$code] = '[0%] ' . $vat['name'];
        }
        foreach ($vat_exempt as $code => $vat) {
            $vat_options[$code] = '[Exempt] ' . $vat['name'];
        }
    }
    
    woocommerce_wp_select( array(
        'id' => '_mzqpay_vat_code',
        'label' => __('qPay VAT Code', 'qpay-gateway'),
        'description' => __('Select VAT treatment for this product (0% or exempt)', 'qpay-gateway'),
        'desc_tip' => true,
        'options' => $vat_options,
    ) );

    echo '</div>';
}

add_action('woocommerce_process_product_meta', 'mzqpay_save_product_fields');
function mzqpay_save_product_fields($post_id){
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies nonce in woocommerce_process_product_meta
    if (isset($_POST['_mzqpay_gs1_code'])){
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies nonce
        update_post_meta($post_id, '_mzqpay_gs1_code', sanitize_text_field(wp_unslash($_POST['_mzqpay_gs1_code'])));
    } else {
        delete_post_meta($post_id, '_mzqpay_gs1_code');
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies nonce
    $disable = isset($_POST['_mzqpay_gs1_disable_map']) ? 'yes' : 'no';
    update_post_meta($post_id, '_mzqpay_gs1_disable_map', $disable);
    
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies nonce
    if (isset($_POST['_mzqpay_vat_code'])){
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies nonce
        update_post_meta($post_id, '_mzqpay_vat_code', sanitize_text_field(wp_unslash($_POST['_mzqpay_vat_code'])));
    } else {
        delete_post_meta($post_id, '_mzqpay_vat_code');
    }
}

// Register REST callback endpoint for qPay to notify payment results
add_action('rest_api_init', function(){
    register_rest_route('mzqpay/v1', '/callback', array(
        'methods' => 'POST',
        'callback' => 'mzqpay_rest_callback',
        'permission_callback' => '__return_true'
    ));
});

function mzqpay_rest_callback($request){
    $raw_body = $request->get_body();
    $body = array();
    // Prefer structured JSON params, but fall back to decoding raw body for compatibility
    if (method_exists($request, 'get_json_params')){
        $body = $request->get_json_params();
    }
    if (empty($body)){
        $raw_body = $request->get_body();
        $decoded = json_decode($raw_body, true);
        if (is_array($decoded) && !empty($decoded)){
            $body = $decoded;
        }
    }
    if (empty($body)) {
        return new WP_REST_Response(array('error'=>'empty payload'), 400);
    }

    // Prefer invoice_id or payment_id
    $invoice_id = isset($body['invoice_id']) ? sanitize_text_field($body['invoice_id']) : (isset($body['object_id']) ? sanitize_text_field($body['object_id']) : null);
    $payment_id = isset($body['payment_id']) ? sanitize_text_field($body['payment_id']) : null;

    if (!$invoice_id && !$payment_id){
        return new WP_REST_Response(array('error'=>'no invoice_id or payment_id'), 400);
    }

    // verify signature if provided and webhook secret configured
    $gateway = mzqpay_get_gateway();
    // configurable header and algorithm
    $webhook_secret = $gateway->get_option('webhook_secret', get_option('mzqpay_webhook_secret',''));
    $sig_header_name = $gateway->get_option('webhook_signature_header', get_option('mzqpay_webhook_signature_header','x-qpay-signature'));
    $sig_alg = $gateway->get_option('webhook_signature_alg', get_option('mzqpay_webhook_signature_alg','sha256'));
    $sig_header = $request->get_header($sig_header_name);
    if ($webhook_secret){
        if (!$sig_header){
            return new WP_REST_Response(array('error'=>'missing_signature'), 400);
        }
        $algo = in_array($sig_alg, hash_algos(), true) ? $sig_alg : 'sha256';
        $calc = hash_hmac($algo, $raw_body, $webhook_secret);
        if (!hash_equals($calc, $sig_header)){
            return new WP_REST_Response(array('error'=>'invalid_signature'), 403);
        }
    }

    // find order by invoice meta
    $order = null;
    if ($invoice_id){
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- required for order lookup
        $orders = wc_get_orders(array('limit'=>1,'meta_key'=>'_mzqpay_invoice_id','meta_value'=>$invoice_id));
        if (!empty($orders)) $order = $orders[0];
    }

    if (!$order && $payment_id){
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- required for order lookup
        $orders = wc_get_orders(array('limit'=>1,'meta_key'=>'_mzqpay_payment_id','meta_value'=>$payment_id));
        if (!empty($orders)) $order = $orders[0];
    }

    if (!$order){
        return new WP_REST_Response(array('error'=>'order not found'), 404);
    }

    // verify with qPay payment/check using saved credentials
    $gateway = mzqpay_get_gateway();
    $mode = $gateway->get_option('mode', get_option('mzqpay_mode','sandbox'));
    if ($mode === 'sandbox'){
        $client_id = $gateway->get_option('sandbox_client_id', get_option('mzqpay_sandbox_client_id','TEST_MERCHANT'));
        $client_secret = $gateway->get_option('sandbox_client_secret', get_option('mzqpay_sandbox_client_secret','123456'));
        $auth_url = 'https://merchant-sandbox.qpay.mn/v2/auth/token';
        $payment_check_url = 'https://merchant-sandbox.qpay.mn/v2/payment/check';
    } else {
        $client_id = $gateway->get_option('live_client_id', get_option('mzqpay_live_client_id',''));
        $client_secret = $gateway->get_option('live_client_secret', get_option('mzqpay_live_client_secret',''));
        $auth_url = 'https://merchant.qpay.mn/v2/auth/token';
        $payment_check_url = 'https://merchant.qpay.mn/v2/payment/check';
    }

    // get token (cached)
    $access_token = $gateway->get_cached_token($client_id, $client_secret, $auth_url);
    if (is_wp_error($access_token)) return new WP_REST_Response(array('error'=>'auth_failed'), 500);

    // build check payload (use INVOICE if invoice_id)
    $obj_type = $invoice_id ? 'INVOICE' : 'PAYMENT';
    $obj_id = $invoice_id ? $invoice_id : $payment_id;
    $check_payload = array('object_type'=>$obj_type, 'object_id'=>$obj_id, 'offset'=>array('page_number'=>1,'page_limit'=>100));

    $check = wp_remote_post($payment_check_url, array(
        'headers' => array('Authorization'=>'Bearer '.$access_token,'Content-Type'=>'application/json'),
        'body' => wp_json_encode($check_payload),
        'timeout' => 20
    ));

    if (is_wp_error($check) || wp_remote_retrieve_response_code($check) >= 400){
        return new WP_REST_Response(array('error'=>'check_failed'),500);
    }

    $check_body = json_decode(wp_remote_retrieve_body($check), true);
    // inspect check_body to determine status
    // naive approach: if any payment with status 'PAID' or similar exists, mark order complete
    $paid = false;
    if (!empty($check_body['data']) && is_array($check_body['data'])){
        foreach($check_body['data'] as $d){
            if (isset($d['status']) && in_array(strtoupper($d['status']), array('PAID','SUCCESS','COMPLETED'), true)){
                $paid = true; break;
            }
        }
    }

    if ($paid){
        $order->payment_complete();
        // Reduce stock levels for paid order
        wc_reduce_stock_levels($order->get_id());
        $order->add_order_note('qPay confirmed payment via callback.');

        // optionally create eBarimt if enabled
        $enable_ebarimt = $gateway->get_option('enable_ebarimt','no') === 'yes';
        if ($enable_ebarimt){
            // try to find a payment_id from check response
            $payment_id_found = null;
            if (!empty($check_body['data']) && is_array($check_body['data'])){
                foreach($check_body['data'] as $d){
                    if (!empty($d['payment_id'])){ $payment_id_found = $d['payment_id']; break; }
                    if (!empty($d['payments']) && is_array($d['payments'])){
                        foreach($d['payments'] as $p){ if (!empty($p['payment_id'])){ $payment_id_found = $p['payment_id']; break 2; } }
                    }
                }
            }

            if ($payment_id_found){
                $creds = $gateway->get_credentials();
                $token = $gateway->get_access_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
                if (!is_wp_error($token)){
                    $eb_payload = $gateway->build_ebarimt_payload($order, $payment_id_found);
                    $eb_res = $gateway->post_ebarimt($eb_payload, $token, $creds['ebarimt_url']);
                    if (is_wp_error($eb_res)){
                        $order->add_order_note('eBarimt creation failed: ' . $eb_res->get_error_message() . ' — queued for retry');
                        mzqpay_enqueue_task(array('type'=>'ebarimt_retry','order_id'=>$order->get_id(),'payment_id'=>$payment_id_found,'attempts'=>0));
                    } else {
                        if (is_array($eb_res) && isset($eb_res['code']) && $eb_res['code'] < 400){
                            $order->add_order_note('eBarimt created: ' . substr($eb_res['body'],0,800));
                            $order->update_meta_data('_mzqpay_ebarimt_response', $eb_res['body']);
                            $order->save();
                        } else {
                            $order->add_order_note('eBarimt creation failed: ' . (is_array($eb_res) ? $eb_res['body'] : wp_json_encode($eb_res)) . ' — queued for retry' );
                            mzqpay_enqueue_task(array('type'=>'ebarimt_retry','order_id'=>$order->get_id(),'payment_id'=>$payment_id_found,'attempts'=>0));
                        }
                    }
                } else {
                    $order->add_order_note('eBarimt skipped: auth failed');
                }
            } else {
                $order->add_order_note('eBarimt skipped: no payment_id found in check response');
            }
        }

        // Return HTTP 200 with body "SUCCESS" as per qPay callback specification
        return new WP_REST_Response('SUCCESS', 200);
    }

    // otherwise, store callback payload for later review
    $order->add_order_note('qPay callback received: ' . wp_json_encode($body));
    // Return HTTP 200 with body "SUCCESS" even for non-paid status per qPay docs
    return new WP_REST_Response('SUCCESS', 200);
}

} // End mzqpay_load_plugin()
