<?php
/**
 * Admin View: Shared Form-Integration Settings Sub-Page.
 *
 * Used by CF7, WPForms, and Ninja Forms.
 * The calling render method sets $int_key before including this file.
 *
 * Both cards (Settings and SMS Template) are always visible on the same page.
 *
 * Submitting this form only overwrites the fields for the submitted
 * integration section (identified by the hidden _save_section field),
 * leaving all other integrations' settings intact in the database.
 *
 * @var string        $int_key  Integration key: cf7 | wpforms | nf.
 * @var KwtSMS_Admin  $this     Admin controller instance.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.
// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var string $int_key, set by admin controller before including this view.

// Per-integration configuration.
$kwtsms_configs = array(
	'cf7'     => array(
		'label'        => __( 'Contact Form 7', 'kwtsms' ),
		'enabled_key'  => 'cf7_enabled',
		'mode_key'     => 'cf7_mode',
		'tpl_key'      => 'cf7_confirmation',
		'placeholders' => '{site_name}, {form_name}',
		'tip'          => __( 'Setup tip: add a tel field named kwtsms_phone to your CF7 form:', 'kwtsms' ),
		// 2026-11-05 11:21 +03: placeholder 965XXXXXXXX replaces a real person's number, int-testnumber.
		'tip_code'     => '[tel kwtsms_phone placeholder "e.g. 965XXXXXXXX"]',
	),
	'wpforms' => array(
		'label'        => __( 'WPForms', 'kwtsms' ),
		'enabled_key'  => 'wpforms_enabled',
		'mode_key'     => 'wpforms_mode',
		'tpl_key'      => 'wpforms_confirmation',
		'placeholders' => '{site_name}, {form_name}',
		'tip'          => __( 'WPForms automatically detects Phone fields. Add a Phone field to your form to enable SMS delivery.', 'kwtsms' ),
		'tip_code'     => '',
	),
	'nf'      => array(
		'label'        => __( 'Ninja Forms', 'kwtsms' ),
		'enabled_key'  => 'nf_enabled',
		'mode_key'     => 'nf_mode',
		'tpl_key'      => 'nf_confirmation',
		'placeholders' => '{form_name}, {phone}',
		'tip'          => __( 'Add a Phone field (type=tel) to your Ninja Form. kwtSMS will detect it automatically.', 'kwtsms' ),
		'tip_code'     => '',
	),
);

if ( ! isset( $kwtsms_configs[ $int_key ] ) ) {
	wp_die( esc_html__( 'Unknown integration.', 'kwtsms' ) );
}

$kwtsms_cfg = $kwtsms_configs[ $int_key ];

$kwtsms_settings = $this->plugin->settings;

$kwtsms_int = array_merge(
	KwtSMS_Settings::DEFAULTS['integrations'],
	(array) $kwtsms_settings->get( 'integrations' )
);

$kwtsms_templates    = $kwtsms_settings->get_all_integration_templates();
$kwtsms_enabled_key  = $kwtsms_cfg['enabled_key'];
$kwtsms_mode_key     = $kwtsms_cfg['mode_key'];
$kwtsms_tpl_key      = $kwtsms_cfg['tpl_key'];
$kwtsms_label        = $kwtsms_cfg['label'];
$kwtsms_is_enabled   = ! empty( $kwtsms_int[ $kwtsms_enabled_key ] );
$kwtsms_current_mode = $kwtsms_int[ $kwtsms_mode_key ] ?? 'notification';
$kwtsms_tpl          = $kwtsms_templates[ $kwtsms_tpl_key ] ?? array(
	'enabled' => 0,
	'en'      => '',
	'ar'      => '',
);

/* translators: %s: integration label e.g. "WPForms" */
$kwtsms_page_title = sprintf( __( '%s Settings', 'kwtsms' ), $kwtsms_label );
?>
<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php echo esc_html( $kwtsms_page_title ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-integrations' ) ); ?>" class="button" style="margin-left:16px;align-self:center;">
			&larr; <?php esc_html_e( 'All Integrations', 'kwtsms' ); ?>
		</a>
	</div>
	<hr class="wp-header-end">

	<form method="post" action="options.php">
		<?php settings_fields( 'kwtsms_otp_integrations_group' ); ?>
		<input type="hidden" name="kwtsms_otp_integrations[_save_section]" value="<?php echo esc_attr( $int_key ); ?>" />

		<!-- ===== Settings Card ===== -->
		<div class="kwtsms-tab-section" style="margin-top:16px;">

			<div class="kwtsms-template-card">
				<div class="kwtsms-template-card-header">
					<h3>
					<?php
					/* translators: %s: integration name (e.g. WooCommerce) */
					echo esc_html( sprintf( __( '%s Integration', 'kwtsms' ), $kwtsms_label ) );
					?>
					</h3>
				</div>
				<p class="description">
					<?php
					/* translators: %s: integration name (e.g. WooCommerce) */
					echo esc_html( sprintf( __( 'Send a confirmation SMS after a %s form is submitted successfully.', 'kwtsms' ), $kwtsms_label ) );
					?>
				</p>

				<table class="form-table" style="margin-top:12px;">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Integration', 'kwtsms' ); ?></th>
						<td>
							<label class="kwtsms-toggle">
								<input type="checkbox"
									name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_enabled_key ); ?>]"
									value="1"
									<?php checked( $kwtsms_is_enabled ); ?> />
								<span>
								<?php
								/* translators: %s: integration name (e.g. WooCommerce) */
								echo esc_html( sprintf( __( 'Enable %s SMS Integration', 'kwtsms' ), $kwtsms_label ) );
								?>
								</span>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Integration Mode', 'kwtsms' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:6px;">
									<input type="radio"
										name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_mode_key ); ?>]"
										value="notification"
										<?php checked( $kwtsms_current_mode, 'notification' ); ?> />
									<strong><?php esc_html_e( 'Notification', 'kwtsms' ); ?></strong>:
									<?php esc_html_e( 'Send a confirmation SMS after form submit.', 'kwtsms' ); ?>
								</label>
								<label style="display:block;">
									<input type="radio"
										name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_mode_key ); ?>]"
										value="gate"
										<?php checked( $kwtsms_current_mode, 'gate' ); ?> />
									<strong><?php esc_html_e( 'OTP Gate', 'kwtsms' ); ?></strong>:
									<?php esc_html_e( 'Block submission until the phone number is verified via OTP.', 'kwtsms' ); ?>
								</label>
							</fieldset>
							<?php if ( 'gate' === $kwtsms_current_mode ) : ?>
							<div class="notice notice-info inline" style="margin:8px 0 0;">
								<p><?php esc_html_e( 'OTP Gate is active. Visitors must verify their phone number before this form submits.', 'kwtsms' ); ?></p>
							</div>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<div class="notice notice-info inline" style="margin:12px 0 0;">
					<p>
						<?php echo esc_html( $kwtsms_cfg['tip'] ); ?>
						<?php if ( ! empty( $kwtsms_cfg['tip_code'] ) ) : ?>
							<code><?php echo esc_html( $kwtsms_cfg['tip_code'] ); ?></code>
						<?php endif; ?>
					</p>
				</div>
			</div>

		</div><!-- /.kwtsms-tab-section[settings] -->

		<!-- ===== SMS Template Card ===== -->
		<div class="kwtsms-tab-section">

			<div class="kwtsms-template-card">
				<div class="kwtsms-template-card-header">
					<h3><?php esc_html_e( 'Form Submission Confirmation', 'kwtsms' ); ?></h3>
				</div>
				<p class="description"><?php esc_html_e( 'Sent to the submitter after a successful form submission.', 'kwtsms' ); ?></p>

				<p class="description" style="margin-top:12px;">
					<strong><?php esc_html_e( 'Placeholders:', 'kwtsms' ); ?></strong>
					<code><?php echo esc_html( $kwtsms_cfg['placeholders'] ); ?></code>
				</p>

				<div class="kwtsms-lang-tabs">
					<div class="kwtsms-tab-nav">
						<button type="button" class="kwtsms-tab-btn is-active" data-tab="en"><?php esc_html_e( 'English', 'kwtsms' ); ?></button>
						<button type="button" class="kwtsms-tab-btn" data-tab="ar"><?php esc_html_e( 'Arabic', 'kwtsms' ); ?></button>
					</div>
					<div class="kwtsms-tab-pane" data-tab="en">
						<div class="kwtsms-textarea-wrap">
							<textarea
								name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_tpl_key ); ?>][en]"
								id="int_<?php echo esc_attr( $kwtsms_tpl_key ); ?>_en"
								class="large-text kwtsms-sms-textarea"
								rows="3"
								dir="ltr"
								data-lang="en"
							><?php echo esc_textarea( $kwtsms_tpl['en'] ); ?></textarea>
							<div class="kwtsms-char-counter" data-target="int_<?php echo esc_attr( $kwtsms_tpl_key ); ?>_en">
								<span class="kwtsms-char-count">0</span> <?php esc_html_e( 'characters', 'kwtsms' ); ?>
								&middot; <span class="kwtsms-page-count">1</span> <?php esc_html_e( 'SMS page(s)', 'kwtsms' ); ?>
							</div>
						</div>
					</div>
					<div class="kwtsms-tab-pane" data-tab="ar" style="display:none;">
						<div class="kwtsms-textarea-wrap">
							<textarea
								name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_tpl_key ); ?>][ar]"
								id="int_<?php echo esc_attr( $kwtsms_tpl_key ); ?>_ar"
								class="large-text kwtsms-sms-textarea"
								rows="3"
								dir="rtl"
								data-lang="ar"
							><?php echo esc_textarea( $kwtsms_tpl['ar'] ); ?></textarea>
							<div class="kwtsms-char-counter" data-target="int_<?php echo esc_attr( $kwtsms_tpl_key ); ?>_ar">
								<span class="kwtsms-char-count">0</span> <?php esc_html_e( 'characters', 'kwtsms' ); ?>
								&middot; <span class="kwtsms-page-count">1</span> <?php esc_html_e( 'SMS page(s)', 'kwtsms' ); ?>
							</div>
						</div>
					</div>
				</div>
				<div class="kwtsms-reset-wrap" style="margin-top:8px;">
					<button type="button" class="button kwtsms-reset-template"
						data-key="<?php echo esc_attr( $kwtsms_tpl_key ); ?>">
						&#8635; <?php esc_html_e( 'Reset to Default', 'kwtsms' ); ?>
					</button>
				</div>
			</div>

		</div><!-- /.kwtsms-tab-section[template] -->

		<?php submit_button( __( 'Save Settings', 'kwtsms' ), 'primary kwtsms-save-btn' ); ?>

	</form>

</div><!-- /.kwtsms-admin-wrap -->
