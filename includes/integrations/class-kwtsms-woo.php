<?php
/**
 * WooCommerce Integration.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Provides:
 * - Order status SMS notifications (processing, shipped, completed, cancelled)
 * - Phone field on WC registration form + welcome SMS after registration
 * - Checkout OTP gate: require phone + OTP for guest orders (optional, admin-controlled)
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Woo
 *
 * Hooks into WooCommerce events to send SMS notifications and to gate
 * guest checkout behind an OTP verification step.
 */
class KwtSMS_Woo {

	/**
	 * Reference to the main plugin instance.
	 *
	 * @var KwtSMS_Plugin
	 */
	private $plugin;

	/**
	 * Map WooCommerce order status slugs to their settings template keys.
	 *
	 * @var array<string,string>
	 */
	private static $status_template_map = array(
		'processing' => 'woo_processing',
		'on-hold'    => 'woo_shipped',
		'completed'  => 'woo_completed',
		'cancelled'  => 'woo_cancelled',
		'pending'    => 'woo_pending',
		'refunded'   => 'woo_refunded',
		'failed'     => 'woo_failed',
	);

	/**
	 * Transient prefix for checkout OTP sessions.
	 *
	 * @var string
	 */
	const CHECKOUT_OTP_PREFIX = 'kwtsms_checkout_otp_';

	/**
	 * Additional Checkout Fields API id of the checkout block OTP code field.
	 *
	 * 2026-11-05 17:10 +03: id of the block checkout code field (namespace/name as the API requires), wp-blockcheckout.
	 *
	 * @var string
	 */
	const BLOCK_OTP_FIELD = 'kwtsms/otp-code';

