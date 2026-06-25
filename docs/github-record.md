# GitHub Record

Date: 2026-06-25

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
