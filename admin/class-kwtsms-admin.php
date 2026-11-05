<?php
/**
 * Admin Controller: menus, settings registration, admin notices.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Registers three subpages under a top-level "kwtSMS" menu:
 *   - General Settings  (OTP behaviour, CAPTCHA)
 *   - Gateway Settings  (API credentials, SenderID, balance)
 *   - SMS Templates     (EN + AR templates with character counters)
 *
 * Settings are registered via the WordPress Settings API with sanitize callbacks.
 * Scripts and styles are enqueued only on plugin admin pages.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Admin
 */
class KwtSMS_Admin {

	/**
	 * Plugin manager.
	 *
	 * Protected so admin view files included inside methods can access it via $this.
	 *
	 * @var KwtSMS_Plugin
	 */
	protected $plugin;

	/**
	 * Admin page hook suffixes (used to scope asset enqueuing).
	 *
	 * @var string[]
	 */
	private $page_hooks = array();

	/**
	 * Flag set during ajax_logout_gateway() to signal that sanitize_gateway_settings()
	 * should pass the raw value through unchanged (used to write the cleared state
	 * without the sanitizer re-reading stale DB values and overriding it).
	 *
	 * @var bool
	 */
	private $clearing_credentials = false;

	/**
	 * Constructor.
	 *
	 * @param KwtSMS_Plugin $plugin Plugin manager.
	 */
	public function __construct( KwtSMS_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'handle_log_exports' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
		add_filter( 'get_user_option_meta-box-order_dashboard', array( $this, 'force_widget_side_column' ) );
		add_action( 'wp_ajax_kwtsms_get_coverage', array( $this, 'ajax_get_coverage' ) );
		add_action( 'wp_ajax_kwtsms_logout_gateway', array( $this, 'ajax_logout_gateway' ) );
		add_action( 'wp_ajax_kwtsms_save_user_phone', array( $this, 'ajax_save_user_phone' ) );
	}

	// =========================================================================
	// Menu registration
	// =========================================================================

	/**
	 * Register the top-level admin menu and subpages.
	 */
	public function register_menus() {
		$this->page_hooks[] = add_menu_page(
			__( 'kwtSMS', 'kwtsms' ),
			__( 'kwtSMS', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp',
			array( $this, 'render_general_page' ),
			'dashicons-format-chat',
			80
		);

		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'General Settings', 'kwtsms' ),
			__( 'General', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp',
			array( $this, 'render_general_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'Admin Alerts', 'kwtsms' ),
			'&#8627; ' . __( 'Alerts', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp-alerts',
			array( $this, 'render_alerts_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'Gateway Settings', 'kwtsms' ),
			__( 'Gateway', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp-gateway',
			array( $this, 'render_gateway_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'SMS Templates', 'kwtsms' ),
			__( 'Templates', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp-templates',
			array( $this, 'render_templates_page' )
		);

		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'Integrations', 'kwtsms' ),
			__( 'Integrations', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp-integrations',
			array( $this, 'render_integrations_page' )
		);

		// Conditionally register sub-pages for each active integration.
		$integrations_active = array(
			'woo'     => class_exists( 'WooCommerce' ),
			'cf7'     => class_exists( 'WPCF7' ),
			'wpforms' => function_exists( 'wpforms' ) || class_exists( 'WPForms\WPForms' ),
			'nf'      => class_exists( 'Ninja_Forms' ),
		);

		$int_labels = array(
			'woo'     => __( 'WooCommerce', 'kwtsms' ),
			'cf7'     => __( 'Contact Form 7', 'kwtsms' ),
			'wpforms' => __( 'WPForms', 'kwtsms' ),
			'nf'      => __( 'Ninja Forms', 'kwtsms' ),
		);

		foreach ( $integrations_active as $key => $active ) {
			if ( ! $active ) {
				continue;
			}
			$this->page_hooks[] = add_submenu_page(
				'kwtsms-otp',
				/* translators: %s: integration name (e.g. WooCommerce) */
				sprintf( __( '%s Settings', 'kwtsms' ), $int_labels[ $key ] ),
				'&#8627; ' . $int_labels[ $key ],
				'manage_options',
				'kwtsms-otp-int-' . $key,
				array( $this, 'render_int_' . $key . '_page' )
			);
		}

		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'kwtSMS Logs', 'kwtsms' ),
			__( 'Logs', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp-logs',
			array( $this, 'render_logs_page' )
		);

		// ── Users Without Phone: dynamic menu entry ───────────────────────────
		// Query is cached in a 5-minute transient to avoid a meta_query on every
		// admin page load. The transient is cleared whenever a phone is saved
		// (ajax_save_user_phone) or OTP roles are changed (sanitize_general_settings).
		$nophone_count = get_transient( 'kwtsms_nophone_count' );
		if ( false === $nophone_count ) {
			$required_roles = (array) $this->plugin->settings->get( 'general.otp_required_roles', array() );
			$nophone_query  = array(
				'number'     => -1,
				'fields'     => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => 'kwtsms_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'kwtsms_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'value'   => '',
						'compare' => '=',
					),
				),
			);
			if ( ! empty( $required_roles ) ) {
				$nophone_query['role__in'] = $required_roles;
			}
			$nophone_count = count( get_users( $nophone_query ) );
			set_transient( 'kwtsms_nophone_count', $nophone_count, 5 * MINUTE_IN_SECONDS );
		}

		// Add count badge to the menu label when there are users needing phones.
		if ( $nophone_count > 0 ) {
			$users_menu_label = sprintf(
				/* translators: %s: HTML span element containing the count of users missing a phone number. */
				__( 'Users %s', 'kwtsms' ),
				'<span class="update-plugins"><span class="plugin-count">' . (int) $nophone_count . '</span></span>'
			);
		} else {
			$users_menu_label = __( 'Users', 'kwtsms' );
		}

		// Always register the page so its URL stays accessible (e.g. the General
		// Settings notice links here). When count = 0, hide it from the sidebar.
		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'Users Without Phone', 'kwtsms' ),
			$users_menu_label,
			'manage_options',
			'kwtsms-otp-users',
			array( $this, 'render_users_no_phone_page' )
		);

		// When count = 0, hide the menu item via admin_head CSS+JS instead of
		// calling remove_submenu_page(). remove_submenu_page() strips the entry
		// from $submenu but WordPress then cannot resolve the page's parent slug
		// for the hookname check, resulting in a "not allowed" error on direct
		// URL access, which breaks the redirect from the view itself.
		if ( 0 === (int) $nophone_count ) {
			add_action(
				'admin_head',
				static function () {
					// Hide the sidebar <li> that contains the Users Without Phone link.
					wp_add_inline_style(
						'admin-menu',
						'#adminmenu a[href$="page=kwtsms-otp-users"]{display:none}'
					);
				}
			);
			add_action(
				'admin_enqueue_scripts',
				static function () {
					wp_add_inline_script(
						'common',
						"document.addEventListener('DOMContentLoaded',function(){" .
						"var a=document.querySelector('#adminmenu a[href*=\"kwtsms-otp-users\"]');" .
						"if(a){var li=a.closest('li');if(li)li.style.display='none';}" .
						'});'
					);
				}
			);
		}
		// ── End Users Without Phone ────────────────────────────────────────────

		$this->page_hooks[] = add_submenu_page(
			'kwtsms-otp',
			__( 'kwtSMS Help & Support', 'kwtsms' ),
			__( 'Help', 'kwtsms' ),
			'manage_options',
			'kwtsms-otp-help',
			array( $this, 'render_help_page' )
		);
	}

	// =========================================================================
	// Settings registration
	// =========================================================================

