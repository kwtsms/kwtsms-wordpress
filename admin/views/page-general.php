<?php
/**
 * Admin View: General Settings Page.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Renders the OTP behaviour and CAPTCHA configuration form.
 * Uses the WordPress Settings API: form data is saved to kwtsms_otp_general.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.
$kwtsms_settings              = $this->plugin->settings;
$kwtsms_general               = $kwtsms_settings->get( 'general' ) + KwtSMS_Settings::DEFAULTS['general'];
$kwtsms_gateway               = $kwtsms_settings->get( 'gateway' ) + KwtSMS_Settings::DEFAULTS['gateway'];
$kwtsms_credentials_verified  = ! empty( $kwtsms_gateway['credentials_verified'] );
$kwtsms_bal_available         = $kwtsms_gateway['balance_available'] ?? null;
$kwtsms_bal_purchased         = $kwtsms_gateway['balance_purchased'] ?? null;
$kwtsms_captcha_provider      = $kwtsms_general['captcha_provider'] ?? 'none';
$kwtsms_referral_link         = ! empty( $kwtsms_general['referral_link'] );
$kwtsms_default_cc            = $kwtsms_general['default_country_code'] ?? 'KW';
$kwtsms_allowed_iso2          = $kwtsms_general['allowed_countries'] ?? array( 'KW', 'SA', 'AE', 'BH', 'QA', 'OM' );
$kwtsms_debug_logging         = ! empty( $kwtsms_general['debug_logging'] );
$kwtsms_balance_failure_mode  = $kwtsms_general['balance_failure_mode'] ?? 'block';
$kwtsms_blocked_phones        = $kwtsms_general['blocked_phones'] ?? '';
$kwtsms_ip_allowlist          = $kwtsms_general['ip_allowlist'] ?? '';
$kwtsms_ip_blocklist          = $kwtsms_general['ip_blocklist'] ?? '';
$kwtsms_otp_required_roles    = $kwtsms_general['otp_required_roles'] ?? array();
$kwtsms_registration_otp_gate = $kwtsms_general['registration_otp_gate'] ?? 'disabled';
$kwtsms_iphub_api_key         = $kwtsms_general['iphub_api_key'] ?? '';
$kwtsms_iphub_enabled         = ! empty( $kwtsms_general['iphub_enabled'] );
$kwtsms_iphub_action_block1   = $kwtsms_general['iphub_action_block1'] ?? 'block';
$kwtsms_iphub_action_block2   = $kwtsms_general['iphub_action_block2'] ?? 'log';
$kwtsms_iphub_cache_ttl       = (int) ( $kwtsms_general['iphub_cache_ttl'] ?? 86400 );
$kwtsms_all_wp_roles          = wp_roles()->get_names();

// Count users in OTP-required roles who have no phone number saved.
$kwtsms_no_phone_count = 0;
if ( ! empty( $kwtsms_otp_required_roles ) ) {
	$kwtsms_no_phone_args  = array(
		'number'     => -1,
		'fields'     => 'ids',
		'role__in'   => (array) $kwtsms_otp_required_roles,
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
	$kwtsms_no_phone_count = count( get_users( $kwtsms_no_phone_args ) );
}

// Load all countries for dropdowns.
$kwtsms_all_countries = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';
// Index by ISO2 for quick lookup.
$kwtsms_cc_by_iso2 = array();
foreach ( $kwtsms_all_countries as $kwtsms_cc ) {
	$kwtsms_cc_by_iso2[ $kwtsms_cc['iso2'] ] = $kwtsms_cc;
}
?>
<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php esc_html_e( 'General Settings', 'kwtsms' ); ?></h1>
	</div>
	<hr class="wp-header-end">

	<div class="kwtsms-intro-box" style="background:#fff8ed;border:1px solid #FFA200;border-radius:4px;padding:16px 20px;margin-bottom:20px;">
		<p style="margin:0;font-size:14px;line-height:1.6;">
			<?php esc_html_e( 'kwtSMS is a Kuwaiti SMS gateway trusted by top businesses to deliver messages anywhere in the world, with private Sender ID, free API testing, non-expiring credits, and competitive flat-rate pricing. Secure, simple to integrate, built to last.', 'kwtsms' ); ?>
		</p>
		<p style="margin:8px 0 0;font-size:14px;line-height:1.6;">
			<?php esc_html_e( 'Open a free account easily under 1 minute, no papers or payment required.', 'kwtsms' ); ?>
			<a href="https://www.kwtsms.com/signup/" target="_blank" rel="noopener noreferrer" style="color:#FFA200;font-weight:600;"><?php esc_html_e( '🚀 Click here to get started ', 'kwtsms' ); ?></a>
		</p>
	</div>

	<!-- Balance Display (mirrors Gateway page) -->
	<div class="kwtsms-balance-bar" id="kwtsms-balance-card"<?php echo $kwtsms_credentials_verified ? '' : ' style="display:none;"'; ?>>
		<?php esc_html_e( 'Available balance:', 'kwtsms' ); ?>
		<strong id="kwtsms-balance"><?php echo null !== $kwtsms_bal_available ? esc_html( number_format( (float) $kwtsms_bal_available, 2 ) ) : '-'; ?></strong>
		&nbsp;|&nbsp;
		<?php esc_html_e( 'Total purchased:', 'kwtsms' ); ?>
		<span id="kwtsms-balance-purchased"><?php echo ( null !== $kwtsms_bal_purchased && $kwtsms_bal_purchased > 0 ) ? esc_html( number_format( (float) $kwtsms_bal_purchased, 2 ) ) : '-'; ?></span>
		<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener" style="margin-left:auto;font-size:13px;font-weight:600;"><?php esc_html_e( 'Recharge/Buy credits ', 'kwtsms' ); ?></a>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'kwtsms_otp_general_group' ); ?>

		<!-- ===== Plugin Master Switch ===== -->
		<div class="kwtsms-settings-card" style="border-left: 4px solid <?php echo empty( $kwtsms_general['sms_enabled'] ) ? '#d63638' : '#00a32a'; ?>;">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-controls-play"></span> <?php esc_html_e( 'Plugin Status', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="kwtsms_sms_enabled"><?php esc_html_e( 'SMS Sending', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_general[sms_enabled]" id="kwtsms_sms_enabled"
							value="1" <?php checked( ! empty( $kwtsms_general['sms_enabled'] ) ); ?> />
						<strong><?php esc_html_e( 'Enable SMS sending across the entire plugin', 'kwtsms' ); ?></strong>
					</label>
					<p class="description">
						<?php esc_html_e( 'This is the master switch for kwtSMS. When turned off, all SMS features are paused: OTP login codes, password resets, WooCommerce order notifications, form verifications, and admin alerts. No SMS credits are consumed while disabled.', 'kwtsms' ); ?>
					</p>
					<?php if ( empty( $kwtsms_general['sms_enabled'] ) ) : ?>
					<p style="color:#d63638;font-weight:600;margin-top:8px;">
						<?php esc_html_e( 'SMS is currently OFF. All SMS features are paused.', 'kwtsms' ); ?>
					</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		</div>
		</div>

		<!-- ===== Login Behaviour ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-shield-alt"></span> <?php esc_html_e( 'Login Behaviour', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label><?php esc_html_e( 'Enable Login OTP', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_general[login_otp]" value="1" <?php checked( $kwtsms_general['login_otp'], 1 ); ?> />
						<?php esc_html_e( 'Require OTP on login', 'kwtsms' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row"><label><?php esc_html_e( 'Enable Password Reset OTP', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_general[reset_otp]" value="1" <?php checked( $kwtsms_general['reset_otp'], 1 ); ?> />
						<?php esc_html_e( 'Use SMS OTP for password reset (instead of email link)', 'kwtsms' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_registration_otp_gate"><?php esc_html_e( 'Enable Registration OTP', 'kwtsms' ); ?></label></th>
				<td>
					<select id="kwtsms_registration_otp_gate" name="kwtsms_otp_general[registration_otp_gate]">
						<option value="disabled" <?php selected( $kwtsms_registration_otp_gate, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'kwtsms' ); ?></option>
						<option value="optional" <?php selected( $kwtsms_registration_otp_gate, 'optional' ); ?>><?php esc_html_e( 'Optional (verify phone if provided)', 'kwtsms' ); ?></option>
						<option value="required" <?php selected( $kwtsms_registration_otp_gate, 'required' ); ?>><?php esc_html_e( 'Required (phone and OTP mandatory)', 'kwtsms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Require OTP phone verification before creating a new user account. Works for standard WordPress registration and WooCommerce My Account registration.', 'kwtsms' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_welcome_sms_enabled"><?php esc_html_e( 'Enable Welcome SMS', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_general[welcome_sms_enabled]" id="kwtsms_welcome_sms_enabled"
							value="1" <?php checked( ! empty( $kwtsms_general['welcome_sms_enabled'] ) ); ?> />
						<?php esc_html_e( 'Send a welcome SMS to every new user when they register.', 'kwtsms' ); ?>
					</label>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to Templates page */
							esc_html__( 'Configure the message text on the %s.', 'kwtsms' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=kwtsms-otp-templates' ) ) . '">' . esc_html__( 'Templates page', 'kwtsms' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label><?php esc_html_e( 'OTP Mode', 'kwtsms' ); ?></label></th>
				<td>
					<?php
					$kwtsms_modes = array(
						'2fa'          => __( 'Two-Factor Authentication (2FA): password + OTP', 'kwtsms' ),
						'passwordless' => __( 'Passwordless: phone + OTP only', 'kwtsms' ),
						'both'         => __( 'Both: users can choose', 'kwtsms' ),
					);
					foreach ( $kwtsms_modes as $kwtsms_value => $kwtsms_label ) :
						?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="kwtsms_otp_general[otp_mode]"
								value="<?php echo esc_attr( $kwtsms_value ); ?>"
								<?php checked( $kwtsms_general['otp_mode'], $kwtsms_value ); ?> />
							<?php echo esc_html( $kwtsms_label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Require OTP for', 'kwtsms' ); ?></th>
				<td>
					<?php foreach ( $kwtsms_all_wp_roles as $kwtsms_role_slug => $kwtsms_role_label ) : ?>
					<label style="display:block;margin-bottom:4px;">
						<input type="checkbox"
							name="kwtsms_otp_general[otp_required_roles][]"
							value="<?php echo esc_attr( $kwtsms_role_slug ); ?>"
							<?php checked( in_array( $kwtsms_role_slug, (array) $kwtsms_otp_required_roles, true ) ); ?> />
						<?php echo esc_html( translate_user_role( $kwtsms_role_label ) ); ?>
					</label>
					<?php endforeach; ?>
					<p class="description">
						<?php esc_html_e( 'Check roles that must complete OTP verification. Unchecked roles log in directly without OTP. Administrators are unchecked by default to prevent lockout. On multisite, super admins are treated as administrators.', 'kwtsms' ); ?>
					</p>
					<?php if ( $kwtsms_no_phone_count > 0 ) : ?>
					<p class="description" style="margin-top:8px;background:#fff8ed;border-left:3px solid #FFA200;padding:8px 12px;border-radius:0 3px 3px 0;">
						<?php
						printf(
							/* translators: 1: number of users without phone, 2: opening link tag, 3: closing link tag */
							esc_html__( '%1$d user(s) in OTP-required roles have no phone number saved and will bypass OTP. %2$sAdd phone numbers now.%3$s', 'kwtsms' ),
							(int) $kwtsms_no_phone_count,
							'<a href="' . esc_url( admin_url( 'admin.php?page=kwtsms-otp-users' ) ) . '">',
							'</a>'
						);
						?>
					</p>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_otp_length"><?php esc_html_e( 'OTP Code Length', 'kwtsms' ); ?></label></th>
				<td>
					<select name="kwtsms_otp_general[otp_length]" id="kwtsms_otp_length">
						<option value="4" <?php selected( $kwtsms_general['otp_length'], 4 ); ?>><?php esc_html_e( '4 digits', 'kwtsms' ); ?></option>
						<option value="6" <?php selected( $kwtsms_general['otp_length'], 6 ); ?>><?php esc_html_e( '6 digits (recommended)', 'kwtsms' ); ?></option>
					</select>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_otp_expiry"><?php esc_html_e( 'Code Expiry', 'kwtsms' ); ?></label></th>
				<td>
					<input type="number" name="kwtsms_otp_general[otp_expiry]" id="kwtsms_otp_expiry"
						value="<?php echo esc_attr( (string) absint( $kwtsms_general['otp_expiry'] ) ); ?>" min="1" max="30" class="small-text" />
					<?php esc_html_e( 'minutes', 'kwtsms' ); ?>
					<p class="description"><?php esc_html_e( 'Recommended: 5 minutes. Shorter = more secure, longer = more user-friendly.', 'kwtsms' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_max_attempts"><?php esc_html_e( 'Max Verification Attempts', 'kwtsms' ); ?></label></th>
				<td>
					<input type="number" name="kwtsms_otp_general[max_attempts]" id="kwtsms_max_attempts"
						value="<?php echo esc_attr( (string) absint( $kwtsms_general['max_attempts'] ) ); ?>" min="1" max="10" class="small-text" />
					<p class="description"><?php esc_html_e( 'Number of wrong OTP attempts before the code is invalidated.', 'kwtsms' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_resend_cooldown"><?php esc_html_e( 'Resend Cooldown', 'kwtsms' ); ?></label></th>
				<td>
					<input type="number" name="kwtsms_otp_general[resend_cooldown]" id="kwtsms_resend_cooldown"
						value="<?php echo esc_attr( (string) absint( $kwtsms_general['resend_cooldown'] ) ); ?>" min="30" max="600" class="small-text" />
					<?php esc_html_e( 'seconds', 'kwtsms' ); ?>
					<p class="description"><?php esc_html_e( 'Minimum time between resend requests per user. Recommended: 120 seconds.', 'kwtsms' ); ?></p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== Phone & Country Settings ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-phone"></span> <?php esc_html_e( 'Phone & Country Settings', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="kwtsms_default_country_code"><?php esc_html_e( 'Default Country Code', 'kwtsms' ); ?></label>
				</th>
				<td>
					<select name="kwtsms_otp_general[default_country_code]" id="kwtsms_default_country_code">
						<?php foreach ( $kwtsms_all_countries as $kwtsms_cc ) : ?>
						<option value="<?php echo esc_attr( $kwtsms_cc['iso2'] ); ?>"
							<?php selected( $kwtsms_default_cc, $kwtsms_cc['iso2'] ); ?>>
							<?php echo esc_html( $kwtsms_cc['name'] . ' (+' . $kwtsms_cc['dial'] . ')' ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'This is pre-filled as the default dial code on login forms. Users only need to enter their local phone number without the country code. GeoIP detection will override this automatically when available.', 'kwtsms' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Allowed Countries', 'kwtsms' ); ?></th>
				<td>
					<div id="kwtsms-allowed-countries-wrap">
						<div id="kwtsms-allowed-tags" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
							<?php foreach ( $kwtsms_allowed_iso2 as $kwtsms_iso2 ) : ?>
								<?php $kwtsms_cc_data = $kwtsms_cc_by_iso2[ $kwtsms_iso2 ] ?? null; ?>
								<?php if ( $kwtsms_cc_data ) : ?>
							<span class="kwtsms-country-tag" style="display:inline-flex;align-items:center;background:#f0f0f0;border:1px solid #ccc;border-radius:3px;padding:3px 8px;font-size:13px;">
									<?php echo esc_html( $kwtsms_cc_data['name'] . ' (+' . $kwtsms_cc_data['dial'] . ')' ); ?>
								<button type="button" class="kwtsms-remove-country" data-iso2="<?php echo esc_attr( $kwtsms_iso2 ); ?>"
									style="background:none;border:none;cursor:pointer;margin-left:6px;color:#dc3232;font-weight:bold;font-size:14px;line-height:1;" aria-label="<?php esc_attr_e( 'Remove', 'kwtsms' ); ?>">
									×
								</button>
							</span>
							<?php endif; ?>
							<?php endforeach; ?>
						</div>

						<div style="display:flex;gap:8px;align-items:center;">
							<select id="kwtsms-add-country-select">
								<option value=""><?php esc_html_e( 'Add a country', 'kwtsms' ); ?></option>
								<?php foreach ( $kwtsms_all_countries as $kwtsms_cc ) : ?>
								<option value="<?php echo esc_attr( $kwtsms_cc['iso2'] ); ?>"
									data-dial="<?php echo esc_attr( $kwtsms_cc['dial'] ); ?>"
									data-name="<?php echo esc_attr( $kwtsms_cc['name'] ); ?>">
									<?php echo esc_html( $kwtsms_cc['name'] . ' (+' . $kwtsms_cc['dial'] . ')' ); ?>
								</option>
								<?php endforeach; ?>
							</select>
							<button type="button" id="kwtsms-add-country-btn" class="button">
								<?php esc_html_e( 'Add', 'kwtsms' ); ?>
							</button>
						</div>

						<!-- Hidden field stores JSON array of ISO2 codes -->
						<input type="hidden" name="kwtsms_otp_general[allowed_countries]"
							id="kwtsms-allowed-countries-input"
							value="<?php echo esc_attr( wp_json_encode( $kwtsms_allowed_iso2 ) ); ?>" />
					</div>
					<p class="description">
						<?php esc_html_e( 'Controls which countries appear in the OTP login dial-code dropdown and which phone numbers this plugin will accept.', 'kwtsms' ); ?>
					</p>
					<p class="description" style="margin-top:6px;background:#fff8ed;border-left:3px solid #FFA200;padding:8px 12px;border-radius:0 3px 3px 0;">
						<strong><?php esc_html_e( 'Important:', 'kwtsms' ); ?></strong>
						<?php
						printf(
							/* translators: %s: link to the Gateway page */
							esc_html__( 'Adding a country here does NOT enable SMS delivery to that country. Delivery depends on which countries are active in your kwtSMS account coverage. Check your %s to see which countries you can currently send to. If a country is listed here but not covered in your account, SMS will silently fail.', 'kwtsms' ),
							'<a href="' . esc_url( admin_url( 'admin.php?page=kwtsms-otp-gateway' ) ) . '">' . esc_html__( 'Gateway page (SMS Coverage section)', 'kwtsms' ) . '</a>'
						);
						?>
					</p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== On Balance Failure ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-warning"></span> <?php esc_html_e( 'On Balance Failure', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<p style="margin-top:-8px;color:#555;font-size:13px;">
			<?php esc_html_e( 'Decides what happens when kwtSMS cannot send an OTP because your SMS credit balance is zero. An admin email is always sent when this condition is first detected.', 'kwtsms' ); ?>
		</p>
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><?php esc_html_e( 'When credits run out', 'kwtsms' ); ?></th>
				<td>
					<fieldset>
						<label style="display:block;margin-bottom:10px;">
							<input type="radio" name="kwtsms_otp_general[balance_failure_mode]" value="block"
								<?php checked( $kwtsms_balance_failure_mode, 'block' ); ?> />
							<strong><?php esc_html_e( 'Block logins (Recommended)', 'kwtsms' ); ?></strong>
							<p class="description" style="margin-left:24px;">
								<?php esc_html_e( 'Users who require OTP cannot log in until the account is recharged. This keeps OTP enforcement intact and makes the outage visible.', 'kwtsms' ); ?>
							</p>
						</label>
						<label style="display:block;">
							<input type="radio" name="kwtsms_otp_general[balance_failure_mode]" value="allow"
								<?php checked( $kwtsms_balance_failure_mode, 'allow' ); ?> />
							<?php esc_html_e( 'Allow login without OTP (password only)', 'kwtsms' ); ?>
							<p class="description" style="margin-left:24px;color:#d63638;">
								<?php esc_html_e( 'Users bypass OTP and log in with password alone until credits are restored. Choose this only if uninterrupted access is more important than 2FA enforcement.', 'kwtsms' ); ?>
							</p>
						</label>
					</fieldset>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== Attribution Footer ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-megaphone"></span> <?php esc_html_e( 'Attribution Footer', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label for="kwtsms_referral_link"><?php esc_html_e( 'Show credit link', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_general[referral_link]" id="kwtsms_referral_link"
							value="1" <?php checked( $kwtsms_referral_link ); ?> />
						<?php esc_html_e( 'Display "SMS by kwtSMS.com" footer on login pages (opt-in)', 'kwtsms' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'The link text is fixed and cannot be customized.', 'kwtsms' ); ?></p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<div id="kwtsms-advanced-options" style="display:none;">

		<!-- ===== CAPTCHA ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'CAPTCHA Protection', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label><?php esc_html_e( 'CAPTCHA Provider', 'kwtsms' ); ?></label></th>
				<td>
					<?php
					$kwtsms_providers = array(
						'none'      => __( 'None', 'kwtsms' ),
						'recaptcha' => __( 'Google reCAPTCHA v3 (invisible)', 'kwtsms' ),
						'turnstile' => __( 'Cloudflare Turnstile', 'kwtsms' ),
					);
					foreach ( $kwtsms_providers as $kwtsms_value => $kwtsms_label ) :
						?>
						<label style="display:block;margin-bottom:6px;">
							<input type="radio" name="kwtsms_otp_general[captcha_provider]"
								value="<?php echo esc_attr( $kwtsms_value ); ?>"
								id="captcha_provider_<?php echo esc_attr( $kwtsms_value ); ?>"
								<?php checked( $kwtsms_captcha_provider, $kwtsms_value ); ?> />
							<?php echo esc_html( $kwtsms_label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>

			<tr class="kwtsms-captcha-fields kwtsms-recaptcha-fields" style="<?php echo 'recaptcha' !== $kwtsms_captcha_provider ? 'display:none;' : ''; ?>">
				<th scope="row"><?php esc_html_e( 'reCAPTCHA v3 Keys', 'kwtsms' ); ?></th>
				<td>
					<input type="text" name="kwtsms_otp_general[recaptcha_site_key]"
						value="<?php echo esc_attr( $kwtsms_general['recaptcha_site_key'] ?? '' ); ?>"
						class="regular-text" placeholder="<?php esc_attr_e( 'Site Key', 'kwtsms' ); ?>" /><br /><br />
					<input type="password" name="kwtsms_otp_general[recaptcha_secret_key]"
						value="<?php echo esc_attr( $kwtsms_general['recaptcha_secret_key'] ?? '' ); ?>"
						class="regular-text" placeholder="<?php esc_attr_e( 'Secret Key', 'kwtsms' ); ?>" />
					<p class="description">
						<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Get keys from Google reCAPTCHA Admin Console ', 'kwtsms' ); ?>
						</a>
					</p>
				</td>
			</tr>

			<tr class="kwtsms-captcha-fields kwtsms-turnstile-fields" style="<?php echo 'turnstile' !== $kwtsms_captcha_provider ? 'display:none;' : ''; ?>">
				<th scope="row"><?php esc_html_e( 'Turnstile Keys', 'kwtsms' ); ?></th>
				<td>
					<input type="text" name="kwtsms_otp_general[turnstile_site_key]"
						value="<?php echo esc_attr( $kwtsms_general['turnstile_site_key'] ?? '' ); ?>"
						class="regular-text" placeholder="<?php esc_attr_e( 'Site Key', 'kwtsms' ); ?>" /><br /><br />
					<input type="password" name="kwtsms_otp_general[turnstile_secret_key]"
						value="<?php echo esc_attr( $kwtsms_general['turnstile_secret_key'] ?? '' ); ?>"
						class="regular-text" placeholder="<?php esc_attr_e( 'Secret Key', 'kwtsms' ); ?>" />
					<p class="description">
						<a href="https://dash.cloudflare.com/" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Get keys from Cloudflare Dashboard ', 'kwtsms' ); ?>
						</a>
					</p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== Security ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-privacy"></span> <?php esc_html_e( 'Security', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="kwtsms_blocked_phones"><?php esc_html_e( 'Blocked Phone Numbers', 'kwtsms' ); ?></label>
				</th>
				<td>
					<textarea name="kwtsms_otp_general[blocked_phones]" id="kwtsms_blocked_phones"
						rows="6" class="large-text code"
						<?php /* 2026-11-05 11:21 +03: placeholder 965XXXXXXXX replaces real-looking Kuwait numbers, int-testnumber. */ ?>
						placeholder="965XXXXXXXX&#10;965XXXXXXXX"><?php echo esc_textarea( $kwtsms_blocked_phones ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One per line or comma-separated, with country code (digits only). These numbers will never receive OTP SMS. Blocked requests return a silent success to prevent enumeration.', 'kwtsms' ); ?>
					</p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== IP Rules ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'IP Rules', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<p style="margin-top:-8px;color:#555;font-size:13px;">
			<?php esc_html_e( 'One IPv4 or IPv6 address or CIDR per line (e.g. 192.168.1.0/24). Allowlisted IPs skip per-IP rate limiting. Blocklisted IPs receive a silent refusal.', 'kwtsms' ); ?>
		</p>
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row">
					<label for="kwtsms_ip_allowlist"><?php esc_html_e( 'IP Allowlist', 'kwtsms' ); ?></label>
				</th>
				<td>
					<textarea name="kwtsms_otp_general[ip_allowlist]" id="kwtsms_ip_allowlist"
						rows="5" class="large-text code"
						placeholder="192.168.1.0/24&#10;10.0.0.5"><?php echo esc_textarea( $kwtsms_ip_allowlist ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'These IPs skip the per-IP rate limit and proxy detection (when enabled). OTP is still required.', 'kwtsms' ); ?>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row">
					<label for="kwtsms_ip_blocklist"><?php esc_html_e( 'IP Blocklist', 'kwtsms' ); ?></label>
				</th>
				<td>
					<textarea name="kwtsms_otp_general[ip_blocklist]" id="kwtsms_ip_blocklist"
						rows="5" class="large-text code"
						placeholder="185.220.101.0/24&#10;45.33.32.156"><?php echo esc_textarea( $kwtsms_ip_blocklist ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'These IPs are silently refused. The response is identical to a rate-limit error to prevent enumeration of this list.', 'kwtsms' ); ?>
					</p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== IPHub Proxy/VPN Detection ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Proxy and VPN Detection (IPHub)', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<p style="margin-top:-8px;color:#555;font-size:13px;">
			<?php
			printf(
				/* translators: %s: IPHub website link */
				esc_html__( 'Uses the %s API to identify and act on OTP requests from known proxy or VPN IP addresses. Allowlisted IPs (above) always bypass this check.', 'kwtsms' ),
				'<a href="https://iphub.info/" target="_blank" rel="noopener noreferrer">IPHub</a>'
			);
			?>
		</p>
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label for="kwtsms_iphub_enabled"><?php esc_html_e( 'Enable IPHub Detection', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_general[iphub_enabled]" id="kwtsms_iphub_enabled"
							value="1" <?php checked( $kwtsms_iphub_enabled ); ?> />
						<?php esc_html_e( 'Check each OTP request IP against the IPHub reputation database.', 'kwtsms' ); ?>
					</label>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_iphub_api_key"><?php esc_html_e( 'IPHub API Key', 'kwtsms' ); ?></label></th>
				<td>
					<input type="password" name="kwtsms_otp_general[iphub_api_key]" id="kwtsms_iphub_api_key"
						value="<?php echo esc_attr( $kwtsms_iphub_api_key ); ?>"
						class="regular-text" autocomplete="new-password"
						placeholder="<?php esc_attr_e( 'Paste your IPHub API key here', 'kwtsms' ); ?>" />
					<p class="description">
						<a href="https://iphub.info/register" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Get a free API key from IPHub.info (up to 1,000 lookups/day on the free plan) ', 'kwtsms' ); ?>
						</a>
					</p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_iphub_action_block1"><?php esc_html_e( 'Action for Level 1 (Proxy/VPN)', 'kwtsms' ); ?></label></th>
				<td>
					<select name="kwtsms_otp_general[iphub_action_block1]" id="kwtsms_iphub_action_block1">
						<option value="block" <?php selected( $kwtsms_iphub_action_block1, 'block' ); ?>><?php esc_html_e( 'Block silently (recommended)', 'kwtsms' ); ?></option>
						<option value="log"   <?php selected( $kwtsms_iphub_action_block1, 'log' ); ?>><?php esc_html_e( 'Log only, allow through', 'kwtsms' ); ?></option>
						<option value="allow" <?php selected( $kwtsms_iphub_action_block1, 'allow' ); ?>><?php esc_html_e( 'Allow (no action)', 'kwtsms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'IP identified as a confirmed proxy or VPN exit node.', 'kwtsms' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_iphub_action_block2"><?php esc_html_e( 'Action for Level 2 (Mixed)', 'kwtsms' ); ?></label></th>
				<td>
					<select name="kwtsms_otp_general[iphub_action_block2]" id="kwtsms_iphub_action_block2">
						<option value="log"   <?php selected( $kwtsms_iphub_action_block2, 'log' ); ?>><?php esc_html_e( 'Log only, allow through (recommended)', 'kwtsms' ); ?></option>
						<option value="block" <?php selected( $kwtsms_iphub_action_block2, 'block' ); ?>><?php esc_html_e( 'Block silently', 'kwtsms' ); ?></option>
						<option value="allow" <?php selected( $kwtsms_iphub_action_block2, 'allow' ); ?>><?php esc_html_e( 'Allow (no action)', 'kwtsms' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'IP from a range that contains a mix of residential and proxy traffic.', 'kwtsms' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"><label for="kwtsms_iphub_cache_ttl"><?php esc_html_e( 'Cache TTL', 'kwtsms' ); ?></label></th>
				<td>
					<input type="number" name="kwtsms_otp_general[iphub_cache_ttl]" id="kwtsms_iphub_cache_ttl"
						value="<?php echo esc_attr( (string) absint( $kwtsms_iphub_cache_ttl ) ); ?>"
						class="small-text" />
					<?php esc_html_e( 'seconds', 'kwtsms' ); ?>
					<p class="description">
						<?php esc_html_e( 'How long to cache each IP reputation result (min: 1 hour = 3600 s, max: 7 days = 604800 s, default: 86400 s = 1 day). Cached results avoid repeated API calls for the same IP.', 'kwtsms' ); ?>
					</p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== Developer Tools ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Developer Tools', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label for="kwtsms_debug_logging"><?php esc_html_e( 'Debug Logging', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_general[debug_logging]" id="kwtsms_debug_logging"
							value="1" <?php checked( $kwtsms_debug_logging ); ?> />
						<?php esc_html_e( 'Enable detailed logging for troubleshooting.', 'kwtsms' ); ?>
					</label>
					<?php if ( $kwtsms_debug_logging ) : ?>
					<p class="description" style="color:#d63638;font-weight:600;">
						<?php esc_html_e( '⚠ Debug Logging is ON, disable this on production sites.', 'kwtsms' ); ?>
					</p>
					<?php endif; ?>
					<p class="description">
						<?php
						// Show relative path using wp_upload_dir() per WP.org guidelines.
						$kwtsms_upload      = wp_upload_dir();
						$kwtsms_content_rel = basename( dirname( $kwtsms_upload['basedir'] ) );
						$kwtsms_log_path    = $kwtsms_content_rel . '/uploads/kwtsms-logs/'; // 2026-11-11 12:51 +03: folder only, the file name is random (security fix).
						printf(
							/* translators: %s: path to the debug log file */
							esc_html__( 'Log file: %s', 'kwtsms' ),
							'<code>' . esc_html( $kwtsms_log_path ) . '</code>'
						);
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-help' ) ); ?>">
							<?php esc_html_e( 'See Help page for troubleshooting guide ', 'kwtsms' ); ?>
						</a>
					</p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		</div><!-- /#kwtsms-advanced-options -->

		<!-- ===== Advanced Options Toggle ===== -->
		<div class="kwtsms-advanced-toggle-bar" style="margin:20px 0 4px;padding:12px 16px;background:#f8f8f8;border:1px solid #ddd;border-radius:4px;display:flex;align-items:center;gap:14px;">
			<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;font-size:13px;margin:0;">
				<input type="checkbox" id="kwtsms-advanced-toggle" style="width:16px;height:16px;cursor:pointer;margin:0;" />
				<?php esc_html_e( 'Show Advanced Options', 'kwtsms' ); ?>
			</label>
			<span style="color:#888;font-size:12px;"><?php esc_html_e( 'CAPTCHA, Security, IP Rules, Proxy Detection, Developer Tools', 'kwtsms' ); ?></span>
		</div>


		<?php submit_button( __( 'Save Settings', 'kwtsms' ), 'primary kwtsms-save-btn' ); ?>
	</form>

</div><!-- /.kwtsms-admin-wrap -->

<?php
ob_start();
?>
// Allowed countries tag manager
(function() {
	'use strict';
	var tagsDiv   = document.getElementById('kwtsms-allowed-tags');
	var input     = document.getElementById('kwtsms-allowed-countries-input');
	var addSelect = document.getElementById('kwtsms-add-country-select');
	var addBtn    = document.getElementById('kwtsms-add-country-btn');
	if ( ! tagsDiv || ! input || ! addSelect || ! addBtn ) return;

	function getIso2List() {
		try { return JSON.parse(input.value) || []; } catch(e) { return []; }
	}

	function setIso2List(list) {
		input.value = JSON.stringify(list);
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}

	var removeLabel = ( typeof kwtsmsGeneral !== 'undefined' && kwtsmsGeneral.removeLabel )
		? kwtsmsGeneral.removeLabel : 'Remove';

	function renderTag(iso2, dial, name) {
		var span = document.createElement('span');
		span.className = 'kwtsms-country-tag';
		span.style.cssText = 'display:inline-flex;align-items:center;background:#f0f0f0;border:1px solid #ccc;border-radius:3px;padding:3px 8px;font-size:13px;';
		span.dataset.iso2  = iso2;
		span.textContent   = name + ' (+' + dial + ')';
		var btn = document.createElement('button');
		btn.type      = 'button';
		btn.className = 'kwtsms-remove-country';
		btn.dataset.iso2 = iso2;
		btn.style.cssText = 'background:none;border:none;cursor:pointer;margin-left:6px;color:#dc3232;font-weight:bold;font-size:14px;line-height:1;';
		btn.textContent = '\u00d7';
		btn.setAttribute( 'aria-label', removeLabel );
		btn.addEventListener('click', function() {
			var list = getIso2List().filter(function(c) { return c !== iso2; });
			setIso2List(list);
			span.remove();
		});
		span.appendChild(btn);
		return span;
	}

	// Bind remove buttons on existing tags.
	tagsDiv.querySelectorAll('.kwtsms-remove-country').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var iso2 = btn.dataset.iso2;
			var list = getIso2List().filter(function(c) { return c !== iso2; });
			setIso2List(list);
			btn.closest('.kwtsms-country-tag').remove();
		});
	});

	// Add button.
	addBtn.addEventListener('click', function() {
		var opt  = addSelect.options[addSelect.selectedIndex];
		var iso2 = opt.value;
		var dial = opt.dataset.dial;
		var name = opt.dataset.name;
		if (!iso2) return;
		var list = getIso2List();
		if (list.indexOf(iso2) !== -1) return; // already added
		list.push(iso2);
		setIso2List(list);
		tagsDiv.appendChild(renderTag(iso2, dial, name));
		addSelect.selectedIndex = 0;
	});
})();

// Advanced Options toggle
(function() {
	'use strict';
	var toggle = document.getElementById('kwtsms-advanced-toggle');
	var panel  = document.getElementById('kwtsms-advanced-options');
	if ( ! toggle || ! panel ) return;

	var STORAGE_KEY = 'kwtsms_show_advanced';

	function applyState(show) {
		panel.style.display = show ? '' : 'none';
		toggle.checked = show;
	}

	// Restore persisted state.
	applyState( localStorage.getItem( STORAGE_KEY ) === '1' );

	toggle.addEventListener('change', function() {
		var show = toggle.checked;
		localStorage.setItem( STORAGE_KEY, show ? '1' : '0' );
		applyState( show );
	});
})();
<?php
$kwtsms_general_page_js = ob_get_clean();

wp_localize_script(
	'kwtsms-admin',
	'kwtsmsGeneral',
	array(
		'removeLabel' => __( 'Remove', 'kwtsms' ),
	)
);
wp_add_inline_script( 'kwtsms-admin', $kwtsms_general_page_js, 'after' );
?>
