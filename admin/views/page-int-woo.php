<?php
/**
 * Admin View: WooCommerce Integration Settings Sub-Page.
 *
 * Layout:
 *   Section 1 (top, no tabs): Settings cards stacked vertically.
 *     1. WooCommerce Integration (enable, admin phone, checkout OTP, COD-only).
 *     2. Customer SMS per Order Status checkboxes.
 *     3. Admin SMS Notifications (status checkboxes, uses main admin phone).
 *     4. Stock Alerts (phone override, enable toggles only, no templates).
 *     5. Cart Abandonment (enable, delay, coupon settings only, no templates).
 *     6. Multivendor (enable toggles, phone override only, no templates).
 *
 *   Section 2 (bottom, vertical tabs): ALL SMS templates grouped.
 *     Order Templates: Processing, Shipped, Completed, Cancelled, Pending, Refunded, Failed.
 *     Stock Alerts: Low Stock, Out of Stock, Backorder, New Product, Back in Stock.
 *     Abandoned Cart: Cart Recovery.
 *     Multivendor: Instant Order, Vendor SMS.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.

$kwtsms_settings   = $this->plugin->settings;
$kwtsms_int        = array_merge(
	KwtSMS_Settings::DEFAULTS['integrations'],
	(array) $kwtsms_settings->get( 'integrations' )
);
$kwtsms_templates  = $kwtsms_settings->get_all_integration_templates();
$kwtsms_woo_active = class_exists( 'WooCommerce' );

/*
 * All vertical-tab template definitions, organized by group.
 * Each entry: tab_key => array( tab_label, label, description, placeholders ).
 */
