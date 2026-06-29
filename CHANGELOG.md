# Changelog

## Unreleased

### Security hardening

- Kept order rate limiting active when the client IP cannot be resolved by falling back to a shared unknown-client scope.
- Switched TypechoPay action/event IP capture to Typecho's request IP helper instead of reading `REMOTE_ADDR` directly.
- Hardened guest token secure-cookie detection behind common HTTPS-terminating reverse proxies.
- Replaced payment-create exception message matching with a typed gateway configuration exception.

### Maintenance

- Removed the unused non-atomic return-token verifier and unused fulfillment handler contract.
- Reworked card stock counting to use the Typecho query builder.
- Replaced theme-template `extract()` usage with an isolated renderer.

## 0.4.22 - 2026-06-29

### Article product display

- Defaulted the article editor shortcode insertion checkbox to enabled when global product auto-injection is off, so saved card-code articles still get a visible frontend purchase module.

## 0.4.21 - 2026-06-28

### Article card editor

- Defaulted new article card products to 0.01 yuan in the editor so selecting card mode no longer saves an empty amount.
- Normalized blank article-editor amounts to the minimum 1 fen during save and shortcode insertion, keeping the card import flow from aborting before cards are stored.

## 0.4.20 - 2026-06-28

### Alipay Page Pay

- Removed unsupported `AopClient::$charset` assignment so PHP 8 no longer prints deprecated dynamic-property notices before the Alipay Page Pay form.
- Added regression coverage for the supported `postCharset` AOP client configuration.

## 0.4.19 - 2026-06-28

### Alipay routing

- Changed Alipay payment routing to use Page Pay on desktop browsers and Wap/H5 Pay on mobile browsers.
- Removed Precreate QR code payment from the default Alipay browser flow.
- Refreshed return tokens when retrying reusable active orders that do not yet have a payment entry.

## 0.4.18 - 2026-06-28

### Alipay signing

- Fixed Alipay Page Pay requests missing the `sign` parameter by passing bare key bodies to the AOP SDK while keeping full PEM text in plugin settings.
- Added AOP SDK-compatible private-key signing and public-key loading checks to the diagnostics page.
- Aligned Alipay AOP client setup with the official demo by setting `postCharset` and `apiVersion`.

## 0.4.17 - 2026-06-28

### Alipay settings

- Changed the Alipay application private key and Alipay public key fields to render saved values directly in the plugin settings page.
- Updated settings copy to clarify that unchanged displayed key values can be saved as-is.

## 0.4.16 - 2026-06-28

### Alipay settings

- Clarified that saved Alipay key textareas intentionally stay blank to avoid rendering private keys into admin HTML.
- Added explicit saved/unsaved status text for Alipay AppID, application private key, and Alipay public key fields.
- Clarified that the plugin needs the application private key and Alipay public key; the application public key belongs in the Alipay Open Platform console.

## 0.4.15 - 2026-06-28

### Config restore

- Preserved encrypted sensitive config backups when current Typecho plugin settings contain empty redacted fields, preventing Alipay private/public keys from being cleared during disable/enable cycles.
- Added regression coverage for Alipay text-key storage and backup fallback behavior.

## 0.4.14 - 2026-06-28

### Config restore

- Restore `typechopay_config_backup` back into `plugin:TypechoPay` during plugin activation so settings survive Typecho's disable/enable config deletion path.

## 0.4.13 - 2026-06-28

### Alipay diagnostics

- Trim Alipay AppID and Seller ID/PID on save and read to avoid invisible whitespace in provider requests.
- Clarified that Alipay AppID must be the application APPID, while the bound merchant account PID belongs in the optional Seller ID field.
- Expanded the payment diagnostics page to show the effective AppID, detect AppID/PID mixups, and label the configured Alipay gateway as sandbox or production.

## 0.4.12 - 2026-06-28

### Hardening

- Sensitive plugin settings now use separate replacement inputs; saved endpoint, WeChat APIv3, Alipay private-key, and Alipay public-key values are no longer rendered back into the settings HTML.
- Config backup format now encrypts sensitive fields with a site-secret-derived AES-GCM key while keeping disable/enable recovery.
- New card-code imports no longer write card text into the legacy `code_mask` column.
- Card inventory and card sales admin pages now send `Cache-Control: no-store`.

### Operations

- Added a `支付诊断` admin page for PHP extensions, Composer SDK loading, HTTPS notify URLs, WeChat certificate path readability, and Alipay key parseability.
- Removed the legacy PayPay gateway adapter from the source tree and CI tests; the current mainline supports WeChat Pay and Alipay CNY flows.
- Marked `do=create` as a legacy signed-entry endpoint; new frontend entry points continue to use `do=prepare`.
- Added hidden upgrade shims for old `defaultCurrency`, PayPay, and `_section_*` settings so legacy saved config can be opened once and cleaned on save.
- Expanded schema usability checks for product, card item, category, fulfillment, and token columns.
- CI now runs on PHP 7.4, 8.0, and 8.2.

### Article editor

- Article binding and body insertion are now separated: the editor no longer defaults to writing `[typechopay_product]` into the article body, while the explicit insert controls remain available.

## 0.4.11 - 2026-06-28

### Alipay settings

- Added TypechoPay config backup/restore so plugin updates that require disable/enable can keep saved payment settings visible.
- Alipay AppID, gateway URL, application private key, Alipay public key, and Seller ID now load existing saved values into the settings form defaults.
- Alipay ordinary public-key-mode keys can be pasted directly into plugin settings; bare key bodies are normalized to PEM text before saving.

### Card codes

- Article editor card lists, card inventory, and card sales now show full card-code values for administrators instead of starred masks.
- New imports no longer generate starred `code_mask` compatibility labels.

## 0.4.10 - 2026-06-27

### Article editor

- Fixed the article card import submit button so it no longer depends on Typecho's delegated `button[name=do]` handler.
- The import button now explicitly locates the write form, sets `do=save`, marks the form as submitting to avoid beforeunload prompts, and submits the native form.

## 0.4.9 - 2026-06-27

### Article editor

- Added an explicit `确认提交` button to the article editor card import tab.
- The card import submit now saves the article and imports pasted cards, then returns to the card list with the existing import result notice.
- Added an empty-input guard so the import submit button does not accidentally save without pasted card lines.

## 0.4.8 - 2026-06-27

### Admin simplification

- Simplified the article editor card-code panel into two tabs: card list and card import.
- Changed article and product admin price inputs to CNY yuan with a 0.01 minimum while keeping stored amounts in fen.
- Removed PayPay/JPY from the operator-facing plugin config, settings help, README examples, and payment gateway filtering.

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
- Added card-code inventory stats, recent card rows, and pasted card import directly at the bottom of the article editor.
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
