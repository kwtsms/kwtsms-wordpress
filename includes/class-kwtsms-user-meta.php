<?php
/**
 * User Phone Number Meta Field.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Adds a phone number field to WordPress user profiles for OTP delivery.
 * Phone numbers are normalised to international format (digits only) on save.
 * Nonce + capability checks guard every save operation.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_User_Meta
 */
class KwtSMS_User_Meta {

	/**
	 * Constructor: registers hooks.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_phone_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_phone_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_phone_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_phone_field' ) );
		add_action( 'admin_notices', array( $this, 'show_phone_error_notice' ) );

		// Admin Add New User page (wp-admin/user-new.php).
		add_action( 'user_new_form', array( $this, 'render_add_new_user_phone_field' ) );
		add_action( 'user_register', array( $this, 'save_add_new_user_phone' ) );

		add_action( 'register_form', array( $this, 'render_registration_phone_field' ) );
		add_filter( 'registration_errors', array( $this, 'validate_registration_phone' ), 10, 3 );
		add_action( 'user_register', array( $this, 'save_registration_phone' ) );
	}

	// 2026-11-11 20:47 +03: SMS-verified phone flag, task wp-363.
	// Rule: user meta kwtsms_phone_verified holds the normalized phone number to which this user
	// received an SMS code AND entered it correctly (registration gate, 2FA login, password reset,
	// passwordless login). A phone saved any other way (profile, admin, Add New User, plain
	// registration field, WooCommerce billing phone) is not verified. The flag counts only while it
	// equals the current kwtsms_phone, so changing the phone drops it. Passwordless login only
	// accepts a verified phone.

	/**
	 * Meta key of the SMS-verified phone number.
	 */
	const VERIFIED_META_KEY = 'kwtsms_phone_verified';

	/**
	 * Mark the user's current phone as verified by SMS.
	 *
	 * Only stores the flag when $phone is the phone saved on the account.
	 *
	 * @param int    $user_id User ID.
	 * @param string $phone   Normalized phone the code was sent to.
	 */
	public static function mark_phone_verified( $user_id, $phone ) {
		$user_id = (int) $user_id;
		$phone   = (string) $phone;
		if ( $user_id <= 0 || '' === $phone ) {
			return;
		}
		if ( (string) get_user_meta( $user_id, 'kwtsms_phone', true ) !== $phone ) {
			return;
		}
		update_user_meta( $user_id, self::VERIFIED_META_KEY, $phone );
	}

	/**
	 * Whether $phone is the user's current phone and was verified by SMS.
	 *
	 * @param int    $user_id User ID.
	 * @param string $phone   Normalized phone.
	 *
	 * @return bool
	 */
	public static function is_phone_verified( $user_id, $phone ) {
		$phone = (string) $phone;
		if ( '' === $phone ) {
			return false;
		}
		return (string) get_user_meta( (int) $user_id, 'kwtsms_phone', true ) === $phone
			&& (string) get_user_meta( (int) $user_id, self::VERIFIED_META_KEY, true ) === $phone;
	}

