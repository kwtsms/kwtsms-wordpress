<?php
/**
 * Passwordless Login Page: phone number entry form.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Available variables:
 *
 * @var string $error_message   Error to display.
 * @var string $success_message Info / success message to display.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

$kwtsms_error_message   = $error_message ?? '';
$kwtsms_success_message = $success_message ?? '';
$kwtsms_site_name       = get_bloginfo( 'name' );
$kwtsms_login_url       = wp_login_url();

/* $plugin_settings is passed as a local variable from KwtSMS_Login_OTP::render_passwordless_page() */
$kwtsms_captcha = new KwtSMS_Captcha( $plugin_settings ?? new KwtSMS_Settings() );

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
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( __( 'Login with SMS: ', 'kwtsms' ) . $kwtsms_site_name ); ?></title>
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
		<h2 class="kwtsms-otp-title"><?php esc_html_e( 'Login with SMS', 'kwtsms' ); ?></h2>
		<p class="kwtsms-otp-desc"><?php esc_html_e( 'Enter your registered phone number to receive a one-time login code.', 'kwtsms' ); ?></p>

		<?php if ( ! empty( $kwtsms_error_message ) ) : ?>
		<div class="kwtsms-otp-error" role="alert"><?php echo esc_html( $kwtsms_error_message ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $kwtsms_success_message ) ) : ?>
		<div class="kwtsms-otp-success" role="status"><?php echo esc_html( $kwtsms_success_message ); ?></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( add_query_arg( 'action', 'kwtsms_passwordless', $kwtsms_login_url ) ); ?>" id="kwtsms-passwordless-form">
			<?php wp_nonce_field( 'kwtsms_passwordless_submit', 'kwtsms_passwordless_nonce' ); ?>

			<label class="screen-reader-text">
				<?php esc_html_e( 'Phone number', 'kwtsms' ); ?>
			</label>

			<?php
			// $allowed_countries and $detected_iso2 are passed from render_passwordless_page().
			// Provide safe fallbacks if viewed directly.
			$kwtsms_allowed_countries = $allowed_countries ?? array();
			$kwtsms_detected_iso2     = $detected_iso2 ?? 'KW';

			// Generate flag emoji from ISO2 using Unicode Regional Indicator Symbols.
			$kwtsms_flag_emoji = function ( $iso2 ) {
				$chars = array();
				foreach ( str_split( strtoupper( $iso2 ) ) as $kwtsms_c ) {
					// 2026-11-07 00:23 +03: html_entity_decode instead of mb_chr(): mb_chr needs the mbstring extension (WordPress polyfills it only from 7.1, we support 6.0); Plugin Check error. wp-release-361.
					$chars[] = html_entity_decode( '&#' . ( 0x1F1E6 + ( ord( $kwtsms_c ) - ord( 'A' ) ) ) . ';', ENT_QUOTES, 'UTF-8' );
				}
				return implode( '', $chars );
			};

			// Resolve pre-selected country's dial code and flag.
			$kwtsms_default_dial = '965';
			$kwtsms_default_flag = $kwtsms_flag_emoji( 'KW' );
			foreach ( $kwtsms_allowed_countries as $kwtsms_c ) {
				if ( $kwtsms_c['iso2'] === $kwtsms_detected_iso2 ) {
					$kwtsms_default_dial = $kwtsms_c['dial'];
					$kwtsms_default_flag = $kwtsms_flag_emoji( $kwtsms_c['iso2'] );
					break;
				}
			}
			?>

			<div class="kwtsms-phone-group">
				<div class="kwtsms-dial-wrap" id="kwtsms-dial-wrap">
					<button type="button" id="kwtsms-dial-trigger" class="kwtsms-dial-trigger"
						aria-haspopup="listbox" aria-expanded="false">
						<span id="kwtsms-dial-display"><?php echo esc_html( $kwtsms_default_flag . ' +' . $kwtsms_default_dial ); ?></span>
						<span class="kwtsms-dial-arrow">&#9662;</span>
					</button>
					<div id="kwtsms-dial-dropdown" class="kwtsms-dial-dropdown" role="listbox" hidden>
						<input type="text" id="kwtsms-dial-search" class="kwtsms-dial-search"
							placeholder="<?php esc_attr_e( 'Search country...', 'kwtsms' ); ?>"
							autocomplete="off" />
						<ul id="kwtsms-dial-list">
							<?php foreach ( $kwtsms_allowed_countries as $kwtsms_c ) : ?>
							<li role="option"
								data-dial="<?php echo esc_attr( $kwtsms_c['dial'] ); ?>"
								data-iso2="<?php echo esc_attr( $kwtsms_c['iso2'] ); ?>"
								data-name="<?php echo esc_attr( strtolower( $kwtsms_c['name'] ) ); ?>"
								<?php
								if ( $kwtsms_c['iso2'] === $kwtsms_detected_iso2 ) :
									?>
									class="is-focused"<?php endif; ?>>
								<?php echo esc_html( $kwtsms_flag_emoji( $kwtsms_c['iso2'] ) . ' +' . $kwtsms_c['dial'] . ' ' . $kwtsms_c['name'] ); ?>
							</li>
							<?php endforeach; ?>
						</ul>
					</div>
					<!-- Hidden dial code submitted with the form -->
					<input type="hidden" name="kwtsms_dial_code" id="kwtsms_dial_code"
						value="<?php echo esc_attr( $kwtsms_default_dial ); ?>" />
				</div>
				<input
					type="tel"
					name="kwtsms_local_phone"
					id="kwtsms_local_phone"
					class="input"
					placeholder="<?php esc_attr_e( 'Local number', 'kwtsms' ); ?>"
					autocomplete="tel-national"
					maxlength="15"
					required
				/>
				<!-- Hidden combined field: dial + local, submitted as kwtsms_phone -->
				<input type="hidden" name="kwtsms_phone" id="kwtsms_phone_combined" />
			</div>

			<?php echo wp_kses_post( $kwtsms_captcha->render_widget() ); ?>

			<input type="submit" class="button button-primary button-large kwtsms-btn" value="<?php esc_attr_e( 'Send OTP Code', 'kwtsms' ); ?>" />
		</form>

		<p class="kwtsms-back-link">
			<a href="<?php echo esc_url( $kwtsms_login_url ); ?>">← <?php esc_html_e( 'Back to login', 'kwtsms' ); ?></a>
		</p>
	</div>
</div>

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
<?php wp_footer(); ?>
</body>
</html>
