<?php
defined( 'ABSPATH' ) || exit;

/**
 * Customer database keyed by normalised phone number.
 */
class CODM_Customers {

	public static function init() {
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_checkout' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_block_checkout' ), 10, 2 );
	}

	public static function table() {
		return CODM_DB::table( 'customers' );
	}

	/** Create or update a customer by phone, return its id. */
	public static function upsert( $phone, $name, $city ) {
		global $wpdb;
		if ( '' === (string) $phone ) {
			return 0;
		}
		$t   = self::table();
		$now = CODM_DB::now();
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO $t (phone, name, city, created_at, updated_at) VALUES (%s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE name = VALUES(name), city = VALUES(city), updated_at = VALUES(updated_at)",
			$phone, $name, $city, $now, $now
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE phone = %s", $phone ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function get_many( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id IN (' . CODM_DB::placeholders( $ids ) . ')', $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->id ] = $r;
		}
		return $out;
	}

	/** Order counts per customer: total, delivered, returned, cancelled, active, spent. */
	public static function stats( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$sql  = "SELECT customer_id, COUNT(*) AS total,
				SUM(status = 'delivered') AS delivered,
				SUM(status = 'returned') AS returned,
				SUM(status = 'cancelled') AS cancelled,
				SUM(status NOT IN ('delivered','returned','cancelled')) AS active,
				SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END) AS spent
			FROM " . CODM_DB::table( 'orders' ) . ' WHERE customer_id IN (' . CODM_DB::placeholders( $ids ) . ') GROUP BY customer_id';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->customer_id ] = $r;
		}
		return $out;
	}

	/**
	 * Risk level: blacklisted | high | medium | trusted | new
	 */
	public static function risk( $stat, $customer ) {
		if ( $customer && ! empty( $customer->blacklisted ) ) {
			return 'blacklisted';
		}
		$returned  = $stat ? (int) $stat->returned : 0;
		$delivered = $stat ? (int) $stat->delivered : 0;
		$cancelled = $stat ? (int) $stat->cancelled : 0;
		$threshold = max( 1, (int) CODM_DB::setting( 'risk_returns' ) );

		if ( $returned >= $threshold ) {
			return 'high';
		}
		if ( $returned > 0 || ( $cancelled >= 2 && $cancelled > $delivered ) ) {
			return 'medium';
		}
		if ( $delivered > 0 ) {
			return 'trusted';
		}
		return 'new';
	}

	public static function set_blacklist( $id, $flag, $reason = '' ) {
		global $wpdb;
		return false !== $wpdb->update(
			self::table(),
			array(
				'blacklisted'      => $flag ? 1 : 0,
				'blacklist_reason' => $flag ? substr( $reason, 0, 255 ) : '',
				'updated_at'       => CODM_DB::now(),
			),
			array( 'id' => (int) $id )
		);
	}

	public static function is_blacklisted_phone( $phone ) {
		global $wpdb;
		$phone = CODM_DB::normalize_phone( $phone );
		if ( '' === $phone ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT blacklisted FROM ' . self::table() . ' WHERE phone = %s', $phone ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Classic checkout: blacklist, repeat-order and phone rules for cash on delivery. */
	public static function validate_classic_checkout( $data, $errors ) {
		if ( ! isset( $data['payment_method'] ) || 'cod' !== $data['payment_method'] ) {
			return;
		}
		$ids = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids[] = (int) $item['product_id'];
			}
		}
		$msg = CODM_Extra::check( isset( $data['billing_phone'] ) ? $data['billing_phone'] : '', $ids );
		if ( '' !== $msg ) {
			$errors->add( 'codm_refused', $msg );
		}
	}

	/** Block checkout (Store API): same rules. */
	public static function validate_block_checkout( $order, $request ) {
		if ( ! ( $order instanceof WC_Order ) ) {
			return;
		}
		$method = isset( $request['payment_method'] ) && $request['payment_method'] ? $request['payment_method'] : $order->get_payment_method();
		if ( 'cod' !== $method ) {
			return;
		}
		$ids = array();
		foreach ( $order->get_items() as $item ) {
			$ids[] = (int) $item->get_product_id();
		}
		$msg = CODM_Extra::check( $order->get_billing_phone(), $ids );
		if ( '' !== $msg && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'codm_refused', $msg, 400 );
		}
	}
}