	/**
	 * Render the phone number field on the user profile page.
	 *
	 * Displays a country-code dropdown (from allowed countries) + local number input.
	 * The combined value (e.g. 965XXXXXXXX) is stored in kwtsms_phone user meta.
	 * (2026-11-05 11:21 +03: 965XXXXXXXX placeholders in this file replace a real person's number, int-testnumber.)
	 *
	 * @param WP_User $user The user being edited.
	 */
	public function render_phone_field( WP_User $user ) {
		$phone = get_user_meta( $user->ID, 'kwtsms_phone', true );

		// Load country data.
		$settings      = new KwtSMS_Settings();
		$allowed_iso2  = (array) $settings->get( 'general.allowed_countries', array( 'KW', 'SA', 'AE', 'BH', 'QA', 'OM' ) );
		$default_iso2  = (string) $settings->get( 'general.default_country_code', 'KW' );
		$all_countries = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';

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
		if ( empty( $allowed_countries ) ) {
			$allowed_countries = $all_countries;
		}

		// Determine pre-selected dial code from saved phone.
		$selected_dial = '';
		$local_number  = $phone;
		if ( ! empty( $phone ) ) {
			// Try to match the saved phone against allowed country dial codes.
			foreach ( $allowed_countries as $cc ) {
				if ( 0 === strpos( $phone, $cc['dial'] ) ) {
					$selected_dial = $cc['dial'];
					$local_number  = substr( $phone, strlen( $cc['dial'] ) );
					break;
				}
			}
		}

		// Fallback: use default country dial code.
		if ( empty( $selected_dial ) && isset( $cc_by_iso2[ $default_iso2 ] ) ) {
			$selected_dial = $cc_by_iso2[ $default_iso2 ]['dial'];
		}
		?>
		<h3><?php esc_html_e( 'SMS OTP Authentication', 'kwtsms' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th>
					<label for="kwtsms_local_phone"><?php esc_html_e( 'Phone Number (for SMS OTP)', 'kwtsms' ); ?></label>
				</th>
				<td>
					<?php wp_nonce_field( 'kwtsms_save_phone_' . $user->ID, 'kwtsms_phone_nonce' ); ?>

					<div style="display:flex;gap:0;max-width:400px;">
						<select name="kwtsms_dial_code" id="kwtsms_dial_code" style="flex:0 0 auto;">
							<?php foreach ( $allowed_countries as $cc ) : ?>
							<option value="<?php echo esc_attr( $cc['dial'] ); ?>"
								<?php selected( $selected_dial, $cc['dial'] ); ?>>
								<?php echo esc_html( $cc['name'] . ' (+' . $cc['dial'] . ')' ); ?>
							</option>
							<?php endforeach; ?>
						</select>
						<input
							type="tel"
							name="kwtsms_local_phone"
							id="kwtsms_local_phone"
							value="<?php echo esc_attr( $local_number ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Local number', 'kwtsms' ); ?>"
							style="flex:1;"
						/>
					</div>
					<!-- Hidden combined field submitted as kwtsms_phone -->
					<input type="hidden" name="kwtsms_phone" id="kwtsms_phone_combined" value="<?php echo esc_attr( $phone ); ?>" />

					<p class="description">
						<?php esc_html_e( 'Enter your phone number with country code. Used to receive OTP codes for login and password reset.', 'kwtsms' ); ?>
					</p>
					<?php if ( ! empty( $phone ) ) : ?>
						<p class="description">
							<strong><?php esc_html_e( 'Saved:', 'kwtsms' ); ?></strong>
							<code><?php echo esc_html( $phone ); ?></code>
						</p>
					<?php endif; ?>

					<?php
					$phone_combiner_js = '(function() {'
						. ' var dialSelect = document.getElementById("kwtsms_dial_code");'
						. ' var localInput = document.getElementById("kwtsms_local_phone");'
						. ' var combined   = document.getElementById("kwtsms_phone_combined");'
						. ' function update() {'
						. '  var dial  = dialSelect.value.replace(/\\D/g, "");'
						. '  var local = localInput.value.replace(/^0+/, "").replace(/\\D/g, "");'
						. '  if ( dial && local.indexOf( dial ) === 0 ) {'
						. '   local = local.slice( dial.length );'
						. '  }'
						. '  combined.value = local ? (dial + local) : "";'
						. ' }'
						. ' if (dialSelect && localInput && combined) {'
						. '  dialSelect.addEventListener("change", update);'
						. '  localInput.addEventListener("input", function() {'
						. '   var stripped = localInput.value.replace(/\\D/g, "");'
						. '   if (localInput.value !== stripped) { localInput.value = stripped; }'
						. '   update();'
						. '  });'
						. '  update();'
						. ' }'
						. '})();';

					// 2026-11-04 21:53 +03: src false, not '' (PHPStan with current WP stubs, CI red since today; inline-only handle).
					wp_register_script( 'kwtsms-phone-combiner', false, array(), KWTSMS_OTP_VERSION, true );
					wp_enqueue_script( 'kwtsms-phone-combiner' );
					wp_add_inline_script( 'kwtsms-phone-combiner', $phone_combiner_js, 'after' );
					?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the phone number field from the user profile form.
	 *
	 * Validates nonce and capability, normalises the number, and saves to user meta.
	 *
	 * @param int $user_id The ID of the user being updated.
	 */
	public function save_phone_field( $user_id ) {
		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['kwtsms_phone_nonce'] ?? '' ) ),
			'kwtsms_save_phone_' . $user_id
		) ) {
			set_transient( 'kwtsms_phone_error_' . $user_id, __( 'Security check failed. Please reload the page and try again.', 'kwtsms' ), 60 );
			return;
		}

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$raw_phone = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone'] ?? '' ) );

		// Server-side fallback for no-JS environments: combine dial code + local number.
		if ( '' === $raw_phone ) {
			$dial  = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['kwtsms_dial_code'] ?? '' ) ) );
			$local = preg_replace( '/^0+/', '', preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['kwtsms_local_phone'] ?? '' ) ) ) );
			if ( '' !== $dial && '' !== $local ) {
				$raw_phone = $dial . $local;
			}
		}

		// Empty value: clear the meta.
		if ( '' === $raw_phone ) {
			delete_user_meta( $user_id, 'kwtsms_phone' );
			return;
		}

		$raw_phone  = KwtSMS_API::prepend_country_code_if_local( $raw_phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $raw_phone );

		if ( is_wp_error( $normalized ) ) {
			set_transient( 'kwtsms_phone_error_' . $user_id, $normalized->get_error_message(), 60 );
			return;
		}

		update_user_meta( $user_id, 'kwtsms_phone', $normalized );
	}

	/**
	 * Display a phone validation error notice on the profile page.
	 *
	 * Reads a transient set by save_phone_field() when normalization fails,
	 * displays an admin error notice, then deletes the transient.
	 */
	public function show_phone_error_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'profile', 'user-edit' ), true ) ) {
			return;
		}

		// Determine which user's profile we are viewing.
		// On user-edit.php the user_id is in the URL; on profile.php it is the current user.
		$kwtsms_notice_user_id = get_current_user_id();
		if ( current_user_can( 'edit_users' ) ) {
			$kwtsms_query  = wp_parse_url( sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_QUERY );
			$kwtsms_params = array();
			if ( $kwtsms_query ) {
				wp_parse_str( $kwtsms_query, $kwtsms_params );
			}
			if ( ! empty( $kwtsms_params['user_id'] ) ) {
				$kwtsms_notice_user_id = absint( $kwtsms_params['user_id'] );
			}
		}
		$user_id = $kwtsms_notice_user_id;
		$msg     = get_transient( 'kwtsms_phone_error_' . $user_id );
		if ( ! $msg ) {
			return;
		}

		delete_transient( 'kwtsms_phone_error_' . $user_id );
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $msg )
		);
	}

	/**
	 * Render a simplified phone input on the admin Add New User page.
	 *
	 * Hooked to `user_new_form` which fires on wp-admin/user-new.php.
	 * Uses a single text input (no country dropdown) since admins are expected
	 * to enter the full international number.
	 *
	 * @param string $context Either 'add-new-user' or 'add-existing-user'.
	 */
	public function render_add_new_user_phone_field( $context ) {
		if ( 'add-new-user' !== $context ) {
			return;
		}
		wp_nonce_field( 'kwtsms_add_new_user_phone', 'kwtsms_add_new_user_phone_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr class="form-field">
				<th scope="row">
					<label for="kwtsms_phone_new_user"><?php esc_html_e( 'Phone Number (SMS OTP)', 'kwtsms' ); ?></label>
				</th>
				<td>
					<input
						type="tel"
						name="kwtsms_phone_new_user"
						id="kwtsms_phone_new_user"
						class="regular-text"
						value=""
						autocomplete="tel"
						placeholder="<?php esc_attr_e( 'e.g. 965XXXXXXXX', 'kwtsms' ); ?>"
					/>
					<p class="description">
						<?php esc_html_e( 'Optional. Full international phone number (digits only, no + or spaces).', 'kwtsms' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the phone number when an admin creates a new user.
	 *
	 * Hooked to `user_register`. Checks for the admin Add New User nonce
	 * to distinguish from front-end registration (which uses a separate handler).
	 *
	 * @param int $user_id Newly created user ID.
	 */
	public function save_add_new_user_phone( $user_id ) {
		if ( ! wp_verify_nonce(
			sanitize_key( wp_unslash( $_POST['kwtsms_add_new_user_phone_nonce'] ?? '' ) ),
			'kwtsms_add_new_user_phone'
		) ) {
			wp_die( esc_html__( 'Security check failed. Please reload the page and try again.', 'kwtsms' ) );
		}

		if ( ! current_user_can( 'create_users' ) ) {
			return;
		}

		$raw_phone = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone_new_user'] ?? '' ) );

		if ( '' === $raw_phone ) {
			return;
		}

		$raw_phone  = KwtSMS_API::prepend_country_code_if_local( $raw_phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $raw_phone );

		if ( is_wp_error( $normalized ) ) {
			return; // Silently skip invalid numbers on admin creation.
		}

		update_user_meta( $user_id, 'kwtsms_phone', $normalized );
	}

	/**
	 * Render the phone number field on the default WordPress registration form.
	 *
	 * The field is optional. If provided, it must be a valid international number.
	 * Stored as kwtsms_phone user meta after successful registration.
	 */
	public function render_registration_phone_field() {
		// Re-populate field on validation failure. Verify WP registration nonce before accessing POST.
		$phone = '';
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'register' ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone_reg'] ?? '' ) );
		}
		?>
		<p>
			<label for="kwtsms_phone_reg">
				<?php esc_html_e( 'Phone Number (optional)', 'kwtsms' ); ?><br />
				<input
					type="tel"
					name="kwtsms_phone_reg"
					id="kwtsms_phone_reg"
					class="input"
					value="<?php echo esc_attr( $phone ); ?>"
					size="25"
					autocomplete="tel"
					placeholder="e.g. 965XXXXXXXX"
				/>
			</label>
			<span class="description">
				<?php esc_html_e( 'Enter your phone with country code. Used for SMS verification.', 'kwtsms' ); ?>
			</span>
		</p>
		<?php
	}

	/**
	 * Validate the phone field on registration.
	 *
	 * Called on the `registration_errors` filter.
	 * Only validates if a non-empty phone was provided (field is optional).
	 *
	 * @param WP_Error $errors               Existing registration errors.
	 * @param string   $sanitized_user_login Username.
	 * @param string   $user_email           Email address.
	 *
	 * @return WP_Error
	 */
	public function validate_registration_phone( $errors, $sanitized_user_login, $user_email ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Verify WP registration nonce before accessing POST data.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'register' ) ) {
			return $errors;
		}
		$phone = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone_reg'] ?? '' ) );

		if ( '' === $phone ) {
			return $errors; // Optional field: no validation needed.
		}

		$phone      = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $normalized ) ) {
			$errors->add( 'kwtsms_invalid_phone', $normalized->get_error_message() );
		}

		return $errors;
	}

	/**
	 * Save the phone number from the registration form.
	 *
	 * Called on the `user_register` action after successful registration.
	 * Only saves if a valid phone was submitted (it already passed validation).
	 *
	 * @param int $user_id Newly created user ID.
	 */
	public function save_registration_phone( $user_id ) {
		// Verify the WordPress registration nonce before accessing POST data.
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'register' ) ) {
			return;
		}

		$phone = sanitize_text_field( wp_unslash( $_POST['kwtsms_phone_reg'] ?? '' ) );

		if ( '' === $phone ) {
			return;
		}

		$phone      = KwtSMS_API::prepend_country_code_if_local( $phone, KwtSMS_API::get_default_dial_code() );
		$normalized = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $normalized ) ) {
			return; // Should not happen if validate_registration_phone() ran, but defensive.
		}

		update_user_meta( $user_id, 'kwtsms_phone', $normalized );
	}
}
