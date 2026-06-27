# GitHub Record

Date: 2026-06-25

## 2026-06-27 Article Card Import Submit (v0.4.9)

### Change

Made the article editor card import tab more explicit for operators.

### Scope

- Added a `确认提交` button directly below the pasted card textarea.
- The button submits with `do=save`, so it saves the article and imports cards without forcing the publish action.
- Added a browser-side empty textarea guard before submit.
- Kept the existing backend import notice, so successful imports report raw count, duplicates, imported count, and database duplicates after reload.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-27 Admin Simplification and CNY Flow (v0.4.8)

### Change

Simplified the operator-facing payment flow around article products and card-code delivery.

### Scope

- Changed article editor and product admin amount inputs from fen integers to yuan decimal inputs, with 0.01 as the minimum accepted value.
- Kept database and gateway amounts in fen through `Money::assertCnyYuanAmount()`.
- Reworked the article editor card-code area into two tabs: `卡密列表` and `添加卡密`.
- Removed PayPay/JPY from plugin config, settings help, README examples, frontend gateway normalization, and create/notify gateway filtering.
- Kept historical gateway files and tests in place for now; this change only removes them from the current product surface.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-27 Alipay SDK Loader Fix (v0.4.7)

### Change

Fixed the Alipay runtime path discovered during live sandbox testing on the Typecho server.

### Scope

- Corrected `Support/AlipaySdk.php` so SDK files are loaded from `TypechoPay/vendor/...`.
- Added a static regression check to catch accidental fallback to `usr/plugins/vendor/...`.
- Kept the sandbox gateway setting from v0.4.6 unchanged.

### Verification

Run after pulling this change:

```sh
composer install --no-dev --prefer-dist --no-interaction --no-progress
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-27 Alipay Sandbox Gateway Configuration (v0.4.6)

### Change

Prepared the Alipay integration for real sandbox testing by making the AOP gateway URL configurable from the TypechoPay plugin settings.

### Scope

- Added `alipayGatewayUrl` to the plugin settings.
- Defaulted blank or invalid gateway URLs back to `https://openapi.alipay.com/gateway.do`.
- Documented the sandbox gateway `https://openapi-sandbox.dl.alipaydev.com/gateway.do`.
- Changed `AlipayGateway` so the SDK client uses the configured gateway URL instead of a hardcoded production endpoint.
- Added `tests/AlipayGatewayTest.php` as a regression guard.

### Boundary

This does not change Alipay key mode support. The plugin still supports ordinary public-key mode only, not public-key certificate mode.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-27 Article Product Display Stability (v0.4.5)

### Change

Stabilized the article editor to frontend product-panel path so an article-bound card-code product is easier to insert, preview, and diagnose before real payment testing.

### Scope

- Added a cursor-position editor button for inserting `[typechopay_product]`, while retaining the save-time top insertion option.
- Added editor visibility status for shortcode, global auto-injection, and theme-helper fallback paths.
- Added a frontend preview link from the article editor when the current content already has a permalink.
- Added owner-limited `查看我的卡密` action rendering for card-code products with delivered cards.
- Added admin-only product-panel diagnostics for missing deliverables, no compatible gateway, and empty stock.

### Boundary

This is a display-stability change. It does not add payment popups, local QR JavaScript, a buyer user center, refunds, or membership/download deliverables.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-27 Theme Integration and Editor Diagnostics (v0.4.4)

### Change

Improved the article-as-product flow for themes that do not use Typecho's normal content rendering chain, and made article-editor/card diagnostics more operator friendly.

### Scope

- Added `Plugin::renderArticleProductPanel($archive)` as an explicit theme helper for article detail templates.
- Added admin-only hidden HTML diagnostics for common auto-injection misses: auto-inject disabled, no product bound to the current content id, and paused products.
- Replaced direct CSS `echo` with a `shopCssLink()` helper that returns the stylesheet link with rendered UI HTML.
- Added `loadFrontendCss` plugin config so themes can fully own frontend styling.
- Added editor status chips for bound product ID, product status, current stock, and auto-insert position.
- Kept article-editor pasted card import positioned as a small quick-add path; formal batch imports remain in the product/inventory management flow.

### Boundary

This change does not modify arbitrary themes automatically. Themes should add `Plugin::renderPostBadge($this)` in article-list cards and `Plugin::renderArticleProductPanel($this)` in article detail templates only where the theme needs explicit integration.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-27 Article Product Display and Typecho Category Alignment (v0.4.3)

### Change

