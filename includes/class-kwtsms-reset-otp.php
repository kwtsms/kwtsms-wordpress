<?php
/**
 * Password Reset via OTP.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Replaces (or supplements) the default WordPress email-based password reset
 * with an SMS OTP verification step.
 *
 * Flow:
 *   1. User visits /wp-login.php?action=lostpassword.
 *   2. lostpassword_post: we intercept, look up user by login/email/phone,
 *      generate OTP, send SMS, store partial reset session, redirect to OTP page.
 *   3. OTP entry page (action=kwtsms_reset_otp): user enters code.
 *   4. On valid OTP: generate WP reset key, redirect to standard rp page.
 *   5. User sets new password via standard WP reset flow.
 *
 * Fallback: if user has no phone on file and email reset is not disabled,
 * fall back to the default WP email reset flow.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Reset_OTP
 */
class KwtSMS_Reset_OTP {

	/**
	 * Plugin manager.
	 *
	 * @var KwtSMS_Plugin
	 */
	private $plugin;

	/**
	 * Transient key prefix for reset sessions.
	 *
	 * @var string
	 */
	const RESET_TRANSIENT_PREFIX = 'kwtsms_reset_session_';

	/**
	 * Cookie name for reset session.
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
		$this->cookie_name = 'kwtsms_reset_' . COOKIEHASH;

		add_action( 'login_init', array( $this, 'maybe_intercept_password_reset' ), 1 );
		add_action( 'login_form_lostpassword', array( $this, 'add_phone_field_to_lost_password' ) );
		add_action( 'login_init', array( $this, 'handle_reset_otp_action' ) );

		// Register custom login action so wp-login.php preserves the global $action.
		add_filter( 'login_form_kwtsms_reset_otp', '__return_true' );
	}

	/**
	 * Intercept the lostpassword form submission early, before any output.
	 *
	 * Fires on `login_init` at priority 1, before login_header() is called
	 * and before login_form_lostpassword outputs content, so wp_safe_redirect
	 * can send headers cleanly.
	 *
	 * Only acts on POST requests to action=lostpassword.
	 */
	public function maybe_intercept_password_reset() {
		if ( 'POST' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return;
		}
		// WordPress sets the global $action variable in wp-login.php before login_init fires.
		// Reading it directly avoids filter_input() which the WP.org scanner flags.
		global $action;
		if ( 'lostpassword' !== $action ) {
			return;
		}

		// Verify the WordPress lostpassword form nonce. Fail early if missing or invalid.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'lostpassword' ) ) {
			return;
		}

		// Try to find user from form input (login, email, or phone).
		$input = sanitize_text_field( wp_unslash( $_POST['user_login'] ?? '' ) );

		if ( empty( $input ) ) {
			return;
		}

		$resolved_user = $this->resolve_user( $input );

		if ( ! ( $resolved_user instanceof WP_User ) ) {
			// No user found: fall through to WP default (generic confirmation).
			return;
		}

		$phone = get_user_meta( $resolved_user->ID, 'kwtsms_phone', true );

		if ( empty( $phone ) ) {
			$this->plugin->api->write_debug_log( 'reset_otp', 'Falling back to email reset for user #' . $resolved_user->ID . ' (' . $resolved_user->user_login . '): no phone number on file' );
			return;
		}

		// Rate-limit checks: per-phone, per-IP, per-account.
		// Errors are injected via lostpassword_post so WP renders the form with the message.
		$rate_limited = $this->plugin->otp->is_rate_limited( $phone, 'reset', $resolved_user->ID )
			|| $this->plugin->otp->is_ip_rate_limited( 'reset', $resolved_user->ID, $phone )
			|| $this->plugin->otp->is_user_rate_limited( $resolved_user->ID, 'reset', $phone );

		if ( $rate_limited ) {
			add_action(
				'lostpassword_post',
				function ( $errors ) {
					$errors->add(
						'kwtsms_rate_limited',
						__( 'Too many requests. Please wait a few minutes before trying again.', 'kwtsms' )
					);
				}
			);
			return;
		}

		// Anti-enumeration: silently succeed for blocked phones without sending SMS.
		if ( $this->plugin->otp->is_phone_blocked( $phone ) ) {
			add_filter( 'send_retrieve_password_email', '__return_false' );
			wp_safe_redirect(
				add_query_arg( 'action', 'kwtsms_reset_otp', wp_login_url() )
			);
			exit;
		}

		// IP blocklist: silently pretend success (anti-enumeration).
		$client_ip = $this->plugin->otp->get_client_ip();
		if ( '' !== $client_ip && $this->plugin->otp->is_ip_blocklisted( $client_ip ) ) {
			$this->plugin->api->write_debug_log( 'reset_otp', 'Blocklisted IP attempted OTP: ' . $client_ip );
			add_filter( 'send_retrieve_password_email', '__return_false' );
			wp_safe_redirect(
				add_query_arg( 'action', 'kwtsms_reset_otp', wp_login_url() )
			);
			exit;
		}