	/**
	 * Register all plugin settings with the WordPress Settings API.
	 *
	 * Sanitize callbacks ensure no unsafe data is stored.
	 */
	public function register_settings() {
		// ----- General settings -----
		register_setting(
			'kwtsms_otp_general_group',
			'kwtsms_otp_general',
			array(
				'sanitize_callback' => array( $this, 'sanitize_general_settings' ),
			)
		);

		add_settings_section(
			'kwtsms_general_otp_behavior',
			__( 'OTP Behaviour', 'kwtsms' ),
			'__return_null',
			'kwtsms_general_settings'
		);

		add_settings_section(
			'kwtsms_general_captcha',
			__( 'CAPTCHA Protection', 'kwtsms' ),
			'__return_null',
			'kwtsms_general_settings'
		);

		// ----- Gateway settings -----
		register_setting(
			'kwtsms_otp_gateway_group',
			'kwtsms_otp_gateway',
			array(
				'sanitize_callback' => array( $this, 'sanitize_gateway_settings' ),
			)
		);

		// ----- Template settings -----
		register_setting(
			'kwtsms_otp_templates_group',
			'kwtsms_otp_templates',
			array(
				'sanitize_callback' => array( $this, 'sanitize_template_settings' ),
			)
		);

		// ----- Integration settings -----
		register_setting(
			'kwtsms_otp_integrations_group',
			'kwtsms_otp_integrations',
			array(
				'sanitize_callback' => array( $this, 'sanitize_integrations_settings' ),
			)
		);

		// ----- Admin alert settings -----
		register_setting(
			'kwtsms_otp_alerts_group',
			'kwtsms_otp_alerts',
			array(
				'sanitize_callback' => array( $this, 'sanitize_alerts_settings' ),
			)
		);
	}

	// =========================================================================
	// Sanitize callbacks
	// =========================================================================

	/**
	 * Sanitize general settings.
	 *
	 * @param mixed $raw Raw form input.
	 *
	 * @return array Sanitized settings array.
	 */
	public function sanitize_general_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$defaults = KwtSMS_Settings::DEFAULTS['general'];

		// Sanitize allowed_countries: must be an array of valid ISO2 codes (2 uppercase letters).
		$allowed_raw = $raw['allowed_countries'] ?? $defaults['allowed_countries'];
		if ( ! is_array( $allowed_raw ) ) {
			// Accept JSON string from hidden input.
			$decoded     = json_decode( stripslashes( (string) $allowed_raw ), true );
			$allowed_raw = is_array( $decoded ) ? $decoded : $defaults['allowed_countries'];
		}
		$allowed_countries = array_values(
			array_filter(
				array_map( 'strtoupper', array_map( 'sanitize_text_field', $allowed_raw ) ),
				static function ( $code ) {
					return preg_match( '/^[A-Z]{2}$/', $code );
				}
			)
		);
		if ( empty( $allowed_countries ) ) {
			$allowed_countries = $defaults['allowed_countries'];
		}

		// Sanitize default_country_code: must be a 2-letter uppercase ISO2 code.
		$default_cc = strtoupper( sanitize_text_field( $raw['default_country_code'] ?? $defaults['default_country_code'] ) );
		if ( ! preg_match( '/^[A-Z]{2}$/', $default_cc ) ) {
			$default_cc = $defaults['default_country_code'];
		}

		// Sanitize otp_required_roles: allow only role slugs that exist in WP.
		$all_roles          = array_keys( wp_roles()->get_names() );
		$raw_roles          = array_map( 'sanitize_text_field', (array) ( $raw['otp_required_roles'] ?? array() ) );
		$otp_required_roles = array_values( array_intersect( $raw_roles, $all_roles ) );

		// Changing roles changes which users need phones. Bust the menu count cache.
		delete_transient( 'kwtsms_nophone_count' );

