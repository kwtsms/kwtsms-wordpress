<?php
/**
 * Uninstall routine for kwtSMS: OTP & SMS Notifications.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * @package KwtSMS_OTP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Uninstall routine for kwtSMS: OTP & SMS Notifications.
 *
 * WordPress only runs this file when the plugin is deleted (not just deactivated).
 * The WP_UNINSTALL_PLUGIN check is required: it prevents direct execution.
 *
 * Data removed:
 * - All kwtsms_otp_* options from wp_options
 * - All kwtsms_phone user meta from wp_usermeta
 * - All kwtsms_otp_* and kwtsms_partial_auth_* transients
 * - Scheduled cron events (kwtsms_check_abandoned_carts)
 * - Debug log files (uploads/kwtsms-debug.log, .log.1 and the uploads/kwtsms-logs/ folder)
 *   (2026-11-11 20:50 +03: list updated for the .log.1 copy, task wp-363)
 *
 * @package KwtSMS_OTP
 */

global $wpdb;

// -------------------------------------------------------------------------
// 1. Remove all plugin options.
// -------------------------------------------------------------------------
$kwtsms_options = array(
	'kwtsms_otp_general',
	'kwtsms_otp_gateway',
	'kwtsms_otp_templates',
	'kwtsms_otp_version',
	'kwtsms_otp_send_log',
	'kwtsms_otp_sms_history',
	'kwtsms_otp_attempt_log',
	'kwtsms_otp_integrations',
	'kwtsms_otp_alerts',
	'kwtsms_abandoned_carts',
);

foreach ( $kwtsms_options as $kwtsms_option ) {
	delete_option( $kwtsms_option );
	// Also remove from multisite if applicable.
	delete_site_option( $kwtsms_option );
}

// -------------------------------------------------------------------------
// 2. Remove user phone meta.
// -------------------------------------------------------------------------
delete_metadata( 'user', 0, 'kwtsms_phone', '', true );
delete_metadata( 'user', 0, 'kwtsms_dismissed_version', '', true );
delete_metadata( 'user', 0, 'kwtsms_trusted_devices', '', true );
// 2026-11-11 20:47 +03: SMS-verified phone flag added in this release, task wp-363.
delete_metadata( 'user', 0, 'kwtsms_phone_verified', '', true );
// 2026-11-11 21:02 +03: stricter-login notice dismissal and its site flag, task wp-363.
delete_metadata( 'user', 0, 'kwtsms_strict_notice_dismissed', '', true );
delete_option( 'kwtsms_otp_strict_notice' );

// -------------------------------------------------------------------------
// 3. Remove product post meta (back-in-stock subscribers).
// -------------------------------------------------------------------------
delete_post_meta_by_key( 'kwtsms_back_in_stock_subscribers' );

// -------------------------------------------------------------------------
// 4. Remove transients (OTPs, partial auths, rate limiters).
// Transients are stored as _transient_* and _transient_timeout_* in options.
// -------------------------------------------------------------------------
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_kwtsms_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_kwtsms_' ) . '%'
	)
);

// -------------------------------------------------------------------------
// 5. Clear scheduled cron events.
// -------------------------------------------------------------------------
wp_clear_scheduled_hook( 'kwtsms_check_abandoned_carts' );

// -------------------------------------------------------------------------
// 6. Remove debug log file.
// -------------------------------------------------------------------------
$kwtsms_upload_dir = wp_upload_dir();
$kwtsms_debug_log  = ! empty( $kwtsms_upload_dir['basedir'] ) ? $kwtsms_upload_dir['basedir'] . '/kwtsms-debug.log' : '';
if ( $kwtsms_debug_log && file_exists( $kwtsms_debug_log ) ) {
	wp_delete_file( $kwtsms_debug_log );
}
// 2026-11-11 20:50 +03: also the rotated copy of the old uploads log (kwtsms-debug.log.1), task wp-363.
if ( $kwtsms_debug_log && file_exists( $kwtsms_debug_log . '.1' ) ) {
	wp_delete_file( $kwtsms_debug_log . '.1' );
}
// 2026-11-11 12:51 +03: the 3.6.2 log folder (random file name, rotation, guard files) and its option.
$kwtsms_log_dir = ! empty( $kwtsms_upload_dir['basedir'] ) ? $kwtsms_upload_dir['basedir'] . '/kwtsms-logs' : '';
if ( $kwtsms_log_dir && is_dir( $kwtsms_log_dir ) ) {
	foreach ( (array) glob( $kwtsms_log_dir . '/{,.}*', GLOB_BRACE ) as $kwtsms_file ) {
		if ( is_file( $kwtsms_file ) ) {
			wp_delete_file( $kwtsms_file );
		}
	}
	rmdir( $kwtsms_log_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- removing the plugin's own empty log folder on uninstall.
}
delete_option( 'kwtsms_debug_log_token' );