Aligned the product display path with the article-as-product model: Typecho article categories remain the frontend primary taxonomy, while TypechoPay categories are now presented as optional shop topics.

### Scope

- Changed auto-injection detection so `[typechopay_content]` protected blocks do not suppress the bound article product panel.
- Added Typecho native category filters to `[typechopay_shop]`: `mid`, `typecho_mid`, `typecho_category`, `category_slug`, and `typecho_category_slug`.
- Product cards fall back to the bound article's Typecho category labels when no TypechoPay shop topic is assigned.
- Added `Plugin::renderPostBadge($archive)` for theme article-list badges and `typechopay/post-badge.php` theme override support.
- Product management now displays bound article title, edit link, and Typecho article categories for `content_id` bindings.
- Renamed the product-category UI copy to **商城专题** to avoid confusing it with Typecho native article categories.

### Boundary

This change does not automatically patch arbitrary theme list templates. Themes can call `Plugin::renderPostBadge($this)` where they want the article card badge to appear, or override `typechopay/post-badge.php`.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-26 Simplified Article Card-Code Management (v0.4.2)

### Change

Moved the card-code operator workflow closer to the article editor bottom area and reduced the fields shown during normal writing.

### Scope

- Simplified the editor panel title to **文章付费与卡密** and kept only off, paid reading, and card-code management modes.
- Removed the article-editor JPY selector. Article-created products are fixed to CNY and use cents.
- Kept product key, purchase policy, stock display, cover, and summary as hidden/preserved values in the article editor; advanced changes remain in 商品管理.
- Added card-code inventory stats, recent masked card rows, and pasted card import in the article editor panel.
- Saving an article in card-code mode can import pasted card lines through `CardCodeService`; file upload and preview confirmation remain in 商品管理.

### Boundary

The article editor import accepts pasted text only because Typecho's native write form is not a file-upload form. Full `.txt/.csv/.tsv` upload and preview confirmation remain in the product management page.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-26 Article Editor Paid-Feature Panel (v0.4.1)

### Change

Added the article editing workflow for card-code products: authors can enable TypechoPay directly while writing a Typecho post/page, instead of creating a detached product and manually copying the article cid.

### Scope

- Registered Typecho post/page editor hooks and post/page save hooks.
- Added a **TypechoPay 付费功能** panel with modes for off, paid reading, and card-code management.
- Saving the article upserts `pay_products` by `content_id`, validates price/key/purchase rules, and syncs `post_access` / `cardcode` deliverables.
- The editor can insert `[typechopay_product]` into the article body so the bound product renders even when global auto-injection is disabled.
- Added quick links from the editor panel to product editing, card inventory, and card sales for already-bound products.
- Fixed card import preview so it renders without redirecting, and changed confirmation to a temporary `preview_token` instead of hidden raw card lines.

### Boundary

Download, image, video, and membership-free rules are still placeholders. The current implemented editor modes are paid reading and card-code management.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

After deployment to an already-enabled Typecho site, disable and re-enable TypechoPay once so the new editor hooks are registered.

## 2026-06-26 Article Product Frontend Loop (v0.4.0)

### Change

Moved the frontend card-code purchase flow closer to the article-as-product model: a Typecho article remains the product detail page, while TypechoPay owns price, purchase policy, stock reservation, and card delivery.

### Scope

- Added a plugin setting for article product auto-injection: off, top, bottom, or after the first paragraph.
- Added `Plugin::autoInjectProductPanel()` so a bound active product can render automatically on its article when no TypechoPay shortcode is present.
- Added `[typechopay_product]` support without a `product` attribute, resolving the active product bound to the current article cid.
- Added default `typechopay-product-panel` markup and CSS with stock, sold count, sold-out, owned, login-required, and payment-unavailable states.
- Added `product-panel.php` theme override support under `usr/themes/{theme}/typechopay/`.
- Enforced `allow_guest = 0` in `Action::createFromPayload()` before creating guest tokens or orders.
- Added `allow_guest` and `max_per_user` to resolved stored-product data and order snapshots.
- Defaulted newly created card-code products to login-required purchase unless the admin explicitly enables guest purchase.
- Expanded product edit version bumps to include status, max_per_user, allow_guest, content_id, stock policy, and deliverable changes.

### Boundary

This change does not inject product badges into arbitrary theme article-list cards. Themes can use shortcodes or override templates first; a theme-specific list-card hook should be added only after the target theme integration is known.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-26 Admin Panels and Card Management (v0.3.2)

### Change

