<?php
/**
 * Main plugin manager / service locator.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Bootstraps and wires all plugin modules. Uses a singleton pattern so only
 * one instance is created per request. Each module is responsible for
 * registering its own hooks in its constructor.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Plugin
 *
 * Central manager that initialises all plugin components.
 */
class KwtSMS_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var KwtSMS_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Settings helper.
	 *
	 * @var KwtSMS_Settings
	 */
	public $settings;

	/**
	 * KwtSMS API client.
	 *
	 * @var KwtSMS_API
	 */
	public $api;

	/**
	 * OTP engine.
	 *
	 * @var KwtSMS_OTP_Engine
	 */
	public $otp;

	/**
	 * WooCommerce cart abandonment instance (set by integrations loader when WC active).
	 *
	 * @var KwtSMS_Woo_Cart|null
	 */
	public $woo_cart = null;

	/**
	 * Get or create the singleton instance.
	 *
	 * @return KwtSMS_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: private to enforce singleton.
	 *
	 * Instantiates all modules. Module constructors register their own hooks,
	 * keeping this class thin.
	 */
	private function __construct() {
		$this->settings = new KwtSMS_Settings();
		$this->api      = new KwtSMS_API(
			$this->settings->get( 'gateway.api_username', '' ),
			$this->settings->get( 'gateway.api_password', '' ),
			(bool) $this->settings->get( 'gateway.test_mode', true ),
			(bool) $this->settings->get( 'general.debug_logging', 0 )
		);
		$this->otp      = new KwtSMS_OTP_Engine( $this->settings );

		// Admin-only modules: only load on admin requests to keep frontend lean.
		if ( is_admin() ) {
			new KwtSMS_Admin( $this );
		}

		// Frontend / login modules: load on all requests.
		new KwtSMS_User_Meta();

		// Load login/reset OTP only when enabled in settings.
		if ( $this->settings->get( 'general.login_otp', 1 ) ) {
			new KwtSMS_Login_OTP( $this );
		}
		if ( $this->settings->get( 'general.reset_otp', 1 ) ) {
			new KwtSMS_Reset_OTP( $this );
		}

		// CAPTCHA module: always loaded so it can enqueue on login page.
		new KwtSMS_Captcha( $this->settings );

		// Third-party plugin integrations (WooCommerce, CF7, WPForms, Ninja Forms).
		new KwtSMS_Integrations( $this );

		// Registration OTP Gate: verify phone before account creation.
		if ( 'disabled' !== $this->settings->get( 'general.registration_otp_gate', 'disabled' ) ) {
			new KwtSMS_Registration_OTP_Gate( $this->settings, $this->api, $this->otp );
		}

		// Admin Site Alerts: notify admin phone(s) on key site events.
		new KwtSMS_Admin_Alerts( $this );

		// Welcome SMS: fires for all registrations (WC checkout, WC My Account, standard WP).
		add_action( 'user_register', array( $this, 'maybe_send_welcome_on_register' ), 20 );

		// AJAX handlers.
		$this->register_ajax_handlers();

		// Trusted Devices: revoke all on password reset.
		add_action( 'password_reset', array( $this, 'on_password_reset_revoke_devices' ), 10, 1 );

		// Trusted Devices: user profile section for viewing and revoking.
		add_action( 'show_user_profile', array( $this, 'render_trusted_devices_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'render_trusted_devices_profile' ) );

		// Referral link on standard WP login page.
		if ( $this->settings->get( 'general.referral_link', 0 ) ) {
			add_action( 'login_footer', array( $this, 'render_login_referral' ) );
		}
	}

	/**
	 * Output the "SMS by kwtSMS.com" credit on the standard WP login page footer.
	 *
	 * Hooked to `login_footer` when the referral_link setting is enabled.
	 */
	public function render_login_referral() {
		$ref_url = add_query_arg(
			'ref',
			wp_parse_url( home_url(), PHP_URL_HOST ),
			'https://www.kwtsms.com/'
		);
		printf(
			'<p class="kwtsms-credit" style="text-align:center;color:#aaa;font-size:12px;margin-top:16px;">' .
			'<a href="%s" target="_blank" rel="noopener" style="color:#aaa;">' .
			'%s' .
			'</a></p>',
			esc_url( $ref_url ),
			esc_html__( 'SMS by kwtSMS.com', 'kwtsms' )
		);
	}

	/**
	 * Send welcome SMS after any user registration.
	 *
	 * Fires on `user_register` with priority 20 (after WC saves phone meta at 10).
	 * Phone resolution order:
	 *   1. $_POST['kwtsms_phone_reg'] - WC My Account registration field.
	 *   2. $_POST['billing_phone']    - WC checkout registration.
	 *   3. kwtsms_phone user meta     - set before user_register fires (some custom forms).
	 *
	 * @param int $user_id Newly created user ID.
	 */
	public function maybe_send_welcome_on_register( $user_id ) {
		if ( ! $this->settings->get( 'general.welcome_sms_enabled', 0 ) ) {
			return;
		}

		// Read phone from user meta. The phone was already saved by
		// save_registration_phone() or save_wc_customer_phone() which fire
		// on the same user_register / woocommerce_created_customer hooks
		// at an earlier priority (10 vs our 20). Those handlers verify the
		// nonce and sanitize the POST input before saving to meta.
		$phone = (string) get_user_meta( $user_id, 'kwtsms_phone', true );

		if ( '' === $phone ) {
			$this->api->write_debug_log( 'welcome_sms', 'Skipped welcome SMS for user #' . $user_id . ': no phone number found' );
			return;
		}

		$phone = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
		$phone = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $phone ) ) {
			$this->api->write_debug_log( 'welcome_sms', 'Skipped welcome SMS for user #' . $user_id . ': phone normalization failed' );
			return;
		}

		$user    = get_userdata( $user_id );
		$name    = $user ? $user->display_name : '';
		$message = $this->otp->build_message( '', 'welcome_sms', array( '{name}' => $name ) );
		$this->api->send(
			$phone,
			$this->settings->get( 'gateway.sender_id', '' ),
			$message,
			'welcome'
		);
	}

	/**
	 * Register wp_ajax_* handlers used across modules.
	 *
	 * Keeping registrations here avoids scattering add_action calls.
	 */
	private function register_ajax_handlers() {
		// Credential verification (admin only).
		add_action( 'wp_ajax_kwtsms_verify_credentials', array( $this, 'ajax_verify_credentials' ) );
		add_action( 'wp_ajax_kwtsms_reload_all', array( $this, 'ajax_reload_all' ) );

		// OTP resend (guests, not yet logged in).
		add_action( 'wp_ajax_nopriv_kwtsms_resend_otp', array( $this, 'ajax_resend_otp' ) );
		add_action( 'wp_ajax_kwtsms_resend_otp', array( $this, 'ajax_resend_otp' ) );

		// Test SMS send (admin only).
		add_action( 'wp_ajax_kwtsms_send_test_sms', array( $this, 'ajax_send_test_sms' ) );

		// Form OTP gate: send OTP to phone before form submission (guests).
		add_action( 'wp_ajax_nopriv_kwtsms_form_send_otp', array( $this, 'ajax_form_send_otp' ) );
		add_action( 'wp_ajax_kwtsms_form_send_otp', array( $this, 'ajax_form_send_otp' ) );

		// Form OTP gate: verify code and mark transient as verified (guests).
		add_action( 'wp_ajax_nopriv_kwtsms_form_verify_otp', array( $this, 'ajax_form_verify_otp' ) );
		add_action( 'wp_ajax_kwtsms_form_verify_otp', array( $this, 'ajax_form_verify_otp' ) );

		// Enqueue form-otp.js on frontend when gate mode is active.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_form_otp_assets' ) );

		// Trusted Device revoke (logged-in users only).
		add_action( 'wp_ajax_kwtsms_revoke_device', array( $this, 'ajax_revoke_device' ) );
		add_action( 'wp_ajax_kwtsms_revoke_all_devices', array( $this, 'ajax_revoke_all_devices' ) );
	}

	// =========================================================================
	// Form OTP Gate helpers
	// =========================================================================

	/**
	 * Generate a 32-character hex token for form OTP gate sessions.
	 *
	 * @return string 32-char lowercase hex string.
	 */
	public function generate_form_token() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Check whether a form OTP gate token has been successfully verified.
	 *
	 * Looks up the transient `kwtsms_form_otp_{token}` and returns whether
	 * the `verified` flag is set to true.
	 *
	 * @param string $token The 32-char hex token from the hidden form input.
	 *
	 * @return bool True if the token exists and is verified; false otherwise.
	 */
	public function verify_form_token( $token ) {
		$token = sanitize_text_field( $token );
		if ( empty( $token ) || ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return false;
		}

		$data = get_transient( 'kwtsms_form_otp_' . $token );
		if ( ! is_array( $data ) ) {
			return false;
		}

		return ! empty( $data['verified'] );
	}

	// =========================================================================
	// AJAX: Form OTP gate, send OTP
	// =========================================================================

	/**
	 * AJAX: normalise a phone number, send an OTP, and store the transient.
	 *
	 * Expected POST params:
	 *   nonce  - kwtsms_form_otp_nonce
	 *   phone  - raw phone number entered by the visitor
	 *
	 * Returns JSON success with { token } on success so the JS can store the
	 * token in a hidden input, or JSON error with { message } on failure.
	 *
	 * Security: nonce verified; no capability check (nopriv endpoint, used by
	 * logged-out visitors filling in public forms).
	 */
	public function ajax_form_send_otp() {
		check_ajax_referer( 'kwtsms_form_otp_nonce', 'nonce' );

		$raw_phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$form_id   = absint( wp_unslash( $_POST['form_id'] ?? 0 ) );
		if ( empty( $raw_phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Phone number is required.', 'kwtsms' ) ) );
			return;
		}

		$raw_phone  = KwtSMS_API::prepend_country_code_if_local( $raw_phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $raw_phone );
		if ( is_wp_error( $normalized ) ) {
			wp_send_json_error( array( 'message' => $normalized->get_error_message() ) );
			return;
		}

		// Rate limit: per-phone and per-IP to prevent SMS credit exhaustion.
		// user_id = 0 because form gate is used by guests (not logged-in users).
		if ( $this->otp->is_rate_limited( $normalized, 'form_otp', 0 ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'kwtsms' ) ) );
			return;
		}
		if ( $this->otp->is_ip_rate_limited( 'form_otp', 0, $normalized ) ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'kwtsms' ) ) );
			return;
		}

		// Generate a 6-digit OTP code using a CSPRNG (PHP 7+ standard library).
		// random_int() is cryptographically secure; wp_rand() uses mt_rand which is predictable.
		$otp_code = (string) random_int( 100000, 999999 );
		$otp_hash = wp_hash_password( $otp_code );

		// Generate a fresh session token for this verification attempt.
		$token = $this->generate_form_token();

		// Store in a 15-minute transient.
		set_transient(
			'kwtsms_form_otp_' . $token,
			array(
				'phone'    => $normalized,
				'form_id'  => $form_id,
				'otp_hash' => $otp_hash,
				'verified' => false,
			),
			900 // 15 minutes.
		);

		// Build and send the OTP SMS.
		$site_name = get_bloginfo( 'name' );
		/* translators: 1: site name, 2: OTP code */
		$message = sprintf(
			/* translators: 1: site name, 2: OTP code */
			__( '%1$s: Your verification code is %2$s. Valid for 15 minutes.', 'kwtsms' ),
			$site_name,
			$otp_code
		);

		$result = $this->api->send(
			$normalized,
			$this->settings->get( 'gateway.sender_id', '' ),
			$message,
			'form_otp'
		);

		if ( is_wp_error( $result ) ) {
			// Clean up the transient to avoid orphaned records.
			delete_transient( 'kwtsms_form_otp_' . $token );
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( array( 'token' => $token ) );
	}

	// =========================================================================
	// AJAX: Form OTP gate, verify OTP
	// =========================================================================

	/**
	 * AJAX: verify the submitted OTP code against the stored hash.
	 *
	 * Expected POST params:
	 *   nonce  - kwtsms_form_otp_nonce
	 *   token  - 32-char hex session token (from hidden input)
	 *   code   - OTP code entered by the visitor
	 *
	 * On success, updates the transient to set verified=true and returns
	 * JSON success. On failure returns JSON error with { message }.
	 *
	 * Security: nonce verified; no capability check (nopriv endpoint).
	 */
	public function ajax_form_verify_otp() {
		check_ajax_referer( 'kwtsms_form_otp_nonce', 'nonce' );

		$token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$code  = sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) );

		if ( empty( $token ) || empty( $code ) ) {
			wp_send_json_error( array( 'message' => __( 'Token and code are required.', 'kwtsms' ) ) );
			return;
		}

		// Validate token format (must be 32 lowercase hex chars).
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token.', 'kwtsms' ) ) );
			return;
		}

		$transient_key = 'kwtsms_form_otp_' . $token;
		$data          = get_transient( $transient_key );

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Session expired. Please request a new code.', 'kwtsms' ) ) );
			return;
		}

		// Enforce max 5 attempts per token.
		$attempts = (int) ( $data['attempts'] ?? 0 );
		if ( $attempts >= 5 ) {
			delete_transient( $transient_key );
			wp_send_json_error( array( 'message' => __( 'Too many incorrect attempts. Please request a new code.', 'kwtsms' ) ) );
			return;
		}

		// Verify the submitted code against the stored bcrypt hash.
		if ( ! wp_check_password( $code, $data['otp_hash'] ) ) {
			// Wrong code: increment attempt counter and persist.
			$data['attempts'] = $attempts + 1;
			set_transient( $transient_key, $data, 900 );
			wp_send_json_error( array( 'message' => __( 'Incorrect code. Please try again.', 'kwtsms' ) ) );
			return;
		}

		// Mark as verified; keep the transient alive for the form submission check.
		$data['verified'] = true;
		set_transient( $transient_key, $data, 900 );

		wp_send_json_success( array( 'message' => __( 'Phone number verified successfully.', 'kwtsms' ) ) );
	}

	// =========================================================================
	// Frontend: enqueue form-otp.js
	// =========================================================================

	/**
	 * Enqueue form-otp.js on the frontend only when at least one integration
	 * is configured in gate mode and its integration is enabled.
	 *
	 * Hooked to wp_enqueue_scripts so it only runs on public pages.
	 */
	public function enqueue_form_otp_assets() {
		$cf7_gate     = ( 'gate' === $this->settings->get( 'integrations.cf7_mode', 'notification' ) )
			&& $this->settings->get( 'integrations.cf7_enabled', 1 );
		$wpforms_gate = ( 'gate' === $this->settings->get( 'integrations.wpforms_mode', 'notification' ) )
			&& $this->settings->get( 'integrations.wpforms_enabled', 1 );
		$nf_gate      = ( 'gate' === $this->settings->get( 'integrations.nf_mode', 'notification' ) )
			&& $this->settings->get( 'integrations.nf_enabled', 1 );

		if ( ! $cf7_gate && ! $wpforms_gate && ! $nf_gate ) {
			return;
		}

		wp_enqueue_script(
			'kwtsms-form-otp',
			KWTSMS_OTP_URL . 'assets/js/form-otp.js',
			array( 'jquery' ),
			KWTSMS_OTP_VERSION,
			true
		);

		wp_localize_script(
			'kwtsms-form-otp',
			'kwtSmsFormData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'kwtsms_form_otp_nonce' ),
				'gateNonce'       => wp_create_nonce( 'kwtsms_gate_verify' ),
				'defaultDialCode' => KwtSMS_API::get_default_dial_code(),
				'strings'         => array(
					'enterPhone'       => __( 'Enter your phone number to verify', 'kwtsms' ),
					'sendCode'         => __( 'Send Code', 'kwtsms' ),
					'sending'          => __( 'Sending...', 'kwtsms' ),
					'enterCode'        => __( 'Enter the code sent to your phone', 'kwtsms' ),
					'verifyCode'       => __( 'Verify', 'kwtsms' ),
					'verifying'        => __( 'Verifying...', 'kwtsms' ),
					'verified'         => __( 'Phone verified!', 'kwtsms' ),
					'resend'           => __( 'Resend Code', 'kwtsms' ),
					'close'            => __( 'Cancel', 'kwtsms' ),
					// 2026-11-05 11:21 +03: placeholder 965XXXXXXXX replaces a real person's number, int-testnumber.
					'phonePlaceholder' => __( 'e.g. 965XXXXXXXX', 'kwtsms' ),
					'codePlaceholder'  => __( '6-digit code', 'kwtsms' ),
					'modalTitle'       => __( 'Phone Verification Required', 'kwtsms' ),
					'verifiedMsg'      => __( 'Your phone has been verified. Submitting form...', 'kwtsms' ),
				),
			)
		);
	}

	/**
	 * AJAX: verify API credentials and return sender IDs + balance.
	 *
	 * Called from the Gateway Settings page when the admin clicks
	 * "Save & Verify Credentials".
	 *
	 * Security: nonce + manage_options capability check.
	 */
	public function ajax_verify_credentials() {
		check_ajax_referer( 'kwtsms_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		$username = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
		$password = sanitize_text_field( wp_unslash( $_POST['password'] ?? '' ) );

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'Username and password are required.', 'kwtsms' ) ) );
			return;
		}

		// Use a temporary API instance with the submitted (not yet saved) credentials.
		$api        = new KwtSMS_API( $username, $password, false );
		$sender_ids = $api->get_sender_ids();
		$balance    = $api->get_balance();

		if ( is_wp_error( $sender_ids ) ) {
			// Clear saved verified state so dependent features stay locked.
			$gw = get_option( 'kwtsms_otp_gateway', array() );
			if ( ! empty( $gw['credentials_verified'] ) ) {
				$gw['credentials_verified'] = 0;
				update_option( 'kwtsms_otp_gateway', $gw );
			}
			wp_send_json_error(
				array( 'message' => $sender_ids->get_error_message() )
			);
			return;
		}

		// Also fetch coverage to persist alongside sender IDs and balance.
		$coverage     = $api->get_coverage();
		$coverage_arr = ( ! is_wp_error( $coverage ) ) ? (array) $coverage : array();

		// Enrich coverage items with dial codes from the local country-codes data.
		$_countries    = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';
		$_dial_name    = array(); // Maps lowercase name to dial code.
		$_dial_iso2    = array(); // Maps ISO2 country code to dial code.
		$_name_by_dial = array(); // Maps dial code to country name.
		foreach ( $_countries as $_cc ) {
			$_dial_name[ strtolower( $_cc['name'] ) ] = $_cc['dial'];
			$_dial_iso2[ $_cc['iso2'] ]               = $_cc['dial'];
			$_name_by_dial[ $_cc['dial'] ]            = $_cc['name'];
		}
		unset( $_countries, $_cc );

		// API status strings that should never be treated as country names.
		$_cov_api_codes = array( 'OK', 'ERROR', 'ERR', 'FAIL', 'FAILED', 'NULL', 'NONE', 'N/A', 'NA', 'TRUE', 'FALSE' );

		$coverage_enriched = array();
		foreach ( $coverage_arr as $_cov ) {
			// Normalise to (name, dial, iso2) regardless of input shape.
			if ( is_array( $_cov ) ) {
				$_cname = (string) ( $_cov['name'] ?? $_cov['country'] ?? $_cov['countryName'] ?? $_cov['CountryName'] ?? '' );
				$_cdial = (string) ( $_cov['dial'] ?? '' );
				$_ciso2 = (string) ( $_cov['cc'] ?? $_cov['iso2'] ?? '' );
			} else {
				$_cname = trim( (string) $_cov );
				$_cdial = '';
				$_ciso2 = '';
			}

			// Clear name if it is an API status string (OK, ERROR, FAIL, ...).
			if ( in_array( strtoupper( $_cname ), $_cov_api_codes, true ) ) {
				$_cname = '';
			}

			// Bare digit string in name field  treat it as dial code.
			if ( '' !== $_cname && ctype_digit( $_cname ) ) {
				if ( '' === $_cdial ) {
					$_cdial = $_cname;
				}
				$_cname = '';
			}

			// Name present but not a recognised country  try to resolve from dial.
			if ( '' !== $_cname && '' !== $_cdial
				&& ! isset( $_dial_name[ strtolower( $_cname ) ] )
				&& isset( $_name_by_dial[ $_cdial ] ) ) {
				$_cname = $_name_by_dial[ $_cdial ];
			}

			// Resolve name from dial when name is still missing.
			if ( '' === $_cname && '' !== $_cdial ) {
				$_cname = $_name_by_dial[ $_cdial ] ?? '';
			}

			// Resolve dial from name when dial is missing.
			if ( '' === $_cdial && '' !== $_cname ) {
				$_cdial = $_dial_name[ strtolower( $_cname ) ] ?? ( '' !== $_ciso2 ? ( $_dial_iso2[ strtoupper( $_ciso2 ) ] ?? '' ) : '' );
			}

			// Skip entries we couldn't resolve to a real country name.
			if ( '' === $_cname ) {
				continue;
			}

			$coverage_enriched[] = array_filter(
				array(
					'name' => $_cname,
					'dial' => $_cdial,
				)
			);
		}
		unset( $_dial_name, $_dial_iso2, $_name_by_dial, $_cov_api_codes, $_cov, $_cname, $_cdial, $_ciso2 );
		$coverage_arr = $coverage_enriched;

		// Persist the verified state, credentials, and all fetched gateway data
		// so that page reloads (and multi-worker SQLite in WP Playground) do not
		// lose the sender ID list, balance, or coverage between AJAX and form-save.
		$gw                         = get_option( 'kwtsms_otp_gateway', array() );
		$gw['credentials_verified'] = 1;
		$gw['api_username']         = $username;
		$gw['api_password']         = $password;
		$gw['test_mode']            = 1; // Always default to Test Mode ON after login.
		$gw['sender_ids']           = (array) $sender_ids;
		$gw['coverage']             = $coverage_arr;
		if ( ! is_wp_error( $balance ) ) {
			$gw['balance_available']  = $balance['available'];
			$gw['balance_purchased']  = $balance['purchased'];
			$gw['balance_updated_at'] = time();
		}
		update_option( 'kwtsms_otp_gateway', $gw );

		$balance_data = is_wp_error( $balance ) ? null : $balance;

		wp_send_json_success(
			array(
				'sender_ids' => $sender_ids,
				'balance'    => $balance_data,
				'coverage'   => $coverage_arr,
			)
		);
	}

	/**
	 * AJAX: Reload, refresh sender IDs, balance, and coverage using saved credentials.
	 *
	 * Unlike ajax_verify_credentials (which expects credentials in the POST body),
	 * this handler reads the stored API username and password from the database so
	 * the Reload button works even when the password field is not visible in the UI.
	 * Returns the same JSON shape as ajax_verify_credentials so handleVerifyResponse()
	 * can process both responses identically.
	 */
	public function ajax_reload_all() {
		check_ajax_referer( 'kwtsms_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		$gw       = get_option( 'kwtsms_otp_gateway', array() );
		$username = $gw['api_username'] ?? '';
		$password = $gw['api_password'] ?? '';

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( array( 'message' => __( 'No API credentials saved. Please save your credentials first.', 'kwtsms' ) ) );
			return;
		}

		$test_mode  = ! empty( $gw['test_mode'] );
		$api        = new KwtSMS_API( $username, $password, $test_mode );
		$sender_ids = $api->get_sender_ids();

		if ( is_wp_error( $sender_ids ) ) {
			wp_send_json_error( array( 'message' => $sender_ids->get_error_message() ) );
			return;
		}

		$balance      = $api->get_balance();
		$coverage     = $api->get_coverage();
		$coverage_arr = ( ! is_wp_error( $coverage ) ) ? (array) $coverage : array();

		// Persist refreshed data (credentials + verified flag are unchanged).
		$gw['sender_ids'] = (array) $sender_ids;
		$gw['coverage']   = $coverage_arr;
		if ( ! is_wp_error( $balance ) ) {
			$gw['balance_available']  = $balance['available'];
			$gw['balance_purchased']  = $balance['purchased'];
			$gw['balance_updated_at'] = time();
		}
		update_option( 'kwtsms_otp_gateway', $gw );

		wp_send_json_success(
			array(
				'sender_ids' => $sender_ids,
				'balance'    => is_wp_error( $balance ) ? null : $balance,
				'coverage'   => $coverage_arr,
			)
		);
	}

	/**
	 * AJAX: resend OTP to the user's phone.
	 *
	 * Called from the OTP entry page when the user clicks "Resend".
	 * Supports both login 2FA and password-reset flows by reading the
	 * `context` POST param ('login' or 'reset') and resolving the correct
	 * transient prefix accordingly.
	 *
	 * Rate limiting is enforced inside KwtSMS_OTP_Engine.
	 */
	public function ajax_resend_otp() {
		check_ajax_referer( 'kwtsms_otp_nonce', 'nonce' );

		$session_token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
		$context       = sanitize_key( wp_unslash( $_POST['context'] ?? 'login' ) );

		// Normalise context to either 'reset' or 'login'.
		if ( 'reset' !== $context ) {
			$context = 'login';
		}

		if ( empty( $session_token ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid session.', 'kwtsms' ) ) );
			return;
		}

		// Resolve transient prefix, template ID, and SMS action by context.
		if ( 'reset' === $context ) {
			$transient_key = KwtSMS_Reset_OTP::RESET_TRANSIENT_PREFIX . $session_token;
			$template_id   = 'reset_otp';
			$sms_action    = 'reset';
			$otp_action    = 'reset';
			$expired_msg   = __( 'Session expired. Please start the password reset process again.', 'kwtsms' );
		} else {
			$transient_key = 'kwtsms_partial_auth_' . $session_token;
			$template_id   = 'login_otp';
			$sms_action    = 'login';
			$otp_action    = 'login';
			$expired_msg   = __( 'Session expired. Please log in again.', 'kwtsms' );
		}

		// Retrieve session to find the user.
		$partial = get_transient( $transient_key );
		if ( ! $partial ) {
			wp_send_json_error( array( 'message' => $expired_msg ) );
			return;
		}

		$user_id = absint( $partial['user_id'] );
		$phone   = get_user_meta( $user_id, 'kwtsms_phone', true );

		if ( empty( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'No phone number on this account.', 'kwtsms' ) ) );
			return;
		}

		// Rate limit checks: per-phone, per-IP, per-account.
		if ( $this->otp->is_rate_limited( $phone ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Too many requests. Please wait before trying again.', 'kwtsms' ),
					'cooldown' => $this->settings->get( 'general.resend_cooldown', 60 ),
				)
			);
			return;
		}
		if ( $this->otp->is_ip_rate_limited( $otp_action, $user_id, $phone ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Too many requests from this location. Please wait before trying again.', 'kwtsms' ),
					'cooldown' => $this->settings->get( 'general.resend_cooldown', 60 ),
				)
			);
			return;
		}
		if ( $this->otp->is_user_rate_limited( $user_id, $otp_action, $phone ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Too many requests for this account. Please wait before trying again.', 'kwtsms' ),
					'cooldown' => $this->settings->get( 'general.resend_cooldown', 60 ),
				)
			);
			return;
		}

		// Anti-enumeration: silently succeed for blocked phones without sending SMS.
		if ( $this->otp->is_phone_blocked( $phone ) ) {
			$this->api->write_debug_log( 'form_otp', 'Blocked phone attempted OTP: ' . $phone );
			wp_send_json_success(
				array(
					'message'  => __( 'A new code has been sent to your phone.', 'kwtsms' ),
					'cooldown' => $this->settings->get( 'general.resend_cooldown', 60 ),
				)
			);
			return;
		}

		// Server-side send-cooldown guard: prevents rapid-fire resend requests even
		// if the client-side countdown is bypassed (e.g. via direct AJAX call).
		if ( $this->otp->is_send_cooldown_active( $user_id, $otp_action ) ) {
			wp_send_json_error(
				array(
					'message'  => __( 'Please wait before requesting another code.', 'kwtsms' ),
					'cooldown' => $this->settings->get( 'general.resend_cooldown', 60 ),
				)
			);
			return;
		}

		// Generate OTP (reuses existing valid code) and send SMS.
		$otp_code = $this->otp->generate( $user_id, $otp_action );
		$message  = $this->otp->build_message( $otp_code, $template_id );
		$result   = $this->api->send(
			$phone,
			$this->settings->get( 'gateway.sender_id', '' ),
			$message,
			$sms_action
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		$this->otp->set_send_cooldown( $user_id, $otp_action );

		// Sliding-window counters are recorded inside is_rate_limited(),
		// is_ip_rate_limited(), and is_user_rate_limited(); no separate
		// increment calls needed.

		wp_send_json_success(
			array(
				'message'  => __( 'A new code has been sent to your phone.', 'kwtsms' ),
				'cooldown' => $this->settings->get( 'general.resend_cooldown', 60 ),
			)
		);
	}

	// =========================================================================
	// Trusted Devices
	// =========================================================================

	/**
	 * Revoke all trusted devices for a user when they reset their password.
	 *
	 * Hooked to `password_reset` action. Ensures that after a password reset,
	 * any previously trusted devices are no longer trusted.
	 *
	 * @param WP_User $user The user whose password was reset.
	 */
	public function on_password_reset_revoke_devices( $user ) {
		( new KwtSMS_Trusted_Devices() )->revoke_all( (int) $user->ID );
	}

	/**
	 * Render the trusted devices table in the user profile edit screen.
	 *
	 * Shows each trusted device with its last-seen time and partial UA string.
	 * Each row has an individual "Revoke" button; a "Revoke all" link appears
	 * at the bottom of the section. Both actions are AJAX-powered.
	 *
	 * Hooked to `show_user_profile` and `edit_user_profile`.
	 *
	 * @param WP_User $user The user whose profile is being edited.
	 */
	public function render_trusted_devices_profile( $user ) {
		// Only show when login OTP / 2FA is enabled.
		if ( ! $this->settings->get( 'general.login_otp', 1 ) ) {
			return;
		}

		$trusted  = new KwtSMS_Trusted_Devices();
		$devices  = $trusted->get_devices( $user->ID );
		$nonce    = wp_create_nonce( 'kwtsms_profile_nonce' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<h2><?php esc_html_e( 'Trusted Devices', 'kwtsms' ); ?></h2>
		<table class="form-table" id="kwtsms-trusted-devices-table" data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-ajax="<?php echo esc_url( $ajax_url ); ?>">
			<tbody>
			<?php if ( empty( $devices ) ) : ?>
				<tr>
					<td colspan="3">
						<em><?php esc_html_e( 'No trusted devices.', 'kwtsms' ); ?></em>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th><?php esc_html_e( 'Last seen', 'kwtsms' ); ?></th>
					<th><?php esc_html_e( 'Device', 'kwtsms' ); ?></th>
					<th></th>
				</tr>
				<?php foreach ( $devices as $device ) : ?>
				<tr class="kwtsms-device-row" data-hash="<?php echo esc_attr( $device['token_hash'] ); ?>">
					<td>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: human-readable time difference */
								__( '%s ago', 'kwtsms' ),
								human_time_diff( (int) $device['last_seen'] )
							)
						);
						?>
					</td>
					<td><?php echo esc_html( substr( $device['ua'], 0, 60 ) ); ?></td>
					<td>
						<button type="button" class="button button-small kwtsms-revoke-device">
							<?php esc_html_e( 'Revoke', 'kwtsms' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $devices ) ) : ?>
		<p>
			<a href="#" id="kwtsms-revoke-all-devices">
				<?php esc_html_e( 'Revoke all trusted devices', 'kwtsms' ); ?>
			</a>
		</p>
		<?php endif; ?>
		<?php
		wp_add_inline_script(
			'common',
			'(function(){' .
			'var table=document.getElementById("kwtsms-trusted-devices-table");' .
			'if(!table)return;' .
			'var userId=table.dataset.userId;' .
			'var nonce=table.dataset.nonce;' .
			'var ajaxUrl=table.dataset.ajax;' .
			'table.addEventListener("click",function(e){' .
				'if(!e.target.classList.contains("kwtsms-revoke-device"))return;' .
				'e.preventDefault();' .
				'var row=e.target.closest(".kwtsms-device-row");' .
				'var hash=row?row.dataset.hash:"";' .
				'if(!hash)return;' .
				'var fd=new FormData();' .
				'fd.append("action","kwtsms_revoke_device");' .
				'fd.append("nonce",nonce);' .
				'fd.append("user_id",userId);' .
				'fd.append("token_hash",hash);' .
				'fetch(ajaxUrl,{method:"POST",body:fd})' .
					'.then(function(r){return r.json();})' .
					'.then(function(json){if(json.success&&row){row.remove();}});' .
			'});' .
			'var revokeAll=document.getElementById("kwtsms-revoke-all-devices");' .
			'if(revokeAll){' .
				'revokeAll.addEventListener("click",function(e){' .
					'e.preventDefault();' .
					'var fd=new FormData();' .
					'fd.append("action","kwtsms_revoke_all_devices");' .
					'fd.append("nonce",nonce);' .
					'fd.append("user_id",userId);' .
					'fetch(ajaxUrl,{method:"POST",body:fd})' .
						'.then(function(r){return r.json();})' .
						'.then(function(json){' .
							'if(json.success){' .
								'table.querySelectorAll(".kwtsms-device-row").forEach(function(r){r.remove();});' .
								'revokeAll.parentElement.remove();' .
							'}' .
						'});' .
				'});' .
			'}' .
			'}());'
		);
		?>
		<?php
	}

	/**
	 * AJAX: revoke a single trusted device by token hash.
	 *
	 * Accepts: nonce, user_id (int), token_hash (sha256 hex string).
	 * Capability check: current user must be able to edit the target user.
	 *
	 * Security: nonce verified, capability checked.
	 */
	public function ajax_revoke_device() {
		check_ajax_referer( 'kwtsms_profile_nonce', 'nonce' );

		$target_user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( ! current_user_can( 'edit_user', $target_user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		$token_hash = sanitize_text_field( wp_unslash( $_POST['token_hash'] ?? '' ) );
		if ( empty( $token_hash ) || ! preg_match( '/^[0-9a-f]{64}$/', $token_hash ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid token hash.', 'kwtsms' ) ) );
			return;
		}

		( new KwtSMS_Trusted_Devices() )->revoke_by_hash( $target_user_id, $token_hash );
		wp_send_json_success();
	}

	/**
	 * AJAX: revoke all trusted devices for a user.
	 *
	 * Accepts: nonce, user_id (int).
	 * Capability check: current user must be able to edit the target user.
	 *
	 * Security: nonce verified, capability checked.
	 */
	public function ajax_revoke_all_devices() {
		check_ajax_referer( 'kwtsms_profile_nonce', 'nonce' );

		$target_user_id = absint( wp_unslash( $_POST['user_id'] ?? 0 ) );
		if ( ! current_user_can( 'edit_user', $target_user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		( new KwtSMS_Trusted_Devices() )->revoke_all( $target_user_id );
		wp_send_json_success();
	}

	/**
	 * AJAX: send a test SMS to the configured test phone.
	 *
	 * Security: nonce + manage_options.
	 */
	public function ajax_send_test_sms() {
		check_ajax_referer( 'kwtsms_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		if ( empty( $phone ) ) {
			$phone = $this->settings->get( 'gateway.test_phone', '' );
		}

		// Auto-prepend default country code for short (local) numbers.
		$phone = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );

		$normalized = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $normalized ) ) {
			wp_send_json_error( array( 'message' => $normalized->get_error_message() ) );
			return;
		}

		// Reject numbers that are too short: country code + local number must be at least 10 digits.
		// e.g. Kuwait: 965 (3) + 8 local = 11 total. Anything under 10 is clearly incomplete.
		if ( strlen( $normalized ) < 10 ) {
			wp_send_json_error(
				array(
					'message' => __( 'Phone number is too short. Enter the country code followed by the full local number, e.g. 96512345678 (Kuwait: 965 + 8 digits, 11 digits total).', 'kwtsms' ),
				)
			);
			return;
		}

		// Build the test message: site name + timestamp in Gulf/Kuwait time (GMT+3).
		try {
			$tz_obj = new DateTimeZone( 'Asia/Kuwait' );
			$dt     = new DateTime( 'now', $tz_obj );
			$stamp  = $dt->format( 'Y-m-d H:i' ) . ' GMT+3';
		} catch ( Exception $e ) {
			$stamp = gmdate( 'Y-m-d H:i' ) . ' GMT+3';
		}
		$site_name = get_bloginfo( 'name' );
		$message   = "Test SMS message from {$site_name}\nStamp: {$stamp}";

		$result = $this->api->send(
			$normalized,
			$this->settings->get( 'gateway.sender_id', '' ),
			$message,
			'test'
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		$is_test_mode = (bool) $this->settings->get( 'gateway.test_mode', false );

		// Re-read the gateway option to pick up any balance update made by send().
		$gw_option = get_option( 'kwtsms_otp_gateway', array() );

		wp_send_json_success(
			array(
				'phone'     => esc_html( $normalized ),
				'test_mode' => $is_test_mode,
				'msg_id'    => esc_html( $result['msg_id'] ),
				'balance'   => array(
					'available' => isset( $gw_option['balance_available'] ) ? (float) $gw_option['balance_available'] : null,
					'purchased' => isset( $gw_option['balance_purchased'] ) ? (float) $gw_option['balance_purchased'] : null,
				),
			)
		);
	}
}
