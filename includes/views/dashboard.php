<?php
defined( 'ABSPATH' ) || exit;
$codm_empty = ! CODM_DB::table_exists( CODM_DB::table( 'orders' ) ) || ! (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . CODM_DB::table( 'orders' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
?>
<div class="wrap codm">
	<?php CODM_Admin::header( 'codm', __( 'Dashboard', 'cod-manager-pro' ) ); ?>

	<?php if ( $codm_empty ) : ?>
		<div class="codm-card codm-onboard">
			<h2><?php esc_html_e( 'Bring in your orders', 'cod-manager-pro' ); ?></h2>
			<p><?php esc_html_e( 'New WooCommerce orders appear here automatically. To see your history right away, import the orders from the last 90 days.', 'cod-manager-pro' ); ?></p>
			<?php if ( current_user_can( CODM_Roles::CAP_SETTINGS ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'codm_tools' ); ?>
					<input type="hidden" name="action" value="codm_tools">
					<input type="hidden" name="do" value="import">
					<input type="hidden" name="days" value="90">
					<button class="codm-btn codm-btn--primary"><?php esc_html_e( 'Import last 90 days', 'cod-manager-pro' ); ?></button>
					<a class="codm-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-settings&tab=shipping' ) ); ?>"><?php esc_html_e( 'Add your shipping companies', 'cod-manager-pro' ); ?></a>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php $codm_steps = current_user_can( CODM_Roles::CAP_SETTINGS ) ? CODM_Extra::setup_steps() : array(); ?>
	<?php if ( $codm_steps ) : ?>
		<div class="codm-card codm-setup">
			<h2><?php esc_html_e( 'Finish setting up', 'cod-manager-pro' ); ?> <span class="codm-muted"><?php echo esc_html( sprintf( /* translators: %d steps */ _n( '%d step left', '%d steps left', count( $codm_steps ), 'cod-manager-pro' ), count( $codm_steps ) ) ); ?></span></h2>
			<p class="codm-muted"><?php esc_html_e( 'Until these are done, profit, payouts and labels are incomplete.', 'cod-manager-pro' ); ?></p>
			<ul class="codm-setup-list">
				<?php foreach ( $codm_steps as $codm_step ) : ?>
					<li><span><?php echo esc_html( $codm_step[0] ); ?></span><a class="codm-btn codm-btn--sm" href="<?php echo esc_url( $codm_step[1] ); ?>"><?php esc_html_e( 'Fix', 'cod-manager-pro' ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<div class="codm-toolbar" id="codm-range">
		<div class="codm-segment" role="group" aria-label="<?php esc_attr_e( 'Period', 'cod-manager-pro' ); ?>">
			<button type="button" data-range="today"><?php esc_html_e( 'Today', 'cod-manager-pro' ); ?></button>
			<button type="button" data-range="7"><?php esc_html_e( '7 days', 'cod-manager-pro' ); ?></button>
			<button type="button" data-range="30" class="is-active"><?php esc_html_e( '30 days', 'cod-manager-pro' ); ?></button>
			<button type="button" data-range="month"><?php esc_html_e( 'This month', 'cod-manager-pro' ); ?></button>
			<button type="button" data-range="last_month"><?php esc_html_e( 'Last month', 'cod-manager-pro' ); ?></button>
			<button type="button" data-range="90"><?php esc_html_e( '90 days', 'cod-manager-pro' ); ?></button>
		</div>
		<div class="codm-dates">
			<label><span class="screen-reader-text"><?php esc_html_e( 'From', 'cod-manager-pro' ); ?></span><input type="date" id="codm-from"></label>
			<span aria-hidden="true">–</span>
			<label><span class="screen-reader-text"><?php esc_html_e( 'To', 'cod-manager-pro' ); ?></span><input type="date" id="codm-to"></label>
		</div>
	</div>

	<section class="codm-hero" id="codm-hero" aria-live="polite">
		<div class="codm-hero-main">
			<span class="codm-label"><?php esc_html_e( 'Net profit', 'cod-manager-pro' ); ?></span>
			<span class="codm-hero-value" data-k="net_profit">—</span>
			<span class="codm-hero-sub" data-k="margin_text"></span>
		</div>
		<dl class="codm-hero-side">
			<div><dt><?php esc_html_e( 'Revenue (delivered)', 'cod-manager-pro' ); ?></dt><dd data-k="revenue">—</dd></div>
			<div><dt><?php esc_html_e( 'Delivery success', 'cod-manager-pro' ); ?></dt><dd data-k="delivery_rate">—</dd></div>
			<div><dt><?php esc_html_e( 'Return rate', 'cod-manager-pro' ); ?></dt><dd data-k="return_rate">—</dd></div>
			<div><dt><?php esc_html_e( 'Confirmation rate', 'cod-manager-pro' ); ?></dt><dd data-k="confirmation_rate">—</dd></div>
		</dl>
	</section>

	<section class="codm-kpis" id="codm-kpis">
		<a class="codm-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders' ) ); ?>"><span><?php esc_html_e( 'Total orders', 'cod-manager-pro' ); ?></span><strong data-k="orders">—</strong></a>
		<a class="codm-kpi codm-kpi--pending" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=open' ) ); ?>"><span><?php esc_html_e( 'Pending', 'cod-manager-pro' ); ?></span><strong data-k="pending">—</strong></a>
		<a class="codm-kpi codm-kpi--confirmed" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=confirmed' ) ); ?>"><span><?php esc_html_e( 'Confirmed', 'cod-manager-pro' ); ?></span><strong data-k="confirmed">—</strong></a>
		<a class="codm-kpi codm-kpi--shipped" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=shipped' ) ); ?>"><span><?php esc_html_e( 'Shipped', 'cod-manager-pro' ); ?></span><strong data-k="shipped">—</strong></a>
		<a class="codm-kpi codm-kpi--delivered" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=delivered' ) ); ?>"><span><?php esc_html_e( 'Delivered', 'cod-manager-pro' ); ?></span><strong data-k="delivered">—</strong></a>
		<a class="codm-kpi codm-kpi--cancelled" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=cancelled' ) ); ?>"><span><?php esc_html_e( 'Cancelled', 'cod-manager-pro' ); ?></span><strong data-k="cancelled">—</strong></a>
		<a class="codm-kpi codm-kpi--returned" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=returned' ) ); ?>"><span><?php esc_html_e( 'Returned', 'cod-manager-pro' ); ?></span><strong data-k="returned">—</strong></a>
		<a class="codm-kpi" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-finance' ) ); ?>"><span><?php esc_html_e( 'COD to collect from couriers', 'cod-manager-pro' ); ?></span><strong data-k="pending_payouts">—</strong></a>
	</section>

	<div class="codm-grid">
		<section class="codm-card codm-span-2">
			<div class="codm-card-head">
				<h2><?php esc_html_e( 'Sales', 'cod-manager-pro' ); ?></h2>
				<div class="codm-segment codm-segment--small" role="group" aria-label="<?php esc_attr_e( 'Group by', 'cod-manager-pro' ); ?>" id="codm-group">
					<button type="button" data-group="day" class="is-active"><?php esc_html_e( 'Daily', 'cod-manager-pro' ); ?></button>
					<button type="button" data-group="week"><?php esc_html_e( 'Weekly', 'cod-manager-pro' ); ?></button>
					<button type="button" data-group="month"><?php esc_html_e( 'Monthly', 'cod-manager-pro' ); ?></button>
				</div>
			</div>
			<div id="codm-sales-chart" class="codm-chart"></div>
		</section>

		<section class="codm-card">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Order status', 'cod-manager-pro' ); ?></h2></div>
			<div id="codm-status-chart" class="codm-donut-wrap"></div>
		</section>

		<section class="codm-card codm-span-3">
			<div class="codm-card-head">
				<h2><?php esc_html_e( 'Results by source', 'cod-manager-pro' ); ?></h2>
				<span class="codm-muted"><?php esc_html_e( 'Where your orders come from, and which sources confirm and get delivered', 'cod-manager-pro' ); ?></span>
			</div>
			<div id="codm-sources" class="codm-table-wrap"></div>
		</section>

		<section class="codm-card codm-span-2">
			<div class="codm-card-head">
				<h2><?php esc_html_e( 'Recent orders', 'cod-manager-pro' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders' ) ); ?>"><?php esc_html_e( 'All orders', 'cod-manager-pro' ); ?></a>
			</div>
			<div id="codm-recent" class="codm-table-wrap"></div>
		</section>

		<section class="codm-card">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Quick actions', 'cod-manager-pro' ); ?></h2></div>
			<div class="codm-quick">
				<a class="codm-btn codm-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=open' ) ); ?>"><?php esc_html_e( 'Confirm new orders', 'cod-manager-pro' ); ?></a>
				<a class="codm-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-orders&status=ready' ) ); ?>"><?php esc_html_e( 'Orders ready to ship', 'cod-manager-pro' ); ?></a>
				<a class="codm-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-finance#payouts' ) ); ?>"><?php esc_html_e( 'Record a courier payout', 'cod-manager-pro' ); ?></a>
				<a class="codm-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=codm-finance#expenses' ) ); ?>"><?php esc_html_e( 'Add ad spend', 'cod-manager-pro' ); ?></a>
			</div>
			<h3 class="codm-subhead"><?php esc_html_e( 'Low stock', 'cod-manager-pro' ); ?></h3>
			<ul id="codm-lowstock" class="codm-list"></ul>
		</section>
	</div>
</div>
