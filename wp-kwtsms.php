<?php
/**
 * Plugin Name:       kwtSMS: OTP & SMS Notifications
 * Plugin URI:        https://www.kwtsms.com/integrations.html
 * Description:       Replace passwords with SMS codes, send WooCommerce order updates automatically, and verify phone numbers on any contact form, all in one plugin. Supports 2FA, passwordless login, WooCommerce order update, and OTP-gated forms for CF7, WPForms, and Ninja Forms. Arabic support included.
 * Version:           3.6.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            kwtsms
 * Author URI:        https://www.kwtsms.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * WC tested up to:   11.2
 * Text Domain:       kwtsms
 * Domain Path:       /languages
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
// 2026-11-05 21:08 +03: 3.5.1 to 3.6.0, match the plugin header for the 3.6.0 release (constant lagged the 3.5.2 header), wp-release-360.
// 2026-11-07 00:09 +03: 3.6.1 (On-Hold text fix with its upgrade step, full Arabic, package fix), task wp-release-361.
define( 'KWTSMS_OTP_VERSION', '3.6.1' );
define( 'KWTSMS_OTP_FILE', __FILE__ );
define( 'KWTSMS_OTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'KWTSMS_OTP_URL', plugin_dir_url( __FILE__ ) );
define( 'KWTSMS_OTP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 *
 * Maps class names to files in /includes and /admin.
 * Naming convention: KwtSMS_Foo_Bar  includes/class-kwtsms-foo-bar.php
 *
 * @param string $class_name The fully-qualified class name.
 */
function kwtsms_otp_autoload( $class_name ) {
	// Only handle classes with our prefix.
	if ( strpos( $class_name, 'KwtSMS_' ) !== 0 ) {
		return;
	}

	// Convert class name to file name.
	// e.g. KwtSMS_OTP_Engine becomes class-kwtsms-otp-engine.php.
	$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

	$locations = array(
		KWTSMS_OTP_DIR . 'includes/' . $file_name,
		KWTSMS_OTP_DIR . 'admin/' . $file_name,
	);

	foreach ( $locations as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'kwtsms_otp_autoload' );

/**
 * Activation hook: runs when plugin is first activated.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Sets default options and records the installed version.
 * Must be registered at top-level (not inside another hook).
 */
function kwtsms_otp_activate() {
	// Set installed version for future upgrade routines.
	update_option( 'kwtsms_otp_version', KWTSMS_OTP_VERSION );

	// Seed default settings if not already present.
	if ( ! get_option( 'kwtsms_otp_general' ) ) {
		update_option(
			'kwtsms_otp_general',
			array(
				'otp_mode'              => '2fa',
				'otp_length'            => 6,
				'otp_expiry'            => 5,
				'max_attempts'          => 3,
				'resend_cooldown'       => 120,
				'login_otp'             => 1,
				'reset_otp'             => 1,
				'registration_otp_gate' => 'disabled',
				'captcha_provider'      => 'none',
				'referral_link'         => 0,
				'default_country_code'  => 'KW',
				'allowed_countries'     => array( 'KW', 'SA', 'AE', 'BH', 'QA', 'OM' ),
			)
		);
	}
}
register_activation_hook( KWTSMS_OTP_FILE, 'kwtsms_otp_activate' );

/**
 * Deactivation hook.
 *
 * Does not remove data; that is handled by uninstall.php.
 */
function kwtsms_otp_deactivate() {
	// Clear the cart abandonment cron job on deactivation.
	wp_clear_scheduled_hook( 'kwtsms_check_abandoned_carts' );
}
register_deactivation_hook( KWTSMS_OTP_FILE, 'kwtsms_otp_deactivate' );


/**
 * Bootstrap the plugin after all plugins are loaded.
 *
 * Using plugins_loaded ensures other plugins (e.g. WooCommerce) are available
 * before we register hooks that may depend on them.
 */
function kwtsms_otp_init() {
	// Load plugin translations. Required for GitHub-distributed installs where
	// WordPress auto-loading does not apply (only WordPress.org plugins get that).
	// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Kept for GitHub-distributed installs where WP auto-loading does not apply.
	load_plugin_textdomain( 'kwtsms', false, dirname( KWTSMS_OTP_BASENAME ) . '/languages' );

	// Emergency bypass: if defined in wp-config.php, skip all OTP logic.
	// Useful when an admin is locked out. See Help page for instructions.
	if ( defined( 'KWTSMS_OTP_DISABLED' ) && KWTSMS_OTP_DISABLED ) {
		return;
	}

	// Boot the main plugin manager.
	KwtSMS_Plugin::get_instance();
}
add_action( 'plugins_loaded', 'kwtsms_otp_init' );

/**
 * One-time upgrade steps for sites updating from an older version.
 *
 * 2026-11-05 23:54 +03: added for q-wp-onhold. Before 3.6.1 the On-Hold SMS default said the order was shipped,
 * but WooCommerce On-Hold means awaiting payment. A saved text that still equals the old default is
 * replaced with the new default; any text the store owner wrote is left untouched.
 */
function kwtsms_otp_maybe_upgrade() {
	$installed = (string) get_option( 'kwtsms_otp_version', '0' );
	if ( version_compare( $installed, KWTSMS_OTP_VERSION, '>=' ) ) {
		return;
	}
	if ( version_compare( $installed, '3.6.1', '<' ) ) {
		$integrations = get_option( 'kwtsms_otp_integrations', array() );
		if ( is_array( $integrations ) && isset( $integrations['woo_shipped'] ) && is_array( $integrations['woo_shipped'] ) ) {
			$old_defaults = array(
				'en' => '{site_name}: Your order #{order_id} has been shipped and is on its way!',
				'ar' => '{site_name}: طلبك رقم #{order_id} قيد الشحن وفي طريقه إليك!',
			);
			$new_defaults = KwtSMS_Settings::DEFAULTS['integrations']['woo_shipped'];
			foreach ( $old_defaults as $lang => $old_text ) {
				if ( isset( $integrations['woo_shipped'][ $lang ] ) && $old_text === $integrations['woo_shipped'][ $lang ] ) {
					$integrations['woo_shipped'][ $lang ] = $new_defaults[ $lang ];
				}
			}
			update_option( 'kwtsms_otp_integrations', $integrations );
		}
	}
	update_option( 'kwtsms_otp_version', KWTSMS_OTP_VERSION );
}
add_action( 'plugins_loaded', 'kwtsms_otp_maybe_upgrade', 5 );


/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 *
 * This silences the WooCommerce admin notice introduced in WooCommerce 8.5+ for
 * plugins that have not explicitly declared whether they support HPOS (custom order
 * tables). The plugin does not query the orders table directly, so compatibility is
 * declared as true.
 *
 * @see https://developer.woocommerce.com/docs/hpos-compatibility-checklist/
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				KWTSMS_OTP_FILE,
				true
			);
		}
	}
);