$kwtsms_all_vtab_defs = array(
	'order'       => array(
		'group_label' => __( 'Order Templates', 'kwtsms' ),
		'tabs'        => array(
			'woo_processing' => array(
				'tab_label'    => __( 'Processing', 'kwtsms' ),
				'label'        => __( 'New Order / Order Confirmed (Processing)', 'kwtsms' ),
				'description'  => __( "Sent immediately when a paid order is placed (credit card, PayPal, COD). This is the main 'new order' notification.", 'kwtsms' ),
				'placeholders' => '{order_id}, {total}, {site_name}, {customer_name}',
			),
			'woo_shipped'    => array(
				'tab_label'    => __( 'Shipped', 'kwtsms' ),
				'label'        => __( 'Order Shipped (On-Hold)', 'kwtsms' ),
				'description'  => __( 'Sent when the order status is set to On-Hold, typically used to indicate the order has been shipped.', 'kwtsms' ),
				'placeholders' => '{order_id}, {site_name}, {customer_name}',
			),
			'woo_completed'  => array(
				'tab_label'    => __( 'Completed', 'kwtsms' ),
				'label'        => __( 'Order Completed', 'kwtsms' ),
				'description'  => __( 'Sent when the order is marked as fully delivered and complete.', 'kwtsms' ),
				'placeholders' => '{order_id}, {site_name}, {customer_name}',
			),
			'woo_cancelled'  => array(
				'tab_label'    => __( 'Cancelled', 'kwtsms' ),
				'label'        => __( 'Order Cancelled', 'kwtsms' ),
				'description'  => __( 'Sent when the order is cancelled by the customer or admin.', 'kwtsms' ),
				'placeholders' => '{order_id}, {site_name}, {customer_name}',
			),
			'woo_pending'    => array(
				'tab_label'    => __( 'Pending', 'kwtsms' ),
				'label'        => __( 'New Order: Awaiting Payment (Pending)', 'kwtsms' ),
				'description'  => __( 'Sent when an order is placed but payment not yet received (e.g. bank transfer). Disabled by default.', 'kwtsms' ),
				'placeholders' => '{order_id}, {site_name}, {customer_name}',
			),
			'woo_refunded'   => array(
				'tab_label'    => __( 'Refunded', 'kwtsms' ),
				'label'        => __( 'Order Refunded', 'kwtsms' ),
				'description'  => __( 'Sent when a refund is issued for the order. Disabled by default.', 'kwtsms' ),
				'placeholders' => '{order_id}, {site_name}, {customer_name}',
			),
			'woo_failed'     => array(
				'tab_label'    => __( 'Failed', 'kwtsms' ),
				'label'        => __( 'Payment Failed: Order Not Confirmed', 'kwtsms' ),
				'description'  => __( 'Sent when the payment attempt fails and the order is not confirmed. Disabled by default.', 'kwtsms' ),
				'placeholders' => '{order_id}, {site_name}, {customer_name}',
			),
		),
	),
	'stock'       => array(
		'group_label' => __( 'Stock Alerts', 'kwtsms' ),
		'tabs'        => array(
			'woo_tpl_low_stock'     => array(
				'tab_label'    => __( 'Low Stock', 'kwtsms' ),
				'label'        => __( 'Low Stock Alert', 'kwtsms' ),
				'description'  => __( 'Sent to the stock admin phone when a product reaches its low stock threshold.', 'kwtsms' ),
				'placeholders' => '{site_name}, {product_name}, {quantity}',
			),
			'woo_tpl_no_stock'      => array(
				'tab_label'    => __( 'Out of Stock', 'kwtsms' ),
				'label'        => __( 'Out of Stock Alert', 'kwtsms' ),
				'description'  => __( 'Sent to the stock admin phone when a product goes out of stock.', 'kwtsms' ),
				'placeholders' => '{site_name}, {product_name}',
			),
			'woo_tpl_backorder'     => array(
				'tab_label'    => __( 'Backorder', 'kwtsms' ),
				'label'        => __( 'Backorder Alert', 'kwtsms' ),
				'description'  => __( 'Sent to the stock admin phone when a product is placed on backorder.', 'kwtsms' ),
				'placeholders' => '{site_name}, {product_name}',
			),
			'woo_tpl_new_product'   => array(
				'tab_label'    => __( 'New Product', 'kwtsms' ),
				'label'        => __( 'New Product SMS', 'kwtsms' ),
				'description'  => __( 'Sent to the stock admin phone when a new product is first published. Disabled by default.', 'kwtsms' ),
				'placeholders' => '{site_name}, {product_name}',
			),
			'woo_tpl_back_in_stock' => array(
				'tab_label'    => __( 'Back in Stock', 'kwtsms' ),
				'label'        => __( 'Back-in-Stock Notification', 'kwtsms' ),
				'description'  => __( 'When enabled, out-of-stock product pages show a subscribe form. Subscribers receive an SMS when the product comes back in stock.', 'kwtsms' ),
				'placeholders' => '{site_name}, {product_name}',
			),
		),
	),
	'cart'        => array(
		'group_label' => __( 'Abandoned Cart', 'kwtsms' ),
		'tabs'        => array(
			'woo_tpl_cart_abandon' => array(
				'tab_label'    => __( 'Cart Recovery', 'kwtsms' ),
				'label'        => __( 'Cart Abandonment Recovery SMS', 'kwtsms' ),
				'description'  => __( 'Message sent to the customer when their cart is considered abandoned.', 'kwtsms' ),
				'placeholders' => '{site_name}, {first_name}, {cart_total}, {coupon_code}, {discount}, {cart_url}',
			),
		),
	),
	'multivendor' => array(
		'group_label' => __( 'Multivendor', 'kwtsms' ),
		'tabs'        => array(
			'woo_tpl_instant_order'    => array(
				'tab_label'    => __( 'Instant Order', 'kwtsms' ),
				'label'        => __( 'Instant New Order Alert', 'kwtsms' ),
				'description'  => __( 'Send an SMS to the admin immediately when any new order is placed, before any status changes.', 'kwtsms' ),
				'placeholders' => '{site_name}, {order_id}, {customer_name}, {total}',
			),
			'woo_tpl_vendor_new_order' => array(
				'tab_label'    => __( 'Vendor SMS', 'kwtsms' ),
				'label'        => __( 'Vendor New Order SMS', 'kwtsms' ),
				'description'  => __( 'Requires Dokan, WCFM, or WC Vendors. Sends an SMS to each vendor whose product appears in a new order. Vendors must save their phone number in their WordPress user profile.', 'kwtsms' ),
				'placeholders' => '{site_name}, {order_id}, {product_name}, {total}',
			),
		),
	),
);

