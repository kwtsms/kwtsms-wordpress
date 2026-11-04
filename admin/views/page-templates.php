<?php
/**
 * Admin View: SMS Templates Page.
 *
 * Pure JS tabs (no page reload). All template sections live in one form;
 * switching tabs only toggles visibility. Save Templates persists all
 * three templates at once.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.
$kwtsms_templates = $this->plugin->settings->get_all_templates();

$kwtsms_template_labels = array(
	'login_otp'   => __( 'Login OTP', 'kwtsms' ),
	'reset_otp'   => __( 'Password Reset OTP', 'kwtsms' ),
	'welcome_sms' => __( 'Welcome SMS', 'kwtsms' ),
);

$kwtsms_template_descriptions = array(
	'login_otp'   => __( 'Sent when a user requests a login OTP code.', 'kwtsms' ),
	'reset_otp'   => __( 'Sent when a user requests a password reset via OTP.', 'kwtsms' ),
	'welcome_sms' => __( 'Sent after a new user account is created. (Optional)', 'kwtsms' ),
);

$kwtsms_template_placeholders = array(
	'login_otp'   => array(
		'{otp}'            => __( 'The generated OTP code', 'kwtsms' ),
		'{site_name}'      => __( 'Your WordPress site name', 'kwtsms' ),
		'{expiry_minutes}' => __( 'OTP validity period in minutes', 'kwtsms' ),
	),
	'reset_otp'   => array(
		'{otp}'            => __( 'The generated OTP code', 'kwtsms' ),
		'{site_name}'      => __( 'Your WordPress site name', 'kwtsms' ),
		'{expiry_minutes}' => __( 'OTP validity period in minutes', 'kwtsms' ),
	),
	'welcome_sms' => array(
		'{name}'      => __( 'User display name', 'kwtsms' ),
		'{site_name}' => __( 'Your WordPress site name', 'kwtsms' ),
	),
);
?>
<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php esc_html_e( 'SMS Templates', 'kwtsms' ); ?></h1>
	</div>
	<hr class="wp-header-end">

	<!-- JS Tab navigation (no page reload) -->
	<nav class="nav-tab-wrapper" id="kwtsms-tpl-tabs">
		<?php
		$kwtsms_first = true;
		foreach ( $kwtsms_template_labels as $kwtsms_key => $kwtsms_label ) :
			?>
		<a href="#<?php echo esc_attr( $kwtsms_key ); ?>"
			class="nav-tab<?php echo $kwtsms_first ? ' nav-tab-active' : ''; ?>"
			data-kwtsms-tab="<?php echo esc_attr( $kwtsms_key ); ?>">
			<?php echo esc_html( $kwtsms_label ); ?>
		</a>
			<?php
			$kwtsms_first = false;
		endforeach;
		?>
	</nav>

	<form method="post" action="options.php">
		<?php settings_fields( 'kwtsms_otp_templates_group' ); ?>

		<?php
		$kwtsms_first_section = true;
		foreach ( $kwtsms_template_labels as $kwtsms_key => $kwtsms_label ) :
			$kwtsms_tpl = $kwtsms_templates[ $kwtsms_key ] ?? array(
				'enabled' => 0,
				'en'      => '',
				'ar'      => '',
			);
			?>
		<div class="kwtsms-tab-section" data-kwtsms-panel="<?php echo esc_attr( $kwtsms_key ); ?>"<?php echo $kwtsms_first_section ? ' style="margin-top:16px;"' : ' style="display:none;"'; ?>>

			<div class="kwtsms-placeholder-help">
				<strong><?php esc_html_e( 'Available placeholders:', 'kwtsms' ); ?></strong>
				<ul style="margin:6px 0 0 16px;list-style:disc;">
					<?php foreach ( $kwtsms_template_placeholders[ $kwtsms_key ] as $kwtsms_placeholder => $kwtsms_desc ) : ?>
					<li>
						<span class="kwtsms-placeholder-tag"><?php echo esc_html( $kwtsms_placeholder ); ?></span>
						<span class="kwtsms-placeholder-desc"><?php echo esc_html( $kwtsms_desc ); ?></span>
					</li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div class="kwtsms-template-card">
				<div class="kwtsms-template-card-header">
					<h3><?php echo esc_html( $kwtsms_label ); ?></h3>
				</div>
				<p class="description"><?php echo esc_html( $kwtsms_template_descriptions[ $kwtsms_key ] ); ?></p>

				<div class="kwtsms-lang-tabs">
					<div class="kwtsms-tab-nav">
						<button type="button" class="kwtsms-tab-btn is-active" data-tab="en"><?php esc_html_e( 'English', 'kwtsms' ); ?></button>
						<button type="button" class="kwtsms-tab-btn" data-tab="ar"><?php esc_html_e( 'Arabic', 'kwtsms' ); ?></button>
					</div>
					<div class="kwtsms-tab-pane" data-tab="en">
						<div class="kwtsms-textarea-wrap">
							<textarea
								name="kwtsms_otp_templates[<?php echo esc_attr( $kwtsms_key ); ?>][en]"
								id="tpl_<?php echo esc_attr( $kwtsms_key ); ?>_en"
								class="large-text kwtsms-sms-textarea"
								rows="3"
								dir="ltr"
								data-lang="en"
							><?php echo esc_textarea( $kwtsms_tpl['en'] ); ?></textarea>
							<div class="kwtsms-char-counter" data-target="tpl_<?php echo esc_attr( $kwtsms_key ); ?>_en">
								<span class="kwtsms-char-count">0</span> <?php esc_html_e( 'characters', 'kwtsms' ); ?>
								&middot; <span class="kwtsms-page-count">1</span> <?php esc_html_e( 'SMS page(s)', 'kwtsms' ); ?>
							</div>
						</div>
					</div>
					<div class="kwtsms-tab-pane" data-tab="ar" style="display:none;">
						<div class="kwtsms-textarea-wrap">
							<textarea
								name="kwtsms_otp_templates[<?php echo esc_attr( $kwtsms_key ); ?>][ar]"
								id="tpl_<?php echo esc_attr( $kwtsms_key ); ?>_ar"
								class="large-text kwtsms-sms-textarea"
								rows="3"
								dir="rtl"
								data-lang="ar"
							><?php echo esc_textarea( $kwtsms_tpl['ar'] ); ?></textarea>
							<div class="kwtsms-char-counter" data-target="tpl_<?php echo esc_attr( $kwtsms_key ); ?>_ar">
								<span class="kwtsms-char-count">0</span> <?php esc_html_e( 'characters', 'kwtsms' ); ?>
								&middot; <span class="kwtsms-page-count">1</span> <?php esc_html_e( 'SMS page(s)', 'kwtsms' ); ?>
							</div>
						</div>
					</div>
				</div>
				<div class="kwtsms-reset-wrap" style="margin-top:8px;">
					<button type="button" class="button kwtsms-reset-template"
						data-key="<?php echo esc_attr( $kwtsms_key ); ?>">
						&#8635; <?php esc_html_e( 'Reset to Default', 'kwtsms' ); ?>
					</button>
				</div>
			</div>
		</div>
			<?php
			$kwtsms_first_section = false;
		endforeach;
		?>

		<?php submit_button( __( 'Save Templates', 'kwtsms' ), 'primary kwtsms-save-btn' ); ?>
	</form>
</div>

<?php
// Inline JS for tab switching (uses wp_add_inline_script pattern via PHP output buffer).
// 2026-11-04 21:53 +03: src false, not '' (PHPStan with current WP stubs, CI red since today; inline-only handle).
wp_register_script( 'kwtsms-tpl-tabs', false, array(), KWTSMS_OTP_VERSION, true );
wp_enqueue_script( 'kwtsms-tpl-tabs' );
wp_add_inline_script(
	'kwtsms-tpl-tabs',
	'(function(){' .
	'var tabs=document.querySelectorAll("#kwtsms-tpl-tabs [data-kwtsms-tab]");' .
	'var panels=document.querySelectorAll("[data-kwtsms-panel]");' .
	'function activate(key){' .
		'tabs.forEach(function(t){t.classList.toggle("nav-tab-active",t.getAttribute("data-kwtsms-tab")===key);});' .
		'panels.forEach(function(p){' .
			'if(p.getAttribute("data-kwtsms-panel")===key){p.style.display="";p.style.marginTop="16px";}' .
			'else{p.style.display="none";}' .
		'});' .
		'window.location.hash=key;' .
	'}' .
	'tabs.forEach(function(t){' .
		't.addEventListener("click",function(e){e.preventDefault();activate(this.getAttribute("data-kwtsms-tab"));});' .
	'});' .
	'var hash=window.location.hash.replace("#","");' .
	'if(hash&&document.querySelector("[data-kwtsms-panel=\""+hash+"\"]")){activate(hash);}' .
	'})();'
);
?>
