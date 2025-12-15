<?php
/**
 * Mazala QPay Blocks Integration
 * 
 * Integrates Mazala QPay payment gateway with WooCommerce Blocks checkout.
 * 
 * @package MazalaQPay
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MZQPay Blocks Support Class
 * 
 * Extends AbstractPaymentMethodType to provide block checkout integration.
 */
final class MZQPay_Blocks_Support extends AbstractPaymentMethodType {
    
    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'mzqpay';
    
    /**
     * Gateway instance
     *
     * @var MZQPay_Payment_Gateway
     */
    private $gateway;

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_mzqpay_settings', array());
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways[$this->name]) ? $gateways[$this->name] : null;
    }

    /**
     * Returns if this payment method should be active.
     *
     * @return boolean
     */
    public function is_active() {
        if (!$this->gateway) {
            return false;
        }
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path = 'assets/js/blocks/mzqpay-blocks.js';
        $script_asset_path = MZQPAY_PLUGIN_DIR . 'assets/js/blocks/mzqpay-blocks.asset.php';
        $script_asset = file_exists($script_asset_path)
            ? require($script_asset_path)
            : array(
                'dependencies' => array(),
                'version' => MZQPAY_VERSION
            );
        $script_url = MZQPAY_PLUGIN_URL . $script_path;

        wp_register_script(
            'mzqpay-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('mzqpay-blocks', 'mazala-qpay-gateway', MZQPAY_PLUGIN_DIR . 'languages');
        }

        return array('mzqpay-blocks');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        return array(
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports' => array_filter($this->gateway ? $this->gateway->supports : array(), array($this->gateway, 'supports')),
            'logo_url' => MZQPAY_PLUGIN_URL . 'assets/images/qpay-logo.png',
            'icons' => $this->get_icons(),
        );
    }

    /**
     * Get payment method icons.
     *
     * @return array
     */
    private function get_icons() {
        $icons = array();
        
        // Add qPay logo
        $icons[] = array(
            'id' => 'qpay',
            'src' => MZQPAY_PLUGIN_URL . 'assets/images/qpay-logo.png',
            'alt' => 'qPay',
        );
        
        return $icons;
    }
}
