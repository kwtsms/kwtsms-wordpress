<?php
/**
 * KwtSMS REST API Client.
 *
 * Wraps all calls to the kwtsms JSON REST API (v4.1).
 * Always uses HTTPS + POST as required by the API documentation.
 * Credentials are never logged or output to the browser.
 *
 * API base: https://www.kwtsms.com/API/
 *
 * @package KwtSMS_OTP
 * @see     docs/KwtSMS.com_API_Documentation_v41.pdf
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_API
 */
class KwtSMS_API {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://www.kwtsms.com/API/';

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 15;

	/**
	 * Context metadata passed from send() to append_sms_history() calls.
	 *
	 * Set before each send() call, reset after. Allows callers to attach
	 * metadata (e.g. order_id) without modifying every internal history call.
	 *
	 * @var array
	 */
	private static $send_context = array();

	/**
	 * API username.
	 *
	 * @var string
	 */
	private $username;

	/**
	 * API password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Whether to add test=1 to the send payload (API queues but does not deliver).
	 *
	 * @var bool
	 */
	private $test_mode;

	/**
	 * Whether detailed debug logging to file is enabled.
	 *
	 * @var bool
	 */
	private $debug_mode;

	/**
	 * Constructor.
	 *
	 * @param string $username   kwtsms API username.
	 * @param string $password   kwtsms API password.
	 * @param bool   $test_mode  Whether to use test mode.
	 * @param bool   $debug_mode Whether to write detailed debug logs to file.
	 */
	public function __construct( $username, $password, $test_mode = false, $debug_mode = false ) {
		$this->username   = $username;
		$this->password   = $password;
		$this->test_mode  = (bool) $test_mode;
		$this->debug_mode = (bool) $debug_mode;
	}

	// =========================================================================
	// Public API methods
	// =========================================================================

	/**
	 * Retrieve account balance.
	 *
	 * @return array{available: float, purchased: float}|WP_Error
	 */
	public function get_balance() {
		$response = $this->request( 'balance/', array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'available' => (float) ( $response['available'] ?? 0 ),
			'purchased' => (float) ( $response['purchased'] ?? 0 ),
		);
	}

