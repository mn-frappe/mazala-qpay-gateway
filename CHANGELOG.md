# Changelog

All notable changes to WooQPay will be documented in this file.

## [1.0.0] - 2024-12-12

### Added
- Full qPay API v2 integration
- All 11 API endpoints implemented:
  - POST /v2/auth/token - Authentication
  - POST /v2/auth/refresh - Token refresh
  - POST /v2/invoice - Create invoice
  - GET /v2/invoice/{id} - Get invoice
  - DELETE /v2/invoice/{id} - Cancel invoice
  - POST /v2/payment/check - Check payment status
  - GET /v2/payment/{id} - Get payment details
  - DELETE /v2/payment/{id} - Cancel payment
  - POST /v2/payment/refund - Process refund
  - POST /v2/payment/list - List payments
  - POST /v2/ebarimt_v3/create - Create eBarimt
  - DELETE /v2/ebarimt/{id} - Cancel eBarimt
- eBarimt v3 API support with full payload validation
- WooCommerce Blocks checkout support
- WooCommerce HPOS (High-Performance Order Storage) compatibility
- WooCommerce COT (Custom Order Tables) support
- Store API integration for headless commerce
- WC_Logger integration for debugging
- Multi-bank deep link support (14+ banks)
- QR code payment display
- Real-time payment polling
- Webhook signature validation (HMAC-SHA256)
- Automatic token refresh
- Token caching with expiry detection
- Invoice expiry configuration
- Partial payment support
- Exceed amount payment support
- Recurring payment foundation
- Refund processing from WooCommerce
- Stock level reduction on payment
- Order status management
- Admin settings panel
- Sandbox mode with pre-filled test credentials
- Mongolian (mn_MN) translation
- Complete fixtures from qPay API documentation:
  - 14 banks with deep links
  - 21 districts
  - 25+ error codes with translations
  - Invoice statuses
  - Payment statuses
  - Tax product codes (G1s)
  - Currency codes
  - Receiver types

### Security
- ABSPATH direct access prevention
- Nonce verification for AJAX requests
- Input sanitization
- Output escaping
- SQL prepared statements
- Secret encryption support
- Webhook signature validation

### Developer
- PHPDoc documentation
- PHPCS configuration
- EditorConfig settings
- Comprehensive test suite (20+ test files)
- Git ignore patterns
