<?php
/**
 * Admin View: Admin Alerts Settings Page.
 *
 * Renders per-event toggles, admin phone numbers, and bilingual message
 * templates (EN / AR via language tabs). Uses the WordPress Settings API,
 * saved to kwtsms_otp_alerts.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.
$kwtsms_settings = $this->plugin->settings;
// array_replace_recursive merges nested template arrays (en/ar) correctly.
// The + operator only works for flat arrays; alerts has nested tpl_* sub-arrays.
$kwtsms_alerts = array_replace_recursive( KwtSMS_Settings::DEFAULTS['alerts'], (array) $kwtsms_settings->get( 'alerts' ) );

$kwtsms_events = array(
	'user_register'  => __( 'New User Registered', 'kwtsms' ),
	'wp_login'       => __( 'User Login', 'kwtsms' ),
	'post_published' => __( 'Post Published', 'kwtsms' ),
	'comment_posted' => __( 'Comment Posted', 'kwtsms' ),
	'core_update'    => __( 'WordPress Core Updated', 'kwtsms' ),
);

$kwtsms_tpl_placeholders = array(
	'tpl_user_register'  => '{site_name}, {username}, {email}',
	'tpl_wp_login'       => '{site_name}, {username}',
	'tpl_post_published' => '{site_name}, {post_title}',
	'tpl_comment_posted' => '{site_name}, {post_title}, {author}',
	'tpl_core_update'    => '{site_name}, {version}',
);
?>
<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php esc_html_e( 'Admin Alerts', 'kwtsms' ); ?></h1>
	</div>
	<hr class="wp-header-end">

	<form method="post" action="options.php">
		<?php settings_fields( 'kwtsms_otp_alerts_group' ); ?>

		<!-- ===== Admin Phones ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-phone"></span> <?php esc_html_e( 'Recipient Phone Numbers', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="kwtsms-admin-phones"><?php esc_html_e( 'Admin Phone Numbers', 'kwtsms' ); ?></label>
				</th>
				<td>
					<input type="text" id="kwtsms-admin-phones"
						name="kwtsms_otp_alerts[admin_phones]"
						value="<?php echo esc_attr( $kwtsms_alerts['admin_phones'] ); ?>"
						class="regular-text"
						placeholder="96598765432, 96512345678">
					<p class="description"><?php esc_html_e( 'Comma-separated phone numbers with country code. All enabled alert types are sent to every number listed here.', 'kwtsms' ); ?></p>
				</td>
			</tr>
		</table>
		</div>
		</div>

		<!-- ===== Event Alerts ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-bell"></span> <?php esc_html_e( 'WordPress Event Alerts', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<p style="margin-top:0;margin-bottom:16px;color:#555;font-size:13px;">
			<?php esc_html_e( 'Enable or disable each event alert. Configure the message text in the Templates section below.', 'kwtsms' ); ?>
		</p>
		<table class="form-table" role="presentation">
			<?php foreach ( $kwtsms_events as $kwtsms_event_key => $kwtsms_event_label ) : ?>
			<tr>
				<th scope="row"><?php echo esc_html( $kwtsms_event_label ); ?></th>
				<td>
					<label class="kwtsms-toggle">
						<input type="checkbox"
							name="kwtsms_otp_alerts[<?php echo esc_attr( $kwtsms_event_key ); ?>]"
							value="1"
							<?php checked( ! empty( $kwtsms_alerts[ $kwtsms_event_key ] ) ); ?>>
						<?php esc_html_e( 'Enabled', 'kwtsms' ); ?>
					</label>
				</td>
			</tr>
			<?php endforeach; ?>
		</table>
		</div>
		</div>

		<!-- ===== Alert Templates (JS tabs) ===== -->
		<div class="kwtsms-settings-card">
		<div class="kwtsms-settings-card-header">
			<h3><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'SMS Templates', 'kwtsms' ); ?></h3>
		</div>
		<div class="kwtsms-settings-card-body">
		<p style="margin-top:0;margin-bottom:12px;color:#555;font-size:13px;">
			<?php esc_html_e( 'Customise the SMS message for each event in English and Arabic.', 'kwtsms' ); ?>
		</p>

		<nav class="nav-tab-wrapper" id="kwtsms-alert-tpl-tabs">
			<?php
			$kwtsms_alert_first = true;
			foreach ( $kwtsms_events as $kwtsms_event_key => $kwtsms_event_label ) :
				?>
			<a href="#<?php echo esc_attr( $kwtsms_event_key ); ?>"
				class="nav-tab<?php echo $kwtsms_alert_first ? ' nav-tab-active' : ''; ?>"
				data-kwtsms-alert-tab="<?php echo esc_attr( $kwtsms_event_key ); ?>">
				<?php echo esc_html( $kwtsms_event_label ); ?>
			</a>
				<?php
				$kwtsms_alert_first = false;
			endforeach;
			?>
		</nav>

		<?php
		$kwtsms_alert_first_panel = true;
		foreach ( $kwtsms_events as $kwtsms_event_key => $kwtsms_event_label ) :
			$kwtsms_tpl_key     = 'tpl_' . $kwtsms_event_key;
			$kwtsms_tpl         = is_array( $kwtsms_alerts[ $kwtsms_tpl_key ] ) ? $kwtsms_alerts[ $kwtsms_tpl_key ] : array(
				'en' => '',
				'ar' => '',
			);
			$kwtsms_default_tpl = KwtSMS_Settings::DEFAULTS['alerts'][ $kwtsms_tpl_key ] ?? array(
				'en' => '',
				'ar' => '',
			);
			$kwtsms_en_id       = 'alerts_' . $kwtsms_event_key . '_en';
			$kwtsms_ar_id       = 'alerts_' . $kwtsms_event_key . '_ar';
			?>
		<div class="kwtsms-alert-tpl-panel" data-kwtsms-alert-panel="<?php echo esc_attr( $kwtsms_event_key ); ?>"<?php echo $kwtsms_alert_first_panel ? ' style="margin-top:16px;"' : ' style="display:none;"'; ?>>
		<div class="kwtsms-template-card">
			<div class="kwtsms-template-card-header">
				<h3><?php echo esc_html( $kwtsms_event_label ); ?></h3>
			</div>

			<p class="description" style="margin:0 0 12px;">
				<?php
				printf(
					/* translators: %s: comma-separated list of placeholder names */
					esc_html__( 'Available placeholders: %s', 'kwtsms' ),
					'<code>' . esc_html( $kwtsms_tpl_placeholders[ $kwtsms_tpl_key ] ) . '</code>'
				);
				?>
			</p>

			<div class="kwtsms-lang-tabs">
				<div class="kwtsms-tab-nav">
					<button type="button" class="kwtsms-tab-btn is-active" data-tab="en"><?php esc_html_e( 'English', 'kwtsms' ); ?></button>
					<button type="button" class="kwtsms-tab-btn" data-tab="ar"><?php esc_html_e( 'Arabic', 'kwtsms' ); ?></button>
				</div>
				<div class="kwtsms-tab-pane" data-tab="en">
					<div class="kwtsms-textarea-wrap">
						<textarea
							name="kwtsms_otp_alerts[<?php echo esc_attr( $kwtsms_tpl_key ); ?>][en]"
							id="<?php echo esc_attr( $kwtsms_en_id ); ?>"
							class="large-text kwtsms-sms-textarea"
							rows="3"
							dir="ltr"
							data-lang="en"
						><?php echo esc_textarea( $kwtsms_tpl['en'] ? $kwtsms_tpl['en'] : $kwtsms_default_tpl['en'] ); ?></textarea>
						<div class="kwtsms-char-counter" data-target="<?php echo esc_attr( $kwtsms_en_id ); ?>">
							<span class="kwtsms-char-count">0</span> <?php esc_html_e( 'characters', 'kwtsms' ); ?>
							&middot; <span class="kwtsms-page-count">1</span> <?php esc_html_e( 'SMS page(s)', 'kwtsms' ); ?>
						</div>
					</div>
				</div>
				<div class="kwtsms-tab-pane" data-tab="ar" style="display:none;">
					<div class="kwtsms-textarea-wrap">
						<textarea
							name="kwtsms_otp_alerts[<?php echo esc_attr( $kwtsms_tpl_key ); ?>][ar]"
							id="<?php echo esc_attr( $kwtsms_ar_id ); ?>"
							class="large-text kwtsms-sms-textarea"
							rows="3"
							dir="rtl"
							data-lang="ar"
						><?php echo esc_textarea( $kwtsms_tpl['ar'] ? $kwtsms_tpl['ar'] : $kwtsms_default_tpl['ar'] ); ?></textarea>
						<div class="kwtsms-char-counter" data-target="<?php echo esc_attr( $kwtsms_ar_id ); ?>">
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
		</div>
			<?php
			$kwtsms_alert_first_panel = false;
		endforeach;
		?>

		<?php
		// 2026-11-04 21:53 +03: src false, not '' (PHPStan with current WP stubs, CI red since today; inline-only handle).
		wp_register_script( 'kwtsms-alert-tpl-tabs', false, array(), KWTSMS_OTP_VERSION, true );
		wp_enqueue_script( 'kwtsms-alert-tpl-tabs' );
		wp_add_inline_script(
			'kwtsms-alert-tpl-tabs',
			'(function(){' .
			'var tabs=document.querySelectorAll("#kwtsms-alert-tpl-tabs [data-kwtsms-alert-tab]");' .
			'var panels=document.querySelectorAll("[data-kwtsms-alert-panel]");' .
			'function activate(key){' .
				'tabs.forEach(function(t){t.classList.toggle("nav-tab-active",t.getAttribute("data-kwtsms-alert-tab")===key);});' .
				'panels.forEach(function(p){' .
					'if(p.getAttribute("data-kwtsms-alert-panel")===key){p.style.display="";p.style.marginTop="16px";}' .
					'else{p.style.display="none";}' .
				'});' .
				'window.location.hash=key;' .
			'}' .
			'tabs.forEach(function(t){' .
				't.addEventListener("click",function(e){e.preventDefault();activate(this.getAttribute("data-kwtsms-alert-tab"));});' .
			'});' .
			'var hash=window.location.hash.replace("#","");' .
			'if(hash&&document.querySelector("[data-kwtsms-alert-panel=\""+hash+"\"]")){activate(hash);}' .
			'})();'
		);
		?>
		</div>
		</div>

		<?php submit_button( __( 'Save Alert Settings', 'kwtsms' ), 'primary kwtsms-save-btn' ); ?>
	</form>

</div><!-- /.kwtsms-admin-wrap -->
