<?php
/**
 * WooCommerce Stock & Inventory Alerts.
 *
 * Handles:
 *   - Low stock alert        (woocommerce_low_stock)
 *   - Out of stock alert     (woocommerce_no_stock)
 *   - Backorder alert        (woocommerce_product_on_backorder)
 *   - New product published  (transition_post_status on 'product' post type)
 *   - Back-in-stock notify   (woocommerce_product_set_stock_status to instock)
 *
 * All admin alerts send to the configured stock admin phone. The back-in-stock
 * notification also sends to each subscribed customer phone stored in
 * post meta key 'kwtsms_back_in_stock_subscribers'.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class KwtSMS_Woo_Stock
 */
class KwtSMS_Woo_Stock {

	/**
	 * Plugin reference.
	 *
	 * @var KwtSMS_Plugin
	 */
	private $plugin;

	/**
	 * Constructor. Registers hooks when WooCommerce is active.
	 *
	 * @param KwtSMS_Plugin $plugin Plugin manager.
	 */
	public function __construct( KwtSMS_Plugin $plugin ) {
		$this->plugin = $plugin;

		// Skip if WooCommerce integration disabled.
		if ( ! $this->plugin->settings->get( 'integrations.woo_enabled', 1 ) ) {
			return;
		}

		// D1: Admin stock alerts.
		if ( $this->plugin->settings->get( 'integrations.woo_low_stock_enabled', 1 ) ) {
			add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ), 10, 1 );
		}
		if ( $this->plugin->settings->get( 'integrations.woo_no_stock_enabled', 1 ) ) {
			add_action( 'woocommerce_no_stock', array( $this, 'on_no_stock' ), 10, 1 );
		}
		if ( $this->plugin->settings->get( 'integrations.woo_backorder_enabled', 1 ) ) {
			add_action( 'woocommerce_product_on_backorder', array( $this, 'on_backorder' ), 10, 1 );
		}

		// D2: New product published.
		if ( $this->plugin->settings->get( 'integrations.woo_new_product_enabled', 0 ) ) {
			add_action( 'transition_post_status', array( $this, 'on_product_published' ), 10, 3 );
		}

		// D1: Back-in-stock customer notifications.
		if ( $this->plugin->settings->get( 'integrations.woo_back_in_stock_enabled', 0 ) ) {
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status_changed' ), 10, 3 );
			add_action( 'woocommerce_single_product_summary', array( $this, 'render_back_in_stock_form' ), 31 );

			// AJAX: subscribe to back-in-stock notification.
			add_action( 'wp_ajax_kwtsms_back_in_stock_subscribe', array( $this, 'ajax_back_in_stock_subscribe' ) );
			add_action( 'wp_ajax_nopriv_kwtsms_back_in_stock_subscribe', array( $this, 'ajax_back_in_stock_subscribe' ) );
		}
	}

	// =========================================================================
	// D1: Admin stock alerts
	// =========================================================================

	/**
	 * Send admin SMS on low stock.
	 *
	 * @param WC_Product $product The product with low stock.
	 */
	public function on_low_stock( $product ) {
		$this->send_admin_stock_sms(
			'woo_tpl_low_stock',
			array(
				'{site_name}'    => get_bloginfo( 'name' ),
				'{product_name}' => $product->get_name(),
				'{quantity}'     => (string) $product->get_stock_quantity(),
			)
		);
	}

	/**
	 * Send admin SMS on out of stock.
	 *
	 * @param WC_Product $product The out-of-stock product.
	 */
	public function on_no_stock( $product ) {
		$this->send_admin_stock_sms(
			'woo_tpl_no_stock',
			array(
				'{site_name}'    => get_bloginfo( 'name' ),
				'{product_name}' => $product->get_name(),
			)
		);
	}

	/**
	 * Send admin SMS on backorder.
	 *
	 * @param array $args Hook arguments: {product: WC_Product, quantity: int, order: WC_Order}.
	 */
	public function on_backorder( $args ) {
		$product = $args['product'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			$this->plugin->api->write_debug_log( 'woo_stock', 'Skipped backorder alert: product object not available in hook args' );
			return;
		}
		$this->send_admin_stock_sms(
			'woo_tpl_backorder',
			array(
				'{site_name}'    => get_bloginfo( 'name' ),
				'{product_name}' => $product->get_name(),
			)
		);
	}

	// =========================================================================
	// D2: New product published
	// =========================================================================

	/**
	 * Send admin SMS when a WooCommerce product is first published.
	 *
	 * Fires only on first publish (old_status not 'publish', new_status 'publish',
	 * post_type 'product'). Re-publishing after update is ignored.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post object.
	 */
	public function on_product_published( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( 'product' !== $post->post_type ) {
			return;
		}

		$this->send_admin_stock_sms(
			'woo_tpl_new_product',
			array(
				'{site_name}'    => get_bloginfo( 'name' ),
				'{product_name}' => $post->post_title,
			)
		);
	}

	// =========================================================================
	// D1: Back-in-stock customer notifications
	// =========================================================================

	/**
	 * When a product transitions to 'instock', SMS all subscribers and clear the list.
	 *
	 * Subscribers are stored in post meta 'kwtsms_back_in_stock_subscribers' as a
	 * JSON-encoded array of normalized phone number strings.
	 *
	 * @param int        $product_id Product ID.
	 * @param string     $status     New stock status ('instock'|'outofstock'|'onbackorder').
	 * @param WC_Product $product    Product object.
	 */
	public function on_stock_status_changed( $product_id, $status, $product ) {
		if ( 'instock' !== $status ) {
			return;
		}

		$raw_subs = get_post_meta( $product_id, 'kwtsms_back_in_stock_subscribers', true );
		if ( empty( $raw_subs ) ) {
			$this->plugin->api->write_debug_log( 'back_in_stock', 'Product #' . $product_id . ' is back in stock but has no subscribers' );
			return;
		}

		$subscribers = json_decode( $raw_subs, true );
		if ( ! is_array( $subscribers ) || empty( $subscribers ) ) {
			$this->plugin->api->write_debug_log( 'back_in_stock', 'Product #' . $product_id . ' is back in stock but subscriber list is empty or malformed' );
			return;
		}

		$tpl = $this->build_stock_message(
			'woo_tpl_back_in_stock',
			array(
				'{site_name}'    => get_bloginfo( 'name' ),
				'{product_name}' => $product->get_name(),
			)
		);
		if ( '' === $tpl ) {
			$this->plugin->api->write_debug_log( 'back_in_stock', 'Product #' . $product_id . ' is back in stock with ' . count( $subscribers ) . ' subscriber(s) but template is empty or missing' );
			return;
		}

		$sender_id = (string) $this->plugin->settings->get( 'gateway.sender_id', '' );

		$phones = array_values(
			array_filter(
				$subscribers,
				function ( $p ) {
					return is_string( $p ) && '' !== $p;
				}
			)
		);
		if ( ! empty( $phones ) ) {
			$this->plugin->api->send( $phones, $sender_id, $tpl, 'back_in_stock' );
		}

		// Clear subscriber list after sending.
		delete_post_meta( $product_id, 'kwtsms_back_in_stock_subscribers' );
	}

	/**
	 * AJAX handler: subscribe a phone number to back-in-stock notifications.
	 *
	 * Expects POST: product_id (int), phone (string), nonce (kwtsms_bis_subscribe).
	 */
	public function ajax_back_in_stock_subscribe() {
		// Verify nonce first using a static action, so product_id is not read before authentication.
		$nonce = sanitize_key( wp_unslash( $_POST['nonce'] ?? '' ) );
		if ( ! wp_verify_nonce( $nonce, 'kwtsms_bis_subscribe' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'kwtsms' ) ) );
			return;
		}

		$product_id = absint( wp_unslash( $_POST['product_id'] ?? 0 ) );
		$raw        = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
		$raw        = KwtSMS_API::prepend_country_code_if_local( $raw, KwtSMS_API::get_default_dial_code() );
		$phone      = KwtSMS_API::normalize_phone( $raw );

		if ( is_wp_error( $phone ) ) {
			wp_send_json_error( array( 'message' => $phone->get_error_message() ) );
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'outofstock' !== $product->get_stock_status() ) {
			wp_send_json_error( array( 'message' => __( 'Product not available for notification.', 'kwtsms' ) ) );
			return;
		}

		$existing = json_decode( (string) get_post_meta( $product_id, 'kwtsms_back_in_stock_subscribers', true ), true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		if ( ! in_array( $phone, $existing, true ) ) {
			$existing[] = $phone;
			update_post_meta( $product_id, 'kwtsms_back_in_stock_subscribers', wp_json_encode( $existing ) );
		}

		wp_send_json_success( array( 'message' => __( 'You will be notified when this product is back in stock.', 'kwtsms' ) ) );
	}

	/**
	 * Render a "Notify me when back in stock" form on out-of-stock product pages.
	 */
	public function render_back_in_stock_form() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		if ( 'outofstock' !== $product->get_stock_status() ) {
			return;
		}

		$product_id = $product->get_id();
		$nonce      = wp_create_nonce( 'kwtsms_bis_subscribe' );
		?>
		<div id="kwtsms-bis-form" style="margin:16px 0;padding:12px;border:1px solid #ddd;border-radius:4px;">
			<p style="margin:0 0 8px;font-weight:600;">
				<?php esc_html_e( 'Notify me when back in stock', 'kwtsms' ); ?>
			</p>
			<input type="tel"
				id="kwtsms-bis-phone"
				placeholder="<?php esc_attr_e( 'Phone number', 'kwtsms' ); ?>"
				style="width:100%;padding:8px;margin-bottom:8px;box-sizing:border-box;" />
			<button type="button" id="kwtsms-bis-submit"
				style="background:#FFA200;color:#fff;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;">
				<?php esc_html_e( 'Notify Me', 'kwtsms' ); ?>
			</button>
			<span id="kwtsms-bis-msg" style="display:none;margin-left:8px;font-size:13px;"></span>
		</div>
		<?php
		// 2026-11-04 21:53 +03: src false, not '' (PHPStan with current WP stubs, CI red since today; inline-only handle).
		wp_register_script( 'kwtsms-bis', false, array(), KWTSMS_OTP_VERSION, true );
		wp_enqueue_script( 'kwtsms-bis' );
		wp_localize_script(
			'kwtsms-bis',
			'kwtSmsBisData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'productId' => (string) $product_id,
				'nonce'     => $nonce,
			)
		);
		wp_add_inline_script(
			'kwtsms-bis',
			'(function(){' .
			'var btn=document.getElementById("kwtsms-bis-submit");' .
			'var msg=document.getElementById("kwtsms-bis-msg");' .
			'var ph=document.getElementById("kwtsms-bis-phone");' .
			'if(!btn){return;}' .
			'var d=kwtSmsBisData;' .
			'btn.addEventListener("click",function(){' .
				'var data=new FormData();' .
				'data.append("action","kwtsms_back_in_stock_subscribe");' .
				'data.append("product_id",d.productId);' .
				'data.append("phone",ph.value);' .
				'data.append("nonce",d.nonce);' .
				'fetch(d.ajaxUrl,{method:"POST",body:data})' .
				'.then(function(r){return r.json();})' .
				'.then(function(res){' .
					'msg.style.display="inline";' .
					'msg.style.color=res.success?"#46b450":"#dc3232";' .
					'msg.textContent=res.data.message;' .
				'});' .
			'});' .
			'})();'
		);
		?>
		<?php
	}

	// =========================================================================
	// Shared helpers
	// =========================================================================

	/**
	 * Send an SMS to the configured stock admin phone(s).
	 *
	 * @param string $tpl_key      Template key under integrations settings.
	 * @param array  $placeholders Map of {placeholder} to replacement value.
	 */
	private function send_admin_stock_sms( $tpl_key, array $placeholders ) {
		$admin_phone = (string) $this->plugin->settings->get( 'integrations.woo_stock_admin_phone', '' );
		if ( '' === trim( $admin_phone ) ) {
			$admin_phone = (string) $this->plugin->settings->get( 'integrations.woo_admin_phone', '' );
		}
		if ( '' === trim( $admin_phone ) ) {
			$this->plugin->api->write_debug_log( 'woo_stock', 'Skipped stock alert (' . $tpl_key . '): no admin phone configured' );
			return;
		}

		$message = $this->build_stock_message( $tpl_key, $placeholders );
		if ( '' === $message ) {
			$this->plugin->api->write_debug_log( 'woo_stock', 'Skipped stock alert (' . $tpl_key . '): template empty or missing' );
			return;
		}

		$sender_id = (string) $this->plugin->settings->get( 'gateway.sender_id', '' );

		$dial_code = KwtSMS_API::get_default_dial_code();
		$phones    = array();
		foreach ( preg_split( '/[\s,]+/', $admin_phone, -1, PREG_SPLIT_NO_EMPTY ) as $raw ) {
			$phones[] = KwtSMS_API::prepend_country_code_if_local( $raw, $dial_code );
		}
		$this->plugin->api->send( $phones, $sender_id, $message, 'woo_stock' );
	}

	/**
	 * Build a stock alert message from template settings.
	 *
	 * @param string $tpl_key      Settings key under integrations (e.g. 'woo_tpl_low_stock').
	 * @param array  $placeholders Placeholder substitutions.
	 *
	 * @return string Rendered message, or empty string if template missing.
	 */
	private function build_stock_message( $tpl_key, array $placeholders ) {
		$templates = $this->plugin->settings->get_all_integration_templates();
		$tpl       = $templates[ $tpl_key ] ?? array();

		if ( empty( $tpl['en'] ) ) {
			return '';
		}

		$lang    = ( function_exists( 'is_rtl' ) && is_rtl() ) ? 'ar' : 'en';
		$message = $tpl[ $lang ] ?? $tpl['en'];

		return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $message );
	}
}
