# Contributing to kwtSMS: OTP & SMS Notifications

## Prerequisites

- PHP 7.4 or later (PHP 8.x recommended)
- Composer
- WordPress 6.0 or later (via WP Playground for local testing, no Docker needed)
- A kwtSMS account (for integration tests only)

## Setup

```bash
git clone https://github.com/kwtsms/kwtsms-wordpress.git wp-kwtsms
cd wp-kwtsms
composer install
```

## Running Tests

```bash
# Unit tests with Brain\Monkey mocks (no WordPress installation needed)
./vendor/bin/phpunit --no-coverage

# With coverage report
./vendor/bin/phpunit

# Code style check
./vendor/bin/phpcs --standard=WordPress .

# Static analysis
./vendor/bin/phpstan analyse
```

## Local Testing (WP Playground)

No Docker required. Playground spins up a full WordPress instance in seconds:

```bash
ln -s /path/to/wp-kwtsms /tmp/wp-kwtsms
npx @wp-playground/cli@latest server --auto-mount /tmp/wp-kwtsms --follow-symlinks
# WordPress at http://127.0.0.1:9400  (admin / password)
```

Enable **Test Mode** in Gateway settings. The SMS is queued but not delivered. Credits are still deducted, so delete queued messages from your kwtSMS dashboard to recover them. The OTP code is written to `wp-content/kwtsms-debug.log` so you can complete full flows without a real phone.

## Project Structure

```
wp-kwtsms.php                  Plugin bootstrap and hook registration
includes/
  class-kwtsms-plugin.php      Service locator (singleton)
  class-kwtsms-api.php         kwtSMS HTTP API client
  class-kwtsms-settings.php    Settings helper (wp_options wrapper)
  class-kwtsms-otp-engine.php  OTP generate/verify, sliding-window rate limiting
  class-kwtsms-login-otp.php   Login 2FA / passwordless hooks
  class-kwtsms-reset-otp.php   Password reset OTP hooks
  class-kwtsms-user-meta.php   Phone number field on user profile
  class-kwtsms-captcha.php     reCAPTCHA v3 / Cloudflare Turnstile
  class-kwtsms-integrations.php Integration loader
  integrations/
    class-kwtsms-woo.php         WooCommerce order SMS
    class-kwtsms-woo-metabox.php Per-order custom SMS metabox
    class-kwtsms-cf7.php         Contact Form 7
    class-kwtsms-wpforms.php     WPForms
    class-kwtsms-ninjaforms.php  Ninja Forms
admin/
  class-kwtsms-admin.php       Admin class and sanitization
  views/                       Admin page templates (PHP partials)
assets/
  css/                         Admin and login stylesheets
  js/                          Admin JS, login JS, form OTP modal
languages/                     POT and PO/MO translation files
tests/                         PHPUnit 9 + Brain\Monkey
```

## Branch Naming

- `feature/short-description` for new features
- `fix/short-description` for bug fixes
- `docs/short-description` for documentation changes

## Pull Request Checklist

- [ ] All existing tests pass: `./vendor/bin/phpunit --no-coverage`
- [ ] New code has tests where practical
- [ ] No PHPCS errors: `./vendor/bin/phpcs --standard=WordPress .`
- [ ] PHPStan passes: `./vendor/bin/phpstan analyse`
- [ ] Inputs sanitized (`sanitize_text_field`, `absint`, etc.)
- [ ] Outputs escaped (`esc_html`, `esc_attr`, `esc_url`)
- [ ] Nonce verified on all AJAX and form submissions
- [ ] Capability check (`current_user_can`) on all admin actions
- [ ] API credentials never output to HTML
- [ ] CHANGELOG.md updated under `[Unreleased]`

## Code Style

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- PHPDoc on all classes and public methods
- Class names: `KwtSMS_Class_Name`
- Method names: `snake_case()`
- Hooks: `kwtsms_hook_name`
- Text domain: `kwtsms`
- Never output raw PHP errors or stack traces to the browser
- Never commit credentials, `.env` files, or debug logs

## Internationalization

All user-facing strings must use WordPress i18n functions:

```php
__( 'String', 'kwtsms' )
esc_html__( 'String', 'kwtsms' )
_e( 'String', 'kwtsms' )
```

After adding new strings, regenerate the POT file:

```bash
wp i18n make-pot . languages/kwtsms.pot
```

## Releasing

1. Bump the version in `wp-kwtsms.php` (plugin header) and `readme.txt` (Stable tag)
2. Update `readme.txt` changelog with the new version entry
3. Update `CHANGELOG.md`: move items from `[Unreleased]` to a new version section
4. Regenerate the POT file: `wp i18n make-pot . languages/kwtsms.pot`
5. Compile MO files: `wp i18n make-mo languages/`
6. Run the full test suite and PHPCS
7. Commit with `feat: release vX.Y.Z`
8. Tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`
9. GitHub Actions automatically builds the plugin zip and publishes a GitHub release. No manual step needed.
