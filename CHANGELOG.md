# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Full Arabic translation of the admin screens (808 of 808 strings).
- The plugin now tells WooCommerce it is tested up to WooCommerce 11.2, so stores see it as compatible.

### Fixed

- The WordPress.org package no longer contains a hidden .git folder from the build.

## [3.6.0] - 2026-11-05

### Added
- Requests to kwtSMS carry the integration name and version (no personal data) so kwtSMS can see which integrations are in use.
- Arabic translation for the checkout phone verification messages and the checkout SMS template settings.

### Changed
- Checkout phone verification has its own SMS template in English and Arabic (kwtSMS > Templates), so the code is no longer sent with the login wording.

### Fixed
- Checkout phone verification now also works with the WooCommerce Checkout block (the default checkout since WooCommerce 8.3).
- Plain ASCII punctuation in all messages and texts, so no SMS is switched to the more expensive Unicode encoding by a dash or curly quote.

### Docs
- ERR028 visitor message no longer claims a fixed 15-second wait: duplicate SMS protection is opt-in per account with a configurable window (10 seconds by default)
- Example phone numbers in placeholders, help text, error messages and translations now use the placeholder 965XXXXXXXX instead of a real-looking number

## [3.5.2] - 2026-03-25

### Fixed
- Fix: sms_enabled was overwritten to 0 by the gateway sanitize callback (no checkbox on the gateway form). Removed sms_enabled from gateway sanitize; general settings is now the single source of truth.
- Fix: send() now reads sms_enabled from general settings only with default=1, removing the broken gateway fallback.
- Fix: Remember Me checkbox now forwarded through the OTP flow. Added a "Remember Me" checkbox to the OTP verification form so the auth cookie respects the user's choice.
- Fix: replaced global $profileuser usage with wp_parse_url approach to avoid WP.org prefix scanner false positive.
- Fix: uninstall.php now includes ABSPATH guard before WP_UNINSTALL_PLUGIN check.
- Fix: WP.org directory assets (screenshots, banners, icons) moved to wporg-assets/ and excluded from the plugin zip.
- Fix: sms_enabled added to the general settings sanitize callback (was missing, causing the value to never persist).

### Changed
- External Services section in readme simplified (concise format with inline links).
- Help links cleaned up: removed Integration Test Checklist, Best Practices, and API Documentation PDF links.
- WooCommerce advanced features (stock alerts, back-in-stock, cart abandonment, instant order, multivendor) added to readme feature list.

## [3.5.1] - 2026-03-22

### Fixed
- Security: back-in-stock subscribe nonce changed to a static action so product_id is never read before authentication.
- Security: all nonce values now passed through sanitize_key() before wp_verify_nonce().
- Security: sanitize_url() replaced with esc_url_raw().
- Security: absint() calls on POST user_id now include wp_unslash().
- Security: GET page parameter compared using sanitize_key() in log export handler.
- Security: printf output in SMS history log now escapes at point of output.

### Added
- Clear Log button added to debug log tab.

### Removed
- Elementor Pro Forms and Gravity Forms integrations removed (not ready for WordPress.org review).

## [3.3.3] - 2026-03-13

### Fixed
- Local phone numbers with a trunk prefix (single leading zero, e.g. Saudi `0559...`, UAE `050...`) are now correctly normalized: the trunk digit is stripped before the local-number length check, and the threshold is raised from 8 to 9 digits to cover 9-digit local formats. Matches the TypeScript `normalize()` implementation.

## [3.3.2] - 2026-03-13

### Added
- Country-specific phone number validation: `normalize_phone()` now checks local digit length and mobile starting digits for 70+ countries (GCC, Levant, MENA, Europe, Asia, Americas, Africa, Oceania). Rules are kept in sync with the TypeScript PHONE_RULES table. Numbers from countries not in the table continue to pass through with generic E.164 length validation only.

### Fixed
- Security: OTP codes are now stored as HMAC-SHA256 hashes in transients instead of plaintext. A database read can no longer reveal active OTP values.
- Security: Cart abandonment read-modify-write operations are now protected by a MySQL advisory lock, preventing concurrent race conditions.
- Security: Debug log and CSV export responses now include `X-Content-Type-Options: nosniff` header.
- Security: Private IP detection in rate limiting now uses correct `172.16.0.0/12` CIDR instead of the overly broad `172.` prefix match, which incorrectly treated `172.0.0.0`-`172.15.255.255` as private.
- Security: API password is now saved with `wp_unslash()` only (not `sanitize_text_field()`), preserving special characters such as `<`, `>`, `&`, `"`, and `'` that `sanitize_text_field()` stripped.
- Security: Checkout OTP send path now enforces per-phone and per-IP rate limits before issuing a code.
- Security: Cart recovery coupon codes now use `bin2hex(random_bytes(4))` for cryptographically secure entropy instead of a truncated MD5 hash.
- Added missing `is_ip_in_cidr()` method to `KwtSMS_OTP_Engine` that was referenced but never defined, causing a fatal error on every OTP attempt.