Added card-inventory and card-sales admin pages, product editing, file upload import, and fixed purchase policy / reservation release logic.

### Scope

- New `manage/card-inventory.php`: paginated card inventory with product/status/batch filters, void and compromised batch actions.
- New `manage/card-sales.php`: delivered cards with order/payment/fulfillment details, retry count, last error.
- Product editing in `manage/products.php`: title, price, status, purchase policy, max_per_user, deliverables.
- File upload (.txt/.csv/.tsv, max 5MB) for card import alongside textarea.
- Import statistics: raw_count, duplicate_in_file, imported, db_duplicates.
- Fixed `PurchasePolicyService::countPaidOrders` to use `COUNT(*)` for `limited` policy.
- Fixed `releaseExpiredReservations` to skip cards whose orders have `payment_status` paid or processing.
- Batch fingerprint pre-check to reduce DB unique-violation errors.
- Schema v7: new indexes for delivered_order_id, (product_id, status, delivered_at), (batch_id, status).

### Boundary

Does not yet include: bulk card reissue, refund-based card void, low-stock email alerts, or database integration tests.

## 2026-06-26 Payment Lifecycle P0 Follow-Up

### Change

Hardened the v0.3 sandbox payment lifecycle around active-order reuse, return/delivery tokens, rate limiting, and callback acknowledgement.

### Scope

- Fixed active-order reuse so reusable local payment entries do not recreate upstream payment sessions with the same `out_trade_no`.
- Restricted reuse to non-expired orders with matching product version, amount, currency, gateway, and buyer.
- Preserved existing return tokens during reuse and added atomic `consumeReturnToken()` with `return_token_expires_at`.
- Rotated delivery tokens after return-token consumption and moved generated delivery access to HttpOnly cookies plus clean 303 delivery redirects.
- Fixed IP rate limiting to use 64-character nonce hashes and Typecho query-builder counts instead of `Db::quoteValue()`.
- Split payment confirmation from fulfillment so verified paid callbacks are acknowledged even when local entitlement/card delivery fails.
- Bumped schema version to `6` for the new return-token expiry column.

### Boundary

This change hardens lifecycle correctness but still does not implement refunds, low-stock notification, or manual card void/reissue workflows.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-26 Card-Code Fulfillment Loop

### Change

Implemented the card-code sales loop on top of the product and fulfillment foundation.

### Scope

- Added `CardCodeCipher` for AES-256-GCM encryption of card-code plaintext.
- Added `CardCodeService` for card-code import, duplicate fingerprinting, stock counts, reservation, delivery, release, and owner-only decryption.
- Added **TypechoPay → 商品与卡密** admin panel for creating products, importing card-code batches, checking inventory, and copying product shortcodes.
- Added `cardcode` fulfillment support in `FulfillmentManager`.
- Reserved card-code stock before creating the upstream payment session.
- Delivered the reserved card code after verified payment success and recorded `pay_fulfillments.card_item_id`.
- Released reserved but undelivered card stock when local creation fails or providers return terminal failed/cancelled/expired/closed states.
- Added `/action/typechopay?do=delivery` to let the order owner or poll-token holder view delivered card codes.
- Kept public order polling free of plaintext card data; it only exposes `has_card_delivery`.

### Boundary

This commit does not yet implement low-stock email, refund-driven `compromised` marking, manual void/reissue workflows, or a dedicated customer order center. Those should be handled in the next product-management pass.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-26 Product/Fulfillment Foundation

### Change

Added the v0.3 product and fulfillment foundation so paid-reading can evolve toward card-code and multi-deliverable products without coupling stock delivery into payment gateways.

### Scope

- Added `ProductService` so new `[typechopay product="..."]` / `[typechopay product_id="..."]` entries resolve current server-side product price at click time.
- Kept legacy `amount/currency` shortcodes working as a compatibility path, while documenting their stale-cache price limitation.
- Added product snapshot fields to orders: `product_id`, `product_key`, `product_version`, and `product_snapshot_json`.
- Split order state into `payment_status` and `fulfillment_status` while retaining legacy `status`.
- Added `pay_products`, `pay_product_deliverables`, `pay_fulfillments`, `pay_card_batches`, and `pay_card_items` schema.
- Added `FulfillmentManager` and fulfillment records for `post_access` / `content_block` entitlement delivery.
- Changed guest login recovery to claim both guest orders and entitlements.
- Removed runtime schema mutation from `OrderService`; schema changes now run through activation-time versioned migration.
- Stopped reusing pending orders, which keeps each order poll token stable for its lifetime.
- Replaced Alipay callback/query CNY amount parsing with string-based yuan-to-fen conversion.