// Status enable/disable checklist used in the Settings section.
$kwtsms_customer_status_labels = array(
	'woo_processing' => array(
		'label' => __( 'New Order / Order Confirmed (Processing)', 'kwtsms' ),
		'hint'  => __( "Fires immediately when a paid order is placed (credit card, PayPal, COD). This is the main 'new order' notification.", 'kwtsms' ),
	),
	'woo_pending'    => array(
		'label' => __( 'New Order: Awaiting Payment (Pending)', 'kwtsms' ),
		'hint'  => __( 'Fires when an order is placed but payment has not been received yet (e.g. bank transfer). Disabled by default.', 'kwtsms' ),
	),
	'woo_failed'     => array(
		'label' => __( 'Payment Failed: Order Not Confirmed', 'kwtsms' ),
		'hint'  => __( 'Fires when the payment attempt fails and the order is not confirmed. Disabled by default.', 'kwtsms' ),
	),
	'woo_shipped'    => array(
		'label' => __( 'Order Shipped (On-Hold)', 'kwtsms' ),
		'hint'  => __( 'Fires when the order status is set to On-Hold, typically used to indicate the order has been shipped.', 'kwtsms' ),
	),
	'woo_completed'  => array(
		'label' => __( 'Order Completed', 'kwtsms' ),
		'hint'  => __( 'Fires when the order is marked as fully delivered and complete.', 'kwtsms' ),
	),
	'woo_cancelled'  => array(
		'label' => __( 'Order Cancelled', 'kwtsms' ),
		'hint'  => __( 'Fires when the order is cancelled by the customer or admin.', 'kwtsms' ),
	),
	'woo_refunded'   => array(
		'label' => __( 'Order Refunded', 'kwtsms' ),
		'hint'  => __( 'Fires when a refund is issued for the order. Disabled by default.', 'kwtsms' ),
	),
);

/*
 * Vertical-tabs JS: switch panels, update URL hash, set _save_section.
 */
