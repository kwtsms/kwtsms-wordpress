# kwtSMS: OTP & SMS Notifications, WordPress Plugin

[![CI](https://github.com/kwtsms/kwtsms-wordpress/actions/workflows/ci.yml/badge.svg)](https://github.com/kwtsms/kwtsms-wordpress/actions/workflows/ci.yml)
[![Semgrep](https://github.com/kwtsms/kwtsms-wordpress/actions/workflows/codeql.yml/badge.svg)](https://github.com/kwtsms/kwtsms-wordpress/actions/workflows/codeql.yml)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg?logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg?logo=php&logoColor=white)](https://php.net)
[![Release](https://img.shields.io/github/v/release/kwtsms/kwtsms-wordpress)](https://github.com/kwtsms/kwtsms-wordpress/releases)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-optional-96588a.svg?logo=woocommerce&logoColor=white)](https://woocommerce.com)

Replace passwords with SMS codes, automate WooCommerce order updates, and verify phone numbers on any contact form. Built on the [kwtSMS](https://www.kwtsms.com) gateway.

> Don't have a kwtSMS account? [Sign up at kwtsms.com →](https://www.kwtsms.com/signup)

---

## About kwtSMS

kwtSMS is a Kuwaiti SMS gateway trusted by top businesses to deliver messages anywhere in the world, with private Sender ID, free API testing, non-expiring credits, and competitive flat-rate pricing. Secure, simple to integrate, built to last. Open a free account in under 1 minute, no paperwork or payment required. [Get started →](https://www.kwtsms.com/signup/)

---

## Requirements

| | Version |
|---|---|
| WordPress | 6.0 or later |
| PHP | 7.4 or later (8.x recommended) |
| kwtSMS account | [Sign up free](https://www.kwtsms.com/signup) |
| WooCommerce | Optional |
| Contact Form 7 / WPForms / Ninja Forms | Optional |

---

## Installation

### Option 1: WordPress Plugin Directory

1. In your WordPress dashboard, go to **Plugins → Add New Plugin**.
2. Search for **kwtSMS**.
3. Click **Install Now** next to "kwtSMS: OTP & SMS Notifications", then click **Activate**.

### Option 2: Upload via WordPress Admin

1. Download the latest `kwtsms.zip` from the [Releases page](https://github.com/kwtsms/kwtsms-wordpress/releases).
2. In your WordPress dashboard, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the downloaded `.zip` file and click **Install Now**.
4. Click **Activate Plugin**.

### Option 3: WP-CLI

```bash
wp plugin install https://github.com/kwtsms/kwtsms-wordpress/releases/latest/download/kwtsms.zip --activate
```

### Initial Setup

After activation:

1. Go to **kwtSMS → Gateway** in your WordPress dashboard.
2. Enter your **API Username** and **API Password** (from your kwtSMS account under Account → API Settings, not your login credentials).
3. Click **Login** to verify credentials. The Sender ID dropdown will populate automatically.
4. Select your **Sender ID** and click **Save Settings**.
5. Go to **kwtSMS → General** to configure OTP mode (2FA, Passwordless, or both), rate limits, and CAPTCHA.
6. Optionally enable **Test Mode** while setting up: SMS is queued but never delivered, and the OTP code is visible under **kwtSMS → Logs → Debug Log**.

---

## Features

### Authentication
- **2FA mode:** standard password login followed by a one-time SMS code
- **Passwordless login:** phone number + OTP only, no password needed
- **Both:** let each user choose their preferred method
- **Password reset via OTP:** replaces the default email reset flow with SMS
- **Per-role enforcement:** choose which user roles require OTP (e.g. skip OTP for subscribers)
- **Welcome SMS:** send a customisable welcome message when a new user registers
- **Google reCAPTCHA v3** and **Cloudflare Turnstile** bot protection
- **Country code dropdown** on login forms: restrict to GCC or custom country list

### Security
- Cryptographically secure OTP generation
- **Sliding-window rate limiting:** per-phone, per-IP, and per-account, immune to fixed-window boundary exploits
- **Duplicate OTP guard:** reuses existing valid OTP on double-click or page reload, no duplicate SMS
- **IP Allowlist/Blocklist:** CIDR support for IPv4 and IPv6. Allowlisted IPs bypass rate limiting; blocklisted IPs receive a silent refusal
- **IPHub proxy/VPN detection:** optional integration to silently block or flag OTP requests from known proxies and VPNs, with per-IP caching
- **Registration OTP gate:** verify phone number via OTP before the WordPress account is created, preventing registrations with invalid numbers
- **Trusted Devices:** after completing 2FA, users can trust a device for 30 days. Subsequent logins skip OTP on trusted devices. Tokens stored as SHA-256 hashes. Profile page shows all trusted devices with revoke controls
- **Phone blocking list:** block specific numbers from ever receiving an OTP (anti-enumeration)
- Timing-safe OTP verification
- Hardened session cookies
- Emergency bypass option for admin lockout recovery

### WooCommerce
- **7 order status SMS**: Processing, On-Hold (Shipped), Completed, Cancelled, Pending Payment, Refunded, Failed
- **Admin SMS notifications:** notify a configurable phone number on any order status change
- **Per-order custom SMS:** send a free-text SMS to the customer from the order edit screen
- **OTP gate on checkout:** verify phone before placing order, with optional COD-only mode
- **Stock alerts:** low stock, out-of-stock, and backorder notifications to admin
- **New product SMS:** notify admin when a product is first published
- **Back-in-stock notifications:** customers subscribe via product page, SMS sent when stock returns
- **Instant new order SMS:** fires once per order at checkout, before any status change
- **Multivendor support:** route order SMS to the vendor (Dokan, WCFM, WC Vendors)
- **Cart abandonment recovery:** detect abandoned carts, send recovery SMS with a generated coupon code, track recovery rate in the dashboard widget
- HPOS (High-Performance Order Storage) compatible

### Form Integrations: Notification or OTP Gate
Each integration supports two modes: **Notification** (send confirmation SMS on submit) or **OTP Gate** (block submission until phone is verified via OTP).

| Plugin | Auto-detected | Notification | OTP Gate |
|--------|:---:|:---:|:---:|
| Contact Form 7 | ✓ | ✓ | ✓ |
| WPForms | ✓ | ✓ | ✓ |
| Ninja Forms | ✓ | ✓ | ✓ |

Need a specific plugin supported? [Open an issue](https://github.com/kwtsms/kwtsms-wordpress/issues) and we will look into it.

### Balance & Gateway
- Account balance displayed on Gateway and Help pages without re-verifying credentials
- Pre-send balance check: warns before sending if credits are zero
- Test phone country code validation with hint text
- Test Mode: SMS is queued but never delivered. Credits are deducted; recover them by deleting queued messages from your kwtSMS dashboard. OTP code is visible under kwtSMS → Logs → Debug Log.

### Admin
- 6 admin pages under the **kwtSMS** menu: General, Gateway, Templates, Integrations, Logs, Help
- **Users Without Phone** sub-page: lists all users missing a phone number, with inline edit and dynamic count badge on the Users menu item
- Live credential verification with Sender ID auto-population
- OTP send log (last 100 entries)
- Dashboard widget with today's send count
- Full Arabic (RTL) translation included

---

## Screenshots

<table>
<tr>
<td align="center">
<a href="wporg-assets/screenshot-1.png"><img src="wporg-assets/screenshot-1.png" width="260" alt="General Settings"></a><br>
<sub>General Settings: OTP mode, rate limits, CAPTCHA</sub>
</td>
<td align="center">
<a href="wporg-assets/screenshot-2.png"><img src="wporg-assets/screenshot-2.png" width="260" alt="SMS Templates"></a><br>
<sub>SMS Templates: English and Arabic with character counter</sub>
</td>
<td align="center">
<a href="wporg-assets/screenshot-3.png"><img src="wporg-assets/screenshot-3.png" width="260" alt="2FA OTP verification"></a><br>
<sub>2FA: OTP step after password login</sub>
</td>
<td align="center">
<a href="wporg-assets/screenshot-4.png"><img src="wporg-assets/screenshot-4.png" width="260" alt="Passwordless login"></a><br>
<sub>Passwordless: phone + OTP, no password needed</sub>
</td>
</tr>
<tr>
<td align="center">
<a href="wporg-assets/screenshot-5.png"><img src="wporg-assets/screenshot-5.png" width="260" alt="Password reset via OTP"></a><br>
<sub>Password reset: OTP replaces email link</sub>
</td>
<td align="center">
<a href="wporg-assets/screenshot-6.png"><img src="wporg-assets/screenshot-6.png" width="260" alt="WooCommerce integration"></a><br>
<sub>WooCommerce: order status SMS and checkout OTP gate</sub>
</td>
<td align="center">
<a href="wporg-assets/screenshot-7.png"><img src="wporg-assets/screenshot-7.png" width="260" alt="Integrations overview"></a><br>
<sub>Integrations: WooCommerce, CF7, WPForms, and more</sub>
</td>
<td align="center">
<a href="wporg-assets/screenshot-8.png"><img src="wporg-assets/screenshot-8.png" width="260" alt="Contact Form 7 settings"></a><br>
<sub>CF7: Notification or OTP Gate mode per form</sub>
</td>
</tr>
</table>

---

## External Services

This plugin connects to the following external services:

**1. kwtSMS API** (required): sends all SMS messages. [kwtsms.com](https://www.kwtsms.com) | [Terms](https://www.kwtsms.com/policy.html) | [Privacy](https://www.kwtsms.com/privacy.html)

Requests to kwtSMS carry the integration name and version (no personal data) so kwtSMS can see which integrations are in use.

**2. ipapi.co** (optional): detects visitor country for dial-code pre-selection. [ipapi.co](https://ipapi.co) | [Terms](https://ipapi.co/terms/) | [Privacy](https://ipapi.co/privacy/)

**3. IPHub** (optional): proxy/VPN detection on OTP requests. [iphub.info](https://iphub.info) | [Terms](https://iphub.info/legal) | [Privacy](https://iphub.info/legal)

**4. Google reCAPTCHA v3** (optional): bot protection on OTP forms. [google.com/recaptcha](https://www.google.com/recaptcha/) | [Terms](https://policies.google.com/terms) | [Privacy](https://policies.google.com/privacy)

**5. Cloudflare Turnstile** (optional): alternative bot protection. [cloudflare.com/turnstile](https://www.cloudflare.com/products/turnstile/) | [Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/)

---

## FAQ

**1. Do I need a kwtSMS account?**

Yes. Sign up free at [kwtsms.com](https://www.kwtsms.com/signup). API credentials (username and password, not your login mobile) are entered in kwtSMS > Gateway.

**2. What is the difference between Test Mode and Live Mode?**

In Test Mode, messages are queued on the kwtSMS server but never delivered to the recipient's phone. Credits are still deducted. To recover them, log in to your kwtSMS dashboard and delete the queued messages from the outbox. The OTP code is visible under **kwtSMS → Logs → Debug Log** so you can complete flows during development without a real phone. In Live Mode, the SMS is delivered and credits are deducted. Always develop with Test Mode on, then disable it before going live.

**3. My SMS status shows OK but the recipient did not receive it. What happened?**

Check the Sending Queue at [kwtsms.com](https://www.kwtsms.com/login/). If the message is stuck there, it was accepted but not dispatched. Common causes: emoji or hidden characters in the message body, spam filter triggers, or Test Mode still enabled. Delete the stuck message from the queue to recover your credits.

**4. What is a Sender ID and why should I not use the shared KWT-SMS sender?**

A Sender ID is the name that appears on the recipient's phone instead of a random number. `KWT-SMS` is a shared test sender: it causes delivery delays and is blocked on Virgin Kuwait. For OTP you must use a **Transactional** Sender ID, which bypasses DND filtering on Zain and Ooredoo. Promotional Sender IDs are silently filtered, meaning OTP messages fail while credits are still deducted. Register a private Sender ID through your kwtSMS account.

**5. I am getting an authentication error when I save my credentials. What should I check?**

The plugin requires your **API username and API password**, not your account mobile number or login password. Log in to [kwtsms.com](https://www.kwtsms.com/login/), go to Account > API settings, and copy the API credentials. They are case-sensitive.

**6. Can I send SMS to numbers outside Kuwait?**

International sending is disabled by default on all kwtSMS accounts. Log in to your kwtSMS account and activate coverage for the countries you need. Enable IP and phone rate limiting before turning on international coverage to prevent balance drain from automated abuse.

**7. Does the plugin work without WooCommerce?**

Yes. WooCommerce is fully optional. All login, password reset, and contact form features work on any WordPress site.

**8. How do I recover if I am locked out due to OTP?**

Add this line to `wp-config.php` (before the `/* That's all, stop editing! */` line):

```php
define( 'KWTSMS_OTP_DISABLED', true );
```

Log in normally, fix your phone number or gateway issue, then remove the line.

Alternatively, use WP-CLI to remove the phone from your account: `wp user meta delete <user_id> kwtsms_phone` (replace `<user_id>` with your user ID, usually `1` for the first admin).

---

## Help & Support

- **[kwtSMS FAQ](https://www.kwtsms.com/faq/)**: Answers to common questions about credits, sender IDs, OTP, and delivery.
- **[kwtSMS Support](https://www.kwtsms.com/support.html)**: Open a support ticket or browse help articles.
- **[Contact kwtSMS](https://www.kwtsms.com/#contact)**: Reach the kwtSMS team directly for Sender ID registration and account issues.
- **[Sender ID Help](https://www.kwtsms.com/sender-id-help.html)**: Sender ID registration and guidelines.
- **[kwtSMS Dashboard](https://www.kwtsms.com/login/)**: Recharge credits, buy Sender IDs, view message logs, and manage coverage.
- **[Other Integrations](https://www.kwtsms.com/integrations.html)**: Plugins and integrations for other platforms and languages.
- **[Plugin Issues](https://github.com/kwtsms/kwtsms-wordpress/issues)**: Report bugs or request features.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full version history.

---

## License

GPL-2.0-or-later. See [GNU GPL v2.0](https://www.gnu.org/licenses/gpl-2.0.html)

---

Built on [kwtSMS.com](https://www.kwtsms.com), Kuwait's SMS gateway
