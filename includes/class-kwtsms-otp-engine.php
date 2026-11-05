<?php
/**
 * OTP Engine — generate, store, verify, rate-limit.
 *
 * Uses WordPress Transients API for ephemeral storage.
 * OTP codes are compared with hash_equals() to prevent timing attacks.
 * Codes are single-use: deleted immediately on successful verification.
 *
 * Transient key schema:
 *   kwtsms_otp_{md5(identifier)}         — the OTP record
 *   kwtsms_otp_cd_{md5(identifier)}      — send-cooldown lock (SEND_COOLDOWN seconds)
 *   kwtsms_otp_rate_{md5(phone)}         — sliding-window timestamp array per phone
 *   kwtsms_otp_ip_{md5(ip)}             — sliding-window timestamp array per IP address
 *   kwtsms_otp_acct_{md5(user_id)}      — sliding-window timestamp array per WordPress user ID
 *   kwtsms_otp_lock_{md5(identifier)}    — lockout flag after max attempts
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_OTP_Engine
 */
class KwtSMS_OTP_Engine {

	/**
	 * Maximum OTP send attempts per phone per rate-limit window.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX = 3;

	/**
	 * Rate-limit window in seconds (10 minutes).
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 600;

	/**
	 * Maximum OTP send attempts per IP address per rate-limit window.
	 *
	 * @var int
	 */
	const IP_RATE_LIMIT_MAX = 10;

	/**
	 * IP rate-limit window in seconds (10 minutes).
	 *
	 * @var int
	 */
	const IP_RATE_LIMIT_WINDOW = 600;

	/**
	 * Minimum seconds that must elapse before another OTP SMS can be sent
	 * to the same identifier. Prevents rapid double-sends on double-click
	 * or page-refresh. The existing (reused) OTP remains valid during this window.
	 *
	 * @var int
	 */
	const SEND_COOLDOWN = 60;

	/**
	 * Maximum OTP send attempts per WordPress user ID per rate-limit window.
	 *
	 * @var int
	 */
	const USER_RATE_LIMIT_MAX = 5;

	/**
	 * Per-account rate-limit window in seconds (10 minutes).
	 *
	 * @var int
	 */
	const USER_RATE_LIMIT_WINDOW = 600;

	/**
	 * Settings helper.
	 *
	 * @var KwtSMS_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param KwtSMS_Settings $settings Plugin settings instance.
	 */
	public function __construct( KwtSMS_Settings $settings ) {
		$this->settings = $settings;
	}

	// =========================================================================
	// OTP lifecycle
	// =========================================================================

	/**
	 * Generate and store a new OTP for a user.
	 *
	 * The code is stored as an HMAC-SHA256 hash so that a database read cannot
	 * reveal the plaintext OTP. A fresh code is always issued on each call; the
	 * send-cooldown already prevents abuse from rapid re-requests.
	 *
	 * @param int|string $identifier User ID (int) or phone number (string for passwordless).
	 * @param string     $action     Context: 'login' | 'reset' | 'passwordless' | 'checkout' | 'registration'.
	 *
	 * @return string The plaintext OTP code (for sending via SMS — not stored).
	 */
	public function generate( $identifier, $action ) {
		$key    = $this->transient_key( $identifier );
		$expiry = (int) $this->settings->get( 'general.otp_expiry', 5 ) * MINUTE_IN_SECONDS;

		$length = (int) $this->settings->get( 'general.otp_length', 6 );
		$code   = $this->generate_code( $length );

		set_transient(
			$key,
			array(
				'code_hash' => hash_hmac( 'sha256', $code, wp_salt( 'auth' ) ),
				'attempts'  => 0,
				'action'    => $action,
				'created'   => time(),
			),
			$expiry
		);

		return $code;
	}

