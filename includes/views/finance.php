<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification
list( $codm_from, $codm_to ) = CODM_DB::date_range(
	isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
	isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''
);
// phpcs:enable
$codm_s        = CODM_Finance::summary( $codm_from, $codm_to );
$codm_pending  = CODM_Finance::pending_payouts();
$codm_payouts  = CODM_Finance::payouts( 30 );
$codm_expenses = CODM_Finance::expenses( $codm_from, $codm_to );
$codm_exp_all  = count( $codm_expenses ) >= 200 ? CODM_Finance::expenses_count( $codm_from, $codm_to ) : count( $codm_expenses );
$codm_profits  = CODM_Finance::order_profits( $codm_from, $codm_to, 25 );
$codm_cats     = CODM_Finance::expense_categories();
$codm_base     = admin_url( 'admin.php?page=codm-finance' );
$codm_today    = CODM_DB::today();
$codm_presets  = array(
	__( '7 days', 'cod-manager-pro' )     => array( gmdate( 'Y-m-d', strtotime( $codm_today . ' -6 days' ) ), $codm_today ),
	__( '30 days', 'cod-manager-pro' )    => array( gmdate( 'Y-m-d', strtotime( $codm_today . ' -29 days' ) ), $codm_today ),
	__( 'This month', 'cod-manager-pro' ) => array( gmdate( 'Y-m-01', strtotime( $codm_today ) ), $codm_today ),
	__( 'Last month', 'cod-manager-pro' ) => array( gmdate( 'Y-m-01', strtotime( gmdate( 'Y-m-01', strtotime( $codm_today ) ) . ' -1 month' ) ), gmdate( 'Y-m-t', strtotime( gmdate( 'Y-m-01', strtotime( $codm_today ) ) . ' -1 month' ) ) ),
	__( '90 days', 'cod-manager-pro' )    => array( gmdate( 'Y-m-d', strtotime( $codm_today . ' -89 days' ) ), $codm_today ),
);
$codm_money = function ( $v ) {
	return CODM_DB::money( $v );
};
$codm_signed = function ( $v ) {
	return '<span class="' . ( $v < 0 ? 'codm-neg' : 'codm-pos' ) . '">' . esc_html( CODM_DB::money( $v ) ) . '</span>';
};
?>
<div class="wrap codm">
	<?php CODM_Admin::header( 'codm-finance', __( 'Finance & COD payouts', 'cod-manager-pro' ) ); ?>

	<form class="codm-toolbar" method="get">
		<input type="hidden" name="page" value="codm-finance">
		<div class="codm-segment" role="group" aria-label="<?php esc_attr_e( 'Period', 'cod-manager-pro' ); ?>">
			<?php foreach ( $codm_presets as $codm_label => $codm_range ) : ?>
				<a class="<?php echo ( $codm_range[0] === $codm_from && $codm_range[1] === $codm_to ) ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'from' => $codm_range[0], 'to' => $codm_range[1] ), $codm_base ) ); ?>"><?php echo esc_html( $codm_label ); ?></a>
			<?php endforeach; ?>
		</div>
		<div class="codm-dates">
			<input type="date" name="from" value="<?php echo esc_attr( $codm_from ); ?>" aria-label="<?php esc_attr_e( 'From', 'cod-manager-pro' ); ?>">
			<span aria-hidden="true">–</span>
			<input type="date" name="to" value="<?php echo esc_attr( $codm_to ); ?>" aria-label="<?php esc_attr_e( 'To', 'cod-manager-pro' ); ?>">
			<button class="codm-btn"><?php esc_html_e( 'Apply', 'cod-manager-pro' ); ?></button>
		</div>
		<div class="codm-fin-export">
			<a class="codm-btn" href="<?php echo esc_url( CODM_Finance_Export::url( 'xlsx', $codm_from, $codm_to ) ); ?>"><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span><?php esc_html_e( 'Export Excel', 'cod-manager-pro' ); ?></a>
			<a class="codm-btn" target="_blank" rel="noopener" href="<?php echo esc_url( CODM_Finance_Export::url( 'print', $codm_from, $codm_to ) ); ?>"><span class="dashicons dashicons-pdf" aria-hidden="true"></span><?php esc_html_e( 'PDF report', 'cod-manager-pro' ); ?></a>
		</div>
	</form>

	<section class="codm-hero">
		<div class="codm-hero-main">
			<span class="codm-label"><?php esc_html_e( 'Net profit', 'cod-manager-pro' ); ?></span>
			<span class="codm-hero-value <?php echo $codm_s['net_profit'] < 0 ? 'codm-neg' : ''; ?>"><?php echo esc_html( $codm_money( $codm_s['net_profit'] ) ); ?></span>
			<span class="codm-hero-sub">
				<?php
				echo null === $codm_s['margin']
					? esc_html__( 'No delivered orders in this period yet.', 'cod-manager-pro' )
					/* translators: %s margin percentage */
					: esc_html( sprintf( __( '%s%% net margin on delivered revenue', 'cod-manager-pro' ), number_format_i18n( $codm_s['margin'], 1 ) ) );
				?>
			</span>
		</div>
		<dl class="codm-hero-side">
			<div><dt><?php esc_html_e( 'COD collected by couriers', 'cod-manager-pro' ); ?></dt><dd><?php echo esc_html( $codm_money( $codm_s['cod_collected'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Received from couriers', 'cod-manager-pro' ); ?></dt><dd><?php echo esc_html( $codm_money( $codm_s['cod_received'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Still owed by couriers', 'cod-manager-pro' ); ?></dt><dd><?php echo esc_html( $codm_money( $codm_s['pending_payouts'] ) ); ?></dd></div>
			<div><dt><?php esc_html_e( 'Cost per delivered order (ads)', 'cod-manager-pro' ); ?></dt><dd><?php echo null === $codm_s['cpa'] ? '—' : esc_html( $codm_money( $codm_s['cpa'] ) ); ?></dd></div>
		</dl>
	</section>

	<div class="codm-grid">
		<section class="codm-card">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Profit and loss', 'cod-manager-pro' ); ?></h2></div>
			<table class="codm-pl">
				<tr><th><?php esc_html_e( 'Sales value (all non-cancelled orders)', 'cod-manager-pro' ); ?></th><td class="codm-muted"><?php echo esc_html( $codm_money( $codm_s['order_value'] ) ); ?></td></tr>
				<tr class="codm-pl-strong"><th><?php esc_html_e( 'Revenue (delivered orders)', 'cod-manager-pro' ); ?></th><td><?php echo esc_html( $codm_money( $codm_s['revenue'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Product costs', 'cod-manager-pro' ); ?></th><td>− <?php echo esc_html( $codm_money( $codm_s['product_costs'] ) ); ?></td></tr>
				<tr class="codm-pl-strong"><th><?php esc_html_e( 'Gross profit', 'cod-manager-pro' ); ?></th><td><?php echo $codm_signed( $codm_s['gross_profit'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<tr><th><?php esc_html_e( 'Shipping costs', 'cod-manager-pro' ); ?></th><td>− <?php echo esc_html( $codm_money( $codm_s['shipping_costs'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Return costs', 'cod-manager-pro' ); ?></th><td>− <?php echo esc_html( $codm_money( $codm_s['return_costs'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Advertising', 'cod-manager-pro' ); ?></th><td>− <?php echo esc_html( $codm_money( $codm_s['ads'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Other expenses', 'cod-manager-pro' ); ?></th><td>− <?php echo esc_html( $codm_money( $codm_s['other_expenses'] ) ); ?></td></tr>
				<tr class="codm-pl-total"><th><?php esc_html_e( 'Net profit', 'cod-manager-pro' ); ?></th><td><?php echo $codm_signed( $codm_s['net_profit'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			</table>
			<p class="codm-help"><?php esc_html_e( 'Orders count by the date they were placed; expenses and payouts by their own date. Product costs come from the "Cost price" field on each WooCommerce product.', 'cod-manager-pro' ); ?></p>
		</section>

		<section class="codm-card codm-span-2" id="payouts">
			<div class="codm-card-head"><h2><?php esc_html_e( 'COD reconciliation', 'cod-manager-pro' ); ?></h2></div>
			<p class="codm-help"><?php esc_html_e( 'Delivered and returned orders not yet settled with the courier. Expected payout = COD collected − shipping fees − return fees.', 'cod-manager-pro' ); ?></p>
			<?php if ( ! $codm_pending ) : ?>
				<p class="codm-empty"><?php esc_html_e( 'Every delivered order is settled. New ones appear here once marked Delivered or Returned.', 'cod-manager-pro' ); ?></p>
			<?php endif; ?>
			<?php foreach ( $codm_pending as $codm_p ) : ?>
				<?php $codm_orders = $codm_p['company_id'] ? CODM_Finance::unsettled_orders( $codm_p['company_id'] ) : array(); ?>
				<details class="codm-payout">
					<summary>
						<strong><?php echo esc_html( $codm_p['company'] ); ?></strong>
						<span><?php echo esc_html( sprintf( /* translators: 1 delivered 2 returned */ __( '%1$d delivered, %2$d returned', 'cod-manager-pro' ), $codm_p['delivered'], $codm_p['returned'] ) ); ?></span>
						<span><?php esc_html_e( 'COD', 'cod-manager-pro' ); ?> <?php echo esc_html( $codm_money( $codm_p['cod'] ) ); ?></span>
						<span><?php esc_html_e( 'Fees', 'cod-manager-pro' ); ?> − <?php echo esc_html( $codm_money( $codm_p['fees'] ) ); ?></span>
						<b><?php echo esc_html( $codm_money( $codm_p['expected'] ) ); ?></b>
					</summary>
					<?php if ( ! $codm_p['company_id'] ) : ?>
						<p class="codm-help"><?php esc_html_e( 'These orders have no shipping company. Assign one from the Orders screen to settle them.', 'cod-manager-pro' ); ?></p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codm-payout-form">
							<?php wp_nonce_field( 'codm_record_payout' ); ?>
							<input type="hidden" name="action" value="codm_record_payout">
							<input type="hidden" name="company_id" value="<?php echo (int) $codm_p['company_id']; ?>">
							<div class="codm-table-wrap">
								<table class="codm-table codm-table--compact">
									<thead><tr>
										<th><input type="checkbox" class="codm-check-all" checked aria-label="<?php esc_attr_e( 'Select all', 'cod-manager-pro' ); ?>"></th>
										<th><?php esc_html_e( 'Order', 'cod-manager-pro' ); ?></th>
										<th><?php esc_html_e( 'Customer', 'cod-manager-pro' ); ?></th>
										<th><?php esc_html_e( 'Status', 'cod-manager-pro' ); ?></th>
										<th class="num"><?php esc_html_e( 'COD', 'cod-manager-pro' ); ?></th>
										<th class="num"><?php esc_html_e( 'Fees', 'cod-manager-pro' ); ?></th>
										<th class="num"><?php esc_html_e( 'Net', 'cod-manager-pro' ); ?></th>
									</tr></thead>
									<tbody>
									<?php foreach ( $codm_orders as $codm_o ) : ?>
										<?php
										$codm_net  = CODM_Finance::order_net_payout( $codm_o );
										$codm_fees = (float) $codm_o->shipping_cost + ( 'returned' === $codm_o->status ? (float) $codm_o->return_cost : 0 );
										?>
										<tr>
											<td><input type="checkbox" name="order_ids[]" value="<?php echo (int) $codm_o->order_id; ?>" data-net="<?php echo esc_attr( $codm_net ); ?>" checked aria-label="<?php echo esc_attr( '#' . $codm_o->order_number ); ?>"></td>
											<td>#<?php echo esc_html( $codm_o->order_number ); ?></td>
											<td><?php echo esc_html( $codm_o->customer_name ); ?> <span class="codm-muted"><?php echo esc_html( $codm_o->city ); ?></span></td>
											<td><span class="codm-badge codm-badge--<?php echo esc_attr( $codm_o->status ); ?>"><?php echo esc_html( CODM_Status::label( $codm_o->status ) ); ?></span></td>
											<td class="num"><?php echo 'delivered' === $codm_o->status ? esc_html( $codm_money( $codm_o->total ) ) : '—'; ?></td>
											<td class="num">− <?php echo esc_html( $codm_money( $codm_fees ) ); ?></td>
											<td class="num"><?php echo $codm_signed( $codm_net ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
										</tr>
									<?php endforeach; ?>
									</tbody>
								</table>
							</div>
							<div class="codm-form-row">
								<label><?php esc_html_e( 'Expected', 'cod-manager-pro' ); ?><output class="codm-expected" data-currency="<?php echo esc_attr( CODM_DB::setting( 'currency_label' ) ); ?>"><?php echo esc_html( $codm_money( $codm_p['expected'] ) ); ?></output></label>
								<label><?php esc_html_e( 'Amount received', 'cod-manager-pro' ); ?><input type="text" inputmode="decimal" name="amount_received" class="codm-input codm-received" value="<?php echo esc_attr( number_format( $codm_p['expected'], 2, '.', '' ) ); ?>" required></label>
								<label><?php esc_html_e( 'Transfer date', 'cod-manager-pro' ); ?><input type="date" name="payout_date" class="codm-input" value="<?php echo esc_attr( $codm_today ); ?>" required></label>
								<label><?php esc_html_e( 'Reference', 'cod-manager-pro' ); ?><input type="text" name="reference" class="codm-input" placeholder="<?php esc_attr_e( 'Bank transfer ref.', 'cod-manager-pro' ); ?>"></label>
								<label class="codm-grow"><?php esc_html_e( 'Notes', 'cod-manager-pro' ); ?><input type="text" name="notes" class="codm-input"></label>
								<button class="codm-btn codm-btn--primary"><?php esc_html_e( 'Record payout', 'cod-manager-pro' ); ?></button>
							</div>
							<p class="codm-diff" aria-live="polite"></p>
						</form>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>

			<h3 class="codm-subhead"><?php esc_html_e( 'Payout history', 'cod-manager-pro' ); ?></h3>
			<?php if ( ! $codm_payouts ) : ?>
				<p class="codm-muted"><?php esc_html_e( 'No payouts recorded yet.', 'cod-manager-pro' ); ?></p>
			<?php else : ?>
				<div class="codm-table-wrap">
					<table class="codm-table codm-table--compact">
						<thead><tr>
							<th><?php esc_html_e( 'Date', 'cod-manager-pro' ); ?></th>
							<th><?php esc_html_e( 'Company', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Orders', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Expected', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Received', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Difference', 'cod-manager-pro' ); ?></th>
							<th><?php esc_html_e( 'Reference', 'cod-manager-pro' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $codm_payouts as $codm_po ) : ?>
							<?php $codm_diff = (float) $codm_po->amount_received - (float) $codm_po->amount_expected; ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'd M Y', $codm_po->payout_date ) ); ?></td>
								<td><?php echo esc_html( CODM_Shipping::name( $codm_po->shipping_company_id ) ); ?></td>
								<td class="num"><?php echo (int) $codm_po->orders_count; ?></td>
								<td class="num"><?php echo esc_html( $codm_money( $codm_po->amount_expected ) ); ?></td>
								<td class="num"><?php echo esc_html( $codm_money( $codm_po->amount_received ) ); ?></td>
								<td class="num"><?php echo abs( $codm_diff ) < 0.01 ? '<span class="codm-pos">✓</span>' : $codm_signed( $codm_diff ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
								<td><?php echo esc_html( $codm_po->reference ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>

		<section class="codm-card codm-span-3" id="expenses">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Expenses', 'cod-manager-pro' ); ?></h2></div>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="codm-form-row">
				<?php wp_nonce_field( 'codm_add_expense' ); ?>
				<input type="hidden" name="action" value="codm_add_expense">
				<label><?php esc_html_e( 'Category', 'cod-manager-pro' ); ?>
					<select name="category" class="codm-input">
						<?php foreach ( $codm_cats as $codm_key => $codm_label ) : ?>
							<option value="<?php echo esc_attr( $codm_key ); ?>"><?php echo esc_html( $codm_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php echo esc_html( sprintf( /* translators: %s currency */ __( 'Amount (%s)', 'cod-manager-pro' ), CODM_DB::setting( 'currency_label' ) ) ); ?><input type="text" inputmode="decimal" name="amount" class="codm-input" required></label>
				<label><?php esc_html_e( 'Date', 'cod-manager-pro' ); ?><input type="date" name="expense_date" class="codm-input" value="<?php echo esc_attr( $codm_today ); ?>" required></label>
				<label class="codm-grow"><?php esc_html_e( 'Description', 'cod-manager-pro' ); ?><input type="text" name="description" class="codm-input" placeholder="<?php esc_attr_e( 'e.g. Facebook ads – campaign Ramadan', 'cod-manager-pro' ); ?>"></label>
				<button class="codm-btn codm-btn--primary"><?php esc_html_e( 'Add expense', 'cod-manager-pro' ); ?></button>
			</form>

			<?php if ( $codm_expenses ) : ?>
				<div class="codm-table-wrap">
					<table class="codm-table codm-table--compact">
						<thead><tr>
							<th><?php esc_html_e( 'Date', 'cod-manager-pro' ); ?></th>
							<th><?php esc_html_e( 'Category', 'cod-manager-pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Amount', 'cod-manager-pro' ); ?></th>
							<th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'cod-manager-pro' ); ?></span></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $codm_expenses as $codm_e ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'd M Y', $codm_e->expense_date ) ); ?></td>
								<td><?php echo esc_html( isset( $codm_cats[ $codm_e->category ] ) ? $codm_cats[ $codm_e->category ] : $codm_e->category ); ?></td>
								<td><?php echo esc_html( $codm_e->description ); ?></td>
								<td class="num"><?php echo esc_html( $codm_money( $codm_e->amount ) ); ?></td>
								<td class="num"><a class="codm-link codm-danger" onclick="return confirm('<?php echo esc_js( __( 'Delete this expense?', 'cod-manager-pro' ) ); ?>');" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=codm_delete_expense&id=' . (int) $codm_e->id ), 'codm_delete_expense_' . (int) $codm_e->id ) ); ?>"><?php esc_html_e( 'Delete', 'cod-manager-pro' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php if ( $codm_exp_all > count( $codm_expenses ) ) : ?>
					<p class="codm-muted">
						<?php
						/* translators: 1: shown 2: total */
						echo esc_html( sprintf( __( 'Showing the latest %1$d of %2$d expenses. Totals and the Excel/PDF export include all of them.', 'cod-manager-pro' ), count( $codm_expenses ), $codm_exp_all ) );
						?>
					</p>
				<?php endif; ?>
			<?php else : ?>
				<p class="codm-muted"><?php esc_html_e( 'No expenses in this period. Add your ad spend to see real net profit.', 'cod-manager-pro' ); ?></p>
			<?php endif; ?>
		</section>

		<section class="codm-card codm-span-3">
			<div class="codm-card-head"><h2><?php esc_html_e( 'Profit per order', 'cod-manager-pro' ); ?></h2></div>
			<?php if ( ! $codm_profits ) : ?>
				<p class="codm-muted"><?php esc_html_e( 'Delivered and returned orders from this period will appear here.', 'cod-manager-pro' ); ?></p>
			<?php else : ?>
				<div class="codm-table-wrap">
					<table class="codm-table codm-table--compact">
						<thead><tr>
							<th><?php esc_html_e( 'Order', 'cod-manager-pro' ); ?></th>
							<th><?php esc_html_e( 'Customer', 'cod-manager-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'COD amount', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Product cost', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Shipping', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Return fee', 'cod-manager-pro' ); ?></th>
							<th class="num"><?php esc_html_e( 'Profit', 'cod-manager-pro' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $codm_profits as $codm_o ) : ?>
							<tr>
								<td>#<?php echo esc_html( $codm_o->order_number ); ?></td>
								<td><?php echo esc_html( $codm_o->customer_name ); ?> <span class="codm-muted"><?php echo esc_html( $codm_o->city ); ?></span></td>
								<td><span class="codm-badge codm-badge--<?php echo esc_attr( $codm_o->status ); ?>"><?php echo esc_html( CODM_Status::label( $codm_o->status ) ); ?></span></td>
								<td class="num"><?php echo esc_html( $codm_money( $codm_o->total ) ); ?></td>
								<td class="num">
									<?php
									if ( 'returned' === $codm_o->status ) {
										echo '—';
									} elseif ( (float) $codm_o->product_cost > 0 ) {
										echo esc_html( $codm_money( $codm_o->product_cost ) );
									} else {
										echo '<span class="codm-warn" title="' . esc_attr__( 'No cost price on this product yet', 'cod-manager-pro' ) . '">' . esc_html__( 'Missing', 'cod-manager-pro' ) . '</span>';
									}
									?>
								</td>
								<td class="num"><?php echo esc_html( $codm_money( $codm_o->shipping_cost ) ); ?></td>
								<td class="num"><?php echo 'returned' === $codm_o->status ? esc_html( $codm_money( $codm_o->return_cost ) ) : '—'; ?></td>
								<td class="num"><?php echo $codm_signed( $codm_o->profit ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</div>
</div>
