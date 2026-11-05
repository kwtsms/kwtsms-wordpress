<?php
/**
 * Ninja Forms Integration.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Supports two modes, configurable per-site in the Integrations admin page:
 *
 *  - Notification mode (default): sends a confirmation SMS after a successful
 *    Ninja Forms submission. Looks for a field of type 'phone' or 'tel' in the
 *    submitted form data.
 *
 *  - OTP Gate mode: blocks the form submission by injecting a field-level error
 *    until the visitor has verified their phone number via an OTP code. The JS
 *    layer (form-otp.js) adds a hidden input `kwtsms_form_verified_token`; this
 *    class verifies it server-side using the `ninja_forms_submit_fields` filter.
 *
 * Auto-detected when `Ninja_Forms` class exists (Ninja Forms 3.x+).
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_NinjaForms
 *
 * Hooks into Ninja Forms events to:
 *  - (Notification mode) send a confirmation SMS via `ninja_forms_after_submission`.
 *  - (Gate mode) inject a field error via `ninja_forms_submit_fields` when the
 *    OTP token is absent or unverified.
 *
 * Phone detection strategy:
 *  1. Any NF field whose type is 'phone'.
 *  2. Any NF field whose type is 'tel'.
 */
class KwtSMS_NinjaForms {

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
	 *  - If nf_mode is 'gate', hooks `ninja_forms_submit_fields` to inject a
	 *    field-level error for unverified phone fields.
	 *  - Otherwise (notification mode), hooks `ninja_forms_after_submission` to
	 *    send a confirmation SMS after a successful submission.
	 *
	 * If Ninja Forms is not installed or the integration is disabled in settings,
	 * no hook is registered and the class exits immediately.
	 *
	 * @param KwtSMS_Plugin        $plugin    The main plugin instance.
	 * @param KwtSMS_Settings|null $_settings Unused: accepted for DI parity with
	 *                                         the GF class; settings are always
	 *                                         accessed via $plugin->settings.
	 */
	public function __construct( KwtSMS_Plugin $plugin, KwtSMS_Settings $_settings = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->plugin = $plugin;

		// Ninja Forms must be active.
		if ( ! class_exists( 'Ninja_Forms' ) ) {
			return;
		}

		// Bail entirely if the NF integration is disabled.
		if ( ! $this->plugin->settings->get( 'integrations.nf_enabled', 1 ) ) {
			return;
		}

		$mode = $this->plugin->settings->get( 'integrations.nf_mode', 'notification' );

		if ( 'gate' === $mode ) {
			// Gate mode: ninja_forms_submit_fields is a filter that receives the
			// fields array for the current submission. We annotate the phone field
			// with an errors entry to block processing.
			add_filter( 'ninja_forms_submit_fields', array( $this, 'gate_validate_fields' ) );
		} else {
			// Notification mode: ninja_forms_after_submission fires after NF has
			// saved the submission. Receives $form_data array.
			add_action( 'ninja_forms_after_submission', array( $this, 'send_notification' ) );
		}
	}

	// =========================================================================
	// Gate mode
	// =========================================================================

	/**
	 * Gate mode: inject a field-level error if the OTP token is absent or unverified.
	 *
	 * Hooked to `ninja_forms_submit_fields` (filter). NF passes the fields array
	 * for the current submission. Each field entry is an associative array; adding
	 * an 'errors' sub-array with a non-empty value causes NF to abort processing
	 * and return the error to the visitor.
	 *
	 * @param array $fields Array of submitted field data arrays.
	 *
	 * @return array Possibly annotated fields array.
	 */
	public function gate_validate_fields( $fields ) {
		// Verify our gate nonce: created at page load via wp_localize_script and injected into the form by form-otp.js after successful OTP verification.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_kwtsms_gate_nonce'] ?? '' ) ), 'kwtsms_gate_verify' ) ) {
			foreach ( $fields as &$field ) {
				if ( $this->is_phone_field( $field ) ) {
					$field['errors']['verify'] = __( 'Please verify your phone number before submitting this form.', 'kwtsms' );
					break;
				}
			}
			unset( $field );
			return $fields;
		}
		$token = sanitize_text_field( wp_unslash( $_POST['kwtsms_form_verified_token'] ?? '' ) );

