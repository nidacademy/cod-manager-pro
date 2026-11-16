<?php
defined( 'ABSPATH' ) || exit;

/**
 * CSV export and shipping labels (rendering lives in CODM_Labels).
 */
class CODM_Export {

	public static function init() {
		add_action( 'admin_post_codm_export', array( __CLASS__, 'export_csv' ) );
		add_action( 'admin_post_codm_labels', array( __CLASS__, 'labels' ) );
	}

	private static function guard( $action ) {
		if ( ! current_user_can( CODM_Roles::CAP_ORDERS ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cod-manager-pro' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** Filters from the query string (same names as the Orders screen). */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$get = function ( $k ) {
			return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		};
		$ids = isset( $_GET['ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['ids'] ) ) ) ) ) : array();
		// phpcs:enable
		return array(
			'status'   => sanitize_key( $get( 'status' ) ),
			'search'   => $get( 'search' ),
			'city'     => $get( 'city' ),
			'source'   => $get( 'source' ),
			'company'  => $get( 'company' ),
			'from'     => $get( 'from' ),
			'to'       => $get( 'to' ),
			'ids'      => $ids,
			'per_page' => 0,
			'counts'   => false,
		);
	}

	public static function export_csv() {
		self::guard( 'codm_export' );
		// The full customer list (names, phones, addresses) is for managers only.
		if ( ! current_user_can( CODM_Roles::CAP_FINANCE ) ) {
			wp_die( esc_html__( 'Only a manager can export orders.', 'cod-manager-pro' ), 403 );
		}
		$result  = CODM_Orders::query( self::filters() );
		$finance = current_user_can( CODM_Roles::CAP_FINANCE );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=cod-orders-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows Arabic and French correctly.

		$head = array(
			__( 'Order', 'cod-manager-pro' ),
			__( 'Date', 'cod-manager-pro' ),
			__( 'Customer', 'cod-manager-pro' ),
			__( 'Phone', 'cod-manager-pro' ),
			__( 'City', 'cod-manager-pro' ),
			__( 'Address', 'cod-manager-pro' ),
			__( 'Products', 'cod-manager-pro' ),
			__( 'Quantity', 'cod-manager-pro' ),
			__( 'Products total', 'cod-manager-pro' ),
			__( 'Delivery fee', 'cod-manager-pro' ),
			__( 'COD amount', 'cod-manager-pro' ),
			__( 'Status', 'cod-manager-pro' ),
			__( 'Shipping company', 'cod-manager-pro' ),
			__( 'Tracking number', 'cod-manager-pro' ),
			__( 'Unanswered messages', 'cod-manager-pro' ),
			__( 'Delivery attempts', 'cod-manager-pro' ),
			__( 'Source', 'cod-manager-pro' ),
			__( 'Campaign', 'cod-manager-pro' ),
		);
		if ( $finance ) {
			$head = array_merge( $head, array( __( 'Product cost', 'cod-manager-pro' ), __( 'Shipping cost', 'cod-manager-pro' ), __( 'Return cost', 'cod-manager-pro' ), __( 'Profit', 'cod-manager-pro' ) ) );
		}
		fputcsv( $out, $head );

		foreach ( $result['rows'] as $r ) {
			$items    = json_decode( (string) $r->items, true );
			$products = array();
			foreach ( (array) $items as $i ) {
				$products[] = (int) $i['qty'] . ' x ' . $i['name'] . ' (' . number_format( (float) $i['price'], 2, '.', '' ) . ')';
			}
			$line = array(
				$r->order_number,
				$r->created_at,
				$r->customer_name,
				$r->phone ? '+' . $r->phone : '',
				$r->city,
				$r->address,
				implode( ' | ', $products ),
				(int) $r->items_count,
				$r->subtotal,
				$r->delivery_fee,
				$r->total,
				CODM_Status::label( $r->status ),
				CODM_Shipping::name( $r->shipping_company_id ),
				$r->tracking_number,
				$r->call_attempts,
				$r->delivery_attempts,
				isset( $r->source ) ? $r->source : '',
				isset( $r->campaign ) ? $r->campaign : '',
			);
			if ( $finance ) {
				$p    = CODM_Finance::order_profit( $r );
				$line = array_merge( $line, array( $r->product_cost, $r->shipping_cost, $r->return_cost, $p['final'] ? $p['value'] : '' ) );
			}
			// Prevent spreadsheet formula injection.
			$line = array_map( function ( $v ) {
				$v = (string) $v;
				return preg_match( '/^[=+\-@\t\r|]/', $v ) && ! is_numeric( $v ) ? "'" . $v : $v;
			}, $line );
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	public static function labels() {
		self::guard( 'codm_labels' );
		$filters = self::filters();
		if ( ! $filters['ids'] ) {
			wp_die( esc_html__( 'Select the orders to print first.', 'cod-manager-pro' ) );
		}
		// Remember each user's preferred paper.
		$user   = get_current_user_id();
		$format = isset( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( array_key_exists( $format, CODM_Labels::formats() ) ) {
			update_user_meta( $user, 'codm_label_format', $format );
		} else {
			$format = (string) get_user_meta( $user, 'codm_label_format', true );
		}
		$base   = add_query_arg( array(
			'action'   => 'codm_labels',
			'_wpnonce' => wp_create_nonce( 'codm_labels' ),
			'ids'      => implode( ',', $filters['ids'] ),
		), admin_url( 'admin-post.php' ) );
		$result = CODM_Orders::query( $filters );
		CODM_Labels::render( $result['rows'], $format, $base );
	}
}
