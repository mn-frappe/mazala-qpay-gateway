=== Mazala QPay Gateway for WooCommerce ===
Contributors: mazalaio
Tags: woocommerce, payment, qpay, mongolia, ebarimt
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments via qPay (Mongolian payment gateway) with full eBarimt tax receipt integration for WooCommerce stores.

== Description ==

Mazala QPay Gateway for WooCommerce is a complete qPay payment gateway integration for WooCommerce, enabling Mongolian businesses to accept payments via qPay's QR code payment system with full eBarimt (electronic tax receipt) support.

= Features =

* **qPay API v2 Integration** - Full support for qPay's latest API
* **QR Code Payments** - Generate QR codes for easy mobile payments
* **eBarimt Integration** - Automatic tax receipt generation (v3 API)
* **Multi-Bank Support** - Deep links for all major Mongolian banks
* **WooCommerce Blocks** - Full support for the new checkout blocks
* **HPOS Compatible** - Works with WooCommerce High-Performance Order Storage
* **Sandbox Mode** - Test with qPay's sandbox environment
* **Refund Support** - Process refunds directly from WooCommerce
* **Webhook Support** - Real-time payment notifications
* **Mongolian Language** - Full translation included

= Supported Banks =

* Khan Bank
* Golomt Bank
* Trade and Development Bank (TDB)
* Khas Bank
* State Bank
* XacBank
* Capitron Bank
* Most Money
* Bogd Bank
* TransDev Finance
* Arig Bank
* Credit Bank
* Chingis Khaan Bank
* National Investment Bank (NIB)

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher
* SSL certificate (HTTPS required for callbacks)
* qPay merchant account

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/mazala-qpay-gateway/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to WooCommerce > Settings > Payments > Mazala QPay Gateway
4. Enter your qPay credentials (sandbox credentials are pre-filled for testing)
5. Configure eBarimt settings if needed
6. Enable the gateway and start accepting payments

= Sandbox Testing =

The plugin comes pre-configured with qPay sandbox credentials:
* Sandbox URL: https://merchant-sandbox.qpay.mn
* Username: TEST_MERCHANT
* Password: 123456
* Invoice Code: TEST_INVOICE

== Frequently Asked Questions ==

= What is qPay? =

qPay is Mongolia's leading QR code payment platform that allows customers to pay using their bank's mobile app by scanning a QR code.

= What is eBarimt? =

eBarimt is Mongolia's electronic tax receipt system. This plugin can automatically generate eBarimt receipts for all payments.

= Is HTTPS required? =

Yes, HTTPS is required for receiving webhook callbacks from qPay.

= Can I test without a qPay account? =

Yes! The plugin includes sandbox credentials for testing. Just enable sandbox mode.

== Screenshots ==

1. Payment gateway settings
2. QR code on checkout
3. Payment confirmation page
4. eBarimt receipt generation

== Changelog ==

= 1.0.0 =
* Initial release
* Full qPay API v2 integration
* eBarimt v3 API support
* WooCommerce Blocks support
* HPOS compatibility
* Multi-bank deep links
* Webhook support
* Refund processing
* Mongolian translation

== Upgrade Notice ==

= 1.0.0 =
Initial release of Mazala QPay Gateway for WooCommerce.