wp_add_inline_script(
	'kwtsms-admin',
	'(function(){'
	. 'var tabs=document.querySelectorAll(".kwtsms-vtabs-nav a[data-vtab]");'
	. 'var panels=document.querySelectorAll(".kwtsms-vtabs-panel");'
	. 'var secInput=document.getElementById("kwtsms-vtab-save-section");'
	. 'function activate(key){'
		. 'tabs.forEach(function(t){t.classList.toggle("vtab-active",t.getAttribute("data-vtab")===key);});'
		. 'panels.forEach(function(p){p.classList.toggle("vtab-active",p.getAttribute("data-vtab-panel")===key);});'
		. 'if(secInput)secInput.value="woo";'
		. 'window.location.hash=key;'
	. '}'
	. 'tabs.forEach(function(t){'
		. 't.addEventListener("click",function(e){e.preventDefault();activate(this.getAttribute("data-vtab"));});'
	. '});'
	. 'var hash=window.location.hash.replace("#","");'
	. 'if(hash&&document.querySelector("[data-vtab-panel=\""+hash+"\"]")){activate(hash);}'
	. '}());'
);
?>
<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php esc_html_e( 'WooCommerce Settings', 'kwtsms' ); ?></h1>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=kwtsms-otp-integrations' ) ); ?>" class="button" style="margin-left:16px;align-self:center;">
			&larr; <?php esc_html_e( 'All Integrations', 'kwtsms' ); ?>
		</a>
	</div>
	<hr class="wp-header-end">

	<?php if ( ! $kwtsms_woo_active ) : ?>
	<div class="notice notice-warning inline" style="margin:16px 0;">
		<p><?php esc_html_e( 'WooCommerce is not installed or activated. The settings below will be saved and applied once WooCommerce is active.', 'kwtsms' ); ?></p>
	</div>
	<?php endif; ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'kwtsms_otp_integrations_group' ); ?>
		<input type="hidden" id="kwtsms-vtab-save-section" name="kwtsms_otp_integrations[_save_section]" value="woo" />

		<!-- ============================================================= -->
		<!-- SECTION 1: Settings cards (no tabs)                           -->
		<!-- ============================================================= -->

		<!-- Card 1: WooCommerce Integration -->
		<div class="kwtsms-settings-card">
			<div class="kwtsms-settings-card-header">
				<h3><span class="dashicons dashicons-cart"></span> <?php esc_html_e( 'WooCommerce Integration', 'kwtsms' ); ?></h3>
			</div>
			<div class="kwtsms-settings-card-body">
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Send SMS notifications for order status changes and registration events.', 'kwtsms' ); ?>
			</p>

			<table class="form-table" style="margin-top:12px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Integration', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_enabled]"
								value="1"
								<?php checked( $kwtsms_int['woo_enabled'], 1 ); ?> />
							<?php esc_html_e( 'Enable WooCommerce SMS Integration', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Admin Phone Numbers', 'kwtsms' ); ?></th>
					<td>
						<input type="text"
							name="kwtsms_otp_integrations[woo_admin_phone]"
							value="<?php echo esc_attr( $kwtsms_int['woo_admin_phone'] ?? '' ); ?>"
							class="regular-text"
							<?php /* 2026-11-05 11:21 +03: placeholder 965XXXXXXXX replaces real-looking numbers, int-testnumber. */ ?>
							placeholder="<?php esc_attr_e( 'e.g. 965XXXXXXXX, 965XXXXXXXX', 'kwtsms' ); ?>" />
						<p class="description"><?php esc_html_e( 'All WooCommerce SMS alerts are sent to these numbers. Individual features below can override this.', 'kwtsms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Checkout OTP', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_checkout_otp]"
								id="kwtsms-checkout-otp-toggle"
								value="1"
								<?php checked( $kwtsms_int['woo_checkout_otp'], 1 ); ?> />
							<?php esc_html_e( 'Require OTP verification before placing an order', 'kwtsms' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'When enabled, customers must verify their billing phone with an OTP before checkout completes. Recommended for reducing fraudulent orders.', 'kwtsms' ); ?>
						</p>
					</td>
				</tr>
				<tr id="kwtsms-cod-only-row"<?php echo empty( $kwtsms_int['woo_checkout_otp'] ) ? ' style="display:none;"' : ''; ?>>
					<th scope="row"><?php esc_html_e( 'COD Only', 'kwtsms' ); ?></th>
					<td>
						<label class="kwtsms-toggle">
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_checkout_otp_cod_only]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_checkout_otp_cod_only'] ) ); ?> />
							<span><?php esc_html_e( 'Require OTP only for Cash on Delivery orders', 'kwtsms' ); ?></span>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, card and other payment methods bypass the OTP gate. Only COD customers must verify.', 'kwtsms' ); ?></p>
					</td>
				</tr>
			</table>
			<?php
			wp_add_inline_script(
				'kwtsms-admin',
				'(function(){'
				. 'var toggle=document.getElementById("kwtsms-checkout-otp-toggle");'
				. 'var row=document.getElementById("kwtsms-cod-only-row");'
				. 'if(!toggle||!row)return;'
				. 'toggle.addEventListener("change",function(){'
					. 'row.style.display=this.checked?"":"none";'
				. '});'
				. '}());'
			);
			?>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.woo-integration-card -->

		<!-- Card 2: Customer SMS per Order Status -->
		<div class="kwtsms-settings-card">
			<div class="kwtsms-settings-card-header">
				<h3><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Customer SMS per Order Status', 'kwtsms' ); ?></h3>
			</div>
			<div class="kwtsms-settings-card-body">
			<p class="description">
				<?php esc_html_e( 'Select which order status changes trigger a customer SMS notification.', 'kwtsms' ); ?>
			</p>
			<table class="form-table" style="margin-top:8px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enabled statuses', 'kwtsms' ); ?></th>
					<td>
						<?php
						foreach ( $kwtsms_customer_status_labels as $kwtsms_csl_key => $kwtsms_csl_def ) :
							$kwtsms_csl_tpl = $kwtsms_templates[ $kwtsms_csl_key ] ?? array( 'enabled' => 0 );
							?>
						<div style="margin-bottom:10px;">
							<label style="display:block;">
								<input type="checkbox"
									name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_csl_key ); ?>][enabled]"
									value="1"
									<?php checked( $kwtsms_csl_tpl['enabled'], 1 ); ?> />
								<strong><?php echo esc_html( $kwtsms_csl_def['label'] ); ?></strong>
							</label>
							<p class="description" style="margin:2px 0 0 20px;"><?php echo esc_html( $kwtsms_csl_def['hint'] ); ?></p>
						</div>
						<?php endforeach; ?>
						<p class="description">
							<?php esc_html_e( 'Edit message text in the SMS Templates section below.', 'kwtsms' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.customer-status-card -->

		<!-- Card 3: Admin SMS Notifications -->
		<div class="kwtsms-settings-card">
			<div class="kwtsms-settings-card-header">
				<h3><span class="dashicons dashicons-businessman"></span> <?php esc_html_e( 'Admin SMS Notifications', 'kwtsms' ); ?></h3>
			</div>
			<div class="kwtsms-settings-card-body">
			<p class="description">
				<?php esc_html_e( 'Send an SMS to a store admin phone number when selected order status changes occur.', 'kwtsms' ); ?>
			</p>
			<table class="form-table" style="margin-top:12px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Notify admin for statuses', 'kwtsms' ); ?></th>
					<td>
						<?php
						$kwtsms_admin_notify_statuses = $kwtsms_int['woo_notify_admin_statuses'] ?? array();
						$kwtsms_status_options        = array(
							'processing' => __( 'New Order / Order Confirmed (Processing)', 'kwtsms' ),
							'pending'    => __( 'New Order: Awaiting Payment (Pending)', 'kwtsms' ),
							'failed'     => __( 'Payment Failed: Order Not Confirmed', 'kwtsms' ),
							'on-hold'    => __( 'Order Shipped (On-Hold)', 'kwtsms' ),
							'completed'  => __( 'Order Completed', 'kwtsms' ),
							'cancelled'  => __( 'Order Cancelled', 'kwtsms' ),
							'refunded'   => __( 'Order Refunded', 'kwtsms' ),
						);
						foreach ( $kwtsms_status_options as $kwtsms_slug => $kwtsms_label ) :
							?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_notify_admin_statuses][]"
								value="<?php echo esc_attr( $kwtsms_slug ); ?>"
								<?php checked( in_array( $kwtsms_slug, (array) $kwtsms_admin_notify_statuses, true ) ); ?> />
							<?php echo esc_html( $kwtsms_label ); ?>
						</label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Select which status changes trigger an admin SMS notification.', 'kwtsms' ); ?></p>
					</td>
				</tr>
			</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.admin-notification-card -->

		<!-- Card 4: Stock Alerts (settings only, templates below) -->
		<div class="kwtsms-settings-card">
			<div class="kwtsms-settings-card-header">
				<h3><span class="dashicons dashicons-archive"></span> <?php esc_html_e( 'Stock Alerts', 'kwtsms' ); ?></h3>
			</div>
			<div class="kwtsms-settings-card-body">
			<p class="description">
				<?php esc_html_e( 'SMS alerts for stock level changes. Edit message templates in the SMS Templates section below.', 'kwtsms' ); ?>
			</p>

			<table class="form-table" style="margin-top:12px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Phone Override (optional)', 'kwtsms' ); ?></th>
					<td>
						<input type="text"
							name="kwtsms_otp_integrations[woo_stock_admin_phone]"
							value="<?php echo esc_attr( $kwtsms_int['woo_stock_admin_phone'] ?? '' ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Leave blank to use main admin phone', 'kwtsms' ); ?>" />
						<p class="description"><?php esc_html_e( 'Override the main admin phone for stock alerts only. Leave empty to use the main phone above.', 'kwtsms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Low Stock', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_low_stock_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_low_stock_enabled'] ) ); ?> />
							<?php esc_html_e( 'Send SMS on low stock', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Out of Stock', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_no_stock_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_no_stock_enabled'] ) ); ?> />
							<?php esc_html_e( 'Send SMS on out of stock', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Backorder', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_backorder_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_backorder_enabled'] ) ); ?> />
							<?php esc_html_e( 'Send SMS on backorder', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'New Product', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_new_product_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_new_product_enabled'] ) ); ?> />
							<?php esc_html_e( 'Send SMS when a new product is published', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Back in Stock', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_back_in_stock_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_back_in_stock_enabled'] ) ); ?> />
							<?php esc_html_e( 'Enable back-in-stock subscriber notifications', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.stock-alerts-card -->

		<!-- Card 5: Cart Abandonment (settings only, template below) -->
		<div class="kwtsms-settings-card">
			<div class="kwtsms-settings-card-header">
				<h3><span class="dashicons dashicons-migrate"></span> <?php esc_html_e( 'Cart Abandonment Recovery', 'kwtsms' ); ?></h3>
			</div>
			<div class="kwtsms-settings-card-body">
			<p class="description">
				<?php esc_html_e( 'Automatically send a recovery SMS with an optional coupon code to customers who add items to their cart but do not complete checkout. Edit the message template in the SMS Templates section below.', 'kwtsms' ); ?>
			</p>
			<table class="form-table" style="margin-top:12px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_cart_abandon_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_cart_abandon_enabled'] ) ); ?> />
							<?php esc_html_e( 'Enable cart abandonment recovery SMS', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Send delay (minutes)', 'kwtsms' ); ?></th>
					<td>
						<input type="number"
							name="kwtsms_otp_integrations[woo_cart_abandon_delay]"
							value="<?php echo absint( $kwtsms_int['woo_cart_abandon_delay'] ?? 60 ); ?>"
							min="1"
							class="small-text" />
						<p class="description"><?php esc_html_e( 'Minutes of inactivity before the recovery SMS is sent.', 'kwtsms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Coupon discount (%)', 'kwtsms' ); ?></th>
					<td>
						<input type="number"
							name="kwtsms_otp_integrations[woo_cart_abandon_coupon]"
							value="<?php echo absint( $kwtsms_int['woo_cart_abandon_coupon'] ?? 10 ); ?>"
							min="0"
							max="100"
							class="small-text" />
						<p class="description"><?php esc_html_e( 'Set to 0 to disable coupon generation. Otherwise, a single-use percentage coupon will be created and included in the message.', 'kwtsms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Coupon expiry (hours)', 'kwtsms' ); ?></th>
					<td>
						<input type="number"
							name="kwtsms_otp_integrations[woo_cart_abandon_expiry]"
							value="<?php echo absint( $kwtsms_int['woo_cart_abandon_expiry'] ?? 48 ); ?>"
							min="1"
							class="small-text" />
						<p class="description"><?php esc_html_e( 'Hours until the generated coupon expires.', 'kwtsms' ); ?></p>
					</td>
				</tr>
			</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.cart-abandonment-card -->

		<!-- Card 6: Multivendor (settings only, templates below) -->
		<div class="kwtsms-settings-card">
			<div class="kwtsms-settings-card-header">
				<h3><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'Multivendor SMS', 'kwtsms' ); ?></h3>
			</div>
			<div class="kwtsms-settings-card-body">
			<p class="description">
				<?php esc_html_e( 'Instant new order alerts and vendor-specific SMS notifications for multivendor stores. Edit message templates in the SMS Templates section below.', 'kwtsms' ); ?>
			</p>
			<table class="form-table" style="margin-top:12px;">
				<tr>
					<th scope="row"><?php esc_html_e( 'Instant Order Alert', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_instant_order_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_instant_order_enabled'] ) ); ?> />
							<?php esc_html_e( 'Send SMS on new order', 'kwtsms' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Phone Override (optional)', 'kwtsms' ); ?></th>
					<td>
						<input type="text"
							name="kwtsms_otp_integrations[woo_instant_order_phone]"
							value="<?php echo esc_attr( $kwtsms_int['woo_instant_order_phone'] ?? '' ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'Leave blank to use main admin phone', 'kwtsms' ); ?>" />
						<p class="description"><?php esc_html_e( 'Override the main admin phone for instant order alerts only. Leave empty to use the main phone above.', 'kwtsms' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Vendor SMS', 'kwtsms' ); ?></th>
					<td>
						<label>
							<input type="checkbox"
								name="kwtsms_otp_integrations[woo_vendor_sms_enabled]"
								value="1"
								<?php checked( ! empty( $kwtsms_int['woo_vendor_sms_enabled'] ) ); ?> />
							<?php esc_html_e( 'Send SMS to vendors on new order', 'kwtsms' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Requires Dokan, WCFM, or WC Vendors. Vendors must save their phone number in their WordPress user profile.', 'kwtsms' ); ?></p>
					</td>
				</tr>
			</table>
		</div><!-- /.kwtsms-settings-card-body -->
		</div><!-- /.multivendor-card -->

		<!-- ============================================================= -->
		<!-- SECTION 2: SMS Templates (vertical tabs, ALL templates)       -->
		<!-- ============================================================= -->

		<h2 class="title"><?php esc_html_e( 'SMS Templates', 'kwtsms' ); ?></h2>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Edit the SMS message text for each notification type.', 'kwtsms' ); ?>
		</p>

		<div class="kwtsms-vtabs">
			<nav class="kwtsms-vtabs-nav">
				<?php
				$kwtsms_first_tab = true;
				foreach ( $kwtsms_all_vtab_defs as $kwtsms_group_key => $kwtsms_group ) :
					?>
				<div class="vtab-group"><?php echo esc_html( $kwtsms_group['group_label'] ); ?></div>
					<?php
					foreach ( $kwtsms_group['tabs'] as $kwtsms_tab_key => $kwtsms_tab_def ) :
						$kwtsms_active_class = $kwtsms_first_tab ? 'vtab-active' : '';
						$kwtsms_first_tab    = false;
						?>
				<a href="<?php echo esc_url( '#' . $kwtsms_tab_key ); ?>"
					data-vtab="<?php echo esc_attr( $kwtsms_tab_key ); ?>"
					class="<?php echo esc_attr( $kwtsms_active_class ); ?>">
						<?php echo esc_html( $kwtsms_tab_def['tab_label'] ); ?>
				</a>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</nav>

			<div class="kwtsms-vtabs-content">
				<?php
				$kwtsms_first_panel = true;
				foreach ( $kwtsms_all_vtab_defs as $kwtsms_group ) :
					foreach ( $kwtsms_group['tabs'] as $kwtsms_key => $kwtsms_def ) :
						$kwtsms_tpl         = $kwtsms_templates[ $kwtsms_key ] ?? array(
							'en' => '',
							'ar' => '',
						);
						$kwtsms_panel_class = $kwtsms_first_panel ? ' vtab-active' : '';
						$kwtsms_first_panel = false;
						$kwtsms_en_id       = 'int_' . $kwtsms_key . '_en';
						$kwtsms_ar_id       = 'int_' . $kwtsms_key . '_ar';
						?>
				<div class="kwtsms-vtabs-panel<?php echo esc_attr( $kwtsms_panel_class ); ?>"
					data-vtab-panel="<?php echo esc_attr( $kwtsms_key ); ?>">

					<div class="kwtsms-template-card">
						<div class="kwtsms-template-card-header">
							<h3><?php echo esc_html( $kwtsms_def['label'] ); ?></h3>
						</div>
						<p class="description"><?php echo esc_html( $kwtsms_def['description'] ); ?></p>
						<p class="description" style="margin-top:4px;">
							<strong><?php esc_html_e( 'Placeholders:', 'kwtsms' ); ?></strong>
							<code><?php echo esc_html( $kwtsms_def['placeholders'] ); ?></code>
						</p>

						<div class="kwtsms-lang-tabs">
							<div class="kwtsms-tab-nav">
								<button type="button" class="kwtsms-tab-btn is-active" data-tab="en"><?php esc_html_e( 'English', 'kwtsms' ); ?></button>
								<button type="button" class="kwtsms-tab-btn" data-tab="ar"><?php esc_html_e( 'Arabic', 'kwtsms' ); ?></button>
							</div>
							<div class="kwtsms-tab-pane" data-tab="en">
								<div class="kwtsms-textarea-wrap">
									<textarea
										name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_key ); ?>][en]"
										id="<?php echo esc_attr( $kwtsms_en_id ); ?>"
										class="large-text kwtsms-sms-textarea"
										rows="3"
										dir="ltr"
										data-lang="en"
									><?php echo esc_textarea( $kwtsms_tpl['en'] ); ?></textarea>
									<div class="kwtsms-char-counter" data-target="<?php echo esc_attr( $kwtsms_en_id ); ?>">
										<span class="kwtsms-char-count">0</span> <?php esc_html_e( 'characters', 'kwtsms' ); ?>
										&middot; <span class="kwtsms-page-count">1</span> <?php esc_html_e( 'SMS page(s)', 'kwtsms' ); ?>
									</div>
								</div>
							</div>
							<div class="kwtsms-tab-pane" data-tab="ar" style="display:none;">
								<div class="kwtsms-textarea-wrap">
									<textarea
										name="kwtsms_otp_integrations[<?php echo esc_attr( $kwtsms_key ); ?>][ar]"
										id="<?php echo esc_attr( $kwtsms_ar_id ); ?>"
										class="large-text kwtsms-sms-textarea"
										rows="3"
										dir="rtl"
										data-lang="ar"
									><?php echo esc_textarea( $kwtsms_tpl['ar'] ); ?></textarea>
									<div class="kwtsms-char-counter" data-target="<?php echo esc_attr( $kwtsms_ar_id ); ?>">
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

				</div><!-- /.kwtsms-vtabs-panel -->
					<?php endforeach; ?>
				<?php endforeach; ?>

			</div><!-- /.kwtsms-vtabs-content -->
		</div><!-- /.kwtsms-vtabs -->

		<?php submit_button( __( 'Save WooCommerce Settings', 'kwtsms' ), 'primary kwtsms-save-btn' ); ?>

	</form>

</div><!-- /.kwtsms-admin-wrap -->
