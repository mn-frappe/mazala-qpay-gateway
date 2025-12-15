<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MZQPay_Payment_Gateway extends WC_Payment_Gateway {
    
    /** @var WC_Logger Logger instance */
    protected $logger;
    
    /** @var string Log context source */
    protected $log_context = 'qpay-gateway';
    
    public function __construct() {
        $this->id = 'mzqpay';
        $this->method_title = 'Mazala QPay Gateway';
        $this->method_description = __('Accept payments via qPay (Mongolian payment gateway) with eBarimt integration.', 'qpay-gateway');
        $this->has_fields = false;
        $this->icon = apply_filters('mzqpay_icon', MZQPAY_PLUGIN_URL . 'assets/images/qpay-logo.png');
        
        // Declare supported features
        $this->supports = array(
            'products',
            'refunds',
            'pre-orders',
        );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title', 'qPay');
        $this->description = $this->get_option('description','Pay with qPay');
        
        // Initialize logger
        $this->init_logger();

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
    }
    
    /**
     * Initialize WooCommerce logger
     */
    protected function init_logger() {
        if (function_exists('wc_get_logger')) {
            $this->logger = wc_get_logger();
        }
    }
    
    /**
     * Log a message using WC_Logger
     * @param string $message Log message
     * @param string $level Log level: emergency|alert|critical|error|warning|notice|info|debug
     */
    protected function log($message, $level = 'info') {
        if ($this->logger && $this->get_option('enable_logging', 'no') === 'yes') {
            $this->logger->log($level, $message, array('source' => $this->log_context));
        }
    }

    /**
     * Send an email alert when a queued task permanently fails.
     * order_id may be null.
     */
    protected function send_failure_alert($order_id, $task_row, $last_error){
        $enabled = $this->get_option('enable_alerts','no') === 'yes';
        if (!$enabled) return false;
        $to = $this->get_option('alert_email', get_option('admin_email'));
        if (empty($to)) $to = get_option('admin_email');

        $subject = sprintf('QPay Gateway: queued task permanently failed (type=%s)', isset($task_row['type']) ? $task_row['type'] : 'unknown');
        $order_link = $order_id ? admin_url('post.php?post=' . intval($order_id) . '&action=edit') : '';
        $body_lines = array();
        $body_lines[] = 'A queued QPay Gateway task failed after maximum retry attempts.';
        $body_lines[] = '';
        $body_lines[] = 'Task:' . wp_json_encode($task_row);
        $body_lines[] = '';
        $body_lines[] = 'Last error: ' . $last_error;
        if ($order_link) $body_lines[] = 'Order: ' . $order_link;
        $body = implode("\n", $body_lines);

        // Use wp_mail (WP handles From header usually). Return boolean.
        return wp_mail($to, $subject, $body);
    }

    public function init_form_fields(){
        // Check if advanced mode is enabled
        $advanced_mode = $this->get_option('advanced_mode', 'no') === 'yes';
        
        // Build district options from fixtures (for advanced mode)
        $district_options = array('' => __('Select District', 'qpay-gateway'));
        if (class_exists('MZQPay_Fixtures')) {
            foreach (MZQPay_Fixtures::get_districts() as $code => $district) {
                $district_options[$code] = $district['name'] . ' (' . $code . ')';
            }
        }

        // Build bank options from fixtures (for advanced mode)
        $bank_options = array('' => __('Select Bank', 'qpay-gateway'));
        if (class_exists('MZQPay_Fixtures')) {
            foreach (MZQPay_Fixtures::get_banks() as $code => $bank) {
                $bank_options[$code] = $bank['name_en'] . ' (' . $code . ')';
            }
        }

        // =====================================================================
        // AUTOPILOT MODE - Minimal settings that work out of the box
        // =====================================================================
        $this->form_fields = array(
            // Test Connection Button (custom field type)
            'test_connection' => array(
                'title' => __('🔌 Connection Status','qpay-gateway'),
                'type' => 'test_connection',
                'description' => __('Test your qPay API connection','qpay-gateway'),
            ),
            'enabled' => array(
                'title' => __('Enable qPay','qpay-gateway'),
                'type' => 'checkbox',
                'label' => __('✓ Accept payments via qPay','qpay-gateway'),
                'default' => 'yes',
                'description' => __('Enable to start accepting qPay payments immediately in sandbox mode.','qpay-gateway'),
            ),
            'mode' => array(
                'title' => __('Environment','qpay-gateway'),
                'type' => 'select',
                'options' => array(
                    'sandbox' => '🧪 Sandbox (Testing)',
                    'production' => '🚀 Production (Live)'
                ),
                'description' => __('Start with Sandbox to test. Switch to Production when ready to accept real payments.','qpay-gateway'),
                'default' => 'sandbox'
            ),
            
            // Production credentials section - only show in production mode
            'production_section' => array(
                'title' => __('🔐 Production Credentials','qpay-gateway'),
                'type' => 'title',
                'description' => __('Enter your live qPay credentials (required for production mode).','qpay-gateway'),
            ),
            'live_client_id' => array(
                'title' => __('Client ID','qpay-gateway'),
                'type' => 'text',
                'description' => __('Your qPay merchant client ID.','qpay-gateway'),
                'default' => '',
                'placeholder' => 'Enter your qPay Client ID'
            ),
            'live_client_secret' => array(
                'title' => __('Client Secret','qpay-gateway'),
                'type' => 'password',
                'description' => __('Your qPay merchant client secret.','qpay-gateway'),
                'default' => '',
                'placeholder' => 'Enter your qPay Client Secret'
            ),
            'invoice_code' => array(
                'title' => __('Invoice Code','qpay-gateway'),
                'type' => 'text',
                'description' => __('Your qPay invoice code for production.','qpay-gateway'),
                'default' => 'TEST_INVOICE',
                'placeholder' => 'YOUR_INVOICE_CODE'
            ),
            
            // Advanced mode toggle
            'advanced_section' => array(
                'title' => __('⚙️ Settings Mode','qpay-gateway'),
                'type' => 'title',
                'description' => '',
            ),
            'advanced_mode' => array(
                'title' => __('Advanced Mode','qpay-gateway'),
                'type' => 'checkbox',
                'label' => __('Show all settings','qpay-gateway'),
                'description' => __('Enable to see eBarimt, webhook, and other advanced options.','qpay-gateway'),
                'default' => 'no'
            ),
        );
        
        // =====================================================================
        // ADVANCED MODE - All settings (when enabled)
        // =====================================================================
        if ($advanced_mode) {
            $advanced_fields = array(
                // Display Settings
                'display_section' => array(
                    'title' => __('📝 Display Settings','qpay-gateway'),
                    'type' => 'title',
                    'description' => '',
                ),
                'title' => array(
                    'title' => __('Payment Title','qpay-gateway'),
                    'type' => 'text',
                    'default' => 'qPay',
                    'description' => __('Title shown to customers at checkout.','qpay-gateway'),
                ),
                'description' => array(
                    'title' => __('Description','qpay-gateway'),
                    'type' => 'textarea',
                    'default' => 'Pay securely with qPay - QR code or bank app',
                    'description' => __('Description shown to customers at checkout.','qpay-gateway'),
                ),
                'display_language' => array(
                    'title' => __('Language','qpay-gateway'),
                    'type' => 'select',
                    'options' => array('en' => 'English', 'mn' => 'Монгол'),
                    'default' => 'en'
                ),
                
                // Sandbox credentials (hidden but kept for compatibility)
                'sandbox_section' => array(
                    'title' => __('🧪 Sandbox Credentials','qpay-gateway'),
                    'type' => 'title',
                    'description' => __('Pre-filled with test credentials. No changes needed for testing.','qpay-gateway'),
                ),
                'sandbox_client_id' => array(
                    'title' => __('Sandbox Client ID','qpay-gateway'),
                    'type' => 'text',
                    'default' => 'TEST_MERCHANT',
                    'description' => __('Default: TEST_MERCHANT','qpay-gateway'),
                ),
                'sandbox_client_secret' => array(
                    'title' => __('Sandbox Client Secret','qpay-gateway'),
                    'type' => 'password',
                    'default' => '123456',
                    'description' => __('Default: 123456','qpay-gateway'),
                ),
                
                // eBarimt Settings
                'ebarimt_section' => array(
                    'title' => __('🧾 eBarimt (Tax Receipt)','qpay-gateway'),
                    'type' => 'title',
                    'description' => __('Configure Mongolian tax receipt generation.','qpay-gateway'),
                ),
                'enable_ebarimt' => array(
                    'title' => __('Enable eBarimt','qpay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Auto-generate eBarimt after payment','qpay-gateway'),
                    'default' => 'no'
                ),
                'ebarimt_sender_register' => array(
                    'title' => __('Company Register','qpay-gateway'),
                    'type' => 'text',
                    'description' => __('Your company tax registration number.','qpay-gateway'),
                    'default' => ''
                ),
                'ebarimt_sender_name' => array(
                    'title' => __('Company Name','qpay-gateway'),
                    'type' => 'text',
                    'default' => ''
                ),
                'ebarimt_branch_code' => array(
                    'title' => __('Branch Code','qpay-gateway'),
                    'type' => 'text',
                    'default' => 'SALBAR1'
                ),
                'ebarimt_district_code' => array(
                    'title' => __('District','qpay-gateway'),
                    'type' => 'select',
                    'options' => $district_options,
                    'default' => ''
                ),
                'ebarimt_receiver_type' => array(
                    'title' => __('Default Receiver Type','qpay-gateway'),
                    'type' => 'select',
                    'options' => array('CITIZEN'=>'Individual','COMPANY'=>'Business'),
                    'default' => 'CITIZEN'
                ),
                
                // Webhook Settings
                'webhook_section' => array(
                    'title' => __('🔔 Webhook Settings','qpay-gateway'),
                    'type' => 'title',
                    /* translators: %s: callback URL */
                    'description' => sprintf(__('Callback URL: %s','qpay-gateway'), '<code>' . esc_url(home_url('/?mzqpay_callback=1')) . '</code>'),
                ),
                'webhook_secret' => array(
                    'title' => __('Webhook Secret','qpay-gateway'),
                    'type' => 'password',
                    'description' => __('Optional: For signature verification.','qpay-gateway'),
                    'default' => ''
                ),
                
                // Payment Options
                'payment_section' => array(
                    'title' => __('💳 Payment Options','qpay-gateway'),
                    'type' => 'title',
                    'description' => '',
                ),
                'allow_partial' => array(
                    'title' => __('Partial Payments','qpay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Allow partial/installment payments','qpay-gateway'),
                    'default' => 'no'
                ),
                'enable_expiry' => array(
                    'title' => __('Invoice Expiry','qpay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Invoices expire after timeout','qpay-gateway'),
                    'default' => 'no'
                ),
                
                // Alerts & Logging
                'alerts_section' => array(
                    'title' => __('📊 Monitoring','qpay-gateway'),
                    'type' => 'title',
                    'description' => '',
                ),
                'enable_logging' => array(
                    'title' => __('Debug Logging','qpay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Log to WooCommerce > Status > Logs','qpay-gateway'),
                    'default' => 'no'
                ),
                'enable_alerts' => array(
                    'title' => __('Email Alerts','qpay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Send alerts on payment failures','qpay-gateway'),
                    'default' => 'no'
                ),
                'alert_email' => array(
                    'title' => __('Alert Email','qpay-gateway'),
                    'type' => 'text',
                    'default' => get_option('admin_email')
                ),
                
                // Environment Variables (for developers)
                'env_section' => array(
                    'title' => __('🔧 Developer Options','qpay-gateway'),
                    'type' => 'title',
                    'description' => __('For advanced server configurations.','qpay-gateway'),
                ),
                'use_env' => array(
                    'title' => __('Use ENV Variables','qpay-gateway'),
                    'type' => 'checkbox',
                    'label' => __('Read credentials from environment variables','qpay-gateway'),
                    'default' => 'no'
                ),
            );
            
            $this->form_fields = array_merge($this->form_fields, $advanced_fields);
        }
    }
    
    /**
     * Generate Test Connection Button HTML
     */
    public function generate_test_connection_html($key, $data) {
        $field_key = $this->get_field_key($key);
        $mode = $this->get_option('mode', 'sandbox');
        $mode_label = $mode === 'sandbox' ? '🧪 Sandbox' : '🚀 Production';
        
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo wp_kses_post($data['title']); ?></label>
            </th>
            <td class="forminp">
                <div id="wooqpay-connection-status" style="margin-bottom: 10px; padding: 12px; border-radius: 4px; background: #f0f0f1;">
                    <span id="wooqpay-status-text">Click the button to test your connection</span>
                </div>
                <button type="button" class="button button-primary" id="wooqpay-test-connection" style="margin-right: 10px;">
                    🔌 Test Connection
                </button>
                <span style="color: #666; font-size: 12px;">
                    Mode: <strong><?php echo esc_html($mode_label); ?></strong>
                </span>
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#wooqpay-test-connection').on('click', function() {
                        var $btn = $(this);
                        var $status = $('#wooqpay-connection-status');
                        var $text = $('#wooqpay-status-text');
                        
                        $btn.prop('disabled', true).text('⏳ Testing...');
                        $status.css('background', '#f0f0f1');
                        $text.text('Connecting to qPay API...');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'mzqpay_test_connection',
                                nonce: '<?php echo esc_attr(wp_create_nonce('mzqpay_test_connection')); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $status.css('background', '#d4edda');
                                    $text.html('✅ <strong>Connected!</strong> ' + response.data.message);
                                } else {
                                    $status.css('background', '#f8d7da');
                                    $text.html('❌ <strong>Failed:</strong> ' + response.data.message);
                                }
                            },
                            error: function() {
                                $status.css('background', '#f8d7da');
                                $text.html('❌ <strong>Error:</strong> Could not reach server');
                            },
                            complete: function() {
                                $btn.prop('disabled', false).text('🔌 Test Connection');
                            }
                        });
                    });
                });
                </script>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Retrieve credentials and API endpoints based on mode and settings.
     */
    /**
     * Hardcoded sandbox credentials for immediate testing
     */
    const SANDBOX_URL = 'https://merchant-sandbox.qpay.mn';
    const SANDBOX_USERNAME = 'TEST_MERCHANT';
    const SANDBOX_PASSWORD = '123456';
    const SANDBOX_INVOICE_CODE = 'TEST_INVOICE';
    
    public function get_credentials(){
        $mode = $this->get_option('mode', 'sandbox');
        $use_env = $this->get_option('use_env','no') === 'yes';
        $out = array();
        
        if ($mode === 'sandbox'){
            // =====================================================
            // SANDBOX MODE - ALWAYS use hardcoded test credentials
            // These cannot be overridden - ensures testing works
            // =====================================================
            $out['client_id'] = self::SANDBOX_USERNAME;      // TEST_MERCHANT
            $out['client_secret'] = self::SANDBOX_PASSWORD;  // 123456
            $out['invoice_code'] = self::SANDBOX_INVOICE_CODE; // TEST_INVOICE
            $out['auth_url'] = self::SANDBOX_URL . '/v2/auth/token';
            $out['invoice_url'] = self::SANDBOX_URL . '/v2/invoice';
            $out['payment_check_url'] = self::SANDBOX_URL . '/v2/payment/check';
            $out['ebarimt_url'] = self::SANDBOX_URL . '/v2/ebarimt_v3/create';
            $out['base_url'] = self::SANDBOX_URL;
        } else {
            // =====================================================
            // PRODUCTION MODE - Use configured credentials
            // =====================================================
            if ($use_env){
                $id_var = $this->get_option('env_client_id_var','WOOQPAY_CLIENT_ID');
                $sec_var = $this->get_option('env_client_secret_var','WOOQPAY_CLIENT_SECRET');
                $out['client_id'] = getenv($id_var) ?: '';
                $secret_raw = getenv($sec_var) ?: '';
                $out['client_secret'] = $this->decrypt_secret($secret_raw);
            } else {
                $out['client_id'] = $this->get_option('live_client_id', '');
                $secret_raw = $this->get_option('live_client_secret', '');
                $out['client_secret'] = $this->decrypt_secret($secret_raw);
            }
            $out['invoice_code'] = $this->get_option('invoice_code', '');
            $out['auth_url'] = 'https://merchant.qpay.mn/v2/auth/token';
            $out['invoice_url'] = 'https://merchant.qpay.mn/v2/invoice';
            $out['payment_check_url'] = 'https://merchant.qpay.mn/v2/payment/check';
            $out['ebarimt_url'] = 'https://merchant.qpay.mn/v2/ebarimt/create';
            $out['base_url'] = 'https://merchant.qpay.mn';
        }
        return $out;
    }

    /**
     * Request access token via Basic auth. Returns token string or WP_Error.
     */
    public function get_access_token($client_id, $client_secret, $auth_url){
        // Low level call without caching; kept for backward compatibility
        $auth = base64_encode($client_id . ':' . $client_secret);
        $resp = wp_remote_post($auth_url, array(
            'headers' => array('Authorization' => 'Basic ' . $auth),
            'timeout' => 20,
        ));
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code >= 400) return new WP_Error('auth_failed','Auth request failed', array('code'=>$code,'body'=>wp_remote_retrieve_body($resp)));
        $body_raw = wp_remote_retrieve_body($resp);
        $body = json_decode($body_raw, true);
        $token = isset($body['access_token']) ? $body['access_token'] : (isset($body['token']) ? $body['token'] : null);
        if (!$token) return new WP_Error('no_token','No access token in response', $body);

        // Cache token and refresh_token if expires_in present
        if (isset($body['expires_in']) && is_numeric($body['expires_in'])){
            $ttl = intval($body['expires_in']) - 30;
            if ($ttl < 60) $ttl = 60;
            $cache_key = 'mzqpay_token_' . md5($client_id . '|' . $auth_url);
            $store = array('token'=>$token,'cached_at'=>time(),'expires_in'=>intval($body['expires_in']));
            if (isset($body['refresh_token'])) {
                $store['refresh_token'] = $body['refresh_token'];
                // persist refresh token into option for persistence across processes
                update_option('mzqpay_refresh_token_' . md5($client_id), $body['refresh_token']);
            } else {
                // try to load persisted refresh token if exists
                $rt = get_option('mzqpay_refresh_token_' . md5($client_id), '');
                if (!empty($rt)) $store['refresh_token'] = $rt;
            }
            set_transient($cache_key, $store, $ttl);
        }

        return $token;
    }

    /**
     * Get cached token or fetch and cache a new one.
     */
    public function get_cached_token($client_id, $client_secret, $auth_url){
        $cache_key = 'mzqpay_token_' . md5($client_id . '|' . $auth_url);
        $cached = get_transient($cache_key);

        $opt_prefix = 'mzqpay_refresh_token_' . md5($client_id);
        $backoff_opt = 'mzqpay_refresh_next_attempt_' . md5($client_id);
        $attempts_opt = 'mzqpay_refresh_attempts_' . md5($client_id);

        // If we have a valid, unexpired cached token, return it
        if (!empty($cached) && !empty($cached['token'])){
            if (!empty($cached['expires_in']) && !empty($cached['cached_at'])){
                $expires_at = intval($cached['cached_at']) + intval($cached['expires_in']);
                if (time() < $expires_at - 15){
                    return $cached['token'];
                }
            } else {
                return $cached['token'];
            }
        }

        // Determine refresh token: prefer transient's refresh_token, otherwise persisted option
        $refresh_token = '';
        if (!empty($cached) && !empty($cached['refresh_token'])){
            $refresh_token = $cached['refresh_token'];
        } else {
            $refresh_token = get_option($opt_prefix, '');
        }

        // If we have a refresh token, attempt refresh unless backoff is in effect
        if (!empty($refresh_token)){
            $now = time();
            $next_allowed = intval(get_option($backoff_opt, 0));
            if ($next_allowed > $now){
                // still in backoff window; fallback to client credentials
                return $this->get_access_token($client_id, $client_secret, $auth_url);
            }

            $refresh_url = str_replace('/token','/refresh',$auth_url);
            $resp = wp_remote_post($refresh_url, array('headers'=>array('Authorization'=>'Bearer '.$refresh_token),'timeout'=>15));
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) < 400){
                $body = json_decode(wp_remote_retrieve_body($resp), true);
                $new_token = isset($body['access_token']) ? $body['access_token'] : (isset($body['token']) ? $body['token'] : null);
                if ($new_token){
                    $ttl = isset($body['expires_in']) ? intval($body['expires_in']) - 30 : 300;
                    if ($ttl < 60) $ttl = 60;
                    $store = array('token'=>$new_token,'cached_at'=>time(),'expires_in'=>isset($body['expires_in'])?intval($body['expires_in']):$ttl);
                    if (isset($body['refresh_token']) && !empty($body['refresh_token'])){
                        $store['refresh_token'] = $body['refresh_token'];
                        update_option($opt_prefix, $body['refresh_token']);
                    } else {
                        // preserve existing persisted refresh token when refresh response doesn't include one
                        $existing_rt = get_option($opt_prefix, '');
                        if (!empty($existing_rt)) $store['refresh_token'] = $existing_rt;
                    }
                    set_transient($cache_key, $store, $ttl);
                    // reset backoff attempts
                    delete_option($backoff_opt);
                    delete_option($attempts_opt);
                    return $new_token;
                }
            }

            // Refresh failed: increment attempts and set exponential backoff
            $attempts = intval(get_option($attempts_opt, 0)) + 1;
            update_option($attempts_opt, $attempts);
            $delay = pow(2, min(8, $attempts)); // cap exponent
            $delay_seconds = min(3600, $delay * 60); // cap 1 hour
            update_option($backoff_opt, time() + $delay_seconds);
            // fallback to client credentials flow
            return $this->get_access_token($client_id, $client_secret, $auth_url);
        }

        // No refresh token available: fallback to fresh auth using client creds
        $t = $this->get_access_token($client_id, $client_secret, $auth_url);
        return $t;
    }

    /**
     * Encrypt a secret using WOOQPAY_SECRET_KEY environment variable or option `mzqpay_secret_key`.
     */
    protected function encrypt_secret($plaintext){
        $key = getenv('WOOQPAY_SECRET_KEY') ?: get_option('mzqpay_secret_key','');
        if (empty($key) || !function_exists('openssl_encrypt')) return $plaintext;
        $method = 'AES-256-CBC';
        $ivlen = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $cipher = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) return $plaintext;
        return 'enc:' . base64_encode($iv . $cipher);
    }

    /**
     * Decrypt secret previously encrypted with encrypt_secret.
     */
    protected function decrypt_secret($blob){
        if (!is_string($blob)) return $blob;
        if (strpos($blob,'enc:') !== 0) return $blob;
        $key = getenv('WOOQPAY_SECRET_KEY') ?: get_option('mzqpay_secret_key','');
        if (empty($key) || !function_exists('openssl_decrypt')) return substr($blob,4);
        $data = base64_decode(substr($blob,4));
        $method = 'AES-256-CBC';
        $ivlen = openssl_cipher_iv_length($method);
        $iv = substr($data,0,$ivlen);
        $cipher = substr($data,$ivlen);
        $plain = openssl_decrypt($cipher, $method, $key, OPENSSL_RAW_DATA, $iv);
        if ($plain === false) return substr($blob,4);
        return $plain;
    }

    /**
     * Queue helpers: push a task onto the outbound queue.
     */
    public function enqueue_task($task){
        global $wpdb;
        $table = $wpdb->prefix . 'mzqpay_queue';
        $now = current_time('mysql');
        $payload = isset($task['payload']) ? maybe_serialize($task['payload']) : null;
        $payment_id = isset($task['payment_id']) ? sanitize_text_field($task['payment_id']) : null;
        $order_id = isset($task['order_id']) ? intval($task['order_id']) : null;
        $attempts = isset($task['attempts']) ? intval($task['attempts']) : 0;
        $next_run = isset($task['next_run']) ? $task['next_run'] : $now;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert
        $wpdb->insert($table, array('type'=>substr($task['type'],0,64),'order_id'=>$order_id,'payment_id'=>$payment_id,'payload'=>$payload,'attempts'=>$attempts,'next_run'=>$next_run,'created_at'=>$now,'updated_at'=>$now), array('%s','%d','%s','%s','%d','%s','%s','%s'));
        $id = intval($wpdb->insert_id);
        if ($id <= 0){
            $err = isset($wpdb->last_error) ? $wpdb->last_error : 'unknown';
            $q = isset($wpdb->last_query) ? $wpdb->last_query : '';
            $log = gmdate('c') . " enqueue failed: id={$id} error=" . $err . " query=" . $q . "\n";
            @file_put_contents('/tmp/mzqpay_enqueue_debug.log', $log, FILE_APPEND);
            // also write payload snapshot for diagnostics
            @file_put_contents('/tmp/mzqpay_enqueue_payload.json', json_encode($task), FILE_APPEND);
        }
        return $id;
    }

    /**
     * Process queued tasks (called from WP-Cron worker)
     */
    public function process_queue_tasks($limit = 20){
        global $wpdb;
        $table = $wpdb->prefix . 'mzqpay_queue';
        $now = current_time('mysql');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from wpdb prefix is safe
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE (next_run IS NULL OR next_run <= %s) ORDER BY next_run ASC, id ASC LIMIT %d", $now, $limit), ARRAY_A);
        if (empty($rows)) return;
        foreach($rows as $row){
            $ok = false;
            $task = array('type'=>$row['type'],'order_id'=>$row['order_id'],'payment_id'=>$row['payment_id'],'payload'=>maybe_unserialize($row['payload']),'attempts'=>intval($row['attempts']));
            try {
                switch($row['type']){
                    case 'ebarimt_retry':
                        $order = wc_get_order($row['order_id']);
                        if (!$order){ $ok = true; break; }
                        $creds = $this->get_credentials();
                        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
                        if (is_wp_error($token)) { $ok = false; $last_error = 'auth_error'; break; }
                        $payload = $this->build_ebarimt_payload($order, $row['payment_id']);
                        $res = $this->post_ebarimt($payload, $token, $creds['ebarimt_url']);
                        if (!is_wp_error($res) && is_array($res) && isset($res['code']) && $res['code'] < 400){
                            update_post_meta($order->get_id(), '_mzqpay_ebarimt_response', $res['body']);
                            $order->add_order_note('eBarimt queued task succeeded.');
                            $ok = true;
                        } else {
                            $ok = false; $last_error = is_wp_error($res) ? $res->get_error_message() : (is_array($res) ? $res['body'] : 'unknown');
                        }
                        break;
                    case 'invoice_create_retry':
                        $order = wc_get_order($row['order_id']);
                        if (!$order){ $ok = true; break; }
                        $creds = $this->get_credentials();
                        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
                        if (is_wp_error($token)) { $ok = false; $last_error = 'auth_error'; break; }
                        $invoice_url = isset($creds['invoice_url']) ? $creds['invoice_url'] : '';
                        $payload = maybe_unserialize($row['payload']);
                        $inv = wp_remote_post($invoice_url, array('headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload),'timeout'=>30));
                        if (!is_wp_error($inv) && wp_remote_retrieve_response_code($inv) < 400){
                            $inv_body = json_decode(wp_remote_retrieve_body($inv), true);
                            if (isset($inv_body['invoice_id'])) $order->update_meta_data('_mzqpay_invoice_id', $inv_body['invoice_id']);
                            $order->update_meta_data('_mzqpay_invoice_response', wp_json_encode($inv_body));
                            $order->save();
                            $order->add_order_note('Queued invoice creation succeeded.');
                            $ok = true;
                        } else {
                            $ok = false; $last_error = is_wp_error($inv) ? $inv->get_error_message() : wp_remote_retrieve_body($inv);
                        }
                        break;
                    case 'refund_retry':
                        $payload = maybe_unserialize($row['payload']);
                        $refund_row_id = isset($payload['refund_row_id']) ? intval($payload['refund_row_id']) : null;
                        if (!$refund_row_id){ $ok = true; break; }
                        $res = $this->perform_refund_attempt($refund_row_id);
                        if (is_wp_error($res)){
                            $ok = false; $last_error = $res->get_error_message();
                        } else {
                            // try to attach an order note if order exists
                            $order = wc_get_order($row['order_id']);
                            if ($order && method_exists($order, 'add_order_note')){
                                $order->add_order_note('Queued refund succeeded.');
                            }
                            $ok = true;
                        }
                        break;
                    default:
                        $ok = true;
                }
            } catch (Exception $e){
                $ok = false; $last_error = $e->getMessage();
            }
            if ($ok){
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table delete
                $wpdb->delete($table, array('id'=>intval($row['id'])), array('%d'));
            } else {
                // increment attempts and set next_run with exponential backoff
                $attempts = intval($row['attempts']) + 1;
                $max = 6;
                if ($attempts >= $max){
                    // give up, record and remove
                    if (!empty($row['order_id'])){ 
                        $o = wc_get_order($row['order_id']); 
                        if ($o) {
                            $o->add_order_note('qPay queued task failed after retries: ' . esc_html($last_error));
                        }
                    }
                    // send alert if enabled
                    try {
                        $this->send_failure_alert(isset($row['order_id']) ? intval($row['order_id']) : null, $row, $last_error);
                    } catch (Exception $e) {
                        // don't block removal on alert errors
                    }
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table delete
                    $wpdb->delete($table, array('id'=>intval($row['id'])), array('%d'));
                } else {
                    // backoff: 2^attempts minutes
                    $delay = pow(2, $attempts); // minutes
                    $next_ts = strtotime($now) + ($delay * 60);
                    $next_run = gmdate('Y-m-d H:i:s', $next_ts);
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
                    $wpdb->update($table, array('attempts'=>$attempts,'last_error'=>substr($last_error,0,65535),'next_run'=>$next_run,'updated_at'=>$now), array('id'=>intval($row['id'])), array('%d','%s','%s','%s'), array('%d'));
                }
            }
        }
    }

    /**
     * Cancel invoice via API
     */
    public function cancel_invoice($order){
        $inv_id = $order->get_meta('_mzqpay_invoice_id');
        if (!$inv_id) return new WP_Error('no_invoice','No invoice id');
        $creds = $this->get_credentials();
        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($token)) return $token;
        $url = rtrim($creds['invoice_url'],'/') . '/' . rawurlencode($inv_id);
        $resp = wp_remote_request($url, array('method'=>'DELETE','headers'=>array('Authorization'=>'Bearer '.$token),'timeout'=>20));
        if (is_wp_error($resp)) return $resp;
        if (wp_remote_retrieve_response_code($resp) >= 400) return new WP_Error('cancel_failed','Cancel failed',wp_remote_retrieve_body($resp));
        $order->add_order_note('Invoice cancelled via qPay.');
        $this->log('Invoice cancelled for order #' . $order->get_id(), 'info');
        return true;
    }

    /**
     * Get payment details via API (GET /v2/payment/:payment_id)
     * @param string $payment_id Payment ID
     * @return array|WP_Error Payment data or error
     */
    public function get_payment($payment_id) {
        if (empty($payment_id)) {
            return new WP_Error('no_payment_id', __('Payment ID is required', 'qpay-gateway'));
        }
        
        $creds = $this->get_credentials();
        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($token)) return $token;
        
        $mode = $this->get_option('mode', 'sandbox');
        $base_url = ($mode === 'sandbox') 
            ? 'https://merchant-sandbox.qpay.mn/v2/payment/' 
            : 'https://merchant.qpay.mn/v2/payment/';
        
        $url = $base_url . rawurlencode($payment_id);
        
        $resp = wp_remote_get($url, array(
            'headers' => array('Authorization' => 'Bearer ' . $token),
            'timeout' => 20,
        ));
        
        if (is_wp_error($resp)) return $resp;
        
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        
        if ($code >= 400) {
            $error_msg = $this->translate_api_error($body);
            return new WP_Error('payment_get_failed', $error_msg, array('code' => $code, 'body' => $body));
        }
        
        $data = json_decode($body, true);
        $this->log('Payment details retrieved for: ' . $payment_id, 'info');
        return $data;
    }

    /**
     * List payments via API (POST /v2/payment/list)
     * @param array $args Filter arguments: object_type, object_id, start_date, end_date, page_number, page_limit
     * @return array|WP_Error Payment list or error
     */
    public function list_payments($args = array()) {
        $creds = $this->get_credentials();
        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($token)) return $token;
        
        $mode = $this->get_option('mode', 'sandbox');
        $url = ($mode === 'sandbox') 
            ? 'https://merchant-sandbox.qpay.mn/v2/payment/list' 
            : 'https://merchant.qpay.mn/v2/payment/list';
        
        // Build payload per qPay API spec
        $payload = array(
            'object_type' => isset($args['object_type']) ? $args['object_type'] : 'INVOICE',
            'object_id' => isset($args['object_id']) ? $args['object_id'] : '',
            'offset' => array(
                'page_number' => isset($args['page_number']) ? intval($args['page_number']) : 1,
                'page_limit' => isset($args['page_limit']) ? intval($args['page_limit']) : 100,
            ),
        );
        
        if (!empty($args['start_date'])) {
            $payload['start_date'] = $args['start_date'];
        }
        if (!empty($args['end_date'])) {
            $payload['end_date'] = $args['end_date'];
        }
        
        $resp = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ));
        
        if (is_wp_error($resp)) return $resp;
        
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        
        if ($code >= 400) {
            $error_msg = $this->translate_api_error($body);
            return new WP_Error('payment_list_failed', $error_msg, array('code' => $code, 'body' => $body));
        }
        
        $data = json_decode($body, true);
        $this->log('Payment list retrieved: ' . count(isset($data['data']) ? $data['data'] : array()) . ' results', 'info');
        return $data;
    }

    /**
     * Cancel a card payment via API (DELETE /v2/payment/cancel/:payment_id)
     * Note: Only card transactions can be cancelled per qPay docs
     * @param string $payment_id Payment ID
     * @param string $callback_url Callback URL for cancellation result
     * @param string $note Optional note
     * @return array|WP_Error Result or error
     */
    public function cancel_payment($payment_id, $callback_url = '', $note = '') {
        if (empty($payment_id)) {
            return new WP_Error('no_payment_id', __('Payment ID is required', 'qpay-gateway'));
        }
        
        $creds = $this->get_credentials();
        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($token)) return $token;
        
        $mode = $this->get_option('mode', 'sandbox');
        $url = ($mode === 'sandbox') 
            ? 'https://merchant-sandbox.qpay.mn/v2/payment/cancel/' . rawurlencode($payment_id)
            : 'https://merchant.qpay.mn/v2/payment/cancel/' . rawurlencode($payment_id);
        
        // Build body per qPay docs
        $body = array();
        if (!empty($callback_url)) {
            $body['callback_url'] = $callback_url;
        }
        if (!empty($note)) {
            $body['note'] = $note;
        }
        
        $resp = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($body),
            'timeout' => 20,
        ));
        
        if (is_wp_error($resp)) return $resp;
        
        $code = wp_remote_retrieve_response_code($resp);
        $response_body = wp_remote_retrieve_body($resp);
        
        if ($code >= 400) {
            $error_msg = $this->translate_api_error($response_body);
            return new WP_Error('payment_cancel_failed', $error_msg, array('code' => $code, 'body' => $response_body));
        }
        
        $this->log('Payment cancelled: ' . $payment_id, 'info');
        return json_decode($response_body, true);
    }

    /**
     * Cancel an eBarimt receipt via API (DELETE /v2/ebarimt_v3/:ebarimt_id)
     * @param string $ebarimt_id eBarimt ID to cancel
     * @return array|WP_Error Result or error
     */
    public function cancel_ebarimt($ebarimt_id) {
        if (empty($ebarimt_id)) {
            return new WP_Error('no_ebarimt_id', __('eBarimt ID is required', 'qpay-gateway'));
        }
        
        $creds = $this->get_credentials();
        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($token)) return $token;
        
        $mode = $this->get_option('mode', 'sandbox');
        $url = ($mode === 'sandbox') 
            ? 'https://merchant-sandbox.qpay.mn/v2/ebarimt_v3/' . rawurlencode($ebarimt_id)
            : 'https://merchant.qpay.mn/v2/ebarimt_v3/' . rawurlencode($ebarimt_id);
        
        $resp = wp_remote_request($url, array(
            'method' => 'DELETE',
            'headers' => array('Authorization' => 'Bearer ' . $token),
            'timeout' => 20,
        ));
        
        if (is_wp_error($resp)) return $resp;
        
        $code = wp_remote_retrieve_response_code($resp);
        $response_body = wp_remote_retrieve_body($resp);
        
        if ($code >= 400) {
            $error_msg = $this->translate_api_error($response_body);
            return new WP_Error('ebarimt_cancel_failed', $error_msg, array('code' => $code, 'body' => $response_body));
        }
        
        $this->log('eBarimt cancelled: ' . $ebarimt_id, 'info');
        return json_decode($response_body, true);
    }

    /**
     * Process a refund via WooCommerce standard refund interface.
     * This is the standard WooCommerce method that integrates with the admin refund UI.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount (null for full refund).
     * @param string $reason   Refund reason.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->log('Refund failed: Order not found - #' . $order_id, 'error');
            return new WP_Error('invalid_order', __('Order not found.', 'qpay-gateway'));
        }
        
        $payment_id = $order->get_meta('_mzqpay_payment_id');
        if (empty($payment_id)) {
            $this->log('Refund failed: No payment ID for order #' . $order_id, 'error');
            return new WP_Error('no_payment_id', __('No qPay payment ID found for this order.', 'qpay-gateway'));
        }
        
        // Log the refund attempt
        $this->log(sprintf('Processing refund for order #%d, amount: %s, reason: %s', $order_id, $amount, $reason), 'info');
        
        // For partial refunds, qPay may not support them - check and handle
        $order_total = floatval($order->get_total());
        $refund_amount = $amount !== null ? floatval($amount) : $order_total;
        
        if ($refund_amount < $order_total) {
            // Partial refund - check if supported
            $this->log('Partial refund requested: ' . $refund_amount . ' of ' . $order_total, 'info');
        }
        
        // Use the existing refund_payment method which handles idempotency and retries
        $result = $this->refund_payment($order);
        
        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            $error_message = $result->get_error_message();
            
            // If it's pending/scheduled for retry, that's still acceptable for WC
            if ($error_code === 'refund_pending') {
                $order->add_order_note(
                    /* translators: 1: refund amount, 2: refund reason */
                    sprintf(__('qPay refund queued for processing. Amount: %1$s. Reason: %2$s', 'qpay-gateway'), 
                        wc_price($refund_amount, array('currency' => $order->get_currency())),
                        $reason
                    )
                );
                $this->log('Refund queued for retry - order #' . $order_id, 'warning');
                // Return true since it's queued and will be processed
                return true;
            }
            
            $this->log('Refund failed for order #' . $order_id . ': ' . $error_message, 'error');
            return $result;
        }
        
        // Success
        $order->add_order_note(
            /* translators: 1: refund amount, 2: refund reason */
            sprintf(__('qPay refund processed successfully. Amount: %1$s. Reason: %2$s', 'qpay-gateway'),
                wc_price($refund_amount, array('currency' => $order->get_currency())),
                $reason
            )
        );
        
        $this->log('Refund successful for order #' . $order_id, 'info');
        return true;
    }

    /**
     * Refund payment via API (best-effort) - internal method
     */
    public function refund_payment($order){
        global $wpdb;
        $payment_id = $order->get_meta('_mzqpay_payment_id');
        if (!$payment_id) return new WP_Error('no_payment','No payment id');

        // generate or reuse an idempotency key per refund
        $id_key = 'refund_' . $order->get_id() . '_' . preg_replace('/[^a-z0-9]/i','', $payment_id);

        $refunds_table = $wpdb->prefix . 'mzqpay_refunds';
        // check existing refund record
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from wpdb prefix is safe
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$refunds_table} WHERE idempotency_key = %s LIMIT 1", $id_key), ARRAY_A);
        if (!$row){
            $now = current_time('mysql');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table insert
            $wpdb->insert($refunds_table, array('order_id'=>$order->get_id(),'payment_id'=>$payment_id,'idempotency_key'=>$id_key,'status'=>'pending','attempts'=>0,'created_at'=>$now,'updated_at'=>$now), array('%d','%s','%s','%s','%d','%s','%s'));
            $row_id = intval($wpdb->insert_id);
        } else {
            $row_id = intval($row['id']);
        }

        $creds = $this->get_credentials();
        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($token)) return $token;
        $url = rtrim($creds['payment_check_url'],'/');
        $refund_url = str_replace('/check','/refund',$url);

        // send idempotency header
        $headers = array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Idempotency-Key'=>$id_key);
        $body = wp_json_encode(array('payment_id'=>$payment_id));
        $resp = wp_remote_request($refund_url, array('method'=>'DELETE','headers'=>$headers,'body'=>$body,'timeout'=>20));
        if (is_wp_error($resp)){
            // schedule retry via queue
            $prev_attempts = isset($row['attempts']) ? intval($row['attempts']) : 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
            $wpdb->update($refunds_table, array('attempts'=>$prev_attempts + 1,'last_error'=>$resp->get_error_message(),'updated_at'=>current_time('mysql')), array('id'=>$row_id), array('%d','%s','%s'), array('%d'));
            mzqpay_enqueue_task(array('type'=>'refund_retry','order_id'=>$order->get_id(),'payment_id'=>$payment_id,'payload'=>array('refund_row_id'=>$row_id),'attempts'=>0));
            return new WP_Error('refund_pending','Refund scheduled for retry');
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code >= 400){
            $prev_attempts = isset($row['attempts']) ? intval($row['attempts']) : 0;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
            $wpdb->update($refunds_table, array('attempts'=>$prev_attempts + 1,'last_error'=>substr($body,0,65535),'response'=>$body,'updated_at'=>current_time('mysql')), array('id'=>$row_id));
            mzqpay_enqueue_task(array('type'=>'refund_retry','order_id'=>$order->get_id(),'payment_id'=>$payment_id,'payload'=>array('refund_row_id'=>$row_id),'attempts'=>0));
            return new WP_Error('refund_failed','Refund failed: scheduled for retry', $body);
        }

        // success
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
        $wpdb->update($refunds_table, array('status'=>'succeeded','refund_id'=>substr($body,0,128),'response'=>$body,'updated_at'=>current_time('mysql')), array('id'=>$row_id));
        $order->add_order_note('Payment refunded via qPay.');
        return true;
    }

    /**
     * Attempt a refund for a persisted refund row id. Returns true on success or WP_Error on failure.
     */
    public function perform_refund_attempt($refund_row_id){
        global $wpdb;
        $refunds_table = $wpdb->prefix . 'mzqpay_refunds';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from wpdb prefix is safe
        $rrow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$refunds_table} WHERE id = %d", intval($refund_row_id)), ARRAY_A);
        if (!$rrow) return new WP_Error('not_found','Refund row not found');
        $creds = $this->get_credentials();
        $token = $this->get_cached_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($token)) return $token;
        $payment_id = $rrow['payment_id'];
        $url = rtrim($creds['payment_check_url'],'/');
        $refund_url = str_replace('/check','/refund',$url);
        $headers = array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Idempotency-Key'=>$rrow['idempotency_key']);
        $resp = wp_remote_request($refund_url, array('method'=>'DELETE','headers'=>$headers,'body'=>wp_json_encode(array('payment_id'=>$payment_id)),'timeout'=>20));
        if (is_wp_error($resp)){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
            $wpdb->update($refunds_table, array('attempts'=>intval($rrow['attempts']) + 1,'last_error'=>$resp->get_error_message(),'updated_at'=>current_time('mysql')), array('id'=>intval($rrow['id'])));
            return new WP_Error('request_failed', $resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code >= 400){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
            $wpdb->update($refunds_table, array('attempts'=>intval($rrow['attempts']) + 1,'last_error'=>substr($body,0,65535),'response'=>$body,'updated_at'=>current_time('mysql')), array('id'=>intval($rrow['id'])));
            return new WP_Error('refund_failed','Refund failed', $body);
        }
        // success
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table update
        $wpdb->update($refunds_table, array('status'=>'succeeded','refund_id'=>substr($body,0,128),'response'=>$body,'updated_at'=>current_time('mysql')), array('id'=>intval($rrow['id'])));
        return true;
    }

    /**
     * Load parsed QPay docs (from XLSX parsed JSON) if available.
     * Caches result in $this->parsed_docs
     */
    protected function load_parsed_docs(){
        if (isset($this->parsed_docs)) return $this->parsed_docs;
        $path = WP_PLUGIN_DIR . '/wooqpay/../docs/QPayAPIv2_parsed.json';
        if (!file_exists($path)){
            // try alternate path
            $path = WP_PLUGIN_DIR . '/docs/QPayAPIv2_parsed.json';
        }
        if (!file_exists($path)){
            $this->parsed_docs = null;
            return null;
        }
        $raw = file_get_contents($path);
        $json = json_decode($raw, true);
        $this->parsed_docs = $json;

        // Build GS1 lookup indexes for faster matching
        $this->gs1_index_by_code = array();
        $this->gs1_index_by_name = array();
        $this->gs1_index_tokens = array();
        if (!empty($json['GS1']) && is_array($json['GS1'])){
            $rows = $json['GS1'];
            foreach($rows as $idx => $row){
                if ($idx === 0) continue; // header
                if (!is_array($row)) continue;
                $code = isset($row[0]) ? trim(strval($row[0])) : '';
                $desc = isset($row[1]) ? trim(strval($row[1])) : '';
                if ($code !== ''){
                    $this->gs1_index_by_code[$code] = array('code'=>$code,'name'=>$desc);
                }
                if ($desc !== ''){
                    $ln = $this->normalize_string($desc);
                    $this->gs1_index_by_name[$ln] = array('code'=>$code,'name'=>$desc);
                    // token index
                    $tokens = preg_split('/\s+/', $ln);
                    foreach($tokens as $t){
                        if (strlen($t) < 3) continue;
                        if (!isset($this->gs1_index_tokens[$t])) $this->gs1_index_tokens[$t] = array();
                        $this->gs1_index_tokens[$t][] = array('code'=>$code,'name'=>$desc);
                    }
                }
            }
        }

        return $this->parsed_docs;
    }

    /**
     * Normalize strings for matching: lowercase, trim, remove punctuation
     */
    protected function normalize_string($s){
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}\s]+/u',' ', $s); // remove punctuation
        $s = preg_replace('/\s+/',' ', $s);
        return $s;
    }

    /**
     * Map a product SKU or name to a GS1/classification entry.
     * Uses MZQPay_Fixtures for centralized data access.
     * Returns associative array with at least 'code' and 'name' or null.
     */
    protected function map_product_to_gs1($sku, $name){
        if (!class_exists('MZQPay_Fixtures')) {
            return null;
        }
        
        $result = MZQPay_Fixtures::find_gs1_code($sku, $name);
        return $result;
    }

    /**
     * Get the VAT type for a product based on its VAT code meta or default.
     * Returns: 1=VAT taxable (10%), 2=VAT free (0%), 3=VAT exempt
     */
    protected function get_product_vat_type($product) {
        if (!$product) {
            return 1; // Default taxable
        }
        
        $vat_code = $product->get_meta('_mzqpay_vat_code');
        if (empty($vat_code)) {
            return 1; // Default taxable
        }
        
        if (class_exists('MZQPay_Fixtures')) {
            return MZQPay_Fixtures::get_vat_type($vat_code);
        }
        
        return 1;
    }

    /**
     * Translate a qPay API error to human-readable message.
     */
    protected function translate_api_error($response) {
        $lang = $this->get_option('display_language', 'en');
        
        if (class_exists('MZQPay_Fixtures')) {
            return MZQPay_Fixtures::translate_error($response, $lang);
        }
        
        return is_string($response) ? $response : json_encode($response);
    }

    /**
     * Build invoice lines array for qPay from a WC_Order instance.
     * Includes tax_type per line based on product VAT settings.
     */
    protected function build_invoice_lines($order){
        $lines = array();
        $i = 1;
        foreach($order->get_items() as $item_id => $item){
            $product = $item->get_product();
            $qty = floatval($item->get_quantity());
            $unit_price = floatval($order->get_item_subtotal($item, false));
            if ($unit_price <= 0) $unit_price = floatval($item->get_total()) / max(1,$qty);
            $amount = round($unit_price * $qty, 2);
            $sku = $product ? $product->get_sku() : '';
            $pname = $item->get_name();
            
            // Check product meta override first
            $classification = null;
            $vat_type = 1; // Default: VAT taxable
            $vat_code = '';
            
            if ($product){
                $override = $product->get_meta('_mzqpay_gs1_code');
                $disable = $product->get_meta('_mzqpay_gs1_disable_map');
                $vat_code = $product->get_meta('_mzqpay_vat_code');
                
                if (!empty($override)){
                    $classification = strval($override);
                } elseif ($disable === 'yes'){
                    $classification = null;
                } else {
                    $map = $this->map_product_to_gs1($sku, $pname);
                    $classification = $map ? $map['code'] : null;
                }
                
                // Get VAT type from product meta
                $vat_type = $this->get_product_vat_type($product);
            } else {
                $map = $this->map_product_to_gs1($sku, $pname);
                $classification = $map ? $map['code'] : null;
            }
            
            // Calculate VAT based on tax_type
            // tax_type: 1=VAT taxable (10%), 2=VAT free (0%), 3=VAT exempt
            $vat_rate = ($vat_type === 1) ? 0.10 : 0.0;
            $vat_amount = round($amount * $vat_rate, 2);
            
            $line = array(
                'line_no' => $i,
                'description' => $pname,
                'quantity' => $qty,
                'unit_price' => $unit_price,
                'amount' => $amount,
                'tax_type' => $vat_type,
                'tax_product_code' => !empty($vat_code) ? $vat_code : null,
                'vat' => $vat_amount,
            );
            
            if ($classification) $line['classification_code'] = $classification;
            if ($sku) $line['sku'] = $sku;
            
            // Calculate VAT based on whether it's included in price
            if ($vat_type === 1) {
                $line['calculate_vat'] = true;
            } else {
                $line['calculate_vat'] = false;
            }
            
            $lines[] = $line;
            $i++;
        }
        
        // Add shipping as a line if present (shipping is typically VAT taxable)
        $ship_total = floatval($order->get_shipping_total());
        if ($ship_total > 0){
            $ship_vat = round($ship_total * 0.10, 2);
            $lines[] = array(
                'line_no' => $i, 
                'description' => __('Shipping', 'qpay-gateway'), 
                'quantity' => 1, 
                'unit_price' => $ship_total, 
                'amount' => $ship_total,
                'tax_type' => 1,
                'vat' => $ship_vat,
                'calculate_vat' => true,
            );
        }
        
        return $lines;
    }

    /**
     * Build an eBarimt payload using order data and payment_id.
     * Follows qPay v2 ebarimt_v3/create API specification.
     */
    protected function build_ebarimt_payload($order, $payment_id){
        // Build eBarimt payload per qPay v2 API specification
        $receiver_type = $this->get_option('ebarimt_receiver_type','CITIZEN');
        $district_code = $this->get_option('ebarimt_district_code', '');
        $branch_code = $this->get_option('ebarimt_branch_code', 'SALBAR1');

        // For CITIZEN: use phone number as ebarimt_receiver
        // For COMPANY: use company register number as ebarimt_receiver
        $ebarimt_receiver = '';
        if ($receiver_type === 'CITIZEN') {
            $ebarimt_receiver = $order->get_billing_phone();
        } else {
            $ebarimt_receiver = $order->get_billing_company();
        }

        // Build the simplified eBarimt payload per the API docs
        // Required fields: payment_id, ebarimt_receiver_type
        // Optional: ebarimt_receiver, district_code, classification_code
        $payload = array(
            'payment_id' => $payment_id,
            'ebarimt_receiver_type' => $receiver_type,
        );
        
        // Add optional fields if present
        if (!empty($ebarimt_receiver)) {
            $payload['ebarimt_receiver'] = $ebarimt_receiver;
        }
        
        if (!empty($district_code)) {
            $payload['district_code'] = $district_code;
        }
        
        // Try to get classification code from the first product
        $classification_code = null;
        foreach($order->get_items() as $item_id => $item){
            $product = $item->get_product();
            if ($product) {
                $override = $product->get_meta('_mzqpay_gs1_code');
                if (!empty($override)) {
                    $classification_code = $override;
                    break;
                }
                $disable = $product->get_meta('_mzqpay_gs1_disable_map');
                if ($disable !== 'yes') {
                    $map = $this->map_product_to_gs1($product->get_sku(), $item->get_name());
                    if ($map && !empty($map['code'])) {
                        $classification_code = $map['code'];
                        break;
                    }
                }
            }
        }
        
        if (!empty($classification_code)) {
            $payload['classification_code'] = $classification_code;
        }

        // Basic validation
        $errors = $this->validate_ebarimt_payload($payload);
        if (!empty($errors)){
            return new WP_Error('ebarimt_validation','Validation failed', $errors);
        }
        return $payload;
    }

    /**
     * Validate eBarimt payload; returns array of errors or empty array
     */
    protected function validate_ebarimt_payload($payload){
        $errs = array();
        
        // Required fields per API spec
        if (empty($payload['payment_id'])) {
            $errs[] = 'payment_id is required';
        }
        if (empty($payload['ebarimt_receiver_type'])) {
            $errs[] = 'ebarimt_receiver_type is required';
        }
        if (!in_array($payload['ebarimt_receiver_type'], array('CITIZEN', 'COMPANY'))) {
            $errs[] = 'ebarimt_receiver_type must be CITIZEN or COMPANY';
        }
        
        return $errs;
    }

    /**
     * Post eBarimt payload to configured endpoint and return response array or WP_Error.
     */
    protected function post_ebarimt($payload, $token, $ebarimt_url){
        $resp = wp_remote_post($ebarimt_url, array('headers'=>array('Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'),'body'=>wp_json_encode($payload),'timeout'=>30));
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        return array('code'=>$code,'body'=>$body);
    }

    public function admin_options(){
        echo '<h3>' . esc_html($this->method_title) . '</h3>';
        echo '<p>' . esc_html__('qPay v2 gateway settings. Sandbox is prefilled with test credentials on activation.','qpay-gateway') . '</p>';
        echo '<table class="form-table">';
        // output settings fields
        $this->generate_settings_html();
        echo '</table>';
    }

    /**
     * Verify a webhook signature for given raw body, header value, secret and algorithm.
     * Returns true if signature matches, false otherwise.
     */
    public function verify_webhook_signature($raw_body, $sig_header, $secret, $alg = 'sha256'){
        if (empty($secret)) return false;
        $algo = in_array($alg, hash_algos()) ? $alg : 'sha256';
        $calc = hash_hmac($algo, $raw_body, $secret);
        // normalize header (may include prefix like 'sha256=...')
        $header_val = $sig_header;
        if (strpos($header_val, '=') !== false){
            list(, $v) = explode('=', $header_val, 2);
            $header_val = $v;
        }
        return hash_equals($calc, $header_val);
    }

    // Payment processing: request token, create invoice (simple), then return success with redirect to receipt
    public function process_payment($order_id){
        $order = wc_get_order($order_id);
        
        // Validate currency is supported
        $currency = $order->get_currency();
        if (!MZQPay_Fixtures::is_currency_supported($currency)) {
            $supported = implode(', ', array_keys(MZQPay_Fixtures::get_currencies()));
            /* translators: 1: currency code, 2: list of supported currencies */
            wc_add_notice(sprintf(__('Currency %1$s is not supported by qPay. Supported currencies: %2$s','qpay-gateway'), $currency, $supported), 'error');
            return;
        }
        
        // get sandbox/production mode and credentials (gateway options take precedence)
        $mode = $this->get_option('mode', get_option('mzqpay_mode','sandbox'));
        if ($mode === 'sandbox'){
            $client_id = $this->get_option('sandbox_client_id', get_option('mzqpay_sandbox_client_id','TEST_MERCHANT'));
            $client_secret = $this->get_option('sandbox_client_secret', get_option('mzqpay_sandbox_client_secret','123456'));
            $auth_url = 'https://merchant-sandbox.qpay.mn/v2/auth/token';
            $invoice_url = 'https://merchant-sandbox.qpay.mn/v2/invoice';
            $payment_check_url = 'https://merchant-sandbox.qpay.mn/v2/payment/check';
        } else {
            $client_id = $this->get_option('live_client_id', get_option('mzqpay_live_client_id',''));
            $client_secret = $this->get_option('live_client_secret', get_option('mzqpay_live_client_secret',''));
            $auth_url = 'https://merchant.qpay.mn/v2/auth/token';
            $invoice_url = 'https://merchant.qpay.mn/v2/invoice';
            $payment_check_url = 'https://merchant.qpay.mn/v2/payment/check';
        }

        // 1) get token via helper
        $creds = $this->get_credentials();
        $access_token = $this->get_access_token($creds['client_id'], $creds['client_secret'], $creds['auth_url']);
        if (is_wp_error($access_token)){
            $error_msg = $this->translate_api_error($access_token->get_error_message());
            wc_add_notice(__('qPay authentication failed: ','qpay-gateway') . $error_msg,'error');
            return;
        }

        // 2) create invoice with lines built from order
        $callback_url = get_home_url(null,'/?mzqpay_callback=1&order_id=' . $order_id);
        $lines = $this->build_invoice_lines($order);
        
        // Use configured invoice_code and branch_code
        $invoice_code = $this->get_option('invoice_code', 'TEST_INVOICE');
        $branch_code = $this->get_option('ebarimt_branch_code', 'SALBAR1');
        
        $payload = array(
            'invoice_code' => $invoice_code,
            'sender_invoice_no' => (string)$order_id . '-' . time(),
            'invoice_receiver_code' => 'terminal',
            'sender_branch_code' => $branch_code,
            'invoice_description' => 'Order #' . $order_id,
            'amount' => floatval($order->get_total()),
            'callback_url' => $callback_url,
            'invoice_receiver_data' => array(
                'register' => $order->get_billing_company() ?: $order->get_billing_phone(),
                'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone()
            ),
            'lines' => $lines,
            // Invoice expiry settings per qPay API docs
            'enable_expiry' => $this->get_option('enable_expiry', 'no') === 'yes' ? 'true' : 'false',
            // Partial payment settings per qPay API docs
            'allow_partial' => $this->get_option('allow_partial', 'no') === 'yes',
            'allow_exceed' => $this->get_option('allow_exceed', 'no') === 'yes',
        );
        
        // Add minimum_amount if configured
        $min_amount = $this->get_option('minimum_amount', '');
        if (!empty($min_amount) && is_numeric($min_amount)) {
            $payload['minimum_amount'] = floatval($min_amount);
        }
        
        // Add maximum_amount if configured
        $max_amount = $this->get_option('maximum_amount', '');
        if (!empty($max_amount) && is_numeric($max_amount)) {
            $payload['maximum_amount'] = floatval($max_amount);
        }
        
        // Subscription payment settings per qPay API docs (card payments only)
        if ($this->get_option('allow_subscribe', 'no') === 'yes') {
            $payload['allow_subscribe'] = true;
            $payload['subscription_interval'] = $this->get_option('subscription_interval', '1M');
            
            $subscription_webhook = $this->get_option('subscription_webhook', '');
            if (!empty($subscription_webhook)) {
                $payload['subscription_webhook'] = $subscription_webhook;
            } else {
                // Default to main callback URL
                $payload['subscription_webhook'] = $callback_url;
            }
        }

        $invoice_url = isset($creds['invoice_url']) ? $creds['invoice_url'] : '';
        $inv = wp_remote_post($invoice_url, array(
            'headers' => array('Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ));

        if (is_wp_error($inv)){
            // Network error - enqueue for retry
            $this->enqueue_task(array('type'=>'invoice_create_retry','order_id'=>$order_id,'payload'=>$payload,'attempts'=>0));
            $order->add_order_note('qPay invoice creation failed (network error); queued for retry.');
            wc_add_notice(__('qPay invoice creation queued for retry','qpay-gateway'),'notice');
            $order->update_meta_data('_mzqpay_invoice_queued','1');
            $order->save();
            $order->update_status('on-hold', 'Awaiting qPay invoice (queued)');
            return array('result'=>'success','redirect'=>$this->get_return_url($order));
        }
        
        $response_code = wp_remote_retrieve_response_code($inv);
        $inv_body = json_decode(wp_remote_retrieve_body($inv), true);
        
        if ($response_code >= 400){
            // API error - translate error message if available
            $error_message = '';
            if (isset($inv_body['message'])) {
                $error_message = $this->translate_api_error($inv_body['message']);
            } elseif (isset($inv_body['error'])) {
                $error_message = $this->translate_api_error($inv_body['error']);
            } else {
                /* translators: %d: HTTP response code */
                $error_message = sprintf(__('HTTP %d error','qpay-gateway'), $response_code);
            }
            
            // Log detailed error
            $order->add_order_note(sprintf('qPay invoice creation failed: %s (HTTP %d)', $error_message, $response_code));
            
            // Enqueue for retry
            $this->enqueue_task(array('type'=>'invoice_create_retry','order_id'=>$order_id,'payload'=>$payload,'attempts'=>0));
            wc_add_notice(__('qPay invoice creation failed: ','qpay-gateway') . $error_message,'error');
            $order->update_meta_data('_mzqpay_invoice_queued','1');
            $order->save();
            $order->update_status('on-hold', 'Awaiting qPay invoice (queued)');
            return array('result'=>'success','redirect'=>$this->get_return_url($order));
        }

        // Save invoice id to order meta if provided
        if (isset($inv_body['invoice_id'])){
            $order->update_meta_data('_mzqpay_invoice_id', $inv_body['invoice_id']);
        }
        // save full invoice response for receipt rendering
        $order->update_meta_data('_mzqpay_invoice_response', wp_json_encode($inv_body));
        $order->save();

        // mark order on-hold and redirect to receipt page where merchant can show QR/shortUrl
        $order->update_status('on-hold', 'Awaiting qPay payment');

        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    public function receipt_page($order){
        echo '<p>' . esc_html__('Thank you. Please wait for qPay payment confirmation.','qpay-gateway') . '</p>';
        $order_id = is_object($order) ? $order->get_id() : intval($order);
        $order_obj = wc_get_order($order_id);
        $inv_raw = $order_obj->get_meta('_mzqpay_invoice_response');
        if ($inv_raw){
            $inv = json_decode($inv_raw, true);
            echo '<div class="wooqpay-receipt">';
            if (!empty($inv['qr_image'])){
                echo '<p>' . esc_html__('Scan QR to pay:','qpay-gateway') . '</p>';
                echo '<img src="data:image/png;base64,' . esc_attr($inv['qr_image']) . '" alt="qpay-qr" style="max-width:300px;" />';
            }
            if (!empty($inv['qPay_shortUrl'])){
                echo '<p><a href="' . esc_url($inv['qPay_shortUrl']) . '" target="_blank">' . esc_html__('Open qPay Short URL','qpay-gateway') . '</a></p>';
            }
            if (!empty($inv['urls']) && is_array($inv['urls'])){
                echo '<p>' . esc_html__('Open in bank app:','qpay-gateway') . '</p>';
                foreach($inv['urls'] as $u){
                    if (!empty($u['link']) && !empty($u['name'])){
                        echo '<p><a class="button" href="' . esc_url($u['link']) . '">' . esc_html($u['name']) . '</a></p>';
                    }
                }
            }
            echo '</div>';
        }
    }
}
