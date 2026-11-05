<?php
/**
 * Admin View: Logs Page: SMS History + OTP Attempts.
 *
 * 2026-11-05 21:15 +03: banned dash/quote characters replaced with ASCII (SMS cost, project rule), int-dashes
 *
 * Tab 1, SMS History: full unredacted send log (phone + message).
 * Tab 2, OTP Attempts: verification events with IP, action, and result.
 *
 * Both logs support pagination (20 rows/page) and CSV export.
 *
 * @package KwtSMS_OTP
 */

defined( 'ABSPATH' ) || exit;

// phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- @var KwtSMS_Admin $this, injected by admin controller.

if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( esc_html__( 'You do not have permission to access this page.', 'kwtsms' ) );
}

// $kwtsms_active_tab and $kwtsms_current_page are set by the controller
// (KwtSMS_Admin::render_logs_page) before including this view.
$kwtsms_active_tab     = isset( $kwtsms_active_tab ) ? $kwtsms_active_tab : 'sms_history';
$kwtsms_current_page   = isset( $kwtsms_current_page ) ? (int) $kwtsms_current_page : 1;
$kwtsms_items_per_page = 20;

// Debug log tab variables: only relevant when debug_logging is enabled.
// NOTE: download/clear/export handlers are registered on admin_init in KwtSMS_Admin::handle_log_exports()
// so that Content-Type headers can be sent before any HTML output.
$kwtsms_uploads_dir      = wp_upload_dir();
$kwtsms_debug_log_path   = ! empty( $kwtsms_uploads_dir['basedir'] ) ? $kwtsms_uploads_dir['basedir'] . '/kwtsms-debug.log' : '';
$kwtsms_debug_logging_on = (bool) $this->plugin->settings->get( 'general.debug_logging', 0 );
$kwtsms_debug_log_exists = $kwtsms_debug_log_path && file_exists( $kwtsms_debug_log_path );
$kwtsms_show_debug_tab   = $kwtsms_debug_logging_on && $kwtsms_debug_log_exists;

// -------------------------------------------------------------------------
// Load log data for display.
// -------------------------------------------------------------------------
$kwtsms_sms_history = get_option( 'kwtsms_otp_sms_history', array() );
$kwtsms_attempt_log = get_option( 'kwtsms_otp_attempt_log', array() );
if ( ! is_array( $kwtsms_sms_history ) ) {
	$kwtsms_sms_history = array(); }
if ( ! is_array( $kwtsms_attempt_log ) ) {
	$kwtsms_attempt_log = array(); }

$kwtsms_active_log    = 'sms_history' === $kwtsms_active_tab ? $kwtsms_sms_history : $kwtsms_attempt_log;
$kwtsms_total_entries = count( $kwtsms_active_log );
$kwtsms_total_pages   = max( 1, (int) ceil( $kwtsms_total_entries / $kwtsms_items_per_page ) );
$kwtsms_current_page  = min( $kwtsms_current_page, $kwtsms_total_pages );
$kwtsms_offset        = ( $kwtsms_current_page - 1 ) * $kwtsms_items_per_page;
$kwtsms_page_entries  = array_slice( $kwtsms_active_log, $kwtsms_offset, $kwtsms_items_per_page );

/**
 * Build a tab URL for the Logs page.
 *
 * @param string $tab   Tab key.
 * @param array  $extra Additional query arguments.
 * @return string Admin URL with page + tab query args.
 */
function kwtsms_logs_tab_url( $tab, $extra = array() ) {
	return add_query_arg(
		array_merge(
			array(
				'page' => 'kwtsms-otp-logs',
				'tab'  => $tab,
			),
			$extra
		),
		admin_url( 'admin.php' )
	);
}

/**
 * Return an HTML label for an OTP attempt result code.
 *
 * @param string $result Attempt result code.
 * @return string HTML-formatted label string.
 */
