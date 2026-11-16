<?php
/**
 * Finance page export: Excel workbook (.xlsx) and a print-ready report (Save as PDF).
 */

defined( 'ABSPATH' ) || exit;

class CODM_Finance_Export {

	const NONCE = 'codm_fin_export';

	public static function init() {
		add_action( 'admin_post_codm_fin_xlsx', array( __CLASS__, 'xlsx' ) );
		add_action( 'admin_post_codm_fin_print', array( __CLASS__, 'report' ) );
	}

	public static function url( $type, $from, $to ) {
		return add_query_arg( array(
			'action'   => 'codm_fin_' . $type,
			'from'     => $from,
			'to'       => $to,
			'_wpnonce' => wp_create_nonce( self::NONCE ),
		), admin_url( 'admin-post.php' ) );
	}

	private static function guard() {
		if ( ! current_user_can( CODM_Roles::CAP_FINANCE ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cod-manager-pro' ), 403 );
		}
		check_admin_referer( self::NONCE );
	}

	private static function range() {
		// phpcs:disable WordPress.Security.NonceVerification
		return CODM_DB::date_range(
			isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '',
			isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ''
		);
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ */
	/* Data: one structure used by both the Excel file and the PDF report  */
	/* ------------------------------------------------------------------ */

	/**
	 * One cell: value, type and an optional stable key. The key lets the code recognise
	 * a row or a value without comparing its (translated) text.
	 */
	private static function c( $v, $t = 's', $k = '' ) {
		return array( 'v' => $v, 't' => $t, 'k' => $k );
	}

	/** Translated "Total" label (used in every table). */
	private static function total_label() {
		return __( 'Total', 'cod-manager-pro' );
	}

	public static function sheets( $from, $to ) {
		global $wpdb;
		$s      = CODM_Finance::summary( $from, $to );
		$cats   = CODM_Finance::expense_categories();
		$sheets = array();

		// 1. Summary. Each line: label, value, type, stable key.
		$lines = array(
			array( __( 'Profit and loss', 'cod-manager-pro' ), null, 'h', 'pl' ),
			array( __( 'Sales value (all non-cancelled orders)', 'cod-manager-pro' ), $s['order_value'], 'money', 'order_value' ),
			array( __( 'Revenue (delivered orders)', 'cod-manager-pro' ), $s['revenue'], 'money_b', 'revenue' ),
			array( __( 'Product costs', 'cod-manager-pro' ), -$s['product_costs'], 'money', 'product_costs' ),
			array( __( 'Gross profit', 'cod-manager-pro' ), $s['gross_profit'], 'money_b', 'gross_profit' ),
			array( __( 'Shipping costs', 'cod-manager-pro' ), -$s['shipping_costs'], 'money', 'shipping_costs' ),
			array( __( 'Return costs', 'cod-manager-pro' ), -$s['return_costs'], 'money', 'return_costs' ),
			array( __( 'Advertising', 'cod-manager-pro' ), -$s['ads'], 'money', 'ads' ),
			array( __( 'Other expenses', 'cod-manager-pro' ), -$s['other_expenses'], 'money', 'other_expenses' ),
			array( __( 'Net profit', 'cod-manager-pro' ), $s['net_profit'], 'money_b', 'net_profit' ),
			array( __( 'Net margin (on delivered revenue)', 'cod-manager-pro' ), $s['margin'], 'pct', 'margin' ),
			array( __( 'Cash on delivery', 'cod-manager-pro' ), null, 'h', 'cod' ),
			array( __( 'COD collected by couriers', 'cod-manager-pro' ), $s['cod_collected'], 'money', 'cod_collected' ),
			array( __( 'Received from couriers', 'cod-manager-pro' ), $s['cod_received'], 'money', 'cod_received' ),
			array( __( 'Still owed by couriers', 'cod-manager-pro' ), $s['pending_payouts'], 'money_b', 'pending_payouts' ),
			array( __( 'Orders', 'cod-manager-pro' ), null, 'h', 'orders_head' ),
			array( __( 'Orders placed', 'cod-manager-pro' ), $s['orders'], 'num', 'orders' ),
			array( __( 'Confirmed', 'cod-manager-pro' ), $s['confirmed'], 'num', 'confirmed' ),
			array( __( 'Shipped', 'cod-manager-pro' ), $s['shipped'], 'num', 'shipped' ),
			array( __( 'Delivered', 'cod-manager-pro' ), $s['delivered'], 'num', 'delivered' ),
			array( __( 'Returned', 'cod-manager-pro' ), $s['returned'], 'num', 'returned' ),
			array( __( 'Cancelled', 'cod-manager-pro' ), $s['cancelled'], 'num', 'cancelled' ),
			array( __( 'Confirmation rate', 'cod-manager-pro' ), $s['confirmation_rate'], 'pct', 'confirmation_rate' ),
			array( __( 'Delivery rate', 'cod-manager-pro' ), $s['delivery_rate'], 'pct', 'delivery_rate' ),
			array( __( 'Return rate', 'cod-manager-pro' ), $s['return_rate'], 'pct', 'return_rate' ),
			array( __( 'Average order value (delivered)', 'cod-manager-pro' ), $s['aov'], 'money', 'aov' ),
			array( __( 'Cost per delivered order (ads)', 'cod-manager-pro' ), $s['cpa'], 'money', 'cpa' ),
		);
		$rows = array();
		foreach ( $lines as $l ) {
			if ( 'h' === $l[2] ) {
				$rows[] = array( self::c( $l[0], 'h', $l[3] ), self::c( '', 'h' ) );
				continue;
			}
			$bold   = '_b' === substr( $l[2], -2 );
			$rows[] = array( self::c( $l[0], $bold ? 'b' : 's', $l[3] ), self::c( $l[1], $l[2] ) );
		}
		$sheets[] = array(
			'name'   => __( 'Summary', 'cod-manager-pro' ),
			'title'  => __( 'Summary', 'cod-manager-pro' ),
			'head'   => array( __( 'Item', 'cod-manager-pro' ), __( 'Value', 'cod-manager-pro' ) ),
			'widths' => array( 40, 20 ),
			'rows'   => $rows,
			'total'  => null,
			'kind'   => 'summary',
			'stats'  => $s,
		);

		// 2. Profit per order (delivered and returned orders of the period).
		$rows = array();
		$tot  = array( 0.0, 0.0, 0.0, 0.0, 0.0 );
		foreach ( (array) CODM_Finance::order_profits( $from, $to, 0 ) as $o ) {
			$returned = 'returned' === $o->status;
			$vals     = array(
				$returned ? 0.0 : (float) $o->total,
				$returned ? 0.0 : -(float) $o->product_cost,
				-(float) $o->shipping_cost,
				$returned ? -(float) $o->return_cost : 0.0,
				(float) $o->profit,
			);
			foreach ( $vals as $i => $v ) {
				$tot[ $i ] += $v;
			}
			$rows[] = array(
				self::c( '#' . $o->order_number ),
				self::c( substr( (string) $o->created_at, 0, 10 ) ),
				self::c( $o->customer_name ),
				self::c( $o->city ),
				self::c( $returned ? __( 'Returned', 'cod-manager-pro' ) : __( 'Delivered', 'cod-manager-pro' ), 's', $returned ? 'returned' : 'delivered' ),
				self::c( $o->shipping_company_id ? CODM_Shipping::name( $o->shipping_company_id ) : '' ),
				self::c( '' !== (string) $o->source ? $o->source : __( 'Unknown', 'cod-manager-pro' ) ),
				self::c( $vals[0], 'money' ),
				self::c( $vals[1], 'money' ),
				self::c( $vals[2], 'money' ),
				self::c( $vals[3], 'money' ),
				self::c( $vals[4], 'money_b' ),
			);
		}
		$n_rows   = count( $rows );
		$sheets[] = array(
			'name'   => __( 'Profit per order', 'cod-manager-pro' ),
			'title'  => __( 'Profit per order', 'cod-manager-pro' ),
			'head'   => array(
				__( 'Order', 'cod-manager-pro' ),
				__( 'Date', 'cod-manager-pro' ),
				__( 'Customer', 'cod-manager-pro' ),
				__( 'City', 'cod-manager-pro' ),
				__( 'Result', 'cod-manager-pro' ),
				__( 'Shipping company', 'cod-manager-pro' ),
				__( 'Source', 'cod-manager-pro' ),
				__( 'COD amount', 'cod-manager-pro' ),
				__( 'Product cost', 'cod-manager-pro' ),
				__( 'Shipping fee', 'cod-manager-pro' ),
				__( 'Return fee', 'cod-manager-pro' ),
				__( 'Profit', 'cod-manager-pro' ),
			),
			'signed' => array( 11 ), // Profit column: shown with a + / - sign.
			'widths' => array( 10, 12, 22, 14, 11, 16, 14, 14, 14, 14, 13, 14 ),
			'rows'   => $rows,
			/* translators: %s: number of orders */
			'total'  => $rows ? array( self::c( self::total_label(), 'b' ), self::c( sprintf( _n( '%s order', '%s orders', $n_rows, 'cod-manager-pro' ), number_format_i18n( $n_rows ) ), 'b' ), self::c( '' ), self::c( '' ), self::c( '' ), self::c( '' ), self::c( '' ), self::c( $tot[0], 'money_b' ), self::c( $tot[1], 'money_b' ), self::c( $tot[2], 'money_b' ), self::c( $tot[3], 'money_b' ), self::c( $tot[4], 'money_b' ) ) : null,
			'empty'  => __( 'No delivered or returned orders in this period.', 'cod-manager-pro' ),
		);

		// 3. Expenses.
		$rows = array();
		$sum  = 0.0;
		foreach ( (array) CODM_Finance::expenses( $from, $to, 0 ) as $e ) {
			$sum   += (float) $e->amount;
			$rows[] = array(
				self::c( $e->expense_date ),
				self::c( isset( $cats[ $e->category ] ) ? $cats[ $e->category ] : $e->category ),
				self::c( $e->description ),
				self::c( (float) $e->amount, 'money' ),
			);
		}
		$sheets[] = array(
			'name'   => __( 'Expenses', 'cod-manager-pro' ),
			'title'  => __( 'Expenses', 'cod-manager-pro' ),
			'head'   => array( __( 'Date', 'cod-manager-pro' ), __( 'Category', 'cod-manager-pro' ), __( 'Description', 'cod-manager-pro' ), __( 'Amount', 'cod-manager-pro' ) ),
			'widths' => array( 12, 22, 44, 14 ),
			'rows'   => $rows,
			'total'  => $rows ? array( self::c( self::total_label(), 'b' ), self::c( '' ), self::c( '' ), self::c( $sum, 'money_b' ) ) : null,
			'empty'  => __( 'No expenses in this period.', 'cod-manager-pro' ),
		);

		// 4. Courier payouts received in the period.
		$rows = array();
		$t    = array( 0, 0.0, 0.0 );
		$list = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . CODM_DB::table( 'payouts' ) . ' WHERE payout_date BETWEEN %s AND %s ORDER BY payout_date DESC, id DESC',
			$from,
			$to
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( (array) $list as $p ) {
			$exp    = (float) $p->amount_expected;
			$rec    = (float) $p->amount_received;
			$t[0]  += (int) $p->orders_count;
			$t[1]  += $exp;
			$t[2]  += $rec;
			$rows[] = array(
				self::c( $p->payout_date ),
				self::c( $p->shipping_company_id ? CODM_Shipping::name( $p->shipping_company_id ) : '' ),
				self::c( (int) $p->orders_count, 'num' ),
				self::c( $exp, 'money' ),
				self::c( $rec, 'money' ),
				self::c( $rec - $exp, 'money_b' ),
				self::c( $p->reference ),
				self::c( $p->notes ),
			);
		}
		$sheets[] = array(
			'name'   => __( 'Courier payouts', 'cod-manager-pro' ),
			'title'  => __( 'Courier payouts received', 'cod-manager-pro' ),
			'head'   => array(
				__( 'Date', 'cod-manager-pro' ),
				__( 'Shipping company', 'cod-manager-pro' ),
				__( 'Orders', 'cod-manager-pro' ),
				__( 'Expected', 'cod-manager-pro' ),
				__( 'Received', 'cod-manager-pro' ),
				__( 'Difference', 'cod-manager-pro' ),
				__( 'Reference', 'cod-manager-pro' ),
				__( 'Notes', 'cod-manager-pro' ),
			),
			'signed' => array( 5 ), // Difference column: shown with a + / - sign.
			'widths' => array( 12, 18, 9, 14, 14, 14, 16, 30 ),
			'rows'   => $rows,
			'total'  => $rows ? array( self::c( self::total_label(), 'b' ), self::c( '' ), self::c( $t[0], 'num' ), self::c( $t[1], 'money_b' ), self::c( $t[2], 'money_b' ), self::c( $t[2] - $t[1], 'money_b' ), self::c( '' ), self::c( '' ) ) : null,
			'empty'  => __( 'No payouts recorded in this period.', 'cod-manager-pro' ),
		);

		// 5. What couriers still owe (all unsettled orders, as of today).
		$rows = array();
		$t    = array( 0, 0, 0.0, 0.0, 0.0 );
		foreach ( CODM_Finance::pending_payouts() as $p ) {
			$t[0]  += $p['delivered'];
			$t[1]  += $p['returned'];
			$t[2]  += $p['cod'];
			$t[3]  += $p['fees'];
			$t[4]  += $p['expected'];
			$rows[] = array(
				self::c( $p['company'] ),
				self::c( $p['delivered'], 'num' ),
				self::c( $p['returned'], 'num' ),
				self::c( $p['cod'], 'money' ),
				self::c( -$p['fees'], 'money' ),
				self::c( $p['expected'], 'money_b' ),
			);
		}
		$sheets[] = array(
			'name'   => __( 'Still owed by couriers', 'cod-manager-pro' ),
			/* translators: %s: today's date */
			'title'  => sprintf( __( 'Still owed by couriers (as of %s)', 'cod-manager-pro' ), date_i18n( 'j M Y', strtotime( CODM_DB::today() ) ) ),
			'head'   => array(
				__( 'Shipping company', 'cod-manager-pro' ),
				__( 'Delivered', 'cod-manager-pro' ),
				__( 'Returned', 'cod-manager-pro' ),
				__( 'COD collected', 'cod-manager-pro' ),
				__( 'Fees', 'cod-manager-pro' ),
				__( 'Expected payout', 'cod-manager-pro' ),
			),
			'widths' => array( 22, 11, 11, 15, 14, 16 ),
			'rows'   => $rows,
			'total'  => $rows ? array( self::c( self::total_label(), 'b' ), self::c( $t[0], 'num' ), self::c( $t[1], 'num' ), self::c( $t[2], 'money_b' ), self::c( -$t[3], 'money_b' ), self::c( $t[4], 'money_b' ) ) : null,
			'empty'  => __( 'Every delivered and returned order is settled.', 'cod-manager-pro' ),
		);

		return $sheets;
	}

	/* ------------------------------------------------------------------ */
	/* Excel                                                               */
	/* ------------------------------------------------------------------ */

	public static function xlsx() {
		self::guard();
		list( $from, $to ) = self::range();
		$file = self::build_xlsx( $from, $to );
		if ( ! $file ) {
			wp_die( esc_html__( 'The Excel file could not be created on this server (the PHP Zip extension is missing).', 'cod-manager-pro' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		$slug = sanitize_title( get_bloginfo( 'name' ) );
		header( 'Content-Disposition: attachment; filename=' . ( $slug ? $slug . '-' : '' ) . 'finance-' . $from . '-to-' . $to . '.xlsx' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $file );
		exit;
	}

	private static function x( $v ) {
		$v = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $v );
		return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private static function col( $i ) {
		$s = '';
		$i++;
		while ( $i > 0 ) {
			$m = ( $i - 1 ) % 26;
			$s = chr( 65 + $m ) . $s;
			$i = intdiv( $i - 1, 26 );
		}
		return $s;
	}

	private static function cur() {
		$s = get_option( 'codm_settings' );
		$c = ( is_array( $s ) && ! empty( $s['currency_label'] ) ) ? (string) $s['currency_label'] : 'DH';
		return str_replace( array( '"', '\\' ), '', $c );
	}

	// Cell builders for the Excel writer.
	private static function xs( $v, $st ) {
		return array( 'k' => 's', 'v' => (string) $v, 's' => $st );
	}

	private static function xn( $v, $st ) {
		return array( 'k' => 'n', 'v' => $v, 's' => $st );
	}

	private static function xf( $f, $v, $st ) {
		return array( 'k' => 'f', 'f' => $f, 'v' => $v, 's' => $st );
	}

	private static function numv( $n ) {
		$n = round( (float) $n, 6 );
		return abs( $n ) < 0.0000001 ? '0' : rtrim( rtrim( sprintf( '%.6F', $n ), '0' ), '.' );
	}

	private static function cell_xml( $ref, $c ) {
		if ( 'n' === $c['k'] ) {
			return '<c r="' . $ref . '" s="' . $c['s'] . '"><v>' . self::numv( $c['v'] ) . '</v></c>';
		}
		if ( 'f' === $c['k'] ) {
			return '<c r="' . $ref . '" s="' . $c['s'] . '"><f>' . self::x( $c['f'] ) . '</f><v>' . self::numv( $c['v'] ) . '</v></c>';
		}
		if ( '' === $c['v'] ) {
			return '<c r="' . $ref . '" s="' . $c['s'] . '"/>';
		}
		return '<c r="' . $ref . '" t="inlineStr" s="' . $c['s'] . '"><is><t xml:space="preserve">' . self::x( $c['v'] ) . '</t></is></c>';
	}

	/** Turn one data sheet into rows of styled cells. Returns [rows, meta]. */
	private static function table_rows( $sh, $head_rows ) {
		$numeric = array( 'money', 'money_b', 'num', 'pct' );
		$signed  = array();
		$sig_col = isset( $sh['signed'] ) ? (array) $sh['signed'] : array();
		foreach ( $sh['head'] as $i => $h ) {
			$signed[ $i ] = in_array( $i, $sig_col, true );
		}
		$probe = $sh['rows'] ? $sh['rows'][0] : ( $sh['total'] ? $sh['total'] : array() );
		$rows  = $head_rows;
		$head  = array();
		foreach ( $sh['head'] as $i => $h ) {
			$is_num = isset( $probe[ $i ] ) && in_array( $probe[ $i ]['t'], $numeric, true );
			$head[] = self::xs( $h, $is_num ? 25 : 1 );
		}
		$rows[]    = $head;
		$head_row  = count( $rows );
		$first     = $head_row + 1;
		foreach ( $sh['rows'] as $r ) {
			$line = array();
			foreach ( array_values( $r ) as $i => $cell ) {
				$t = $cell['t'];
				$v = $cell['v'];
				if ( in_array( $t, $numeric, true ) ) {
					if ( null === $v || '' === $v ) {
						$line[] = self::xs( '—', 9 );
						continue;
					}
					if ( 'pct' === $t ) {
						$line[] = self::xn( (float) $v / 100, 5 );
					} elseif ( 'num' === $t ) {
						$line[] = self::xn( $v, 8 );
					} else {
						$line[] = self::xn( $v, ! empty( $signed[ $i ] ) ? 12 : ( 'money_b' === $t ? 3 : 2 ) );
					}
				} else {
					$line[] = self::xs( $v, 'b' === $t ? 6 : 24 );
				}
			}
			$rows[] = $line;
		}
		$last = count( $rows );
		if ( ! $sh['rows'] && ! empty( $sh['empty'] ) ) {
			$rows[] = array( self::xs( $sh['empty'], 7 ) );
		}
		if ( $sh['rows'] && ! empty( $sh['total'] ) ) {
			$line = array();
			foreach ( array_values( $sh['total'] ) as $i => $cell ) {
				$t = $cell['t'];
				if ( in_array( $t, $numeric, true ) ) {
					$colL   = self::col( $i );
					$st     = 'num' === $t ? 16 : ( ! empty( $signed[ $i ] ) ? 15 : 14 );
					$line[] = self::xf( 'SUM(' . $colL . $first . ':' . $colL . $last . ')', (float) $cell['v'], $st );
				} else {
					$line[] = self::xs( $cell['v'], '' === (string) $cell['v'] ? 22 : 13 );
				}
			}
			$rows[] = $line;
		}
		return array( $rows, array( 'head_row' => $head_row, 'first' => $first, 'last' => $last, 'cols' => count( $sh['head'] ), 'has_rows' => (bool) $sh['rows'] ) );
	}

	private static function summary_rows( $sh, $head_rows ) {
		$s     = $sh['stats'];
		$rows  = $head_rows;
		$fmt   = function ( $v ) {
			return CODM_DB::money( $v );
		};
		$rows[] = array( self::xs( __( 'Net profit', 'cod-manager-pro' ), 20 ), self::xs( __( 'Revenue (delivered)', 'cod-manager-pro' ), 17 ), self::xs( __( 'Owed by couriers', 'cod-manager-pro' ), 17 ), self::xs( __( 'Delivery rate', 'cod-manager-pro' ), 17 ) );
		$rows[] = array(
			self::xn( $s['net_profit'], 21 ),
			self::xn( $s['revenue'], 18 ),
			self::xn( $s['pending_payouts'], 18 ),
			null === $s['delivery_rate'] ? self::xs( '—', 30 ) : self::xn( (float) $s['delivery_rate'] / 100, 19 ),
		);
		$n_del  = (int) $s['delivered'];
		$n_ord  = (int) $s['orders'];
		$rows[] = array(
			/* translators: %s: net margin, e.g. "12.5 %" */
			self::xs( null === $s['margin'] ? __( 'No delivered orders yet', 'cod-manager-pro' ) : sprintf( __( '%s net margin', 'cod-manager-pro' ), number_format_i18n( $s['margin'], 1 ) . ' %' ), 27 ),
			/* translators: %s: number of delivered orders */
			self::xs( sprintf( _n( '%s delivered order', '%s delivered orders', $n_del, 'cod-manager-pro' ), number_format_i18n( $n_del ) ), 26 ),
			/* translators: %s: amount received from couriers */
			self::xs( sprintf( __( 'Received %s', 'cod-manager-pro' ), $fmt( $s['cod_received'] ) ), 26 ),
			/* translators: %s: number of orders placed */
			self::xs( sprintf( _n( '%s order placed', '%s orders placed', $n_ord, 'cod-manager-pro' ), number_format_i18n( $n_ord ) ), 26 ),
		);
		$kpi_row = count( $rows ) - 1;
		$rows[]  = array();
		$rows[]  = array( self::xs( __( 'Item', 'cod-manager-pro' ), 1 ), self::xs( __( 'Value', 'cod-manager-pro' ), 25 ) );
		foreach ( $sh['rows'] as $r ) {
			$label = $r[0]['v'];
			$key   = isset( $r[0]['k'] ) ? $r[0]['k'] : '';
			$t     = $r[1]['t'];
			$v     = $r[1]['v'];
			if ( 'h' === $r[0]['t'] ) {
				$rows[] = array( self::xs( $label, 10 ), self::xs( '', 10 ) );
				continue;
			}
			if ( 'net_profit' === $key ) {
				$rows[] = array( self::xs( $label, 28 ), self::xn( $v, 23 ) );
				continue;
			}
			$lst = 'b' === $r[0]['t'] ? 6 : 24;
			if ( null === $v || '' === $v ) {
				$cell = self::xs( '—', 9 );
			} elseif ( 'pct' === $t ) {
				$cell = self::xn( (float) $v / 100, 5 );
			} elseif ( 'num' === $t ) {
				$cell = self::xn( $v, 8 );
			} elseif ( 'gross_profit' === $key ) {
				$cell = self::xn( $v, 12 );
			} else {
				$cell = self::xn( $v, 'money_b' === $t ? 3 : 2 );
			}
			$rows[] = array( self::xs( $label, $lst ), $cell );
		}
		return array( $rows, array( 'kpi_row' => $kpi_row ) );
	}

	private static function sheet_xml( $sh, $ctx ) {
		$summary = 'summary' === ( $sh['kind'] ?? '' );
		$head    = array(
			array( self::xs( $ctx['store'], 4 ) ),
			array( self::xs( $summary ? __( 'Finance summary', 'cod-manager-pro' ) : $sh['title'], 11 ) ),
			array( self::xs( $ctx['sub'], 7 ) ),
			array(),
		);
		if ( $summary ) {
			list( $rows, $meta ) = self::summary_rows( $sh, $head );
			$widths              = array( 38, 20, 20, 20 );
		} else {
			list( $rows, $meta ) = self::table_rows( $sh, $head );
			$widths              = $sh['widths'];
		}

		$x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$x .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		$x .= '<sheetPr><tabColor rgb="' . ( $summary ? 'FF17142A' : 'FF3B2FC0' ) . '"/><pageSetUpPr fitToPage="1"/></sheetPr>';
		$x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0"' . ( $summary ? ' tabSelected="1"' : '' ) . '>';
		if ( ! $summary && $meta['has_rows'] ) {
			$x .= '<pane ySplit="' . $meta['head_row'] . '" topLeftCell="A' . ( $meta['head_row'] + 1 ) . '" activePane="bottomLeft" state="frozen"/>';
		}
		$x .= '</sheetView></sheetViews>';
		$x .= '<sheetFormatPr defaultRowHeight="18" customHeight="1"/>';
		$x .= '<cols>';
		foreach ( $widths as $i => $w ) {
			$x .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $w . '" customWidth="1"/>';
		}
		$x .= '</cols><sheetData>';
		foreach ( $rows as $ri => $r ) {
			$n  = $ri + 1;
			$ht = '';
			if ( 1 === $n ) {
				$ht = ' ht="30" customHeight="1"';
			} elseif ( $summary && $n === $meta['kpi_row'] ) {
				$ht = ' ht="30" customHeight="1"';
			} elseif ( ! $summary && $n === $meta['head_row'] ) {
				$ht = ' ht="24" customHeight="1"';
			}
			$x .= '<row r="' . $n . '"' . $ht . '>';
			foreach ( array_values( $r ) as $ci => $cell ) {
				$x .= self::cell_xml( self::col( $ci ) . $n, $cell );
			}
			$x .= '</row>';
		}
		$x .= '</sheetData>';
		if ( ! $summary && $meta['has_rows'] ) {
			$x .= '<autoFilter ref="A' . $meta['head_row'] . ':' . self::col( $meta['cols'] - 1 ) . $meta['last'] . '"/>';
		}
		$x .= '<printOptions horizontalCentered="1"/>';
		$x .= '<pageMargins left="0.45" right="0.45" top="0.55" bottom="0.6" header="0.3" footer="0.3"/>';
		$x .= '<pageSetup paperSize="9" orientation="' . ( $summary ? 'portrait' : 'landscape' ) . '" fitToWidth="1" fitToHeight="0"/>';
		// In Excel header/footer codes "&" starts a command, so literal "&" in text is doubled.
		/* translators: 1: current page number 2: total number of pages */
		$page = sprintf( str_replace( '&', '&&', __( 'Page %1$s of %2$s', 'cod-manager-pro' ) ), '&P', '&N' );
		$foot = '&L&8' . str_replace( '&', '&&', $ctx['store'] . ' · ' . __( 'Finance report', 'cod-manager-pro' ) . ' · ' . $ctx['period'] ) . '&R&8' . $page;
		$x   .= '<headerFooter><oddFooter>' . self::x( $foot ) . '</oddFooter></headerFooter>';
		$x   .= '</worksheet>';
		return array( $x, $meta );
	}

	private static function styles_xml() {
		$cur  = self::cur();
		$fmts = array(
			164 => '#,##0.00 "' . $cur . '";[Red]-#,##0.00 "' . $cur . '";"—"',
			165 => '0.0%',
			166 => '+#,##0.00 "' . $cur . '";[Red]-#,##0.00 "' . $cur . '";0.00 "' . $cur . '"',
			167 => '#,##0.00 "' . $cur . '";[Red]-#,##0.00 "' . $cur . '";0.00 "' . $cur . '"',
		);
		$nf = '';
		foreach ( $fmts as $id => $code ) {
			$nf .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::x( $code ) . '"/>';
		}
		$font = function ( $sz, $rgb, $b = false, $i = false ) {
			return '<font>' . ( $b ? '<b/>' : '' ) . ( $i ? '<i/>' : '' ) . '<sz val="' . $sz . '"/><color rgb="' . $rgb . '"/><name val="Calibri"/><family val="2"/></font>';
		};
		$fonts = array(
			$font( 11, 'FF17142A' ),              // 0 body
			$font( 11, 'FF17142A', true ),        // 1 bold
			$font( 18, 'FF17142A', true ),        // 2 store name
			$font( 9, 'FF8A879C', false, true ),  // 3 muted italic
			$font( 11, 'FF3B2FC0', true ),        // 4 brand bold
			$font( 10, 'FFFFFFFF', true ),        // 5 table head
			$font( 9, 'FF6B6880', true ),         // 6 kpi label
			$font( 15, 'FF17142A', true ),        // 7 kpi value
			$font( 15, 'FFFFFFFF', true ),        // 8 kpi main value
			$font( 9, 'FFC9C6DA' ),               // 9 kpi main sub
			$font( 12, 'FF17142A', true ),        // 10 grand total
			$font( 9, 'FF8A879C' ),               // 11 kpi sub
			$font( 9, 'FFC9C6DA', true ),         // 12 kpi main label
			$font( 11, 'FF0F7A3D', true ),        // 13 profit green
			$font( 12, 'FF0F7A3D', true ),        // 14 grand total green
		);
		$fill  = function ( $rgb ) {
			return '<fill><patternFill patternType="solid"><fgColor rgb="' . $rgb . '"/><bgColor indexed="64"/></patternFill></fill>';
		};
		$fills = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' . $fill( 'FF17142A' ) . $fill( 'FFF7F6FB' ) . $fill( 'FFEEECF8' );
		$line  = '<color rgb="FFE6E4EE"/>';
		$ink   = '<color rgb="FF17142A"/>';
		$borders = '<border><left/><right/><top/><bottom/><diagonal/></border>'
			. '<border><left/><right/><top/><bottom style="thin">' . $line . '</bottom><diagonal/></border>'
			. '<border><left/><right/><top style="medium">' . $ink . '</top><bottom/><diagonal/></border>'
			. '<border><left/><right/><top style="thin">' . $ink . '</top><bottom style="double">' . $ink . '</bottom><diagonal/></border>'
			. '<border><left style="thin">' . $line . '</left><right style="thin">' . $line . '</right><top style="thin">' . $line . '</top><bottom style="thin">' . $line . '</bottom><diagonal/></border>';
		// numFmt, font, fill, border, horizontal align, vertical align.
		$xfs = array(
			array( 0, 0, 0, 0, '', 'center' ),       // 0 plain
			array( 0, 5, 2, 0, 'left', 'center' ),   // 1 head
			array( 164, 0, 0, 1, '', 'center' ),     // 2 money
			array( 167, 1, 0, 1, '', 'center' ),     // 3 money bold
			array( 0, 2, 0, 0, '', 'center' ),       // 4 store name
			array( 165, 0, 0, 1, '', 'center' ),     // 5 percent
			array( 0, 1, 0, 1, '', 'center' ),       // 6 bold text
			array( 0, 3, 0, 0, '', 'center' ),       // 7 muted
			array( 3, 0, 0, 1, '', 'center' ),       // 8 integer
			array( 0, 0, 0, 1, 'right', 'center' ),  // 9 dash
			array( 0, 4, 4, 1, '', 'center' ),       // 10 section
			array( 0, 4, 0, 0, '', 'center' ),       // 11 sheet title
			array( 166, 13, 0, 1, '', 'center' ),    // 12 signed profit
			array( 0, 1, 0, 2, '', 'center' ),       // 13 total label
			array( 167, 1, 0, 2, '', 'center' ),     // 14 total money
			array( 166, 13, 0, 2, '', 'center' ),    // 15 total signed
			array( 3, 1, 0, 2, '', 'center' ),       // 16 total integer
			array( 0, 6, 3, 4, 'left', 'bottom' ),   // 17 kpi label
			array( 167, 7, 3, 4, 'left', 'center' ), // 18 kpi money
			array( 165, 7, 3, 4, 'left', 'center' ), // 19 kpi percent
			array( 0, 12, 2, 0, 'left', 'bottom' ),  // 20 kpi main label
			array( 167, 8, 2, 0, 'left', 'center' ), // 21 kpi main value
			array( 0, 0, 0, 2, '', 'center' ),       // 22 total blank
			array( 166, 14, 0, 3, '', 'center' ),    // 23 grand total value
			array( 0, 0, 0, 1, '', 'center' ),       // 24 text
			array( 0, 5, 2, 0, 'right', 'center' ),  // 25 head numeric
			array( 0, 11, 3, 4, 'left', 'top' ),     // 26 kpi sub
			array( 0, 9, 2, 0, 'left', 'top' ),      // 27 kpi main sub
			array( 0, 10, 0, 3, '', 'center' ),      // 28 grand total label
			array( 0, 0, 0, 0, '', 'center' ),       // 29 spare
			array( 0, 7, 3, 4, 'left', 'center' ),   // 30 kpi text value
		);
		$cx = '';
		foreach ( $xfs as $xf ) {
			$cx .= '<xf numFmtId="' . $xf[0] . '" fontId="' . $xf[1] . '" fillId="' . $xf[2] . '" borderId="' . $xf[3] . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment' . ( $xf[4] ? ' horizontal="' . $xf[4] . '"' : '' ) . ' vertical="' . $xf[5] . '"/></xf>';
		}
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="' . count( $fmts ) . '">' . $nf . '</numFmts>'
			. '<fonts count="' . count( $fonts ) . '">' . implode( '', $fonts ) . '</fonts>'
			. '<fills count="5">' . $fills . '</fills>'
			. '<borders count="5">' . $borders . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="' . count( $xfs ) . '">' . $cx . '</cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
			. '</styleSheet>';
	}

	public static function build_xlsx( $from, $to ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return false;
		}
		$sheets = self::sheets( $from, $to );
		$period = date_i18n( 'j M Y', strtotime( $from ) ) . ' – ' . date_i18n( 'j M Y', strtotime( $to ) );
		$ctx    = array(
			'store'  => get_bloginfo( 'name' ),
			'period' => $period,
			/* translators: 1: date range 2: currency 3: export date and time */
			'sub'    => sprintf( __( 'Period %1$s  ·  Amounts in %2$s  ·  Exported %3$s', 'cod-manager-pro' ), $period, self::cur(), date_i18n( 'j M Y, H:i' ) ),
		);
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = wp_tempnam( 'codm-finance.xlsx' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}
		$ct    = '';
		$wb    = '';
		$rels  = '';
		$names = '';
		$used  = array();
		foreach ( $sheets as $i => $sh ) {
			$n = $i + 1;
			// Excel sheet names: at most 31 characters, none of \ / ? * [ ] :, no leading or trailing
			// apostrophe, unique. Translated names can be in any script, so cut by character, not byte.
			$name = trim( str_replace( array( '\\', '/', '?', '*', '[', ']', ':' ), '', (string) $sh['name'] ), " '" );
			$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31, 'UTF-8' ) : substr( $name, 0, 31 );
			$name = trim( $name, " '" );
			if ( '' === $name || isset( $used[ strtolower( $name ) ] ) ) {
				$name = 'Sheet' . $n;
			}
			$used[ strtolower( $name ) ] = true;
			list( $xml, $meta ) = self::sheet_xml( $sh, $ctx );
			$ct   .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$wb   .= '<sheet name="' . self::x( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$q     = "'" . str_replace( "'", "''", $name ) . "'";
			if ( ! empty( $meta['has_rows'] ) ) {
				$names .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">' . self::x( $q . '!$A$' . $meta['head_row'] . ':$' . self::col( $meta['cols'] - 1 ) . '$' . $meta['last'] ) . '</definedName>';
				$names .= '<definedName name="_xlnm.Print_Titles" localSheetId="' . $i . '">' . self::x( $q . '!$' . $meta['head_row'] . ':$' . $meta['head_row'] ) . '</definedName>';
			}
			$zip->addFromString( 'xl/worksheets/sheet' . $n . '.xml', $xml );
		}
		$count = count( $sheets );
		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $ct . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>' );
		$zip->addFromString( 'docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::x( sprintf( /* translators: 1: store name 2: date range */ __( '%1$s – Finance report %2$s', 'cod-manager-pro' ), $ctx['store'], $period ) ) . '</dc:title><dc:creator>' . self::x( $ctx['store'] ) . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate( 'Y-m-d\TH:i:s\Z' ) . '</dcterms:created></cp:coreProperties>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $wb . '</sheets>' . ( $names ? '<definedNames>' . $names . '</definedNames>' : '' ) . '</workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . ( $count + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>' );
		$zip->addFromString( 'xl/styles.xml', self::styles_xml() );
		$zip->close();
		return $file;
	}
	/* ------------------------------------------------------------------ */
	/* Print-ready report (the browser saves it as PDF)                    */
	/* ------------------------------------------------------------------ */

	public static function report() {
		self::guard();
		list( $from, $to ) = self::range();
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo self::report_html( $from, $to ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function fmt( $cell ) {
		$t = $cell['t'];
		$v = $cell['v'];
		if ( in_array( $t, array( 'money', 'money_b', 'num', 'pct' ), true ) ) {
			if ( null === $v || '' === $v ) {
				return '<td class="num">—</td>';
			}
			if ( 0.0 === round( (float) $v, 6 ) ) {
				$v = 0;
			}
			if ( 'pct' === $t ) {
				$txt = number_format_i18n( (float) $v, 1 ) . ' %';
			} elseif ( 'num' === $t ) {
				$txt = number_format_i18n( (float) $v );
			} else {
				$txt = CODM_DB::money( $v );
			}
			$cls = 'num' . ( 'money_b' === $t ? ' strong' : '' ) . ( (float) $v < 0 ? ' neg' : '' );
			return '<td class="' . $cls . '">' . esc_html( $txt ) . '</td>';
		}
		return '<td' . ( 'b' === $t ? ' class="strong"' : '' ) . '><bdi>' . esc_html( (string) $v ) . '</bdi></td>';
	}

	public static function report_html( $from, $to ) {
		$sh    = self::sheets( $from, $to );
		$s     = $sh[0]['stats'];
		$store = get_bloginfo( 'name' );
		$d     = function ( $x ) {
			return date_i18n( 'j M Y', strtotime( $x ) );
		};
		$money = function ( $v, $signed = false ) {
			$v = (float) $v;
			if ( abs( $v ) < 0.005 ) {
				$v = 0.0;
			}
			$t = CODM_DB::money( abs( $v ) );
			if ( $v < 0 ) {
				return '− ' . $t;
			}
			return ( $signed && $v > 0 ? '+ ' : '' ) . $t;
		};
		$cost = function ( $v ) use ( $money ) {
			return abs( (float) $v ) < 0.005 ? '<span class="dash">—</span>' : esc_html( $money( $v ) );
		};
		$pct = function ( $v ) {
			return null === $v ? '—' : number_format_i18n( (float) $v, 1 ) . ' %';
		};
		$tone = function ( $v ) {
			return (float) $v < -0.004 ? 'neg' : ( (float) $v > 0.004 ? 'pos' : '' );
		};
		$v      = function ( $cell ) {
			return $cell['v'];
		};
		$period = $d( $from ) . ' – ' . $d( $to );
		$foot   = preg_replace( '/[\x00-\x1F\\\\"]/u', '', $store . ' · ' . __( 'Finance report', 'cod-manager-pro' ) . ' · ' . $period );
		// CSS string for the page counter in the print footer: "Page " counter(page) " of " counter(pages).
		/* translators: 1: current page number 2: total number of pages */
		$page_txt = __( 'Page %1$s of %2$s', 'cod-manager-pro' );
		$page_css = '"' . str_replace(
			array( '%1$s', '%2$s' ),
			array( '" counter(page) "', '" counter(pages) "' ),
			preg_replace( '/[\x00-\x1F\\\\"<>{};]/u', '', $page_txt )
		) . '"';

		// Where the delivered revenue went.
		$rev   = (float) $s['revenue'];
		$net   = (float) $s['net_profit'];
		$parts = array(
			array( __( 'Product costs', 'cod-manager-pro' ), (float) $s['product_costs'], '#9b93e6' ),
			array( __( 'Shipping & returns', 'cod-manager-pro' ), (float) $s['shipping_costs'] + (float) $s['return_costs'], '#eea05a' ),
			array( __( 'Ads & other expenses', 'cod-manager-pro' ), (float) $s['ads'] + (float) $s['other_expenses'], '#e36b75' ),
		);
		$costs = array_sum( array_column( $parts, 1 ) );
		if ( $net > 0 ) {
			$parts[] = array( __( 'Net profit', 'cod-manager-pro' ), $net, '#2f9e6b' );
		}
		$base = max( $rev, $costs, 0.01 );

		// Order outcome.
		$orders = (int) $s['orders'];
		$other  = max( 0, $orders - (int) $s['delivered'] - (int) $s['returned'] - (int) $s['cancelled'] );
		$funnel = array(
			array( __( 'Delivered', 'cod-manager-pro' ), (int) $s['delivered'], '#2f9e6b' ),
			array( __( 'Returned', 'cod-manager-pro' ), (int) $s['returned'], '#e36b75' ),
			array( __( 'Cancelled', 'cod-manager-pro' ), (int) $s['cancelled'], '#b9b6c6' ),
			array( __( 'Still in progress', 'cod-manager-pro' ), $other, '#9b93e6' ),
		);
		$n_rows = count( $sh[1]['rows'] );

		$tables = array(
			'orders'  => $sh[1],
			'exp'     => $sh[2],
			'payouts' => $sh[3],
			'owed'    => $sh[4],
		);
		ob_start();
		?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( /* translators: 1: start date 2: end date */ __( 'Finance report %1$s to %2$s', 'cod-manager-pro' ), $from, $to ) ); ?></title>
<style>
@page {
	size: A4;
	margin: 14mm 13mm 16mm;
	@bottom-left { content: "<?php echo esc_html( $foot ); ?>"; font: 7.5pt -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #8a879c; }
	@bottom-right { content: <?php echo $page_css; // phpcs:ignore WordPress.Security.EscapeOutput -- quotes, backslashes, <, >, {, }, ; and control characters removed above. ?>; font: 7.5pt -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #8a879c; }
}
:root { --ink: #17142a; --muted: #6b6880; --soft: #8a879c; --line: #e6e4ee; --bg: #f7f6fb; --brand: #3b2fc0; --pos: #0f7a3d; --neg: #b42318; }
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body { margin: 0; background: #e9e8f0; color: var(--ink); font: 10pt/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Noto Sans Arabic", Tahoma, Arial, sans-serif; }
.bar { position: sticky; top: 0; z-index: 2; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 10px 20px; background: var(--ink); color: #fff; }
.bar button, .bar a { font: 600 13px/1 inherit; padding: 10px 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,.3); background: transparent; color: #fff; text-decoration: none; cursor: pointer; }
.bar button { background: #fff; color: var(--ink); border-color: #fff; }
.bar span { opacity: .75; font-size: 12px; }
.sheet { width: 210mm; min-height: 297mm; margin: 20px auto; padding: 14mm 13mm 16mm; background: #fff; box-shadow: 0 4px 24px rgba(23,20,42,.12); }

/* Header */
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; padding-bottom: 14px; border-bottom: 1px solid var(--line); }
.eyebrow { font-size: 7.5pt; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--brand); }
.head h1 { margin: 4px 0 0; font-size: 20pt; line-height: 1.15; letter-spacing: -.02em; }
.period { text-align: right; }
.period strong { display: block; font-size: 11pt; margin-top: 4px; }
.period small { color: var(--soft); font-size: 8pt; }

/* KPI tiles */
.kpis { display: grid; grid-template-columns: 1.25fr 1fr 1fr 1fr; gap: 8px; margin: 16px 0 18px; }
.kpi { border: 1px solid var(--line); border-radius: 10px; padding: 11px 12px 10px; break-inside: avoid; }
.kpi .l { font-size: 7.5pt; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
.kpi .v { display: block; margin: 5px 0 2px; font-size: 15pt; font-weight: 700; letter-spacing: -.01em; font-variant-numeric: tabular-nums; white-space: nowrap; }
.kpi .s { font-size: 8pt; color: var(--soft); }
.kpi.main { background: var(--ink); border-color: var(--ink); color: #fff; }
.kpi.main .l, .kpi.main .s { color: rgba(255,255,255,.7); }
.kpi.main .v { font-size: 18pt; }
.kpi.main .v.neg { color: #ff9b8f; }

/* Sections */
h2 { margin: 0 0 8px; font-size: 11pt; letter-spacing: -.01em; break-after: avoid; }
h2 small { font-weight: 400; color: var(--soft); font-size: 8.5pt; margin-left: 6px; }
.block { margin-bottom: 18px; }
.keep { break-inside: avoid; }
.cols { display: grid; grid-template-columns: 1.1fr 1fr; gap: 18px; }
.panel { border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; break-inside: avoid; }
.panel + .panel { margin-top: 10px; }
.muted { color: var(--muted); }
.empty { margin: 0; padding: 12px 14px; border: 1px dashed var(--line); border-radius: 10px; color: var(--soft); font-size: 9pt; }

/* Revenue bar */
.stack { display: flex; height: 14px; border-radius: 7px; overflow: hidden; background: var(--bg); margin: 4px 0 10px; }
.stack i { display: block; height: 100%; }
.legend { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 12px; }
.legend div { font-size: 8pt; color: var(--muted); }
.legend b { display: block; color: var(--ink); font-size: 9.5pt; font-variant-numeric: tabular-nums; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 2px; margin-right: 5px; vertical-align: 0; }
.note { margin: 8px 0 0; font-size: 8.5pt; color: var(--neg); }

/* Statements */
table { width: 100%; border-collapse: collapse; font-size: 9pt; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
th { text-align: left; font-size: 7.5pt; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); padding: 0 6px 6px; border-bottom: 1.5px solid var(--ink); white-space: nowrap; }
td { padding: 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
th:first-child, td:first-child { padding-left: 0; }
th:last-child, td:last-child { padding-right: 0; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
td small { display: block; color: var(--soft); font-size: 7.5pt; margin-top: 1px; }
.pos { color: var(--pos); }
.neg { color: var(--neg); }
.dash { color: #c4c1d1; }
tr.sub td { font-weight: 700; border-bottom-color: #cfcbe0; }
tr.total td { font-weight: 700; border-top: 1.5px solid var(--ink); border-bottom: 0; padding-top: 8px; }
tr.grand td { font-size: 11pt; font-weight: 800; border-top: 1.5px solid var(--ink); border-bottom: 3px double var(--ink); padding: 8px 0; }
.pl td { padding-left: 0; padding-right: 0; }
.pl td.ind { padding-left: 12px; color: var(--muted); }
.kv { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; border-bottom: 1px solid var(--line); font-size: 9pt; }
.kv:last-child { border-bottom: 0; }
.kv b { font-variant-numeric: tabular-nums; white-space: nowrap; }
.kv.hl b { font-size: 11pt; }
.panel h3 { margin: 0 0 6px; font-size: 8pt; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
.fun { display: grid; grid-template-columns: 92px 1fr 64px; gap: 8px; align-items: center; font-size: 8.5pt; padding: 3px 0; }
.fun .track { height: 8px; border-radius: 4px; background: var(--bg); overflow: hidden; }
.fun .track i { display: block; height: 100%; border-radius: 4px; }
.fun .n { text-align: right; font-variant-numeric: tabular-nums; color: var(--muted); }
.fun .n b { color: var(--ink); }
.rates { display: flex; gap: 14px; margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--line); font-size: 8pt; color: var(--muted); }
.rates b { color: var(--ink); }
.tag { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 7.5pt; font-weight: 700; }
.tag.ok { background: #e3f4ea; color: var(--pos); }
.tag.ko { background: #fde8e7; color: var(--neg); }
bdi { unicode-bidi: isolate; }
.foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid var(--line); color: var(--soft); font-size: 7.5pt; }

@media screen and (max-width: 820px) {
	.sheet { width: auto; min-height: 0; margin: 0; padding: 18px 14px; box-shadow: none; }
	.kpis { grid-template-columns: 1fr 1fr; }
	.cols { grid-template-columns: 1fr; }
	.legend { grid-template-columns: 1fr 1fr; }
	.scroll { overflow-x: auto; }
}
@media print {
	body { background: #fff; }
	.bar { display: none; }
	.sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>
<div class="bar">
	<button type="button" onclick="window.print()"><?php esc_html_e( 'Save as PDF / Print', 'cod-manager-pro' ); ?></button>
	<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'codm-finance', 'from' => $from, 'to' => $to ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Back to Finance', 'cod-manager-pro' ); ?></a>
	<span><?php esc_html_e( 'Choose “Save as PDF” as the destination. Turn off “Headers and footers” in More settings if your browser still prints the web address.', 'cod-manager-pro' ); ?></span>
</div>

<div class="sheet">
	<div class="head">
		<div>
			<div class="eyebrow"><?php esc_html_e( 'Finance report', 'cod-manager-pro' ); ?></div>
			<h1><bdi><?php echo esc_html( $store ); ?></bdi></h1>
		</div>
		<div class="period">
			<div class="eyebrow"><?php esc_html_e( 'Period', 'cod-manager-pro' ); ?></div>
			<strong><?php echo esc_html( $period ); ?></strong>
			<small><?php echo esc_html( sprintf( /* translators: %s: date and time the report was generated */ __( 'Generated %s', 'cod-manager-pro' ), date_i18n( 'j M Y, H:i' ) ) ); ?></small>
		</div>
	</div>

	<div class="kpis">
		<div class="kpi main"><span class="l"><?php esc_html_e( 'Net profit', 'cod-manager-pro' ); ?></span><span class="v <?php echo $net < 0 ? 'neg' : ''; ?>"><?php echo esc_html( $money( $net ) ); ?></span><span class="s"><?php echo null === $s['margin'] ? esc_html__( 'No delivered orders yet', 'cod-manager-pro' ) : esc_html( sprintf( /* translators: %s: net margin, e.g. "12.5 %" */ __( '%s net margin', 'cod-manager-pro' ), $pct( $s['margin'] ) ) ); ?></span></div>
		<div class="kpi"><span class="l"><?php esc_html_e( 'Revenue', 'cod-manager-pro' ); ?></span><span class="v"><?php echo esc_html( $money( $rev ) ); ?></span><span class="s"><?php echo esc_html( sprintf( /* translators: %s: number of delivered orders */ _n( '%s delivered order', '%s delivered orders', (int) $s['delivered'], 'cod-manager-pro' ), number_format_i18n( (int) $s['delivered'] ) ) ); ?></span></div>
		<div class="kpi"><span class="l"><?php esc_html_e( 'Owed by couriers', 'cod-manager-pro' ); ?></span><span class="v"><?php echo esc_html( $money( $s['pending_payouts'] ) ); ?></span><span class="s"><?php echo esc_html( sprintf( /* translators: %s: amount received from couriers */ __( 'Received %s', 'cod-manager-pro' ), $money( $s['cod_received'] ) ) ); ?></span></div>
		<div class="kpi"><span class="l"><?php esc_html_e( 'Delivery rate', 'cod-manager-pro' ); ?></span><span class="v"><?php echo esc_html( $pct( $s['delivery_rate'] ) ); ?></span><span class="s"><?php echo esc_html( sprintf( /* translators: %s: number of orders placed */ _n( '%s order placed', '%s orders placed', $orders, 'cod-manager-pro' ), number_format_i18n( $orders ) ) ); ?></span></div>
	</div>

	<div class="block keep">
		<h2><?php esc_html_e( 'Where the revenue went', 'cod-manager-pro' ); ?></h2>
		<?php if ( $rev <= 0 && $costs <= 0 ) : ?>
			<p class="empty"><?php esc_html_e( 'No delivered orders or costs in this period yet.', 'cod-manager-pro' ); ?></p>
		<?php else : ?>
			<div class="stack">
				<?php foreach ( $parts as $p ) : if ( $p[1] <= 0 ) { continue; } ?>
					<i style="width:<?php echo esc_attr( round( $p[1] / $base * 100, 2 ) ); ?>%;background:<?php echo esc_attr( $p[2] ); ?>"></i>
				<?php endforeach; ?>
			</div>
			<div class="legend">
				<?php foreach ( $parts as $p ) : ?>
					<div><span class="dot" style="background:<?php echo esc_attr( $p[2] ); ?>"></span><?php echo esc_html( $p[0] ); ?><b><?php echo esc_html( $money( $p[1] ) ); ?> <span class="muted" style="font-weight:400;font-size:8pt"><?php echo $rev > 0 ? esc_html( number_format_i18n( $p[1] / $rev * 100, 0 ) . ' %' ) : ''; ?></span></b></div>
				<?php endforeach; ?>
			</div>
			<?php if ( $net < 0 ) : ?>
				<p class="note"><?php echo esc_html( sprintf( /* translators: %s: amount of the loss */ __( 'Costs are higher than delivered revenue: this period is a loss of %s.', 'cod-manager-pro' ), $money( abs( $net ) ) ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<div class="cols block">
		<div class="keep">
			<h2><?php esc_html_e( 'Profit and loss', 'cod-manager-pro' ); ?></h2>
			<table class="pl">
				<tr><td class="muted"><?php esc_html_e( 'Sales value', 'cod-manager-pro' ); ?> <small><?php esc_html_e( 'All non-cancelled orders placed', 'cod-manager-pro' ); ?></small></td><td class="num muted"><?php echo esc_html( $money( $s['order_value'] ) ); ?></td></tr>
				<tr class="sub"><td><?php esc_html_e( 'Revenue', 'cod-manager-pro' ); ?> <small style="font-weight:400"><?php esc_html_e( 'Delivered orders only', 'cod-manager-pro' ); ?></small></td><td class="num"><?php echo esc_html( $money( $rev ) ); ?></td></tr>
				<tr><td class="ind"><?php esc_html_e( 'Product costs', 'cod-manager-pro' ); ?></td><td class="num"><?php echo $cost( -$s['product_costs'] ); // phpcs:ignore ?></td></tr>
				<tr class="sub"><td><?php esc_html_e( 'Gross profit', 'cod-manager-pro' ); ?></td><td class="num <?php echo esc_attr( $tone( $s['gross_profit'] ) ); ?>"><?php echo esc_html( $money( $s['gross_profit'] ) ); ?></td></tr>
				<tr><td class="ind"><?php esc_html_e( 'Shipping costs', 'cod-manager-pro' ); ?></td><td class="num"><?php echo $cost( -$s['shipping_costs'] ); // phpcs:ignore ?></td></tr>
				<tr><td class="ind"><?php esc_html_e( 'Return costs', 'cod-manager-pro' ); ?></td><td class="num"><?php echo $cost( -$s['return_costs'] ); // phpcs:ignore ?></td></tr>
				<tr><td class="ind"><?php esc_html_e( 'Advertising', 'cod-manager-pro' ); ?></td><td class="num"><?php echo $cost( -$s['ads'] ); // phpcs:ignore ?></td></tr>
				<tr><td class="ind"><?php esc_html_e( 'Other expenses', 'cod-manager-pro' ); ?></td><td class="num"><?php echo $cost( -$s['other_expenses'] ); // phpcs:ignore ?></td></tr>
				<tr class="grand"><td><?php esc_html_e( 'Net profit', 'cod-manager-pro' ); ?></td><td class="num <?php echo esc_attr( $tone( $net ) ); ?>"><?php echo esc_html( $money( $net ) ); ?></td></tr>
			</table>
		</div>
		<div>
			<div class="panel">
				<h3><?php esc_html_e( 'Cash on delivery', 'cod-manager-pro' ); ?></h3>
				<div class="kv"><span><?php esc_html_e( 'Collected by couriers', 'cod-manager-pro' ); ?></span><b><?php echo esc_html( $money( $s['cod_collected'] ) ); ?></b></div>
				<div class="kv"><span><?php esc_html_e( 'Received from couriers', 'cod-manager-pro' ); ?></span><b><?php echo esc_html( $money( $s['cod_received'] ) ); ?></b></div>
				<div class="kv hl"><span><?php esc_html_e( 'Still owed to you', 'cod-manager-pro' ); ?></span><b><?php echo esc_html( $money( $s['pending_payouts'] ) ); ?></b></div>
			</div>
			<div class="panel">
				<h3><?php echo esc_html( sprintf( /* translators: %s: number of orders placed */ _n( 'Orders · %s placed', 'Orders · %s placed', $orders, 'cod-manager-pro' ), number_format_i18n( $orders ) ) ); ?></h3>
				<?php foreach ( $funnel as $f ) : ?>
					<div class="fun"><span><?php echo esc_html( $f[0] ); ?></span><span class="track"><i style="width:<?php echo esc_attr( $orders ? round( $f[1] / $orders * 100, 1 ) : 0 ); ?>%;background:<?php echo esc_attr( $f[2] ); ?>"></i></span><span class="n"><b><?php echo esc_html( number_format_i18n( $f[1] ) ); ?></b><?php echo $orders ? esc_html( ' · ' . number_format_i18n( $f[1] / $orders * 100, 0 ) . '%' ) : ''; ?></span></div>
				<?php endforeach; ?>
				<div class="rates"><span><?php esc_html_e( 'Avg. order', 'cod-manager-pro' ); ?> <b><?php echo esc_html( $money( $s['aov'] ) ); ?></b></span><span><?php esc_html_e( 'Ad cost / delivery', 'cod-manager-pro' ); ?> <b><?php echo null === $s['cpa'] ? '—' : esc_html( $money( $s['cpa'] ) ); ?></b></span></div>
			</div>
		</div>
	</div>

	<div class="block">
		<h2><?php esc_html_e( 'Profit per order', 'cod-manager-pro' ); ?> <small><?php echo esc_html( sprintf( /* translators: %s: number of orders */ _n( '%s delivered or returned', '%s delivered or returned', $n_rows, 'cod-manager-pro' ), number_format_i18n( $n_rows ) ) ); ?></small></h2>
		<?php if ( ! $tables['orders']['rows'] ) : ?>
			<p class="empty"><?php echo esc_html( $tables['orders']['empty'] ); ?></p>
		<?php else : ?>
			<div class="scroll"><table>
				<thead><tr><th><?php esc_html_e( 'Order', 'cod-manager-pro' ); ?></th><th><?php esc_html_e( 'Customer', 'cod-manager-pro' ); ?></th><th><?php esc_html_e( 'Result', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'COD', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Product', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Shipping', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Return', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Profit', 'cod-manager-pro' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $tables['orders']['rows'] as $r ) : $ok = 'delivered' === $r[4]['k']; $r = array_map( $v, $r ); ?>
					<tr>
						<td><b><?php echo esc_html( $r[0] ); ?></b><small><?php echo esc_html( $d( $r[1] ) ); ?></small></td>
						<td><bdi><?php echo esc_html( $r[2] ); ?></bdi><small><bdi><?php echo esc_html( implode( ' · ', array_filter( array( $r[3], $r[5] ) ) ) ); ?></bdi></small></td>
						<td><span class="tag <?php echo $ok ? 'ok' : 'ko'; ?>"><?php echo esc_html( $r[4] ); ?></span><small><?php echo esc_html( $r[6] ); ?></small></td>
						<td class="num"><?php echo $cost( $r[7] ); // phpcs:ignore ?></td>
						<td class="num"><?php echo $cost( $r[8] ); // phpcs:ignore ?></td>
						<td class="num"><?php echo $cost( $r[9] ); // phpcs:ignore ?></td>
						<td class="num"><?php echo $cost( $r[10] ); // phpcs:ignore ?></td>
						<td class="num <?php echo esc_attr( $tone( $r[11] ) ); ?>"><b><?php echo esc_html( $money( $r[11], true ) ); ?></b></td>
					</tr>
				<?php endforeach; ?>
				<?php $t = array_map( $v, $tables['orders']['total'] ); ?>
					<tr class="total"><td colspan="3"><?php esc_html_e( 'Total', 'cod-manager-pro' ); ?></td><td class="num"><?php echo esc_html( $money( $t[7] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[8] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[9] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[10] ) ); ?></td><td class="num <?php echo esc_attr( $tone( $t[11] ) ); ?>"><?php echo esc_html( $money( $t[11], true ) ); ?></td></tr>
				</tbody>
			</table></div>
		<?php endif; ?>
	</div>

	<div class="block">
		<h2><?php esc_html_e( 'Courier settlement', 'cod-manager-pro' ); ?> <small><?php echo esc_html( sprintf( /* translators: %s: today's date */ __( 'as of %s', 'cod-manager-pro' ), $d( CODM_DB::today() ) ) ); ?></small></h2>
		<?php if ( ! $tables['owed']['rows'] ) : ?>
			<p class="empty"><?php echo esc_html( $tables['owed']['empty'] ); ?></p>
		<?php else : ?>
			<table class="keep">
				<thead><tr><th><?php esc_html_e( 'Shipping company', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Delivered', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Returned', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'COD collected', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Fees', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Expected payout', 'cod-manager-pro' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $tables['owed']['rows'] as $r ) : $r = array_map( $v, $r ); ?>
					<tr><td><bdi><?php echo esc_html( $r[0] ); ?></bdi></td><td class="num"><?php echo esc_html( number_format_i18n( $r[1] ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $r[2] ) ); ?></td><td class="num"><?php echo esc_html( $money( $r[3] ) ); ?></td><td class="num"><?php echo $cost( $r[4] ); // phpcs:ignore ?></td><td class="num"><b><?php echo esc_html( $money( $r[5] ) ); ?></b></td></tr>
				<?php endforeach; ?>
				<?php $t = array_map( $v, $tables['owed']['total'] ); ?>
					<tr class="total"><td><?php esc_html_e( 'Total', 'cod-manager-pro' ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $t[1] ) ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $t[2] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[3] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[4] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[5] ) ); ?></td></tr>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="block">
		<h2><?php esc_html_e( 'Payouts received', 'cod-manager-pro' ); ?> <small><?php esc_html_e( 'in this period', 'cod-manager-pro' ); ?></small></h2>
		<?php if ( ! $tables['payouts']['rows'] ) : ?>
			<p class="empty"><?php echo esc_html( $tables['payouts']['empty'] ); ?></p>
		<?php else : ?>
			<table>
				<thead><tr><th><?php esc_html_e( 'Date', 'cod-manager-pro' ); ?></th><th><?php esc_html_e( 'Company', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Orders', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Expected', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Received', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Difference', 'cod-manager-pro' ); ?></th><th><?php esc_html_e( 'Reference', 'cod-manager-pro' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $tables['payouts']['rows'] as $r ) : $r = array_map( $v, $r ); ?>
					<tr><td><?php echo esc_html( $d( $r[0] ) ); ?></td><td><bdi><?php echo esc_html( $r[1] ); ?></bdi></td><td class="num"><?php echo esc_html( number_format_i18n( $r[2] ) ); ?></td><td class="num"><?php echo esc_html( $money( $r[3] ) ); ?></td><td class="num"><?php echo esc_html( $money( $r[4] ) ); ?></td><td class="num <?php echo esc_attr( $tone( $r[5] ) ); ?>"><?php echo esc_html( $money( $r[5], true ) ); ?></td><td><bdi><?php echo esc_html( $r[6] ); ?></bdi></td></tr>
				<?php endforeach; ?>
				<?php $t = array_map( $v, $tables['payouts']['total'] ); ?>
					<tr class="total"><td colspan="2"><?php esc_html_e( 'Total', 'cod-manager-pro' ); ?></td><td class="num"><?php echo esc_html( number_format_i18n( $t[2] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[3] ) ); ?></td><td class="num"><?php echo esc_html( $money( $t[4] ) ); ?></td><td class="num <?php echo esc_attr( $tone( $t[5] ) ); ?>"><?php echo esc_html( $money( $t[5], true ) ); ?></td><td></td></tr>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="block">
		<h2><?php esc_html_e( 'Expenses', 'cod-manager-pro' ); ?></h2>
		<?php if ( ! $tables['exp']['rows'] ) : ?>
			<p class="empty"><?php echo esc_html( $tables['exp']['empty'] ); ?></p>
		<?php else : ?>
			<table>
				<thead><tr><th><?php esc_html_e( 'Date', 'cod-manager-pro' ); ?></th><th><?php esc_html_e( 'Category', 'cod-manager-pro' ); ?></th><th><?php esc_html_e( 'Description', 'cod-manager-pro' ); ?></th><th class="num"><?php esc_html_e( 'Amount', 'cod-manager-pro' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $tables['exp']['rows'] as $r ) : $r = array_map( $v, $r ); ?>
					<tr><td style="white-space:nowrap"><?php echo esc_html( $d( $r[0] ) ); ?></td><td><?php echo esc_html( $r[1] ); ?></td><td><bdi><?php echo esc_html( $r[2] ); ?></bdi></td><td class="num"><?php echo esc_html( $money( $r[3] ) ); ?></td></tr>
				<?php endforeach; ?>
				<?php $t = array_map( $v, $tables['exp']['total'] ); ?>
					<tr class="total"><td colspan="3"><?php esc_html_e( 'Total', 'cod-manager-pro' ); ?></td><td class="num"><?php echo esc_html( $money( $t[3] ) ); ?></td></tr>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<p class="foot"><?php esc_html_e( 'Orders are counted by the date they were placed; expenses and payouts by their own date. Revenue and costs only include delivered orders; returned orders count their shipping and return fees. “Still owed” covers every delivered or returned order not yet settled with the courier, as of today. Product costs come from the “Cost price” field on each product.', 'cod-manager-pro' ); ?></p>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 600); });</script>
</body>
</html>
		<?php
		return ob_get_clean();
	}
}
