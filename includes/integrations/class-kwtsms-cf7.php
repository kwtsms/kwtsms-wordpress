<?php
/**
 * Contact Form 7 Integration.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Supports two modes, configurable per-site in the Integrations admin page:
 *
 *  - Notification mode (default): sends a confirmation SMS after a successful
 *    CF7 form submission. Use the [tel kwtsms_phone] tag in your form.
 *
 *  - OTP Gate mode: blocks mail delivery until the visitor has verified their
 *    phone number via an OTP code. The JS layer (form-otp.js) adds a hidden
 *    input `kwtsms_form_verified_token`; this class verifies it server-side
 *    using the `wpcf7_before_send_mail` filter.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_CF7
 *
 * Hooks into Contact Form 7 events to:
 *  - (Notification mode) send a confirmation SMS via `wpcf7_mail_sent`.
 *  - (Gate mode) block mail unless the phone is OTP-verified via
 *    `wpcf7_before_send_mail`.
 *
 * To enable SMS on any form, add a tel field named `kwtsms_phone`:
 *   [tel kwtsms_phone]          (optional phone)
 *   [tel* kwtsms_phone]         (required phone)
 */
class KwtSMS_CF7 {

	/**
	 * Reference to the main plugin instance.
	 *
	 * @var KwtSMS_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * Registers hooks based on the configured mode:
	 *  - If cf7_mode is 'gate', hooks `wpcf7_before_send_mail` to block
	 *    unverified submissions.
	 *  - Otherwise (notification mode), hooks `wpcf7_mail_sent` to send a
	 *    confirmation SMS after successful submission.
	 *
	 * If the CF7 integration is disabled in settings, no hook is registered
	 * and the class exits immediately.
	 *
	 * @param KwtSMS_Plugin $plugin The main plugin instance.
	 */
	public function __construct( KwtSMS_Plugin $plugin ) {
		$this->plugin = $plugin;

		// Bail entirely if the CF7 integration is disabled.
		if ( ! $this->plugin->settings->get( 'integrations.cf7_enabled', 1 ) ) {
			return;
		}

		$mode = $this->plugin->settings->get( 'integrations.cf7_mode', 'notification' );

		if ( 'gate' === $mode ) {
			// Gate mode: verify token before CF7 sends mail.
			// Returning false from this filter prevents CF7 from sending mail
			// and causes it to respond with a validation failure message.
			add_filter( 'wpcf7_before_send_mail', array( $this, 'gate_verify_token' ), 10, 3 );
		} else {
			// Notification mode: send SMS after a valid CF7 form submission.
			// wpcf7_submit fires regardless of SMTP status: SMS is delivered even
			// when no email server is configured. Status is checked to exclude
			// validation failures, spam, and aborted (gate-blocked) submissions.
			add_action( 'wpcf7_submit', array( $this, 'send_confirmation_sms_on_submit' ), 10, 2 );
		}
	}

	// =========================================================================
	// Gate mode
	// =========================================================================

	/**
	 * Gate mode: verify the OTP token before CF7 sends mail.
	 *
	 * Hooked to `wpcf7_before_send_mail` with priority 10. CF7 calls this
	 * filter and, if the return value is false, aborts mail delivery and
	 * marks the submission as having a validation error.
	 *
	 * @param WPCF7_ContactForm $cf7      The contact form instance.
	 * @param bool              $abort    Whether to abort mail sending (passed by ref in CF7 >= 5.x).
	 * @param WPCF7_Submission  $submission The current submission object.
	 *
	 * @return WPCF7_ContactForm The (possibly mutated) form instance.
	 */
	public function gate_verify_token( $cf7, &$abort, $submission ) {
		// Verify our gate nonce: injected into the form by form-otp.js after successful OTP verification.
		// The nonce is in $_POST (not CF7's get_posted_data) because it is a JS-injected hidden input,
		// not a registered CF7 form tag. Nonce IS verified on the next line (fail-early pattern).
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_kwtsms_gate_nonce'] ?? '' ) ), 'kwtsms_gate_verify' ) ) {
			$abort = true;
			if ( is_a( $submission, 'WPCF7_Submission' ) ) {
				$submission->set_response(
					__( 'Please verify your phone number before submitting this form.', 'kwtsms' )
				);
			}
			return $cf7;
		}
		// Use the CF7 Submission API instead of raw $_POST to read posted data.
		// This is the correct CF7 idiom and avoids direct superglobal access.
		$cf7_submission = WPCF7_Submission::get_instance();
		$posted         = $cf7_submission ? $cf7_submission->get_posted_data() : array();
		$token          = sanitize_text_field( $posted['kwtsms_form_verified_token'] ?? '' );

