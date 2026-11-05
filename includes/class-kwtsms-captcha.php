<?php
/**
 * CAPTCHA Provider: Google reCAPTCHA v3 and Cloudflare Turnstile.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Loads and verifies CAPTCHA widgets on the OTP request forms (login + reset).
 * The active provider is determined by the `general.captcha_provider` setting.
 * If the provider is 'none', no CAPTCHA is shown or verified.
 *
 * reCAPTCHA v3 verification endpoint:   https://www.google.com/recaptcha/api/siteverify
 * Cloudflare Turnstile verification:    https://challenges.cloudflare.com/turnstile/v0/siteverify
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Captcha
 */
class KwtSMS_Captcha {

	/**
	 * Settings helper.
	 *
	 * @var KwtSMS_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param KwtSMS_Settings $settings Plugin settings.
	 */
	public function __construct( KwtSMS_Settings $settings ) {
		$this->settings = $settings;

		// Enqueue scripts on login-adjacent pages.
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_captcha_scripts' ) );
	}

	/**
	 * Enqueue the CAPTCHA JavaScript based on active provider.
	 */
	public function enqueue_captcha_scripts() {
		$provider = $this->settings->get( 'general.captcha_provider', 'none' );

		// phpcs:disable PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- External CAPTCHA APIs (Google reCAPTCHA, Cloudflare Turnstile) require loading scripts from their own CDNs.
		if ( 'recaptcha' === $provider ) {
			$site_key = $this->settings->get( 'general.recaptcha_site_key', '' );
			if ( $site_key ) {
				wp_enqueue_script(
					'google-recaptcha',
					'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ),
					array(),
					KWTSMS_OTP_VERSION,
					true
				);
			}
		} elseif ( 'turnstile' === $provider ) {
			$site_key = $this->settings->get( 'general.turnstile_site_key', '' );
			if ( $site_key ) {
				wp_enqueue_script(
					'cloudflare-turnstile',
					'https://challenges.cloudflare.com/turnstile/v0/api.js',
					array(),
					KWTSMS_OTP_VERSION,
					true
				);
			}
		}
		// phpcs:enable PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent
	}

	// =========================================================================
	// Rendering
	// =========================================================================

	/**
	 * Render the CAPTCHA widget HTML for the OTP request forms.
	 *
	 * ReCAPTCHA v3: renders a hidden input that is populated by JS.
	 * Turnstile: renders a visible widget div.
	 *
	 * @return string HTML output.
	 */
	public function render_widget() {
		$provider = $this->settings->get( 'general.captcha_provider', 'none' );

		if ( 'recaptcha' === $provider ) {
			return $this->render_recaptcha_v3();
		} elseif ( 'turnstile' === $provider ) {
			return $this->render_turnstile();
		}

		return '';
	}

	/**
	 * Render Google reCAPTCHA v3 hidden input + inline JS to populate it.
	 *
	 * @return string HTML.
	 */
	private function render_recaptcha_v3() {
		$site_key = $this->settings->get( 'general.recaptcha_site_key', '' );
		if ( empty( $site_key ) ) {
			return '';
		}

		$js = 'document.addEventListener( "DOMContentLoaded", function () {'
			. ' if ( typeof grecaptcha === "undefined" ) { return; }'
			. ' grecaptcha.ready( function () {'
			. '  grecaptcha.execute( ' . wp_json_encode( $site_key ) . ', { action: "kwtsms_otp_request" } )'
			. '   .then( function ( token ) {'
			. '    var field = document.getElementById( "kwtsms_recaptcha_token" );'
			. '    if ( field ) { field.value = token; }'
			. '   } );'
			. ' } );'
			. '} );';

		wp_add_inline_script( 'google-recaptcha', $js, 'after' );

		return '<input type="hidden" name="kwtsms_recaptcha_token" id="kwtsms_recaptcha_token" />';
	}

	/**
	 * Render Cloudflare Turnstile widget div.
	 *
	 * @return string HTML.
	 */
	private function render_turnstile() {
		$site_key = $this->settings->get( 'general.turnstile_site_key', '' );
		if ( empty( $site_key ) ) {
			return '';
		}

		return sprintf(
			'<div class="cf-turnstile" data-sitekey="%s" data-theme="light"></div>',
			esc_attr( $site_key )
		);
	}

	// =========================================================================
	// Verification
	// =========================================================================

	/**
	 * Verify the CAPTCHA token submitted with a form.
	 *
	 * Returns true on success, WP_Error on failure.
	 * Returns true immediately if no CAPTCHA provider is configured.
	 *
	 * @param array $post_data The $_POST data array.
	 *
	 * @return true|WP_Error
	 */
	public function verify( array $post_data ) {
		$provider = $this->settings->get( 'general.captcha_provider', 'none' );

		if ( 'recaptcha' === $provider ) {
			$token = sanitize_text_field( $post_data['kwtsms_recaptcha_token'] ?? '' );
			return $this->verify_recaptcha_v3( $token );
		}

		if ( 'turnstile' === $provider ) {
			$token = sanitize_text_field( $post_data['cf-turnstile-response'] ?? '' );
			return $this->verify_turnstile( $token );
		}

		// No CAPTCHA configured: pass through.
		return true;
	}

	/**
	 * Verify a Google reCAPTCHA v3 token.
	 *
	 * A score of 0.5 or higher is required (Google's recommended threshold).
	 *
	 * @param string $token The reCAPTCHA token from the client.
	 *
	 * @return true|WP_Error
	 */
	private function verify_recaptcha_v3( $token ) {
		if ( empty( $token ) ) {
			return new WP_Error(
				'kwtsms_captcha_missing',
				__( 'CAPTCHA verification failed. Please try again.', 'kwtsms' )
			);
		}

		$secret = $this->settings->get( 'general.recaptcha_secret_key', '' );
		if ( empty( $secret ) ) {
			// Secret not configured: skip verification.
			return true;
		}

		$response = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Network failure: fail open to avoid blocking legitimate users.
			return true;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $data['success'] ) || ! $data['success'] ) {
			return new WP_Error(
				'kwtsms_captcha_failed',
				__( 'CAPTCHA verification failed. Please try again.', 'kwtsms' )
			);
		}

		if ( isset( $data['score'] ) && $data['score'] < 0.5 ) {
			return new WP_Error(
				'kwtsms_captcha_score',
				__( 'Your request was flagged as suspicious. Please try again.', 'kwtsms' )
			);
		}

		return true;
	}

	/**
	 * Verify a Cloudflare Turnstile token.
	 *
	 * @param string $token The Turnstile token from the client.
	 *
	 * @return true|WP_Error
	 */
	private function verify_turnstile( $token ) {
		if ( empty( $token ) ) {
			return new WP_Error(
				'kwtsms_captcha_missing',
				__( 'Please complete the security check before continuing.', 'kwtsms' )
			);
		}

		$secret = $this->settings->get( 'general.turnstile_secret_key', '' );
		if ( empty( $secret ) ) {
			return true;
		}

		$response = wp_remote_post(
			'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return true; // Fail open on network errors.
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $data['success'] ) || ! $data['success'] ) {
			return new WP_Error(
				'kwtsms_captcha_failed',
				__( 'Security check failed. Please try again.', 'kwtsms' )
			);
		}

		return true;
	}
}
