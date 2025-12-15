<?php
/**
 * Asset file for WooQPay Blocks integration
 */
return array(
    'dependencies' => array(
        'wc-blocks-registry',
        'wc-settings',
        'wp-element',
        'wp-html-entities',
        'wp-i18n',
    ),
    'version' => defined('WOOQPAY_VERSION') ? WOOQPAY_VERSION : '1.0.0',
);
