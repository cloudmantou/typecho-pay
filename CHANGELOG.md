# Changelog

## Unreleased

## 0.2.0 - 2026-06-25

- Enhanced plugin config UI with grouped sections: Basic, PayPay, WeChat Pay, Alipay
- Added detailed descriptions and help text for all configuration fields
- Added direct links to payment platform dashboards (PayPay, WeChat, Alipay)
- Created settings-help.php admin panel with callback URLs display
- Added comprehensive setup guides for each payment gateway
- Added shortcode usage documentation and FAQ section
- Updated README with new admin menu structure and setup instructions

## 0.1.0 - 2026-06-25

- Fixed PayPay Dynamic QR active query path to `/v2/codes/payments/{merchantPaymentId}`.
- Added active order query for PayPay, WeChat Pay, and Alipay.
- Changed `/action/typechopay?do=query` to query the upstream gateway while orders are pending.
- Added QR payment page rendering with polling.
- Added signed payment payload expiry, gateway coverage, and one-time nonce consumption.
- Added `pay_entitlements` and minimal paid-reading access grants.
- Fixed entitlement access SQL grouping and hid purchase buttons for already-owned content.
- Added signed `return_to` handling so paid QR pages redirect back instead of reloading POST.
- Added `paid_pending_grant` / `grant_failed` recovery states and admin entitlement regrant action.
- Added server-side throttling for active gateway queries.
- Hardened paid state transitions so orders enter `paid` only after entitlement grant succeeds.
- Added provider event metadata fields for payment event auditing.
- Fixed attributed `[typechopay_content ...]` parsing, Composer SDK autoloading, and currency-incompatible gateway buttons.

## 0.1.0 - 2026-06-25

- Added TypechoPay plugin entry and activation/deactivation flow.
- Added order and notification-event tables.
- Added unified `/action/typechopay` create, notify, query, and return handlers.
- Added PayPay Dynamic QR direct HMAC client.
- Added WeChat Pay Native SDK adapter and APIv3 notification verification.
- Added Alipay Page Pay / Precreate SDK adapter and async notification verification.
- Added article shortcode with HMAC-signed payment entry fields.
- Added admin order list and implementation documentation.
