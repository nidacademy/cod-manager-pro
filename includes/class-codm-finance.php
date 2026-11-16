<?php
defined( 'ABSPATH' ) || exit;

/**
 * Financial calculations, expenses and courier (COD) settlements.
 *
 * Definitions (all amounts in the store currency, MAD):
 *  Revenue         = total COD amount of delivered orders (products + delivery fee charged)
 *  Product costs   = cost price x quantity of delivered orders
 *  Gross profit    = Revenue - Product costs
 *  Shipping costs  = courier fee of delivered and returned orders
 *  Return costs    = courier return fee of returned orders
 *  Net profit      = Gross profit - Shipping costs - Return costs - Advertising - Other expenses
 *
 * Orders are counted by the date they were placed; expenses by their own date.
 */
class CODM_Finance {

	public static function init() {
		add_action( 'admin_post_codm_add_expense', array( __CLASS__, 'handle_add_expense' ) );
		add_action( 'admin_post_codm_delete_expense', array( __CLASS__, 'handle_delete_expense' ) );
		add_action( 'admin_post_codm_record_payout', array( __CLASS__, 'handle_record_payout' ) );
	}

	public static function expense_categories() {
		return array(
			'ads'       => __( 'Advertising', 'cod-manager-pro' ),
			'packaging' => __( 'Packaging', 'cod-manager-pro' ),
			'salaries'  => __( 'Salaries & agents', 'cod-manager-pro' ),
			'shipping'  => __( 'Other shipping costs', 'cod-manager-pro' ),
			'tools'     => __( 'Apps & subscriptions', 'cod-manager-pro' ),
			'other'     => __( 'Other', 'cod-manager-pro' ),
		);
	}

	/**
	 * Profit of one order.
	 *  Delivered: total - product cost - shipping cost (final)
	 *  Returned:  -(shipping cost + return cost)        (final)
	 *  Cancelled: 0                                     (final)
	 *  Others:    expected profit if delivered          (not final)
	 */
	public static function order_profit( $r ) {
		$total    = (float) $r->total;
		$product  = (float) $r->product_cost;
		$shipping = (float) $r->shipping_cost;
		$return   = (float) $r->return_cost;
		switch ( $r->status ) {
			case 'delivered':
				return array( 'value' => round( $total - $product - $shipping, 2 ), 'final' => true );
			case 'returned':
				return array( 'value' => round( -( $shipping + $return ), 2 ), 'final' => true );
			case 'cancelled':
				return array( 'value' => 0.0, 'final' => true );
		}
		$shipping = $shipping > 0 ? $shipping : CODM_Shipping::default_cost( $r->shipping_company_id );
		return array( 'value' => round( $total - $product - $shipping, 2 ), 'final' => false );
	}

	private static function range_where( $from, $to ) {
		global $wpdb;
		return $wpdb->prepare( 'created_at BETWEEN %s AND %s', $from . ' 00:00:00', $to . ' 23:59:59' );
	}

	/** All KPIs for a date range. */
	public static function summary( $from, $to ) {
		global $wpdb;
		$t     = CODM_DB::table( 'orders' );
		$where = self::range_where( $from, $to );

		$status_sums = array();
		foreach ( array_keys( CODM_Status::all() ) as $s ) {
			$status_sums[] = "SUM(status = '" . esc_sql( $s ) . "') AS `n_" . esc_sql( $s ) . '`';
		}

		$r = $wpdb->get_row( "SELECT COUNT(*) AS orders, " . implode( ', ', $status_sums ) . ",
			SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END) AS revenue,
			SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END) AS order_value,
			SUM(CASE WHEN status = 'delivered' THEN product_cost ELSE 0 END) AS product_costs,
			SUM(CASE WHEN status IN ('delivered','returned') THEN shipping_cost ELSE 0 END) AS shipping_costs,
			SUM(CASE WHEN status = 'returned' THEN return_cost ELSE 0 END) AS return_costs,
			SUM(CASE WHEN status = 'delivered' THEN delivery_fee ELSE 0 END) AS delivery_fees
			FROM $t WHERE $where", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL

		$n = array();
		foreach ( array_keys( CODM_Status::all() ) as $s ) {
			$n[ $s ] = (int) $r[ 'n_' . $s ];
		}