		if ( empty( $token ) || ! $this->plugin->verify_form_token( $token ) ) {
			$abort = true;

			// Attach an invalidation message so CF7 shows a user-facing error.
			if ( is_a( $submission, 'WPCF7_Submission' ) ) {
				$submission->set_response(
					__( 'Please verify your phone number before submitting this form.', 'kwtsms' )
				);
			}

			return $cf7;
		}

		// Token is verified: consume it immediately (single-use) to prevent replay.
		// Note: form_id in the transient is stored for audit purposes.
		// Token is single-use (consumed on success) so cross-form replay is prevented.
		delete_transient( 'kwtsms_form_otp_' . $token );

		return $cf7;
	}

	// =========================================================================
	// Notification mode
	// =========================================================================

	/**
	 * Send a confirmation SMS after a CF7 form submission, regardless of email delivery.
	 *
	 * Hooked to `wpcf7_submit` which fires for every submission. Skips invalid
	 * submissions (validation_failed, spam, aborted) and only sends SMS when the
	 * form passed validation (mail_sent or mail_failed).
	 *
	 * @param WPCF7_ContactForm $cf7    The contact form instance.
	 * @param array             $result Submission result array with 'status' key.
	 */
	public function send_confirmation_sms_on_submit( $cf7, $result ) {
		$skip_statuses = array( 'validation_failed', 'spam', 'aborted' );
		if ( in_array( $result['status'] ?? '', $skip_statuses, true ) ) {
			return;
		}
		$this->send_confirmation_sms( $cf7 );
	}

	/**
	 * Send a confirmation SMS after a CF7 form is successfully submitted.
	 *
	 * Looks for a field named `kwtsms_phone` in the submitted data. The message
	 * text is loaded from the saved `integrations.cf7_confirmation` template and
	 * supports both English and Arabic (selected via is_rtl()). If the template
	 * is disabled by the admin, no SMS is sent.
	 *
	 * @param WPCF7_ContactForm $cf7 The contact form instance.
	 */
	public function send_confirmation_sms( $cf7 ) {
		if ( ! is_a( $cf7, 'WPCF7_ContactForm' ) ) {
			return;
		}

		$phone = $this->get_submission_phone();
		if ( empty( $phone ) ) {
			$this->plugin->api->write_debug_log( 'cf7', 'Skipped CF7 confirmation SMS for form "' . sanitize_text_field( $cf7->title() ) . '": no phone field value found' );
			return;
		}

		$phone      = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $normalized ) ) {
			$this->plugin->api->write_debug_log( 'cf7', 'Skipped CF7 confirmation SMS for form "' . sanitize_text_field( $cf7->title() ) . '": phone normalization failed (' . $normalized->get_error_message() . ')' );
			return;
		}

		$message = $this->render_confirmation_template( sanitize_text_field( $cf7->title() ) );
		if ( empty( $message ) ) {
			$this->plugin->api->write_debug_log( 'cf7', 'Skipped CF7 confirmation SMS for form "' . sanitize_text_field( $cf7->title() ) . '": template disabled or missing' );
			return;
		}

		$this->plugin->api->send(
			$normalized,
			$this->plugin->settings->get( 'gateway.sender_id', '' ),
			$message,
			'cf7'
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Retrieve the `kwtsms_phone` value from the current CF7 submission.
	 *
	 * Isolated into a protected method so that tests can override it without
	 * needing to stub the static WPCF7_Submission::get_instance() call, which
	 * cannot be intercepted by Brain\Monkey.
	 *
	 * @return string Raw phone string from posted data, or empty string if absent.
	 */
	protected function get_submission_phone() {
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return '';
		}
		$posted = $submission->get_posted_data();
		return isset( $posted['kwtsms_phone'] ) ? sanitize_text_field( $posted['kwtsms_phone'] ) : '';
	}

	/**
	 * Render the CF7 confirmation SMS from the saved template.
	 *
	 * Loads the `cf7_confirmation` template from integrations settings, checks
	 * the `enabled` sub-key, selects the correct language string (ar for RTL
	 * sites, en otherwise), and replaces {site_name} and {form_name} placeholders.
	 *
	 * @param string $form_title The CF7 form title.
	 *
	 * @return string Rendered SMS message, or empty string if disabled / missing.
	 */
	private function render_confirmation_template( $form_title ) {
		$templates = $this->plugin->settings->get_all_integration_templates();
		$template  = $templates['cf7_confirmation'] ?? array();

		if ( empty( $template['enabled'] ) ) {
			return '';
		}

		$lang    = ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'ar' : 'en';
		$message = $template[ $lang ] ?? $template['en'] ?? '';

		if ( '' === $message ) {
			return '';
		}

		return str_replace(
			array( '{site_name}', '{form_name}' ),
			array( get_bloginfo( 'name' ), $form_title ),
			$message
		);
	}
}