	/**
	 * Verify a submitted OTP code.
	 *
	 * Uses timing-safe comparison (hash_equals) to prevent brute-force
	 * timing analysis. Logs each verification attempt to kwtsms_otp_attempt_log.
	 *
	 * @param int|string $identifier User ID or phone.
	 * @param string     $submitted  The code the user entered.
	 * @param string     $action     Context for logging: 'login'|'passwordless'|'reset'.
	 * @param int|null   $user_id    WordPress user ID for logging (null if unknown).
	 * @param string     $phone      Phone number for logging (masked).
	 *
	 * @return string One of: 'valid' | 'invalid' | 'expired' | 'max_attempts' | 'not_found'
	 */
	public function verify( $identifier, $submitted, $action = 'login', $user_id = null, $phone = '' ) {
		$key  = $this->transient_key( $identifier );
		$data = get_transient( $key );
		$ip   = $this->get_client_ip();

		if ( false === $data ) {
			KwtSMS_API::append_attempt_log( $user_id, $phone, $ip, $action, 'expired' );
			return 'expired';
		}

		$max_attempts = (int) $this->settings->get( 'general.max_attempts', 3 );

		// Check if already locked out.
		if ( $data['attempts'] >= $max_attempts ) {
			KwtSMS_API::append_attempt_log( $user_id, $phone, $ip, $action, 'locked' );
			return 'max_attempts';
		}

		// Double-check expiry manually (transient TTL may have slight drift).
		$expiry_seconds = (int) $this->settings->get( 'general.otp_expiry', 5 ) * MINUTE_IN_SECONDS;
		if ( ( time() - $data['created'] ) > $expiry_seconds ) {
			delete_transient( $key );
			KwtSMS_API::append_attempt_log( $user_id, $phone, $ip, $action, 'expired' );
			return 'expired';
		}

		// Timing-safe comparison — hash the submitted code before comparing to the stored hash.
		$stored_hash = isset( $data['code_hash'] ) ? (string) $data['code_hash'] : '';
		if ( '' === $stored_hash || ! hash_equals( $stored_hash, hash_hmac( 'sha256', (string) $submitted, wp_salt( 'auth' ) ) ) ) {
			++$data['attempts'];
			if ( $data['attempts'] >= $max_attempts ) {
				// Delete OTP on lockout — user must request a new one.
				delete_transient( $key );
				KwtSMS_API::append_attempt_log( $user_id, $phone, $ip, $action, 'locked' );
				return 'max_attempts';
			}
			// Update attempt count.
			$remaining_ttl = $expiry_seconds - ( time() - $data['created'] );
			set_transient( $key, $data, max( 1, $remaining_ttl ) );
			KwtSMS_API::append_attempt_log( $user_id, $phone, $ip, $action, 'wrong_code' );
			return 'invalid';
		}

		// Valid — delete immediately to make it single-use.
		delete_transient( $key );
		KwtSMS_API::append_attempt_log( $user_id, $phone, $ip, $action, 'success' );
		return 'valid';
	}

	/**
	 * Get the number of remaining verification attempts.
	 *
	 * @param int|string $identifier User ID or phone.
	 *
	 * @return int Remaining attempts, or 0 if OTP not found.
	 */
	public function get_remaining_attempts( $identifier ) {
		$data = get_transient( $this->transient_key( $identifier ) );
		if ( false === $data ) {
			return 0;
		}
		$max = (int) $this->settings->get( 'general.max_attempts', 3 );
		return max( 0, $max - (int) $data['attempts'] );
	}

	// =========================================================================
	// Rate limiting
	// =========================================================================

	/**
	 * Check whether a phone number has exceeded the OTP send rate limit.
	 *
	 * Uses a sliding-window algorithm: the transient stores an array of Unix
	 * timestamps, one per request. On each call, timestamps older than the
	 * window are pruned and the current timestamp is recorded (if not at the
	 * limit), making it impossible to game the boundary.
	 *
	 * Combines the former is_rate_limited() + increment_rate() into one call.
	 * The timestamp is always recorded when the caller is not blocked, so
	 * callers must NOT call a separate increment method.
	 *
	 * @param string   $phone   Normalised phone number.
	 * @param string   $action  Context: 'login'|'passwordless'|'reset'.
	 * @param int|null $user_id User ID for logging.
	 *
	 * @return bool True if rate-limited (limit reached, timestamp NOT recorded).
	 *              False if not limited (timestamp recorded for this request).
	 */
	public function is_rate_limited( $phone, $action = 'login', $user_id = null ) {
		$limited = $this->is_rate_limited_sliding(
			$this->rate_key( $phone ),
			self::RATE_LIMIT_MAX,
			self::RATE_LIMIT_WINDOW
		);
		if ( $limited ) {
			KwtSMS_API::append_attempt_log( $user_id, $phone, $this->get_client_ip(), $action, 'rate_limited' );
		}
		return $limited;
	}