function kwtsms_attempt_result_label( $result ) {
	$labels = array(
		'success'      => '<span style="color:#46b450;">' . esc_html__( 'Success', 'kwtsms' ) . '</span>',
		'wrong_code'   => '<span style="color:#f0ad4e;">' . esc_html__( 'Wrong code', 'kwtsms' ) . '</span>',
		'expired'      => '<span style="color:#888;">' . esc_html__( 'Expired', 'kwtsms' ) . '</span>',
		'locked'       => '<span style="color:#dc3232;">' . esc_html__( 'Locked (max attempts)', 'kwtsms' ) . '</span>',
		'rate_limited' => '<span style="color:#dc3232;">' . esc_html__( 'Rate limited', 'kwtsms' ) . '</span>',
		'brute_force'  => '<span style="color:#dc3232;font-weight:bold;">' . esc_html__( '⚠ Brute force', 'kwtsms' ) . '</span>',
	);
	return $labels[ $result ] ?? esc_html( $result );
}
?>

<div class="wrap kwtsms-admin-wrap">

	<?php $this->render_page_notices(); ?>

	<div class="kwtsms-admin-header">
		<img src="<?php echo esc_url( KWTSMS_OTP_URL . 'admin/images/kwtsms_logo_60.png' ); ?>" alt="kwtSMS" class="kwtsms-logo" />
		<h1><?php esc_html_e( 'Logs', 'kwtsms' ); ?></h1>
	</div>
	<hr class="wp-header-end">

	<div class="kwtsms-settings-card">
	<div class="kwtsms-settings-card-header">
		<h3><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'SMS Logs', 'kwtsms' ); ?></h3>
	</div>
	<div class="kwtsms-settings-card-body">

	<!-- Tabs -->
	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( kwtsms_logs_tab_url( 'sms_history' ) ); ?>"
			class="nav-tab <?php echo 'sms_history' === $kwtsms_active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'SMS History', 'kwtsms' ); ?>
		</a>
		<a href="<?php echo esc_url( kwtsms_logs_tab_url( 'attempt_log' ) ); ?>"
			class="nav-tab <?php echo 'attempt_log' === $kwtsms_active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'OTP Attempts', 'kwtsms' ); ?>
		</a>
		<?php if ( $kwtsms_show_debug_tab ) : ?>
		<a href="<?php echo esc_url( kwtsms_logs_tab_url( 'debug_log' ) ); ?>"
			class="nav-tab <?php echo 'debug_log' === $kwtsms_active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Debug Log', 'kwtsms' ); ?>
		</a>
		<?php endif; ?>
	</nav>

	<?php if ( 'debug_log' !== $kwtsms_active_tab && $kwtsms_total_entries > 0 ) : ?>
	<div class="kwtsms-log-toolbar" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'action'   => 'export_csv',
					'log'      => $kwtsms_active_tab,
					'_wpnonce' => wp_create_nonce( 'kwtsms_export_csv_' . $kwtsms_active_tab ),
				),
				admin_url( 'admin.php?page=kwtsms-otp-logs' )
			)
		);
		?>
		"
			class="button">
			⬇ <?php esc_html_e( 'Export CSV', 'kwtsms' ); ?>
		</a>

		<span style="color:#888;font-size:13px;">
			<?php
			printf(
				/* translators: %d total entries */
				esc_html__( '%d entries total', 'kwtsms' ),
				(int) $kwtsms_total_entries
			);
			?>
		</span>
	</div>
	<?php endif; ?>

	<?php if ( 'sms_history' === $kwtsms_active_tab ) : ?>
	<!-- ===== SMS History Tab ===== -->
		<?php if ( empty( $kwtsms_page_entries ) ) : ?>
	<p><?php esc_html_e( 'No SMS history yet.', 'kwtsms' ); ?></p>
	<?php else : ?>
	<table class="widefat striped kwtsms-log-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date / Time', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Sender ID', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Message', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Type', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Status', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Result', 'kwtsms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $kwtsms_page_entries as $kwtsms_entry ) : ?>
			<tr>
				<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $kwtsms_entry['time'] ?? 0 ) ); ?></td>
				<td><?php echo esc_html( $kwtsms_entry['sender_id'] ?? '' ); ?></td>
				<td style="max-width:400px;word-break:break-word;"><?php echo esc_html( $kwtsms_entry['message'] ?? '' ); ?></td>
				<td><?php echo esc_html( $kwtsms_entry['phone'] ?? '' ); ?></td>
				<td><?php echo esc_html( $kwtsms_entry['type'] ?? '' ); ?></td>
				<td style="color:<?php echo 'sent' === ( $kwtsms_entry['status'] ?? '' ) ? '#46b450' : '#dc3232'; ?>;">
					<?php echo 'sent' === ( $kwtsms_entry['status'] ?? '' ) ? esc_html__( 'Sent', 'kwtsms' ) : esc_html__( 'Failed', 'kwtsms' ); ?>
				</td>
				<td>
					<?php
					$kwtsms_gr = $kwtsms_entry['gateway_result'] ?? array();
					if ( ! empty( $kwtsms_gr ) ) :
						$kwtsms_gr_ok   = ! empty( $kwtsms_gr['ok'] );
						$kwtsms_gr_code = $kwtsms_gr['code'] ?? '';
						$kwtsms_gr_msg  = $kwtsms_gr['message'] ?? '';
						if ( $kwtsms_gr_ok ) {
							$kwtsms_gr_label = __( 'OK', 'kwtsms' );
							$kwtsms_gr_color = '#46b450';
						} else {
							$kwtsms_parts    = array_filter( array( $kwtsms_gr_code, $kwtsms_gr_msg ) );
							$kwtsms_gr_label = $kwtsms_parts ? implode( ': ', $kwtsms_parts ) : __( 'Error', 'kwtsms' );
							$kwtsms_gr_color = '#dc3232';
						}
						printf( '<span style="color:%s;">%s</span>', esc_attr( $kwtsms_gr_color ), esc_html( $kwtsms_gr_label ) );
					endif;
					?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php elseif ( 'attempt_log' === $kwtsms_active_tab ) : ?>
	<!-- ===== OTP Attempts Tab ===== -->
		<?php if ( empty( $kwtsms_page_entries ) ) : ?>
	<p><?php esc_html_e( 'No OTP attempts logged yet.', 'kwtsms' ); ?></p>
	<?php else : ?>
	<table class="widefat striped kwtsms-log-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date / Time', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'User', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Phone', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'IP Address', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Action', 'kwtsms' ); ?></th>
				<th><?php esc_html_e( 'Result', 'kwtsms' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php
			foreach ( $kwtsms_page_entries as $kwtsms_entry ) :
				$kwtsms_user_id = $kwtsms_entry['user_id'] ?? null;
				if ( $kwtsms_user_id ) {
					$kwtsms_user_data  = get_userdata( (int) $kwtsms_user_id );
					$kwtsms_user_label = $kwtsms_user_data
						? $kwtsms_user_data->user_login . ' (#' . (int) $kwtsms_user_id . ')'
						: '#' . (int) $kwtsms_user_id;
				} else {
					$kwtsms_user_label = '-';
				}
				?>
			<tr>
				<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $kwtsms_entry['time'] ?? 0 ) ); ?></td>
				<td>
				<?php
				echo esc_html( $kwtsms_user_label );
				?>
					</td>
				<td><?php echo esc_html( $kwtsms_entry['phone'] ?? '' ); ?></td>
				<td><?php echo esc_html( $kwtsms_entry['ip'] ?? '' ); ?></td>
				<td><?php echo esc_html( $kwtsms_entry['action'] ?? '' ); ?></td>
				<td><?php echo wp_kses( kwtsms_attempt_result_label( $kwtsms_entry['result'] ?? '' ), array( 'span' => array( 'style' => array() ) ) ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>

	<?php elseif ( $kwtsms_show_debug_tab ) : ?>
	<!-- ===== Debug Log Tab ===== -->
		<?php
		// Read file, reverse lines (newest first), paginate.
		$kwtsms_lines_raw       = file( $kwtsms_debug_log_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$kwtsms_lines_raw       = $kwtsms_lines_raw ? $kwtsms_lines_raw : array();
		$kwtsms_lines           = array_reverse( $kwtsms_lines_raw );
		$kwtsms_total_lines     = count( $kwtsms_lines );
		$kwtsms_per_page_dbg    = 100;
		$kwtsms_total_pages_dbg = max( 1, (int) ceil( $kwtsms_total_lines / $kwtsms_per_page_dbg ) );
		$kwtsms_cur_page_dbg    = min( $kwtsms_current_page, $kwtsms_total_pages_dbg );
		$kwtsms_offset_dbg      = ( $kwtsms_cur_page_dbg - 1 ) * $kwtsms_per_page_dbg;
		$kwtsms_page_lines      = array_slice( $kwtsms_lines, $kwtsms_offset_dbg, $kwtsms_per_page_dbg );
		?>

	<div class="kwtsms-log-toolbar" style="display:flex;gap:10px;align-items:center;margin:16px 0;">
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'action'   => 'download_debug_log',
					'_wpnonce' => wp_create_nonce( 'kwtsms_download_debug_log' ),
				),
				admin_url( 'admin.php?page=kwtsms-otp-logs&tab=debug_log' )
			)
		);
		?>
		"
			class="button">
			&#11015; <?php esc_html_e( 'Download', 'kwtsms' ); ?>
		</a>

	<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'action'   => 'clear_debug_log',
					'_wpnonce' => wp_create_nonce( 'kwtsms_clear_debug_log' ),
				),
				admin_url( 'admin.php?page=kwtsms-otp-logs&tab=debug_log' )
			)
		);
		?>
	"
		class="button"
		onclick="return confirm('<?php esc_attr_e( 'Clear the debug log? This cannot be undone.', 'kwtsms' ); ?>');">
		&#128465; <?php esc_html_e( 'Clear Log', 'kwtsms' ); ?>
	</a>

		<span style="color:#888;font-size:13px;">
			<?php
			printf(
				/* translators: %d: number of log lines */
				esc_html__( '%d lines total', 'kwtsms' ),
				(int) $kwtsms_total_lines
			);
			?>
		</span>
		<span style="color:#888;font-size:13px;margin-left:auto;">
		<?php
			// Show the full normalized path for clarity.
			$kwtsms_debug_log_display = wp_normalize_path( $kwtsms_debug_log_path );
			echo esc_html( $kwtsms_debug_log_display );
		?>
		</span>
	</div>

		<?php if ( empty( $kwtsms_page_lines ) ) : ?>
	<p><?php esc_html_e( 'The debug log is empty.', 'kwtsms' ); ?></p>
	<?php else : ?>
	<pre style="background:#1e1e1e;color:#d4d4d4;font-size:12px;line-height:1.6;padding:16px;border-radius:4px;overflow:auto;max-height:600px;white-space:pre-wrap;">
		<?php
		foreach ( $kwtsms_page_lines as $kwtsms_line ) {
			echo esc_html( $kwtsms_line ) . "\n";
		}
		?>
	</pre>

		<?php if ( $kwtsms_total_pages_dbg > 1 ) : ?>
	<div class="tablenav" style="margin-top:12px;">
		<div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', kwtsms_logs_tab_url( 'debug_log' ) ),
						'format'  => '',
						'current' => $kwtsms_cur_page_dbg,
						'total'   => $kwtsms_total_pages_dbg,
					)
				)
			);
			?>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>

	<?php endif; // end three-way tab. ?>

	<!-- Pagination -->
	<?php if ( $kwtsms_total_pages > 1 ) : ?>
	<div class="tablenav" style="margin-top:12px;">
		<div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%', kwtsms_logs_tab_url( $kwtsms_active_tab ) ),
						'format'  => '',
						'current' => $kwtsms_current_page,
						'total'   => $kwtsms_total_pages,
					)
				)
			);
			?>
		</div>
	</div>
	<?php endif; ?>

	</div><!-- .kwtsms-settings-card-body -->
	</div><!-- .kwtsms-settings-card -->
</div>
