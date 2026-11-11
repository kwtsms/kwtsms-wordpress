<?php
/**
 * Admin View: Help & Support Page.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Getting started guide, feature overview, troubleshooting, and support links.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.
$kwtsms_settings             = $this->plugin->settings;
$kwtsms_credentials_verified = (bool) $kwtsms_settings->get( 'gateway.credentials_verified', false );
$kwtsms_has_credentials      = $kwtsms_credentials_verified
	&& ! empty( $kwtsms_settings->get( 'gateway.api_username', '' ) )
	&& ! empty( $kwtsms_settings->get( 'gateway.api_password', '' ) );
$kwtsms_has_sender           = $kwtsms_credentials_verified && ! empty( $kwtsms_settings->get( 'gateway.sender_id', '' ) );
$kwtsms_test_mode            = (bool) $kwtsms_settings->get( 'gateway.test_mode', 1 );
$kwtsms_debug_logging        = (bool) $kwtsms_settings->get( 'general.debug_logging', 0 );
// Relative content path for display: uses wp_upload_dir() per WP.org guidelines.
$kwtsms_upload_dir  = wp_upload_dir();
$kwtsms_content_dir = basename( dirname( $kwtsms_upload_dir['basedir'] ) );
?>
<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php esc_html_e( 'Help &amp; Support', 'kwtsms' ); ?></h1>
	</div>
	<hr class="wp-header-end">

	<!-- ===== Plugin Status ===== -->
	<div class="kwtsms-settings-card">
	<div class="kwtsms-settings-card-header">
		<h3><span class="dashicons dashicons-dashboard"></span> <?php esc_html_e( 'Current Status', 'kwtsms' ); ?></h3>
	</div>
	<div class="kwtsms-settings-card-body">
		<table style="border-collapse:collapse;width:100%;font-size:13px;">
			<tr>
				<td style="padding:6px 0;width:200px;"><strong><?php esc_html_e( 'API Credentials', 'kwtsms' ); ?></strong></td>
				<td>
					<?php if ( $kwtsms_has_credentials ) : ?>
					<span style="color:#46b450;">&#10003; <?php esc_html_e( 'Configured', 'kwtsms' ); ?></span>
					<?php else : ?>
					<span style="color:#dc3232;">&#10007; <?php esc_html_e( 'Not configured', 'kwtsms' ); ?></span>,
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-gateway' ) ); ?>"><?php esc_html_e( 'Go to Gateway Settings ', 'kwtsms' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td style="padding:6px 0;"><strong><?php esc_html_e( 'Sender ID', 'kwtsms' ); ?></strong></td>
				<td>
					<?php if ( $kwtsms_has_sender ) : ?>
					<span style="color:#46b450;">&#10003; <?php echo esc_html( $kwtsms_settings->get( 'gateway.sender_id', '' ) ); ?></span>
					<?php else : ?>
					<span style="color:#dc3232;">&#10007; <?php esc_html_e( 'Not selected', 'kwtsms' ); ?></span>,
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-gateway' ) ); ?>"><?php esc_html_e( 'Go to Gateway Settings ', 'kwtsms' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td style="padding:6px 0;"><strong><?php esc_html_e( 'Test Mode', 'kwtsms' ); ?></strong></td>
				<td>
					<?php if ( $kwtsms_test_mode ) : ?>
					<span style="color:#FFA200;font-weight:600;"><?php esc_html_e( 'ON, no real SMS is sent', 'kwtsms' ); ?></span>
					<?php else : ?>
					<span style="color:#46b450;"><?php esc_html_e( 'OFF, live SMS delivery', 'kwtsms' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td style="padding:6px 0;"><strong><?php esc_html_e( 'Debug Logging', 'kwtsms' ); ?></strong></td>
				<td>
					<?php if ( $kwtsms_debug_logging ) : ?>
					<span style="color:#FFA200;font-weight:600;"><?php esc_html_e( 'ON', 'kwtsms' ); ?></span>,
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-logs&tab=debug_log' ) ); ?>"><?php echo esc_html( $kwtsms_content_dir . '/uploads/kwtsms-logs/' ); ?></a>
					<?php else : ?>
					<span style="color:#757575;"><?php esc_html_e( 'OFF', 'kwtsms' ); ?></span>,
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp' ) ); ?>"><?php esc_html_e( 'Enable in General Settings ', 'kwtsms' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td style="padding:6px 0;"><strong><?php esc_html_e( 'Account Balance', 'kwtsms' ); ?></strong></td>
				<td>
					<?php
					$kwtsms_bal_available = $kwtsms_settings->get( 'gateway.balance_available', null );
					$kwtsms_bal_updated   = (int) $kwtsms_settings->get( 'gateway.balance_updated_at', 0 );
					if ( $kwtsms_credentials_verified && null !== $kwtsms_bal_available ) :
						?>
					<span style="color:#46b450;font-weight:600;">
						<?php echo esc_html( number_format( (float) $kwtsms_bal_available, 2 ) ); ?>
					</span>
						<?php if ( $kwtsms_bal_updated > 0 ) : ?>
					<span style="color:#888;font-size:12px;">
							<?php
							printf(
							/* translators: %s: human-readable time difference */
								esc_html__( '(updated %s ago)', 'kwtsms' ),
								esc_html( human_time_diff( $kwtsms_bal_updated, time() ) )
							);
							?>
					</span>
					<?php endif; ?>
					, <a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener" style="font-weight:600;"><?php esc_html_e( 'Recharge/Buy credits ', 'kwtsms' ); ?></a>
					<?php else : ?>
					<span style="color:#888;"><?php esc_html_e( 'Not available, login on the Gateway page first.', 'kwtsms' ); ?></span>,
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-gateway' ) ); ?>"><?php esc_html_e( 'Go to Gateway Settings ', 'kwtsms' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<td style="padding:6px 0;"><strong><?php esc_html_e( 'Plugin Version', 'kwtsms' ); ?></strong></td>
				<td><?php echo esc_html( defined( 'KWTSMS_OTP_VERSION' ) ? KWTSMS_OTP_VERSION : '-' ); ?></td>
			</tr>
		</table>
	</div><!-- .kwtsms-settings-card-body -->
	</div><!-- .kwtsms-settings-card -->

	<!-- ===== Support ===== -->
	<div class="kwtsms-settings-card">
	<div class="kwtsms-settings-card-header">
		<h3><span class="dashicons dashicons-rest-api"></span> <?php esc_html_e( 'Support &amp; Resources', 'kwtsms' ); ?></h3>
	</div>
	<div class="kwtsms-settings-card-body">
		<ul style="font-size:14px;line-height:2;">
			<li>
				<strong><?php esc_html_e( 'kwtSMS FAQ:', 'kwtsms' ); ?></strong>
				<a href="https://www.kwtsms.com/faq/" target="_blank" rel="noopener">kwtsms.com/faq/</a>,
				<?php esc_html_e( 'answers to common questions about credits, sender IDs, OTP, and delivery.', 'kwtsms' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'kwtSMS Support:', 'kwtsms' ); ?></strong>
				<a href="https://www.kwtsms.com/support.html" target="_blank" rel="noopener">kwtsms.com/support.html</a>,
				<?php esc_html_e( 'open a support ticket or browse help articles.', 'kwtsms' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Contact kwtSMS:', 'kwtsms' ); ?></strong>
				<a href="https://www.kwtsms.com/#contact" target="_blank" rel="noopener">kwtsms.com/#contact</a>,
				<?php esc_html_e( 'reach the kwtSMS team directly for Sender ID registration and account issues.', 'kwtsms' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'kwtSMS API Documentation:', 'kwtsms' ); ?></strong>
				<a href="https://www.kwtsms.com/doc/KwtSMS.com_API_Documentation_v41.pdf" target="_blank" rel="noopener">KwtSMS API v4.1 (PDF)</a>
			</li>
			<li>
				<strong><?php esc_html_e( 'kwtSMS Dashboard (balance, coverage, sender IDs):', 'kwtsms' ); ?></strong>
				<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener">kwtsms.com/login</a>
			</li>
			<li>
				<strong><?php esc_html_e( 'kwtSMS Integrations:', 'kwtsms' ); ?></strong>
				<a href="https://www.kwtsms.com/integrations.html" target="_blank" rel="noopener">kwtsms.com/integrations.html</a>,
				<?php esc_html_e( 'other platforms and integrations supported by kwtSMS.', 'kwtsms' ); ?>
			</li>
		</ul>
	</div><!-- .kwtsms-settings-card-body -->
	</div><!-- .kwtsms-settings-card -->


	<!-- ===== Getting Started ===== -->
	<div class="kwtsms-settings-card">
	<div class="kwtsms-settings-card-header">
		<h3><span class="dashicons dashicons-welcome-learn-more"></span> <?php esc_html_e( 'Getting Started', 'kwtsms' ); ?></h3>
	</div>
	<div class="kwtsms-settings-card-body">
	<div style="max-width:800px;">
		<ol style="font-size:14px;line-height:1.8;">
			<li>
				<strong><?php esc_html_e( 'Create a kwtSMS account', 'kwtsms' ); ?></strong><br>
				<?php esc_html_e( 'Go to kwtsms.com, sign up, log in, and request API access. Your API username and password will be provided in your account dashboard.', 'kwtsms' ); ?>
				<a href="https://www.kwtsms.com/signup" target="_blank" rel="noopener"><?php esc_html_e( 'Sign up ', 'kwtsms' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Register a Sender ID', 'kwtsms' ); ?></strong><br>
				<?php esc_html_e( 'A Sender ID is the name or number your recipients see. Apply for one in your kwtSMS dashboard. This is required before sending.', 'kwtsms' ); ?>
				<a href="https://www.kwtsms.com/sender-id-help.html" target="_blank" rel="noopener"><?php esc_html_e( 'Learn more ', 'kwtsms' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Configure Gateway Settings', 'kwtsms' ); ?></strong><br>
				<?php esc_html_e( 'Go to kwtSMS  Gateway. Enter your API username and password, then click "Login" to verify your credentials and load your approved Sender IDs. Select a Sender ID and save.', 'kwtsms' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-gateway' ) ); ?>"><?php esc_html_e( 'Go to Gateway ', 'kwtsms' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Set up General Settings', 'kwtsms' ); ?></strong><br>
				<?php esc_html_e( 'Choose your OTP mode (2FA, Passwordless, or Both), code length, expiry, and default country for the dial-code dropdown.', 'kwtsms' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp' ) ); ?>"><?php esc_html_e( 'Go to General ', 'kwtsms' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Add phone numbers to user profiles', 'kwtsms' ); ?></strong><br>
				<?php esc_html_e( 'Each user must have a phone number in their profile (Users  Edit User  Phone Number).', 'kwtsms' ); ?>
				<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'Without a phone number, 2FA is skipped for that user.', 'kwtsms' ); ?></span>
			</li>
			<li>
				<strong><?php esc_html_e( 'Send a test SMS', 'kwtsms' ); ?></strong><br>
				<?php esc_html_e( 'On the Gateway page, enter a test phone number and click "Send Test SMS". With Test Mode ON, the message is queued in your kwtSMS account but not delivered to the phone. Turn Test Mode OFF for real delivery.', 'kwtsms' ); ?>
			</li>
		</ol>

		<!-- ===== Test Mode & Credits ===== -->
		<div style="background:#fff8e1;border-left:4px solid #FFA200;padding:14px 18px;border-radius:0 4px 4px 0;margin-bottom:24px;font-size:14px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Test Mode and Credits: Important', 'kwtsms' ); ?></h3>
			<p style="margin-top:0;">
				<?php esc_html_e( 'When Test Mode is ON, messages are queued on the kwtSMS servers but never delivered to the recipient\'s phone.', 'kwtsms' ); ?>
				<strong><?php esc_html_e( ' Credits are still deducted', 'kwtsms' ); ?></strong>,
				<?php esc_html_e( 'kwtSMS charges for queued messages even in test mode.', 'kwtsms' ); ?>
			</p>
			<p>
				<?php esc_html_e( 'To recover credits from test messages, log in to your kwtSMS dashboard and delete the queued messages from the outbox queue.', 'kwtsms' ); ?>
				<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener"><?php esc_html_e( 'kwtSMS Dashboard ', 'kwtsms' ); ?></a>
			</p>
			<p style="margin-bottom:0;">
				<strong><?php esc_html_e( 'How to tell:', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'An orange "Test Mode" notice appears at the top of every kwtSMS admin page when active. To disable it, go to Gateway Settings and uncheck Test Mode.', 'kwtsms' ); ?>
			</p>
		</div>

		<!-- ===== Features ===== -->
		<h2><?php esc_html_e( 'Features Overview', 'kwtsms' ); ?></h2>

		<h3><?php esc_html_e( 'OTP Two-Factor Authentication (2FA)', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'After a user enters their password, an OTP code is sent to their registered phone. They must enter the code to complete login. Configure code length (4 or 6 digits), expiry (1 to 30 min), and max wrong attempts in General Settings.', 'kwtsms' ); ?></p>

		<h3><?php esc_html_e( 'Passwordless Login', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'Users can log in with just their phone number, no password needed. An OTP is sent to their phone and they are logged in on success. Enable via OTP Mode  Passwordless or Both. A country-code dropdown with GeoIP pre-selection is shown on the login form.', 'kwtsms' ); ?></p>

		<h3><?php esc_html_e( 'Password Reset via SMS', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'Replaces the default email reset link with an SMS OTP. Users receive a code by SMS to verify their identity, then are taken to the reset form. Enable via General Settings  Enable Password Reset OTP.', 'kwtsms' ); ?></p>

		<h3><?php esc_html_e( 'Per-Role OTP Enforcement', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'Choose which user roles require OTP (e.g. require it for Administrators but skip it for Subscribers). Excluded roles bypass OTP entirely. Configure under General Settings  Authentication.', 'kwtsms' ); ?></p>

		<h3><?php esc_html_e( 'SMS Templates', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'Customise the message text for each event (login, reset, welcome). Placeholders like {otp}, {site_name}, and {expiry_minutes} are replaced automatically. Separate English and Arabic templates are supported.', 'kwtsms' ); ?></p>

		<h3><?php esc_html_e( 'WooCommerce Integration', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'When WooCommerce is active, the plugin can send SMS to customers when order status changes. Supported statuses: Processing, On-Hold (Shipped), Completed, Cancelled, Pending Payment, Refunded, and Failed. Each status has its own configurable template (English + Arabic). Additional features:', 'kwtsms' ); ?></p>
		<ul style="margin-left:20px;font-size:14px;line-height:1.8;">
			<li><?php esc_html_e( 'Admin SMS notifications: send a copy to a store phone number on any status change.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Per-order custom SMS: send a custom message from the order edit screen.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Checkout OTP gate: require phone verification before an order is placed.', 'kwtsms' ); ?></li>
		</ul>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-integrations' ) ); ?>"><?php esc_html_e( 'Integrations Settings ', 'kwtsms' ); ?></a></p>

		<h3><?php esc_html_e( 'Form Integrations (Contact Form 7, WPForms, Ninja Forms)', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'Each form plugin integration supports two modes:', 'kwtsms' ); ?></p>
		<ul style="margin-left:20px;font-size:14px;line-height:1.8;">
			<li><strong><?php esc_html_e( 'Notification mode', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Send a confirmation SMS to the customer after a successful form submission.', 'kwtsms' ); ?></li>
			<li><strong><?php esc_html_e( 'OTP Gate mode', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Block the form submission until the user verifies their phone number with an OTP. An overlay modal appears on submit, asking the user to enter and confirm their phone.', 'kwtsms' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Security', 'kwtsms' ); ?></h3>
		<ul style="margin-left:20px;font-size:14px;line-height:1.8;">
			<li><strong><?php esc_html_e( 'Sliding-window rate limiting', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'OTP requests are limited per phone, per IP, and per account. The sliding-window algorithm prevents gaming at window boundaries.', 'kwtsms' ); ?></li>
			<li><strong><?php esc_html_e( 'Phone blocking list', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Block specific numbers from ever receiving an OTP. Blocked numbers receive a silent success response (anti-enumeration).', 'kwtsms' ); ?></li>
			<li><strong><?php esc_html_e( 'Bot protection', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Optional Google reCAPTCHA v3 or Cloudflare Turnstile on OTP forms.', 'kwtsms' ); ?></li>
			<li><strong><?php esc_html_e( 'Emergency bypass', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Add define( \'KWTSMS_OTP_DISABLED\', true ) to wp-config.php to disable all OTP logic if you are locked out.', 'kwtsms' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Logs', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'The Logs page shows three tabs:', 'kwtsms' ); ?></p>
		<ul style="margin-left:20px;font-size:14px;">
			<li><strong><?php esc_html_e( 'SMS History', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Full unredacted log of all SMS sends (phone, message, status, message ID, gateway result).', 'kwtsms' ); ?></li>
			<li><strong><?php esc_html_e( 'OTP Attempts', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Every verification attempt with result, IP address, and user. Useful for detecting brute-force attacks.', 'kwtsms' ); ?></li>
			<li><strong><?php esc_html_e( 'Debug Log', 'kwtsms' ); ?></strong>: <?php esc_html_e( 'Full API request/response log (visible only when Debug Logging is enabled in General Settings).', 'kwtsms' ); ?></li>
		</ul>

		<h3><?php esc_html_e( 'Allowed Countries', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'In General Settings you can restrict which countries are shown in the dial-code dropdown and accepted for OTP. Default is GCC countries. This prevents OTPs from being sent to unintended regions.', 'kwtsms' ); ?></p>

		<!-- ===== Collecting Phone Numbers ===== -->
		<h2><?php esc_html_e( 'How to Collect Phone Numbers from Users', 'kwtsms' ); ?></h2>
		<p>
			<?php esc_html_e( 'The plugin sends OTP codes and SMS notifications to the phone number stored in each user\'s profile. Without a phone number on file, 2FA is silently bypassed and no SMS is ever sent to that user.', 'kwtsms' ); ?>
			<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'Make sure every user has a phone number before enabling 2FA.', 'kwtsms' ); ?></span>
		</p>
		<p><?php esc_html_e( 'Choose the collection method that matches how your users join your site:', 'kwtsms' ); ?></p>

		<h3><?php esc_html_e( 'Method 1: WooCommerce Registration (recommended for WooCommerce stores)', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'When WooCommerce is active, the plugin automatically adds a Phone Number field to the WooCommerce My Account registration form and to checkout. The number is saved to the user profile on account creation, no extra steps needed.', 'kwtsms' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Enable the WooCommerce integration: Integrations  WooCommerce  Enable WooCommerce SMS Integration.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Phone collection is active automatically on the My Account registration form and checkout page.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Test by registering a new account on /my-account and verifying the phone appears under Users  Edit User  Phone Number.', 'kwtsms' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Method 2: Manual entry by the admin', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'You can add or update a phone number for any existing user directly in the WordPress admin panel.', 'kwtsms' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Go to Users in the WordPress admin menu.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Click Edit under the user\'s name.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Scroll down to the Phone Number field (added by this plugin).', 'kwtsms' ); ?></li>
			<?php /* 2026-11-05 11:21 +03: placeholder 965XXXXXXXX replaces a real-looking Kuwait number, int-testnumber. */ ?>
			<li><?php esc_html_e( 'Enter the number with country code, e.g. 965XXXXXXXX for Kuwait.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Click Update User to save.', 'kwtsms' ); ?></li>
		</ol>

		<h3><?php esc_html_e( 'Method 3: Ask users to update their own profile', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'Users can add their own phone number from the front-end WordPress profile page.', 'kwtsms' ); ?></p>
		<ol>
			<li><?php esc_html_e( 'Direct users to their profile page: /wp-admin/profile.php (or the equivalent front-end profile page if your theme provides one).', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'They will see a Phone Number field. Ask them to enter their number with country code.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'They click Update Profile to save.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Once saved, 2FA and SMS notifications will work for that user on their next login.', 'kwtsms' ); ?></li>
		</ol>

		<div style="background:#e7f5ff;border-left:4px solid #72aee6;padding:14px 18px;border-radius:0 4px 4px 0;margin-bottom:24px;font-size:14px;">
			<strong><?php esc_html_e( 'Tip: Check who is missing a phone number', 'kwtsms' ); ?></strong><br>
			<?php esc_html_e( 'Go to Users in the admin, then look for the Phone Number column. Any user showing "-" or a blank value has no phone on file and will bypass 2FA until one is added.', 'kwtsms' ); ?>
			<a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>"><?php esc_html_e( 'View all users ', 'kwtsms' ); ?></a>
		</div>

		<!-- ===== Styling ===== -->
		<h2><?php esc_html_e( 'Styling &amp; Customisation', 'kwtsms' ); ?></h2>
		<p>
			<?php esc_html_e( 'The OTP and passwordless login pages use the plugin\'s own stylesheet (assets/css/login.css). This stylesheet is intentionally minimal, it uses standard WordPress blue (#2271b1) for interactive elements and inherits the base WordPress login page layout. It does NOT override your theme colours or fonts.', 'kwtsms' ); ?>
		</p>
		<p>
			<?php esc_html_e( 'To customise the appearance, you do NOT need a customisation page. Simply add CSS overrides to your theme\'s Additional CSS (Appearance  Customise  Additional CSS) or your child theme\'s style.css. Key selectors:', 'kwtsms' ); ?>
		</p>
		<ul style="margin-left:20px;font-size:13px;line-height:2;font-family:monospace;background:#f8f8f8;padding:10px 20px;border:1px solid #ddd;border-radius:4px;">
			<li>.kwtsms-otp-wrap: <?php esc_html_e( 'outer container of OTP entry form', 'kwtsms' ); ?></li>
			<li>.kwtsms-form-card: <?php esc_html_e( 'white card around the form', 'kwtsms' ); ?></li>
			<li>.kwtsms-otp-input: <?php esc_html_e( 'the OTP code input field', 'kwtsms' ); ?></li>
			<li>.kwtsms-submit-btn: <?php esc_html_e( 'the Submit / Verify button', 'kwtsms' ); ?></li>
			<li>.kwtsms-phone-group: <?php esc_html_e( 'country code + phone input wrapper', 'kwtsms' ); ?></li>
			<li>.kwtsms-credit: <?php esc_html_e( '"SMS by kwtSMS.com" footer (opt-in, off by default, enabled via General Settings &gt; Show credit link)', 'kwtsms' ); ?></li>
		</ul>
		<p>
			<?php esc_html_e( 'No customisation page is needed. Full CSS control is available through standard WordPress/theme overrides.', 'kwtsms' ); ?>
		</p>

	</div><!-- max-width wrapper -->
	</div><!-- .kwtsms-settings-card-body -->
	</div><!-- .kwtsms-settings-card -->

	<!-- ===== Troubleshooting ===== -->
	<div class="kwtsms-settings-card">
	<div class="kwtsms-settings-card-header">
		<h3><span class="dashicons dashicons-sos"></span> <?php esc_html_e( 'Troubleshooting', 'kwtsms' ); ?></h3>
	</div>
	<div class="kwtsms-settings-card-body">
	<div style="max-width:800px;">

		<h3><?php esc_html_e( 'Messages not being delivered: step by step', 'kwtsms' ); ?></h3>
		<ol style="font-size:14px;line-height:1.9;">
			<li>
				<strong><?php esc_html_e( 'Check whether Test Mode is ON.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'Look for the orange "kwtSMS is in Test Mode" notice at the top of this page. If it appears, messages are being queued but not delivered. Go to Gateway Settings, uncheck Test Mode, and save. Credits are consumed even in test mode, delete queued messages from the kwtSMS dashboard to recover them.', 'kwtsms' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Verify your API credentials.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'On the Gateway page, click Login. If it fails, your API username or password is incorrect. Log in to kwtsms.com  API Settings to get the correct credentials.', 'kwtsms' ); ?>
				<a href="https://www.kwtsms.com/login/" target="_blank" rel="noopener"><?php esc_html_e( 'kwtSMS Dashboard ', 'kwtsms' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Confirm a Sender ID is selected.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'The Sender ID dropdown on the Gateway page must have a selection. If it is empty, click Reload after verifying credentials. Without a Sender ID, no message can be sent.', 'kwtsms' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Check your account balance.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'You need at least 1 credit per message. Your current balance is shown on the Gateway page and in the Current Status table above. Insufficient credits cause error ERR010 or ERR011.', 'kwtsms' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Check the SMS History log.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'Go to Logs  SMS History. If the send attempt is listed with Status: Failed, the Result column shows the API error code. Match it to the error table below.', 'kwtsms' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-logs&tab=sms_history' ) ); ?>"><?php esc_html_e( 'SMS History ', 'kwtsms' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Enable Debug Logging for full API details.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'Go to General  Developer Tools and turn Debug Logging ON. Trigger a send again. Then view the full request and response (including the exact error from kwtSMS) in the Debug Log.', 'kwtsms' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-logs&tab=debug_log' ) ); ?>"><?php esc_html_e( 'Debug Log ', 'kwtsms' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Check destination coverage.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'The SMS Coverage table on the Gateway page lists which countries are supported. If the destination country is not covered or shows as inactive, add coverage via your kwtSMS dashboard.', 'kwtsms' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Run a Gateway Test SMS.', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'On the Gateway page, enter a phone number including country code and click "Send Test SMS". This isolates whether the issue is the API connection or a specific trigger in the plugin.', 'kwtsms' ); ?>
			</li>
		</ol>

		<!-- KWT-SMS promotional sender ID warning -->
		<div style="background:#fef0f0;border-left:4px solid #d63638;padding:14px 18px;border-radius:0 4px 4px 0;margin:16px 0 24px;font-size:14px;">
			<h3 style="margin-top:0;color:#d63638;"><?php esc_html_e( '⛔ KWT-SMS Promotional Sender ID: For Testing Only. Do Not Use in Production', 'kwtsms' ); ?></h3>
			<p style="margin-top:0;">
				<?php esc_html_e( 'The shared "KWT-SMS" sender ID is a public promotional channel. It is only suitable for initial testing while you are setting up the plugin. It must not be used in a live production site.', 'kwtsms' ); ?>
			</p>
			<ul style="margin-left:20px;line-height:1.9;margin-bottom:12px;">
				<li>
					<strong><?php esc_html_e( 'Severe delivery delays:', 'kwtsms' ); ?></strong>
					<?php esc_html_e( 'Promotional sender IDs are lower priority by design. Delivery can take 120 seconds or more, far too slow for OTP codes that users expect in seconds.', 'kwtsms' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Virgin Mobile (Zain-MVNO) numbers never receive the message:', 'kwtsms' ); ?></strong>
					<?php esc_html_e( 'Kuwait numbers starting with 4 (Virgin subscribers) do not receive messages from promotional sender IDs at all. Those users will never get an OTP.', 'kwtsms' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Do Not Disturb (DND) filters:', 'kwtsms' ); ?></strong>
					<?php esc_html_e( 'Promotional messages are blocked for users who have enabled DND on their number, causing lost credits and undelivered OTPs.', 'kwtsms' ); ?>
				</li>
				<li>
					<strong><?php esc_html_e( 'Brand damage:', 'kwtsms' ); ?></strong>
					<?php esc_html_e( 'Recipients see "KWT-SMS" as the sender, not your business name. This reduces trust and makes messages look like spam.', 'kwtsms' ); ?>
				</li>
			</ul>
			<p style="margin-bottom:4px;">
				<strong><?php esc_html_e( 'What to do:', 'kwtsms' ); ?></strong>
				<?php esc_html_e( 'Register a private alphanumeric Sender ID in your kwtSMS account using your brand name (e.g. "MyShop"). Private sender IDs have fast delivery, reach all Kuwaiti operators including Virgin, bypass DND filters, and build customer trust.', 'kwtsms' ); ?>
			</p>
			<p style="margin-bottom:0;">
				<a href="https://www.kwtsms.com/faq/must-have-senderid-for-otp.html" target="_blank" rel="noopener"><?php esc_html_e( 'Why you need a private Sender ID for OTP ', 'kwtsms' ); ?></a>
				&nbsp;&middot;&nbsp;
				<a href="https://www.kwtsms.com/#contact" target="_blank" rel="noopener"><?php esc_html_e( 'Contact kwtSMS to register your Sender ID ', 'kwtsms' ); ?></a>
			</p>
		</div>

		<!-- Kuwait DLR note -->
		<h3><?php esc_html_e( 'Message shows as Sent but was never delivered (Kuwait numbers)', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'Delivery Reports (DLR) are not available for messages sent to Kuwait mobile numbers. The API returns "OK" (sent) as soon as the message is handed off to the operator, but there is no delivery confirmation for Kuwait. If a customer says they did not receive the SMS and the Logs page shows "Sent", check:', 'kwtsms' ); ?></p>
		<ul style="margin-left:20px;font-size:14px;line-height:1.8;">
			<li><?php esc_html_e( 'Is the customer using Virgin (Zain-MVNO)? If so, switch from KWT-SMS to a private sender ID.', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Check the kwtSMS API error log in your kwtSMS account dashboard (API  Error Log).', 'kwtsms' ); ?></li>
			<li><?php esc_html_e( 'Use the Debug Log (enable in General  Developer Tools) for the full API request and response.', 'kwtsms' ); ?></li>
		</ul>

		<!-- International coverage -->
		<h3><?php esc_html_e( 'Messages not reaching international numbers', 'kwtsms' ); ?></h3>
		<p>
			<?php esc_html_e( 'International SMS coverage is disabled by default on all new kwtSMS accounts. To enable coverage for countries outside Kuwait, contact kwtSMS support.', 'kwtsms' ); ?>
			<a href="https://www.kwtsms.com/#contact" target="_blank" rel="noopener"><?php esc_html_e( 'kwtSMS Contact ', 'kwtsms' ); ?></a>
		</p>
		<p><?php esc_html_e( 'The Gateway page shows your current coverage list. If a destination country is missing, it has not been enabled.', 'kwtsms' ); ?></p>

		<!-- API rate limiting -->
		<h3><?php esc_html_e( 'API requests being blocked (rate limit)', 'kwtsms' ); ?></h3>
		<p>
			<?php esc_html_e( 'The kwtSMS API allows a maximum of 10 requests per second from a single IP address. Exceeding this limit causes your server\'s IP to be temporarily blocked by kwtSMS. Under normal use this limit is never reached, each OTP send is one request. If you are running bulk sends or automated tests, introduce a delay between requests.', 'kwtsms' ); ?>
		</p>

		<h3><?php esc_html_e( 'Users get "Session expired" error', 'kwtsms' ); ?></h3>
		<p><?php esc_html_e( 'The OTP session is stored as a 15-minute transient. This can be cleared by object cache flushes or plugin conflicts. Check that no caching plugin is clearing transients too aggressively.', 'kwtsms' ); ?></p>

		<h3 style="color:#dc3232;"><?php esc_html_e( '⚠ Admin Lockout: Cannot Receive OTP', 'kwtsms' ); ?></h3>
		<div style="background:#fff8e1;border-left:4px solid #FFA200;padding:12px 16px;border-radius:0 4px 4px 0;margin-bottom:16px;font-size:14px;">
			<p style="margin-top:0;">
				<?php esc_html_e( 'If your admin account has a phone number set and you cannot receive the OTP (lost phone, changed number, gateway issue), you will be locked out of WordPress. Use one of the emergency bypass methods below.', 'kwtsms' ); ?>
			</p>

			<p><strong><?php esc_html_e( 'Option 1: Emergency bypass constant (fastest)', 'kwtsms' ); ?></strong></p>
			<p><?php esc_html_e( 'Add this line to your wp-config.php (before the "stop editing" comment):', 'kwtsms' ); ?></p>
			<pre style="background:#fff;border:1px solid #ddd;padding:8px 12px;font-size:13px;overflow-x:auto;">define( 'KWTSMS_OTP_DISABLED', true );</pre>
			<p><?php esc_html_e( 'This completely disables all OTP logic. Log in normally, fix your phone number or gateway, then remove the line.', 'kwtsms' ); ?></p>

			<p><strong><?php esc_html_e( 'Option 2: Remove phone via WP-CLI', 'kwtsms' ); ?></strong></p>
			<pre style="background:#fff;border:1px solid #ddd;padding:8px 12px;font-size:13px;overflow-x:auto;">wp user meta delete &lt;user_id&gt; kwtsms_phone</pre>
			<p><?php esc_html_e( 'Replace &lt;user_id&gt; with your user ID (usually 1 for the first admin). This removes the phone from your account so OTP is skipped on next login.', 'kwtsms' ); ?></p>

			<p><strong><?php esc_html_e( 'Option 3: Disable the plugin via SFTP/FTP', 'kwtsms' ); ?></strong></p>
			<p style="margin-bottom:0;">
				<?php esc_html_e( 'Connect via SFTP/FTP and rename the plugin folder from wp-kwtsms to _wp-kwtsms. WordPress will deactivate the plugin automatically, allowing normal login. Rename it back to re-enable.', 'kwtsms' ); ?>
			</p>
		</div>

		<h3><?php esc_html_e( 'Where is the debug log?', 'kwtsms' ); ?></h3>
		<p>
			<?php
			$kwtsms_debug_log_url = admin_url( 'admin.php?page=kwtsms-otp-logs&tab=debug_log' );
			printf(
				/* translators: 1: path to debug log file, 2: link to Logs page */
				esc_html__( 'When Debug Logging is enabled, all API activity is recorded in %1$s. You can view, scroll, and download it directly from %2$s.', 'kwtsms' ),
				'<code>' . esc_html( $kwtsms_content_dir . '/uploads/kwtsms-logs/' ) . '</code>',
				'<a href="' . esc_url( $kwtsms_debug_log_url ) . '">' . esc_html__( 'Logs  Debug Log', 'kwtsms' ) . '</a>'
			);
			?>
		</p>

	</div><!-- max-width wrapper -->
	</div><!-- .kwtsms-settings-card-body -->
	</div><!-- .kwtsms-settings-card -->

</div>