	/**
	 * Check whether the current IP has exceeded the OTP send rate limit.
	 *
	 * Uses a sliding-window algorithm (see is_rate_limited() for details).
	 * Fails open (returns false) if the IP cannot be determined, so that
	 * users behind proxies or in unusual network environments are not
	 * incorrectly blocked.
	 *
	 * Combines the former is_ip_rate_limited() + increment_ip_rate() into one
	 * call. Callers must NOT call a separate increment method.
	 *
	 * @param string   $action  Context: 'login'|'passwordless'|'reset'.
	 * @param int|null $user_id User ID for logging.
	 * @param string   $phone   Phone for logging.
	 *
	 * @return bool True if the IP is rate-limited.
	 */
	public function is_ip_rate_limited( $action = 'login', $user_id = null, $phone = '' ) {
		$ip = $this->get_client_ip();
		if ( '' === $ip ) {
			return false; // Cannot determine IP — fail open.
		}
		// Skip rate limiting for allowlisted IPs.
		if ( $this->is_ip_allowlisted( $ip ) ) {
			return false;
		}
		$limited = $this->is_rate_limited_sliding(
			'kwtsms_otp_ip_' . md5( $ip ),
			self::IP_RATE_LIMIT_MAX,
			self::IP_RATE_LIMIT_WINDOW
		);
		if ( $limited ) {
			KwtSMS_API::append_attempt_log( $user_id, $phone, $ip, $action, 'rate_limited' );
		}
		return $limited;
	}

	/**
	 * Check whether a user account has exceeded the OTP send rate limit.
	 *
	 * Uses a sliding-window algorithm (see is_rate_limited() for details).
	 * Only applies when $user_id is a positive integer. Guests (user_id = 0
	 * or null) are not subject to this limit; they are covered by the per-IP
	 * and per-phone limits instead.
	 *
	 * Combines the former is_user_rate_limited() + increment_user_rate() into
	 * one call. Callers must NOT call a separate increment method.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $action  Context: 'login'|'passwordless'|'reset'.
	 * @param string $phone   Phone for logging.
	 *
	 * @return bool True if the account is rate-limited.
	 */
	public function is_user_rate_limited( $user_id, $action = 'login', $phone = '' ) {
		if ( ! is_int( $user_id ) || $user_id <= 0 ) {
			return false;
		}
		$limited = $this->is_rate_limited_sliding(
			'kwtsms_otp_acct_' . md5( (string) $user_id ),
			self::USER_RATE_LIMIT_MAX,
			self::USER_RATE_LIMIT_WINDOW
		);
		if ( $limited ) {
			KwtSMS_API::append_attempt_log( $user_id, $phone, $this->get_client_ip(), $action, 'rate_limited' );
		}
		return $limited;
	}

	// =========================================================================
	// Send-cooldown
	// =========================================================================

	/**
	 * Check whether an OTP SMS was sent to this identifier within the last
	 * SEND_COOLDOWN seconds.
	 *
	 * Used by callers to skip re-sending an SMS when an OTP was just dispatched
	 * (e.g. double-click or page refresh). The OTP transient itself remains
	 * valid — the user can still enter the code they already received.
	 *
	 * @param int|string $identifier User ID or phone.
	 * @param string     $action     Action context ('login'|'reset'|'passwordless'|'checkout'|'').
	 *
	 * @return bool True if within the cooldown window.
	 */
	public function is_send_cooldown_active( $identifier, string $action = '' ): bool {
		return (bool) get_transient( $this->cooldown_key( $identifier, $action ) );
	}

	/**
	 * Record that an OTP SMS was just sent to this identifier.
	 *
	 * Sets a SEND_COOLDOWN-second transient so that is_send_cooldown_active()
	 * returns true for the next 60 seconds.
	 *
	 * @param int|string $identifier User ID or phone.
	 * @param string     $action     Action context ('login'|'reset'|'passwordless'|'checkout'|'').
	 */
	public function set_send_cooldown( $identifier, string $action = '' ): void {
		set_transient( $this->cooldown_key( $identifier, $action ), 1, self::SEND_COOLDOWN );
	}

