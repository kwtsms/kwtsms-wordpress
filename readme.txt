=== kwtSMS: OTP & SMS Notifications ===
Contributors: kwtsms
Tags: sms, otp, authentication, woocommerce, login
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.6.3
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

**3. IPHub** (optional): proxy/VPN detection on OTP requests. [iphub.info](https://iphub.info) | [Terms](https://iphub.info/legal/terms) | [Privacy](https://iphub.info/legal/privacy)

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

= 3.6.3 =
* New: login setting for users without a phone number.
* New: SMS login can be limited to confirmed phone numbers.
* Bug fixes.

= 3.6.2 =
* Bug fixes.

= 3.6.1 =
* Change: the WooCommerce On-Hold SMS now says the order is waiting for payment confirmation.
* New: admin screens in Arabic.
* New: compatible with WooCommerce up to 11.2.
* Bug fixes.

= 3.6.0 =
* New: checkout phone verification works with the WooCommerce Checkout block.
* New: a separate checkout SMS template in English and Arabic.
* Bug fixes.

= 3.5.2 =
* Bug fixes.

= 3.5.1 =
* New: Clear Log button on the debug log tab.
* Removed: Elementor Pro Forms and Gravity Forms integrations.
* Bug fixes.

= 3.3.3 =
* Bug fixes.

= 3.3.2 =
* New: phone number checks for 70+ countries.
* Bug fixes.

= 3.3.1 =
* Bug fixes.

= 3.3.0 =
* New: WooCommerce HPOS compatibility.
* New: OTP for Cash on Delivery orders only.
* New: stock alert, new product and back-in-stock SMS.
* New: instant new order SMS.
* New: multivendor SMS for Dokan, WCFM and WC Vendors.
* New: cart abandonment SMS with recovery stats.

= 3.2.0 =
* New: admin site alerts by SMS.

= 3.1.7 =
* New: trusted devices for 2FA.

= 3.1.6 =
* New: phone verification at registration.

= 3.1.5 =
* New: proxy and VPN detection.

= 3.1.4 =
* New: IP allow and block lists.

= 3.1.3 =
* Improvements.

= 3.1.2 =
* Improvements.

= 3.1.1 =
* Dashboard widget improvements.
* Bug fixes.

= 3.0.4 =
* Bug fixes.

= 3.0.3 =
* Bug fixes and improvements.

= 3.0.2 =
* Bug fixes.

= 3.0.1 =
* Bug fixes.

= 3.0.0 =
* Initial public release.

== Upgrade Notice ==

= 3.6.3 =
Bug fixes. New login settings; your current login behaviour is kept.

= 3.6.2 =
Bug fixes.

= 3.6.1 =
The On-Hold order SMS text was updated. Check kwtSMS > WooCommerce if you changed that text yourself.

= 3.6.0 =
Checkout phone verification now works with the WooCommerce Checkout block; a separate checkout SMS template was added.

= 3.0.0 =
Initial release. No upgrade required.