	/**
	 * Constructor.
	 *
	 * Registers all WooCommerce hooks. If the WooCommerce integration is
	 * disabled via the admin settings, no hooks are registered and the class
	 * exits immediately. The checkout OTP gate hooks are only added when that
	 * sub-feature is also enabled.
	 *
	 * @param KwtSMS_Plugin $plugin The main plugin instance.
	 */
	public function __construct( KwtSMS_Plugin $plugin ) {
		$this->plugin = $plugin;

		// Declare HPOS (High-Performance Order Storage) compatibility.
		add_action(
			'before_woocommerce_init',
			function () {
				if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
						'custom_order_tables',
						KWTSMS_OTP_DIR . 'wp-kwtsms.php',
						true
					);
				}
			}
		);

		// Bail entirely if the WooCommerce integration is disabled.
		if ( ! $this->plugin->settings->get( 'integrations.woo_enabled', 1 ) ) {
			return;
		}

		// Order status notifications.
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 10, 4 );

		// Registration: phone field + welcome SMS.
		add_action( 'woocommerce_register_form', array( $this, 'render_wc_phone_field' ) );
		add_filter( 'woocommerce_registration_errors', array( $this, 'validate_wc_phone_field' ), 10, 3 );
		add_action( 'woocommerce_created_customer', array( $this, 'save_wc_customer_phone' ) );
		add_action( 'woocommerce_edit_account_form', array( $this, 'output_account_nonce' ) );

		// Checkout OTP gate (only when enabled).
		if ( $this->is_checkout_otp_enabled() ) {
			add_action( 'woocommerce_after_order_notes', array( $this, 'render_checkout_otp_field' ) );
			add_action( 'woocommerce_checkout_process', array( $this, 'process_checkout_otp' ) );
			add_action( 'woocommerce_checkout_order_created', array( $this, 'clear_checkout_otp_session' ) );

			// 2026-11-05 17:10 +03: Checkout block (Store API) gate: an optional order field for the code plus
			// server-side enforcement before the draft order becomes a pending order; the classic hooks above never
			// fire on the block checkout, so block stores had no verification, wp-blockcheckout.
			add_action( 'woocommerce_init', array( $this, 'register_block_checkout_otp_field' ) );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'process_block_checkout_otp' ), 10, 2 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'clear_checkout_otp_session' ) );
		}
	}

	// =========================================================================
	// Order status notifications
	// =========================================================================

	/**
	 * Send an SMS notification when an order status changes.
	 *
	 * Supported transitions:
	 *   pending|on-hold  processing  (order confirmed / payment received)
	 *   processing       on-hold     (order shipped, labelled "Shipped")
	 *   *                completed   (order completed)
	 *   *                cancelled   (order cancelled)
	 *
	 * Phone resolution priority:
	 *   1. kwtsms_phone user meta (normalised at save time)
	 *   2. Billing phone from order (normalised on-the-fly)
	 *
	 * @param int           $order_id   WooCommerce order ID.
	 * @param string        $old_status Previous status slug.
	 * @param string        $new_status New status slug.
	 * @param WC_Order|null $order      Order object (passed by WC since 3.0).
	 */
	public function on_order_status_changed( $order_id, $old_status, $new_status, $order = null ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			$this->plugin->api->write_debug_log( 'woo_order', 'Skipped order status SMS: could not load order #' . $order_id );
			return;
		}

		// Get customer phone: prefer kwtsms_phone user meta, fall back to billing phone.
		$user_id = $order->get_customer_id();
		$phone   = '';
		if ( $user_id ) {
			$phone = get_user_meta( $user_id, 'kwtsms_phone', true );
		}
		if ( empty( $phone ) ) {
			$raw = $order->get_billing_phone();
			if ( ! empty( $raw ) ) {
				$raw        = KwtSMS_API::prepend_country_code_if_local( $raw, KwtSMS_API::get_default_dial_code() );
				$normalized = KwtSMS_API::normalize_phone( $raw );
				$phone      = is_wp_error( $normalized ) ? '' : $normalized;
			}
		}

		if ( empty( $phone ) ) {
			$this->plugin->api->write_debug_log( 'woo_order', 'Skipped order status SMS for order #' . $order->get_order_number() . ': no phone on file (user_id=' . $user_id . ')' );
			return;
		}

		$message = $this->build_order_message( $new_status, $order );
		if ( empty( $message ) ) {
			$this->plugin->api->write_debug_log( 'woo_order', 'Skipped order status SMS for order #' . $order->get_order_number() . ': template disabled or not configured for status "' . $new_status . '"' );
			return;
		}

		$this->plugin->api->send(
			$phone,
			$this->plugin->settings->get( 'gateway.sender_id', '' ),
			$message,
			'woo_order',
			array( 'order_id' => $order->get_id() )
		);

		// Admin SMS notification: send to configured admin phone(s) for selected statuses.
		$admin_phone     = $this->plugin->settings->get( 'integrations.woo_admin_phone', '' );
		$notify_statuses = $this->plugin->settings->get( 'integrations.woo_notify_admin_statuses', array() );
		if ( ! empty( $admin_phone ) && in_array( $new_status, (array) $notify_statuses, true ) ) {
			$admin_msg = sprintf(
				/* translators: 1: order id 2: customer name 3: status 4: total */
				__( 'New order #%1$s, %2$s, %3$s, %4$s', 'kwtsms' ),
				$order->get_id(),
				$order->get_formatted_billing_full_name(),
				wc_get_order_status_name( $new_status ),
				number_format( (float) $order->get_total(), absint( get_option( 'woocommerce_price_num_decimals', 2 ) ) ) . ' ' . get_woocommerce_currency()
			);
			$sender_id = (string) $this->plugin->settings->get( 'gateway.sender_id', '' );
			$dial_code = KwtSMS_API::get_default_dial_code();
			$phones    = array();
			foreach ( preg_split( '/[\s,]+/', $admin_phone, -1, PREG_SPLIT_NO_EMPTY ) as $raw_p ) {
				$phones[] = KwtSMS_API::prepend_country_code_if_local( $raw_p, $dial_code );
			}
			$this->plugin->api->send( $phones, $sender_id, $admin_msg, 'woo_admin', array( 'order_id' => $order->get_id() ) );
		}
	}

	/**
	 * Build an SMS message for a given order status transition.
	 *
	 * Delegates to render_order_template() which reads the saved template from
	 * settings. Returns an empty string when the status is not handled or when
	 * the individual template is disabled by the admin.
	 *
	 * @param string   $status New status slug.
	 * @param WC_Order $order  The order.
	 *
	 * @return string SMS message, or empty string if status not handled / disabled.
	 */
	private function build_order_message( $status, WC_Order $order ) {
		$order_id      = $order->get_order_number();
		$total         = number_format( (float) $order->get_total(), absint( get_option( 'woocommerce_price_num_decimals', 2 ) ) ) . ' ' . get_woocommerce_currency();
		$site_name     = get_bloginfo( 'name' );
		$customer_name = trim(
			$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
		);

		$vars = array(
			'{site_name}'     => $site_name,
			'{order_id}'      => $order_id,
			'{total}'         => $total,
			'{customer_name}' => $customer_name,
		);

		return $this->render_order_template( $status, $vars );
	}

	/**
	 * Render an order SMS template from saved settings.
	 *
	 * Looks up the template key for the given status, loads the saved template
	 * from the integrations settings group, checks the per-template `enabled`
	 * flag, selects the correct language string (ar for RTL sites, en otherwise),
	 * and performs placeholder substitution.
	 *
	 * @param string $status WooCommerce order status slug.
	 * @param array  $vars   Map of placeholder strings to replacement values.
	 *
	 * @return string Rendered SMS message, or empty string if not applicable.
	 */
	private function render_order_template( $status, array $vars ) {
		// Look up the settings key for this status.
		$template_key = self::$status_template_map[ $status ] ?? '';
		if ( '' === $template_key ) {
			return '';
		}

		// Load the template array from settings (merges saved + defaults).
		$templates = $this->plugin->settings->get_all_integration_templates();
		$template  = $templates[ $template_key ] ?? array();

		// Respect per-template enabled flag.
		if ( empty( $template['enabled'] ) ) {
			return '';
		}

		// Select language: Arabic for RTL sites, English otherwise.
		$lang    = ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'ar' : 'en';
		$message = $template[ $lang ] ?? $template['en'] ?? '';

		if ( '' === $message ) {
			return '';
		}

		// Replace all placeholders.
		return str_replace( array_keys( $vars ), array_values( $vars ), $message );
	}

	// =========================================================================
	// Registration: phone field + welcome SMS
	// =========================================================================

	/**
	 * Render phone field on the WooCommerce registration form (My Account page).
	 *
	 * The field is optional: an empty submission is silently skipped.
	 * Re-populates the field value from $_POST on validation failure.
	 */
	public function render_wc_phone_field() {
		// Re-populate the field value from POST on validation failure.
		// Nonce is verified by WooCommerce core before this filter fires.
		// We only read a single sanitized field for re-display, not processing.
		$phone = '';
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce-register-nonce'] ?? '' ) ), 'woocommerce-register' ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone_reg'] ?? '' ) );
		}
		?>
		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="kwtsms_phone_reg">
				<?php esc_html_e( 'Phone Number (optional)', 'kwtsms' ); ?>
			</label>
			<input
				type="tel"
				class="woocommerce-Input woocommerce-Input--text input-text"
				name="kwtsms_phone_reg"
				id="kwtsms_phone_reg"
				autocomplete="tel"
				value="<?php echo esc_attr( $phone ); ?>"
				<?php /* 2026-11-05 11:21 +03: placeholder 965XXXXXXXX replaces a real person's number, int-testnumber. */ ?>
				placeholder="<?php esc_attr_e( 'e.g. 965XXXXXXXX', 'kwtsms' ); ?>"
			/>
			<span class="description" style="font-size:12px;">
				<?php esc_html_e( 'Enter with country code. Used for SMS order notifications.', 'kwtsms' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Validate the phone field on WC registration.
	 *
	 * An empty value is allowed (field is optional). A non-empty value must
	 * pass KwtSMS_API::normalize_phone() validation.
	 *
	 * @param WP_Error $errors   Existing validation errors.
	 * @param string   $username Submitted username.
	 * @param string   $email    Submitted email.
	 *
	 * @return WP_Error The (potentially augmented) errors object.
	 */
	public function validate_wc_phone_field( $errors, $username, $email ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Verify WooCommerce registration nonce before accessing POST data.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce-register-nonce'] ?? '' ) ), 'woocommerce-register' ) ) {
			return $errors;
		}
		$phone = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone_reg'] ?? '' ) );
		if ( '' !== $phone ) {
			$phone      = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
			$normalized = KwtSMS_API::normalize_phone( $phone );
			if ( is_wp_error( $normalized ) ) {
				$errors->add( 'kwtsms_invalid_phone', $normalized->get_error_message() );
			}
		}
		return $errors;
	}

	/**
	 * Output a nonce field on the WooCommerce My Account edit form.
	 *
	 * Paired with nonce verification in save_wc_customer_phone().
	 */
	public function output_account_nonce() {
		wp_nonce_field( 'kwtsms_woo_account', 'kwtsms_woo_account_nonce' );
	}

	/**
	 * Save phone meta after WC account creation.
	 *
	 * Only runs when a non-empty phone was submitted. The phone is normalised
	 * before storage. If normalisation fails the whole step is skipped.
	 *
	 * Welcome SMS is handled by the `user_register` hook in KwtSMS_Plugin
	 * (fires for all registration types: WC checkout, WC My Account, standard WP).
	 *
	 * @param int $customer_id New customer user ID.
	 */
	public function save_wc_customer_phone( $customer_id ) {
		// WooCommerce verifies its own nonce before firing woocommerce_created_customer.
		// Read the phone from the order billing data or WC customer meta instead of POST.
		$phone = '';
		$order = wc_get_customer_last_order( $customer_id );
		if ( $order ) {
			$phone = $order->get_billing_phone();
		}
		if ( '' === $phone ) {
			$phone = get_user_meta( $customer_id, 'billing_phone', true );
		}
		if ( '' === $phone ) {
			return;
		}

		$phone      = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $normalized ) ) {
			return;
		}

		update_user_meta( $customer_id, 'kwtsms_phone', $normalized );
	}

	// =========================================================================
	// Checkout OTP gate
	// =========================================================================

	/**
	 * Check if the checkout OTP gate is enabled in settings.
	 *
	 * @return bool
	 */
	private function is_checkout_otp_enabled() {
		return (bool) $this->plugin->settings->get( 'integrations.woo_checkout_otp', 0 );
	}

	/**
	 * Render the OTP input field in the checkout form.
	 *
	 * If the current session has already been OTP-verified, shows a
	 * "Phone verified" confirmation and a hidden field instead of the input
	 * so the process_checkout_otp handler can short-circuit immediately.
	 *
	 * @param WC_Checkout $checkout WooCommerce checkout instance.
	 */
	public function render_checkout_otp_field( WC_Checkout $checkout ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$cod_only = (bool) $this->plugin->settings->get( 'integrations.woo_checkout_otp_cod_only', 0 );

		// Check if OTP has already been verified for this session.
		$session_key = $this->get_checkout_session_key();
		if ( $session_key && get_transient( self::CHECKOUT_OTP_PREFIX . $session_key ) ) {
			echo '<p style="color:#46b450;font-weight:600;">' . esc_html__( 'Phone verified', 'kwtsms' ) . '</p>';
			echo '<input type="hidden" name="kwtsms_checkout_verified" value="1" />';
			return;
		}

		$token = wp_generate_password( 20, false );
		$nonce = wp_create_nonce( 'kwtsms_otp_nonce' );
		?>
		<div id="kwtsms-checkout-otp" <?php echo $cod_only ? 'data-cod-only="1" ' : ''; ?>style="margin-bottom:20px;padding:16px;border:1px solid #ddd;border-radius:4px;background:#fff8f0;">
			<h4 style="margin:0 0 8px;"><?php esc_html_e( 'Phone Verification', 'kwtsms' ); ?></h4>
			<p style="font-size:14px;margin:0 0 10px;"><?php esc_html_e( 'We will send an OTP to your billing phone to verify your order.', 'kwtsms' ); ?></p>
			<input type="hidden" name="kwtsms_checkout_token" value="<?php echo esc_attr( $token ); ?>" />
			<input type="hidden" name="kwtsms_checkout_nonce" value="<?php echo esc_attr( $nonce ); ?>" />
			<input
				type="text"
				name="kwtsms_checkout_otp_code"
				inputmode="numeric"
				pattern="[0-9]*"
				autocomplete="one-time-code"
				placeholder="<?php esc_attr_e( 'Enter OTP code (leave blank to receive code first)', 'kwtsms' ); ?>"
				style="width:100%;padding:8px;margin-top:4px;box-sizing:border-box;"
			/>
		</div>
		<?php
		if ( $cod_only ) {
			// 2026-11-04 21:53 +03: src false, not '' (PHPStan with current WP stubs, CI red since today; inline-only handle).
			wp_register_script( 'kwtsms-checkout-cod', false, array(), KWTSMS_OTP_VERSION, true );
			wp_enqueue_script( 'kwtsms-checkout-cod' );
			wp_add_inline_script(
				'kwtsms-checkout-cod',
				'(function(){' .
				'var wrap=document.getElementById("kwtsms-checkout-otp");' .
				'if(!wrap)return;' .
				'function update(){' .
					'var chosen=document.querySelector("input[name=\\"payment_method\\"]:checked");' .
					'wrap.style.display=(chosen&&chosen.value==="cod")?"":"none";' .
				'}' .
				'document.addEventListener("change",function(e){' .
					'if(e.target&&e.target.name==="payment_method")update();' .
				'});' .
				'update();' .
				'})();'
			);
		}
		?>
		<?php
	}

	/**
	 * Process the checkout OTP field during WooCommerce checkout validation.
	 *
	 * Two-step flow:
	 *   1. First submit (no OTP code supplied): generate + send OTP to billing
	 *      phone, store pending transient, add a WC notice to prompt the user
	 *      to enter the code, and block order creation.
	 *   2. Second submit (OTP code present): verify against the stored transient.
	 *      On success, mark the session as verified and allow order creation.
	 *      On failure, add an error notice and block order creation.
	 */
	public function process_checkout_otp() {
		// If already verified for this session, skip.
		$session_key = $this->get_checkout_session_key();
		if ( $session_key && get_transient( self::CHECKOUT_OTP_PREFIX . $session_key ) ) {
			return;
		}

		// Nonce check (must come before any $_POST access).
		$nonce = sanitize_key( wp_unslash( $_POST['kwtsms_checkout_nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'kwtsms_otp_nonce' ) ) {
			wc_add_notice( __( 'Security check failed. Please refresh and try again.', 'kwtsms' ), 'error' );
			return;
		}

		// In COD-only mode, skip OTP for non-COD payment methods.
		$cod_only       = (bool) $this->plugin->settings->get( 'integrations.woo_checkout_otp_cod_only', 0 );
		$payment_method = sanitize_key( wp_unslash( $_POST['payment_method'] ?? '' ) );
		if ( $cod_only && 'cod' !== $payment_method ) {
			return;
		}

		$token    = sanitize_text_field( wp_unslash( $_POST['kwtsms_checkout_token'] ?? '' ) );
		$otp_code = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['kwtsms_checkout_otp_code'] ?? '' ) ) );

		// Get billing phone.
		$raw_phone = sanitize_text_field( wp_unslash( $_POST['billing_phone'] ?? '' ) );

		// 2026-11-05 17:10 +03: send/verify steps moved to run_checkout_otp_step() so the block checkout reuses the
		// exact same rate limits, cooldown, SMS and verification; classic notices and order unchanged, wp-blockcheckout.
		$error = $this->run_checkout_otp_step( $raw_phone, (string) $otp_code, $token, $session_key );
		if ( '' !== $error ) {
			wc_add_notice( $error, 'error' );
		}
	}

	/**
	 * Shared checkout OTP step for the classic checkout and the checkout block.
	 *
	 * 2026-11-05 17:10 +03: extracted from process_checkout_otp() unchanged, so both checkouts enforce one
	 * implementation (rate limits, send cooldown, OTP SMS, verification, session flag), wp-blockcheckout.
	 *
	 * With no code: rate-limit, generate and send the OTP, remember the phone, and block the order with the
	 * "OTP has been sent" message. With a code: check it against the same phone and, when valid, mark the
	 * WooCommerce session as verified.
	 *
	 * @param string      $raw_phone   Billing phone as entered by the shopper.
	 * @param string      $otp_code    Digits-only OTP code, or '' on the first submit.
	 * @param string      $token       Identifier of this checkout attempt (OTP and pending-phone transient key).
	 * @param string|null $session_key WooCommerce session customer id, or null when no session exists.
	 * @return string '' when the order may proceed, otherwise the message that blocks it.
	 */
	private function run_checkout_otp_step( $raw_phone, $otp_code, $token, $session_key ) {
		$raw_phone  = KwtSMS_API::prepend_country_code_if_local( $raw_phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $raw_phone );
		if ( is_wp_error( $normalized ) ) {
			return $normalized->get_error_message();
		}

		$transient_key = self::CHECKOUT_OTP_PREFIX . $token;

		if ( '' === $otp_code ) {
			// First (or repeated) submit: enforce per-phone and per-IP rate limits.
			if ( $this->plugin->otp->is_rate_limited( $normalized, 'checkout' ) ) {
				return __( 'Too many OTP requests for this phone number. Please wait before trying again.', 'kwtsms' );
			}
			if ( $this->plugin->otp->is_ip_rate_limited( 'checkout' ) ) {
				return __( 'Too many requests from your connection. Please wait before trying again.', 'kwtsms' );
			}

			// Generate (or reuse) OTP.
			$otp         = $this->plugin->otp->generate( 'checkout_' . $token, 'checkout' );
			$checkout_id = 'checkout_' . $token;

			// Send SMS only if outside the send-cooldown (prevents double-send on double-click).
			if ( ! $this->plugin->otp->is_send_cooldown_active( $checkout_id ) ) {
				// 2026-11-05 19:07 +03: checkout uses its own template; when an admin disables it, fall back to login_otp so
				// the store keeps phone verification, wp-otp-template.
				$tpl_id = $this->plugin->settings->get( 'templates.checkout_otp.enabled', 1 ) ? 'checkout_otp' : 'login_otp';
				$msg    = $this->plugin->otp->build_message( $otp, $tpl_id );
				$result = $this->plugin->api->send(
					$normalized,
					$this->plugin->settings->get( 'gateway.sender_id', '' ),
					$msg,
					'checkout'
				);
				if ( is_wp_error( $result ) ) {
					return $result->get_error_message();
				}
				$this->plugin->otp->set_send_cooldown( $checkout_id );
			}

			// Store the phone so we can verify the same number on the second submit.
			set_transient( $transient_key . '_pending', $normalized, 15 * MINUTE_IN_SECONDS );
			// Prevent order creation on first submit.
			return __( 'An OTP has been sent to your phone. Enter it above and place the order again.', 'kwtsms' );
		}

		// Second submit: verify OTP.
		$stored_phone = get_transient( $transient_key . '_pending' );
		if ( ! $stored_phone || $stored_phone !== $normalized ) {
			return __( 'Session expired. Please refresh and try again.', 'kwtsms' );
		}

		$result = $this->plugin->otp->verify( 'checkout_' . $token, $otp_code, 'checkout' );
		if ( 'valid' !== $result ) {
			return __( 'Incorrect or expired OTP. Please try again.', 'kwtsms' );
		}

		// Mark session as verified so subsequent page loads do not re-prompt.
		if ( $session_key ) {
			set_transient( self::CHECKOUT_OTP_PREFIX . $session_key, 1, HOUR_IN_SECONDS );
		}
		delete_transient( $transient_key . '_pending' );
		return '';
	}

	// =========================================================================
	// Checkout OTP gate: Checkout block (Store API)
	// =========================================================================

	/**
	 * Register the OTP code field on the Checkout block.
	 *
	 * 2026-11-05 17:10 +03: optional text field in the block's Order information section, registered through
	 * the Additional Checkout Fields API (WooCommerce 8.9+; no-op on older versions or classic-only stores).
	 * Reuses the classic strings so existing translations apply. In COD-only mode the field is hidden for other
	 * payment methods with a conditional schema (WooCommerce 9.9+), wp-blockcheckout.
	 *
	 * Hooked to woocommerce_init, as the API requires.
	 */
	public function register_block_checkout_otp_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			$this->plugin->api->write_debug_log( 'woo_checkout', 'Checkout block OTP field not registered: WooCommerce has no Additional Checkout Fields API (needs 8.9+).' );
			return;
		}

		$field = array(
			'id'            => self::BLOCK_OTP_FIELD,
			'label'         => __( 'Phone Verification', 'kwtsms' ),
			'optionalLabel' => __( 'Enter OTP code (leave blank to receive code first)', 'kwtsms' ),
			'location'      => 'order',
			'type'          => 'text',
			'required'      => false,
			'attributes'    => array(
				'autocomplete' => 'one-time-code',
			),
		);

		$cod_only = (bool) $this->plugin->settings->get( 'integrations.woo_checkout_otp_cod_only', 0 );
		if ( $cod_only && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.9.0', '>=' ) ) {
			$field['hidden'] = array(
				'checkout' => array(
					'properties' => array(
						'payment_method' => array(
							'not' => array( 'const' => 'cod' ),
						),
					),
				),
			);
		}

		woocommerce_register_additional_checkout_field( $field );
	}

	/**
	 * Enforce the checkout OTP on the Checkout block, server-side.
	 *
	 * 2026-11-05 17:10 +03: runs inside the Store API checkout POST after the payment method and additional
	 * fields are applied to the draft order and before it is saved as pending; throwing a RouteException stops
	 * the order and shows the message in the block, the same two-submit flow as the classic checkout. PUT/PATCH
	 * draft updates are ignored so no SMS is sent while the shopper is still typing, wp-blockcheckout.
	 *
	 * @param WC_Order         $order   Draft order being placed.
	 * @param \WP_REST_Request $request Store API checkout request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the order must not be placed yet.
	 */
	public function process_block_checkout_otp( $order, $request ) {
		if ( 'POST' !== $request->get_method() ) {
			return;
		}

		$fields   = $request->get_param( 'additional_fields' );
		$raw_code = ( is_array( $fields ) && isset( $fields[ self::BLOCK_OTP_FIELD ] ) ) ? (string) $fields[ self::BLOCK_OTP_FIELD ] : '';
		$otp_code = (string) preg_replace( '/\D/', '', sanitize_text_field( $raw_code ) );

		// A one-time code has no business on the order: drop the value the Fields API copied to the order meta.
		$order->delete_meta_data( '_wc_other/' . self::BLOCK_OTP_FIELD );

		$session_key = $this->get_checkout_session_key();
		if ( $session_key && get_transient( self::CHECKOUT_OTP_PREFIX . $session_key ) ) {
			$this->plugin->api->write_debug_log( 'woo_checkout', 'Checkout block OTP skipped: phone already verified in this session.' );
			return;
		}

		$cod_only = (bool) $this->plugin->settings->get( 'integrations.woo_checkout_otp_cod_only', 0 );
		if ( $cod_only && 'cod' !== $order->get_payment_method() ) {
			$this->plugin->api->write_debug_log( 'woo_checkout', 'Checkout block OTP skipped: COD-only mode and payment method is not cod.' );
			return;
		}

		if ( ! $session_key ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'kwtsms_checkout_otp',
				esc_html__( 'Session expired. Please refresh and try again.', 'kwtsms' ),
				400
			);
		}

		$error = $this->run_checkout_otp_step( (string) $order->get_billing_phone(), $otp_code, 'blk_' . $session_key, $session_key );
		if ( '' !== $error ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'kwtsms_checkout_otp', esc_html( $error ), 400 );
		}
	}

	/**
	 * Clear the checkout OTP session transient after an order is successfully created.
	 *
	 * Prevents the "verified" state from persisting indefinitely in the transient
	 * store after the order has been placed.
	 *
	 * @param WC_Order $order The newly created order.
	 */
	public function clear_checkout_otp_session( WC_Order $order ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$session_key = $this->get_checkout_session_key();
		if ( $session_key ) {
			delete_transient( self::CHECKOUT_OTP_PREFIX . $session_key );
		}
	}

	/**
	 * Get a session-specific key for checkout OTP verification.
	 *
	 * Uses the WooCommerce session customer ID when available. Falls back to
	 * null (meaning per-session verification is skipped) when WC sessions are
	 * unavailable (e.g. during unit tests or very early in the request).
	 *
	 * @return string|null Session key, or null if WC session is unavailable.
	 */
	private function get_checkout_session_key() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$cid = WC()->session->get_customer_id();
			return $cid ? $cid : null;
		}
		return null;
	}
}