### Boundary

Card-code fulfillment is completed in the next entry below. This foundation commit still did not include low-stock notifications, refund/revoke behavior, or manual void/reissue operations.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
composer install --no-dev --prefer-dist --no-interaction --no-progress
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

After deployment to an already-enabled Typecho site, disable and re-enable the plugin once, or otherwise invoke the activation migration, so schema version `4` and the new columns/tables are created.

## 2026-06-25 Configuration Compliance Follow-Up

### Change

Aligned payment setup documentation with the current gateway implementation and added minimal GitHub Actions CI.

### Scope

- Replaced the incorrect PayPay onboarding link with PayPay OPA / Dynamic QR documentation guidance.
- Clarified that Alipay currently supports ordinary public-key mode only, not public-key certificate mode.
- Clarified CNY shortcode amounts are written in cents and converted to yuan before calling Alipay.
- Updated plugin metadata version to `0.2.0`.
- Split the duplicated `0.1.0` changelog section into `0.1.1` and `0.1.0`.
- Removed config-page pseudo section fields so plugin settings do not persist meaningless section-marker values.
- Added `.github/workflows/ci.yml` to install dependencies, lint PHP files, and run `tests/*Test.php`.
- Suppressed third-party Alipay SDK deprecation notices while loading SDK files on PHP 8.
- Declared PHP `ext-curl`, installed curl in CI, and excluded `vendor/` from CI lint.
- Changed the settings help page to generate callback and return URLs through Typecho `Common::url()`.

### Verification

Local static checks:

- `git diff --check`
- Confirmed no incorrect PayPay onboarding links or pseudo section settings remain in `Plugin.php`.

Server-side checks should be run after pulling the pushed commit:

```sh
composer validate --no-check-lock --strict
composer install --no-dev --prefer-dist --no-interaction --no-progress
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## 2026-06-26 Payment Hardening Follow-Up

### Change

Hardened the payment lifecycle before real-money sandbox testing.

### Scope

- Made PayPay transaction webhooks compatible with official `merchant_order_id`, `order_id`, `order_amount`, `merchant_id`, and `notification_type` fields.
- Added per-order poll tokens and ownership checks for query and return endpoints.
- Changed article payment forms to call `do=prepare`, so fresh order data is generated at click time instead of embedding short-lived nonce values in cached HTML.
- Kept unknown create outcomes in `processing`; only definite local/configuration failures are marked `failed`.
- Synced provider terminal statuses to local orders.
- Added duplicate purchase prevention, stricter business target validation, formatted amount display, guest entitlement claim, and hardened guest cookies.
- Added `tests/PayPayWebhookTest.php` and expanded CI to run all `tests/*Test.php`.

### Verification

Run after pulling this change:

```sh
composer validate --no-check-lock --strict
composer install --no-dev --prefer-dist --no-interaction --no-progress
find . -path './vendor' -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l
for test in tests/*Test.php; do php "$test"; done
```

## Change

Initialized `TypechoPay` as a Typecho payment plugin under `usr/plugins/TypechoPay`.

## Scope

- Added plugin entry, action router, database schema creation, and admin order list.
- Added order service with amount/currency validation, notification events, and idempotent paid updates.
- Added PayPay, WeChat Pay, and Alipay gateway adapters.
- Added signed article payment shortcode.
- Added documentation for installation, security boundaries, architecture, callbacks, and verification.

## Security Notes

- No merchant credentials are hardcoded.
- Public payment entry fields are HMAC signed.
- Payment callbacks must pass provider signature validation before marking orders paid.
- Amount and currency are rechecked against the local order before paid status is written.
- Notification payloads are persisted for audit.

## Verification

Completed:

- Initialized this plugin directory as an independent Git repository because `/Users/mantou/Downloads/typecho` is not a Git repository.
- Created initial commit with message `feat: add typecho payment plugin skeleton`; use `git log --oneline -n 1` in this plugin directory for the current hash.

Blocked locally:

- No `php` executable was found in PATH or common Homebrew locations.
- No Typecho `config.inc.php` exists at the project root, so database migration cannot be exercised locally.

Recommended verification in a PHP-enabled environment:

```sh
php usr/plugins/TypechoPay/tests/SignerTest.php
find usr/plugins/TypechoPay -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Next Commit Message

```text
feat: add typecho payment plugin skeleton
```