	// =========================================================================
	// Message building
	// =========================================================================

	/**
	 * Build an SMS message from a template, replacing standard and extra placeholders.
	 *
	 * @param string                $otp_code    OTP code to substitute for {otp} (pass '' for non-OTP templates).
	 * @param string                $template_id Template key: 'login_otp' | 'reset_otp' | 'welcome_sms'.
	 * @param array<string, string> $extra_vars Optional map of placeholder  value for template-specific
	 *                                          vars, e.g. array( '{name}' => 'Ahmad' ) for welcome SMS.
	 *
	 * @return string The fully rendered, sanitised SMS message.
	 */
	public function build_message( $otp_code, $template_id, array $extra_vars = array() ) {
		$templates = $this->settings->get_all_templates();
		$template  = $templates[ $template_id ] ?? $templates['login_otp'];

		// Choose Arabic template for Arabic locales; English for everything else.
		$locale  = get_locale();
		$lang    = ( strpos( $locale, 'ar' ) === 0 ) ? 'ar' : 'en';
		$message = $template[ $lang ] ?? $template['en'] ?? '';

		// 2026-11-05 19:07 +03: if the checkout_otp Arabic text is blank (admin cleared it), send the English text rather
		// than an empty SMS. Scoped to this template so other templates keep their behaviour, wp-otp-template.
		if ( 'checkout_otp' === $template_id && 'ar' === $lang && '' === trim( (string) $message ) ) {
			$message = $template['en'] ?? '';
		}

		// Replace standard placeholders.
		$expiry    = (int) $this->settings->get( 'general.otp_expiry', 5 );
		$site_name = get_bloginfo( 'name' );

		$message = str_replace(
			array( '{otp}', '{site_name}', '{expiry_minutes}' ),
			array( $otp_code, $site_name, (string) $expiry ),
			$message
		);

		// Replace template-specific extra placeholders (e.g. {name} for welcome SMS).
		if ( ! empty( $extra_vars ) ) {
			$message = str_replace( array_keys( $extra_vars ), array_values( $extra_vars ), $message );
		}

		// Sanitise: delegate to the canonical cleaner in KwtSMS_API.
		$message = KwtSMS_API::clean_message( $message );

		return $message;
	}

	// =========================================================================
	// Phone blocking
	// =========================================================================

	/**
	 * Handle a complete OTP request: generate code, build message, send SMS.
	 *
	 * Checks whether the phone is on the admin-configured block list first.
	 * Blocked phones receive a silent success (no SMS sent, no error exposed)
	 * to prevent enumeration of the block list by attackers.
	 *
	 * Returns 'cooldown' (string) when an OTP was already sent within the last
	 * SEND_COOLDOWN seconds. Callers should silently proceed — the existing OTP
	 * is still valid and the user can enter the code they already received.
	 *
	 * @param string $normalized_phone Normalised E.164-style phone number (digits only).
	 * @param int    $identifier       User ID or 0 for guest/phone-based identifiers.
	 * @param string $template_id      Template key: 'login_otp' | 'reset_otp'.
	 * @param string $action           Context: 'login' | 'reset' | 'passwordless'.
	 * @param string $sender_id        Sender ID to use for the SMS.
	 *
	 * @return true|string|array true if phone is blocked (silent success),
	 *                           'cooldown' if within the send-cooldown window,
	 *                           or array ['otp_code','message','phone','sender','action'].
	 */
	public function request_otp( $normalized_phone, $identifier, $template_id, $action, $sender_id ) {
		// Blocklisted IP: silently pretend success — identical response to phone-blocked.
		$client_ip = $this->get_client_ip();
		if ( '' !== $client_ip && $this->is_ip_blocklisted( $client_ip ) ) {
			return true; // pretend success, no SMS sent.
		}

		// Blocked phone: silently pretend success — no SMS sent, no error exposed.
		if ( $this->is_phone_blocked( $normalized_phone ) ) {
			return true; // pretend success, no SMS sent.
		}

		// IPHub proxy/VPN check (A5).
		$reputation = $this->check_ip_reputation( $client_ip );
		if ( 'block' === $reputation ) {
			$this->log_debug( 'iphub', sprintf( '[IPHub] Blocked IP, request silently dropped: %s', $client_ip ) );
			return true; // Silent success: no SMS sent, attacker learns nothing.
		}
		if ( 'log' === $reputation ) {
			$this->log_debug( 'iphub', sprintf( '[IPHub] Suspicious IP flagged but allowed: %s', $client_ip ) );
		}

		// Within send-cooldown: OTP was dispatched recently; skip re-send.
		if ( $this->is_send_cooldown_active( $identifier, $action ) ) {
			return 'cooldown';
		}

		$otp_code = $this->generate( $identifier, $action );
		$message  = $this->build_message( $otp_code, $template_id );

		$this->set_send_cooldown( $identifier, $action );

		return array(
			'otp_code' => $otp_code,
			'message'  => $message,
			'phone'    => $normalized_phone,
			'sender'   => $sender_id,
			'action'   => $action,
		);
	}

