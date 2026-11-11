<?php
/**
 * Registration OTP Gate: verify phone number before creating a WordPress account.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Flow:
 *   1. User submits the registration form with a phone number.
 *   2. gate_registration() intercepts via the registration_errors filter (priority 10).
 *   3. If phone is present and gate mode is not disabled:
 *      a. Generate OTP (keyed on the normalised phone).
 *      b. Send OTP SMS.
 *      c. Store a pending-registration transient: username, email, raw password, phone.
 *      d. Redirect to wp-login.php?action=kwtsms_reg_otp&token={token}.
 *   4. handle_reg_otp_page() renders the OTP entry form on that URL (login_init hook).
 *   5. complete_registration() on POST:
 *      a. Retrieve the pending transient.
 *      b. Verify OTP. On success: call wp_create_user(), set auth cookie, redirect to dashboard.
 *      c. On failure: redirect back to registration with an error query arg.
 *
 * Graceful fallback: if gate mode is optional and no phone was supplied, the user
 * passes through without OTP verification.
 *
 * WooCommerce: gate_woo_registration() mirrors gate_registration() for the
 * woocommerce_registration_errors filter (My Account page registration).
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Registration_OTP_Gate
 */
class KwtSMS_Registration_OTP_Gate {

	/**
	 * Transient key prefix for pending registrations.
	 *
	 * @var string
	 */
	const PENDING_REG_PREFIX = 'kwtsms_pending_reg_';

	/**
	 * OTP action context for registration flow.
	 *
	 * @var string
	 */
	const OTP_ACTION = 'registration';

	/**
	 * Settings helper.
	 *
	 * @var KwtSMS_Settings
	 */
	private $settings;

	/**
	 * KwtSMS API client.
	 *
	 * @var KwtSMS_API
	 */
	private $api;

	/**
	 * OTP engine.
	 *
	 * @var KwtSMS_OTP_Engine
	 */
	private $otp;

	/**
	 * Constructor.
	 *
	 * @param KwtSMS_Settings   $settings Settings instance.
	 * @param KwtSMS_API        $api      API client.
	 * @param KwtSMS_OTP_Engine $otp      OTP engine.
	 */
	public function __construct( KwtSMS_Settings $settings, KwtSMS_API $api, KwtSMS_OTP_Engine $otp ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->otp      = $otp;

		add_filter( 'registration_errors', array( $this, 'prepend_reg_url_error' ), 1, 1 );
		add_filter( 'registration_errors', array( $this, 'gate_registration' ), 10, 3 );
		add_action( 'woocommerce_register_form', array( $this, 'output_woo_register_nonce' ) );
		add_filter( 'woocommerce_registration_errors', array( $this, 'gate_woo_registration' ), 10, 3 );
		add_action( 'login_init', array( $this, 'handle_reg_otp_page' ) );

		// Register custom login action so wp-login.php preserves the global $action.
		add_filter( 'login_form_kwtsms_reg_otp', '__return_true' );
	}

	// =========================================================================
	// Registration error display from redirect query args
	// =========================================================================

	/**
	 * Surface kwtsms_reg_error query-arg errors on the registration form.
	 *
	 * Hooked to registration_errors at priority 1, before any other processing.
	 * Reads the error code from the URL (set by complete_registration() redirects)
	 * and injects a human-readable message into the errors object so WordPress
	 * displays it at the top of the registration form.
	 *
	 * @param WP_Error $errors Existing registration errors.
	 *
	 * @return WP_Error Errors with any URL-sourced error prepended.
	 */
	public function prepend_reg_url_error( WP_Error $errors ): WP_Error {
		// Verify the redirect nonce before reading the error code from the URL.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_kwtsms_reg_nonce'] ?? '' ) ), 'kwtsms_reg_redirect' ) ) {
			return $errors;
		}
		$error_code = sanitize_key( wp_unslash( $_GET['kwtsms_reg_error'] ?? '' ) );

		if ( '' === $error_code ) {
			return $errors;
		}

		$messages = array(
			'expired'       => __( 'Verification expired. Please register again.', 'kwtsms' ),
			'security'      => __( 'Security check failed. Please register again.', 'kwtsms' ),
			'create_failed' => __( 'Account creation failed. Please try again.', 'kwtsms' ),
			'max_attempts'  => __( 'Too many failed attempts. Please register again.', 'kwtsms' ),
		);

		if ( isset( $messages[ $error_code ] ) ) {
			$errors->add( 'kwtsms_reg_' . $error_code, $messages[ $error_code ] );
		}

