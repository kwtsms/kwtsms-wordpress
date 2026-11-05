<?php
/**
 * WooCommerce per-order custom SMS metabox.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Adds a "Send Custom SMS" metabox to the WooCommerce order edit screen.
 * Compatible with both Classic Orders and HPOS (High-Performance Order Storage,
 * WooCommerce 8.6+) via the stable wc_get_page_screen_id() API.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Woo_Metabox
 *
 * Registers and renders a metabox on the WooCommerce order screen that allows
 * admins to send a free-form SMS to any phone number, pre-populated with the
 * customer's billing phone.
 */
class KwtSMS_Woo_Metabox {

	/**
	 * KwtSMS API client.
	 *
	 * @var KwtSMS_API
	 */
	private $api;

	/**
	 * Plugin settings.
	 *
	 * @var KwtSMS_Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * Registers the metabox and the AJAX handler for sending a custom SMS.
	 *
	 * @param KwtSMS_API      $api      kwtsms API client.
	 * @param KwtSMS_Settings $settings Plugin settings helper.
	 */
	public function __construct( $api, $settings ) {
		$this->api      = $api;
		$this->settings = $settings;

		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'wp_ajax_kwtsms_woo_send_custom_sms', array( $this, 'ajax_send_custom_sms' ) );
	}

	/**
	 * Register the "Send Custom SMS" metabox on the WooCommerce order screen.
	 *
	 * Uses the stable wc_get_page_screen_id() API (WooCommerce 8.6+) when available,
	 * which handles both Classic Orders and HPOS transparently. Falls back to the
	 * legacy 'shop_order' post-type screen ID for older WooCommerce versions.
	 *
	 * This replaces the previous approach of introspecting the internal
	 * CustomOrdersTableController class, which is an unstable internal API.
	 */
	public function register_metabox(): void {
		// Use stable WC API (WC 8.6+) when available; fall back to legacy 'shop_order' screen.
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screen = wc_get_page_screen_id( 'shop-order' );
		} else {
			$screen = 'shop_order';
		}

		add_meta_box(
			'kwtsms-custom-sms',
			__( 'Send Custom SMS', 'kwtsms' ),
			array( $this, 'render_metabox' ),
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the metabox HTML.
	 *
	 * Accepts either a WP_Post (Classic Orders) or a WC_Order (HPOS) as the
	 * first argument. Pre-populates the phone field from the kwtsms_phone order
	 * meta (set at checkout OTP), falling back to the billing phone.
	 *
	 * @param \WP_Post|\WC_Order $post_or_order Post or order object passed by WP.
	 */
	public function render_metabox( $post_or_order ) {
		$order = ( $post_or_order instanceof WC_Order )
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		$phone = '';
		if ( $order ) {
			$phone = $order->get_meta( 'kwtsms_phone' );
			if ( empty( $phone ) ) {
				$phone = $order->get_billing_phone();
			}
		}

		// Per-order SMS log.
		$order_sms_log = array();
		if ( $order ) {
			$all_history = get_option( 'kwtsms_otp_sms_history', array() );
			if ( is_array( $all_history ) ) {
				$oid = $order->get_id();
				foreach ( $all_history as $entry ) {
					if ( isset( $entry['order_id'] ) && (int) $entry['order_id'] === $oid ) {
						$order_sms_log[] = $entry;
					}
				}
			}
		}

		?>
		<div id="kwtsms-metabox-wrap">
		<?php if ( ! empty( $order_sms_log ) ) : ?>
			<div class="kwtsms-order-sms-log" style="margin-bottom:12px;border-bottom:1px solid #ddd;padding-bottom:8px;">
				<strong><?php esc_html_e( 'SMS Log', 'kwtsms' ); ?></strong>
				<?php foreach ( $order_sms_log as $entry ) : ?>
					<div style="font-size:12px;margin:4px 0;padding:4px;background:#f9f9f9;">
						<span style="color:#666;"><?php echo esc_html( wp_date( 'M j, H:i', $entry['time'] ?? 0 ) ); ?></span>
						<span class="<?php echo 'sent' === ( $entry['status'] ?? '' ) ? 'dashicons dashicons-yes' : 'dashicons dashicons-no'; ?>" style="font-size:14px;width:14px;height:14px;"></span>
						<span><?php echo esc_html( mb_strimwidth( $entry['message'] ?? '', 0, 60, '...' ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
			<p>
				<label for="kwtsms-custom-sms-phone"><?php esc_html_e( 'Phone', 'kwtsms' ); ?></label><br>
				<input
					type="text"
					id="kwtsms-custom-sms-phone"
					name="kwtsms_custom_sms_phone"
					value="<?php echo esc_attr( $phone ); ?>"
					class="widefat"
					<?php /* 2026-11-05 11:21 +03: placeholder 965XXXXXXXX replaces a real person's number, int-testnumber. */ ?>
					placeholder="<?php esc_attr_e( 'e.g. 965XXXXXXXX', 'kwtsms' ); ?>"
				>
			</p>
			<p>
				<label for="kwtsms-custom-sms-message"><?php esc_html_e( 'Message', 'kwtsms' ); ?></label><br>
				<textarea
					id="kwtsms-custom-sms-message"
					name="kwtsms_custom_sms_message"
					rows="4"
					class="widefat"
					placeholder="<?php esc_attr_e( 'Type your SMS message...', 'kwtsms' ); ?>"
				></textarea>
			</p>
			<p>
				<button
					type="button"
					id="kwtsms-send-custom-sms-btn"
					class="button"
					data-order="<?php echo esc_attr( $order ? (string) $order->get_id() : '0' ); ?>"
					data-nonce="<?php echo esc_attr( wp_create_nonce( 'kwtsms_woo_custom_sms' ) ); ?>"
				>
					<?php esc_html_e( 'Send SMS', 'kwtsms' ); ?>
				</button>
				<span id="kwtsms-custom-sms-result" style="margin-left:8px;"></span>
			</p>
		</div>
		<?php
		$metabox_js = 'jQuery( function( $ ) {'
			. ' $( "#kwtsms-send-custom-sms-btn" ).on( "click", function() {'
			. '  var btn = $( this );'
			. '  btn.prop( "disabled", true );'
			. '  $.post( ajaxurl, {'
			. '   action:   "kwtsms_woo_send_custom_sms",'
			. '   order_id: btn.data( "order" ),'
			. '   phone:    $( "#kwtsms-custom-sms-phone" ).val(),'
			. '   message:  $( "#kwtsms-custom-sms-message" ).val(),'
			. '   nonce:    btn.data( "nonce" )'
			. '  }, function( r ) {'
			. '   btn.prop( "disabled", false );'
			. '   $( "#kwtsms-custom-sms-result" ).text('
			. '    r.success ? "\\u2713 Sent" : "\\u2717 " + r.data.message'
			. '   );'
			. '  } );'
			. ' } );'
			. '} );';

		// 2026-11-04 21:53 +03: src false, not '' (PHPStan with current WP stubs, CI red since today; inline-only handle).
		wp_register_script( 'kwtsms-woo-metabox', false, array( 'jquery' ), KWTSMS_OTP_VERSION, true );
		wp_enqueue_script( 'kwtsms-woo-metabox' );
		wp_add_inline_script( 'kwtsms-woo-metabox', $metabox_js, 'after' );
	}

	/**
	 * AJAX handler: send a custom SMS for a WooCommerce order.
	 *
	 * Validates the nonce and the edit_shop_orders capability before sending.
	 * Phone is normalised via KwtSMS_API::normalize_phone().
	 *
	 * Expected POST fields:
	 *   nonce    - kwtsms_woo_custom_sms nonce
	 *   order_id - WooCommerce order ID
	 *   phone    - Destination phone number
	 *   message  - SMS message text
	 */
	public function ajax_send_custom_sms() {
		check_ajax_referer( 'kwtsms_woo_custom_sms', 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'kwtsms' ) ) );
			return;
		}

		$order_id = absint( wp_unslash( $_POST['order_id'] ?? 0 ) );
		$phone    = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );

		if ( ! $order_id || ! $phone || ! $message ) {
			wp_send_json_error(
				array( 'message' => __( 'Order, phone, and message are required.', 'kwtsms' ) )
			);
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'kwtsms' ) ) );
			return;
		}

		$normalized = KwtSMS_API::normalize_phone( $phone );
		if ( is_wp_error( $normalized ) ) {
			wp_send_json_error( array( 'message' => $normalized->get_error_message() ) );
			return;
		}

		$result = $this->api->send(
			$normalized,
			$this->settings->get( 'gateway.sender_id', '' ),
			$message,
			'woo_custom',
			array( 'order_id' => $order_id )
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			return;
		}

		wp_send_json_success( array( 'message' => __( 'SMS sent.', 'kwtsms' ) ) );
	}
}
