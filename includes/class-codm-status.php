<?php
defined( 'ABSPATH' ) || exit;

/**
 * COD order statuses and their mapping to WooCommerce order statuses.
 *
 * COD Manager Pro status      WooCommerce status
 * ---------------------   ---------------------------------
 * New                     Awaiting confirmation (custom)
 * Pending confirmation    Awaiting confirmation (custom)
 * Confirmed               Processing
 * Processing              Processing
 * Ready to ship           Ready to ship (custom)
 * Shipped                 Shipped (custom)
 * Out for delivery        Out for delivery (custom)
 * Delivered               Completed
 * Cancelled               Cancelled
 * Returned                Returned / refused (custom)
 */
class CODM_Status {

	const WC_AWAITING = 'awaiting-conf';
	const WC_READY    = 'cod-ready';
	const WC_SHIPPED  = 'cod-shipped';
	const WC_OUT      = 'cod-out';
	const WC_RETURNED = 'cod-returned';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_wc_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_wc_statuses' ) );
	}

	public static function all() {
		return array(
			'new'              => __( 'New', 'cod-manager-pro' ),
			'pending'          => __( 'Pending confirmation', 'cod-manager-pro' ),
			'confirmed'        => __( 'Confirmed', 'cod-manager-pro' ),
			'processing'       => __( 'Processing', 'cod-manager-pro' ),
			'ready'            => __( 'Ready to ship', 'cod-manager-pro' ),
			'shipped'          => __( 'Shipped', 'cod-manager-pro' ),
			'out_for_delivery' => __( 'Out for delivery', 'cod-manager-pro' ),
			'delivered'        => __( 'Delivered', 'cod-manager-pro' ),
			'cancelled'        => __( 'Cancelled', 'cod-manager-pro' ),
			'returned'         => __( 'Returned', 'cod-manager-pro' ),
		);
	}

	/** The six steps shown to the team: New → Confirmed → Shipped → Delivered, plus Cancelled and Returned. */
	public static function flow() {
		$all = self::all();
		$out = array();
		foreach ( array( 'new', 'confirmed', 'shipped', 'delivered', 'cancelled', 'returned' ) as $k ) {
			$out[ $k ] = $all[ $k ];
		}
		return $out;
	}

	/** Older detailed statuses folded into the six steps. */
	public static function normalize( $status ) {
		$map = array(
			'pending'          => 'new',
			'processing'       => 'confirmed',
			'ready'            => 'confirmed',
			'out_for_delivery' => 'shipped',
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : $status;
	}

	public static function exists( $status ) {
		return is_string( $status ) && array_key_exists( $status, self::all() );
	}

	public static function label( $status ) {
		$all = self::all();
		return isset( $all[ $status ] ) ? $all[ $status ] : $status;
	}

	/** Waiting for a call. */
	public static function open() {
		return array( 'new', 'pending' );
	}

	/** Confirmed and moving through fulfilment. */
	public static function in_progress() {
		return array( 'confirmed', 'processing', 'ready', 'shipped', 'out_for_delivery' );
	}

	public static function closed() {
		return array( 'delivered', 'cancelled', 'returned' );
	}

	public static function wc_statuses() {
		return array(
			self::WC_AWAITING => _x( 'Awaiting confirmation', 'Order status', 'cod-manager-pro' ),
			self::WC_READY    => _x( 'Ready to ship', 'Order status', 'cod-manager-pro' ),
			self::WC_SHIPPED  => _x( 'Shipped', 'Order status', 'cod-manager-pro' ),
			self::WC_OUT      => _x( 'Out for delivery', 'Order status', 'cod-manager-pro' ),
			self::WC_RETURNED => _x( 'Returned / refused', 'Order status', 'cod-manager-pro' ),
		);
	}

	public static function to_wc( $status ) {
		$map = array(
			'new'              => self::WC_AWAITING,
			'pending'          => self::WC_AWAITING,
			'confirmed'        => 'processing',
			'processing'       => 'processing',
			'ready'            => self::WC_READY,
			'shipped'          => self::WC_SHIPPED,
			'out_for_delivery' => self::WC_OUT,
			'delivered'        => 'completed',
			'cancelled'        => 'cancelled',
			'returned'         => self::WC_RETURNED,
		);
		return isset( $map[ $status ] ) ? $map[ $status ] : '';
	}

	/**
	 * Translate a WooCommerce status into a COD Manager Pro status, given the current one.
	 * Returns '' when the WooCommerce status should not change anything.
	 */
	public static function from_wc( $wc_status, $current ) {
		switch ( $wc_status ) {
			case self::WC_AWAITING:
			case 'on-hold':
			case 'pending':
				return in_array( $current, self::open(), true ) ? $current : 'new';
			case 'processing':
			case self::WC_READY:
				return in_array( $current, array( 'confirmed', 'processing', 'ready' ), true ) ? $current : 'confirmed';
			case self::WC_SHIPPED:
			case self::WC_OUT:
				return in_array( $current, array( 'shipped', 'out_for_delivery' ), true ) ? $current : 'shipped';
			case 'completed':
				return 'delivered';
			case 'cancelled':
			case 'failed':
				return 'cancelled';
			case 'refunded':
				// A refund before shipping is a cancellation; after shipping the parcel came back.
				return in_array( $current, array( 'shipped', 'out_for_delivery', 'delivered', 'returned' ), true ) ? 'returned' : 'cancelled';
			case self::WC_RETURNED:
				return 'returned';
		}
		return '';
	}

	/** Core WooCommerce status each custom status falls back to while the plugin is inactive. */
	private static function fallback_map() {
		return array(
			self::WC_AWAITING => 'on-hold',
			self::WC_READY    => 'processing',
			self::WC_SHIPPED  => 'processing',
			self::WC_OUT      => 'processing',
			self::WC_RETURNED => 'cancelled',
		);
	}

	/** Order tables to update: the posts table always, the HPOS table when it exists. */
	private static function order_tables() {
		global $wpdb;
		$tables = array( array( $wpdb->posts, 'ID', 'post_status', "post_type IN ('shop_order','shop_order_placehold')" ) );
		$hpos   = $wpdb->prefix . 'wc_orders';
		if ( CODM_DB::table_exists( $hpos ) ) {
			$tables[] = array( $hpos, 'id', 'status', "type = 'shop_order'" );
		}
		return $tables;
	}

	/**
	 * On deactivation, move orders out of the custom statuses so they stay visible in WooCommerce,
	 * and remember them so reactivation can put them back. Direct updates: no emails, no stock changes.
	 */
	public static function deactivate() {
		global $wpdb;
		$saved = array();
		foreach ( self::fallback_map() as $custom => $core ) {
			$ids = array();
			foreach ( self::order_tables() as $t ) {
				$found = $wpdb->get_col( $wpdb->prepare( "SELECT {$t[1]} FROM {$t[0]} WHERE {$t[3]} AND {$t[2]} = %s", 'wc-' . $custom ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$ids   = array_merge( $ids, array_map( 'intval', $found ) );
				$wpdb->query( $wpdb->prepare( "UPDATE {$t[0]} SET {$t[2]} = %s WHERE {$t[3]} AND {$t[2]} = %s", 'wc-' . $core, 'wc-' . $custom ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
			if ( $ids ) {
				$saved[ $custom ] = array_values( array_unique( $ids ) );
			}
		}
		update_option( 'codm_status_backup', $saved, false );
		wp_cache_flush();
	}

	/** On activation, put back the custom statuses saved at deactivation (only if the order was not changed since). */
	public static function restore() {
		global $wpdb;
		$saved = get_option( 'codm_status_backup' );
		if ( ! is_array( $saved ) || ! $saved ) {
			return;
		}
		$map = self::fallback_map();
		foreach ( $saved as $custom => $ids ) {
			$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
			if ( ! $ids || ! isset( $map[ $custom ] ) ) {
				continue;
			}
			foreach ( array_chunk( $ids, 500 ) as $chunk ) {
				foreach ( self::order_tables() as $t ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$t[0]} SET {$t[2]} = %s WHERE {$t[3]} AND {$t[2]} = %s AND {$t[1]} IN (" . CODM_DB::placeholders( $chunk ) . ')',
						array_merge( array( 'wc-' . $custom, 'wc-' . $map[ $custom ] ), $chunk )
					) ); // phpcs:ignore WordPress.DB.PreparedSQL
				}
			}
		}
		delete_option( 'codm_status_backup' );
		wp_cache_flush();
	}

	public static function register_wc_statuses() {
		foreach ( self::wc_statuses() as $slug => $label ) {
			$count = $label . ' <span class="count">(%s)</span>';
			register_post_status( 'wc-' . $slug, array(
				'label'                     => $label,
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => array(
					0          => $count,
					1          => $count,
					'singular' => $count,
					'plural'   => $count,
					'context'  => null,
					'domain'   => null,
				),
			) );
		}
	}

	public static function add_wc_statuses( $statuses ) {
		$custom = self::wc_statuses();
		$out    = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-pending' === $key ) {
				$out[ 'wc-' . self::WC_AWAITING ] = $custom[ self::WC_AWAITING ];
			}
			if ( 'wc-processing' === $key ) {
				$out[ 'wc-' . self::WC_READY ]   = $custom[ self::WC_READY ];
				$out[ 'wc-' . self::WC_SHIPPED ] = $custom[ self::WC_SHIPPED ];
				$out[ 'wc-' . self::WC_OUT ]     = $custom[ self::WC_OUT ];
			}
		}
		foreach ( $custom as $slug => $label ) {
			if ( ! isset( $out[ 'wc-' . $slug ] ) ) {
				$out[ 'wc-' . $slug ] = $label;
			}
		}
		return $out;
	}
}
