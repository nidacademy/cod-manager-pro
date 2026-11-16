<?php
defined( 'ABSPATH' ) || exit;

/**
 * "COD Manager Pro" box on the WordPress Dashboard (Dashboard > Screen Options to show or hide it).
 * Today's work queue for everyone with access to orders; money figures for managers only.
 */
class CODM_Dashboard_Widget {

	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		if ( ! current_user_can( CODM_Roles::CAP_ORDERS ) ) {
			return;
		}
		wp_add_dashboard_widget( 'codm_status', __( 'COD Manager Pro', 'cod-manager-pro' ), array( __CLASS__, 'render' ) );
	}

	/** Number of orders per status. */
	private static function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . CODM_DB::table( 'orders' ) . ' GROUP BY status' ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}

	private static function sum( $counts, array $keys ) {
		$n = 0;
		foreach ( $keys as $k ) {
			$n += isset( $counts[ $k ] ) ? $counts[ $k ] : 0;
		}
		return $n;
	}

	public static function render() {
		global $wpdb;
		if ( ! CODM_DB::table_exists( CODM_DB::table( 'orders' ) ) ) {
			echo '<p>' . esc_html__( 'COD Manager Pro is not set up yet. Deactivate and activate the plugin again.', 'cod-manager-pro' ) . '</p>';
			return;
		}
		$c      = self::counts();
		$orders = admin_url( 'admin.php?page=codm-orders' );
		$queues = array(
			array( 'open', __( 'To call', 'cod-manager-pro' ), self::sum( $c, array( 'new', 'pending' ) ), '#e07a00' ),
			array( 'to_ship', __( 'To ship', 'cod-manager-pro' ), self::sum( $c, array( 'confirmed', 'processing', 'ready' ) ), '#4636c9' ),
			array( 'with_courier', __( 'With courier', 'cod-manager-pro' ), self::sum( $c, array( 'shipped', 'out_for_delivery' ) ), '#1f73b0' ),
		);
		$today  = CODM_DB::today();
		$new_today = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . CODM_DB::table( 'orders' ) . ' WHERE created_at >= %s', $today . ' 00:00:00' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$finance = current_user_can( CODM_Roles::CAP_FINANCE );
		?>
		<style>
			#codm_status .codm-w-q { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 4px 0 12px; }
			#codm_status .codm-w-q a { display: block; padding: 10px 10px 8px; border: 1px solid #dcdcde; border-top: 3px solid var(--c); border-radius: 4px; text-decoration: none; color: #1d2327; }
			#codm_status .codm-w-q a:hover { border-color: var(--c); }
			#codm_status .codm-w-q b { display: block; font-size: 22px; line-height: 1.1; }
			#codm_status .codm-w-q span { font-size: 12px; color: #50575e; }
			#codm_status .codm-w-kv { margin: 0; }
			#codm_status .codm-w-kv div { display: flex; justify-content: space-between; gap: 10px; padding: 6px 0; border-top: 1px solid #f0f0f1; }
			#codm_status .codm-w-kv dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; }
			#codm_status .codm-w-kv .neg { color: #b32d2e; }
			#codm_status .codm-w-kv .pos { color: #007a2f; }
			#codm_status .codm-w-h { margin: 12px 0 2px; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #646970; }
			#codm_status .codm-w-warn { margin: 12px 0 0; padding: 8px 10px; background: #fcf9e8; border-left: 4px solid #dba617; }
			#codm_status .codm-w-warn ul { margin: 4px 0 0 16px; list-style: disc; }
			#codm_status .codm-w-links { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
		</style>
		<div class="codm-w-q">
			<?php foreach ( $queues as $q ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'status', $q[0], $orders ) ); ?>" style="--c:<?php echo esc_attr( $q[3] ); ?>">
					<b><?php echo esc_html( number_format_i18n( $q[2] ) ); ?></b>
					<span><?php echo esc_html( $q[1] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
		<dl class="codm-w-kv">
			<div><dt><?php esc_html_e( 'New orders today', 'cod-manager-pro' ); ?></dt><dd><?php echo esc_html( number_format_i18n( $new_today ) ); ?></dd></div>
		</dl>
		<?php
		if ( $finance ) :
			$from = gmdate( 'Y-m-01', strtotime( $today ) );
			$s    = CODM_Finance::summary( $from, $today );
			?>
			<p class="codm-w-h"><?php esc_html_e( 'This month', 'cod-manager-pro' ); ?></p>
			<dl class="codm-w-kv">
				<div><dt><?php esc_html_e( 'Delivered orders', 'cod-manager-pro' ); ?></dt><dd><?php echo esc_html( number_format_i18n( (int) $s['delivered'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Delivery rate', 'cod-manager-pro' ); ?></dt><dd><?php echo null === $s['delivery_rate'] ? '—' : esc_html( number_format_i18n( (float) $s['delivery_rate'], 1 ) . ' %' ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Revenue (delivered)', 'cod-manager-pro' ); ?></dt><dd><?php echo esc_html( CODM_DB::money( $s['revenue'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Net profit', 'cod-manager-pro' ); ?></dt><dd class="<?php echo (float) $s['net_profit'] < 0 ? 'neg' : 'pos'; ?>"><?php echo esc_html( CODM_DB::money( $s['net_profit'] ) ); ?></dd></div>
				<div><dt><?php esc_html_e( 'Still owed by couriers', 'cod-manager-pro' ); ?></dt><dd><?php echo esc_html( CODM_DB::money( $s['pending_payouts'] ) ); ?></dd></div>
			</dl>
			<?php
			$steps = CODM_Extra::setup_steps();
			if ( $steps ) :
				?>
				<div class="codm-w-warn"><strong><?php esc_html_e( 'Finish the setup', 'cod-manager-pro' ); ?></strong>
					<ul>
						<?php foreach ( $steps as $st ) : ?>
							<li><a href="<?php echo esc_url( $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php
			endif;
		endif;
		?>
		<div class="codm-w-links">
			<a class="button button-primary" href="<?php echo esc_url( $orders ); ?>"><?php esc_html_e( 'Open orders', 'cod-manager-pro' ); ?></a>
			<?php if ( $finance ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=codm' ) ); ?>"><?php esc_html_e( 'Dashboard', 'cod-manager-pro' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-finance' ) ); ?>"><?php esc_html_e( 'Finance', 'cod-manager-pro' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}
}