## [3.3.1] - 2026-03-13

### Added
- Elementor Pro and Gravity Forms form integrations are now fully active: removed "coming soon" status, both appear as live integrations on the Integrations overview page with install/activate/configure actions.

### Fixed
- Admin phone fields (order status, instant order, stock alerts, admin alerts) no longer send duplicate SMS when the same number is entered in both local and international format. Normalized phones are deduplicated before sending.
- `woo_admin_phone` (order status admin notification) now accepts space-separated phone numbers in addition to comma-separated, consistent with all other admin phone fields.
- WooCommerce sub-tab settings (stock alerts, multivendor, cart abandonment) were reset to defaults whenever the parent WooCommerce tab was saved, because unrendered checkboxes produce no POST data. Each sub-section now only updates its own fields when its specific tab is saved.
- Cart abandonment records with `recovered=true` were deleted when the cart was emptied after a successful purchase, losing recovery stats.
- Checkout OTP first-submit notice type changed from `notice` to `error` so WooCommerce correctly halts order creation while the customer retrieves their OTP code.
- Instant order and vendor SMS now also fire for WooCommerce block checkout orders via `woocommerce_store_api_checkout_order_processed`.
- Default SMS templates are now applied when saved template values are empty strings, ensuring out-of-box SMS content without requiring manual template entry.

## [3.3.0] - 2026-03-12

### Added
- WooCommerce HPOS (High-Performance Order Storage) compatibility declaration.
- COD-only OTP gate option: require phone verification only for Cash on Delivery orders.
- Stock alert SMS notifications: low stock, out of stock, and backorder events sent to the configured admin phone.
- New product published SMS notification to admin.
- Back-in-stock subscriber notifications: customers opt in on the product page, SMS sent to all subscribers when stock is restored.
- Instant new order SMS alert, fires once per order regardless of payment method or initial status.
- Multivendor support: sends order SMS to the relevant vendor on Dokan, WCFM, or WC Vendors.
- Cart abandonment recovery SMS with optional percentage-off coupon code generation (single-use, configurable expiry).
- Cart abandonment dashboard card showing total tracked carts, SMS sent count, recovered orders, and recovery rate.

## [3.2.0] - 2026-03-11

### Added
- Admin Site Alerts: configurable SMS notifications to admin phone on new user registration, login, post publish, comment, and WordPress core update events.
- Each alert has an individual enable/disable toggle and independent English and Arabic message templates.
- New "Admin Alerts" settings page under the kwtSMS admin menu.

## [3.0.4] - 2026-03-09

### Added
- GitHub Actions CI workflow: PHPCS, PHPStan, and PHPUnit run automatically on push and pull requests across PHP 8.1, 8.2, and 8.3.
- Automated plugin zip release: pushing a version tag (`vX.Y.Z`) now triggers a GitHub Actions workflow that builds and publishes the release zip.
- Branch protection rules and PR template for the repository.

### Fixed
- PHPStan false positives in admin view files: suppressed `$this might not be defined`, defensive null-coalescing, offset-always-exists, and left-side-always-true errors that arise because view templates are included inside class methods.
- PHPUnit test assertions updated to reflect `wpcf7_submit` hook (changed from `wpcf7_mail_sent` in 3.0.3).
- PHPUnit test for Gravity Forms updated to match "Coming soon" display (no tab ID).
- Test placeholders: replaced real API username with `wp_username` throughout test files for client identification.

## [3.0.3] - 2026-03-08

### Fixed
- Password reset OTP SMS now sent correctly even when login OTP cooldown is active for the same user (cooldown is now scoped per action type).
- WooCommerce order total placeholder `{total}` no longer contains HTML entities in SMS messages.
- CF7 gate mode form auto-submit after OTP verification no longer throws TypeError when pendingForm is null.
- WPForms gate mode phone field detection now checks label text (WPForms uses non-standard input names).
- Settings `get()` method now correctly returns `$fallback` instead of undefined `$default` variable.
- Country code dropdown on SMS login page is now properly sized (constrained width, phone field takes remaining space).
- Admin notices and warnings from other plugins (e.g. Action Scheduler) now display above the kwtSMS logo header, not beside it.
- Admin sub-menu page hiding now uses CSS/JS and redirect instead of `remove_submenu_page`, preventing redirect loops.
- Users Without Phone page: menu count badge updates dynamically without page reload.