		// Send SMS only if outside the send-cooldown (prevents double-send on double-click/race).
		// Cooldown is set BEFORE sending so concurrent requests see the lock immediately.
		if ( ! $this->plugin->otp->is_send_cooldown_active( $resolved_user->ID, 'reset' ) ) {
			$this->plugin->otp->set_send_cooldown( $resolved_user->ID, 'reset' );

			$otp_code = $this->plugin->otp->generate( $resolved_user->ID, 'reset' );
			$message  = $this->plugin->otp->build_message( $otp_code, 'reset_otp' );
			$result   = $this->plugin->api->send(
				$phone,
				$this->plugin->settings->get( 'gateway.sender_id', '' ),
				$message,
				'reset'
			);

			if ( is_wp_error( $result ) ) {
				$this->plugin->api->write_debug_log( 'reset_otp', 'SMS failed for user ' . $resolved_user->ID . ': ' . $result->get_error_message() );
				// Fail gracefully: fall back to email reset.
				return;
			}
		}

		// Sliding-window counters are recorded inside is_rate_limited(),
		// is_ip_rate_limited(), and is_user_rate_limited(); no separate
		// increment calls needed.

		// Store reset session.
		$token = wp_generate_password( 40, false );
		set_transient(
			self::RESET_TRANSIENT_PREFIX . $token,
			array(
				'user_id' => $resolved_user->ID,
				'phone'   => $phone,
			),
			15 * MINUTE_IN_SECONDS
		);

		// Set cookie.
		$this->set_reset_cookie( $token );

		// Prevent WP from sending the email reset.
		add_filter( 'send_retrieve_password_email', '__return_false' );