		if ( empty( $token ) || ! $this->plugin->verify_form_token( $token ) ) {
			// Attach the error to the first phone field; NF surfaces it inline.
			foreach ( $fields as &$field ) {
				if ( $this->is_phone_field( $field ) ) {
					$field['errors']['verify'] = __( 'Please verify your phone number before submitting this form.', 'kwtsms' );
					break;
				}
			}
			unset( $field );
			return $fields;
		}

		// Token is verified: consume it immediately (single-use) to prevent replay.
		delete_transient( 'kwtsms_form_otp_' . $token );

		return $fields;
	}

	// =========================================================================
	// Notification mode
	// =========================================================================

	/**
	 * Send a confirmation SMS after a Ninja Forms submission.
	 *
	 * Receives the full $form_data array from NF's after_submission action.
	 * Looks for a phone or tel field in $form_data['fields'], extracts its
	 * value, and sends an SMS using the `nf_confirmation` template.
	 *
	 * @param array $form_data NF form data array with keys:
	 *   'actions', 'settings' (form meta), 'fields' (array of field arrays).
	 */
	public function send_notification( $form_data ) {
		$phone      = $this->extract_phone_from_form_data( $form_data );
		$form_title = sanitize_text_field( $form_data['settings']['title'] ?? '' );
		if ( empty( $phone ) ) {
			$this->plugin->api->write_debug_log( 'ninjaforms', 'Skipped Ninja Forms confirmation SMS for form "' . $form_title . '": no phone field value found' );
			return;
		}

		$phone      = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $normalized ) ) {
			$this->plugin->api->write_debug_log( 'ninjaforms', 'Skipped Ninja Forms confirmation SMS for form "' . $form_title . '": phone normalization failed (' . $normalized->get_error_message() . ')' );
			return;
		}

		$message = $this->render_confirmation_template( $form_title, $phone );
		if ( empty( $message ) ) {
			$this->plugin->api->write_debug_log( 'ninjaforms', 'Skipped Ninja Forms confirmation SMS for form "' . $form_title . '": template disabled or missing' );
			return;
		}

		$this->plugin->api->send(
			$normalized,
			$this->plugin->settings->get( 'gateway.sender_id', '' ),
			$message,
			'ninjaforms'
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Extract the first phone number value from Ninja Forms form data.
	 *
	 * NF stores submitted fields in $form_data['fields'] as an array of field
	 * arrays, each with at minimum 'type' and 'value' keys.
	 *
	 * @param array $form_data The NF form_data array.
	 *
	 * @return string Raw phone string, or empty string if not found.
	 */
	private function extract_phone_from_form_data( array $form_data ) {
		$fields = $form_data['fields'] ?? array();

		foreach ( $fields as $field ) {
			if ( ! $this->is_phone_field( $field ) ) {
				continue;
			}
			return sanitize_text_field( $field['value'] ?? '' );
		}

		return '';
	}

	/**
	 * Determine whether a NF field array represents a phone/tel field.
	 *
	 * Accepts both array and object representations for robustness.
	 *
	 * @param array|object $field NF field array or object.
	 *
	 * @return bool
	 */
	private function is_phone_field( $field ) {
		$type = strtolower( is_array( $field ) ? ( $field['type'] ?? '' ) : ( $field->type ?? '' ) );

		return 'phone' === $type || 'tel' === $type;
	}

	/**
	 * Render the Ninja Forms confirmation SMS from the saved template.
	 *
	 * Loads the `nf_confirmation` template from integrations settings, checks
	 * the `enabled` sub-key, selects the correct language string (ar for RTL
	 * sites, en otherwise), and replaces {form_name} and {phone} placeholders.
	 *
	 * @param string $form_title  The NF form title.
	 * @param string $phone_value The raw phone value from the submission.
	 *
	 * @return string Rendered SMS message, or empty string if disabled / missing.
	 */
	private function render_confirmation_template( $form_title, $phone_value ) {
		$templates = $this->plugin->settings->get_all_integration_templates();
		$template  = $templates['nf_confirmation'] ?? array();

		if ( empty( $template['enabled'] ) ) {
			return '';
		}

		$lang    = ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'ar' : 'en';
		$message = $template[ $lang ] ?? $template['en'] ?? '';

		if ( '' === $message ) {
			return '';
		}

		return str_replace(
			array( '{site_name}', '{form_name}', '{phone}' ),
			array( get_bloginfo( 'name' ), $form_title, $phone_value ),
			$message
		);
	}
}
