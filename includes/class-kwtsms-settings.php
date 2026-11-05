<?php
/**
 * Settings storage and retrieval helper.
 *
 * All plugin settings are stored in four wp_options entries:
 *   - kwtsms_otp_general   : OTP behaviour, CAPTCHA, IPHub proxy detection
 *   - kwtsms_otp_gateway   : API credentials, sender ID, test mode
 *   - kwtsms_otp_templates : SMS message templates (EN + AR)
 *   - kwtsms_otp_alerts    : Admin-targeted event alert settings
 *
 * Access values with dot-notation: $settings->get('gateway.sender_id')
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Settings
 */
class KwtSMS_Settings {

	/**
	 * Default values for all settings groups.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'general'      => array(
			'sms_enabled'           => 1,          // Global SMS kill switch. When 0, no SMS is sent anywhere.
			'otp_mode'              => '2fa',     // Options: 2fa, passwordless, or both.
			'otp_length'            => 6,          // 4 or 6.
			'otp_expiry'            => 5,          // In minutes.
			'max_attempts'          => 3,
			'resend_cooldown'       => 120,        // In seconds.
			'login_otp'             => 1,
			'reset_otp'             => 1,
			'captcha_provider'      => 'none',     // Options: none, recaptcha, or turnstile.
			'recaptcha_site_key'    => '',
			'recaptcha_secret_key'  => '',
			'turnstile_site_key'    => '',
			'turnstile_secret_key'  => '',
			'referral_link'         => 0,          // Show 'SMS service by kwtSMS.com' on login pages (opt-in).
			'default_country_code'  => 'KW',       // ISO2 default for phone dropdown.
			'allowed_countries'     => array( 'KW', 'SA', 'AE', 'BH', 'QA', 'OM' ), // GCC default.
			'debug_logging'         => 0,          // Write detailed logs to wp-content/kwtsms-debug.log.
			'balance_failure_mode'  => 'block',    // block or allow — action when SMS credits run out.
			'blocked_phones'        => '',         // Newline or comma-separated normalized phone numbers.
			'ip_allowlist'          => '',         // Newline-separated IPs or CIDRs. Allowlisted IPs skip rate limiting and proxy checks.
			'ip_blocklist'          => '',         // Newline-separated IPs or CIDRs. Blocklisted IPs receive a silent rate-limit response.
			'otp_required_roles'    => array( 'editor', 'author', 'contributor', 'subscriber' ), // Administrator excluded by default.
			'welcome_sms_enabled'   => 0,           // Send welcome SMS to new registrations.
			'registration_otp_gate' => 'disabled',  // Options: disabled, optional, required.
			'iphub_api_key'         => '',
			'iphub_enabled'         => false,
			'iphub_action_block1'   => 'block',  // Action for block level 1 (confirmed proxy/VPN): block, allow, or log.
			'iphub_action_block2'   => 'log',    // Action for block level 2 (mixed residential/proxy): log, allow, or block.
			'iphub_cache_ttl'       => 86400,    // Transient TTL for cached IP reputation results (default: 1 day = 86400 s).
		),
		'gateway'      => array(
			'api_username'         => '',
			'api_password'         => '',
			'sender_id'            => '',
			'test_mode'            => 1,
			'test_phone'           => '',
			'credentials_verified' => 0,
			'sender_ids'           => array(),    // Cached list from last login.
			'balance_available'    => null,       // Float or null.
			'balance_purchased'    => null,       // Float or null.
			'balance_updated_at'   => 0,          // Timestamp of last balance update.
			'coverage'             => array(),    // Cached from last login.
		),
		'templates'    => array(
			'login_otp'    => array(
				'enabled' => 1,
				'en'      => 'Your {site_name} login code is: {otp}. Valid for {expiry_minutes} minutes. Do not share this code.',
				'ar'      => 'رمزك: {otp}. صالح {expiry_minutes} دقيقة. {site_name}',
			),
			// 2026-11-05 19:07 +03: own template for WooCommerce checkout phone verification, so the code is not sent with
			// the login wording. 2026-11-05 19:08 +03: Arabic default from marketing; if an admin clears it, build_message()
			// sends the English text instead, wp-otp-template.
			'checkout_otp' => array(
				'enabled' => 1,
				'en'      => 'Your {site_name} order verification code is: {otp}. Valid for {expiry_minutes} minutes. Do not share this code.',
				'ar'      => 'رمز تأكيد طلبك: {otp}. صالح {expiry_minutes} دقيقة. لا تشاركه. {site_name}',
			),
			'reset_otp'    => array(
				'enabled' => 1,
				'en'      => 'Your {site_name} password reset code is: {otp}. Valid for {expiry_minutes} minutes.',
				'ar'      => 'رمز إعادة التعيين: {otp}. {expiry_minutes} دقيقة. {site_name}',
			),
			'welcome_sms'  => array(
				'enabled' => 0,
				'en'      => 'Welcome to {site_name}, {name}! Your account has been created.',
				'ar'      => 'مرحباً بك في {site_name}، {name}! تم إنشاء حسابك بنجاح.',
			),
		),
		'integrations' => array(
			'woo_enabled'               => 1,
			'cf7_enabled'               => 1,
			'wpforms_enabled'           => 1,
			'woo_checkout_otp'          => 0,
			'woo_checkout_otp_cod_only' => 0,  // 0 = all payment methods, 1 = COD only.
			'cf7_mode'                  => 'notification', // Options: notification or gate.
			'wpforms_mode'              => 'notification', // Options: notification or gate.
			'woo_processing'            => array(
				'enabled' => 1,
				'en'      => '{site_name}: Your order #{order_id} has been confirmed. Total: {total}. Thank you!',
				'ar'      => '{site_name}: تم تأكيد طلبك رقم #{order_id}. المجموع: {total}. شكرًا لك!',
			),
			'woo_shipped'               => array(
				'enabled' => 1,
				'en'      => '{site_name}: Your order #{order_id} has been shipped and is on its way!',
				'ar'      => '{site_name}: طلبك رقم #{order_id} قيد الشحن وفي طريقه إليك!',
			),
			'woo_completed'             => array(
				'enabled' => 1,
				'en'      => '{site_name}: Your order #{order_id} is complete. Thank you for shopping with us!',
				'ar'      => '{site_name}: طلبك رقم #{order_id} مكتمل. شكرًا لتسوقك معنا!',
			),
			'woo_cancelled'             => array(
				'enabled' => 1,
				'en'      => '{site_name}: Your order #{order_id} has been cancelled. Contact us if this was unexpected.',
				'ar'      => '{site_name}: تم إلغاء طلبك رقم #{order_id}. تواصل معنا إذا لم تطلب ذلك.',
			),
			'woo_pending'               => array(
				'enabled' => 0,
				'en'      => '{site_name}: We received your order #{order_id}. Awaiting payment.',
				'ar'      => 'موقع {site_name}: استلمنا طلبك رقم #{order_id}. بانتظار الدفع.',
			),
			'woo_refunded'              => array(
				'enabled' => 0,
				'en'      => '{site_name}: Your order #{order_id} has been refunded. Contact us for details.',
				'ar'      => 'موقع {site_name}: تم استرداد مبلغ طلبك رقم #{order_id}. تواصل معنا للتفاصيل.',
			),
			'woo_failed'                => array(
				'enabled' => 0,
				'en'      => '{site_name}: Payment for your order #{order_id} failed. Please try again.',
				'ar'      => 'موقع {site_name}: فشل دفع طلبك رقم #{order_id}. يرجى المحاولة مرة أخرى.',
			),
			'woo_admin_phone'           => '',
			'woo_notify_admin_statuses' => array(),
			// D1 — Stock & inventory alerts (to admin phone).
			'woo_stock_admin_phone'     => '',
			'woo_low_stock_enabled'     => 1,
			'woo_no_stock_enabled'      => 1,
			'woo_backorder_enabled'     => 1,
			'woo_tpl_low_stock'         => array(
				'en' => '{site_name}: Low stock alert! {product_name} has {quantity} left.',
				'ar' => '{site_name}: تنبيه مخزون منخفض! {product_name} متبقي {quantity}.',
			),
			'woo_tpl_no_stock'          => array(
				'en' => '{site_name}: {product_name} is now out of stock.',
				'ar' => '{site_name}: {product_name} نفد المخزون.',
			),
			'woo_tpl_backorder'         => array(
				'en' => '{site_name}: {product_name} is now on backorder.',
				'ar' => '{site_name}: {product_name} أصبح تحت الطلب المسبق.',
			),
			// D2 — New product published.
			'woo_new_product_enabled'   => 0,
			'woo_tpl_new_product'       => array(
				'en' => '{site_name}: New product available: {product_name}. Check it out!',
				'ar' => '{site_name}: منتج جديد: {product_name}. اكتشفه الآن!',
			),
			// D1 — Back-in-stock subscriber notifications.
			'woo_back_in_stock_enabled' => 0,
			'woo_tpl_back_in_stock'     => array(
				'en' => '{site_name}: Good news! {product_name} is back in stock.',
				'ar' => '{site_name}: بشرى! {product_name} عاد إلى المخزون.',
			),
			// D3 — Cart abandonment recovery.
			'woo_cart_abandon_enabled'  => 0,
			'woo_cart_abandon_delay'    => 60,   // Minutes before sending recovery SMS.
			'woo_cart_abandon_coupon'   => 10,   // Discount percentage (0 = no coupon).
			'woo_cart_abandon_expiry'   => 48,   // Hours until coupon expires.
			'woo_tpl_cart_abandon'      => array(
				'en' => '{site_name}: Hi {first_name}, you left something behind! Your cart total is {cart_total}. Use code {coupon_code} for {discount}% off: {cart_url}',
				'ar' => '{site_name}: مرحبا {first_name}، نسيت شيئاً! مجموع سلتك {cart_total}. استخدم كود {coupon_code} للحصول على خصم {discount}%: {cart_url}',
			),
			// D4 — Instant new order alert and multivendor SMS.
			'woo_instant_order_enabled' => 0,
			'woo_instant_order_phone'   => '',
			'woo_tpl_instant_order'     => array(
				'en' => '{site_name}: New order #{order_id} from {customer_name}. Total: {total}.',
				'ar' => '{site_name}: طلب جديد #{order_id} من {customer_name}. المجموع: {total}.',
			),
			'woo_vendor_sms_enabled'    => 0,
			'woo_tpl_vendor_new_order'  => array(
				'en' => '{site_name}: New order #{order_id} includes your product {product_name}. Total: {total}.',
				'ar' => '{site_name}: الطلب #{order_id} يتضمن منتجك {product_name}. المجموع: {total}.',
			),
			'cf7_confirmation'          => array(
				'enabled' => 1,
				'en'      => '{site_name}: Your form "{form_name}" has been submitted successfully. Thank you!',
				'ar'      => '{site_name}: تم استلام نموذج "{form_name}" بنجاح. شكرًا لك!',
			),
			'wpforms_confirmation'      => array(
				'enabled' => 1,
				'en'      => '{site_name}: Your form "{form_name}" was received. Thank you!',
				'ar'      => '{site_name}: تم استلام نموذج "{form_name}". شكرًا لك!',
			),
			'nf_enabled'                => 1,
			'nf_mode'                   => 'notification', // Options: notification or gate.
			'nf_confirmation'           => array(
				'enabled' => 1,
				'en'      => '{form_name}: Thank you for submitting the form.',
				'ar'      => '{form_name}: شكراً لإرسال النموذج.',
			),
		),
		'alerts'       => array(
			'admin_phones'       => '',
			'user_register'      => 1,
			'wp_login'           => 0,
			'post_published'     => 1,
			'comment_posted'     => 1,
			'core_update'        => 1,
			'tpl_user_register'  => array(
				'en' => '{site_name}: New user registered, {username} ({email}).',
				'ar' => '{site_name}: مستخدم جديد {username} ({email}).',
			),
			'tpl_wp_login'       => array(
				'en' => '{site_name}: User {username} logged in.',
				'ar' => '{site_name}: المستخدم {username} سجّل دخوله.',
			),
			'tpl_post_published' => array(
				'en' => '{site_name}: New post published: {post_title}.',
				'ar' => '{site_name}: مقال جديد: {post_title}.',
			),
			'tpl_comment_posted' => array(
				'en' => '{site_name}: New comment on "{post_title}" by {author}.',
				'ar' => '{site_name}: تعليق جديد على "{post_title}" من {author}.',
			),
			'tpl_core_update'    => array(
				'en' => '{site_name}: WordPress updated to version {version}.',
				'ar' => '{site_name}: تم تحديث ووردبريس إلى الإصدار {version}.',
			),
		),
	);

	/**
	 * In-memory cache of loaded option groups.
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Retrieve a setting value using dot-notation key.
	 *
	 * Examples:
	 *   get('gateway.api_username')
	 *   get('general.otp_length', 6)
	 *   get('templates.login_otp.en')
	 *
	 * @param string $key     Dot-separated key: group.field or group.subkey.field.
	 * @param mixed  $fallback Fallback value if key is not found.
	 *
	 * @return mixed
	 */
	public function get( $key, $fallback = null ) {
		$parts = explode( '.', $key, 3 );
		$group = $parts[0]; // explode() always returns at least one element.
		$field = $parts[1] ?? null;
		$sub   = $parts[2] ?? null;

		$data = $this->load_group( $group );

		if ( null === $field ) {
			return $data;
		}

		if ( null === $sub ) {
			$value = $data[ $field ] ?? null;
		} else {
			$value = $data[ $field ][ $sub ] ?? null;
		}

		if ( null === $value ) {
			// Fall back to constant defaults.
			$defaults = self::DEFAULTS[ $group ] ?? array();
			if ( null === $sub ) {
				$value = $defaults[ $field ] ?? $fallback;
			} else {
				$value = $defaults[ $field ][ $sub ] ?? $fallback;
			}
		}

		return null !== $value ? $value : $fallback;
	}

