=== kwtSMS: OTP & SMS Notifications ===
Contributors: kwtsms
Tags: sms, otp, authentication, woocommerce, login
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.6.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

SMS OTP login, password reset, and WooCommerce order notifications via the kwtSMS gateway. Arabic RTL support included.

== Description ==

**kwtSMS** replaces or supplements WordPress passwords with SMS one-time codes, sends WooCommerce order updates automatically, and lets you verify phone numbers on any contact form. Built on the [kwtSMS](https://www.kwtsms.com) SMS gateway.

Built for Arabic-speaking markets (Kuwait, Saudi Arabia, UAE, Bahrain, Qatar, Oman) with full RTL admin support and bilingual SMS templates in English and Arabic.

= Authentication =

* **2FA Mode:** users log in with username + password, then confirm with a 6-digit SMS code
* **Passwordless Mode:** users enter their phone number and receive an OTP to log in directly, no password needed
* **Both Modes:** let each user choose their preferred method
* **Password Reset via SMS:** replace the email link with an SMS OTP verification flow
* **Role-Based Enforcement:** configure which user roles must pass OTP (exclude administrators, apply only to customers, etc.)
* **Welcome SMS:** send a customisable welcome message when a new user registers
* **Country Code Dropdown:** restrict the dial-code selector on login forms to GCC countries or a custom list

= WooCommerce Integration =

* **7 order status notifications:** Processing, On-Hold (awaiting payment), Completed, Cancelled, Pending Payment, Refunded, Failed
* **Admin order notifications:** automatically notify a configurable admin phone number on any order status change
* **Checkout OTP Gate:** require phone verification before the customer can place an order, on the classic checkout and the Checkout block (all methods or COD-only)
* **Per-status templates:** independent English + Arabic SMS template for every order status
* **Admin SMS panel:** send a custom free-text SMS to any order's customer from the order edit screen
* **Stock alerts:** low stock, out of stock, and backorder notifications to admin
* **New product SMS:** notify admin when a product is first published
* **Back-in-stock notifications:** customers subscribe on out-of-stock product pages, SMS sent automatically on restock
* **Instant new order SMS:** notify admin the moment an order is placed, before status processing
* **Cart abandonment recovery:** detect abandoned carts, send recovery SMS with auto-generated coupon codes, track recovery stats on the dashboard
* **Multivendor support:** per-vendor order SMS for Dokan, WCFM, and WC Vendors
* HPOS (High-Performance Order Storage) compatible

= Contact Form Integrations =

Each integration supports two modes: **Notification** (send a confirmation SMS on submit) and **OTP Gate** (block submission until the phone number is verified):

* Contact Form 7
* WPForms
* Ninja Forms

= Security =

* Sliding-window rate limiting per phone number, per IP address, and per user account
* Duplicate OTP guard: reuses existing valid OTP on double-click or page reload
* IP Allowlist/Blocklist with CIDR support for IPv4 and IPv6
* IPHub proxy/VPN detection (optional): silently block or flag OTP requests from known proxies
* Registration OTP gate: verify phone via OTP before account creation
* Trusted Devices: trust a device for 30 days after 2FA, with profile revoke controls
* Phone blocking list: silently drop OTP requests from blocked numbers (anti-enumeration)
* Attempt lockout after configurable max failures
* Google reCAPTCHA v3 and Cloudflare Turnstile support
* All credentials stored server-side, never output to HTML
* Nonces on every form and AJAX action
* Anti-enumeration: password reset never reveals whether an account exists

= External Services =

This plugin connects to the following external services:

**1. kwtSMS API** (required): sends all SMS messages. [kwtsms.com](https://www.kwtsms.com) | [Terms](https://www.kwtsms.com/policy.html) | [Privacy](https://www.kwtsms.com/privacy.html)

Requests to kwtSMS carry the integration name and version (no personal data) so kwtSMS can see which integrations are in use.

**2. ipapi.co** (optional): detects visitor country for dial-code pre-selection. [ipapi.co](https://ipapi.co) | [Terms](https://ipapi.co/terms/) | [Privacy](https://ipapi.co/privacy/)

**3. IPHub** (optional): proxy/VPN detection on OTP requests. [iphub.info](https://iphub.info) | [Terms](https://iphub.info/legal) | [Privacy](https://iphub.info/legal)

**4. Google reCAPTCHA v3** (optional): bot protection on OTP forms. [google.com/recaptcha](https://www.google.com/recaptcha/) | [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy)

**5. Cloudflare Turnstile** (optional): alternative bot protection. [cloudflare.com/turnstile](https://www.cloudflare.com/products/turnstile/) | [Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/)

= Admin =

* **Users Without Phone** sub-page under the WordPress Users menu: lists all registered users missing a phone number, with a dynamic count badge on the menu item

= Test Mode =

Enable **Test Mode** in the Gateway settings to test without receiving real SMS messages. Messages are queued on the kwtSMS server but never delivered to the phone. Credits are still deducted. To recover them, log in to your kwtSMS account dashboard and delete the queued messages. The OTP code is visible under kwtSMS > Logs > Debug Log so you can complete flows during development.

= Languages =

Ships with English (default) and Arabic translations. The plugin admin UI and all user-facing strings are fully translatable.

== Installation ==

= Method 1: WordPress Plugin Directory =

1. In your WordPress admin, go to **Plugins > Add New Plugin**.
2. Search for **kwtSMS**.
3. Click **Install Now** next to "kwtSMS: OTP & SMS Notifications".
4. Click **Activate**.
5. Proceed to the configuration steps below.

= Method 2: Upload via WordPress Admin =

1. Download the plugin zip file from the [GitHub releases page](https://github.com/kwtsms/kwtsms-wordpress/releases).
2. In your WordPress admin, go to **Plugins > Add New Plugin**.
3. Click the **Upload Plugin** button at the top of the page.
4. Click **Choose File**, select the downloaded `wp-kwtsms-x.x.x.zip` file, and click **Install Now**.
5. Click **Activate Plugin**.
6. Proceed to the configuration steps below.

= Method 3: Manual FTP / SFTP Upload =

1. Download the plugin zip file from the [GitHub releases page](https://github.com/kwtsms/kwtsms-wordpress/releases).
2. Unzip the file on your computer. You will get a folder named `wp-kwtsms`.
3. Upload the `wp-kwtsms` folder to `/wp-content/plugins/` on your server using an FTP or SFTP client (FileZilla, Cyberduck, etc.).
4. In your WordPress admin, go to **Plugins > Installed Plugins**.
5. Find **kwtSMS: OTP & SMS Notifications** and click **Activate**.
6. Proceed to the configuration steps below.

= Configuration (all methods) =

After activating the plugin:

1. Go to **kwtSMS > Gateway** in the WordPress admin menu.
2. Enter your **kwtSMS API Username** and **API Password** (find these in your kwtSMS account under Account > API Settings, not your login password).
3. Click **Login** to verify credentials. The plugin calls the kwtSMS API and populates the Sender ID dropdown.
4. Select your **Sender ID** from the dropdown and click **Save Settings**.
5. Go to **kwtSMS > General** and select your OTP mode: **2FA** (password + SMS code), **Passwordless** (phone number + SMS code only), or **Both** (let each user choose).
6. Go to **kwtSMS > Templates** to customize your SMS messages in English and Arabic.

= WooCommerce Order Notifications =

Go to **kwtSMS > Integrations > WooCommerce** and enable the order status notifications you want. Each status has its own English and Arabic SMS template.

== Frequently Asked Questions ==

= Do I need a kwtSMS account? =

Yes. You need an active kwtSMS account with API access. Sign up at [kwtsms.com](https://www.kwtsms.com). API credentials (username and password) are entered in the Gateway settings page.

= What is the difference between Test Mode and Live Mode? =

In **Test Mode** (enabled in Gateway Settings), messages are queued on the kwtSMS server but never delivered to the recipient's phone. Credits are still deducted. To recover them, log in to your kwtSMS account dashboard and delete the queued messages from the outbox. The OTP code is visible under kwtSMS > Logs > Debug Log so you can complete authentication flows during development without a real phone. In **Live Mode**, the SMS is delivered to the recipient's phone and credits are deducted. Always develop with Test Mode on, then disable it before going live.

= Does the plugin work without WooCommerce? =

Yes. WooCommerce is fully optional. All login, password reset, and contact form features work on any WordPress site.

= How do I unlock an admin who is locked out due to OTP? =

Add this line to your `wp-config.php`:

`define( 'KWTSMS_OTP_DISABLED', true );`

This bypasses all OTP checks and restores the standard WordPress login. Remove the line once you have regained access.

= My SMS status shows OK but the recipient did not receive the message. What happened? =

Check the Sending Queue at [kwtsms.com](https://www.kwtsms.com/login/). If your message is stuck there, it was accepted by the API but not dispatched. Common causes are emoji in the message, hidden characters from copy-pasting (from Word, PDF, or rich editors), or spam filter triggers. Delete the stuck message from the queue to recover your credits. Also verify that Test Mode is off in Gateway Settings: in test mode, messages are queued but never delivered to the handset.

= What is a Sender ID and why should I not use the shared KWT-SMS sender? =

A Sender ID is the name that appears as the sender on the recipient's phone (for example, MY-APP instead of a random number). `KWT-SMS` is a shared test sender. It causes delivery delays and is blocked on Virgin Kuwait. Register a private Sender ID through your kwtSMS account. For OTP and authentication messages, you need a **Transactional** Sender ID, which bypasses DND (Do Not Disturb) filtering on Zain and Ooredoo. Promotional Sender IDs are filtered, meaning OTP messages can silently fail while credits are still deducted.

= I am getting an authentication error when I save my credentials. What should I check? =

The plugin requires your **API username and API password**, not your account mobile number or your login password. Log in to [kwtsms.com](https://www.kwtsms.com/login/), go to Account > API settings, and verify your API credentials. Credentials are case-sensitive. Make sure there are no extra spaces when copying and pasting.

= Can I send SMS to numbers outside Kuwait? =

International sending is disabled by default on kwtSMS accounts. Log in to your kwtSMS account and activate coverage for the countries you need. Visit the kwtSMS dashboard to view and manage your active country coverage. Note that enabling international coverage increases exposure to automated abuse, so rate limiting is strongly recommended before enabling it.

== Help & Support ==

* **[kwtSMS FAQ](https://www.kwtsms.com/faq/)**: Answers to common questions about credits, sender IDs, OTP, and delivery.
* **[kwtSMS Support](https://www.kwtsms.com/support.html)**: Open a support ticket or browse help articles.
* **[Contact kwtSMS](https://www.kwtsms.com/#contact)**: Reach the kwtSMS team directly for Sender ID registration and account issues.
* **[Sender ID Help](https://www.kwtsms.com/sender-id-help.html)**: Sender ID registration and guidelines.
* **[kwtSMS Dashboard](https://www.kwtsms.com/login/)**: Recharge credits, buy Sender IDs, view message logs, and manage coverage.
* **[Other Integrations](https://www.kwtsms.com/integrations.html)**: Plugins and integrations for other platforms and languages.
* **[Plugin Issues](https://github.com/kwtsms/kwtsms-wordpress/issues)**: Report bugs or request features for this WordPress plugin.

== Screenshots ==

1. Gateway settings: API credentials, live account balance, Sender ID dropdown, and Test Mode toggle.
2. SMS Templates in Arabic (RTL): bilingual template editor with live character counter and SMS page indicator.
3. Two-Step Verification screen: OTP entry step shown after successful password login (2FA mode).
4. Passwordless login: phone number entry with country code selector, no password required.
5. Password reset via OTP: SMS verification step that replaces the default email reset link.
6. WooCommerce integration: per-status SMS templates and checkout OTP gate configuration.
7. Integrations overview: WooCommerce, Contact Form 7, WPForms, and Ninja Forms.
8. SMS Logs: full send history with date, Sender ID, message preview, phone, type, status, and API response.

== Changelog ==

= 3.6.1 =
* Fix: the WooCommerce On-Hold SMS now says the order is waiting for payment confirmation. It used to say the order was shipped. If you never edited that text, it is updated for you; your own wording is kept.
* New: the admin screens are fully translated into Arabic.
* New: the plugin declares compatibility with WooCommerce up to 11.2.
* Fix: the WordPress.org package no longer contains a hidden .git folder.

= 3.6.0 =
* Fix: checkout phone verification now works with the WooCommerce Checkout block (the default checkout since WooCommerce 8.3).
* New: checkout phone verification has its own SMS template in English and Arabic (kwtSMS > Templates), so the code is no longer sent with the login wording.
* New: Arabic translation for the checkout phone verification messages and the checkout template settings.
* New: requests to kwtSMS carry the plugin name and version (no personal data).
* Change: duplicate SMS (ERR028) message no longer promises a fixed 15 second wait.
* Change: example phone numbers in placeholders, help text and translations now use 965XXXXXXXX.

= 3.5.2 =
* Fix: SMS sending was silently disabled after saving Gateway settings (sms_enabled overwritten to 0).
* Fix: Remember Me checkbox now forwarded through the OTP verification flow.
* Fix: replaced global $profileuser with prefixed alternative for WP.org compliance.
* Fix: uninstall.php ABSPATH guard added.
* Fix: WP.org directory assets removed from plugin zip.
* Enhancement: WooCommerce advanced features added to readme (stock alerts, cart abandonment, multivendor, etc.).
* Enhancement: External Services section simplified.

= 3.5.1 =
* Security: back-in-stock subscribe nonce changed to a static action so product_id is never read before authentication.
* Security: all nonce values now passed through sanitize_key() before wp_verify_nonce(), per WordPress security documentation.
* Security: sanitize_url() replaced with esc_url_raw() (sanitize_url was deprecated in WP 6.1).
* Security: absint() calls on POST user_id now include wp_unslash() per WPCS.
* Security: GET page parameter compared using sanitize_key() in the log export handler.
* Security: printf output in SMS history log now escapes at the point of output instead of pre-escaping the variable.
* Feature: Clear Log button added to debug log tab; implements the clear_debug_log handler in handle_log_exports().
* Removed: Elementor Pro Forms and Gravity Forms integrations removed (not ready for WordPress.org review).

= 3.3.3 =
* Fix: Local phone numbers with a trunk prefix (e.g. Saudi 0559..., UAE 050...) are now correctly normalized by stripping the trunk digit and raising the local-number threshold to 9 digits.

= 3.3.2 =
* New: Country-specific phone validation for 70+ countries: local digit length and mobile prefix are now checked inside normalize_phone(), giving callers meaningful error messages instead of generic rejections.
* Fix: OTP codes are now stored as HMAC-SHA256 hashes in transients; a database read can no longer reveal active OTP values.
* Fix: Cart abandonment operations are now protected by a MySQL advisory lock to prevent concurrent race conditions.
* Fix: Debug log and CSV export downloads now include the X-Content-Type-Options: nosniff header.
* Fix: Private IP detection in rate limiting now uses the correct 172.16.0.0/12 CIDR range.
* Fix: API password special characters are now preserved correctly on save.
* Fix: Checkout OTP send now enforces per-phone and per-IP rate limits.
* Fix: Cart recovery coupon codes now use a cryptographically secure random source.
* Fix: Added missing is_ip_in_cidr() method that was causing a fatal error on every OTP attempt.

= 3.3.1 =
* New: Elementor Pro and Gravity Forms integrations are now fully active (removed "coming soon" status).
* Fix: Admin phone fields (order status, instant order, stock alerts, admin alerts) no longer send duplicate SMS when the same number appears in both local and international format.
* Fix: woo_admin_phone (order status admin notification) now accepts space-separated phone numbers, consistent with all other admin phone fields.
* Fix: WooCommerce sub-tab settings (stock alerts, multivendor, cart abandonment) were reset to defaults whenever the parent WooCommerce tab was saved, because unrendered checkboxes produce no POST data. Each sub-section now only updates its own fields when its specific tab is saved.
* Fix: Cart abandonment records with recovered=true were deleted when the cart was emptied after a successful purchase, losing recovery stats.
* Fix: Checkout OTP first-submit notice type changed from notice to error so WooCommerce correctly halts order creation while the customer retrieves their OTP code.
* Fix: Instant order and vendor SMS now also fire for WooCommerce block checkout orders via woocommerce_store_api_checkout_order_processed.
* Fix: Default SMS templates are now applied when saved template values are empty strings, ensuring out-of-box SMS content without requiring manual template entry.

= 3.3.0 =
* New: WooCommerce HPOS (High-Performance Order Storage) compatibility declaration
* New: COD-only OTP gate option: require OTP only for Cash on Delivery orders
* New: Stock alert SMS: low stock, out of stock, backorder notifications to admin
* New: New product published SMS notification to admin
* New: Back-in-stock subscriber notifications: customers opt-in, SMS sent on restock
* New: Instant new order SMS (fires once per order regardless of status)
* New: Multivendor support: vendor SMS for Dokan, WCFM, WC Vendors
* New: Cart abandonment recovery SMS with coupon code generation
* New: Cart abandonment dashboard card with recovery stats

= 3.2.0 =
* Added: Admin Site Alerts: send SMS to admin phone(s) on new user registration, login, post publish, comment, and WordPress core update events.
* Each alert is individually toggleable with configurable EN and AR message templates.
* New "Admin Alerts" settings page under the kwtSMS admin menu.

= 3.1.7 =
* Security: Trusted Devices. After completing 2FA, users can trust their device for 30 days. Trusted devices skip the OTP step on subsequent logins. Tokens stored as SHA-256 hashes in user meta, never raw. Profile page shows all trusted devices with individual and bulk revoke. All trusted devices are cleared on password reset.

= 3.1.6 =
* Security: Registration OTP Gate. Verify phone number via OTP before the WordPress account is created. Supports disabled, optional, and required modes. Works for standard WordPress registration and WooCommerce My Account registration.

= 3.1.5 =
* Security: IPHub Proxy/VPN Detection. OTP requests from known proxy or VPN IPs can be silently blocked or flagged based on admin-configured actions per block level. Result cached per IP with configurable TTL. Allowlisted IPs bypass the check entirely.

= 3.1.4 =
* Security: IP Allowlist/Blocklist with CIDR support. Admin textareas for IPv4/IPv6 address and CIDR ranges. Allowlisted IPs bypass per-IP rate limiting; blocklisted IPs receive a silent refusal indistinguishable from a rate-limit error.

= 3.1.3 =
* Security: Verify sliding-window rate limiting: per-phone, per-IP, and per-account limiters use timestamp arrays that self-prune, making fixed-window boundary exploits impossible.

= 3.1.2 =
* Security: Verify duplicate OTP guard: generate() reuses existing valid OTP and resets expiry clock to prevent double-SMS on double-click or page refresh.

= 3.1.1 =
* Enhancement: Dashboard widget now pinned to the right column for all users.
* Enhancement: kwtSMS Dashboard link added to the dashboard widget for quick credit and log access.
* Enhancement: "View full log" and "kwtSMS Dashboard" links placed on the same line in the widget.
* Fix: Replace em-dash HTML entity separators with pipe/colon in admin balance bar and form integration settings.
* Fix: Login page CSS improvements: button sizing, OTP code input focus style, back link alignment, form box-shadow removal.
* Fix: Logs page tab parameter now validated against a whitelist to prevent unexpected tab values.
* Security: Update security contact email to support@kwtsms.com.
* CI: Remove PHPUnit job from GitHub Actions (tests run locally).

= 3.0.4 =
* CI: Added GitHub Actions workflow for PHPCS, PHPStan, and PHPUnit across PHP 8.1, 8.2, and 8.3.
* CI: Automated plugin zip release on version tag push via GitHub Actions.
* Fix: Replace real API username placeholder with wp_username in tests for client identification.
* Fix: PHPStan false positives in admin view files (variable $this, defensive null-coalescing, offset checks).
* Fix: PHPUnit test for CF7 hook name updated to match wpcf7_submit (changed in 3.0.3).
* Fix: PHPUnit test for Gravity Forms tab updated to reflect "Coming soon" status.

= 3.0.3 =
* Fix: password reset OTP SMS now sent correctly even when login OTP cooldown is active for the same user (cooldown is now scoped per action type).
* Fix: WooCommerce order total placeholder {total} no longer contains HTML entities in SMS messages.
* Fix: CF7 gate mode form auto-submit after OTP verification no longer throws TypeError when pendingForm is null.
* Fix: WPForms gate mode phone field detection now checks label text (WPForms uses non-standard input names).
* Fix: Settings get() method now correctly returns $fallback instead of undefined $default variable.
* Fix: Country code dropdown on SMS login page is now properly sized (constrained width, phone field takes remaining space).
* Fix: Admin notices and warnings from other plugins (e.g. Action Scheduler) now display above the kwtSMS logo header, not beside it.
* Fix: Admin sub-menu page hiding now uses CSS/JS and redirect instead of remove_submenu_page, preventing redirect loops.
* Fix: Users Without Phone page menu count badge now updates dynamically without a page reload.
* Enhancement: CF7 notification mode now sends SMS even when SMTP email delivery fails (hooks wpcf7_submit instead of wpcf7_mail_sent).
* Enhancement: Integrations page notes Elementor Pro requirement for form widgets.
* Enhancement: Integrations page notes Ninja Forms phone field configuration requirement.
* Enhancement: Gravity Forms shown on Integrations page as "Coming soon".

= 3.0.2 =
* Fix: remove tab navigation from form integration pages (CF7, WPForms, Elementor, Gravity Forms, Ninja Forms), both cards now always visible.
* Fix: move Enable Integration toggle into settings table, consistent across all form integrations.
* Fix: restore left padding on WPForms admin pages stripped by WPForms.
* Fix: suppress WPForms injected header, flyout, and footer on kwtSMS integration pages.
* Fix: hide page footer on all kwtSMS admin pages.
* Docs: remove em dashes and separator hyphens from prose in readme.txt and README.md.

= 3.0.1 =
* Fix: resolve all PHP_CodeSniffer WordPress Coding Standards violations.
* Fix: expand country codes data to full 250-country list.
* Fix: alignment and spacing in OTP and passwordless login views.

= 3.0.0 =
* Initial public release.

== Upgrade Notice ==

= 3.6.1 =
The On-Hold order SMS no longer says the order was shipped. Check kwtSMS > WooCommerce if you changed that text yourself.

= 3.6.0 =
Checkout phone verification now works with the WooCommerce Checkout block; a separate checkout SMS template was added.

= 3.0.0 =
Initial release. No upgrade required.
