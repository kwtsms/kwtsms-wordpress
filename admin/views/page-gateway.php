<?php
/**
 * Admin View: Gateway Settings Page.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * API information, login verification, sender ID selection, SMS coverage,
 * test mode, and gateway test SMS.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.
$kwtsms_settings             = $this->plugin->settings;
$kwtsms_gateway              = $kwtsms_settings->get( 'gateway' ) + KwtSMS_Settings::DEFAULTS['gateway'];
$kwtsms_sender_id            = $kwtsms_gateway['sender_id'] ?? '';
$kwtsms_test_mode            = ! empty( $kwtsms_gateway['test_mode'] );
$kwtsms_credentials_verified = ! empty( $kwtsms_gateway['credentials_verified'] );
$kwtsms_sender_ids           = $kwtsms_gateway['sender_ids'] ?? array();

// Build dial-code lookups for coverage pills.
$kwtsms__cc_data_all  = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';
$kwtsms__dial_by_name = array(); // Maps lowercase name to dial code.
$kwtsms__dial_by_iso2 = array(); // Maps ISO2 to dial code.
$kwtsms__name_by_dial = array(); // Maps dial code to country name.
$kwtsms__iso2_by_dial = array(); // Maps dial code to ISO2.
foreach ( $kwtsms__cc_data_all as $kwtsms__cce ) {
	$kwtsms__dial_by_name[ strtolower( $kwtsms__cce['name'] ) ] = $kwtsms__cce['dial'];
	$kwtsms__dial_by_iso2[ $kwtsms__cce['iso2'] ]               = $kwtsms__cce['dial'];
	$kwtsms__name_by_dial[ $kwtsms__cce['dial'] ]               = $kwtsms__cce['name'];
	$kwtsms__iso2_by_dial[ $kwtsms__cce['dial'] ]               = $kwtsms__cce['iso2'];
}
unset( $kwtsms__cc_data_all, $kwtsms__cce );

// API status codes that must never be treated as country names.
$kwtsms__api_codes = array( 'OK', 'ERROR', 'ERR', 'FAIL', 'FAILED', 'NULL', 'NONE', 'N/A', 'NA', 'TRUE', 'FALSE' );
?>
<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php esc_html_e( 'Gateway Settings', 'kwtsms' ); ?></h1>
	</div>
	<hr class="wp-header-end">

	<!-- Balance Display -->
	<?php
	$kwtsms_bal_available = $kwtsms_gateway['balance_available'] ?? null;
	$kwtsms_bal_purchased = $kwtsms_gateway['balance_purchased'] ?? null;
	?>
	<div class="kwtsms-balance-bar" id="kwtsms-balance-card"<?php echo $kwtsms_credentials_verified ? '' : ' style="display:none;"'; ?>>
		<?php esc_html_e( 'Available balance:', 'kwtsms' ); ?>
		<strong id="kwtsms-balance"><?php echo null !== $kwtsms_bal_available ? esc_html( number_format( (float) $kwtsms_bal_available, 2 ) ) : '-'; ?></strong>
		&nbsp;|&nbsp;
		<?php esc_html_e( 'Total purchased:', 'kwtsms' ); ?>
		<span id="kwtsms-balance-purchased"><?php echo ( null !== $kwtsms_bal_purchased && $kwtsms_bal_purchased > 0 ) ? esc_html( number_format( (float) $kwtsms_bal_purchased, 2 ) ) : '-'; ?></span>
		<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener" style="margin-left:auto;font-size:13px;font-weight:600;"><?php esc_html_e( 'Recharge/Buy credits ', 'kwtsms' ); ?></a>
	</div>

	<form method="post" action="options.php" id="kwtsms-gateway-form">
		<?php settings_fields( 'kwtsms_otp_gateway_group' ); ?>

		<?php if ( ! $kwtsms_credentials_verified ) : ?>
		<div class="kwtsms-api-status is-info kwtsms-signup-note">
			<?php esc_html_e( "Don't have a kwtSMS account?", 'kwtsms' ); ?>
			<a href="https://www.kwtsms.com/signup" target="_blank" rel="noopener" style="color:#46b450;font-weight:600;">
				<?php esc_html_e( 'Sign up for free ', 'kwtsms' ); ?>
			</a>
		</div>
		<?php endif; ?>

		<!-- ===== API Information ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-admin-network"></span> <?php esc_html_e( 'API Information', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr id="kwtsms-row-username"<?php echo $kwtsms_credentials_verified ? ' style="display:none;"' : ''; ?>>
				<th scope="row"><label for="kwtsms_api_username"><?php esc_html_e( 'API Username', 'kwtsms' ); ?></label></th>
				<td>
					<input type="text" name="kwtsms_otp_gateway[api_username]" id="kwtsms_api_username"
						value="<?php echo esc_attr( $kwtsms_gateway['api_username'] ); ?>"
						class="regular-text" autocomplete="off" />
					<p class="description"><?php esc_html_e( 'Your kwtSMS API username, found in your kwtSMS account under API Settings, not your login mobile.', 'kwtsms' ); ?></p>
					<p class="description" id="kwtsms-username-warning" style="color:#dc3232;display:none;"></p>
				</td>
			</tr>

			<tr id="kwtsms-row-password"<?php echo $kwtsms_credentials_verified ? ' style="display:none;"' : ''; ?>>
				<th scope="row"><label for="kwtsms_api_password"><?php esc_html_e( 'API Password', 'kwtsms' ); ?></label></th>
				<td>
					<input type="password" name="kwtsms_otp_gateway[api_password]" id="kwtsms_api_password"
						value=""
						placeholder="<?php echo $kwtsms_credentials_verified ? esc_attr__( '(leave blank to keep current password)', 'kwtsms' ) : ''; ?>"
						class="regular-text" autocomplete="new-password" />
					<p class="description"><?php esc_html_e( 'Your kwtSMS API password, found in your kwtSMS account under API Settings, not your login password.', 'kwtsms' ); ?></p>
				</td>
			</tr>

			<tr>
				<th scope="row"></th>
				<td>
					<div style="display:flex;flex-direction:column;gap:8px;align-items:flex-start;">
						<?php if ( $kwtsms_credentials_verified ) : ?>
						<button type="button" id="kwtsms-login-btn" class="button button-primary" style="display:none;">
							<?php esc_html_e( 'Login', 'kwtsms' ); ?>
						</button>
						<div style="display:flex;flex-direction:row;gap:10px;align-items:center;">
							<button type="button" id="kwtsms-reload-all" class="button">
								&#x21BB; <?php esc_html_e( 'Reload', 'kwtsms' ); ?>
							</button>
							<button type="button" id="kwtsms-logout-btn" class="button">
								<?php esc_html_e( 'Logout', 'kwtsms' ); ?>
							</button>
							<span id="kwtsms-login-status" style="font-size:13px;font-weight:600;" aria-live="polite">
								<span style="color:#46b450;">&#x2713; 
								<?php
								/* translators: %s: API username */
								printf( esc_html__( 'Connected as %s', 'kwtsms' ), esc_html( $kwtsms_gateway['api_username'] ) );
								?>
								</span>
							</span>
						</div>
						<p class="description kwtsms-reload-hint"><?php esc_html_e( 'Fetches latest Sender IDs, coverage, and balance from your kwtSMS account.', 'kwtsms' ); ?></p>
						<?php else : ?>
						<button type="button" id="kwtsms-login-btn" class="button button-primary">
							<?php esc_html_e( 'Login', 'kwtsms' ); ?>
						</button>
						<div style="display:flex;flex-direction:row;gap:10px;align-items:center;">
							<button type="button" id="kwtsms-reload-all" class="button" style="display:none;">
								&#x21BB; <?php esc_html_e( 'Reload', 'kwtsms' ); ?>
							</button>
							<button type="button" id="kwtsms-logout-btn" class="button" style="display:none;">
								<?php esc_html_e( 'Logout', 'kwtsms' ); ?>
							</button>
							<span id="kwtsms-login-status" style="font-size:13px;font-weight:600;" aria-live="polite"></span>
						</div>
						<p class="description kwtsms-reload-hint" style="display:none;"><?php esc_html_e( 'Fetches latest Sender IDs, coverage, and balance from your kwtSMS account.', 'kwtsms' ); ?></p>
						<?php endif; ?>
					</div>
					</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- Dependent sections: hidden until credentials are verified -->
		<div id="kwtsms-verified-sections"<?php echo $kwtsms_credentials_verified ? '' : ' style="display:none;"'; ?>>

		<!-- ===== Sender ID ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-id"></span> <?php esc_html_e( 'Sender ID', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr id="kwtsms-sender-row">
				<th scope="row"><label for="kwtsms_sender_id"><?php esc_html_e( 'Active Sender ID', 'kwtsms' ); ?></label></th>
				<td>
					<select name="kwtsms_otp_gateway[sender_id]" id="kwtsms_sender_id">
							<?php if ( ! empty( $kwtsms_sender_ids ) ) : ?>
								<?php foreach ( $kwtsms_sender_ids as $kwtsms_sid ) : ?>
								<option value="<?php echo esc_attr( $kwtsms_sid ); ?>"
									<?php selected( $kwtsms_sender_id, $kwtsms_sid ); ?>>
									<?php echo esc_html( $kwtsms_sid ); ?>
								</option>
								<?php endforeach; ?>
							<?php elseif ( ! empty( $kwtsms_sender_id ) ) : ?>
								<option value="<?php echo esc_attr( $kwtsms_sender_id ); ?>" selected>
									<?php echo esc_html( $kwtsms_sender_id ); ?>
								</option>
							<?php else : ?>
								<option value=""><?php esc_html_e( 'Select Sender ID', 'kwtsms' ); ?></option>
							<?php endif; ?>
					</select>
					<p class="description"><?php esc_html_e( 'This is the name recipients see as the sender of your SMS messages. Choose from the sender IDs registered on your kwtSMS account.', 'kwtsms' ); ?></p>
					<p class="description">
						<a href="https://www.kwtsms.com/sender-id-help.html" target="_blank" rel="noopener">
							<?php esc_html_e( 'Register or request a Sender ID at kwtSMS ', 'kwtsms' ); ?>
						</a>
					</p>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== Test Mode ===== -->
		<div class="kwtsms-settings-card" style="border-left: 4px solid <?php echo $kwtsms_test_mode ? '#d63638' : '#00a32a'; ?>;">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-flag"></span> <?php esc_html_e( 'Test Mode', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="kwtsms_test_mode"><?php esc_html_e( 'Enable Test Mode', 'kwtsms' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="kwtsms_otp_gateway[test_mode]" id="kwtsms_test_mode"
							value="1" <?php checked( $kwtsms_test_mode ); ?> />
						<?php
						printf(
							/* translators: %s: link to kwtSMS account dashboard */
							esc_html__( 'Messages are queued but not delivered. Delete from %s queue to recover credits.', 'kwtsms' ),
							'<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener noreferrer">' . esc_html__( 'kwtSMS account', 'kwtsms' ) . '</a>'
						);
						?>
					</label>
					<?php if ( $kwtsms_test_mode ) : ?>
					<p class="description" style="color:#d63638;font-weight:600;">
						<?php esc_html_e( 'Test Mode is ON. SMS will be queued but NOT delivered to phones.', 'kwtsms' ); ?>
					</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<div style="margin-top:20px;">
			<?php submit_button( __( 'Save Settings', 'kwtsms' ), 'primary kwtsms-save-btn', 'submit', false ); ?>
		</div>
		<hr style="margin:20px 0;" />

		<!-- ===== SMS Coverage ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-admin-site-alt3"></span> <?php esc_html_e( 'SMS Coverage', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Active Coverage', 'kwtsms' ); ?></th>
				<td id="kwtsms-coverage-section" aria-live="polite">
					<div id="kwtsms-coverage-result" style="display:flex;flex-wrap:wrap;gap:6px;" aria-live="polite">
						<?php
						$kwtsms_saved_cov = $kwtsms_gateway['coverage'] ?? array();
						if ( ! empty( $kwtsms_saved_cov ) ) :
							foreach ( $kwtsms_saved_cov as $kwtsms_c ) :
								$kwtsms__cname = '';
								$kwtsms__cdial = '';
								if ( is_array( $kwtsms_c ) ) {
									$kwtsms__cname = $kwtsms_c['name'] ?? $kwtsms_c['country'] ?? $kwtsms_c['countryName'] ?? $kwtsms_c['CountryName'] ?? $kwtsms_c['cc'] ?? '';
									if ( '' === $kwtsms__cname ) {
										foreach ( $kwtsms_c as $kwtsms_v ) {
											if ( is_string( $kwtsms_v ) && '' !== $kwtsms_v ) {
												$kwtsms__cname = $kwtsms_v;
												break; }
										}
									}
									if ( '' === $kwtsms__cname ) {
										continue;
									}
									// Skip API status codes stored as country names.
									if ( in_array( strtoupper( $kwtsms__cname ), $kwtsms__api_codes, true ) ) {
										continue;
									}
									// Bare dial-code digit string stored as name? Resolve to country.
									if ( ctype_digit( $kwtsms__cname ) ) {
										$kwtsms__rname = $kwtsms__name_by_dial[ $kwtsms__cname ] ?? '';
										if ( '' === $kwtsms__rname ) {
											continue;
										}
										$kwtsms__cdial = $kwtsms__cname;
										$kwtsms__cname = $kwtsms__rname;
									} else {
										$kwtsms__cdial = $kwtsms_c['dial'] ?? $kwtsms__dial_by_name[ strtolower( $kwtsms__cname ) ] ?? '';
										if ( '' === $kwtsms__cdial && ! empty( $kwtsms_c['cc'] ) ) {
											$kwtsms__cdial = $kwtsms__dial_by_iso2[ strtoupper( $kwtsms_c['cc'] ) ] ?? '';
										}
									}
								} else {
									$kwtsms__cname = trim( (string) $kwtsms_c );
									if ( '' === $kwtsms__cname ) {
										continue;
									}
									// Skip API status codes.
									if ( in_array( strtoupper( $kwtsms__cname ), $kwtsms__api_codes, true ) ) {
										continue;
									}
									// Bare dial-code digit string? Resolve to country name.
									if ( ctype_digit( $kwtsms__cname ) ) {
										$kwtsms__rname = $kwtsms__name_by_dial[ $kwtsms__cname ] ?? '';
										if ( '' === $kwtsms__rname ) {
											continue;
										}
										$kwtsms__cdial = $kwtsms__cname;
										$kwtsms__cname = $kwtsms__rname;
									} else {
										$kwtsms__cdial = $kwtsms__dial_by_name[ strtolower( $kwtsms__cname ) ] ?? '';
									}
								}
								$kwtsms__clabel = '' !== $kwtsms__cdial ? $kwtsms__cname . ' (+' . $kwtsms__cdial . ')' : $kwtsms__cname;
								echo '<span class="kwtsms-tag-chip">' . esc_html( $kwtsms__clabel ) . '</span>';
							endforeach;
						endif;
						?>
					</div>
					<p class="description" style="margin-top:8px;">
						<?php esc_html_e( 'These are the countries your account can currently send SMS to. To send to additional countries, request coverage from your kwtSMS account.', 'kwtsms' ); ?>
						&nbsp;<a href="https://www.kwtsms.com/coverage/" target="_blank" rel="noopener"><?php esc_html_e( 'Request more coverage ', 'kwtsms' ); ?></a>
					</p>
				</td>
			</tr>
		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		<!-- ===== Gateway Test ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-buddicons-pm"></span> <?php esc_html_e( 'Gateway Test', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">

			<tr>
				<th scope="row"><label for="kwtsms_test_phone"><?php esc_html_e( 'Test Phone Number', 'kwtsms' ); ?></label></th>
				<td>
					<input type="tel" id="kwtsms_test_phone"
						value=""
						class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 96512345678', 'kwtsms' ); ?>" />
					<p class="description"><?php esc_html_e( 'Enter the full number with country code. e.g. Kuwait: 965 + 8 digits = 96512345678 (11 digits total).', 'kwtsms' ); ?></p>
					<?php if ( $kwtsms_test_mode ) : ?>
					<p class="description" style="color:#dc3232;font-weight:700;">
						<?php esc_html_e( '⚠ Test Mode is ON. The SMS will be queued but will NOT be delivered to your phone.', 'kwtsms' ); ?>
					</p>
					<?php else : ?>
					<p class="description" style="color:#2a7a2f;font-weight:600;">
						<?php esc_html_e( 'Test Mode is currently OFF. The SMS will be delivered to the phone number above. SMS credits will be consumed.', 'kwtsms' ); ?>
					</p>
					<?php endif; ?>
					<div style="display:flex;align-items:center;gap:12px;margin-top:8px;">
						<button type="button" id="kwtsms-send-test-sms" class="button button-primary"<?php echo $kwtsms_credentials_verified ? '' : ' disabled'; ?>>
							<?php esc_html_e( 'Send Test SMS', 'kwtsms' ); ?>
						</button>
						<span id="kwtsms-test-sms-result" style="font-size:13px;line-height:1.5;" aria-live="polite"></span>
					</div>
				</td>
			</tr>

		</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.kwtsms-settings-card -->

		</div><!-- #kwtsms-verified-sections -->

	</form>

</div>