	/**
	 * Update a single setting value.
	 *
	 * @param string $key   Dot-separated key.
	 * @param mixed  $value New value.
	 */
	public function set( $key, $value ) {
		$parts = explode( '.', $key, 3 );
		$group = $parts[0]; // explode() always returns at least one element.
		$field = $parts[1] ?? null;
		$sub   = $parts[2] ?? null;

		$data = $this->load_group( $group );

		if ( null === $field ) {
			return;
		}

		if ( null === $sub ) {
			$data[ $field ] = $value;
		} else {
			if ( ! isset( $data[ $field ] ) || ! is_array( $data[ $field ] ) ) {
				$data[ $field ] = array();
			}
			$data[ $field ][ $sub ] = $value;
		}

		update_option( 'kwtsms_otp_' . $group, $data );
		$this->cache[ $group ] = $data;
	}

	/**
	 * Get all templates with defaults merged in.
	 *
	 * @return array
	 */
	public function get_all_templates() {
		$saved    = $this->load_group( 'templates' );
		$defaults = self::DEFAULTS['templates'];

		$merged = array();
		foreach ( $defaults as $key => $default_template ) {
			$merged[ $key ] = array_merge( $default_template, $saved[ $key ] ?? array() );
		}

		return $merged;
	}

	/**
	 * Get all integration templates with defaults merged in.
	 *
	 * Returns only the array-type template entries (not boolean flags).
	 *
	 * @return array
	 */
	public function get_all_integration_templates() {
		$saved    = $this->load_group( 'integrations' );
		$defaults = self::DEFAULTS['integrations'];
		$merged   = array();

		$template_keys = array(
			'woo_processing',
			'woo_shipped',
			'woo_completed',
			'woo_cancelled',
			'woo_pending',
			'woo_refunded',
			'woo_failed',
			'woo_tpl_low_stock',
			'woo_tpl_no_stock',
			'woo_tpl_backorder',
			'woo_tpl_new_product',
			'woo_tpl_back_in_stock',
			'woo_tpl_cart_abandon',
			'woo_tpl_instant_order',
			'woo_tpl_vendor_new_order',
			'cf7_confirmation',
			'wpforms_confirmation',
			'nf_confirmation',
		);

		foreach ( $template_keys as $key ) {
			$saved_tpl = $saved[ $key ] ?? array();
			// Only use saved 'en'/'ar' values when non-empty, so defaults apply when templates were never customized.
			$resolved = $defaults[ $key ];
			if ( ! empty( $saved_tpl['en'] ) ) {
				$resolved['en'] = $saved_tpl['en'];
			}
			if ( ! empty( $saved_tpl['ar'] ) ) {
				$resolved['ar'] = $saved_tpl['ar'];
			}
			// Merge the saved 'enabled' flag so admins can enable/disable individual templates.
			if ( isset( $saved_tpl['enabled'] ) ) {
				$resolved['enabled'] = (int) $saved_tpl['enabled'];
			}
			$merged[ $key ] = $resolved;
		}

		return $merged;
	}

