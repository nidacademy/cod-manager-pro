<?php
defined( 'ABSPATH' ) || exit;

/**
 * Keeps COD Manager Pro and WooCommerce in sync.
 *
 * - New WooCommerce orders are copied into the COD Manager Pro orders table.
 * - Edits to an order in WooCommerce refresh customer, items and totals.
 * - Status changes in COD Manager Pro update the WooCommerce status (and vice versa).
 */
class CODM_Sync {

	/** True while COD Manager Pro itself writes to WooCommerce, to avoid loops. */
	private static $busy = false;

	public static function init() {
		add_filter( 'woocommerce_cod_process_payment_order_status', array( __CLASS__, 'cod_initial_status' ), 20, 2 );

		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'sync_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'sync_order_object' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'sync_order' ), 20, 1 );
		add_action( 'woocommerce_update_order', array( __CLASS__, 'refresh_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_wc_status_changed' ), 20, 3 );

		// Orders trashed or deleted in WooCommerce leave COD Manager Pro; restored ones come back.
		add_action( 'woocommerce_trash_order', array( __CLASS__, 'remove_order' ), 10, 1 );
		add_action( 'woocommerce_before_delete_order', array( __CLASS__, 'remove_order' ), 10, 1 );
		add_action( 'woocommerce_untrash_order', array( __CLASS__, 'restore_order' ), 20, 1 );
		add_action( 'wp_trash_post', array( __CLASS__, 'remove_post' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'remove_post' ), 10, 1 );
		add_action( 'untrashed_post', array( __CLASS__, 'restore_post' ), 20, 1 );

		// Stock: keep it reserved through the COD pipeline, put it back on returns.
		foreach ( array( CODM_Status::WC_AWAITING, CODM_Status::WC_READY, CODM_Status::WC_SHIPPED, CODM_Status::WC_OUT ) as $s ) {
			add_action( 'woocommerce_order_status_' . $s, 'wc_maybe_reduce_stock_levels' );
		}
		add_action( 'woocommerce_order_status_' . CODM_Status::WC_RETURNED, 'wc_maybe_increase_stock_levels' );

		// Keep WooCommerce emails working with the custom "Awaiting confirmation" status.
		add_action( 'woocommerce_order_status_pending_to_' . CODM_Status::WC_AWAITING, array( __CLASS__, 'email_new_order' ), 10, 2 );
		add_action( 'woocommerce_order_status_' . CODM_Status::WC_AWAITING . '_to_processing', array( __CLASS__, 'email_processing' ), 10, 2 );
	}

	public static function cod_initial_status( $status, $order = null ) {
		return CODM_DB::setting( 'hold_cod' ) ? CODM_Status::WC_AWAITING : $status;
	}

	private static function is_order_post( $post_id ) {
		return in_array( get_post_type( $post_id ), array( 'shop_order', 'shop_order_placehold' ), true );
	}

	public static function remove_post( $post_id ) {
		if ( self::is_order_post( $post_id ) ) {
			self::remove_order( $post_id );
		}
	}

	public static function restore_post( $post_id ) {
		if ( self::is_order_post( $post_id ) ) {
			self::restore_order( $post_id );
		}
	}

	/**
	 * Take a trashed or deleted WooCommerce order out of COD Manager Pro.
	 * Orders already settled in a courier payout are kept, so the payout stays complete.
	 */
	public static function remove_order( $order_id ) {
		global $wpdb;
		$order_id = absint( $order_id );
		$row      = $order_id ? CODM_Orders::get( $order_id ) : null;
		if ( ! $row || (int) $row->payout_id > 0 ) {
			return;
		}
		$wpdb->delete( CODM_DB::table( 'orders' ), array( 'order_id' => $order_id ), array( '%d' ) );
		$wpdb->delete( CODM_DB::table( 'order_history' ), array( 'order_id' => $order_id ), array( '%d' ) );
		do_action( 'codm_order_removed', $order_id );
	}

	public static function restore_order( $order_id ) {
		$order_id = absint( $order_id );
		if ( $order_id && ! CODM_Orders::get( $order_id ) ) {
			self::sync_order( $order_id );
		}
	}

	public static function sync_order_object( $order ) {
		if ( $order instanceof WC_Order ) {
			self::sync_order( $order->get_id() );
		}
	}

	public static function refresh_order( $order_id ) {
		if ( ! self::$busy && CODM_Orders::get( $order_id ) ) {
			self::sync_order( $order_id );
		}
	}

	/** Status a brand-new order starts with in COD Manager Pro. */
	private static function initial_status( WC_Order $order ) {
		$wc = $order->get_status();
		if ( 'cod' === $order->get_payment_method() && in_array( $wc, array( 'pending', 'on-hold', 'processing', CODM_Status::WC_AWAITING ), true ) ) {
			return 'new';
		}
		$status = CODM_Status::from_wc( $wc, 'new' );
		return $status ? $status : 'new';
	}

