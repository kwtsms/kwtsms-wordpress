<?php
/**
 * OTP Login: 2FA and Passwordless flows.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * 2FA Flow:
 *   1. authenticate filter (priority 30): intercepts validated WP_User,
 *      stores partial auth transient, sets httponly cookie, returns WP_Error.
 *   2. wp_login_failed action: detects our error code, redirects to OTP page.
 *   3. login_init (action=kwtsms_otp): renders OTP form.
 *   4. POST to kwtsms_otp: verifies OTP, issues auth cookies on success.
 *
 * Passwordless Flow:
 *   1. login_init (action=kwtsms_passwordless): renders phone entry form.
 *   2. POST: normalises phone, checks rate limit, finds user by meta,
 *      generates OTP, sends SMS, redirects to OTP entry.
 *   3. OTP entry (same kwtsms_otp action, context=passwordless).
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Login_OTP
 */
class KwtSMS_Login_OTP {

	/**
	 * Plugin manager (provides api, otp, settings).
	 *
	 * @var KwtSMS_Plugin
	 */
	private $plugin;

	/**
	 * Partial auth cookie name.
	 *
	 * @var string
	 */
	private $cookie_name;

	/**
	 * Constructor.
	 *
	 * @param KwtSMS_Plugin $plugin Plugin manager.
	 */
	public function __construct( KwtSMS_Plugin $plugin ) {
		$this->plugin      = $plugin;
		$this->cookie_name = 'kwtsms_partial_' . COOKIEHASH;

		add_filter( 'authenticate', array( $this, 'intercept_login' ), 30, 3 );
		add_action( 'wp_login_failed', array( $this, 'redirect_to_otp' ), 10, 2 );
		add_action( 'login_init', array( $this, 'handle_login_actions' ) );
		add_action( 'login_form', array( $this, 'add_passwordless_link' ) );

		// Register custom login actions so wp-login.php preserves the global $action.
		// Without these, WP resets $action to 'login' for unrecognized values.
		add_filter( 'login_form_kwtsms_otp', '__return_true' );
		add_filter( 'login_form_kwtsms_passwordless', '__return_true' );
	}

	// =========================================================================
	// 2FA intercept
	// =========================================================================