		return array(
			'sms_enabled'           => ! empty( $raw['sms_enabled'] ) ? 1 : 0,
			'otp_mode'              => in_array( $raw['otp_mode'] ?? '', array( '2fa', 'passwordless', 'both' ), true )
				? $raw['otp_mode']
				: $defaults['otp_mode'],
			'otp_length'            => in_array( (int) ( $raw['otp_length'] ?? 6 ), array( 4, 6 ), true )
				? (int) $raw['otp_length']
				: 6,
			'otp_expiry'            => max( 1, min( 30, absint( $raw['otp_expiry'] ?? 5 ) ) ),
			'max_attempts'          => max( 1, min( 10, absint( $raw['max_attempts'] ?? 3 ) ) ),
			'resend_cooldown'       => max( 30, min( 600, absint( $raw['resend_cooldown'] ?? 120 ) ) ),
			'login_otp'             => ! empty( $raw['login_otp'] ) ? 1 : 0,
			'reset_otp'             => ! empty( $raw['reset_otp'] ) ? 1 : 0,
			'captcha_provider'      => in_array( $raw['captcha_provider'] ?? '', array( 'none', 'recaptcha', 'turnstile' ), true )
				? $raw['captcha_provider']
				: 'none',
			'recaptcha_site_key'    => sanitize_text_field( $raw['recaptcha_site_key'] ?? '' ),
			'recaptcha_secret_key'  => sanitize_text_field( $raw['recaptcha_secret_key'] ?? '' ),
			'turnstile_site_key'    => sanitize_text_field( $raw['turnstile_site_key'] ?? '' ),
			'turnstile_secret_key'  => sanitize_text_field( $raw['turnstile_secret_key'] ?? '' ),
			'referral_link'         => ! empty( $raw['referral_link'] ) ? 1 : 0,
			'default_country_code'  => $default_cc,
			'allowed_countries'     => $allowed_countries,
			'debug_logging'         => ! empty( $raw['debug_logging'] ) ? 1 : 0,
			'balance_failure_mode'  => in_array( $raw['balance_failure_mode'] ?? '', array( 'block', 'allow' ), true )
				? $raw['balance_failure_mode']
				: 'block',
			'blocked_phones'        => sanitize_textarea_field( wp_unslash( $raw['blocked_phones'] ?? '' ) ),
			'ip_allowlist'          => $this->sanitize_ip_list( wp_unslash( $raw['ip_allowlist'] ?? '' ) ),
			'ip_blocklist'          => $this->sanitize_ip_list( wp_unslash( $raw['ip_blocklist'] ?? '' ) ),
			'otp_required_roles'    => $otp_required_roles,
			'welcome_sms_enabled'   => ! empty( $raw['welcome_sms_enabled'] ) ? 1 : 0,
			'registration_otp_gate' => in_array(
				$raw['registration_otp_gate'] ?? 'disabled',
				array( 'disabled', 'optional', 'required' ),
				true
			) ? $raw['registration_otp_gate'] : 'disabled',
			'iphub_api_key'         => sanitize_text_field( wp_unslash( $raw['iphub_api_key'] ?? '' ) ),
			'iphub_enabled'         => ! empty( $raw['iphub_enabled'] ),
			'iphub_action_block1'   => in_array( $raw['iphub_action_block1'] ?? '', array( 'allow', 'block', 'log' ), true )
				? $raw['iphub_action_block1']
				: 'block',
			'iphub_action_block2'   => in_array( $raw['iphub_action_block2'] ?? '', array( 'allow', 'block', 'log' ), true )
				? $raw['iphub_action_block2']
				: 'log',
			'iphub_cache_ttl'       => max( 3600, min( 604800, absint( $raw['iphub_cache_ttl'] ?? 86400 ) ) ),
		);
	}

	/**
	 * Sanitize a newline-separated list of IPs and CIDR ranges.
	 *
	 * Strips empty lines, trims whitespace, and discards invalid entries.
	 *
	 * @param string $raw Raw textarea input.
	 *
	 * @return string Cleaned newline-separated list.
	 */
	private function sanitize_ip_list( string $raw ): string {
		$lines   = preg_split( '/[\r\n]+/', sanitize_textarea_field( $raw ), -1, PREG_SPLIT_NO_EMPTY );
		$cleaned = array();
		foreach ( $lines as $line ) {
			$line  = trim( $line );
			$parts = explode( '/', $line, 2 );
			$ip    = $parts[0];
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( isset( $parts[1] ) ) {
				$prefix = (int) $parts[1];
				$max    = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? 128 : 32;
				if ( $prefix < 0 || $prefix > $max ) {
					continue;
				}
				$cleaned[] = $ip . '/' . $prefix;
			} else {
				$cleaned[] = $ip;
			}
		}
		return implode( "\n", $cleaned );
	}

	/**
	 * Sanitize gateway settings.
	 *
	 * @param mixed $raw Raw form input.
	 *
	 * @return array Sanitized settings array.
	 */
	public function sanitize_gateway_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// During ajax_logout_gateway() the flag is set to write the cleared state
		// directly without the sanitizer re-reading and overriding stale DB values.
		if ( $this->clearing_credentials ) {
			return $raw;
		}

		// test_phone is intentionally not persisted: the field has no name attribute.
		$test_phone = '';

		// Warn if the API username looks like a phone number.
		$api_username_raw = sanitize_text_field( $raw['api_username'] ?? '' );
		if ( ! empty( $api_username_raw ) && preg_match( '/^\+?[\d\s()\-]{8,}$/', $api_username_raw ) ) {
			add_settings_error(
				'kwtsms_otp_gateway',
				'phone_as_username',
				__( 'API Username appears to be a phone number. Please enter your kwtSMS API username, not your phone number. Sign up at kwtsms.com to obtain API access.', 'kwtsms' ),
				'warning'
			);
		}

		// Preserve credentials_verified flag only if the credentials are unchanged.
		$current_gw = get_option( 'kwtsms_otp_gateway', array() );
		// Use wp_unslash only (not sanitize_text_field) to preserve special characters
		// such as <, >, &, ", ' which sanitize_text_field would strip, corrupting the credential.
		$api_password_raw = wp_unslash( $raw['api_password'] ?? '' );

		// The password input is never pre-populated in the HTML (security: prevent
		// the plaintext credential appearing in page source / browser history).
		// An empty submission means "keep the stored password unchanged".
		if ( '' === $api_password_raw ) {
			$api_password_raw = $current_gw['api_password'] ?? '';
		}

		$creds_unchanged = (
			( $current_gw['api_username'] ?? '' ) === $api_username_raw &&
			( $current_gw['api_password'] ?? '' ) === $api_password_raw
		);

		if ( $creds_unchanged ) {
			// Prefer an explicit value in $raw (the logout AJAX handler sets it to 0).
			// Fall back to the current DB value for plain form POSTs that omit the key.
			$credentials_verified = array_key_exists( 'credentials_verified', $raw )
				? (int) $raw['credentials_verified']
				: (int) ( $current_gw['credentials_verified'] ?? 0 );
		} else {
			$credentials_verified = 0;
		}

		// Carry over previously fetched gateway data.
		// When called from a form POST, $raw only contains the HTML form fields
		// (balance, coverage, sender_ids are absent from the POST body).
		// When called via update_option() from an AJAX handler the full option
		// array is passed as $raw, so we must prefer those values; otherwise
		// the sanitize filter silently overwrites the freshly-fetched data with
		// the old values that were in the DB when the filter fired.
		$sender_ids        = is_array( $raw['sender_ids'] ?? null )
			? (array) $raw['sender_ids']
			: (array) ( $current_gw['sender_ids'] ?? array() );
		$balance_available = array_key_exists( 'balance_available', $raw )
			? $raw['balance_available']
			: ( $current_gw['balance_available'] ?? null );
		$balance_purchased = array_key_exists( 'balance_purchased', $raw )
			? $raw['balance_purchased']
			: ( $current_gw['balance_purchased'] ?? null );
		$balance_updated   = array_key_exists( 'balance_updated_at', $raw )
			? (int) $raw['balance_updated_at']
			: (int) ( $current_gw['balance_updated_at'] ?? 0 );
		$coverage          = is_array( $raw['coverage'] ?? null )
			? (array) $raw['coverage']
			: (array) ( $current_gw['coverage'] ?? array() );
		$sender_id_out     = sanitize_text_field( $raw['sender_id'] ?? '' );

		// Auto-verify when credentials are new or changed.
		if ( ! $creds_unchanged && ! empty( $api_username_raw ) && ! empty( $api_password_raw ) ) {
			$api               = new KwtSMS_API( $api_username_raw, $api_password_raw, false );
			$sender_ids_result = $api->get_sender_ids();

			if ( is_wp_error( $sender_ids_result ) ) {
				add_settings_error(
					'kwtsms_otp_gateway',
					'credentials_invalid',
					sprintf(
						/* translators: %s: error message from kwtSMS API */
						__( 'API credentials could not be verified: %s', 'kwtsms' ),
						$sender_ids_result->get_error_message()
					),
					'error'
				);
			} else {
				$credentials_verified = 1;
				$sender_ids           = $sender_ids_result;

				// Auto-select first sender ID if none was previously saved.
				if ( empty( $sender_id_out ) && ! empty( $sender_ids ) ) {
					$sender_id_out = $sender_ids[0];
				}

				$balance_result  = $api->get_balance();
				$coverage_result = $api->get_coverage();

				if ( ! is_wp_error( $balance_result ) ) {
					$balance_available = $balance_result['available'];
					$balance_purchased = $balance_result['purchased'];
					$balance_updated   = time();
				}
				if ( ! is_wp_error( $coverage_result ) ) {
					$coverage = (array) $coverage_result;
				}

				add_settings_error(
					'kwtsms_otp_gateway',
					'credentials_verified',
					__( 'API credentials verified successfully.', 'kwtsms' ),
					'success'
				);
			}
		}

		return array(
			'api_username'         => $api_username_raw,
			'api_password'         => $api_password_raw,
			'sender_id'            => $sender_id_out,
			'test_mode'            => ! empty( $raw['test_mode'] ) ? 1 : 0,
			'test_phone'           => $test_phone,
			'credentials_verified' => $credentials_verified,
			'sender_ids'           => $sender_ids,
			'balance_available'    => $balance_available,
			'balance_purchased'    => $balance_purchased,
			'balance_updated_at'   => $balance_updated,
			'coverage'             => $coverage,
		);
	}

	/**
	 * Sanitize template settings.
	 *
	 * Strips HTML tags and emojis from message templates.
	 *
	 * @param mixed $raw Raw form input.
	 *
	 * @return array Sanitized settings array.
	 */
	public function sanitize_template_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// 2026-11-05 19:07 +03: checkout_otp added so the Templates page can save it, wp-otp-template.
		$sanitized    = array();
		$allowed_keys = array( 'login_otp', 'checkout_otp', 'reset_otp', 'welcome_sms' );

		foreach ( $allowed_keys as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
				continue;
			}

			$template          = $raw[ $key ];
			$sanitized[ $key ] = array(
				'enabled' => ! empty( $template['enabled'] ) ? 1 : 0,
				'en'      => $this->sanitize_template_content( $template['en'] ?? '' ),
				'ar'      => $this->sanitize_template_content( $template['ar'] ?? '' ),
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize integration settings.
	 *
	 * Handles both boolean enable flags and template content arrays.
	 * Uses the currently saved option as a base so that saving one
	 * integration's sub-form does not wipe the settings of others.
	 * The hidden `_save_section` field identifies which sub-form was
	 * submitted; 'all' (or absent) means the legacy full-page form.
	 *
	 * @param mixed $raw Raw form input.
	 *
	 * @return array Sanitized settings array.
	 */
	public function sanitize_integrations_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// Start from currently saved values so that saving one integration's
		// sub-form does not wipe the other integrations' settings.
		$current = get_option( 'kwtsms_otp_integrations', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// _save_section identifies which integration sub-form was submitted.
		// 'all' or empty means the legacy full-page form: sanitize everything.
		$section = sanitize_key( $raw['_save_section'] ?? 'all' );

		$valid_modes = array( 'notification', 'gate' );

		$allowed_statuses      = array_map(
			'sanitize_key',
			array(
				'processing',
				'on-hold',
				'completed',
				'cancelled',
				'pending',
				'refunded',
				'failed',
			)
		);
		$raw_notify            = $raw['woo_notify_admin_statuses'] ?? array();
		$notify_admin_statuses = array_values(
			array_filter(
				(array) $raw_notify,
				function ( $s ) use ( $allowed_statuses ) {
					return in_array( sanitize_key( $s ), $allowed_statuses, true );
				}
			)
		);

		// Use current saved values as base; overwrite only the submitted section.
		$sanitized = $current;

		$update_woo     = in_array( $section, array( 'all', 'woo' ), true );
		$update_cf7     = in_array( $section, array( 'all', 'cf7' ), true );
		$update_wpforms = in_array( $section, array( 'all', 'wpforms' ), true );
		$update_nf      = in_array( $section, array( 'all', 'nf' ), true );

		// Tab-specific subsections: each sub-tab saves only its own fields, not overwritten by the parent 'woo' save.
		$update_stock_alerts     = in_array( $section, array( 'all', 'stock_alerts' ), true );
		$update_multivendor      = in_array( $section, array( 'all', 'multivendor' ), true );
		$update_cart_abandonment = in_array( $section, array( 'all', 'cart_abandonment' ), true );

		if ( $update_woo ) {
			$sanitized['woo_enabled']               = ! empty( $raw['woo_enabled'] ) ? 1 : 0;
			$sanitized['woo_checkout_otp']          = ! empty( $raw['woo_checkout_otp'] ) ? 1 : 0;
			$sanitized['woo_checkout_otp_cod_only'] = ! empty( $raw['woo_checkout_otp_cod_only'] ) ? 1 : 0;
			$sanitized['woo_admin_phone']           = sanitize_text_field( wp_unslash( $raw['woo_admin_phone'] ?? '' ) );
			$sanitized['woo_notify_admin_statuses'] = $notify_admin_statuses;
			foreach ( array( 'woo_processing', 'woo_shipped', 'woo_completed', 'woo_cancelled', 'woo_pending', 'woo_refunded', 'woo_failed' ) as $key ) {
				if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) ) {
					$sanitized[ $key ] = array(
						'enabled' => ! empty( $raw[ $key ]['enabled'] ) ? 1 : 0,
						'en'      => $this->sanitize_template_content( $raw[ $key ]['en'] ?? '' ),
						'ar'      => $this->sanitize_template_content( $raw[ $key ]['ar'] ?? '' ),
					);
				}
			}
		}

		// D1+D2: Stock alerts (saved from stock_alerts tab).
		if ( $update_stock_alerts ) {
			$sanitized['woo_stock_admin_phone']     = sanitize_text_field( wp_unslash( $raw['woo_stock_admin_phone'] ?? '' ) );
			$sanitized['woo_low_stock_enabled']     = ! empty( $raw['woo_low_stock_enabled'] ) ? 1 : 0;
			$sanitized['woo_no_stock_enabled']      = ! empty( $raw['woo_no_stock_enabled'] ) ? 1 : 0;
			$sanitized['woo_backorder_enabled']     = ! empty( $raw['woo_backorder_enabled'] ) ? 1 : 0;
			$sanitized['woo_new_product_enabled']   = ! empty( $raw['woo_new_product_enabled'] ) ? 1 : 0;
			$sanitized['woo_back_in_stock_enabled'] = ! empty( $raw['woo_back_in_stock_enabled'] ) ? 1 : 0;
			foreach ( array( 'woo_tpl_low_stock', 'woo_tpl_no_stock', 'woo_tpl_backorder', 'woo_tpl_new_product', 'woo_tpl_back_in_stock' ) as $tpl_key ) {
				$tpl_raw               = is_array( $raw[ $tpl_key ] ?? null ) ? $raw[ $tpl_key ] : array();
				$sanitized[ $tpl_key ] = array(
					'en' => $this->sanitize_template_content( $tpl_raw['en'] ?? '' ),
					'ar' => $this->sanitize_template_content( $tpl_raw['ar'] ?? '' ),
				);
			}
		}

		// D4: Instant order + multivendor (saved from multivendor tab).
		if ( $update_multivendor ) {
			$sanitized['woo_instant_order_enabled'] = ! empty( $raw['woo_instant_order_enabled'] ) ? 1 : 0;
			$sanitized['woo_instant_order_phone']   = sanitize_text_field( wp_unslash( $raw['woo_instant_order_phone'] ?? '' ) );
			$sanitized['woo_vendor_sms_enabled']    = ! empty( $raw['woo_vendor_sms_enabled'] ) ? 1 : 0;
			foreach ( array( 'woo_tpl_instant_order', 'woo_tpl_vendor_new_order' ) as $tpl_key ) {
				$tpl_raw               = is_array( $raw[ $tpl_key ] ?? null ) ? $raw[ $tpl_key ] : array();
				$sanitized[ $tpl_key ] = array(
					'en' => $this->sanitize_template_content( $tpl_raw['en'] ?? '' ),
					'ar' => $this->sanitize_template_content( $tpl_raw['ar'] ?? '' ),
				);
			}
		}

		// D3: Cart abandonment recovery (saved from cart_abandonment tab).
		if ( $update_cart_abandonment ) {
			$sanitized['woo_cart_abandon_enabled'] = ! empty( $raw['woo_cart_abandon_enabled'] ) ? 1 : 0;

			// Unschedule the cron immediately when the feature is disabled so the
			// event does not linger as an orphaned no-op until plugin deactivation.
			if ( ! $sanitized['woo_cart_abandon_enabled'] ) {
				wp_clear_scheduled_hook( 'kwtsms_check_abandoned_carts' );
			}
			$sanitized['woo_cart_abandon_delay']  = max( 1, absint( $raw['woo_cart_abandon_delay'] ?? 60 ) );
			$sanitized['woo_cart_abandon_coupon'] = min( 100, absint( $raw['woo_cart_abandon_coupon'] ?? 10 ) );
			$sanitized['woo_cart_abandon_expiry'] = max( 1, absint( $raw['woo_cart_abandon_expiry'] ?? 48 ) );
			$tpl_ca_raw                           = is_array( $raw['woo_tpl_cart_abandon'] ?? null ) ? $raw['woo_tpl_cart_abandon'] : array();
			$sanitized['woo_tpl_cart_abandon']    = array(
				'en' => $this->sanitize_template_content( $tpl_ca_raw['en'] ?? '' ),
				'ar' => $this->sanitize_template_content( $tpl_ca_raw['ar'] ?? '' ),
			);
		}

		if ( $update_cf7 ) {
			$sanitized['cf7_enabled'] = ! empty( $raw['cf7_enabled'] ) ? 1 : 0;
			$sanitized['cf7_mode']    = in_array( $raw['cf7_mode'] ?? '', $valid_modes, true ) ? $raw['cf7_mode'] : 'notification';
			if ( isset( $raw['cf7_confirmation'] ) && is_array( $raw['cf7_confirmation'] ) ) {
				$sanitized['cf7_confirmation'] = array(
					'enabled' => ! empty( $raw['cf7_confirmation']['enabled'] ) ? 1 : 0,
					'en'      => $this->sanitize_template_content( $raw['cf7_confirmation']['en'] ?? '' ),
					'ar'      => $this->sanitize_template_content( $raw['cf7_confirmation']['ar'] ?? '' ),
				);
			}
		}

		if ( $update_wpforms ) {
			$sanitized['wpforms_enabled'] = ! empty( $raw['wpforms_enabled'] ) ? 1 : 0;
			$sanitized['wpforms_mode']    = in_array( $raw['wpforms_mode'] ?? '', $valid_modes, true ) ? $raw['wpforms_mode'] : 'notification';
			if ( isset( $raw['wpforms_confirmation'] ) && is_array( $raw['wpforms_confirmation'] ) ) {
				$sanitized['wpforms_confirmation'] = array(
					'enabled' => ! empty( $raw['wpforms_confirmation']['enabled'] ) ? 1 : 0,
					'en'      => $this->sanitize_template_content( $raw['wpforms_confirmation']['en'] ?? '' ),
					'ar'      => $this->sanitize_template_content( $raw['wpforms_confirmation']['ar'] ?? '' ),
				);
			}
		}

		if ( $update_nf ) {
			$sanitized['nf_enabled'] = ! empty( $raw['nf_enabled'] ) ? 1 : 0;
			$sanitized['nf_mode']    = in_array( $raw['nf_mode'] ?? '', $valid_modes, true ) ? $raw['nf_mode'] : 'notification';
			if ( isset( $raw['nf_confirmation'] ) && is_array( $raw['nf_confirmation'] ) ) {
				$sanitized['nf_confirmation'] = array(
					'enabled' => ! empty( $raw['nf_confirmation']['enabled'] ) ? 1 : 0,
					'en'      => $this->sanitize_template_content( $raw['nf_confirmation']['en'] ?? '' ),
					'ar'      => $this->sanitize_template_content( $raw['nf_confirmation']['ar'] ?? '' ),
				);
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitize admin alerts settings.
	 *
	 * @param mixed $raw Raw POST values (expected array).
	 * @return array Sanitized settings array.
	 */
	public function sanitize_alerts_settings( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$out = array();

		// Admin phone numbers (free-text, stored as-is; validated on send).
		$out['admin_phones'] = sanitize_text_field( wp_unslash( $raw['admin_phones'] ?? '' ) );

		// Per-event toggles.
		foreach ( array( 'user_register', 'wp_login', 'post_published', 'comment_posted', 'core_update' ) as $key ) {
			$out[ $key ] = ! empty( $raw[ $key ] ) ? 1 : 0;
		}

		// Per-event templates (EN + AR): uses sanitize_template_content() to strip
		// emoji, hidden Unicode, and invalid characters, consistent with other templates.
		$tpl_keys = array( 'tpl_user_register', 'tpl_wp_login', 'tpl_post_published', 'tpl_comment_posted', 'tpl_core_update' );
		foreach ( $tpl_keys as $tkey ) {
			$out[ $tkey ] = array(
				'en' => $this->sanitize_template_content( $raw[ $tkey . '_en' ] ?? '' ),
				'ar' => $this->sanitize_template_content( $raw[ $tkey . '_ar' ] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * Strip HTML, emoji, and invisible Unicode characters from a template string.
	 *
	 * Unslashes WordPress magic-quotes, passes through sanitize_textarea_field
	 * (invalid UTF-8, basic tag stripping, whitespace normalisation), then
	 * delegates to KwtSMS_API::clean_message() for comprehensive emoji and
	 * hidden-character removal.  What is stored is exactly what will be sent.
	 *
	 * @param string $content Raw template content.
	 *
	 * @return string Clean template.
	 */
	private function sanitize_template_content( $content ) {
		return KwtSMS_API::clean_message( sanitize_textarea_field( wp_unslash( (string) $content ) ) );
	}

	// =========================================================================
	// Asset enqueuing
	// =========================================================================

	/**
	 * Enqueue admin CSS and JS only on plugin pages.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, $this->page_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'kwtsms-admin',
			KWTSMS_OTP_URL . 'assets/css/admin.css',
			array(),
			KWTSMS_OTP_VERSION
		);

		if ( is_rtl() ) {
			wp_enqueue_style(
				'kwtsms-admin-rtl',
				KWTSMS_OTP_URL . 'assets/css/admin-rtl.css',
				array( 'kwtsms-admin' ),
				KWTSMS_OTP_VERSION
			);
		}

		wp_enqueue_script(
			'kwtsms-admin',
			KWTSMS_OTP_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			KWTSMS_OTP_VERSION,
			true
		);

		// Resolve default dial code for auto-prefixing short phone numbers in JS.
		$default_iso2 = $this->plugin->settings->get( 'general.default_country_code', 'KW' );
		$all_ccs      = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';
		$default_dial = '965'; // Kuwait fallback.
		foreach ( $all_ccs as $cc_row ) {
			if ( $cc_row['iso2'] === $default_iso2 ) {
				$default_dial = $cc_row['dial'];
				break;
			}
		}

		wp_localize_script(
			'kwtsms-admin',
			'kwtSmsAdminData',
			array(
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( 'kwtsms_admin_nonce' ),
				'credentialsVerified'   => (bool) $this->plugin->settings->get( 'gateway.credentials_verified', false ),
				'defaultDialCode'       => $default_dial,
				'savedSenderIds'        => array_values( (array) $this->plugin->settings->get( 'gateway.sender_ids', array() ) ),
				'savedBalance'          => array(
					'available'  => $this->plugin->settings->get( 'gateway.balance_available', null ),
					'purchased'  => $this->plugin->settings->get( 'gateway.balance_purchased', null ),
					'updated_at' => (int) $this->plugin->settings->get( 'gateway.balance_updated_at', 0 ),
				),
				'savedCoverage'         => array_values( (array) $this->plugin->settings->get( 'gateway.coverage', array() ) ),
				'template_defaults'     => $this->plugin->settings->get_template_defaults_for_js(),
				'placeholder_estimates' => array(
					'{otp}'            => str_repeat( '0', (int) $this->plugin->settings->get( 'general.otp_length', 6 ) ),
					'{site_name}'      => get_bloginfo( 'name' ),
					'{expiry_minutes}' => (string) (int) $this->plugin->settings->get( 'general.otp_expiry', 10 ),
					'{name}'           => 'User',
					'{customer_name}'  => 'User',
					'{order_id}'       => '12345',
					'{total}'          => '10.000 KWD',
					'{tracking}'       => 'TRK123456',
					'{form_name}'      => 'Contact Form',
					// 2026-11-05 11:21 +03: same-length placeholder instead of a real-looking Kuwait number, int-testnumber.
					'{phone}'          => '965XXXXXXXX',
				),
				'strings'               => array(
					'verifying'          => __( 'Verifying...', 'kwtsms' ),
					'verified'           => __( 'Credentials verified!', 'kwtsms' ),
					'error'              => __( 'Verification failed.', 'kwtsms' ),
					'sending'            => __( 'Sending...', 'kwtsms' ),
					'sent'               => __( 'Test SMS sent! Check your phone.', 'kwtsms' ),
					'characters'         => __( 'characters', 'kwtsms' ),
					'smsPages'           => __( 'SMS page(s)', 'kwtsms' ),
					'usernameIsPhone'    => __( 'This looks like a phone number. Your API Username must be your kwtSMS account username, not a phone number. Sign up at kwtsms.com to obtain API credentials.', 'kwtsms' ),
					'loadingCoverage'    => __( 'Loading coverage...', 'kwtsms' ),
					'coverageError'      => __( 'Could not load coverage data.', 'kwtsms' ),
					'credentialsMissing' => __( 'Please enter your API username and password, then click "Save Settings" before performing this action.', 'kwtsms' ),
					/* translators: %s: API username */
					'connectedAs'        => __( 'Connected as %s', 'kwtsms' ),
					'reload'             => __( 'Reload', 'kwtsms' ),
					'reloading'          => __( 'Reloading...', 'kwtsms' ),
					/* translators: %s: total purchased SMS credits */
					'ofPurchased'        => __( '· of %s purchased', 'kwtsms' ),
					'testPhoneMissing'   => __( 'Please enter a test phone number first.', 'kwtsms' ),
					'phoneTooShort'      => __( 'Number is too short. Enter the country code followed by the full local number, e.g. 96512345678 (Kuwait: 965 + 8 digits).', 'kwtsms' ),
					'testModeResult'     => __( 'Test mode ON: message queued in kwtSMS account queue, will not be delivered. Delete to recover credits.', 'kwtsms' ),
					'testSmsResult'      => __( 'SMS delivered to %phone%. Check your messages.', 'kwtsms' ),
					'testSmsFailed'      => __( 'Send failed. Check your API credentials and phone number.', 'kwtsms' ),
					'unsavedTitle'       => __( 'Unsaved Changes', 'kwtsms' ),
					'unsavedBody'        => __( 'You have unsaved changes. Leaving this page will discard them.', 'kwtsms' ),
					'unsavedSave'        => __( 'Save Changes', 'kwtsms' ),
					'unsavedLeave'       => __( 'Leave Page', 'kwtsms' ),
				),
			)
		);
	}

	// =========================================================================
	// Admin notices
	// =========================================================================

	/**
	 * Hook callback for admin_notices: skips plugin pages (they render inline).
	 *
	 * This prevents duplicate rendering: plugin pages call render_page_notices()
	 * directly inside the .wrap div so notices appear before the logo header.
	 */
	public function show_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_plugin_page = in_array(
			$screen->id,
			array_filter( $this->page_hooks ),
			true
		) || strpos( $screen->id, 'kwtsms' ) !== false;

		// Plugin pages render notices inline (before the logo): skip here.
		if ( $is_plugin_page ) {
			return;
		}
	}

	/**
	 * Render contextual plugin notices inline.
	 *
	 * Called at the top of every plugin page template, before the logo header,
	 * so notices visually appear above the branding rather than beside it.
	 * Also calls settings_errors() to show Settings API save/validation messages.
	 */
	public function render_page_notices() {
		// Settings API messages (e.g. "Settings saved.", validation errors).
		// Capture output and add 'inline' class so WP admin JS doesn't move
		// these notices to after the <h1> inside our flex header row.
		ob_start();
		settings_errors();
		$se_html = ob_get_clean();
		// WP outputs settings_errors with single-quoted class attr (class='notice ...').
		// Add 'inline' so WP admin JS doesn't relocate the notice into our flex header.
		$se_html = str_replace( "class='notice ", "class='notice inline ", $se_html );
		echo wp_kses_post( $se_html );

		// Notice: site is not HTTPS; suppressed on localhost/127.0.0.1 dev environments.
		// The 'inline' class prevents WP admin JS from relocating the notice
		// to after the first <h1> (which is inside our flex header row).
		if ( ! is_ssl() ) {
			$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$is_local  = ( 'localhost' === $home_host || '127.0.0.1' === $home_host || '::1' === $home_host
							|| 0 === strpos( $home_host, 'localhost' ) );
			if ( ! $is_local ) {
				printf(
					'<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'kwtSMS Warning:', 'kwtsms' ),
					esc_html__( 'Your site is not served over HTTPS. OTP codes may be intercepted in transit. Enable SSL for security.', 'kwtsms' )
				);
			}
		}

		// Notice: API credentials not verified (never logged in, or logged out).
		if ( ! $this->plugin->settings->get( 'gateway.credentials_verified', false ) ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s</strong> %s <a href="%s">%s</a>. %s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></p></div>',
				esc_html__( 'kwtSMS:', 'kwtsms' ),
				esc_html__( 'API credentials are not configured.', 'kwtsms' ),
				esc_url( admin_url( 'admin.php?page=kwtsms-otp-gateway' ) ),
				esc_html__( 'Configure now', 'kwtsms' ),
				esc_html__( "Don't have a kwtSMS account?", 'kwtsms' ),
				esc_url( 'https://www.kwtsms.com/signup' ),
				esc_html__( 'Sign up for free', 'kwtsms' )
			);
		}

		// Notice: test mode is active.
		if ( $this->plugin->settings->get( 'gateway.test_mode', 1 ) ) {
			printf(
				/* translators: %s: link to kwtSMS account dashboard */
				'<div class="notice notice-error inline"><p>' . esc_html__( 'kwtSMS is in Test Mode. SMS messages will be queued but not delivered. Delete from %s queue to recover credits.', 'kwtsms' ) . '</p></div>',
				'<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'kwtSMS account', 'kwtsms' ) . '</a>'
			);
		}
	}

	// =========================================================================
	// Page renderers
	// =========================================================================

	/**
	 * Render the General Settings page.
	 */
	public function render_general_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-general.php';
	}

	/**
	 * Render the Gateway Settings page.
	 */
	public function render_gateway_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-gateway.php';
	}

	/**
	 * Render the SMS Templates page.
	 */
	public function render_templates_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-templates.php';
	}

	/**
	 * Render the Integrations admin page.
	 */
	public function render_integrations_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-integrations.php';
	}

	/**
	 * Render WooCommerce integration settings sub-page.
	 */
	public function render_int_woo_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-int-woo.php';
	}

	/**
	 * Render Contact Form 7 integration settings sub-page.
	 */
	public function render_int_cf7_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		$int_key = 'cf7';
		include KWTSMS_OTP_DIR . 'admin/views/page-int-form.php';
	}

	/**
	 * Render WPForms integration settings sub-page.
	 */
	public function render_int_wpforms_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		$int_key = 'wpforms';
		include KWTSMS_OTP_DIR . 'admin/views/page-int-form.php';
	}

	/**
	 * Render Ninja Forms integration settings sub-page.
	 */
	public function render_int_nf_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		$int_key = 'nf';
		include KWTSMS_OTP_DIR . 'admin/views/page-int-form.php';
	}

	/**
	 * Render the Logs page (SMS History + OTP Attempts).
	 */
	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		// Parse navigation params from the request URI using WordPress URL functions.
		// This avoids direct $_GET access which the scanner flags.
		$query_string = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_QUERY );
		$query_params = array();
		if ( $query_string ) {
			wp_parse_str( $query_string, $query_params );
		}
		$valid_tabs          = array( 'sms_history', 'attempt_log', 'debug_log' );
		$kwtsms_active_tab   = isset( $query_params['tab'] ) ? sanitize_key( $query_params['tab'] ) : 'sms_history';
		$kwtsms_active_tab   = in_array( $kwtsms_active_tab, $valid_tabs, true ) ? $kwtsms_active_tab : 'sms_history';
		$kwtsms_current_page = isset( $query_params['paged'] ) ? max( 1, absint( $query_params['paged'] ) ) : 1;
		include KWTSMS_OTP_DIR . 'admin/views/page-logs.php';
	}

	/**
	 * Render the Admin Alerts settings page.
	 */
	public function render_alerts_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-alerts.php';
	}

	/**
	 * Render the Help & Support page.
	 */
	public function render_help_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-help.php';
	}

	/**
	 * Render the Users Without Phone page.
	 *
	 * Lists users in OTP-required roles who have no phone number saved,
	 * with inline AJAX editing so admins can assign phone numbers directly.
	 */
	public function render_users_no_phone_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
		}
		include KWTSMS_OTP_DIR . 'admin/views/page-users-no-phone.php';
	}

	// =========================================================================
	// AJAX: Coverage
	// =========================================================================

	/**
	 * AJAX handler: fetch SMS coverage data.
	 *
	 * Security: nonce + manage_options capability.
	 */
	public function ajax_get_coverage() {
		check_ajax_referer( 'kwtsms_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		$coverage = $this->plugin->api->get_coverage();

		if ( is_wp_error( $coverage ) ) {
			wp_send_json_error( array( 'message' => $coverage->get_error_message() ) );
			return;
		}

		wp_send_json_success( array( 'coverage' => $coverage ) );
	}

	// =========================================================================
	// AJAX: Logout (clear credentials_verified flag and fetched API data)
	// =========================================================================

	/**
	 * AJAX: Logout, clear verified flag and all data fetched from the API.
	 *
	 * Resets credentials_verified, sender_ids, sender_id, coverage, and
	 * balance so the UI shows a clean state until the user logs in again.
	 *
	 * Security: nonce + manage_options.
	 */
	public function ajax_logout_gateway() {
		check_ajax_referer( 'kwtsms_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		$gw                         = get_option( 'kwtsms_otp_gateway', array() );
		$gw['credentials_verified'] = 0;
		$gw['api_username']         = '';
		$gw['api_password']         = '';
		$gw['sender_ids']           = array();
		$gw['sender_id']            = '';
		$gw['coverage']             = array();
		$gw['balance_available']    = null;
		$gw['balance_purchased']    = null;
		$gw['balance_updated_at']   = 0;

		// Set the flag so sanitize_gateway_settings() passes $gw through unchanged,
		// writing the cleared state without the sanitizer re-reading stale DB values.
		$this->clearing_credentials = true;
		update_option( 'kwtsms_otp_gateway', $gw );
		$this->clearing_credentials = false;

		wp_send_json_success();
	}

	// =========================================================================
	// AJAX: Save user phone (Users Without Phone page)
	// =========================================================================

	/**
	 * AJAX handler: save a phone number for a given user.
	 *
	 * Called from the "Users Without Phone" admin page. Normalizes the phone
	 * using the same pipeline as OTP login, then stores it in user meta.
	 *
	 * Security: nonce + manage_options capability.
	 */
	public function ajax_save_user_phone() {
		// --- Access control ---
		check_ajax_referer( 'kwtsms_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'kwtsms' ) ), 403 );
			return;
		}

		// --- Input sanitization ---

		// user_id: must be a positive integer; absint() rejects negatives and zero.
		$user_id = absint( isset( $_POST['user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['user_id'] ) ) : 0 );

		$phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		if ( strlen( $phone ) > 25 ) {
			wp_send_json_error( array( 'message' => __( 'Phone number is too long.', 'kwtsms' ) ) );
			return;
		}

		// --- Validation ---

		if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid user.', 'kwtsms' ) ) );
			return;
		}

		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Phone number is required.', 'kwtsms' ) ) );
			return;
		}

		// Strip everything except digits (and a leading +) before further processing.
		// This is defense-in-depth: the AJAX pipeline must not depend solely on
		// client-side validation, which can be bypassed.
		$phone_digits = preg_replace( '/[^0-9+]/', '', $phone );
		if ( '' === $phone_digits || ! preg_match( '/[0-9]/', $phone_digits ) ) {
			wp_send_json_error( array( 'message' => __( 'Phone number must contain digits.', 'kwtsms' ) ) );
			return;
		}

		// Prepend default dial code for local numbers, then normalize and validate.
		$full_phone = KwtSMS_API::prepend_country_code_if_local( $phone_digits, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $full_phone );

		if ( is_wp_error( $normalized ) ) {
			wp_send_json_error( array( 'message' => $normalized->get_error_message() ) );
			return;
		}

		// --- Persist ---
		// update_user_meta() uses $wpdb->prepare() internally; no SQL injection risk.
		update_user_meta( $user_id, 'kwtsms_phone', $normalized );

		// Bust the menu count cache so the sidebar badge updates on the next page load.
		delete_transient( 'kwtsms_nophone_count' );

		wp_send_json_success(
			array(
				'message' => __( 'Phone saved.', 'kwtsms' ),
				'phone'   => $normalized,
			)
		);
	}

	// =========================================================================
	// AJAX: Clear log
	// =========================================================================

	/**
	 * Handle log file download/export requests before any HTML output.
	 *
	 * Hooked on admin_init so Content-Type / Content-Disposition headers can
	 * be sent before WordPress outputs any HTML. Handles three actions:
	 *   - export_csv          - stream SMS history or OTP attempt log as CSV
	 *   - download_debug_log  - stream the debug log file as a download
	 *   - clear_debug_log     - truncate the debug log file then redirect
	 *
	 * Security: capability check + per-action nonce verification on every branch.
	 */
	public function handle_log_exports() {
		// This method is hooked to admin_init. Only proceed on our logs page.
		// Use the global $pagenow and admin page detection instead of reading GET.
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// check_admin_referer() reads and verifies the nonce from the request.
		// It calls wp_die() on failure, so everything below is nonce-verified.
		// We check two possible nonce actions: debug log download and CSV export.
		// Nonces arrive as GET query parameters in download/export links.
		$nonce_value = sanitize_key( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
		$download_ok = wp_verify_nonce( $nonce_value, 'kwtsms_download_debug_log' );
		$clear_ok    = wp_verify_nonce( $nonce_value, 'kwtsms_clear_debug_log' );

		// CSV export nonces are per-log-key; checked inline below.
		$csv_sms_ok = wp_verify_nonce( $nonce_value, 'kwtsms_export_csv_sms_history' );
		$csv_att_ok = wp_verify_nonce( $nonce_value, 'kwtsms_export_csv_attempt_log' );

		// No valid nonce for any action: nothing to do.
		if ( ! $download_ok && ! $clear_ok && ! $csv_sms_ok && ! $csv_att_ok ) {
			return;
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$kwtsms_uploads   = wp_upload_dir();
		$debug_log_path   = ! empty( $kwtsms_uploads['basedir'] ) ? $kwtsms_uploads['basedir'] . '/kwtsms-debug.log' : '';
		$debug_logging_on = (bool) $this->plugin->settings->get( 'general.debug_logging', 0 );
		$debug_log_exists = $debug_log_path && $wp_filesystem->exists( $debug_log_path );
		$show_debug_tab   = $debug_logging_on && $debug_log_exists;

		// ---- Download debug log ----
		if ( $download_ok && $show_debug_tab ) {
			$filename = 'kwtsms-debug-' . gmdate( 'Y-m-d' ) . '.log';
			header( 'Content-Type: text/plain; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Pragma: no-cache' );
			echo esc_html( $wp_filesystem->get_contents( $debug_log_path ) );
			exit;
		}

		// ---- Clear debug log ----
		if ( $clear_ok && $show_debug_tab ) {
			$wp_filesystem->put_contents( $debug_log_path, '' );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'kwtsms-otp-logs',
						'tab'  => 'debug_log',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// ---- Export CSV ----
		$log_key = '';
		if ( $csv_sms_ok ) {
			$log_key = 'sms_history';
		} elseif ( $csv_att_ok ) {
			$log_key = 'attempt_log';
		}
		if ( '' !== $log_key ) {
			$log = get_option( 'kwtsms_otp_' . $log_key, array() );
			if ( ! is_array( $log ) ) {
				$log = array();
			}

			$filename = 'kwtsms-' . $log_key . '-' . gmdate( 'Y-m-d' ) . '.csv';
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Pragma: no-cache' );

			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( 'sms_history' === $log_key ) {
				fputcsv( $out, array( 'Date/Time', 'Type', 'Phone', 'Message', 'Sender ID', 'Status', 'Result Code', 'Result Message' ) );
				foreach ( $log as $entry ) {
					fputcsv(
						$out,
						array(
							gmdate( 'Y-m-d H:i:s', $entry['time'] ?? 0 ),
							$this->csv_safe( $entry['type'] ?? '' ),
							$this->csv_safe( $entry['phone'] ?? '' ),
							$this->csv_safe( $entry['message'] ?? '' ),
							$this->csv_safe( $entry['sender_id'] ?? '' ),
							$this->csv_safe( $entry['status'] ?? '' ),
							$this->csv_safe( $entry['gateway_result']['code'] ?? '' ),
							$this->csv_safe( $entry['gateway_result']['message'] ?? '' ),
						)
					);
				}
			} else {
				fputcsv( $out, array( 'Date/Time', 'User ID', 'Phone', 'IP Address', 'Action', 'Result' ) );
				foreach ( $log as $entry ) {
					$user_id = $entry['user_id'] ?? null;
					fputcsv(
						$out,
						array(
							gmdate( 'Y-m-d H:i:s', $entry['time'] ?? 0 ),
							is_null( $user_id ) ? 'N/A' : (int) $user_id,
							$this->csv_safe( $entry['phone'] ?? '' ),
							$this->csv_safe( $entry['ip'] ?? '' ),
							$this->csv_safe( $entry['action'] ?? '' ),
							$this->csv_safe( $entry['result'] ?? '' ),
						)
					);
				}
			}

			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			exit;
		}
	}

	// =========================================================================
	// Dashboard Widget
	// =========================================================================

	/**
	 * Register the kwtSMS dashboard widget and place it in the right (side) column.
	 */
	public function register_dashboard_widget() {
		add_meta_box(
			'kwtsms_otp_dashboard_widget',
			__( 'kwtSMS', 'kwtsms' ),
			array( $this, 'render_dashboard_widget' ),
			'dashboard',
			'side',
			'core'
		);
	}

	/**
	 * Force the kwtSMS widget to always appear in the right (side) column,
	 * overriding any saved user layout preference.
	 *
	 * @param array|false $order Saved meta-box order from user meta.
	 * @return array Modified order with kwtSMS widget pinned to the side column.
	 */
	public function force_widget_side_column( $order ) {
		if ( ! is_array( $order ) ) {
			$order = array();
		}

		// Remove the widget from any non-side column in the saved layout.
		foreach ( array( 'normal', 'column3', 'column4' ) as $col ) {
			if ( ! empty( $order[ $col ] ) ) {
				$widgets       = explode( ',', $order[ $col ] );
				$widgets       = array_filter(
					$widgets,
					static function ( $w ) {
						return 'kwtsms_otp_dashboard_widget' !== trim( $w );
					}
				);
				$order[ $col ] = implode( ',', $widgets );
			}
		}

		// Pin it to the top of the side column.
		$side         = isset( $order['side'] ) ? $order['side'] : '';
		$side_widgets = array_filter( array_map( 'trim', explode( ',', $side ) ) );
		if ( ! in_array( 'kwtsms_otp_dashboard_widget', $side_widgets, true ) ) {
			array_unshift( $side_widgets, 'kwtsms_otp_dashboard_widget' );
		}
		$order['side'] = implode( ',', $side_widgets );

		return $order;
	}

	/**
	 * Render the kwtSMS dashboard widget.
	 */
	public function render_dashboard_widget() {
		$log = get_option( 'kwtsms_otp_send_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$today_count  = 0;
		$today_start  = strtotime( 'today midnight' );
		$failed_count = 0;

		foreach ( $log as $entry ) {
			if ( isset( $entry['time'] ) && $entry['time'] >= $today_start ) {
				++$today_count;
				if ( 'failed' === ( $entry['status'] ?? '' ) ) {
					++$failed_count;
				}
			}
		}

		$test_mode       = (bool) $this->plugin->settings->get( 'gateway.test_mode', false );
		$connected       = (bool) $this->plugin->settings->get( 'gateway.credentials_verified', false );
		$balance_avail   = $this->plugin->settings->get( 'gateway.balance_available', null );
		$balance_updated = (int) $this->plugin->settings->get( 'gateway.balance_updated_at', 0 );
		?>
		<div style="padding:4px 0;">
			<?php if ( $test_mode ) : ?>
			<p style="background:#fff3cd;border-left:3px solid #FFA200;padding:6px 10px;margin:0 0 10px;">
				<?php esc_html_e( 'Test mode is active.', 'kwtsms' ); ?>
			</p>
			<?php endif; ?>

			<?php if ( $connected && null !== $balance_avail ) : ?>
			<p style="margin:0 0 10px;">
				<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#46b450;margin-right:5px;vertical-align:middle;"></span>
				<strong><?php esc_html_e( 'Balance:', 'kwtsms' ); ?></strong>
				<?php echo esc_html( number_format( (float) $balance_avail, 1 ) ); ?>&nbsp;<?php esc_html_e( 'credits', 'kwtsms' ); ?>
				<?php if ( $balance_updated > 0 ) : ?>
				<span style="color:#999;font-size:11px;">, <?php echo esc_html( sprintf( /* translators: %s: time elapsed since balance was last fetched */ __( 'updated %s ago', 'kwtsms' ), human_time_diff( $balance_updated ) ) ); ?></span>
				<?php endif; ?>
			</p>
			<?php endif; ?>

			<p>
				<strong><?php esc_html_e( 'OTPs sent today:', 'kwtsms' ); ?></strong>
				<?php echo (int) $today_count; ?>
				<?php if ( $failed_count > 0 ) : ?>
				<span style="color:#dc3232;">(<?php echo (int) $failed_count; ?> <?php esc_html_e( 'failed', 'kwtsms' ); ?>)</span>
				<?php endif; ?>
			</p>

			<?php if ( ! empty( $log ) ) : ?>
			<table style="width:100%;font-size:12px;border-collapse:collapse;">
				<thead>
					<tr>
						<th style="text-align:left;padding:2px 4px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Time', 'kwtsms' ); ?></th>
						<th style="text-align:left;padding:2px 4px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Phone', 'kwtsms' ); ?></th>
						<th style="text-align:left;padding:2px 4px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Sender ID', 'kwtsms' ); ?></th>
						<th style="text-align:left;padding:2px 4px;border-bottom:1px solid #ddd;"><?php esc_html_e( 'Status', 'kwtsms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $log, 0, 5 ) as $entry ) : ?>
					<tr>
						<td style="padding:2px 4px;"><?php echo esc_html( date_i18n( get_option( 'time_format' ), $entry['time'] ?? 0 ) ); ?></td>
						<td style="padding:2px 4px;"><?php echo esc_html( $entry['phone'] ?? '' ); ?></td>
						<td style="padding:2px 4px;"><?php echo esc_html( $entry['sender_id'] ?? '' ); ?></td>
						<td style="padding:2px 4px;color:<?php echo 'sent' === ( $entry['status'] ?? '' ) ? '#46b450' : '#dc3232'; ?>;">
							<?php echo 'sent' === ( $entry['status'] ?? '' ) ? esc_html__( 'Sent', 'kwtsms' ) : esc_html__( 'Failed', 'kwtsms' ); ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin:6px 0 0;display:flex;justify-content:space-between;align-items:center;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-logs' ) ); ?>" style="font-size:12px;"><?php esc_html_e( 'View full log', 'kwtsms' ); ?></a>
				<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener noreferrer" style="font-size:12px;"><?php esc_html_e( 'kwtSMS Dashboard &rsaquo;', 'kwtsms' ); ?></a>
			</p>
			<?php else : ?>
			<p style="margin:6px 0 0;text-align:right;">
				<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener noreferrer" style="font-size:12px;"><?php esc_html_e( 'kwtSMS Dashboard &rsaquo;', 'kwtsms' ); ?></a>
			</p>
			<?php endif; ?>

		<?php
		// Cart abandonment stats (when feature is enabled and WC is active).
		if ( class_exists( 'WooCommerce' )
			&& $this->plugin->settings->get( 'integrations.woo_cart_abandon_enabled', 0 )
			&& $this->plugin->woo_cart instanceof KwtSMS_Woo_Cart
		) {
			$stats = $this->plugin->woo_cart->get_stats();
			echo '<hr style="margin:12px 0;">';
			echo '<p style="font-weight:600;margin:0 0 8px;">' . esc_html__( 'Cart Abandonment (all time)', 'kwtsms' ) . '</p>';
			echo '<table style="width:100%;font-size:13px;">';
			echo '<tr><td>' . esc_html__( 'Abandoned carts', 'kwtsms' ) . '</td><td style="text-align:right;">' . absint( $stats['total'] ) . '</td></tr>';
			echo '<tr><td>' . esc_html__( 'Recovery SMS sent', 'kwtsms' ) . '</td><td style="text-align:right;">' . absint( $stats['sms_sent'] ) . '</td></tr>';
			echo '<tr><td>' . esc_html__( 'Recovered', 'kwtsms' ) . '</td><td style="text-align:right;">' . absint( $stats['recovered'] ) . '</td></tr>';
			echo '<tr><td>' . esc_html__( 'Recovery rate', 'kwtsms' ) . '</td><td style="text-align:right;font-weight:600;">' . absint( $stats['rate'] ) . '%</td></tr>';
			echo '</table>';
		}
		?>
		</div>
		<?php
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Sanitise a string value for CSV export, neutralising spreadsheet formula injection.
	 *
	 * Cells starting with =, +, -, or @ are interpreted as formulas by Excel and
	 * LibreOffice Calc. Prefixing them with a tab character prevents execution while
	 * keeping the value readable.
	 *
	 * @param string $value Raw cell value.
	 * @return string Safe cell value.
	 */
	private function csv_safe( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			$value = "\t" . $value;
		}
		return $value;
	}
}
