
WooQPay
=======

Minimal WooCommerce gateway for qPay v2 (sandbox default).

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## Installation

- Copy the `wooqpay` folder to `/wp-content/plugins/`
- Activate plugin in WP admin or via WP-CLI:

```bash
wp plugin activate wooqpay
```

## Changelog

### 1.0.0 (2025-12-12)
- Initial release
- Full qPay API v2 integration (11 endpoints)
- eBarimt v3 tax receipt support
- WooCommerce HPOS compatibility
- WooCommerce Blocks checkout support
- Action Scheduler integration for background tasks
- Complete fixtures data (banks, districts, GS1 codes, currencies, VAT codes)
- Webhook signature verification (HMAC-SHA256)
- AES-256-CBC credential encryption
- Retry logic with exponential backoff
- 80 unit test methods across 16 test files
- Mongolian (mn_MN) translation support

## Setup & Quick Test (Sandbox)
1. In WordPress admin go to `WooCommerce > Settings > Payments` and enable `WooQPay`.
2. Open `Manage` for `WooQPay`. Confirm `Mode` is `Sandbox` and that `Sandbox client id` shows `TEST_MERCHANT` and `Sandbox client secret` shows `123456` (these are prefilled on activation).
3. Create a test order in the shop and choose `qPay` as the payment method. Place the order.
4. The gateway will request a sandbox access token and call the `invoice` API to create a simple invoice. The order will be placed `on-hold` and the invoice response is saved to order meta.
5. On the order `Thank you` / receipt page the plugin displays the `qr_image` (if provided), `qPay_shortUrl` and deep-link buttons for bank apps where available.

Simulate qPay callback (to mark order paid):

Replace `https://your-site` with your site URL and `INVOICE_ID` with the invoice id saved on the order (`_wooqpay_invoice_id` meta).

```bash
curl -X POST \
	-H "Content-Type: application/json" \
	-d '{"invoice_id":"INVOICE_ID"}' \
	https://your-site/wp-json/wooqpay/v1/callback
# WooQPay

qPay v2 WooCommerce gateway plugin (sandbox default).

## Quick notes
- Sandbox mode is the default on activation. Test credentials are prefilled (`TEST_MERCHANT` / `123456`).
- Use environment variables for live credentials in production by enabling `Use environment variables` in gateway settings.

## Environment variables
- `WOOQPAY_CLIENT_ID` — client id
- `WOOQPAY_CLIENT_SECRET` — client secret

If you enable `Use environment variables` in plugin settings, those environment variables will be read first.

## Webhook (callback) signing
The plugin supports validating a webhook signature using a gateway option `webhook_secret`.
The gateway expects the signature in header `x-qpay-signature` (or `x-qPay-signature`) as HMAC-SHA256 of the raw request body using the webhook secret.

Example to send a signed callback with `curl`:

```bash
BODY='{"invoice_id":"INVOICE_ID","status":"PAID"}'
SECRET='your_secret_here'
SIG=$(printf "%s" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" -binary | xxd -p -c 256)
curl -X POST https://your-site.example/wp-json/wooqpay/v1/callback \
	-H "Content-Type: application/json" \
	-H "x-qpay-signature: $SIG" \
	-d "$BODY"
```

## eBarimt
When `Enable eBarimt posting` is turned on, after qPay confirms the payment the plugin will attempt to create an eBarimt entry using the qPay eBarimt endpoint.
- The plugin will attempt to map products to GS1 classification codes using the `QPayAPIv2_parsed.json` file located in `wp-content/plugins/docs/` (created by the parser you ran earlier).
- The eBarimt payload currently includes buyer info, total amount, and invoice lines built from order items.
- eBarimt responses are stored on the order meta key `_wooqpay_ebarimt_response` and recorded in order notes.

## Testing
1. Activate the plugin (it defaults to sandbox).
2. Create a WooCommerce test order.
3. On checkout, choose `qPay` and complete the flow — the plugin creates an invoice at qPay sandbox and stores the invoice response in order meta.
4. Simulate a qPay callback using the `curl` example above (set `invoice_id` to the invoice id returned in the stored invoice response).

## Debugging
- Order notes contain detailed messages about authentication, invoice creation, eBarimt posting, and webhook handling.
- If GS1 mapping does not find a match for a product, the plugin will still create invoice lines without classification codes.

## Parser
If you need to re-run the Excel parser, run the helper script that created `QPayAPIv2_parsed.json`. The workspace originally used a pure-Python XLSX parser (no external deps) to generate the parsed JSON.

---

If you want, I can: update README with more eBarimt field mappings, add tests for mapping quality, or make mapping configurable per-product via product metadata. Tell me which next.

