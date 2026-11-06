<?php
/**
 * PHPStan bootstrap: defines plugin constants so they are available
 * during static analysis without needing to load the full plugin.
 *
 * These values mirror the runtime definitions in wp-kwtsms.php.
 *
 * @package KwtSMS_OTP
 */

// This file is a dev-only PHPStan bootstrap: not loaded by WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Plugin-specific constants.
defined( 'KWTSMS_OTP_DIR' ) || define( 'KWTSMS_OTP_DIR', __DIR__ . '/' );
defined( 'KWTSMS_OTP_URL' ) || define( 'KWTSMS_OTP_URL', 'https://example.com/wp-content/plugins/wp-kwtsms/' );
defined( 'KWTSMS_OTP_VERSION' ) || define( 'KWTSMS_OTP_VERSION', '0.0.0' );
defined( 'KWTSMS_OTP_FILE' ) || define( 'KWTSMS_OTP_FILE', __DIR__ . '/wp-kwtsms.php' );
defined( 'KWTSMS_OTP_BASENAME' ) || define( 'KWTSMS_OTP_BASENAME', 'wp-kwtsms/wp-kwtsms.php' );

// WordPress session / cookie constants not defined by szepeviktor/phpstan-wordpress bootstrap.
defined( 'COOKIEHASH' ) || define( 'COOKIEHASH', md5( 'phpstan' ) );
defined( 'COOKIEPATH' ) || define( 'COOKIEPATH', '/' );