	/**
	 * Get template default texts for use in admin JavaScript.
	 *
	 * Returns an array keyed by template key containing only the 'en' and 'ar'
	 * default text strings. Used to populate the "Reset to Default" button data.
	 *
	 * @return array<string, array{en: string, ar: string}>
	 */
	public function get_template_defaults_for_js() {
		$all = array();

		foreach ( self::DEFAULTS['templates'] as $key => $tpl ) {
			$all[ $key ] = array(
				'en' => $tpl['en'],
				'ar' => $tpl['ar'],
			);
		}

		foreach ( self::DEFAULTS['integrations'] as $key => $val ) {
			if ( is_array( $val ) && isset( $val['en'], $val['ar'] ) ) {
				$all[ $key ] = array(
					'en' => $val['en'],
					'ar' => $val['ar'],
				);
			}
		}

		foreach ( self::DEFAULTS['alerts'] as $key => $val ) {
			if ( is_array( $val ) ) {
				$all[ $key ] = array(
					'en' => $val['en'],
					'ar' => $val['ar'],
				);
			}
		}

		return $all;
	}

	/**
	 * Load and cache an option group from the database.
	 *
	 * @param string $group Option group name (general|gateway|templates|integrations|alerts|security).
	 *
	 * @return array
	 */
	private function load_group( $group ) {
		if ( ! isset( $this->cache[ $group ] ) ) {
			$data                  = get_option( 'kwtsms_otp_' . $group, array() );
			$this->cache[ $group ] = is_array( $data ) ? $data : array();
		}
		return $this->cache[ $group ];
	}
}