	/**
	 * Intercept validated login credentials and require OTP.
	 *
	 * Fires on the `authenticate` filter at priority 30 (after WP at 20).
	 * Only acts if $user is a valid WP_User and 2FA is enabled.
	 *
	 * @param null|WP_User|WP_Error $user     Authenticated user or error.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 *
	 * @return WP_User|WP_Error
	 */
	public function intercept_login( $user, $username, $password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// 2FA is only active when mode is '2fa' or 'both'.
		$mode = $this->plugin->settings->get( 'general.otp_mode', '2fa' );
		if ( 'passwordless' === $mode ) {
			return $user;
		}

		// Only intercept successful logins.
		if ( ! ( $user instanceof WP_User ) ) {
			return $user;
		}

		// Per-role enforcement: if a non-empty role list is configured, skip OTP
		// for users whose roles do not intersect with the required list.
		// Empty list (default) means all users must pass OTP.
		$required_roles = $this->plugin->settings->get( 'general.otp_required_roles', array() );
		if ( ! empty( $required_roles ) ) {
			$user_roles = $user->roles;
			// On multisite, super admins have an empty roles array.
			// Treat them as 'administrator' so they are subject to the same
			// OTP enforcement as regular administrators.
			if ( empty( $user_roles ) && function_exists( 'is_super_admin' ) && is_super_admin( $user->ID ) ) {
				$user_roles = array( 'administrator' );
			}
			$intersect = array_intersect( $user_roles, (array) $required_roles );
			if ( empty( $intersect ) ) {
				// User's role is not in the required list: bypass OTP, allow direct login.
				return $user;
			}
		}

		$phone = get_user_meta( $user->ID, 'kwtsms_phone', true );

		// If user has no phone, honour admin setting for bypass.
		if ( empty( $phone ) ) {
			$this->plugin->api->write_debug_log( 'login_otp', 'Bypassed 2FA for user #' . $user->ID . ' (' . $user->user_login . '): no phone number on file' );
			return $user;
		}

		// Trusted device check: if the user has a valid trusted device cookie, skip OTP.
		$trusted_devices = new KwtSMS_Trusted_Devices();
		if ( $trusted_devices->is_trusted( $user->ID ) ) {
			$old_token = $trusted_devices->get_cookie_token( $user->ID );
			$new_token = $trusted_devices->rotate_token( $user->ID, $old_token );
			$trusted_devices->set_cookie( $user->ID, $new_token );
			return $user; // Let WordPress complete the login normally.
		}

		// Rate-limit checks: per-phone, per-IP, per-account.
		if ( $this->plugin->otp->is_rate_limited( $phone, 'login', $user->ID ) ) {
			return new WP_Error(
				'kwtsms_rate_limited',
				__( 'Too many OTP requests for this number. Please wait a few minutes before trying again.', 'kwtsms' )
			);
		}
		if ( $this->plugin->otp->is_ip_rate_limited( 'login', $user->ID, $phone ) ) {
			return new WP_Error(
				'kwtsms_rate_limited',
				__( 'Too many OTP requests from this location. Please wait a few minutes before trying again.', 'kwtsms' )
			);
		}
		if ( $this->plugin->otp->is_user_rate_limited( $user->ID, 'login', $phone ) ) {
			return new WP_Error(
				'kwtsms_rate_limited',
				__( 'Too many OTP requests for this account. Please wait a few minutes before trying again.', 'kwtsms' )
			);
		}

		// Anti-enumeration: silently fail without allocating a session.
		// User sees the OTP screen but has no valid transient to verify against.
		// Clear any stale partial-auth cookie so an old session cannot be reused.
		if ( $this->plugin->otp->is_phone_blocked( $phone ) ) {
			$this->plugin->api->write_debug_log( 'login_otp', 'Blocked phone attempted OTP: ' . $phone );
			$this->clear_partial_auth_cookie();
			return new WP_Error( 'kwtsms_otp_required', '' );
		}

		// IP blocklist: silently pretend success (anti-enumeration, same as phone block).
		$client_ip = $this->plugin->otp->get_client_ip();
		if ( '' !== $client_ip && $this->plugin->otp->is_ip_blocklisted( $client_ip ) ) {
			$this->plugin->api->write_debug_log( 'login_otp', 'Blocklisted IP attempted OTP: ' . $client_ip );
			$this->clear_partial_auth_cookie();
			return new WP_Error( 'kwtsms_otp_required', '' );
		}

		// Send SMS only if outside the send-cooldown (prevents double-send on double-click/race).
		// Cooldown is set BEFORE sending so concurrent requests see the lock immediately.
		if ( ! $this->plugin->otp->is_send_cooldown_active( $user->ID, 'login' ) ) {
			$this->plugin->otp->set_send_cooldown( $user->ID, 'login' );

			// Generate OTP: reuses existing valid code if one was sent recently.
			$otp_code = $this->plugin->otp->generate( $user->ID, 'login' );
			$message  = $this->plugin->otp->build_message( $otp_code, 'login_otp' );
			$result   = $this->plugin->api->send(
				$phone,
				$this->plugin->settings->get( 'gateway.sender_id', '' ),
				$message,
				'login'
			);

			if ( is_wp_error( $result ) ) {
				$error_code = $result->get_error_code();
				$this->plugin->api->write_debug_log( 'login_otp', 'SMS send failed for user ' . $user->ID . ': ' . $result->get_error_message() );

				// Gateway not configured: OTP feature is not active yet. Skip silently.
				if ( in_array( $error_code, array( 'kwtsms_no_credentials', 'kwtsms_missing_sender_id' ), true ) ) {
					return $user;
				}

				// Insufficient credits: OTP was active but can no longer send. Alert the admin.
				// The admin-configured balance_failure_mode decides whether to block or allow.
				if ( 'no_balance' === $error_code ) {
					$this->notify_admin_no_balance();
					$mode = $this->plugin->settings->get( 'general.balance_failure_mode', 'block' );
					if ( 'allow' === $mode ) {
						// Admin opted to keep users accessible: fail-open and let login continue.
						return $user;
					}
					return new WP_Error(
						'kwtsms_sms_failed',
						__( 'Your verification code could not be sent. Please contact the site administrator.', 'kwtsms' )
					);
				}

				// Country blocked or SMS disabled: user's phone is in a disallowed country.
				// Do NOT fail-open: show OTP page but with no valid transient (anti-enumeration).
				// Clear any stale partial-auth cookie so an old session cannot be reused.
				if ( in_array( $error_code, array( 'country_not_allowed', 'kwtsms_sms_disabled' ), true ) ) {
					$this->clear_partial_auth_cookie();
					return new WP_Error( 'kwtsms_otp_required', '' );
				}

				// Temporary failure (network, API down): fail-open to avoid lockout.
				return $user;
			}
		}

		// Sliding-window counters are recorded inside is_rate_limited(),
		// is_ip_rate_limited(), and is_user_rate_limited(); no separate
		// increment calls needed.

		// Create partial auth session.
		$token = wp_generate_password( 40, false );
		set_transient(
			'kwtsms_partial_auth_' . $token,
			array(
				'user_id'    => $user->ID,
				'action'     => 'login',
				'phone'      => $phone,
				'rememberme' => false,
			),
			15 * MINUTE_IN_SECONDS
		);

		// Store token in httponly cookie.
		$this->set_partial_auth_cookie( $token );

		return new WP_Error( 'kwtsms_otp_required', '' );
	}

