# Changelog

## Unreleased

## 0.4.0 - 2026-06-26

### Article products

- Added optional article-product auto injection for products bound by `pay_products.content_id`.
- Added `[typechopay_product]` support without a `product` attribute, resolving the current article's bound active product.
- Added default article product panel markup with stock, sold count, login-required, sold-out, owned, and gateway availability states.
- Added `product-panel.php` theme override support via `usr/themes/{theme}/typechopay/product-panel.php`.

### Purchase rules

- Enforced `allow_guest = 0` on the payment create path, not only in admin metadata.
- Included `allow_guest` and `max_per_user` in stored product resolution and order snapshots.
- New card-code products now default to login-required purchase unless the admin explicitly enables guest purchase.
- Product edit version bumps now include status, `max_per_user`, `allow_guest`, `content_id`, stock policy, and deliverable changes.

## 0.3.2 - 2026-06-26

### Admin panels

- Added card-inventory page: paginated inventory with product/status/batch filters, void/compromised batch actions.
- Added card-sales page: delivered card list with order, payment, fulfillment, retry count, and last error.
- Added product editing: modify title, price, status, purchase policy, max_per_user, and deliverables for existing products.
- Added max_per_user input to product create and edit forms.
- Added file upload (.txt/.csv/.tsv, max 5MB) for card-code import alongside textarea input.
- Import now reports raw count, file-internal duplicates, imported count, and database duplicates.
- Product table shows void/compromised stock counts and edit/inventory/sales links.
- Registered card-inventory and card-sales panels in plugin activate/deactivate.

### Bug fixes

- Fixed PurchasePolicyService.countPaidOrders to use COUNT(*) so `limited` policy correctly counts multiple purchases.
- Fixed releaseExpiredReservations to skip cards whose associated orders have payment_status paid or processing.
- Fixed parseLines to return raw_count and duplicate_in_file statistics instead of silently dropping in-file duplicates.
- Added mb_strlen fallback on batch name length check for environments without ext-mbstring.
- Added batch pre-check for existing fingerprints before insert to reduce database unique-violation errors.

### Schema v7

- Added indexes: idx_delivered_order, idx_product_delivery (product_id, status, delivered_at), idx_batch_status (batch_id, status).

## 0.3.1 - 2026-06-26

- Fixed active-order reuse so existing payment sessions are not recreated with the same `out_trade_no`.
- Restricted reuse to non-expired orders with matching product version, amount, currency, and gateway.
- Preserved existing payment return tokens during active-order reuse and only rotated poll/delivery access tokens where safe.
- Added `return_token_expires_at` and atomic return-token consumption (`consumeReturnToken`) before issuing a delivery cookie.
- Removed generated long-lived delivery tokens from delivery URLs; delivery access now uses HttpOnly cookie or owner checks.
- Fixed IP rate limiting to use 64-character nonce hashes and Typecho query builder counts.
- Decoupled payment confirmation from fulfillment so verified paid callbacks are acknowledged even if local delivery fails.
- Added `confirmPayment` and `fulfillPaidOrder` methods to OrderService for clean state-machine separation.
- Payment return now issues 303 redirect to a clean delivery URL without tokens in the query string.
- Added `return_token_expires_at` column (schema v6).

## 0.3.0 - 2026-06-26

- Split poll_token into three tokens: poll (frontend polling only), return (one-time for payment platform redirects), delivery (long-lived for card page).
- PayPay and Alipay redirect URLs no longer expose poll_token.
- Added rate limiting (10 prepare/min per IP) and active order reuse to prevent inventory exhaustion.
- Fixed concurrent card reservation: re-check inside retry loop + UNIQUE constraint on reserved_order_id.
- Purchase policy now based on paid order history (PurchasePolicyService), not content entitlements.
- Stored products require at least one enabled deliverable.
- Card code import is transactional; only unique constraint violations count as duplicates.
- Schema migration backfills old order statuses and verifies tables before version bump.
- syncProviderStatus releases cards only after successful status transition.
- Delivery page security headers: Referrer-Policy, X-Robots-Tag, X-Frame-Options, X-Content-Type-Options.
- Added admin product and card-code inventory management panel.
- Added encrypted card-code import using AES-256-GCM and per-product duplicate fingerprints.
- Added card-code reservation before gateway creation and idempotent delivery after payment success.
- Added release of reserved card-code stock when orders fail, expire, cancel, or close.
- Added secure card delivery page guarded by delivery token/cookie or order ownership.
- Added `CardCodeCipherTest` for encrypted card-code round trips.
- Added product-mode payment entries that resolve current server-side product price by `product` or `product_id`.
- Added product snapshot columns to orders and created product, deliverable, fulfillment, card batch, and card inventory tables.
- Split payment state into `payment_status` and fulfillment state into `fulfillment_status` while keeping legacy `status`.
- Added a minimal fulfillment manager for `post_access` and `content_block` entitlement delivery.
- Changed guest recovery to claim both guest orders and guest entitlements after login.
- Removed runtime schema mutation from business requests and moved schema upgrades behind an activation-time schema version.
- Stopped reusing pending orders so each order keeps a stable poll token for its lifetime.
- Parsed Alipay yuan amounts with string arithmetic instead of floating-point conversion.
- Fixed PayPay transaction webhook parsing for `merchant_order_id`, `order_id`, `order_amount`, `merchant_id`, and `notification_type`.
- Added per-order poll tokens for query/return access control and removed `return_to` from public query responses.
- Added click-time payment entry preparation so cached article HTML no longer embeds short-lived nonce values.
- Kept orders in `processing` when payment creation result is unknown instead of marking them failed.
- Synced provider terminal states such as expired, cancelled, failed, closed, revoked, and trade closed.
- Added server-side duplicate purchase checks, stricter business target validation, guest entitlement claim, and formatted amount display.
- Fixed PayPay setup links to point to PayPay OPA/Dynamic QR documentation.
- Clarified that Alipay currently supports ordinary public-key mode only.
- Clarified CNY shortcode amounts are written in cents and converted to yuan for Alipay.
- Added minimal GitHub Actions CI for dependency install, PHP lint, and signer tests.
- Suppressed third-party Alipay SDK deprecation notices while loading SDK files on PHP 8.
- Declared the PHP cURL extension and excluded `vendor/` from CI lint.
- Generated settings-help callback URLs with Typecho `Common::url()`.

## 0.2.0 - 2026-06-25

- Reorganized plugin config fields by Basic, PayPay, WeChat Pay, and Alipay sections
- Added detailed descriptions and help text for all configuration fields
- Added direct links to payment platform dashboards (PayPay, WeChat, Alipay)
- Created settings-help.php admin panel with callback URLs display
- Added comprehensive setup guides for each payment gateway
- Added shortcode usage documentation and FAQ section
- Updated README with new admin menu structure and setup instructions

## 0.1.1 - 2026-06-25

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