	/**
	 * Check whether a phone number is on the admin block list.
	 *
	 * Accepts a newline- or comma-separated list stored in general.blocked_phones.
	 * Each entry is normalised (digits only) before comparison so formatting
	 * differences (e.g. spaces, dashes) do not affect matching.
	 *
	 * Made public so call sites (login, passwordless, reset, resend) can check
	 * the block list before calling generate() + send() directly.
	 *
	 * @param string $phone Normalised phone number (digits only).
	 *
	 * @return bool True if the phone is blocked.
	 */
	public function is_phone_blocked( string $phone ): bool {
		$list = $this->settings->get( 'general.blocked_phones', '' );
		if ( empty( $list ) ) {
			return false;
		}
		$blocked = preg_split( '/[\r\n,]+/', $list, -1, PREG_SPLIT_NO_EMPTY );
		$blocked = array_map(
			static function ( $p ) {
				return preg_replace( '/\D/', '', trim( $p ) );
			},
			$blocked
		);
		return in_array( $phone, array_filter( $blocked ), true );
	}

	// =========================================================================
	// IP allowlist / blocklist (A6)
	// =========================================================================

	/**
	 * Check whether a client IP is on the admin-configured allowlist.
	 *
	 * Allowlisted IPs skip per-IP rate limiting and IPHub proxy checks.
	 * OTP verification is still required.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 *
	 * @return bool True if the IP is allowlisted.
	 */
	public function is_ip_allowlisted( string $ip ): bool {
		return $this->ip_in_list( $ip, (string) $this->settings->get( 'general.ip_allowlist', '' ) );
	}

	/**
	 * Check whether a client IP is on the admin-configured blocklist.
	 *
	 * Blocklisted IPs receive a rate-limit-identical error response so attackers
	 * cannot enumerate the blocklist by inspecting error messages.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 *
	 * @return bool True if the IP is blocklisted.
	 */
	public function is_ip_blocklisted( string $ip ): bool {
		return $this->ip_in_list( $ip, (string) $this->settings->get( 'general.ip_blocklist', '' ) );
	}

