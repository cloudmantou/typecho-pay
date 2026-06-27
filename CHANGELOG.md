# Changelog

## Unreleased

## 0.4.7 - 2026-06-27

### Alipay runtime

- Fixed the Alipay SDK loader so it resolves Composer vendor files from the TypechoPay plugin root instead of the parent plugins directory.
- Added a regression check for the SDK vendor path used by sandbox and production Alipay requests.

## 0.4.6 - 2026-06-27

### Alipay sandbox

- Added an `alipayGatewayUrl` plugin setting so sandbox tests can use `https://openapi-sandbox.dl.alipaydev.com/gateway.do`.
- Changed the Alipay AOP client to read the configured gateway URL instead of always using the production gateway.
- Documented the Alipay sandbox gateway in the settings help page and added a regression test for the gateway configuration.

## 0.4.5 - 2026-06-27

### Article product display

- Added a cursor-position insert button for `[typechopay_product]` in the article editor while keeping the save-time top insertion option.
- Added editor visibility status and a frontend preview link so admins can see whether the product panel will render through a shortcode, global auto-injection, or theme helper integration.
- Product cards for already purchased card-code products now show a `查看我的卡密` link when the current visitor has a delivered card order.
- Added admin-only product-panel diagnostics for missing deliverables, missing compatible gateways, and empty card-code stock.

## 0.4.4 - 2026-06-27

### Theme integration

- Added `Plugin::renderArticleProductPanel($archive)` so themes that do not run Typecho's content plugin chain can explicitly render the bound article product panel.
- Changed frontend CSS loading from direct `echo` to HTML returned with rendered TypechoPay UI, and added a plugin setting to disable the default frontend CSS.
- Added admin-only hidden diagnostics for auto-injection failures: auto-inject disabled, missing bound product, and paused product.

### Article editor

- The article editor now shows bound product ID, product status, stock summary, and the current auto-insert setting.
- The editor defaults toward inserting `[typechopay_product]` when saving an enabled paid mode, reducing theme compatibility issues when global auto-injection is disabled.
- Clarified that article-editor card pasting is for small additions and formal batch import should use the inventory/product management flow.

## 0.4.3 - 2026-06-27

### Article products

- Fixed article product auto-injection so `[typechopay_content]` protected blocks no longer prevent the bound product panel from rendering.
- Added Typecho native category filters for `[typechopay_shop]` through `mid`, `typecho_mid`, `typecho_category`, `category_slug`, and `typecho_category_slug`.
- Added `Plugin::renderPostBadge($archive)` for theme article-list badges, with `typechopay/post-badge.php` override support.
- Product cards now fall back to the bound article's Typecho categories when no TypechoPay 商城专题 is set.

### Admin

- Renamed the product category UI to 商城专题 to avoid confusing it with Typecho native article categories.
- Product management now shows the bound article title, edit link, and Typecho article categories for products with `content_id`.

## 0.4.2 - 2026-06-26

### Article editor

- Simplified the article editor paid-feature panel to the common modes: off, paid reading, and card-code management.
- Fixed article-editor products to CNY so the card-code workflow no longer shows a JPY selector.
- Added card-code inventory stats, recent masked card rows, and pasted card import directly at the bottom of the article editor.
- Saving an article in card-code mode can now import pasted card lines through `CardCodeService` while keeping full file upload and preview import in 商品管理.

## 0.4.1 - 2026-06-26

### Article editor

- Added a TypechoPay paid-feature panel to the Typecho post/page editor.
- Added editor-side modes for off, paid reading, and card-code management, with download/image/video shown as reserved modes.
- Saving an article can now upsert the bound `pay_products` row by `content_id`, maintain card-code / post-access deliverables, and optionally insert `[typechopay_product]` into the article body.
- Added quick links from the editor panel to edit the bound product, view card inventory, and view card sales.

### Card import

- Fixed card import preview so `preview_cards` stays on the page instead of redirecting before the preview can render.
- Changed preview confirmation to use a temporary `preview_token` instead of embedding raw card lines in hidden form fields.

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