	/**
	 * Insert or refresh one WooCommerce order.
	 *
	 * @param int  $order_id WooCommerce order id.
	 * @param bool $import   True for bulk imports (logged differently).
	 * @return bool True when the order is stored.
	 */
	public static function sync_order( $order_id, $import = false ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! ( $order instanceof WC_Order ) ) {
			return false;
		}
		if ( in_array( $order->get_status(), array( 'checkout-draft', 'draft', 'auto-draft', 'trash' ), true ) ) {
			return false;
		}
		if ( CODM_DB::setting( 'only_cod' ) && 'cod' !== $order->get_payment_method() ) {
			return false;
		}

		$items    = array();
		$subtotal = 0.0;
		$cost     = 0.0;
		$count    = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
				continue;
			}
			$qty       = max( 0, (int) $item->get_quantity() );
			$line      = (float) $item->get_total() + (float) $item->get_total_tax();
			$product   = $item->get_product();
			$unit_cost = CODM_Products::get_cost( $item->get_product_id(), $item->get_variation_id() );
			$items[]   = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => (int) $item->get_variation_id(),
				'name'         => $item->get_name(),
				'sku'          => $product ? (string) $product->get_sku() : '',
				'qty'          => $qty,
				'price'        => $qty ? round( $line / $qty, 2 ) : 0,
				'cost'         => $unit_cost,
			);
			$subtotal += $line;
			$cost     += $unit_cost * $qty;
			$count    += $qty;
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		}
		$phone = $order->get_billing_phone();
		if ( '' === $phone && method_exists( $order, 'get_shipping_phone' ) ) {
			$phone = $order->get_shipping_phone();
		}
		$phone   = CODM_DB::normalize_phone( $phone );
		$city    = $order->get_billing_city() ? $order->get_billing_city() : $order->get_shipping_city();
		$address = implode( ', ', array_filter( array(
			$order->get_billing_address_1() ? $order->get_billing_address_1() : $order->get_shipping_address_1(),
			$order->get_billing_address_2() ? $order->get_billing_address_2() : $order->get_shipping_address_2(),
		) ) );

		$data = array(
			'order_number'   => (string) $order->get_order_number(),
			'customer_id'    => CODM_Customers::upsert( $phone, $name, (string) $city ),
			'customer_name'  => $name,
			'phone'          => $phone,
			'city'           => (string) $city,
			'address'        => $address,
			'items'          => wp_json_encode( $items ),
			'items_count'    => $count,
			'subtotal'       => round( $subtotal, 2 ),
			'delivery_fee'   => round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2 ),
			'discount'       => round( (float) $order->get_discount_total(), 2 ),
			'total'          => round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 ),
			'payment_method' => $order->get_payment_method_title() ? $order->get_payment_method_title() : $order->get_payment_method(),
		);
		list( $data['source'], $data['campaign'] ) = CODM_Extra::source_of( $order );

		$existing = CODM_Orders::get( $order_id );
		// Unpaid card orders (abandoned payments) are added only once they move past "Pending payment".
		if ( ! $existing && 'pending' === $order->get_status() && 'cod' !== $order->get_payment_method() ) {
			return false;
		}
		if ( $existing ) {
			// Product cost is a snapshot once the order is closed, so history stays accurate.
			if ( ! in_array( $existing->status, CODM_Status::closed(), true ) ) {
				$data['product_cost'] = round( $cost, 2 );
			}
			CODM_Orders::update( $order_id, $data );
			return true;
		}

		$created    = $order->get_date_created();
		$now        = CODM_DB::now();
		$company_id = (int) CODM_DB::setting( 'default_company' );
		$data      += array(
			'order_id'            => (int) $order_id,
			'product_cost'        => round( $cost, 2 ),
			'status'              => self::initial_status( $order ),
			'shipping_company_id' => $company_id,
			'shipping_cost'       => CODM_Shipping::default_cost( $company_id ),
			'tracking_number'     => (string) $order->get_meta( '_codm_tracking_number' ),
			'parcels'             => 1,
			'allow_open'          => CODM_DB::setting( 'allow_open' ) ? 1 : 0,
			'created_at'          => $created ? $created->date( 'Y-m-d H:i:s' ) : $now,
			'updated_at'          => $now,
		);
		if ( 'delivered' === $data['status'] ) {
			$data['delivered_at'] = $data['created_at'];
		}

		// The unique key on order_id makes a duplicate insert from a second hook fail harmlessly.
		$inserted = $wpdb->insert( CODM_DB::table( 'orders' ), $data );
		if ( $inserted ) {
			CODM_Orders::history( $order_id, $import ? 'import' : 'created', $import ? __( 'Imported from WooCommerce', 'cod-manager-pro' ) : __( 'Order received', 'cod-manager-pro' ), '', $data['status'] );
			if ( ! $import ) {
				do_action( 'codm_order_created', $order_id, $data['status'] );
			}
		}
		return (bool) $inserted;
	}

	/** Mirror WooCommerce status changes made outside COD Manager Pro. */
	public static function on_wc_status_changed( $order_id, $from, $to ) {
		if ( self::$busy ) {
			return;
		}
		$row = CODM_Orders::get( $order_id );
		if ( ! $row ) {
			self::sync_order( $order_id );
			return;
		}
		// The first status a COD order gets at checkout is not a confirmation.
		$order = wc_get_order( $order_id );
		if ( 'new' === $row->status && in_array( $from, array( 'pending', 'checkout-draft' ), true ) && in_array( $to, array( CODM_Status::WC_AWAITING, 'on-hold', 'processing' ), true ) && $order && 'cod' === $order->get_payment_method() ) {
			return;
		}
		$new = CODM_Status::from_wc( $to, $row->status );
		if ( $new && $new !== $row->status ) {
			/* translators: %s: WooCommerce status name */
			CODM_Orders::change_status( $order_id, $new, sprintf( __( 'WooCommerce status changed to %s', 'cod-manager-pro' ), wc_get_order_status_name( $to ) ), false );
		}
	}

	/** Change the WooCommerce status from COD Manager Pro. */
	public static function set_wc_status( $order_id, $wc_status, $note = '' ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $wc_status ) {
			return;
		}
		self::$busy = true;
		try {
			if ( $order->get_status() !== $wc_status ) {
				$order->update_status( $wc_status, $note );
			} elseif ( $note ) {
				$order->add_order_note( $note );
			}
		} finally {
			self::$busy = false;
		}
	}

	/** Write edited details back to the WooCommerce order. */
	public static function push_details( $order_id, array $data ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( isset( $data['customer_name'] ) ) {
			$parts = preg_split( '/\s+/', trim( $data['customer_name'] ), 2 );
			$order->set_billing_first_name( $parts[0] );
			$order->set_billing_last_name( isset( $parts[1] ) ? $parts[1] : '' );
			if ( $order->get_shipping_first_name() ) {
				$order->set_shipping_first_name( $parts[0] );
				$order->set_shipping_last_name( isset( $parts[1] ) ? $parts[1] : '' );
			}
		}
		if ( isset( $data['phone'] ) ) {
			$order->set_billing_phone( '+' . ltrim( $data['phone'], '+' ) );
		}
		if ( isset( $data['city'] ) ) {
			$order->set_billing_city( $data['city'] );
			if ( $order->get_shipping_city() ) {
				$order->set_shipping_city( $data['city'] );
			}
		}
		if ( isset( $data['address'] ) ) {
			$order->set_billing_address_1( $data['address'] );
			$order->set_billing_address_2( '' );
			if ( $order->get_shipping_address_1() ) {
				$order->set_shipping_address_1( $data['address'] );
				$order->set_shipping_address_2( '' );
			}
		}
		if ( isset( $data['shipping_company_id'] ) ) {
			$order->update_meta_data( '_codm_shipping_company', CODM_Shipping::name( $data['shipping_company_id'] ) );
		}
		if ( isset( $data['tracking_number'] ) ) {
			$order->update_meta_data( '_codm_tracking_number', $data['tracking_number'] );
		}
		self::$busy = true;
		try {
			$order->save();
		} finally {
			self::$busy = false;
		}
	}

	private static function send_email( $class, $order_id, $order ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return;
		}
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails[ $class ] ) ) {
			$emails[ $class ]->trigger( $order_id, $order );
		}
	}

	public static function email_new_order( $order_id, $order = null ) {
		self::send_email( 'WC_Email_New_Order', $order_id, $order );
	}

	public static function email_processing( $order_id, $order = null ) {
		self::send_email( 'WC_Email_Customer_Processing_Order', $order_id, $order );
	}

	/** Import WooCommerce orders from the last N days that are not stored yet. */
	public static function import_recent( $days = 90 ) {
		$ids   = wc_get_orders( array(
			'type'         => 'shop_order',
			'limit'        => 2000,
			'return'       => 'ids',
			'date_created' => '>' . ( time() - (int) $days * DAY_IN_SECONDS ),
		) );
		$count = 0;
		foreach ( $ids as $id ) {
			if ( ! CODM_Orders::get( $id ) && self::sync_order( $id, true ) ) {
				$count++;
			}
		}
		return $count;
	}


}