	/**
	 * Check whether an IP matches any entry in a newline-separated list of IPs or CIDRs.
	 *
	 * @param string $ip      IP address to test.
	 * @param string $ip_list Newline-separated IPs / CIDRs.
	 *
	 * @return bool True if any entry in $ip_list matches $ip.
	 */
	private function ip_in_list( string $ip, string $ip_list ): bool {
		if ( '' === $ip_list ) {
			return false;
		}
		$entries = preg_split( '/[\r\n]+/', $ip_list, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $entries as $entry ) {
			$entry = trim( $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( $this->ip_matches_cidr( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Test whether an IP address falls within a CIDR range (or equals a bare IP).
	 *
	 * Supports IPv4 and IPv6 via inet_pton() binary comparison with bitmasking.
	 * No external library required.
	 *
	 * @param string $ip   IPv4 or IPv6 address to test.
	 * @param string $cidr CIDR range (e.g. "192.168.1.0/24") or bare IP.
	 *
	 * @note IPv4-mapped IPv6 addresses (e.g. ::ffff:1.2.3.4) will NOT match IPv4
	 *       CIDR entries because inet_pton() produces a 16-byte binary string for
	 *       the IPv6 family and a 4-byte string for IPv4. The length guard at line
	 *       577 catches the mismatch and returns false. If you need cross-family
	 *       matching, normalize addresses to one family before calling this method.
	 *
	 * @return bool True if $ip is within $cidr.
	 */
	private function ip_matches_cidr( string $ip, string $cidr ): bool {
		$parts  = explode( '/', $cidr, 2 );
		$range  = $parts[0];
		$prefix = isset( $parts[1] ) ? (int) $parts[1] : -1;

		$ip_bin    = inet_pton( $ip );
		$range_bin = inet_pton( $range );

		if ( false === $ip_bin || false === $range_bin ) {
			return false;
		}

		$ip_len    = strlen( $ip_bin );
		$range_len = strlen( $range_bin );
		if ( $ip_len !== $range_len ) {
			return false;
		}

		$max_prefix = $ip_len * 8;
		// Handles bare IPs (no slash, so $prefix == -1) and valid full-host
		// prefixes (/32 for IPv4, /128 for IPv6). For /32 and /128 the mask
		// would be all-ones anyway, so a direct equality check is equivalent.
		if ( $prefix < 0 || $prefix >= $max_prefix ) {
			return $ip_bin === $range_bin;
		}

		$mask   = str_repeat( "\xFF", (int) ( $prefix / 8 ) );
		$remain = $prefix % 8;
		if ( $remain > 0 ) {
			$mask .= chr( 0xFF & ( 0xFF << ( 8 - $remain ) ) );
		}
		$mask = str_pad( $mask, $ip_len, "\x00" );

		return ( $ip_bin & $mask ) === ( $range_bin & $mask );
	}

	// =========================================================================
	// IP reputation check — IPHub (A5)
	// =========================================================================

	/**
	 * Check IP reputation via the IPHub v2 API.
	 *
	 * Returns 'allow', 'block', or 'log'.
	 * Fails open: any HTTP error or unexpected response returns 'allow' so that
	 * a third-party outage never locks out legitimate users.
	 * Allowlisted IPs bypass this check entirely.
	 *
	 * Block levels returned by IPHub:
	 *   0 — clean / residential.
	 *   1 — confirmed proxy or VPN.
	 *   2 — mixed (some residential, some proxy).
	 *
	 * The raw block level (integer) is cached per IP so subsequent OTP requests
	 * from the same IP do not trigger an extra HTTP call. The action (block/log/allow)
	 * is NOT cached because admins may change it between requests.
	 *
	 * @param string $ip IPv4 or IPv6 address.
	 *
	 * @return string 'allow' | 'block' | 'log'
	 */
	public function check_ip_reputation( string $ip ): string {
		$enabled = (bool) $this->settings->get( 'general.iphub_enabled', false );
		if ( ! $enabled ) {
			return 'allow';
		}

		// Allowlisted IPs bypass IPHub entirely.
		if ( $this->is_ip_allowlisted( $ip ) ) {
			return 'allow';
		}

		$api_key = (string) $this->settings->get( 'general.iphub_api_key', '' );
		if ( '' === $api_key ) {
			return 'allow';
		}

		// Serve from cache when possible.
		$cache_key    = 'kwtsms_ip_' . md5( $ip );
		$cached_level = get_transient( $cache_key );

		if ( false !== $cached_level ) {
			$level = (int) $cached_level;
		} else {
			// Fetch from IPHub API with a short timeout to avoid blocking the login flow.
			$response = wp_remote_get(
				'https://v2.api.iphub.info/ip/' . $ip,
				array(
					'headers' => array( 'X-Key' => $api_key ),
					'timeout' => 3,
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return 'allow'; // Fail open.
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! isset( $body['block'] ) ) {
				return 'allow'; // Fail open.
			}

			$level = (int) $body['block'];
			$ttl   = (int) $this->settings->get( 'general.iphub_cache_ttl', 86400 );
			set_transient( $cache_key, $level, $ttl );
		}

		// Map the block level to an admin-configured action.
		if ( 1 === $level ) {
			return (string) $this->settings->get( 'general.iphub_action_block1', 'block' );
		}
		if ( 2 === $level ) {
			return (string) $this->settings->get( 'general.iphub_action_block2', 'log' );
		}

		return 'allow';
	}

	/**
	 * Write a line to the debug log when the general.debug_logging setting is on.
	 *
	 * Uses the same log file as KwtSMS_API::write_debug_log() so all plugin
	 * output lands in one file: wp-content/kwtsms-debug.log.
	 *
	 * @param string $context Short label (e.g. 'iphub').
	 * @param string $message Log message text.
	 */
	private function log_debug( string $context, string $message ): void {
		if ( ! $this->settings->get( 'general.debug_logging', false ) ) {
			return;
		}
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return;
		}
		$log_path = $upload_dir['basedir'] . '/kwtsms-debug.log';
		$line     = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [kwtsms-otp] [' . $context . '] ' . $message . PHP_EOL;

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$existing = $wp_filesystem->exists( $log_path ) ? $wp_filesystem->get_contents( $log_path ) : '';
		$wp_filesystem->put_contents( $log_path, $existing . $line );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Sliding-window rate-limit primitive.
	 *
	 * The transient stores an array of Unix timestamps — one entry per
	 * recorded request. On every call:
	 *
	 *  1. Load the existing array (or start with an empty one).
	 *  2. Prune timestamps that fall outside the window (older than $window
	 *     seconds ago). Uses array_values() to re-index after filtering.
	 *  3. If the count of remaining timestamps is >= $max, the caller is
	 *     rate-limited: persist the pruned array with a TTL derived from the
	 *     oldest remaining timestamp (so the transient expires naturally when
	 *     all its entries fall outside the window) and return true WITHOUT
	 *     recording the current timestamp.
	 *  4. Otherwise, if $record is true, append the current Unix timestamp,
	 *     persist the updated array with a fresh TTL equal to $window, and
	 *     return false. If $record is false, skip recording (check-only mode).
	 *
	 * This eliminates the fixed-window boundary exploit: no matter when
	 * within the window a request arrives, only requests in the last
	 * $window seconds are counted.
	 *
	 * @param string $key    Transient key (already namespaced by the caller).
	 * @param int    $max    Maximum requests allowed within $window seconds.
	 * @param int    $window Sliding window size in seconds.
	 * @param bool   $record Whether to record the current timestamp when under
	 *                       the limit. Pass false to perform a check-only read
	 *                       (e.g. for pre-flight checks) without consuming a
	 *                       slot. Defaults to true (record on every allowed call).
	 *
	 * @return bool True if the caller is rate-limited (limit reached).
	 *              False if not limited.
	 */
	private function is_rate_limited_sliding( string $key, int $max, int $window, bool $record = true ): bool {
		$data = get_transient( $key );
		$data = is_array( $data ) ? $data : array();
		$now  = time();

		// Prune timestamps outside the current window.
		$data = array_values(
			array_filter(
				$data,
				function ( $ts ) use ( $now, $window ) {
					return $ts > $now - $window;
				}
			)
		);

		if ( count( $data ) >= $max ) {
			// At limit — persist the pruned array but do NOT record the current
			// timestamp; the caller is blocked.
			//
			// Use a TTL derived from the oldest timestamp so the transient
			// auto-expires when all its entries naturally fall outside the
			// window. This prevents an attacker from extending the transient
			// lifetime indefinitely by hammering the endpoint (which would
			// repeatedly reset a full-$window TTL).
			$oldest = min( $data );
			$ttl    = max( 1, $window - ( $now - $oldest ) );
			set_transient( $key, $data, $ttl );
			return true;
		}

		if ( $record ) {
			// Under limit — record the current request timestamp.
			// Note: timestamp is recorded here before the SMS send. If the send
			// fails, the slot is still consumed. This is intentional — it
			// prevents an attacker from making unlimited attempts by triggering
			// gateway errors.
			$data[] = $now;
			set_transient( $key, $data, $window );
		}

		return false;
	}

	/**
	 * Generate a cryptographically random numeric OTP code.
	 *
	 * Uses random_int() which is CSPRNG-backed.
	 *
	 * @param int $length Number of digits (4 or 6).
	 *
	 * @return string Zero-padded numeric code.
	 */
	private function generate_code( $length ) {
		$length = in_array( (int) $length, array( 4, 6 ), true ) ? (int) $length : 6;
		$max    = (int) str_repeat( '9', $length );
		$code   = random_int( 0, $max );
		return str_pad( (string) $code, $length, '0', STR_PAD_LEFT );
	}

	/**
	 * Get the transient key for an OTP record.
	 *
	 * MD5-hashes the identifier to keep key length within WP's 172-char limit.
	 *
	 * @param int|string $identifier User ID or phone.
	 *
	 * @return string Transient key.
	 */
	private function transient_key( $identifier ) {
		return 'kwtsms_otp_' . md5( (string) $identifier );
	}

	/**
	 * Get the transient key for the rate-limit counter of a phone number.
	 *
	 * @param string $phone Normalised phone number.
	 *
	 * @return string Transient key.
	 */
	private function rate_key( $phone ) {
		return 'kwtsms_otp_rate_' . md5( $phone );
	}

	/**
	 * Get the transient key for the send-cooldown lock of an identifier.
	 *
	 * Including the action in the key ensures that login, reset, passwordless,
	 * and form OTP cooldowns are tracked independently per user, preventing a
	 * recent login OTP from blocking a password reset OTP for the same user.
	 *
	 * @param int|string $identifier User ID or phone.
	 * @param string     $action     Action context (included in key for isolation).
	 *
	 * @return string Transient key.
	 */
	private function cooldown_key( $identifier, string $action = '' ) {
		$suffix = '' !== $action ? $identifier . '_' . $action : (string) $identifier;
		return 'kwtsms_otp_cd_' . md5( $suffix );
	}

	/**
	 * Get the client IP address, respecting reverse-proxy headers.
	 *
	 * Uses X-Forwarded-For / X-Real-IP only when REMOTE_ADDR is a private or
	 * loopback address (indicating a trusted proxy is in front of the server).
	 * This prevents trivial IP spoofing by external clients on direct connections
	 * while still working correctly behind AWS ALB, Cloudflare, or Nginx proxies.
	 *
	 * @return string Validated IP address, or empty string.
	 */
	public function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// Only trust proxy headers when REMOTE_ADDR is a known private/loopback range.
		// Note: 172.16.0.0/12 is private; the full 172.0.0.0/8 is NOT — do not use '172.' prefix.
		$private_cidrs = array( '127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7' );
		$is_private    = false;
		foreach ( $private_cidrs as $cidr ) {
			if ( $this->is_ip_in_cidr( $ip, $cidr ) ) {
				$is_private = true;
				break;
			}
		}

		if ( $is_private ) {
			foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ) as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) {
					// X-Forwarded-For may be a comma-separated list; take the first (client) IP.
					$forwarded = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
					if ( filter_var( $forwarded, FILTER_VALIDATE_IP ) ) {
						$ip = $forwarded;
						break;
					}
				}
			}
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Check whether a given IP address falls within a CIDR block.
	 *
	 * Supports both IPv4 (e.g. 192.168.0.0/16) and IPv6 (e.g. ::1/128) ranges.
	 *
	 * @param string $ip   The IP address to test.
	 * @param string $cidr The CIDR notation block to test against.
	 * @return bool True if the IP is within the CIDR range.
	 */
	private function is_ip_in_cidr( $ip, $cidr ) {
		list( $subnet, $bits ) = explode( '/', $cidr );

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) &&
			filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			// IPv4 comparison.
			$bits     = (int) $bits;
			$ip_long  = ip2long( $ip );
			$net_long = ip2long( $subnet );
			$mask     = $bits > 0 ? ( -1 << ( 32 - $bits ) ) : 0;
			return ( $ip_long & $mask ) === ( $net_long & $mask );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) &&
			filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			// IPv6 comparison.
			$bits     = (int) $bits;
			$ip_bin   = inet_pton( $ip );
			$net_bin  = inet_pton( $subnet );
			$ip_hex   = bin2hex( $ip_bin );
			$net_hex  = bin2hex( $net_bin );
			$ip_int   = gmp_init( $ip_hex, 16 );
			$net_int  = gmp_init( $net_hex, 16 );
			$mask_int = gmp_sub( gmp_pow( 2, 128 ), gmp_pow( 2, 128 - $bits ) );
			return gmp_cmp( gmp_and( $ip_int, $mask_int ), gmp_and( $net_int, $mask_int ) ) === 0;
		}

		return false;
	}
}