		// Redirect to OTP page: no output has been sent yet, so headers are clean.
		wp_safe_redirect(
			add_query_arg( 'action', 'kwtsms_reset_otp', wp_login_url() )
		);
		exit;
	}

	/**
	 * Add a note about SMS OTP to the lost-password form.
	 */
	public function add_phone_field_to_lost_password() {
		printf(
			'<p class="message" style="margin-bottom:12px;">%s</p>',
			esc_html__( 'If your account has a phone number registered, an OTP will be sent instead of an email reset link.', 'kwtsms' )
		);
	}

	/**
	 * Handle the kwtsms_reset_otp action on wp-login.php.
	 *
	 * Fires on `login_init`. If action matches, handles GET (render) and POST (verify).
	 */
	public function handle_reset_otp_action() {
		// WordPress sets the global $action variable in wp-login.php before login_init fires.
		// Reading it directly avoids filter_input() which the WP.org scanner flags.
		global $action;
		$kwtsms_action = ! empty( $action ) ? $action : 'login';

		if ( 'kwtsms_reset_otp' !== $kwtsms_action ) {
			return;
		}

		if ( 'POST' === sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			$this->handle_reset_otp_submission();
		}

		$this->render_reset_otp_page();
		exit;
	}

	/**
	 * Handle the OTP verification form for password reset.
	 */
	private function handle_reset_otp_submission() {
		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['kwtsms_reset_nonce'] ?? '' ) ),
			'kwtsms_reset_otp_submit'
		) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'kwtsms' ) );
		}

		$token = $this->get_reset_cookie_token();
		if ( empty( $token ) ) {
			$this->render_reset_otp_page( __( 'Session expired. Please start over.', 'kwtsms' ) );
			exit;
		}

		$session = get_transient( self::RESET_TRANSIENT_PREFIX . $token );
		if ( ! $session ) {
			$this->clear_reset_cookie();
			$this->render_reset_otp_page( __( 'Session expired. Please start over.', 'kwtsms' ) );
			exit;
		}

		$user_id         = absint( $session['user_id'] );
		$reset_phone     = sanitize_text_field( $session['phone'] ?? '' );
		$raw_code        = sanitize_text_field( wp_unslash( $_POST['kwtsms_code'] ?? '' ) );
		$submitted       = preg_replace( '/\D/', '', $raw_code ); // Digits only.
		$expected_length = (int) $this->plugin->settings->get( 'general.otp_length', 6 );
		$raw_ip          = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip              = filter_var( $raw_ip, FILTER_VALIDATE_IP ) ? $raw_ip : '';

		// Detect and log suspicious input.
		$is_suspicious = ( strlen( $raw_code ) > 0 && strlen( $raw_code ) !== strlen( $submitted ) )
			|| ( strlen( $raw_code ) > $expected_length + 4 );

		if ( $is_suspicious ) {
			KwtSMS_API::append_attempt_log( $user_id, $reset_phone, $ip, 'reset', 'invalid_input' );
		}

		// Empty code.
		if ( '' === $submitted ) {
			$this->render_reset_otp_page( __( 'Please enter your verification code.', 'kwtsms' ) );
			exit;
		}

		$result = $this->plugin->otp->verify( $user_id, $submitted, 'reset', $user_id, $reset_phone );

		switch ( $result ) {
			case 'valid':
				delete_transient( self::RESET_TRANSIENT_PREFIX . $token );
				$this->clear_reset_cookie();
				// 2026-11-11 20:47 +03: the code reached this phone, mark it verified, task wp-363.
				KwtSMS_User_Meta::mark_phone_verified( $user_id, $reset_phone );

				// Generate a WP password reset key so the user can use the standard reset form.
				$user = get_userdata( $user_id );
				if ( ! $user ) {
					wp_safe_redirect( wp_login_url() );
					exit;
				}

				$reset_key = get_password_reset_key( $user );
				if ( is_wp_error( $reset_key ) ) {
					$this->render_reset_otp_page( __( 'Could not generate reset link. Please try again.', 'kwtsms' ) );
					exit;
				}

				$rp_url = add_query_arg(
					array(
						'action' => 'rp',
						'key'    => rawurlencode( $reset_key ),
						'login'  => rawurlencode( $user->user_login ),
					),
					network_site_url( 'wp-login.php', 'login' )
				);

				wp_safe_redirect( $rp_url );
				exit;

			case 'invalid':
				$remaining = $this->plugin->otp->get_remaining_attempts( $user_id );
				if ( 0 === $remaining ) {
					// Transient expired between verify() and get_remaining_attempts(): treat as expired.
					$this->render_reset_otp_page(
						__( 'Your code has expired. Please go back and request a new one.', 'kwtsms' )
					);
				} else {
					$this->render_reset_otp_page(
						sprintf(
							/* translators: %d: remaining attempts */
							_n(
								'Incorrect code. %d attempt remaining.',
								'Incorrect code. %d attempts remaining.',
								$remaining,
								'kwtsms'
							),
							$remaining
						)
					);
				}
				exit;

			case 'expired':
				$this->render_reset_otp_page(
					__( 'Your code has expired. Please go back and request a new one.', 'kwtsms' )
				);
				exit;

			case 'max_attempts':
				delete_transient( self::RESET_TRANSIENT_PREFIX . $token );
				$this->clear_reset_cookie();
				$this->render_reset_otp_page(
					__( 'Too many incorrect attempts. Please start the password reset process again.', 'kwtsms' )
				);
				exit;

			default:
				$this->render_reset_otp_page( __( 'Something went wrong. Please try again.', 'kwtsms' ) );
				exit;
		}
	}

	/**
	 * Render the reset OTP entry page.
	 *
	 * @param string $error_message Optional error message.
	 */
	private function render_reset_otp_page( $error_message = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- used in included view.
		nocache_headers();
		wp_enqueue_style( 'login', admin_url( 'css/login.css' ), array(), KWTSMS_OTP_VERSION );
		wp_enqueue_style( 'kwtsms-login', KWTSMS_OTP_URL . 'assets/css/login.css', array( 'login' ), KWTSMS_OTP_VERSION );
		if ( is_rtl() ) {
			wp_enqueue_style( 'kwtsms-login-rtl', KWTSMS_OTP_URL . 'assets/css/login-rtl.css', array( 'kwtsms-login' ), KWTSMS_OTP_VERSION );
		}
		wp_enqueue_script( 'kwtsms-login-js', KWTSMS_OTP_URL . 'assets/js/login.js', array(), KWTSMS_OTP_VERSION, true );
		$otp_length      = (int) $this->plugin->settings->get( 'general.otp_length', 6 );
		$cooldown        = (int) $this->plugin->settings->get( 'general.resend_cooldown', 60 );
		$is_reset        = true;
		$login_url       = wp_login_url();
		$nonce_resend    = wp_create_nonce( 'kwtsms_otp_nonce' );
		$token           = $this->get_reset_cookie_token();
		$redirect_to     = '';
		$plugin_settings = $this->plugin->settings;
		include KWTSMS_OTP_DIR . 'includes/views/page-otp.php';
	}

	// =========================================================================
	// User resolution
	// =========================================================================

	/**
	 * Resolve a user from login name, email, or phone number.
	 *
	 * @param string $input The user-provided identifier.
	 *
	 * @return WP_User|false
	 */
	private function resolve_user( $input ) {
		// Try login.
		$user = get_user_by( 'login', $input );
		if ( $user ) {
			return $user;
		}

		// Try email.
		$user = get_user_by( 'email', $input );
		if ( $user ) {
			return $user;
		}

		// Try phone meta (normalize first).
		$normalized = KwtSMS_API::normalize_phone( $input );
		if ( ! is_wp_error( $normalized ) ) {
			$users = get_users(
				array(
					'meta_key'   => 'kwtsms_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $normalized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
					'fields'     => 'all',
				)
			);
			if ( ! empty( $users ) ) {
				return $users[0];
			}
		}

		return false;
	}

	// =========================================================================
	// Cookie helpers
	// =========================================================================

	/**
	 * Set the reset session cookie.
	 *
	 * @param string $token Session token.
	 */
	private function set_reset_cookie( $token ) {
		setcookie(
			$this->cookie_name,
			$token,
			array(
				'expires'  => time() + 15 * MINUTE_IN_SECONDS,
				'path'     => COOKIEPATH,
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	/**
	 * Get the reset session token from cookie.
	 *
	 * @return string
	 */
	private function get_reset_cookie_token() {
		return sanitize_text_field( wp_unslash( $_COOKIE[ $this->cookie_name ] ?? '' ) );
	}

	/**
	 * Clear the reset session cookie.
	 */
	private function clear_reset_cookie() {
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