	/**
	 * Retrieve available sender IDs for this account.
	 *
	 * Results are cached for 5 minutes to avoid redundant API calls.
	 *
	 * @return string[]|WP_Error Array of sender ID strings on success.
	 */
	public function get_sender_ids() {
		$cache_key = 'kwtsms_senderids_' . md5( $this->username );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$response = $this->request( 'senderid/', array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$ids = isset( $response['senderid'] ) && is_array( $response['senderid'] )
			? array_map( 'sanitize_text_field', $response['senderid'] )
			: array();

		// Cache for 5 minutes.
		set_transient( $cache_key, $ids, 5 * MINUTE_IN_SECONDS );

		return $ids;
	}

	/**
	 * Send an SMS message to one or more recipients.
	 *
	 * Unified entry point for all SMS sending. Handles validation, phone
	 * normalization, deduplication, country allow-list filtering, cached
	 * balance check, and batching (max 200 phones per API request).
	 *
	 * Single mode: when $phones is a string, returns the same format as the
	 * legacy format for backward compatibility with all existing callers.
	 *
	 * Multi mode: when $phones is an array, returns an aggregate result with
	 * sent/failed counts, per-batch details, and any errors encountered.
	 *
	 * @param string|string[] $phones    One phone (string) or multiple (array).
	 * @param string          $sender_id Approved sender ID for this account.
	 * @param string          $message   Message text (English or Arabic).
	 * @param string          $type      Context type for logging: 'login'|'reset'|'passwordless'|'welcome'|'test'|'notification'|'alert'.
	 *
	 * @return array{msg_id: string, balance_after: float|null}|array{sent: int, failed: int, total: int, batches: array, balance_after: float|null, errors: array}|WP_Error
	 */
	/**
	 * Maximum SMS pages allowed per message (kwtsms API limit).
	 *
	 * @var int
	 */
	const MAX_SMS_PAGES = 7;

	/**
	 * Calculate how many SMS pages a message will consume.
	 *
	 * Any non-GSM character (Arabic, emoji remnants, Unicode) forces the
	 * entire message into UCS-2 encoding with shorter page limits.
	 *
	 * @param string $message Cleaned message text.
	 *
	 * @return int Number of SMS pages.
	 */
	public static function count_sms_pages( $message ) {
		$len = mb_strlen( $message, 'UTF-8' );
		if ( 0 === $len ) {
			return 0;
		}

		// Check if message is pure GSM-7 (ASCII + basic Latin).
		$is_gsm = 1 === preg_match( '/\A[\x20-\x7E\n\r]*\z/', $message );

		if ( $is_gsm ) {
			// GSM-7: 160 chars for 1 page, 153 per page for multi-page.
			return ( $len <= 160 ) ? 1 : (int) ceil( $len / 153 );
		}

		// UCS-2 (Arabic, Unicode): 70 chars for 1 page, 67 per page for multi-page.
		return ( $len <= 70 ) ? 1 : (int) ceil( $len / 67 );
	}

	/**
	 * Send an SMS message to one or more phone numbers.
	 *
	 * Handles credentials check, message cleaning, phone normalization,
	 * country filtering, balance check, batch sending, and logging.
	 *
	 * @param string|string[] $phones    One phone number or an array of numbers.
	 * @param string          $sender_id Sender ID approved in your kwtSMS account.
	 * @param string          $message   Plain-text message to send.
	 * @param string          $type      Message type for the send log (e.g. 'login', 'order').
	 * @param array           $context   Optional metadata passed to append_sms_history (e.g. order_id).
	 *
	 * @return array|WP_Error Result array or WP_Error on failure.
	 */
	public function send( $phones, $sender_id, $message, $type = 'login', $context = array() ) {
		self::$send_context = $context;
		$single_mode        = ! is_array( $phones );

		// Normalize input to array.
		if ( $single_mode ) {
			$phones = array( $phones );
		}

		$this->write_debug_log( 'send()', "type={$type} phones=" . count( $phones ) . " sender={$sender_id}" );

		// ── Global SMS kill switch ───────────────────────────────────────────
		// Reads from general settings only. Defaults to enabled (1) if not set.
		$gen    = get_option( 'kwtsms_otp_general', array() );
		$sms_on = $gen['sms_enabled'] ?? 1;
		if ( empty( $sms_on ) ) {
			$err = new WP_Error(
				'kwtsms_sms_disabled',
				__( 'SMS sending is disabled. Enable it in kwtSMS > General Settings.', 'kwtsms' )
			);
			$this->write_debug_log( 'send()', 'ABORT: SMS sending is disabled (sms_enabled=0)' );
			return $err;
		}

		// ── Credentials check (fail fast) ────────────────────────────────────
		if ( empty( $this->username ) || empty( $this->password ) ) {
			$err = new WP_Error(
				'kwtsms_no_credentials',
				__( 'kwtSMS API credentials are not configured. Go to kwtSMS > Gateway and enter your API username and password.', 'kwtsms' )
			);
			$this->write_debug_log( 'send()', 'ABORT: credentials missing' );
			return $err;
		}

		// ── Clean message ────────────────────────────────────────────────────
		$message = self::clean_message( $message );

		// ── Validate required fields ─────────────────────────────────────────
		if ( empty( $message ) ) {
			$err = new WP_Error(
				'kwtsms_missing_message',
				__( 'Cannot send SMS: message is empty. Please check your SMS templates in Settings > kwtSMS > Templates.', 'kwtsms' )
			);
			$this->write_debug_log( 'send()', 'ABORT: message empty' );
			foreach ( $phones as $p ) {
				$log_phone = ! empty( $p ) ? $p : '?';
				self::append_send_log( $log_phone, 'failed', $type );
				self::append_sms_history(
					$log_phone,
					$message,
					'failed',
					$type,
					'',
					'',
					array(
						'ok'      => false,
						'code'    => $err->get_error_code(),
						'message' => $err->get_error_message(),
					),
					$this->username
				);
			}
			if ( $single_mode ) {
				return $err;
			}
			return array(
				'sent'          => 0,
				'failed'        => count( $phones ),
				'total'         => count( $phones ),
				'batches'       => array(),
				'balance_after' => null,
				'errors'        => array( $err ),
			);
		}

		if ( empty( $sender_id ) ) {
			$err = new WP_Error(
				'kwtsms_missing_sender_id',
				__( 'Cannot send SMS: no Sender ID configured. Go to kwtSMS > Gateway, save your API credentials, then choose a Sender ID from the dropdown. Click Save Settings.', 'kwtsms' )
			);
			$this->write_debug_log( 'send()', 'ABORT: sender_id empty' );
			foreach ( $phones as $p ) {
				$log_phone = ! empty( $p ) ? $p : '?';
				self::append_send_log( $log_phone, 'failed', $type );
				self::append_sms_history(
					$log_phone,
					$message,
					'failed',
					$type,
					'',
					'',
					array(
						'ok'      => false,
						'code'    => $err->get_error_code(),
						'message' => $err->get_error_message(),
					),
					$this->username
				);
			}
			if ( $single_mode ) {
				return $err;
			}
			return array(
				'sent'          => 0,
				'failed'        => count( $phones ),
				'total'         => count( $phones ),
				'batches'       => array(),
				'balance_after' => null,
				'errors'        => array( $err ),
			);
		}

		// ── Message length check (max 7 SMS pages) ──────────────────────────
		$pages = self::count_sms_pages( $message );
		if ( $pages > self::MAX_SMS_PAGES ) {
			$err = new WP_Error(
				'kwtsms_message_too_long',
				/* translators: 1: number of pages, 2: max pages allowed */
				sprintf( __( 'Message is too long (%1$d SMS pages). Maximum is %2$d pages. Shorten the message or split it into multiple sends.', 'kwtsms' ), $pages, self::MAX_SMS_PAGES )
			);
			$this->write_debug_log( 'send()', "ABORT: message too long ({$pages} pages, max " . self::MAX_SMS_PAGES . ')' );
			return $err;
		}

		// ── Normalize, deduplicate, and validate phones ──────────────────────
		$normalized = array(); // normalized_phone => true (dedup map).
		$invalid    = 0;

		foreach ( $phones as $raw_phone ) {
			if ( empty( $raw_phone ) ) {
				$err = new WP_Error(
					'kwtsms_missing_phone',
					__( 'Cannot send SMS: phone number is missing. Please check user phone in their profile.', 'kwtsms' )
				);
				$this->write_debug_log( 'send()', 'SKIP: empty phone in batch' );
				self::append_send_log( '?', 'failed', $type );
				self::append_sms_history(
					$raw_phone,
					$message,
					'failed',
					$type,
					'',
					'',
					array(
						'ok'      => false,
						'code'    => $err->get_error_code(),
						'message' => $err->get_error_message(),
					),
					$this->username
				);
				++$invalid;

				// In single mode, return the error immediately.
				if ( $single_mode ) {
					return $err;
				}
				continue;
			}

			$norm = self::normalize_phone( $raw_phone );
			if ( is_wp_error( $norm ) ) {
				$this->write_debug_log( 'send()', "SKIP: invalid phone={$raw_phone}: " . $norm->get_error_message() );
				self::append_send_log( $raw_phone, 'failed', $type );
				self::append_sms_history(
					$raw_phone,
					$message,
					'failed',
					$type,
					'',
					'',
					array(
						'ok'      => false,
						'code'    => $norm->get_error_code(),
						'message' => $norm->get_error_message(),
					),
					$this->username
				);
				++$invalid;

				if ( $single_mode ) {
					return $norm;
				}
				continue;
			}

			// Deduplicate by normalized value.
			if ( isset( $normalized[ $norm ] ) ) {
				$this->write_debug_log( 'send()', "SKIP: duplicate phone={$norm}" );
				continue;
			}

			$normalized[ $norm ] = true;
		}

		$unique_phones = array_keys( $normalized );

		// If no valid phones remain after normalization, abort.
		if ( empty( $unique_phones ) ) {
			$err = new WP_Error(
				'kwtsms_no_valid_phones',
				__( 'Cannot send SMS: no valid phone numbers provided.', 'kwtsms' )
			);
			$this->write_debug_log( 'send()', 'ABORT: no valid phones after normalization' );
			if ( $single_mode ) {
				return $err;
			}
			return array(
				'sent'          => 0,
				'failed'        => $invalid,
				'total'         => count( $phones ),
				'batches'       => array(),
				'balance_after' => null,
				'errors'        => array( $err ),
			);
		}

		// ── Country allow-list check ─────────────────────────────────────────
		$general           = get_option( 'kwtsms_otp_general', array() );
		$allowed_countries = isset( $general['allowed_countries'] ) && is_array( $general['allowed_countries'] )
			? $general['allowed_countries']
			: array();

		if ( ! empty( $allowed_countries ) ) {
			$allowed_phones = array();
			foreach ( $unique_phones as $p ) {
				$phone_iso2 = self::get_iso2_from_phone( $p );
				if ( '' === $phone_iso2 || ! in_array( $phone_iso2, $allowed_countries, true ) ) {
					$country_err = new WP_Error(
						'country_not_allowed',
						__( 'SMS to this country is not enabled.', 'kwtsms' )
					);
					$this->write_debug_log(
						'send()',
						"BLOCK: country not allowed, phone={$p} iso2={$phone_iso2} allowed=" . implode( ',', $allowed_countries )
					);
					self::append_send_log( $p, 'failed', $type );
					self::append_sms_history(
						$p,
						$message,
						'failed',
						$type,
						'',
						'',
						array(
							'ok'      => false,
							'code'    => $country_err->get_error_code(),
							'message' => $country_err->get_error_message(),
						),
						$this->username
					);
					++$invalid;

					if ( $single_mode ) {
						return $country_err;
					}
					continue;
				}
				$allowed_phones[] = $p;
			}
			$unique_phones = $allowed_phones;

			if ( empty( $unique_phones ) ) {
				$err = new WP_Error(
					'country_not_allowed',
					__( 'SMS to this country is not enabled.', 'kwtsms' )
				);
				if ( $single_mode ) {
					return $err;
				}
				return array(
					'sent'          => 0,
					'failed'        => $invalid,
					'total'         => count( $phones ),
					'batches'       => array(),
					'balance_after' => null,
					'errors'        => array( $err ),
				);
			}
		}

		// ── Balance check (once for the whole batch) ─────────────────────────
		$balance_check = $this->check_balance_before_send();
		if ( is_wp_error( $balance_check ) ) {
			$this->write_debug_log( 'send()', 'ABORT: ' . $balance_check->get_error_message() );
			foreach ( $unique_phones as $p ) {
				self::append_send_log( $p, 'failed', $type );
				self::append_sms_history(
					$p,
					$message,
					'failed',
					$type,
					'',
					'',
					array(
						'ok'      => false,
						'code'    => $balance_check->get_error_code(),
						'message' => $balance_check->get_error_message(),
					),
					$this->username
				);
			}
			if ( $single_mode ) {
				return $balance_check;
			}
			return array(
				'sent'          => 0,
				'failed'        => $invalid + count( $unique_phones ),
				'total'         => count( $phones ),
				'batches'       => array(),
				'balance_after' => null,
				'errors'        => array( $balance_check ),
			);
		}

		// ── Split into chunks of 200 (API max per request) ───────────────────
		$chunks        = array_chunk( $unique_phones, 200 );
		$total_sent    = 0;
		$total_failed  = $invalid;
		$batch_results = array();
		$batch_errors  = array();
		$balance_after = null;

		foreach ( $chunks as $chunk_index => $chunk ) {
			// Throttle: 500ms delay between batches to stay under the API rate
			// limit (max 10 req/s per IP). Skip delay on first batch.
			// 2026-10-13 13:15 +03: API limit corrected from 5 to 10 req/s (server limit raised).
			if ( $chunk_index > 0 ) {
				usleep( 500000 );
			}

			$payload = array(
				'sender'  => $sender_id,
				'mobile'  => implode( ',', $chunk ),
				'message' => $message,
			);

			if ( $this->test_mode ) {
				$payload['test'] = 1;
			}

			$response = $this->request( 'send/', $payload );

			if ( is_wp_error( $response ) ) {
				$this->write_debug_log( 'send()', "BATCH {$chunk_index} FAILED: " . $response->get_error_message() );

				$_api_data = is_array( $response->get_error_data() ) ? $response->get_error_data() : array();
				$_api_code = $_api_data['api_code'] ?? '';
				$_api_desc = $_api_data['description'] ?? '';

				foreach ( $chunk as $p ) {
					self::append_send_log( $p, 'failed', $type, $sender_id );
					self::append_sms_history(
						$p,
						$message,
						'failed',
						$type,
						'',
						$sender_id,
						array(
							'ok'      => false,
							'code'    => $_api_code ? $_api_code : $response->get_error_code(),
							'message' => $_api_desc ? $_api_desc : ( $_api_code ? $_api_code : $response->get_error_code() ),
						),
						$this->username
					);
				}

				$total_failed  += count( $chunk );
				$batch_errors[] = $response;

				$batch_results[] = array(
					'ok'     => false,
					'phones' => $chunk,
					'error'  => $response->get_error_message(),
				);

				// In single mode (only one phone, so only one batch), return immediately.
				if ( $single_mode ) {
					return $response;
				}
				continue;
			}

			// ── Batch success ────────────────────────────────────────────────
			$msg_id = sanitize_text_field( $response['msg-id'] ?? '' );
			$this->write_debug_log( 'send()', "BATCH {$chunk_index} SUCCESS: msg-id={$msg_id} phones=" . count( $chunk ) );

			foreach ( $chunk as $p ) {
				self::append_send_log( $p, 'sent', $type, $sender_id );
				self::append_sms_history(
					$p,
					$message,
					'sent',
					$type,
					$msg_id,
					$sender_id,
					array(
						'ok'      => true,
						'code'    => '',
						'message' => 'OK',
					),
					$this->username
				);
			}

			$total_sent += count( $chunk );

			if ( isset( $response['balance-after'] ) ) {
				$balance_after = (float) $response['balance-after'];
				self::update_saved_balance( $balance_after );
			}

			$batch_results[] = array(
				'ok'            => true,
				'phones'        => $chunk,
				'msg_id'        => $msg_id,
				'balance_after' => isset( $response['balance-after'] ) ? (float) $response['balance-after'] : null,
			);
		}

		// ── Return ───────────────────────────────────────────────────────────
		if ( $single_mode ) {
			// Single mode: backward-compatible return format.
			$last_batch = end( $batch_results );
			return array(
				'msg_id'        => $last_batch['msg_id'] ?? '',
				'balance_after' => $balance_after,
			);
		}

		return array(
			'sent'          => $total_sent,
			'failed'        => $total_failed,
			'total'         => $total_sent + $total_failed,
			'batches'       => $batch_results,
			'balance_after' => $balance_after,
			'errors'        => $batch_errors,
		);
	}

	/**
	 * Legacy send method. Deprecated in 3.4.0.
	 *
	 * Calls send() internally. External code should migrate to send().
	 *
	 * @deprecated 3.4.0 Use send() instead.
	 *
	 * @param string $phone     Phone number.
	 * @param string $sender_id Sender ID.
	 * @param string $message   Message text.
	 * @param string $type      Message type for logging.
	 *
	 * @return array|WP_Error
	 */
	public function send_sms( $phone, $sender_id, $message, $type = 'login' ) {
		if ( function_exists( '_deprecated_function' ) ) {
			_deprecated_function( __METHOD__, '3.4.0', 'KwtSMS_API::send()' );
		}
		return $this->send( $phone, $sender_id, $message, $type );
	}

	/**
	 * Retrieve SMS coverage information for the account.
	 *
	 * Returns an array of coverage data (countries and their status).
	 *
	 * @return array|WP_Error Coverage data array on success.
	 */
	public function get_coverage() {
		$response = $this->request( 'coverage/', array() );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Normalize: try common wrapper keys first.
		if ( isset( $response['countries'] ) && is_array( $response['countries'] ) ) {
			return $response['countries'];
		}
		if ( isset( $response['coverage'] ) && is_array( $response['coverage'] ) ) {
			return $response['coverage'];
		}
		// The kwtsms API v4.1 returns {"result":"OK","prefixes":["965","966",...]}
		// Each element is a dial-code string; convert to {dial: "965"} objects so
		// the enrichment layer can resolve country names.
		if ( isset( $response['prefixes'] ) && is_array( $response['prefixes'] ) ) {
			$result = array();
			foreach ( $response['prefixes'] as $prefix ) {
				$prefix = (string) $prefix;
				if ( ctype_digit( $prefix ) ) { // ctype_digit() returns false for empty strings.
					$result[] = array( 'dial' => $prefix );
				}
			}
			return $result;
		}

		// Root-level response: strip known API meta keys.
		$meta_keys = array( 'result', 'status', 'code', 'description', 'message', 'error' );
		foreach ( $meta_keys as $k ) {
			unset( $response[ $k ] );
		}

		// If all remaining keys are digit strings (dial codes), the API returned a
		// {dial_code: status_or_name} map. Preserve the dial codes as 'dial' fields
		// so the enrichment layer can resolve country names from them.
		$remaining_keys = array_keys( $response );
		if ( ! empty( $remaining_keys ) && count( array_filter( $remaining_keys, 'ctype_digit' ) ) === count( $remaining_keys ) ) {
			$result = array();
			foreach ( $response as $dial => $value ) {
				$entry = array( 'dial' => (string) $dial );
				if ( is_string( $value ) && '' !== $value ) {
					$entry['name'] = $value; // may be country name or a status string; enrichment resolves.
				}
				$result[] = $entry;
			}
			return $result;
		}

		return array_values( $response );
	}

	/**
	 * Check whether the account has sufficient balance before sending an SMS.
	 *
	 * Logic (timestamp first, then balance):
	 *   1. Read cached timestamp. If null or older than 24h, refresh via live API.
	 *   2. After refresh (or if fresh), read the balance value.
	 *   3. If balance > 0, allow. If balance <= 0, block with recharge message.
	 *
	 * @return true|WP_Error True if sending is allowed; WP_Error if insufficient credits.
	 */
	public function check_balance_before_send() {
		$gw         = get_option( 'kwtsms_otp_gateway', array() );
		$updated_at = $gw['balance_updated_at'] ?? null;

		// ── Step 1: Check timestamp, refresh if stale ────────────────────────
		$is_stale = ( null === $updated_at ) || ( ( time() - (int) $updated_at ) > DAY_IN_SECONDS );

		if ( $is_stale ) {
			$live = $this->get_balance();
			if ( ! is_wp_error( $live ) ) {
				self::update_saved_balance( $live['available'], $live['purchased'] );
				// Re-read the option so $available reflects the fresh value.
				$gw = get_option( 'kwtsms_otp_gateway', array() );
			}
			// If API unreachable, fall through to use whatever cached value exists.
		}

		// ── Step 2: Use the (now-current) cached balance ─────────────────────
		$available = $gw['balance_available'] ?? null;

		// No balance has ever been recorded: allow the attempt so the first
		// send can go through and populate balance-after.
		if ( null === $available ) {
			return true;
		}

		if ( (float) $available > 0 ) {
			return true;
		}

		return new WP_Error(
			'no_balance',
			__( 'Insufficient SMS credits. Recharge at kwtsms.com and try again.', 'kwtsms' )
		);
	}

	/**
	 * Persist the latest account balance to the gateway option.
	 *
	 * Called after a successful live send or explicit balance check.
	 *
	 * @param float $available  Available credits.
	 * @param float $purchased  Total purchased credits (0 if unknown).
	 */
	public static function update_saved_balance( $available, $purchased = 0.0 ) {
		$gw                       = get_option( 'kwtsms_otp_gateway', array() );
		$gw['balance_available']  = (float) $available;
		$gw['balance_purchased']  = $purchased > 0.0 ? (float) $purchased : ( $gw['balance_purchased'] ?? null );
		$gw['balance_updated_at'] = time();
		update_option( 'kwtsms_otp_gateway', $gw );
	}

	/**
	 * Validate phone numbers before sending.
	 *
	 * @param string $phone Normalised phone number.
	 *
	 * @return string|WP_Error 'OK'|'ER'|'NR' on success, WP_Error on HTTP failure.
	 */
	public function validate_number( $phone ) {
		$response = $this->request( 'validate/', array( 'mobile' => $phone ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// The API returns an object with ER/NR/OK arrays.
		if ( isset( $response['mobile']['OK'] ) && in_array( $phone, (array) $response['mobile']['OK'], true ) ) {
			return 'OK';
		}
		if ( isset( $response['mobile']['NR'] ) && in_array( $phone, (array) $response['mobile']['NR'], true ) ) {
			return 'NR';
		}
		return 'ER';
	}

	// =========================================================================
	// Send log (last 20 OTP sends for admin log viewer)
	// =========================================================================

	/**
	 * Append an entry to the OTP send log (stored in wp_options, max 20 entries).
	 *
	 * @param string $phone  Normalised phone number.
	 * @param string $status 'sent' or 'failed'.
	 * @param string $type      Context type: 'login'|'reset'|'passwordless'|'welcome'|'test'.
	 * @param string $sender_id Sender ID used for this SMS.
	 */
	public static function append_send_log( $phone, $status, $type = '', $sender_id = '' ) {
		$log = get_option( 'kwtsms_otp_send_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'      => time(),
				'phone'     => sanitize_text_field( $phone ), // Full phone — admin-only view.
				'status'    => $status,
				'type'      => sanitize_key( $type ),
				'sender_id' => sanitize_text_field( $sender_id ),
			)
		);

		// Keep only last 20 entries.
		if ( count( $log ) > 20 ) {
			$log = array_slice( $log, 0, 20 );
		}

		update_option( 'kwtsms_otp_send_log', $log, false );
	}

	/**
	 * Append a full (unredacted) SMS send entry to the SMS history log.
	 *
	 * Stored in wp_options as kwtsms_otp_sms_history (max 500 entries).
	 * The full phone number and message text are stored here — only visible
	 * to administrators via the Logs page.
	 *
	 * @param string $phone          Full normalised phone number (e.g. 965XXXXXXXX).
	 * @param string $message        The exact message that was sent.
	 * @param string $status         'sent' or 'failed'.
	 * @param string $type           Context: 'login'|'reset'|'passwordless'|'welcome'|'test'.
	 * @param string $msg_id         Message ID returned by API, or empty on failure.
	 * @param string $sender_id      Sender ID used.
	 * @param array  $gateway_result Raw gateway API response array.
	 * @param string $api_username   API username for log attribution.
	 * @param array  $context        Optional metadata. Supports 'order_id' (int) for WooCommerce.
	 */
	public static function append_sms_history( $phone, $message, $status, $type, $msg_id = '', $sender_id = '', $gateway_result = array(), $api_username = '', $context = array() ) {
		// Merge with send_context set by send() if no explicit context passed.
		if ( empty( $context ) && ! empty( self::$send_context ) ) {
			$context = self::$send_context;
		}
		$log = get_option( 'kwtsms_otp_sms_history', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Redact OTP codes from the log for security. For OTP-type sends, replace
		// any sequence of 4-8 digits with **** so the plaintext code is not stored.
		$otp_types   = array(
			'login_otp',
			'passwordless_otp',
			'reset_otp',
			'checkout_otp',
			'cf7_gate',
			'wpforms_gate',
			'elementor_gate',
			'ninjaforms_gate',
			'gravityforms_gate',
			'reg_otp',
		);
		$log_message = in_array( $type, $otp_types, true )
			? preg_replace( '/\b\d{4,8}\b/', '****', $message )
			: $message;

		array_unshift(
			$log,
			array(
				'time'           => time(),
				'phone'          => sanitize_text_field( $phone ),
				'message'        => sanitize_textarea_field( $log_message ),
				'status'         => in_array( $status, array( 'sent', 'failed' ), true ) ? $status : 'failed',
				'type'           => sanitize_key( $type ),
				'msg_id'         => sanitize_text_field( $msg_id ),
				'sender_id'      => sanitize_text_field( $sender_id ),
				'api_username'   => sanitize_text_field( $api_username ),
				'order_id'       => absint( $context['order_id'] ?? 0 ),
				'gateway_result' => array(
					'ok'      => (bool) ( $gateway_result['ok'] ?? true ),
					'code'    => sanitize_text_field( $gateway_result['code'] ?? '' ),
					'message' => sanitize_text_field( $gateway_result['message'] ?? '' ),
				),
			)
		);

		// Cap at 500 entries.
		if ( count( $log ) > 500 ) {
			$log = array_slice( $log, 0, 500 );
		}

		update_option( 'kwtsms_otp_sms_history', $log, false );
	}

	/**
	 * Append an OTP attempt event to the attempt log.
	 *
	 * Stored in wp_options as kwtsms_otp_attempt_log (max 500 entries).
	 * Phone number is stored unredacted (admin-only view).
	 *
	 * @param int|null $user_id  WordPress user ID, or null for unauthenticated attempts.
	 * @param string   $phone    Normalised phone number.
	 * @param string   $ip       Client IP address.
	 * @param string   $action   'login'|'passwordless'|'reset'.
	 * @param string   $result   'success'|'wrong_code'|'expired'|'locked'|'rate_limited'|'brute_force'.
	 */
	public static function append_attempt_log( $user_id, $phone, $ip, $action, $result ) {
		$log = get_option( 'kwtsms_otp_attempt_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'    => time(),
				'user_id' => is_null( $user_id ) ? null : absint( $user_id ),
				'phone'   => sanitize_text_field( (string) $phone ),
				'ip'      => sanitize_text_field( $ip ),
				'action'  => sanitize_key( $action ),
				'result'  => sanitize_key( $result ),
			)
		);

		// Cap at 500 entries.
		if ( count( $log ) > 500 ) {
			$log = array_slice( $log, 0, 500 );
		}

		update_option( 'kwtsms_otp_attempt_log', $log, false );
	}

	// =========================================================================
	// Message sanitisation (static — used by send() and build_message)
	// =========================================================================

	/**
	 * Sanitise an SMS message before it is sent to the gateway.
	 *
	 * Performs four passes in order:
	 *
	 *  1. Strip HTML tags — admin-entered templates or WooCommerce order data can
	 *     contain markup that looks fine in a browser but breaks SMS encoding.
	 *
	 *  2. Replace non-breaking space (U+00A0) with a plain space — copy-pasted
	 *     content from web pages often contains NBSP which appears identical on
	 *     screen but is encoded differently and can corrupt UTF-8 SMS messages.
	 *
	 *  3. Strip invisible / directional Unicode characters — zero-width spaces,
	 *     joiners, BOM, RTL/LTR marks, soft hyphen, and emoji variation selectors.
	 *     These are invisible to the eye but can cause the gateway to reject the
	 *     message or increase segment count unexpectedly.
	 *
	 *  4. Strip emoji and non-SMS-compatible symbols — the kwtsms API silently
	 *     queues messages that contain emoji without delivering them. Covers all
	 *     known Unicode emoji blocks as of Unicode 15 (U+00A9–U+1FFFF + Tags).
	 *
	 * Arabic and other BMP characters are preserved untouched.
	 *
	 * @param string $message Raw message text.
	 *
	 * @return string Cleaned message ready for the API.
	 */
	public static function clean_message( $message ) {
		// 1. Strip HTML tags.
		$message = wp_strip_all_tags( (string) $message );

		// 2. Non-breaking space  regular space.
		$message = preg_replace( '/\x{00A0}/u', ' ', $message ) ?? $message;

		// 3. Invisible / directional Unicode characters:
		// U+00AD  Soft Hyphen
		// U+200B  Zero Width Space
		// U+200C  Zero Width Non-Joiner
		// U+200D  Zero Width Joiner (used in emoji ZWJ sequences)
		// U+200E  Left-to-Right Mark
		// U+200F  Right-to-Left Mark
		// U+202A–U+202E  Directional formatting (LRE, RLE, PDF, LRO, RLO)
		// U+2060  Word Joiner
		// U+FEFF  BOM / Zero Width No-Break Space
		// U+FE0E  Variation Selector-15 (force text presentation)
		// U+FE0F  Variation Selector-16 (force emoji presentation)
		$message = preg_replace(
			'/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}\x{FE0E}\x{FE0F}]/u',
			'',
			$message
		) ?? $message;

		// 4. Emoji and non-SMS-compatible symbols (Unicode 15, all known emoji blocks):
		// U+00A9–U+00AE   © ®
		// U+203C U+2049   ‼ ⁉
		// U+2122 U+2139   ™ ℹ
		// U+2194–U+2199   Arrow symbols
		// U+21A9–U+21AA   Hooking arrows
		// U+231A–U+231B   Watch / Hourglass
		// U+2328          Keyboard
		// U+23CF          Eject
		// U+23E9–U+23F3   Media / clock buttons
		// U+23F8–U+23FA   Pause / Stop / Record
		// U+24C2          Circled M
		// U+25AA–U+25AB   Small squares
		// U+25B6 U+25C0   Play / Reverse
		// U+25FB–U+25FE   Medium squares
		// U+2600–U+26FF   Miscellaneous Symbols (sun, phone, etc.)
		// U+2702–U+27B0   Dingbats
		// U+27BF          Double curly loop
		// U+2934–U+2935   Curved arrows
		// U+2B05–U+2B07   Arrow buttons
		// U+2B1B–U+2B1C   Large squares
		// U+2B50 U+2B55   Star / Circle
		// U+3030 U+303D   Wavy dash / Part alternation mark
		// U+3297 U+3299   Circled CJK ideographs
		// U+1F000–U+1FFFF Full supplementary emoji plane
		// U+E0000–U+E007F Tags (used in keycap / flag sequences)
		$message = preg_replace(
			'/[' .
			'\x{00A9}\x{00AE}' .
			'\x{203C}\x{2049}\x{2122}\x{2139}' .
			'\x{2194}-\x{2199}\x{21A9}-\x{21AA}' .
			'\x{231A}-\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}' .
			'\x{24C2}' .
			'\x{25AA}-\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}' .
			'\x{2600}-\x{26FF}' .
			'\x{2702}-\x{27B0}\x{27BF}' .
			'\x{2934}-\x{2935}\x{2B05}-\x{2B07}\x{2B1B}-\x{2B1C}\x{2B50}\x{2B55}' .
			'\x{3030}\x{303D}\x{3297}\x{3299}' .
			'\x{1F000}-\x{1FFFF}' .
			'\x{E0000}-\x{E007F}' .
			']/u',
			'',
			$message
		) ?? $message;

		return trim( $message );
	}

	// =========================================================================
	// Phone normalisation (static — used throughout the plugin)
	// =========================================================================

	/**
	 * Country-specific phone number validation rules.
	 *
	 * Each entry maps a dial code string to:
	 *   local_lengths  — valid digit counts AFTER the country code.
	 *   mobile_start   — valid first character(s) of the local number.
	 *                    If empty, any starting digit is accepted.
	 *
	 * Sources: ITU-T E.164, Wikipedia "Telephone numbers in [Country]",
	 * HowToCallAbroad.com, CountryCode.com. Kept in sync with the TypeScript
	 * PHONE_RULES table in kwtsms_shopify/app/lib/kwtsms/phone.ts.
	 */
	private static function get_phone_rules(): array {
		return array(
			// === GCC ===
			'965' => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '4', '5', '6', '9' ),
			),
			'966' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '5' ),
			),
			'971' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '5' ),
			),
			'973' => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '3', '6' ),
			),
			'974' => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '3', '5', '6', '7' ),
			),
			'968' => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '7', '9' ),
			),
			// === Levant ===
			'962' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'961' => array(
				'local_lengths' => array( 7, 8 ),
				'mobile_start'  => array( '3', '7', '8' ),
			),
			'970' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '5' ),
			),
			'964' => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '7' ),
			),
			'963' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			// === Other Arab ===
			'967' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'20'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '1' ),
			),
			'218' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			'216' => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '2', '4', '5', '9' ),
			),
			'212' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '7' ),
			),
			'213' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '5', '6', '7' ),
			),
			'249' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			// === Non-Arab Middle East ===
			'98'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '9' ),
			),
			'90'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '5' ),
			),
			'972' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '5' ),
			),
			// === South Asia ===
			'91'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '6', '7', '8', '9' ),
			),
			'92'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '3' ),
			),
			'880' => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '1' ),
			),
			'94'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'960' => array(
				'local_lengths' => array( 7 ),
				'mobile_start'  => array( '7', '9' ),
			),
			// === East Asia ===
			'86'  => array(
				'local_lengths' => array( 11 ),
				'mobile_start'  => array( '1' ),
			),
			'81'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '7', '8', '9' ),
			),
			'82'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '1' ),
			),
			'886' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			// === Southeast Asia ===
			'65'  => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '8', '9' ),
			),
			'60'  => array(
				'local_lengths' => array( 9, 10 ),
				'mobile_start'  => array( '1' ),
			),
			'62'  => array(
				'local_lengths' => array( 9, 10, 11, 12 ),
				'mobile_start'  => array( '8' ),
			),
			'63'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '9' ),
			),
			'66'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '8', '9' ),
			),
			'84'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '3', '5', '7', '8', '9' ),
			),
			'95'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			'855' => array(
				'local_lengths' => array( 8, 9 ),
				'mobile_start'  => array( '1', '6', '7', '8', '9' ),
			),
			'976' => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '6', '8', '9' ),
			),
			// === Europe ===
			'44'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '7' ),
			),
			'33'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '7' ),
			),
			'49'  => array(
				'local_lengths' => array( 10, 11 ),
				'mobile_start'  => array( '1' ),
			),
			'39'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '3' ),
			),
			'34'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '7' ),
			),
			'31'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6' ),
			),
			'32'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array(),
			),
			'41'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'43'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '6' ),
			),
			'47'  => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '4', '9' ),
			),
			'48'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array(),
			),
			'30'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '6' ),
			),
			'420' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '7' ),
			),
			'46'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'45'  => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array(),
			),
			'40'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'36'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array(),
			),
			'380' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array(),
			),
			// === Americas ===
			'1'   => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array(),
			),
			'52'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array(),
			),
			'55'  => array(
				'local_lengths' => array( 11 ),
				'mobile_start'  => array(),
			),
			'57'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '3' ),
			),
			'54'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '9' ),
			),
			'56'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			'58'  => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '4' ),
			),
			'51'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			'593' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '9' ),
			),
			'53'  => array(
				'local_lengths' => array( 8 ),
				'mobile_start'  => array( '5', '6' ),
			),
			// === Africa ===
			'27'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '7', '8' ),
			),
			'234' => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array( '7', '8', '9' ),
			),
			'254' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '1', '7' ),
			),
			'233' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '2', '5' ),
			),
			'251' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7', '9' ),
			),
			'255' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '7' ),
			),
			'256' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'237' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6' ),
			),
			'225' => array(
				'local_lengths' => array( 10 ),
				'mobile_start'  => array(),
			),
			'221' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			'252' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '6', '7' ),
			),
			'250' => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '7' ),
			),
			// === Oceania ===
			'61'  => array(
				'local_lengths' => array( 9 ),
				'mobile_start'  => array( '4' ),
			),
			'64'  => array(
				'local_lengths' => array( 8, 9, 10 ),
				'mobile_start'  => array( '2' ),
			),
		);
	}

	/**
	 * Country names keyed by dial code, for use in error messages.
	 */
	private static function get_country_names(): array {
		return array(
			'965' => 'Kuwait',
			'966' => 'Saudi Arabia',
			'971' => 'UAE',
			'973' => 'Bahrain',
			'974' => 'Qatar',
			'968' => 'Oman',
			'962' => 'Jordan',
			'961' => 'Lebanon',
			'970' => 'Palestine',
			'964' => 'Iraq',
			'963' => 'Syria',
			'967' => 'Yemen',
			'20'  => 'Egypt',
			'218' => 'Libya',
			'216' => 'Tunisia',
			'212' => 'Morocco',
			'213' => 'Algeria',
			'249' => 'Sudan',
			'98'  => 'Iran',
			'90'  => 'Turkey',
			'972' => 'Israel',
			'91'  => 'India',
			'92'  => 'Pakistan',
			'880' => 'Bangladesh',
			'94'  => 'Sri Lanka',
			'960' => 'Maldives',
			'86'  => 'China',
			'81'  => 'Japan',
			'82'  => 'South Korea',
			'886' => 'Taiwan',
			'65'  => 'Singapore',
			'60'  => 'Malaysia',
			'62'  => 'Indonesia',
			'63'  => 'Philippines',
			'66'  => 'Thailand',
			'84'  => 'Vietnam',
			'95'  => 'Myanmar',
			'855' => 'Cambodia',
			'976' => 'Mongolia',
			'44'  => 'UK',
			'33'  => 'France',
			'49'  => 'Germany',
			'39'  => 'Italy',
			'34'  => 'Spain',
			'31'  => 'Netherlands',
			'32'  => 'Belgium',
			'41'  => 'Switzerland',
			'43'  => 'Austria',
			'47'  => 'Norway',
			'48'  => 'Poland',
			'30'  => 'Greece',
			'420' => 'Czech Republic',
			'46'  => 'Sweden',
			'45'  => 'Denmark',
			'40'  => 'Romania',
			'36'  => 'Hungary',
			'380' => 'Ukraine',
			'1'   => 'USA/Canada',
			'52'  => 'Mexico',
			'55'  => 'Brazil',
			'57'  => 'Colombia',
			'54'  => 'Argentina',
			'56'  => 'Chile',
			'58'  => 'Venezuela',
			'51'  => 'Peru',
			'593' => 'Ecuador',
			'53'  => 'Cuba',
			'27'  => 'South Africa',
			'234' => 'Nigeria',
			'254' => 'Kenya',
			'233' => 'Ghana',
			'251' => 'Ethiopia',
			'255' => 'Tanzania',
			'256' => 'Uganda',
			'237' => 'Cameroon',
			'225' => 'Ivory Coast',
			'221' => 'Senegal',
			'252' => 'Somalia',
			'250' => 'Rwanda',
			'61'  => 'Australia',
			'64'  => 'New Zealand',
		);
	}

	/**
	 * Find the dial-code prefix of a normalised phone number.
	 *
	 * Tries 3-digit codes first, then 2-digit, then 1-digit (longest match wins).
	 * Only considers codes present in get_phone_rules().
	 *
	 * @param string $phone Normalised phone (digits only, no leading zeros).
	 * @return string|null Matched dial code, or null if no rules exist for this number.
	 */
	public static function find_country_code( string $phone ): ?string {
		$rules = self::get_phone_rules();
		foreach ( array( 3, 2, 1 ) as $len ) {
			if ( strlen( $phone ) >= $len ) {
				$prefix = substr( $phone, 0, $len );
				if ( isset( $rules[ $prefix ] ) ) {
					return $prefix;
				}
			}
		}
		return null;
	}

	/**
	 * Validate a normalised phone number against country-specific format rules.
	 *
	 * Checks local number length and (where defined) mobile starting digits.
	 * Numbers with no matching country rule pass through — only generic E.164
	 * length validation (already done in normalize_phone) applies to them.
	 *
	 * @param string $phone Normalised phone (digits only, no leading zeros).
	 * @return true|WP_Error True on pass, WP_Error with description on failure.
	 */
	public static function validate_phone_format( string $phone ) {
		$cc = self::find_country_code( $phone );
		if ( null === $cc ) {
			return true; // No country-specific rules — generic E.164 length is enough.
		}

		$rules        = self::get_phone_rules();
		$rule         = $rules[ $cc ];
		$local        = substr( $phone, strlen( $cc ) );
		$local_len    = strlen( $local );
		$names        = self::get_country_names();
		$country_name = isset( $names[ $cc ] ) ? $names[ $cc ] : ( '+' . $cc );

		// Validate local number length.
		if ( ! in_array( $local_len, $rule['local_lengths'], true ) ) {
			$expected = implode( ' or ', $rule['local_lengths'] );
			return new WP_Error(
				'invalid_phone_length',
				sprintf(
					/* translators: 1: country name, 2: expected digit count(s), 3: dial code, 4: actual digit count */
					__( 'Invalid %1$s number: expected %2$s local digits after +%3$s, got %4$d.', 'kwtsms' ),
					$country_name,
					$expected,
					$cc,
					$local_len
				)
			);
		}

		// Validate mobile starting digit (if rules defined for this country).
		if ( ! empty( $rule['mobile_start'] ) ) {
			$valid = false;
			foreach ( $rule['mobile_start'] as $start ) {
				if ( 0 === strpos( $local, $start ) ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				return new WP_Error(
					'invalid_phone_prefix',
					sprintf(
						/* translators: 1: country name, 2: dial code, 3: comma-separated list of valid starting digits */
						__( 'Invalid %1$s mobile number: local part after +%2$s must start with %3$s.', 'kwtsms' ),
						$country_name,
						$cc,
						implode( ', ', $rule['mobile_start'] )
					)
				);
			}
		}

		return true;
	}

	/**
	 * Normalise a phone number to international format (digits only, no prefix).
	 *
	 * Handles all common input variants:
	 *   +965XXXXXXXX      965XXXXXXXX
	 *   00965XXXXXXXX     965XXXXXXXX
	 *   965 XXXX XXXX     965XXXXXXXX
	 *   965-XXXX-XXXX     965XXXXXXXX
	 *   965XXXXXXXX written in Arabic-Indic or Persian digits -> 965XXXXXXXX
	 *   (2026-11-05 11:20 +03: examples use the 965XXXXXXXX placeholder; the earlier sample
	 *   number belongs to a real person, int-testnumber.)
	 *
	 * @param string $phone Raw phone input from user.
	 *
	 * @return string|WP_Error Normalised number, or WP_Error if invalid.
	 */
	public static function normalize_phone( $phone ) {
		// 1. Convert Arabic/Hindi-Indic numerals (٠١٢٣٤٥٦٧٨٩) to ASCII digits.
		$arabic_numerals = array( '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩' );
		$ascii_digits    = array( '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' );
		$phone           = str_replace( $arabic_numerals, $ascii_digits, $phone );

		// Also convert Eastern Arabic-Indic numerals (used in Persian/Urdu contexts).
		$eastern_numerals = array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' );
		$phone            = str_replace( $eastern_numerals, $ascii_digits, $phone );

		// 2. Strip leading +.
		$phone = preg_replace( '/^\+/', '', $phone );

		// 3. Remove all non-digit characters (spaces, dashes, dots, parentheses).
		$phone = preg_replace( '/\D/', '', $phone );

		// 4. Strip all leading zeros (trunk/international prefix: 0xxx, 00xxx, 000xxx…).
		$phone = ltrim( $phone, '0' );

		// 5. Validate: must be digits only, 7–15 characters (E.164 minimum is 7).
		if ( ! preg_match( '/^\d{7,15}$/', $phone ) ) {
			return new WP_Error(
				'invalid_phone',
				sprintf(
					/* translators: %s: the entered phone number */
					__( 'Please enter a valid phone number with country code (e.g. 965XXXXXXXX). Got: %s', 'kwtsms' ),
					esc_html( $phone )
				)
			);
		}

		// 6. Strip trunk zero embedded after country code (e.g. 9660559... => 966559...).
		// Some users enter the full dialled format: country_code + trunk_0 + local_number.
		// If we detect a country code and the local part starts with 0, try stripping it.
		$cc = self::find_country_code( $phone );
		if ( null !== $cc ) {
			$local = substr( $phone, strlen( $cc ) );
			if ( strlen( $local ) > 0 && '0' === $local[0] ) {
				$stripped_local = ltrim( $local, '0' );
				$candidate      = $cc . $stripped_local;
				// Only accept if the stripped version passes country-specific validation.
				$candidate_check = self::validate_phone_format( $candidate );
				if ( true === $candidate_check ) {
					$phone = $candidate;
				}
			}
		}

		// 7. Validate country-specific format (local length + mobile prefix).
		$format = self::validate_phone_format( $phone );
		if ( is_wp_error( $format ) ) {
			return $format;
		}

		return $phone;
	}

	// =========================================================================
	// Country-code helpers
	// =========================================================================

	/**
	 * Resolve the ISO2 country code for a normalised phone number.
	 *
	 * Algorithm: try the longest matching dial-code prefix first (up to 4 digits),
	 * falling back to shorter prefixes. Uses the local country-codes data file as
	 * the authoritative dial  ISO2 map.
	 *
	 * @param string $phone Normalised phone number (digits only, with country code).
	 * @return string ISO2 code (e.g. 'KW'), or empty string if unresolvable.
	 */
	public static function get_iso2_from_phone( string $phone ): string {
		if ( '' === $phone || ! defined( 'KWTSMS_OTP_DIR' ) ) {
			return '';
		}

		// Build a dial  ISO2 lookup map from the local data file.
		$countries = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';
		$dial_map  = array(); // Maps dial code string to ISO2 country code string.
		foreach ( $countries as $cc ) {
			if ( isset( $cc['dial'], $cc['iso2'] ) && '' !== $cc['dial'] ) {
				$dial_map[ (string) $cc['dial'] ] = $cc['iso2'];
			}
		}

		// Try longest prefix first (up to 4 digits) to handle e.g. '1268' (Antigua)
		// before '1' (US/Canada).
		for ( $len = 4; $len >= 1; $len-- ) {
			if ( strlen( $phone ) < $len ) {
				continue;
			}
			$prefix = substr( $phone, 0, $len );
			if ( isset( $dial_map[ $prefix ] ) ) {
				return $dial_map[ $prefix ];
			}
		}

		return '';
	}

	/**
	 * Auto-prepend the default country dial code to a local (short) number.
	 *
	 * If the stripped digit-only value has 5–8 digits it is treated as a local
	 * number and the dial code is prepended. Numbers that already contain a
	 * country code (> 9 stripped digits) are returned unchanged.
	 *
	 * Handles trunk-prefixed local numbers (single leading 0, e.g. Saudi 0559…,
	 * UAE 050…) in addition to the double-zero international prefix (00xxx).
	 * The threshold is 9 digits to cover countries whose local numbers are 9
	 * digits (Saudi Arabia, UAE, Jordan, etc.) matching the TypeScript normalize()
	 * implementation in kwtsms_shopify/app/lib/kwtsms/phone.ts.
	 *
	 * @param string $phone     Raw phone input (any format).
	 * @param string $dial_code Dial code to prepend, digits only (e.g. '965').
	 * @return string Possibly-modified phone string, ready for normalize_phone().
	 */
	public static function prepend_country_code_if_local( string $phone, string $dial_code ): string {
		$stripped = preg_replace( '/\D/', '', ltrim( trim( $phone ), '+' ) );
		$stripped = preg_replace( '/^00/', '', $stripped ); // Strip 00 international prefix.
		$stripped = preg_replace( '/^0/', '', $stripped );  // Strip single trunk digit (e.g. 0559… → 559…).
		if ( strlen( $stripped ) >= 5 && strlen( $stripped ) <= 9 ) {
			return $dial_code . $stripped;
		}
		return $phone;
	}

	/**
	 * Resolve the admin-configured default country dial code.
	 *
	 * Reads general.default_country_code (ISO2) from saved options, maps it to
	 * a dial code via the country-codes data file, and caches the result for the
	 * current request lifetime.
	 *
	 * @return string Dial code digits, e.g. '965'. Falls back to '965' (Kuwait).
	 */
	public static function get_default_dial_code(): string {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$general   = get_option( 'kwtsms_otp_general', array() );
		$iso2      = $general['default_country_code'] ?? 'KW';
		$countries = include KWTSMS_OTP_DIR . 'includes/data/country-codes.php';
		foreach ( $countries as $cc ) {
			if ( $cc['iso2'] === $iso2 ) {
				$cache = $cc['dial'];
				return $cache;
			}
		}
		$cache = '965'; // Default fallback: Kuwait.
		return $cache;
	}

	// =========================================================================
	// Error code mapping
	// =========================================================================

	/**
	 * Map a kwtsms API error code to a user-friendly translated message.
	 *
	 * Error codes are defined in the kwtsms API Documentation v4.1, pages 11-12.
	 * Raw error codes are NEVER shown to end users — only the mapped messages.
	 *
	 * @param string $code API error code (e.g. 'ERR003').
	 *
	 * @return string Translated user-facing message.
	 */
	public static function map_error_code( $code ) {
		$messages = array(
			'ERR001' => __( 'SMS service is temporarily unavailable. Please try again later.', 'kwtsms' ),
			'ERR002' => __( 'Gateway configuration error. Please contact the site administrator.', 'kwtsms' ),
			'ERR003' => __( 'Gateway authentication failed. Please contact the site administrator.', 'kwtsms' ),
			'ERR004' => __( 'SMS gateway is not enabled on this account. Please contact the site administrator.', 'kwtsms' ),
			'ERR005' => __( 'SMS gateway account is suspended. Please contact the site administrator.', 'kwtsms' ),
			'ERR006' => __( 'Phone number is not valid for SMS delivery.', 'kwtsms' ),
			'ERR007' => __( 'Too many recipients. Please try again.', 'kwtsms' ),
			'ERR008' => __( 'The configured sender ID is not allowed. Please contact the site administrator.', 'kwtsms' ),
			'ERR009' => __( 'SMS template is empty. Please contact the site administrator.', 'kwtsms' ),
			'ERR010' => __( 'Insufficient SMS credits. Please contact the site administrator.', 'kwtsms' ),
			'ERR011' => __( 'Insufficient SMS credits. Please contact the site administrator.', 'kwtsms' ),
			'ERR012' => __( 'Message is too long. Please contact the site administrator.', 'kwtsms' ),
			'ERR013' => __( 'SMS queue is full. Please try again in a few minutes.', 'kwtsms' ),
			'ERR019' => __( 'Delivery report not found.', 'kwtsms' ),
			'ERR020' => __( 'Message not found.', 'kwtsms' ),
			'ERR021' => __( 'Delivery report is not available for this message.', 'kwtsms' ),
			'ERR022' => __( 'Delivery reports are not ready yet. Please check back in 24 hours.', 'kwtsms' ),
			'ERR023' => __( 'Could not retrieve delivery report.', 'kwtsms' ),
			'ERR024' => __( 'Your request was blocked by a security policy. Please contact the site administrator.', 'kwtsms' ),
			'ERR025' => __( 'Phone number is not valid. Please enter a valid mobile number with country code.', 'kwtsms' ),
			'ERR026' => __( 'No SMS coverage for this destination. Please contact the site administrator.', 'kwtsms' ),
			'ERR027' => __( 'Message contains unsupported characters. Please contact the site administrator.', 'kwtsms' ),
			// 2026-10-22 15:37 +03: ERR028 is opt-in duplicate SMS protection with a per-account window (10 s default), never a fixed 15 s.
			'ERR028' => __( 'This number was sent the same message too recently. Please try again shortly.', 'kwtsms' ),
			'ERR029' => __( 'Message not found or message ID is incorrect.', 'kwtsms' ),
			'ERR030' => __( 'Message is stuck in queue. Please contact the site administrator.', 'kwtsms' ),
			'ERR031' => __( 'Message was rejected due to policy violations. Please contact the site administrator.', 'kwtsms' ),
			'ERR032' => __( 'Message was rejected as spam. Please contact the site administrator.', 'kwtsms' ),
			'ERR033' => __( 'No SMS coverage configured. Please contact the site administrator.', 'kwtsms' ),
		);

		return isset( $messages[ $code ] )
			? $messages[ $code ]
			: /* translators: %s: internal error code */ sprintf( __( 'An unexpected error occurred (%s). Please try again.', 'kwtsms' ), esc_html( $code ) );
	}

	// =========================================================================
	// Private HTTP layer
	// =========================================================================

	/**
	 * Send a POST request to the kwtsms JSON API.
	 *
	 * Always uses HTTPS. Always sends credentials in the JSON body (never in URL).
	 * Content-Type and Accept headers are set to application/json.
	 *
	 * @param string $endpoint API endpoint path (e.g. 'send/').
	 * @param array  $payload  Additional payload fields (credentials are appended here).
	 *
	 * @return array|WP_Error Decoded JSON response array on success.
	 */
	private function request( $endpoint, array $payload ) {
		if ( empty( $this->username ) || empty( $this->password ) ) {
			$err = new WP_Error(
				'kwtsms_no_credentials',
				__( 'kwtSMS API credentials are not configured. Please go to Settings  kwtSMS  Gateway and enter your API username and password.', 'kwtsms' )
			);
			$this->write_debug_log( "request({$endpoint})", 'ABORT: credentials missing (username or password empty)' );
			return $err;
		}

		// Credentials go in the body, never in the URL.
		$body = array_merge(
			array(
				'username' => $this->username,
				'password' => $this->password,
			),
			$payload
		);

		$url = self::BASE_URL . ltrim( $endpoint, '/' );

		// Log the endpoint-specific payload only — credentials are in $body, never in $payload,
		// so username and password are never written to the log file.
		$this->write_debug_log( "request({$endpoint})", 'POST ' . $url . ' payload=' . wp_json_encode( $payload ) );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => self::TIMEOUT,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode( $body ),
				// Enforce SSL verification.
				'sslverify' => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			$err = new WP_Error(
				'kwtsms_http_error',
				__( 'Could not connect to the SMS gateway. Please check your internet connection.', 'kwtsms' )
			);
			$this->write_debug_log( "request({$endpoint})", 'HTTP error: ' . $response->get_error_message() );
			return $err;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );

		$this->write_debug_log( "request({$endpoint})", "HTTP {$http_code} response: {$raw_body}" );

		$data = json_decode( $raw_body, true );

		if ( null === $data ) {
			$err = new WP_Error(
				'kwtsms_invalid_response',
				/* translators: %d: HTTP status code */ sprintf( __( 'Unexpected response from SMS gateway (HTTP %d). This may indicate a server-side issue at kwtsms.com.', 'kwtsms' ), (int) $http_code )
			);
			$this->write_debug_log( "request({$endpoint})", "JSON decode failed. Raw: {$raw_body}" );
			return $err;
		}

		// API returns an error object on failure, with result, code, and description fields.
		if ( isset( $data['result'] ) && 'ERROR' === $data['result'] ) {
			$error_code  = $data['code'] ?? 'UNKNOWN';
			$description = $data['description'] ?? '';
			$this->write_debug_log(
				"request({$endpoint})",
				"API ERROR: code={$error_code} description={$description} — this usually means wrong credentials, no Sender ID, or no credits."
			);
			return new WP_Error(
				'kwtsms_api_error_' . strtolower( $error_code ),
				self::map_error_code( $error_code ),
				array(
					'api_code'    => $error_code,
					'description' => $description,
				)
			);
		}

		$this->write_debug_log( "request({$endpoint})", 'SUCCESS result=' . wp_json_encode( $data ) );
		return $data;
	}

	// =========================================================================
	// Debug logging
	// =========================================================================

	/**
	 * Write a timestamped entry to the kwtsms debug log file.
	 *
	 * Only writes if debug_mode is enabled (admin setting: General  Debug Logging).
	 * Log file: wp-content/kwtsms-debug.log
	 *
	 * @param string $context Short label for the calling function.
	 * @param string $message Log message (credentials are never logged).
	 */
	/**
	 * Maximum debug log file size in bytes before rotation (1 MiB).
	 *
	 * @var int
	 */
	const DEBUG_LOG_MAX_BYTES = 1048576;

	/**
	 * Write an entry to the debug log file.
	 *
	 * @param string $context Short label for the log entry (e.g. 'send', 'verify').
	 * @param string $message Log message text.
	 */
	public function write_debug_log( $context, $message ) {
		if ( ! $this->debug_mode ) {
			return;
		}

		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) ) {
			return;
		}

		$log_path = $upload_dir['basedir'] . '/kwtsms-debug.log';

		// Rotate when the file reaches the size limit.
		if ( file_exists( $log_path ) && filesize( $log_path ) >= self::DEBUG_LOG_MAX_BYTES ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- rotating log file in uploads dir.
			rename( $log_path, $log_path . '.1' );
		}

		$line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] [kwtsms-otp] [' . $context . '] ' . $message . PHP_EOL;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- appending to plugin log in uploads dir.
		file_put_contents( $log_path, $line, FILE_APPEND | LOCK_EX );
	}
}