### Changed
- CF7 notification mode now sends SMS even when SMTP email delivery fails (hooks `wpcf7_submit` instead of `wpcf7_mail_sent`).
- Integrations page notes Elementor Pro requirement for form widgets.
- Integrations page notes Ninja Forms phone field configuration requirement.
- Gravity Forms shown on Integrations page as "Coming soon".

## [3.0.2] - 2026-03-07

### Fixed
- Removed tab navigation from form integration pages (CF7, WPForms, Elementor, Gravity Forms, Ninja Forms); both settings cards are now always visible.
- Enable Integration toggle moved into settings table, consistent across all form integrations.
- Restored left padding on WPForms admin pages stripped by WPForms.
- Suppressed WPForms injected header, flyout, and footer on kwtSMS integration pages.
- Hidden page footer on all kwtSMS admin pages.

## [3.0.1] - 2026-03-06

### Fixed
- Resolved all PHP_CodeSniffer WordPress Coding Standards violations.
- Expanded country codes data to full 250-country list.
- Alignment and spacing in OTP and passwordless login views.

## [3.0.0] - 2026-03-05

### Added
- Plugin bootstrap with activation, deactivation, and uninstall hooks.
- kwtSMS API client (`KwtSMS_API`) with `send()`, `verify()`, `balance()`, `sender_ids()`, `coverage()`.
- Settings storage (`KwtSMS_Settings`) backed by `wp_options`, with `DEFAULTS` and typed getters.
- Gateway settings page: API credentials input with live verification and Sender ID dropdown auto-population.
- General settings page: OTP mode (2FA, Passwordless, Both), code length, expiry, rate limits, CAPTCHA.
- Templates page: bilingual English and Arabic SMS templates with live character counter and page indicator.
- 2FA login: password login followed by a 6-digit SMS OTP step.
- Passwordless login: phone number entry followed by OTP, no password required.
- Password reset via OTP: replaces the email reset link with an SMS verification flow.
- Per-role OTP enforcement: choose which user roles require OTP, with administrator exclusion by default.
- Sliding-window rate limiting per phone number, per IP address, and per user account.
- Phone blocking list: silently drop OTP requests from blocked numbers.
- Google reCAPTCHA v3 integration for bot protection on OTP forms.
- Cloudflare Turnstile integration as alternative bot protection.
- Country code dropdown on login forms with GCC and custom country list support.
- Emergency bypass constant `KWTSMS_OTP_DISABLED` in `wp-config.php` for lockout recovery.
- WooCommerce integration: 7 order status SMS notifications (Processing, On-Hold, Completed, Cancelled, Pending Payment, Refunded, Failed).
- WooCommerce checkout OTP gate: require phone verification before order placement.
- Per-order admin SMS metabox: send a custom SMS to the customer from the order edit screen.
- Admin SMS notification: notify a configurable phone on any order status change.
- HPOS (High-Performance Order Storage) compatibility.
- Contact Form 7 integration: Notification and OTP Gate modes.
- WPForms integration: Notification and OTP Gate modes.
- Elementor Pro integration: Notification and OTP Gate modes.
- Gravity Forms integration: Notification and OTP Gate modes.
- Ninja Forms integration: Notification and OTP Gate modes.
- Users Without Phone admin sub-page with dynamic menu count badge.
- OTP send log (last 100 entries) on Logs page.
- Dashboard widget with today's send count.
- Help page with balance display, FAQ links, and support resources.
- Developer action/filter hooks: `kwtsms_otp_before_send`, `kwtsms_otp_message`, `kwtsms_otp_phone_number`, `kwtsms_otp_verified`, `kwtsms_otp_send_failed`.
- English (en_US) and Arabic (ar) translations with RTL admin support.
- PHPUnit 9 + Brain\Monkey test suite (191 tests).
- CodeQL security scanning and Dependabot dependency updates.
- `uninstall.php` that removes all plugin data on deletion.

[3.3.1]: https://github.com/boxlinknet/kwtsms-wordpress/compare/v3.3.0...v3.3.1
[3.0.4]: https://github.com/boxlinknet/kwtsms-wordpress/compare/v3.0.3...v3.0.4
[3.0.3]: https://github.com/boxlinknet/kwtsms-wordpress/compare/v3.0.2...v3.0.3
[3.0.2]: https://github.com/boxlinknet/kwtsms-wordpress/compare/v3.0.1...v3.0.2
[3.0.1]: https://github.com/boxlinknet/kwtsms-wordpress/compare/v3.0.0...v3.0.1
[3.0.0]: https://github.com/boxlinknet/kwtsms-wordpress/releases/tag/v3.0.0