		$expenses = array_fill_keys( array_keys( self::expense_categories() ), 0.0 );
		$exp_rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT category, SUM(amount) AS amount FROM ' . CODM_DB::table( 'expenses' ) . ' WHERE expense_date BETWEEN %s AND %s GROUP BY category',
			$from, $to
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $exp_rows as $e ) {
			$key              = isset( $expenses[ $e->category ] ) ? $e->category : 'other';
			$expenses[ $key ] += (float) $e->amount;
		}
		$ads   = $expenses['ads'];
		$other = array_sum( $expenses ) - $ads;

		$received = (float) $wpdb->get_var( $wpdb->prepare(
			'SELECT SUM(amount_received) FROM ' . CODM_DB::table( 'payouts' ) . ' WHERE payout_date BETWEEN %s AND %s',
			$from, $to
		) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$revenue        = (float) $r['revenue'];
		$product_costs  = (float) $r['product_costs'];
		$shipping_costs = (float) $r['shipping_costs'];
		$return_costs   = (float) $r['return_costs'];
		$gross          = $revenue - $product_costs;
		$net            = $gross - $shipping_costs - $return_costs - $ads - $other;

		$reached_confirm = $n['confirmed'] + $n['processing'] + $n['ready'] + $n['shipped'] + $n['out_for_delivery'] + $n['delivered'] + $n['returned'];
		$finished        = $n['delivered'] + $n['returned'];
		$pending_payouts = self::pending_payouts();

		return array(
			'from'               => $from,
			'to'                 => $to,
			'orders'             => (int) $r['orders'],
			'counts'             => $n,
			'pending'            => $n['new'] + $n['pending'],
			'confirmed'          => $n['confirmed'] + $n['processing'] + $n['ready'],
			'shipped'            => $n['shipped'] + $n['out_for_delivery'],
			'delivered'          => $n['delivered'],
			'cancelled'          => $n['cancelled'],
			'returned'           => $n['returned'],
			'revenue'            => round( $revenue, 2 ),
			'order_value'        => round( (float) $r['order_value'], 2 ),
			'delivery_fees'      => round( (float) $r['delivery_fees'], 2 ),
			'product_costs'      => round( $product_costs, 2 ),
			'shipping_costs'     => round( $shipping_costs, 2 ),
			'return_costs'       => round( $return_costs, 2 ),
			'ads'                => round( $ads, 2 ),
			'other_expenses'     => round( $other, 2 ),
			'expenses'           => $expenses,
			'gross_profit'       => round( $gross, 2 ),
			'net_profit'         => round( $net, 2 ),
			'cod_collected'      => round( $revenue, 2 ),
			'cod_received'       => round( $received, 2 ),
			'pending_payouts'    => round( array_sum( wp_list_pluck( $pending_payouts, 'expected' ) ), 2 ),
			'confirmation_rate'  => self::rate( $reached_confirm, $reached_confirm + $n['cancelled'] ),
			'delivery_rate'      => self::rate( $n['delivered'], $finished ),
			'return_rate'        => self::rate( $n['returned'], $finished ),
			'aov'                => $n['delivered'] ? round( $revenue / $n['delivered'], 2 ) : 0,
			'margin'             => $revenue > 0 ? round( 100 * $net / $revenue, 1 ) : null,
			'cpa'                => $n['delivered'] ? round( $ads / $n['delivered'], 2 ) : null,
		);
	}

	private static function rate( $part, $whole ) {
		return $whole > 0 ? round( 100 * $part / $whole, 1 ) : null;
	}

	/**
	 * Time series for charts: orders, delivered revenue and order profit per bucket.
	 * Advertising and other expenses are not spread over the buckets.
	 */
	public static function series( $from, $to, $group = 'day' ) {
		global $wpdb;
		$t     = CODM_DB::table( 'orders' );
		$where = self::range_where( $from, $to );

		switch ( $group ) {
			case 'week':
				$bucket = 'DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))';
				break;
			case 'month':
				$bucket = "DATE_FORMAT(created_at, '%Y-%m-01')";
				break;
			default:
				$group  = 'day';
				$bucket = 'DATE(created_at)';
		}

		$rows = $wpdb->get_results( "SELECT $bucket AS bucket, COUNT(*) AS orders,
			SUM(status = 'delivered') AS delivered,
			SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END) AS revenue,
			SUM(CASE WHEN status = 'delivered' THEN total - product_cost - shipping_cost
			         WHEN status = 'returned' THEN -(shipping_cost + return_cost) ELSE 0 END) AS profit
			FROM $t WHERE $where GROUP BY bucket ORDER BY bucket ASC", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL

		// Fill empty buckets so the chart has a continuous axis.
		$out    = array();
		$cursor = strtotime( $from );
		if ( 'week' === $group ) {
			$cursor = strtotime( '-' . ( (int) gmdate( 'N', $cursor ) - 1 ) . ' days', $cursor );
		} elseif ( 'month' === $group ) {
			$cursor = strtotime( gmdate( 'Y-m-01', $cursor ) );
		}
		$end   = strtotime( $to );
		$guard = 0;
		while ( $cursor <= $end && $guard++ < 400 ) {
			$key = gmdate( 'Y-m-d', $cursor );
			$r   = isset( $rows[ $key ] ) ? $rows[ $key ] : null;
			if ( 'month' === $group ) {
				$label = date_i18n( 'M Y', $cursor );
			} elseif ( 'week' === $group ) {
				$label = date_i18n( 'd M', $cursor );
			} else {
				$label = date_i18n( 'd M', $cursor );
			}
			$out[] = array(
				'key'       => $key,
				'label'     => $label,
				'orders'    => $r ? (int) $r->orders : 0,
				'delivered' => $r ? (int) $r->delivered : 0,
				'revenue'   => $r ? round( (float) $r->revenue, 2 ) : 0,
				'profit'    => $r ? round( (float) $r->profit, 2 ) : 0,
			);
			if ( 'month' === $group ) {
				$cursor = strtotime( '+1 month', $cursor );
			} elseif ( 'week' === $group ) {
				$cursor = strtotime( '+7 days', $cursor );
			} else {
				$cursor = strtotime( '+1 day', $cursor );
			}
		}
		return $out;
	}

	/**
	 * Money each shipping company still owes: delivered COD minus their fees, for orders not yet settled.
	 */
	public static function pending_payouts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT shipping_company_id,
				SUM(status = \'delivered\') AS delivered,
				SUM(status = \'returned\') AS returned,
				SUM(CASE WHEN status = \'delivered\' THEN total ELSE 0 END) AS cod,
				SUM(shipping_cost) AS shipping,
				SUM(CASE WHEN status = \'returned\' THEN return_cost ELSE 0 END) AS returns_cost
			FROM ' . CODM_DB::table( 'orders' ) . " WHERE payout_id = 0 AND status IN ('delivered','returned')
			GROUP BY shipping_company_id ORDER BY cod DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			$cod   = (float) $r->cod;
			$fees  = (float) $r->shipping + (float) $r->returns_cost;
			$out[] = array(
				'company_id' => (int) $r->shipping_company_id,
				'company'    => $r->shipping_company_id ? CODM_Shipping::name( $r->shipping_company_id ) : __( 'No shipping company', 'cod-manager-pro' ),
				'delivered'  => (int) $r->delivered,
				'returned'   => (int) $r->returned,
				'cod'        => round( $cod, 2 ),
				'fees'       => round( $fees, 2 ),
				'expected'   => round( $cod - $fees, 2 ),
			);
		}
		return $out;
	}

	/** Unsettled delivered/returned orders of one company (for the payout form). */
	public static function unsettled_orders( $company_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT order_id, order_number, customer_name, city, status, total, shipping_cost, return_cost, delivered_at, returned_at
			 FROM ' . CODM_DB::table( 'orders' ) . " WHERE payout_id = 0 AND status IN ('delivered','returned') AND shipping_company_id = %d
			 ORDER BY COALESCE(delivered_at, returned_at) ASC LIMIT 500",
			$company_id
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** What the courier should transfer for one order. */
	public static function order_net_payout( $o ) {
		if ( 'delivered' === $o->status ) {
			return round( (float) $o->total - (float) $o->shipping_cost, 2 );
		}
		return round( -( (float) $o->shipping_cost + (float) $o->return_cost ), 2 );
	}

	/** Expenses in a range, newest first. $limit 0 = all of them. */
	public static function expenses( $from, $to, $limit = 200 ) {
		global $wpdb;
		$limit = (int) $limit;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . CODM_DB::table( 'expenses' ) . ' WHERE expense_date BETWEEN %s AND %s ORDER BY expense_date DESC, id DESC' . ( $limit > 0 ? ' LIMIT ' . $limit : '' ),
			$from, $to
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function expenses_count( $from, $to ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . CODM_DB::table( 'expenses' ) . ' WHERE expense_date BETWEEN %s AND %s',
			$from, $to
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function payouts( $limit = 30 ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . CODM_DB::table( 'payouts' ) . ' ORDER BY payout_date DESC, id DESC LIMIT %d',
			$limit
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Profit per closed order in a range. */
	public static function order_profits( $from, $to, $limit = 25 ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . CODM_DB::table( 'orders' ) . ' WHERE ' . self::range_where( $from, $to ) . " AND status IN ('delivered','returned') ORDER BY created_at DESC" . ( (int) $limit > 0 ? ' LIMIT ' . (int) $limit : '' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $r ) {
			$p         = self::order_profit( $r );
			$r->profit = $p['value'];
		}
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Form handlers                                                       */
	/* ------------------------------------------------------------------ */

	private static function back( $args ) {
		$url = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=codm-finance' );
		$url = remove_query_arg( array( 'codm_msg', 'codm_err' ), $url );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( CODM_Roles::CAP_FINANCE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cod-manager-pro' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function handle_add_expense() {
		global $wpdb;
		self::guard( 'codm_add_expense' );
		$cats     = self::expense_categories();
		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : 'other';
		$amount   = CODM_DB::decimal( isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : 0 ); // phpcs:ignore WordPress.Security
		$date     = isset( $_POST['expense_date'] ) ? sanitize_text_field( wp_unslash( $_POST['expense_date'] ) ) : '';
		if ( $amount <= 0 || ! CODM_DB::valid_date( $date ) ) {
			self::back( array( 'codm_err' => 'expense' ) );
		}
		$wpdb->insert( CODM_DB::table( 'expenses' ), array(
			'category'     => isset( $cats[ $category ] ) ? $category : 'other',
			'amount'       => $amount,
			'expense_date' => $date,
			'description'  => substr( sanitize_text_field( wp_unslash( $_POST['description'] ?? '' ) ), 0, 255 ),
			'user_id'      => get_current_user_id(),
			'created_at'   => CODM_DB::now(),
		) );
		self::back( array( 'codm_msg' => 'expense_added' ) );
	}

	public static function handle_delete_expense() {
		global $wpdb;
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		self::guard( 'codm_delete_expense_' . $id );
		$wpdb->delete( CODM_DB::table( 'expenses' ), array( 'id' => $id ) );
		self::back( array( 'codm_msg' => 'expense_deleted' ) );
	}

	public static function handle_record_payout() {
		global $wpdb;
		self::guard( 'codm_record_payout' );
		$company_id = isset( $_POST['company_id'] ) ? absint( $_POST['company_id'] ) : 0;
		$ids        = isset( $_POST['order_ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['order_ids'] ) ) ) ) : array();
		$received   = CODM_DB::decimal( isset( $_POST['amount_received'] ) ? wp_unslash( $_POST['amount_received'] ) : 0 ); // phpcs:ignore WordPress.Security
		$date       = isset( $_POST['payout_date'] ) ? sanitize_text_field( wp_unslash( $_POST['payout_date'] ) ) : '';
		if ( ! $ids || ! CODM_DB::valid_date( $date ) ) {
			self::back( array( 'codm_err' => 'payout' ) );
		}

		// Only orders that are really unsettled and belong to this company.
		$args   = array_merge( array( $company_id ), $ids );
		$orders = $wpdb->get_results( $wpdb->prepare(
			'SELECT order_id, status, total, shipping_cost, return_cost FROM ' . CODM_DB::table( 'orders' ) . "
			 WHERE payout_id = 0 AND status IN ('delivered','returned') AND shipping_company_id = %d AND order_id IN (" . CODM_DB::placeholders( $ids ) . ')',
			$args
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $orders ) {
			self::back( array( 'codm_err' => 'payout' ) );
		}
		$expected = 0.0;
		foreach ( $orders as $o ) {
			$expected += self::order_net_payout( $o );
		}

		$wpdb->insert( CODM_DB::table( 'payouts' ), array(
			'shipping_company_id' => $company_id,
			'orders_count'        => count( $orders ),
			'amount_expected'     => round( $expected, 2 ),
			'amount_received'     => $received,
			'reference'           => substr( sanitize_text_field( wp_unslash( $_POST['reference'] ?? '' ) ), 0, 100 ),
			'payout_date'         => $date,
			'notes'               => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'user_id'             => get_current_user_id(),
			'created_at'          => CODM_DB::now(),
		) );
		$payout_id = (int) $wpdb->insert_id;
		if ( ! $payout_id ) {
			self::back( array( 'codm_err' => 'payout' ) );
		}
		$valid = array_map( 'intval', wp_list_pluck( $orders, 'order_id' ) );
		// Claim only orders that are still unsettled. If another payout took some of them meanwhile
		// (double click, two managers at once), undo this one instead of counting orders twice.
		$claimed = $wpdb->query( $wpdb->prepare(
			'UPDATE ' . CODM_DB::table( 'orders' ) . ' SET payout_id = %d, updated_at = %s WHERE payout_id = 0 AND order_id IN (' . CODM_DB::placeholders( $valid ) . ')',
			array_merge( array( $payout_id, CODM_DB::now() ), $valid )
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( (int) $claimed !== count( $valid ) ) {
			$wpdb->update( CODM_DB::table( 'orders' ), array( 'payout_id' => 0 ), array( 'payout_id' => $payout_id ), array( '%d' ), array( '%d' ) );
			$wpdb->delete( CODM_DB::table( 'payouts' ), array( 'id' => $payout_id ), array( '%d' ) );
			self::back( array( 'codm_err' => 'payout' ) );
		}
		foreach ( $valid as $oid ) {
			/* translators: %d payout id */
			CODM_Orders::history( $oid, 'payout', sprintf( __( 'Included in courier payout #%d', 'cod-manager-pro' ), $payout_id ) );
		}
		self::back( array( 'codm_msg' => 'payout_recorded' ) );
	}
}
