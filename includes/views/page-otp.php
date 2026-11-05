<?php
/**
 * OTP Entry Page: rendered via login_init hook on wp-login.php?action=kwtsms_otp
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Available variables (set by the calling render method):
 *
 * @var string  $error_message  Error message to display (empty if none).
 * @var string  $token          Partial auth session token.
 * @var int     $otp_length     Expected OTP code length (4 or 6).
 * @var int     $cooldown       Resend cooldown in seconds.
 * @var string  $redirect_to    Post-login redirect URL.
 * @var string  $nonce_resend   Nonce for AJAX resend request.
 * @var bool    $kwtsms_is_reset       True when rendering for password reset context.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

$kwtsms_is_reset  = $is_reset ?? false;
$kwtsms_site_name = get_bloginfo( 'name' );
$kwtsms_login_url = wp_login_url();

// Build site logo: matches WordPress login page header behaviour.
$kwtsms_custom_logo_id = get_theme_mod( 'custom_logo' );
if ( $kwtsms_custom_logo_id ) {
	// Custom logo set via Customizer.
	$kwtsms_logo_html = wp_get_attachment_image(
		$kwtsms_custom_logo_id,
		array( 312, 84 ),
		false,
		array( 'alt' => $kwtsms_site_name )
	);
} elseif ( has_site_icon() ) {
	// Site icon: WordPress login page uses this as fallback since WP 5.5.
	$kwtsms_logo_html = '<img src="' . esc_url( get_site_icon_url( 84 ) ) . '" alt="' . esc_attr( $kwtsms_site_name ) . '" style="max-height:84px;width:auto;" />';
} else {
	// No logo: visually hide the text with screen-reader-text so WordPress login CSS
	// (#login h1 a background-image) shows the WordPress logo instead.
	$kwtsms_logo_html = '<span class="screen-reader-text">' . esc_html( $kwtsms_site_name ) . '</span>';
}

// Referral link settings.
$kwtsms_referral_link_enabled = isset( $plugin_settings ) ? (bool) $plugin_settings->get( 'general.referral_link', 0 ) : false;
$kwtsms_page_title            = $kwtsms_is_reset
	? __( 'Verify Your Identity: Password Reset', 'kwtsms' )
	: __( 'Enter Your Verification Code', 'kwtsms' );

$kwtsms_masked_phone = '';
if ( ! empty( $token ) ) {
	// Use the correct transient prefix depending on context (login vs. reset).
	$kwtsms_transient_key = $kwtsms_is_reset
		? KwtSMS_Reset_OTP::RESET_TRANSIENT_PREFIX . $token
		: 'kwtsms_partial_auth_' . $token;
	$kwtsms_partial       = get_transient( $kwtsms_transient_key );
	if ( $kwtsms_partial && ! empty( $kwtsms_partial['phone'] ) ) {
		$kwtsms_p            = $kwtsms_partial['phone'];
		$kwtsms_len          = strlen( $kwtsms_p );
		$kwtsms_masked_phone = substr( $kwtsms_p, 0, max( 0, $kwtsms_len - 4 ) ) . '****';
	}
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $kwtsms_page_title . ': ' . $kwtsms_site_name ); ?></title>
	<?php wp_head(); ?>
</head>
<body class="login wp-core-ui">
<div id="login">

	<h1>
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php echo esc_attr( $kwtsms_site_name ); ?>" tabindex="-1">
			<?php echo wp_kses_post( $kwtsms_logo_html ); ?>
		</a>
	</h1>

	<div class="kwtsms-otp-box">
		<h2 class="kwtsms-otp-title">
			<?php echo esc_html( $kwtsms_is_reset ? __( 'Password Reset', 'kwtsms' ) : __( 'Two-Step Verification', 'kwtsms' ) ); ?>
		</h2>

		<?php if ( ! empty( $kwtsms_masked_phone ) ) : ?>
		<p class="kwtsms-otp-desc">
			<?php
			printf(
				/* translators: %s: partially masked phone number */
				esc_html__( 'We sent a %1$d-digit code to %2$s', 'kwtsms' ),
				(int) $otp_length,
				'<strong>' . esc_html( $kwtsms_masked_phone ) . '</strong>'
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

		<?php
		$kwtsms_form_action  = ! empty( $kwtsms_is_reset )
			? add_query_arg( 'action', 'kwtsms_reset_otp', $kwtsms_login_url )
			: add_query_arg( 'action', 'kwtsms_otp', $kwtsms_login_url );
		$kwtsms_nonce_action = ! empty( $kwtsms_is_reset ) ? 'kwtsms_reset_otp_submit' : 'kwtsms_otp_submit';
		$kwtsms_nonce_field  = ! empty( $kwtsms_is_reset ) ? 'kwtsms_reset_nonce' : 'kwtsms_otp_nonce';
		?>
		<form method="post" action="<?php echo esc_url( $kwtsms_form_action ); ?>" id="kwtsms-otp-form">
			<?php wp_nonce_field( $kwtsms_nonce_action, $kwtsms_nonce_field ); ?>

			<?php if ( $kwtsms_is_reset ) : ?>
				<input type="hidden" name="kwtsms_context" value="reset" />
			<?php endif; ?>

			<?php if ( ! empty( $redirect_to ) ) : ?>
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
			<?php endif; ?>

			<div class="kwtsms-code-group">
				<label for="kwtsms_code" class="screen-reader-text">
					<?php esc_html_e( 'Verification code', 'kwtsms' ); ?>
				</label>
				<input
					type="text"
					inputmode="numeric"
					pattern="[0-9]*"
					autocomplete="one-time-code"
					name="kwtsms_code"
					id="kwtsms_code"
					class="input kwtsms-code-input"
					placeholder="<?php echo esc_attr( str_repeat( '_', $otp_length ) ); ?>"
					maxlength="<?php echo (int) $otp_length; ?>"
					autofocus
					required
				/>
			</div>

			<?php if ( ! $kwtsms_is_reset ) : ?>
			<div class="kwtsms-remember-me">
				<label>
					<input type="checkbox" name="kwtsms_rememberme" value="1" />
					<?php esc_html_e( 'Remember Me', 'kwtsms' ); ?>
				</label>
			</div>
			<div class="kwtsms-trust-device">
				<label>
					<input type="checkbox" name="kwtsms_trust_device" value="1" />
					<?php esc_html_e( 'Trust this device for 30 days', 'kwtsms' ); ?>
				</label>
			</div>
		<?php endif; ?>

		<input type="submit" name="kwtsms_verify" class="button button-primary button-large kwtsms-btn" value="<?php esc_attr_e( 'Verify Code', 'kwtsms' ); ?>" />
		</form>

		<div class="kwtsms-resend-wrap">
			<button
				type="button"
				id="kwtsms-resend-btn"
				class="kwtsms-resend-btn"
				data-nonce="<?php echo esc_attr( $nonce_resend ); ?>"
				data-cooldown="<?php echo (int) $cooldown; ?>"
				data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-token="<?php echo esc_attr( $token ?? '' ); ?>"
				data-context="<?php echo esc_attr( $kwtsms_is_reset ? 'reset' : 'login' ); ?>"
				disabled
			>
				<?php
				printf(
					/* translators: %d: seconds until resend is allowed */
					esc_html__( 'Resend code (%d)', 'kwtsms' ),
					(int) $cooldown
				);
				?>
			</button>
			<span id="kwtsms-resend-msg" class="kwtsms-resend-msg" aria-live="polite"></span>
		</div>

		<p class="kwtsms-back-link">
			<a href="<?php echo esc_url( $kwtsms_login_url ); ?>">
				← <?php esc_html_e( 'Back to login', 'kwtsms' ); ?>
			</a>
		</p>
	</div>
</div>

<?php wp_footer(); ?>

<?php
if ( $kwtsms_referral_link_enabled ) :
	$kwtsms_ref_url = 'https://www.kwtsms.com/';
	?>
<p class="kwtsms-credit" style="text-align:center;font-size:11px;color:#888;margin-top:16px;">
	<a href="<?php echo esc_url( $kwtsms_ref_url ); ?>" target="_blank" rel="noopener">
		<?php esc_html_e( 'SMS service by kwtSMS.com', 'kwtsms' ); ?>
	</a>
</p>
<?php endif; ?>
</body>
</html>