	/**
	 * After login failure: redirect to OTP page if our error caused it.
	 *
	 * @param string   $username Username.
	 * @param WP_Error $error    The error returned from authenticate.
	 */
	public function redirect_to_otp( $username, $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}
		if ( ! in_array( 'kwtsms_otp_required', $error->get_error_codes(), true ) ) {
			return;
		}

		// Verify the WordPress login form nonce before reading POST data.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'log-in' ) ) {
			$redirect_to = '';
		} else {
			$redirect_to = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) );
		}
		$otp_url = add_query_arg(
			array(
				'action'      => 'kwtsms_otp',
				'redirect_to' => rawurlencode( $redirect_to ),
			),
			wp_login_url()
		);

		wp_safe_redirect( $otp_url );
		exit;
	}

	// =========================================================================
	// Login page action handlers
	// =========================================================================

	/**
	 * Handle custom wp-login.php actions for OTP and passwordless flows.
	 *
	 * Fires on `login_init` at the very top of wp-login.php.
	 */
	public function handle_login_actions() {
		// WordPress sets the global $action variable in wp-login.php before login_init fires.
		// Reading it directly avoids filter_input() which the WP.org scanner flags.
		global $action;
		$kwtsms_action = ! empty( $action ) ? $action : 'login';

		if ( 'kwtsms_otp' === $kwtsms_action ) {
			if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
				$this->handle_otp_submission();
			}
			$this->render_otp_page();
			exit;
		}

		if ( 'kwtsms_passwordless' === $kwtsms_action ) {
			// 2026-11-11 12:43 +03: security fix (platform audit). The passwordless screen and
			// its POST handler answered on every site, whatever the OTP mode. Serve them only when the
			// admin chose passwordless or both; otherwise fall through to the normal login screen.
			$mode = $this->plugin->settings->get( 'general.otp_mode', '2fa' );
			if ( ! in_array( $mode, array( 'passwordless', 'both' ), true ) ) {
				return;
			}
			if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
				$this->handle_passwordless_submission();
			}
			$this->render_passwordless_page();
			exit;
		}
	}

	/**
	 * Inject a "Login with SMS" link below the login form.
	 */
	public function add_passwordless_link() {
		$mode = $this->plugin->settings->get( 'general.otp_mode', '2fa' );
		if ( ! in_array( $mode, array( 'passwordless', 'both' ), true ) ) {
			return;
		}
		printf(
			'<p style="text-align:center;margin-top:10px;"><a href="%s">%s</a></p>',
			esc_url( add_query_arg( 'action', 'kwtsms_passwordless', wp_login_url() ) ),
			esc_html__( 'Login with SMS OTP', 'kwtsms' )
		);
	}

	// =========================================================================
	// OTP form handler
	// =========================================================================

	/**
	 * Handle OTP code submission (both 2FA and passwordless contexts).
	 *
	 * On success: issues WP auth cookies and redirects.
	 * On failure: falls through and re-renders the OTP page with an error.
	 */
	private function handle_otp_submission() {
		// Nonce check.
		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['kwtsms_otp_nonce'] ?? '' ) ),
			'kwtsms_otp_submit'
		) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'kwtsms' ) );
		}

		// Retrieve session token from cookie.
		$token = $this->get_partial_auth_token();
		if ( empty( $token ) ) {
			$this->render_otp_page( __( 'Session expired. Please log in again.', 'kwtsms' ) );
			exit;
		}

		$partial = get_transient( 'kwtsms_partial_auth_' . $token );
		if ( ! $partial ) {
			$this->clear_partial_auth_cookie();
			$this->render_otp_page( __( 'Session expired. Please log in again.', 'kwtsms' ) );
			exit;
		}

		$user_id    = absint( $partial['user_id'] );
		$otp_action = sanitize_key( $partial['action'] ?? 'login' );
		$otp_phone  = sanitize_text_field( $partial['phone'] ?? '' );
		$raw_code   = sanitize_text_field( wp_unslash( $_POST['kwtsms_code'] ?? '' ) );
		$submitted  = preg_replace( '/\D/', '', $raw_code ); // Digits only.

		// Detect suspicious input: log as hacking attempt before processing.
		$expected_length = (int) $this->plugin->settings->get( 'general.otp_length', 6 );
		$raw_ip          = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip              = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';
		$raw_len         = strlen( $raw_code );

		// Log if: raw input had non-digit chars, code is empty, or code length is wrong.
		$is_suspicious = ( $raw_len > 0 && strlen( $submitted ) !== $raw_len ) // non-digit chars present.
			|| ( $raw_len > $expected_length + 4 ) // excessively long: possible injection.
			|| ( $raw_len > 0 && strlen( $submitted ) !== $expected_length && strlen( $submitted ) !== 0 ); // wrong length.

		if ( $is_suspicious ) {
			KwtSMS_API::append_attempt_log( $user_id, $otp_phone, $ip, $otp_action, 'invalid_input' );
		}

		// Empty code: no point calling verify.
		if ( '' === $submitted ) {
			$this->render_otp_page( __( 'Please enter your verification code.', 'kwtsms' ), $token );
			exit;
		}

		// 2026-11-11 20:40 +03: a passwordless session for a phone with no account keeps its code
		// in its own slot ('otp_id'), so attempts and lockout count the same as a real session.
		// user_id 0 never logs in, task wp-363.
		$otp_id = isset( $partial['otp_id'] ) ? sanitize_text_field( $partial['otp_id'] ) : $user_id;

		$result = $this->plugin->otp->verify( $otp_id, $submitted, $otp_action, $user_id, $otp_phone );
		if ( 'valid' === $result && 0 === $user_id ) {
			$result = 'invalid';
		}

		switch ( $result ) {
			case 'valid':
				$rememberme = ! empty( sanitize_key( wp_unslash( $_POST['kwtsms_rememberme'] ?? '' ) ) );
				delete_transient( 'kwtsms_partial_auth_' . $token );
				$this->clear_partial_auth_cookie();
				$this->issue_auth_and_redirect( $user_id, $rememberme );
				break;

			case 'invalid':
				$remaining = $this->plugin->otp->get_remaining_attempts( $otp_id );
				if ( 0 === $remaining ) {
					// Transient expired between verify() and get_remaining_attempts(): treat as expired.
					$this->render_otp_page(
						__( 'Your code has expired. Click "Resend" to get a new one.', 'kwtsms' ),
						$token
					);
				} else {
					$this->render_otp_page(
						sprintf(
							/* translators: %d: number of remaining attempts */
							_n(
								'Incorrect code. %d attempt remaining.',
								'Incorrect code. %d attempts remaining.',
								$remaining,
								'kwtsms'
							),
							$remaining
						),
						$token
					);
				}
				exit;

			case 'expired':
				$this->render_otp_page(
					__( 'Your code has expired. Click "Resend" to get a new one.', 'kwtsms' ),
					$token
				);
				exit;

			case 'max_attempts':
				delete_transient( 'kwtsms_partial_auth_' . $token );
				$this->clear_partial_auth_cookie();
				$this->render_otp_page(
					__( 'Too many incorrect attempts. Please log in again to request a new code.', 'kwtsms' )
				);
				exit;

			default:
				$this->render_otp_page( __( 'Something went wrong. Please try again.', 'kwtsms' ), $token );
				exit;
		}
	}

	/**
	 * Issue WordPress auth cookies and redirect after successful OTP verification.
	 *
	 * @param int  $user_id    Authenticated user ID.
	 * @param bool $rememberme Whether to issue a persistent auth cookie.
	 */
	private function issue_auth_and_redirect( $user_id, $rememberme = false ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_safe_redirect( wp_login_url() );
			exit;
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $rememberme );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing core WP hook, not defining a custom one.

		// Issue a trusted device cookie if the user checked "Trust this device".
		// Only available in the 2FA flow (not password reset).
		// Nonce was verified in handle_otp_submission() which is the only caller of this method.
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kwtsms_otp_nonce'] ?? '' ) ), 'kwtsms_otp_nonce' ) &&
			! empty( $_POST['kwtsms_trust_device'] ) ) {
			$trusted   = new KwtSMS_Trusted_Devices();
			$old_token = $trusted->get_cookie_token( $user_id ); // '' if no existing cookie.
			$new_token = $trusted->rotate_token( $user_id, $old_token );
			$trusted->set_cookie( $user_id, $new_token );
		}

		// redirect_to is passed as a hidden field in the OTP form (page-otp.php).
		// Nonce was verified in handle_otp_submission() which is the only caller of this method.
		// Sanitized by esc_url_raw and validated by wp_safe_redirect (allowlist check).
		$redirect_to = '';
		if ( isset( $_POST['redirect_to'] ) ) {
			$redirect_to = esc_url_raw( wp_unslash( $_POST['redirect_to'] ) );
		}
		if ( empty( $redirect_to ) ) {
			$redirect_to = admin_url();
		}
		wp_safe_redirect( $redirect_to );
		exit;
	}

	// =========================================================================
	// Passwordless form handler
	// =========================================================================

	/**
	 * Handle passwordless phone-number form submission.
	 *
	 * Looks up user by phone meta, generates OTP, redirects to OTP entry.
	 * 2026-11-11 20:40 +03: every valid phone gets the same redirect to the code entry page, known
	 * or not, with similar timing; only a known phone gets an SMS, task wp-363.
	 */
	private function handle_passwordless_submission() {
		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['kwtsms_passwordless_nonce'] ?? '' ) ),
			'kwtsms_passwordless_submit'
		) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'kwtsms' ) );
		}

		// CAPTCHA verification.
		$captcha        = new KwtSMS_Captcha( $this->plugin->settings );
		$captcha_result = $captcha->verify(
			array(
				'kwtsms_recaptcha_token' => sanitize_text_field( wp_unslash( $_POST['kwtsms_recaptcha_token'] ?? '' ) ),
				'cf-turnstile-response'  => sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ?? '' ) ),
			)
		);
		if ( is_wp_error( $captcha_result ) ) {
			$this->render_passwordless_page( $captcha_result->get_error_message() );
			exit;
		}

		$raw_phone  = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone'] ?? '' ) );
		$raw_phone  = KwtSMS_API::prepend_country_code_if_local( $raw_phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $raw_phone );

		if ( is_wp_error( $normalized ) ) {
			$this->render_passwordless_page( $normalized->get_error_message() );
			exit;
		}

		// Rate-limit checks: per-phone and per-IP (no user_id known yet).
		if ( $this->plugin->otp->is_rate_limited( $normalized, 'passwordless' ) ) {
			$this->render_passwordless_page(
				__( 'Too many requests. Please wait a few minutes before trying again.', 'kwtsms' )
			);
			exit;
		}
		if ( $this->plugin->otp->is_ip_rate_limited( 'passwordless', null, $normalized ) ) {
			$this->render_passwordless_page(
				__( 'Too many requests from this location. Please wait a few minutes before trying again.', 'kwtsms' )
			);
			exit;
		}

		// 2026-11-11 20:40 +03: same response for every phone. A missing gateway setup is a
		// site-wide state, so it is checked here, before the phone lookup, and every phone gets
		// the same answer, task wp-363.
		$gateway_ready = '' !== (string) $this->plugin->settings->get( 'gateway.api_username', '' )
			&& '' !== (string) $this->plugin->settings->get( 'gateway.api_password', '' )
			&& '' !== (string) $this->plugin->settings->get( 'gateway.sender_id', '' );
		if ( ! $gateway_ready ) {
			$this->plugin->api->write_debug_log( 'passwordless', 'Passwordless SMS not sent: gateway credentials or Sender ID not configured' );
			$this->render_passwordless_page( __( 'SMS login is not available. Please contact the site administrator.', 'kwtsms' ) );
			exit;
		}

		// Look up user by kwtsms_phone meta.
		$users = get_users(
			array(
				'meta_key'   => 'kwtsms_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $normalized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		// 2026-11-11 20:40 +03: same response for every phone. A phone with no account now gets
		// the same redirect to the code entry page as a known phone (no SMS is sent), instead of
		// a different message, task wp-363.
		if ( empty( $users ) ) {
			$this->plugin->api->write_debug_log( 'passwordless', 'Passwordless SMS not sent: no account has this phone number' );
			$this->passwordless_same_response( $normalized );
		}

		$user_id = (int) $users[0];

		// 2026-11-11 12:43 +03: security fix (platform audit). The per-role list only decides who
		// needs a SECOND factor after a correct password (2FA). In passwordless login the SMS code is
		// the only factor, so it is never skipped: the old role check here issued auth cookies for a
		// phone number alone.

		// Per-account rate-limit check now that user_id is known. The text is the same as the
		// per-phone limit above, which an unknown phone reaches after the same number of tries.
		if ( $this->plugin->otp->is_user_rate_limited( $user_id, 'passwordless', $normalized ) ) {
			$this->render_passwordless_page(
				__( 'Too many requests. Please wait a few minutes before trying again.', 'kwtsms' )
			);
			exit;
		}

		// Blocked phone: same response as every other phone, no SMS.
		if ( $this->plugin->otp->is_phone_blocked( $normalized ) ) {
			$this->plugin->api->write_debug_log( 'passwordless', 'Passwordless SMS not sent: phone is on the blocked list' );
			$this->passwordless_same_response( $normalized );
		}

		// IP blocklist: same response as every other phone, no SMS.
		$client_ip = $this->plugin->otp->get_client_ip();
		if ( '' !== $client_ip && $this->plugin->otp->is_ip_blocklisted( $client_ip ) ) {
			$this->plugin->api->write_debug_log( 'passwordless', 'Blocklisted IP attempted OTP: ' . $client_ip );
			$this->passwordless_same_response( $normalized );
		}

		// Send SMS only if outside the send-cooldown (prevents double-send on double-click/race).
		// Cooldown is set BEFORE sending so concurrent requests see the lock immediately.
		if ( ! $this->plugin->otp->is_send_cooldown_active( $user_id, 'passwordless' ) ) {
			$this->plugin->otp->set_send_cooldown( $user_id, 'passwordless' );

			$otp_code = $this->plugin->otp->generate( $user_id, 'passwordless' );
			$message  = $this->plugin->otp->build_message( $otp_code, 'login_otp' );
			$started  = microtime( true );
			$result   = $this->plugin->api->send(
				$normalized,
				$this->plugin->settings->get( 'gateway.sender_id', '' ),
				$message,
				'passwordless'
			);
			// 2026-11-11 20:40 +03: keep the send time so other phones wait about as long, task wp-363.
			$this->plugin->otp->note_send_duration( microtime( true ) - $started );

			if ( is_wp_error( $result ) ) {
				$error_code = $result->get_error_code();
				$this->plugin->api->write_debug_log( 'login_otp', 'Passwordless SMS failed: ' . $result->get_error_message() );

				// 2026-11-11 20:40 +03: same response for every phone. Every send error now ends on
				// the code entry page like any other phone (the user is not logged in and gets no
				// code); the reason is in the debug log and, for a zero balance, in the admin email.
				// Before, each error showed its own message only for registered phones, task wp-363.
				if ( 'no_balance' === $error_code ) {
					$this->notify_admin_no_balance();
				}
			}
		} else {
			// No new SMS inside the cooldown: wait like a send so the timing matches.
			$this->plugin->otp->pause_like_send();
		}

		// Sliding-window counters are recorded inside is_rate_limited(),
		// is_ip_rate_limited(), and is_user_rate_limited(); no separate
		// increment calls needed.

		// Create partial auth session.
		$token = wp_generate_password( 40, false );
		set_transient(
			'kwtsms_partial_auth_' . $token,
			array(
				'user_id'    => $user_id,
				'action'     => 'passwordless',
				'phone'      => $normalized,
				'rememberme' => false,
			),
			15 * MINUTE_IN_SECONDS
		);
		$this->set_partial_auth_cookie( $token );
		$this->redirect_to_passwordless_otp();
	}

	/**
	 * Answer the passwordless form exactly like a known phone, without sending an SMS.
	 *
	 * 2026-11-11 20:40 +03: same response for every phone, task wp-363. Waits about as long as a
	 * real send, opens a session with the same fields as a real one plus 'otp_id', a code slot
	 * whose code nobody receives, so wrong codes, attempt counts, lockout, expiry and resend behave
	 * the same as for a known phone. user_id 0 can never log in (see handle_otp_submission()).
	 *
	 * @param string $normalized Normalized phone number the visitor typed.
	 */
	private function passwordless_same_response( $normalized ) {
		$this->plugin->otp->pause_like_send();

		$token  = wp_generate_password( 40, false );
		$otp_id = 'pwl_' . $token;
		$this->plugin->otp->generate( $otp_id, 'passwordless' ); // Code is discarded on purpose.
		set_transient(
			'kwtsms_partial_auth_' . $token,
			array(
				'user_id'    => 0,
				'action'     => 'passwordless',
				'phone'      => $normalized,
				'rememberme' => false,
				'otp_id'     => $otp_id,
			),
			15 * MINUTE_IN_SECONDS
		);
		$this->set_partial_auth_cookie( $token );
		$this->redirect_to_passwordless_otp();
	}

	/**
	 * Redirect to the passwordless code entry page and stop.
	 *
	 * 2026-11-11 20:40 +03: shared by every passwordless outcome so the redirect is identical, task wp-363.
	 */
	private function redirect_to_passwordless_otp() {
		$otp_url = add_query_arg(
			array(
				'action'  => 'kwtsms_otp',
				'context' => 'passwordless',
			),
			wp_login_url()
		);
		wp_safe_redirect( $otp_url );
		exit;
	}

	// =========================================================================
	// Page rendering
	// =========================================================================

	/**
	 * Render the OTP code entry page.
	 *
	 * @param string $error_message Optional error message to display.
	 * @param string $token         Optional session token (from cookie if omitted).
	 */
	private function render_otp_page( $error_message = '', $token = '' ) {
		nocache_headers();
		wp_enqueue_style( 'login', admin_url( 'css/login.css' ), array(), KWTSMS_OTP_VERSION );
		wp_enqueue_style( 'kwtsms-login', KWTSMS_OTP_URL . 'assets/css/login.css', array( 'login' ), KWTSMS_OTP_VERSION );
		if ( is_rtl() ) {
			wp_enqueue_style( 'kwtsms-login-rtl', KWTSMS_OTP_URL . 'assets/css/login-rtl.css', array( 'kwtsms-login' ), KWTSMS_OTP_VERSION );
		}
		wp_enqueue_script( 'kwtsms-login-js', KWTSMS_OTP_URL . 'assets/js/login.js', array(), KWTSMS_OTP_VERSION, true );
		$token           = $token ? $token : $this->get_partial_auth_token();
		$otp_length      = (int) $this->plugin->settings->get( 'general.otp_length', 6 );
		$cooldown        = (int) $this->plugin->settings->get( 'general.resend_cooldown', 120 );
		$redirect_to     = wp_get_referer() ? esc_url_raw( wp_get_referer() ) : admin_url();
		$nonce_resend    = wp_create_nonce( 'kwtsms_otp_nonce' );
		$login_url       = wp_login_url();
		$plugin_settings = $this->plugin->settings;

		include KWTSMS_OTP_DIR . 'includes/views/page-otp.php';
	}

	/**
	 * Render the passwordless phone number entry page.
	 *
	 * @param string $error_message   Optional error.
	 * @param string $success_message Optional success/info message.
	 */
	private function render_passwordless_page( $error_message = '', $success_message = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- passed to included view.
		nocache_headers();
		wp_enqueue_style( 'login', admin_url( 'css/login.css' ), array(), KWTSMS_OTP_VERSION );
		wp_enqueue_style( 'kwtsms-login', KWTSMS_OTP_URL . 'assets/css/login.css', array( 'login' ), KWTSMS_OTP_VERSION );
		if ( is_rtl() ) {
			wp_enqueue_style( 'kwtsms-login-rtl', KWTSMS_OTP_URL . 'assets/css/login-rtl.css', array( 'kwtsms-login' ), KWTSMS_OTP_VERSION );
		}
		wp_enqueue_script( 'kwtsms-login-js', KWTSMS_OTP_URL . 'assets/js/login.js', array(), KWTSMS_OTP_VERSION, true );
		$plugin_settings = $this->plugin->settings;

		// Load country data for the dial-code dropdown.
		$all_countries = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';
		$allowed_iso2  = (array) $plugin_settings->get( 'general.allowed_countries', array( 'KW', 'SA', 'AE', 'BH', 'QA', 'OM' ) );
		$default_iso2  = (string) $plugin_settings->get( 'general.default_country_code', 'KW' );

		// Filter to only allowed countries, preserving order from allowed_iso2.
		$cc_by_iso2 = array();
		foreach ( $all_countries as $cc ) {
			$cc_by_iso2[ $cc['iso2'] ] = $cc;
		}
		$allowed_countries = array();
		foreach ( $allowed_iso2 as $iso2 ) {
			if ( isset( $cc_by_iso2[ $iso2 ] ) ) {
				$allowed_countries[] = $cc_by_iso2[ $iso2 ];
			}
		}
		// Fallback: if no allowed countries match, show all.
		if ( empty( $allowed_countries ) ) {
			$allowed_countries = $all_countries;
		}

		// GeoIP detect: pre-select visitor's country (or fall back to default).
		$detected_iso2 = KwtSMS_GeoIP::detect_iso2() ?? $default_iso2;

		// If detected country not in allowed list, fall back to default.
		$allowed_iso2_flat = array_column( $allowed_countries, 'iso2' );
		if ( ! in_array( $detected_iso2, $allowed_iso2_flat, true ) ) {
			$detected_iso2 = in_array( $default_iso2, $allowed_iso2_flat, true ) ? $default_iso2 : ( $allowed_iso2_flat[0] ?? '' );
		}

		include KWTSMS_OTP_DIR . 'includes/views/page-passwordless.php';
	}

	// =========================================================================
	// Admin notifications
	// =========================================================================

	/**
	 * Send a one-per-day admin email when SMS cannot be delivered due to zero balance.
	 *
	 * Throttled via a transient so the admin receives at most one alert per day,
	 * regardless of how many login attempts trigger the condition.
	 */
	private function notify_admin_no_balance() {
		$throttle_key = 'kwtsms_balance_alert_sent';
		if ( get_transient( $throttle_key ) ) {
			return; // Already alerted today.
		}

		$admin_email  = get_option( 'admin_email' );
		$site_name    = get_bloginfo( 'name' );
		$gateway_url  = admin_url( 'admin.php?page=kwtsms-otp-gateway' );
		$recharge_url = 'https://www.kwtsms.com/login/';

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] kwtSMS: OTP delivery failed, account balance is zero', 'kwtsms' ),
			$site_name
		);

		$mode             = $this->plugin->settings->get( 'general.balance_failure_mode', 'block' );
		$mode_description = ( 'allow' === $mode )
			? __( 'Users are currently allowed to log in without OTP (password only) until credits are restored.', 'kwtsms' )
			: __( 'Users who require OTP cannot log in until the account is recharged.', 'kwtsms' );

		$message = sprintf(
			/* translators: 1: site name, 2: gateway URL, 3: recharge URL, 4: behavior description */
			__( 'Hello,\n\nkwtSMS on %1$s could not send OTP verification codes because your account has no SMS credits.\n\n%4$s\n\nTo restore OTP, please top up your kwtSMS account:\n%3$s\n\nOnce recharged, OTP will resume automatically. You can also check your current balance on the Gateway settings page:\n%2$s\n\nThis is an automated alert from the kwtSMS WordPress plugin.', 'kwtsms' ),
			$site_name,
			$gateway_url,
			$recharge_url,
			$mode_description
		);

		wp_mail( $admin_email, $subject, $message );

		// Suppress further alerts for 24 hours.
		set_transient( $throttle_key, 1, DAY_IN_SECONDS );
	}

	// =========================================================================
	// Cookie helpers
	// =========================================================================

	/**
	 * Set the partial auth cookie.
	 *
	 * @param string $token Session token.
	 */
	private function set_partial_auth_cookie( $token ) {
		$expiry  = time() + 15 * MINUTE_IN_SECONDS;
		$secure  = is_ssl();
		$options = array(
			'expires'  => $expiry,
			'path'     => COOKIEPATH,
			'domain'   => COOKIE_DOMAIN,
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Strict',
		);
		setcookie( $this->cookie_name, $token, $options );
	}

	/**
	 * Get the partial auth token from the cookie.
	 *
	 * @return string Token, or empty string if not set.
	 */
	private function get_partial_auth_token() {
		return sanitize_text_field( wp_unslash( $_COOKIE[ $this->cookie_name ] ?? '' ) );
	}

	/**
	 * Clear the partial auth cookie.
	 */
	private function clear_partial_auth_cookie() {
		setcookie(
			$this->cookie_name,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}
}