		return $errors;
	}

	// =========================================================================
	// Standard WP registration gate
	// =========================================================================

	/**
	 * Intercept standard WordPress registration and require OTP when gate is active.
	 *
	 * Hooked to registration_errors at priority 10.
	 *
	 * @param WP_Error $errors               Existing registration errors.
	 * @param string   $sanitized_user_login Sanitized username from the form.
	 * @param string   $user_email           Email address from the form.
	 *
	 * @return WP_Error Unchanged errors, or a redirect never returns (wp_safe_redirect + exit).
	 */
	public function gate_registration( WP_Error $errors, $sanitized_user_login, $user_email ) {
		$gate = $this->settings->get( 'general.registration_otp_gate', 'disabled' );

		if ( 'disabled' === $gate ) {
			return $errors;
		}

		// WordPress registration form nonce.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'register' ) ) {
			return $errors;
		}

		$phone = trim(
			sanitize_text_field( wp_unslash( $_POST['kwtsms_phone'] ?? '' ) )
		);

		if ( '' === $phone ) {
			if ( 'required' === $gate ) {
				$errors->add(
					'phone_required',
					__( 'A phone number is required to register.', 'kwtsms' )
				);
			}
			return $errors;
		}

		// Do not proceed with OTP if other registration errors already exist.
		if ( $errors->has_errors() ) {
			return $errors;
		}

		$raw_pass = isset( $_POST['pass1'] ) ? sanitize_text_field( wp_unslash( $_POST['pass1'] ) ) : '';
		if ( '' === $raw_pass && isset( $_POST['password'] ) ) {
			$raw_pass = sanitize_text_field( wp_unslash( $_POST['password'] ) );
		}
		$password = $raw_pass;

		$result = $this->send_registration_otp( $sanitized_user_login, $user_email, $password, $phone );
		// send_registration_otp() calls wp_safe_redirect() + exit on success (returns null).
		// If it returns a WP_Error, SMS failed: block registration and surface the error.
		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
		return $errors;
	}

	// =========================================================================
	// WooCommerce My Account registration gate
	// =========================================================================

	/**
	 * Output a nonce field on the WooCommerce registration form.
	 *
	 * Paired with nonce verification in gate_woo_registration().
	 */
	public function output_woo_register_nonce() {
		wp_nonce_field( 'kwtsms_woo_register', 'kwtsms_woo_register_nonce' );
	}

	/**
	 * Intercept WooCommerce My Account registration and require OTP when gate is active.
	 *
	 * Hooked to woocommerce_registration_errors at priority 10.
	 * WooCommerce uses a 'password' field (not 'pass1') on the My Account form.
	 *
	 * @param WP_Error $errors   Existing registration errors.
	 * @param string   $username Username from the form.
	 * @param string   $email    Email address from the form.
	 *
	 * @return WP_Error Unchanged errors, or a redirect never returns.
	 */
	public function gate_woo_registration( WP_Error $errors, $username, $email ) {
		$gate = $this->settings->get( 'general.registration_otp_gate', 'disabled' );

		if ( 'disabled' === $gate ) {
			return $errors;
		}

		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kwtsms_woo_register_nonce'] ?? '' ) ), 'kwtsms_woo_register' ) ) {
			$errors->add( 'kwtsms_security', __( 'Security verification failed. Please refresh and try again.', 'kwtsms' ) );
			return $errors;
		}

		// WC My Account registration sends the field as kwtsms_phone_reg; fall back
		// to kwtsms_phone (legacy) and then billing_phone (WC guest checkout).
		$phone = trim(
			sanitize_text_field( wp_unslash( $_POST['kwtsms_phone_reg'] ?? $_POST['kwtsms_phone'] ?? $_POST['billing_phone'] ?? '' ) )
		);

		if ( '' === $phone ) {
			if ( 'required' === $gate ) {
				$errors->add(
					'phone_required',
					__( 'A phone number is required to register.', 'kwtsms' )
				);
			}
			return $errors;
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$raw_pass = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : ( isset( $_POST['pass1'] ) ? sanitize_text_field( wp_unslash( $_POST['pass1'] ) ) : '' );
		$password = $raw_pass;

		$result = $this->send_registration_otp( $username, $email, $password, $phone );
		// send_registration_otp() calls wp_safe_redirect() + exit on success (returns null).
		// If it returns a WP_Error, SMS failed: block registration and surface the error.
		if ( is_wp_error( $result ) ) {
			$errors->add( $result->get_error_code(), $result->get_error_message() );
		}
		return $errors;
	}

	// =========================================================================
	// OTP page: render and handle POST
	// =========================================================================

	/**
	 * Handle the OTP entry page for registration verification.
	 *
	 * Fires on login_init. Renders an OTP entry form when GET, and processes the
	 * submitted code on POST.
	 *
	 * URL: /wp-login.php?action=kwtsms_reg_otp&token={token}
	 */
	public function handle_reg_otp_page() {
		// WordPress passes the login action via the global $action variable on login_init.
		global $action;
		if ( 'kwtsms_reg_otp' !== $action ) {
			return;
		}

		// Verify the redirect nonce, then read the token.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_kwtsms_reg_nonce'] ?? '' ) ), 'kwtsms_reg_redirect' ) ) {
			wp_safe_redirect( wp_registration_url() );
			exit;
		}
		$token = preg_replace( '/[^a-zA-Z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ) );

		if ( empty( $token ) || ! get_transient( 'kwtsms_pending_reg_' . $token ) ) {
			wp_safe_redirect( wp_registration_url() );
			exit;
		}

		if ( 'POST' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			$this->complete_registration( $token );
			exit;
		}

		// GET: render the OTP entry form, showing an error if one was set by a prior submission.
		// Nonce already verified above at function entry.
		$reg_error_key  = sanitize_key( wp_unslash( $_GET['kwtsms_reg_error'] ?? '' ) );
		$error_messages = array(
			'invalid_code' => __( 'Invalid verification code. Please try again.', 'kwtsms' ),
			'expired'      => __( 'Your verification code has expired. Please start over.', 'kwtsms' ),
			'max_attempts' => __( 'Too many failed attempts. Please start over.', 'kwtsms' ),
			'security'     => __( 'Security check failed. Please try again.', 'kwtsms' ),
		);
		$error_message  = $error_messages[ $reg_error_key ] ?? '';
		$this->render_reg_otp_form( $token, $error_message );
		exit;
	}

	/**
	 * Complete registration after successful OTP verification.
	 *
	 * Retrieves the pending registration transient, verifies the submitted OTP,
	 * and on success calls wp_create_user() and logs the new user in.
	 *
	 * @param string $token Session token from the URL.
	 */
	private function complete_registration( $token ) {
		$token         = sanitize_text_field( $token );
		$transient_key = self::PENDING_REG_PREFIX . $token;
		$pending       = get_transient( $transient_key );

		if ( ! is_array( $pending ) ) {
			// Session expired or invalid.
			$url = add_query_arg(
				array(
					'kwtsms_reg_error'  => 'expired',
					'_kwtsms_reg_nonce' => wp_create_nonce( 'kwtsms_reg_redirect' ),
				),
				wp_registration_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		// Verify nonce before accessing any POST data (fail-early).
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['kwtsms_reg_nonce'] ?? '' ) ), 'kwtsms_reg_otp_submit' ) ) {
			$url = add_query_arg(
				array(
					'kwtsms_reg_error'  => 'security',
					'_kwtsms_reg_nonce' => wp_create_nonce( 'kwtsms_reg_redirect' ),
				),
				wp_registration_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		$submitted_code = sanitize_text_field( wp_unslash( $_POST['kwtsms_reg_code'] ?? '' ) );
		$phone          = $pending['phone'];
		$result         = $this->otp->verify( $phone, $submitted_code, self::OTP_ACTION, null, $phone );

		if ( 'valid' !== $result ) {
			if ( 'invalid' === $result ) {
				// Wrong code: redirect back to OTP entry page with error.
				$redirect = add_query_arg(
					array(
						'action'            => 'kwtsms_reg_otp',
						'token'             => rawurlencode( $token ),
						'kwtsms_reg_error'  => 'invalid_code',
						'_kwtsms_reg_nonce' => wp_create_nonce( 'kwtsms_reg_redirect' ),
					),
					wp_login_url()
				);
				wp_safe_redirect( $redirect );
				exit;
			}

			// Expired or max attempts: send back to registration form.
			$error = 'expired' === $result ? 'expired' : 'max_attempts';
			$url   = add_query_arg(
				array(
					'kwtsms_reg_error'  => $error,
					'_kwtsms_reg_nonce' => wp_create_nonce( 'kwtsms_reg_redirect' ),
				),
				wp_registration_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		// OTP is valid: create the user account.
		delete_transient( $transient_key );

		// Create the user with a temporary random password; the pre-hashed
		// password is written directly to the DB below to avoid double-hashing.
		$user_id = wp_create_user(
			$pending['username'],
			wp_generate_password( 24, true, true ),
			$pending['email']
		);

		if ( is_wp_error( $user_id ) ) {
			$url = add_query_arg(
				array(
					'kwtsms_reg_error'  => 'create_failed',
					'_kwtsms_reg_nonce' => wp_create_nonce( 'kwtsms_reg_redirect' ),
				),
				wp_registration_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		// Write the pre-hashed password directly to avoid double-hashing.
		// Do NOT call wp_set_password() here: it re-hashes the value.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->users,
			array(
				'user_pass'           => $pending['password_hash'],
				'user_activation_key' => '',
			),
			array( 'ID' => $user_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		wp_cache_delete( $user_id, 'users' );

		// Save verified phone to user meta.
		update_user_meta( $user_id, 'kwtsms_phone', $phone );
		// 2026-11-11 20:47 +03: this phone just passed an SMS code, mark it verified, task wp-363.
		KwtSMS_User_Meta::mark_phone_verified( $user_id, $phone );

		// Log the user in immediately.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );
		do_action( 'wp_login', $pending['username'], get_userdata( $user_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing core WP hook, not defining a custom one.

		// Redirect to dashboard.
		$redirect = get_dashboard_url( $user_id );
		wp_safe_redirect( $redirect );
		exit;
	}

	// =========================================================================
	// Internal helpers
	// =========================================================================

	/**
	 * Generate an OTP, send it to the phone, store the pending-registration
	 * transient, and redirect to the OTP entry page.
	 *
	 * On success, this method calls wp_safe_redirect() + exit and never returns.
	 * On failure, it returns a WP_Error so that the caller can add the error to
	 * the registration errors object and block account creation.
	 *
	 * @param string $username Username (sanitized by WP core before this is called).
	 * @param string $email    Email address.
	 * @param string $password Raw password (stored as-is for wp_create_user).
	 * @param string $phone    Raw phone number from the form.
	 *
	 * @return WP_Error Returns WP_Error on any failure; redirects and exits on success.
	 */
	private function send_registration_otp( $username, $email, $password, $phone ) {
		$sms_error = new WP_Error(
			'sms_send_failed',
			__( 'Unable to send verification code. Please try again.', 'kwtsms' )
		);

		// Normalise phone number.
		$phone = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
		$phone = KwtSMS_API::normalize_phone( $phone );

		if ( is_wp_error( $phone ) ) {
			// Invalid phone format: block registration with a descriptive error.
			return $sms_error;
		}

		// Double-submit guard: if this email already has an OTP in flight, do not
		// create a second pending transient. TTL matches the OTP expiry window.
		$email_guard_key = 'kwtsms_pending_reg_email_' . md5( $email );
		if ( get_transient( $email_guard_key ) ) {
			// A registration flow for this email is already in progress: silently
			// return the send-failed error so the gate blocks this duplicate request.
			return $sms_error;
		}

		// Rate limiting: per-phone.
		if ( $this->otp->is_rate_limited( $phone, self::OTP_ACTION, 0 ) ) {
			return $sms_error;
		}
		if ( $this->otp->is_ip_rate_limited( self::OTP_ACTION, 0, $phone ) ) {
			return $sms_error;
		}

		// Generate OTP and send SMS.
		$otp_code = $this->otp->generate( $phone, self::OTP_ACTION );
		$message  = $this->otp->build_message( $otp_code, 'login_otp' );

		$send_result = $this->api->send(
			$phone,
			$this->settings->get( 'gateway.sender_id', '' ),
			$message,
			'registration'
		);

		if ( is_wp_error( $send_result ) ) {
			return $sms_error;
		}

		// Mark this email as having an in-progress registration to prevent double-submit.
		$expiry_secs = (int) $this->settings->get( 'general.otp_expiry', 5 ) * MINUTE_IN_SECONDS;
		$ttl         = $expiry_secs + 60;
		set_transient( $email_guard_key, 1, $ttl );

		// Build and store the pending registration transient.
		$token = wp_generate_password( 32, false );

		set_transient(
			self::PENDING_REG_PREFIX . $token,
			array(
				'username'      => $username,
				'email'         => $email,
				'password_hash' => wp_hash_password( $password ),
				'phone'         => $phone,
				'created'       => time(),
			),
			$ttl
		);

		// Redirect to OTP entry page with nonce.
		$redirect = add_query_arg(
			array(
				'action'            => 'kwtsms_reg_otp',
				'token'             => rawurlencode( $token ),
				'_kwtsms_reg_nonce' => wp_create_nonce( 'kwtsms_reg_redirect' ),
			),
			wp_login_url()
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the registration OTP entry form.
	 *
	 * Outputs a full HTML page using the same layout as the login OTP page,
	 * adapted for the registration context.
	 *
	 * @param string $token         Session token.
	 * @param string $error_message Error message to display (empty for none).
	 */
	private function render_reg_otp_form( $token, $error_message ) {
		$site_name  = get_bloginfo( 'name' );
		$otp_length = (int) $this->settings->get( 'general.otp_length', 6 );
		$login_url  = wp_login_url();

		$pending = get_transient( self::PENDING_REG_PREFIX . $token );
		$masked  = '';
		if ( $pending && ! empty( $pending['phone'] ) ) {
			$p      = $pending['phone'];
			$len    = strlen( $p );
			$masked = substr( $p, 0, max( 0, $len - 4 ) ) . '****';
		}

		// Build site logo.
		$custom_logo_id = get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id ) {
			$logo_html = wp_get_attachment_image(
				$custom_logo_id,
				array( 312, 84 ),
				false,
				array( 'alt' => $site_name )
			);
		} elseif ( has_site_icon() ) {
			$logo_html = '<img src="' . esc_url( get_site_icon_url( 84 ) ) . '" alt="' . esc_attr( $site_name ) . '" style="max-height:84px;width:auto;" />';
		} else {
			$logo_html = '<span class="screen-reader-text">' . esc_html( $site_name ) . '</span>';
		}

		$form_action = add_query_arg(
			array(
				'action' => 'kwtsms_reg_otp',
				'token'  => rawurlencode( $token ),
			),
			$login_url
		);

		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( __( 'Verify Your Phone Number', 'kwtsms' ) . ' - ' . $site_name ); ?></title>
		<?php wp_head(); ?>
</head>
<body class="login wp-core-ui">
<div id="login">

	<h1>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( $site_name ); ?>" tabindex="-1">
			<?php echo wp_kses_post( $logo_html ); ?>
		</a>
	</h1>

	<div class="kwtsms-otp-box">
		<h2 class="kwtsms-otp-title"><?php esc_html_e( 'Verify Your Phone Number', 'kwtsms' ); ?></h2>

		<?php if ( ! empty( $masked ) ) : ?>
		<p class="kwtsms-otp-desc">
			<?php
			printf(
				/* translators: 1: number of digits, 2: masked phone number */
				esc_html__( 'We sent a %1$d-digit code to %2$s', 'kwtsms' ),
				(int) $otp_length,
				'<strong>' . esc_html( $masked ) . '</strong>'
			);
			?>
		</p>
		<?php else : ?>
		<p class="kwtsms-otp-desc">
			<?php
			printf(
				/* translators: %d: number of digits */
				esc_html__( 'Enter the %d-digit code sent to your phone.', 'kwtsms' ),
				(int) $otp_length
			);
			?>
		</p>
		<?php endif; ?>

		<?php if ( ! empty( $error_message ) ) : ?>
		<div class="kwtsms-otp-error" role="alert">
			<?php echo esc_html( $error_message ); ?>
		</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $form_action ); ?>" id="kwtsms-reg-otp-form">
			<?php wp_nonce_field( 'kwtsms_reg_otp_submit', 'kwtsms_reg_nonce' ); ?>

			<div class="kwtsms-code-group">
				<label for="kwtsms_reg_code" class="screen-reader-text">
					<?php esc_html_e( 'Verification code', 'kwtsms' ); ?>
				</label>
				<input
					type="text"
					inputmode="numeric"
					pattern="[0-9]*"
					autocomplete="one-time-code"
					name="kwtsms_reg_code"
					id="kwtsms_reg_code"
					class="input kwtsms-code-input"
					placeholder="<?php echo esc_attr( str_repeat( '_', $otp_length ) ); ?>"
					maxlength="<?php echo (int) $otp_length; ?>"
					autofocus
					required
				/>
			</div>

			<input type="submit" name="kwtsms_reg_verify" class="button button-primary button-large kwtsms-btn"
				value="<?php esc_attr_e( 'Verify and Create Account', 'kwtsms' ); ?>" />
		</form>

		<p class="kwtsms-back-link">
			<a href="<?php echo esc_url( $login_url ); ?>">
				<?php esc_html_e( 'Back to login', 'kwtsms' ); ?>
			</a>
		</p>
	</div>
</div>

		<?php wp_footer(); ?>
</body>
</html>
		<?php
		ob_end_flush();
	}
}
