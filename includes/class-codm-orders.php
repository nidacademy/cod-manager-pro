<?php
defined( 'ABSPATH' ) || exit;

/**
 * Order repository and business rules.
 */
class CODM_Orders {

	public static function table() {
		return CODM_DB::table( 'orders' );
	}

	public static function get( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function update( $order_id, array $data ) {
		global $wpdb;
		$data['updated_at'] = CODM_DB::now();
		return $wpdb->update( self::table(), $data, array( 'order_id' => (int) $order_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* History                                                             */
	/* ------------------------------------------------------------------ */

	public static function history( $order_id, $type, $message = '', $from = '', $to = '' ) {
		global $wpdb;
		$wpdb->insert( CODM_DB::table( 'order_history' ), array(
			'order_id'    => (int) $order_id,
			'user_id'     => get_current_user_id(),
			'type'        => substr( $type, 0, 20 ),
			'from_status' => (string) $from,
			'to_status'   => (string) $to,
			'message'     => (string) $message,
			'created_at'  => CODM_DB::now(),
		) );
	}

	public static function get_history( $order_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . CODM_DB::table( 'order_history' ) . ' WHERE order_id = %d ORDER BY id DESC LIMIT 100', $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $h ) {
			$user  = $h->user_id ? get_userdata( $h->user_id ) : null;
			$out[] = array(
				'type'    => $h->type,
				'from'    => $h->from_status ? CODM_Status::label( $h->from_status ) : '',
				'to'      => $h->to_status ? CODM_Status::label( $h->to_status ) : '',
				'to_key'  => $h->to_status,
				'message' => $h->message,
				'user'    => $user ? $user->display_name : __( 'System', 'cod-manager-pro' ),
				'date'    => mysql2date( 'd M Y, H:i', $h->created_at ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Actions                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Change an order's status. Updates timestamps and costs, logs history and (optionally) WooCommerce.
	 *
	 * @return true|WP_Error
	 */
	public static function change_status( $order_id, $new, $note = '', $sync_wc = true ) {
		$new = CODM_Status::normalize( $new );
		if ( ! CODM_Status::exists( $new ) ) {
			return new WP_Error( 'codm_bad_status', __( 'Unknown status.', 'cod-manager-pro' ), array( 'status' => 400 ) );
		}
		$row = self::get( $order_id );
		if ( ! $row ) {
			return new WP_Error( 'codm_not_found', __( 'Order not found in COD Manager Pro.', 'cod-manager-pro' ), array( 'status' => 404 ) );
		}
		$old = $row->status;
		if ( $old === $new ) {
			if ( '' !== $note ) {
				self::add_note( $order_id, $note );
			}
			return true;
		}

		// Orders already settled in a courier payout keep their status, so payouts stay correct.
		if ( (int) $row->payout_id > 0 ) {
			$msg = sprintf(
				/* translators: %d payout id */
				__( 'This order is settled in courier payout #%d, so its status can no longer change.', 'cod-manager-pro' ),
				(int) $row->payout_id
			);
			if ( ! $sync_wc ) {
				/* translators: 1: requested status 2: reason */
				self::history( $order_id, 'note', sprintf( __( 'Status change to %1$s ignored. %2$s', 'cod-manager-pro' ), CODM_Status::label( $new ), $msg ) );
			}
			return new WP_Error( 'codm_settled', $msg, array( 'status' => 409 ) );
		}

		// Re-opening a delivered, returned or cancelled order changes the money figures: finance users only.
		if ( $sync_wc && in_array( $old, CODM_Status::closed(), true ) && is_user_logged_in() && ! current_user_can( CODM_Roles::CAP_FINANCE ) ) {
			return new WP_Error(
				'codm_closed',
				sprintf(
					/* translators: %s status */
					__( 'This order is already %s. Only a manager can change it.', 'cod-manager-pro' ),
					CODM_Status::label( $old )
				),
				array( 'status' => 403 )
			);
		}

		$now  = CODM_DB::now();
		$uid  = get_current_user_id();
		$data = array( 'status' => $new );

		switch ( $new ) {
			case 'confirmed':
				if ( empty( $row->confirmed_at ) ) {
					$data['confirmed_at'] = $now;
				}
				if ( $uid ) {
					$data['agent_id'] = $uid;
				}
				break;
			case 'shipped':
				if ( empty( $row->shipped_at ) ) {
					$data['shipped_at'] = $now;
				}
				break;
			case 'out_for_delivery':
				$data['delivery_attempts'] = (int) $row->delivery_attempts + 1;
				if ( empty( $row->shipped_at ) ) {
					$data['shipped_at'] = $now;
				}
				break;
			case 'delivered':
				$data['delivered_at'] = $now;
				break;
			case 'returned':
				$data['returned_at'] = $now;
				if ( (float) $row->return_cost <= 0 ) {
					$data['return_cost'] = CODM_Shipping::return_cost( $row->shipping_company_id );
				}
				break;
			case 'cancelled':
				$data['cancelled_at'] = $now;
				if ( $uid ) {
					$data['agent_id'] = $uid;
				}
				break;
		}
		// Every order that leaves with a courier costs shipping.
		if ( in_array( $new, array( 'shipped', 'out_for_delivery', 'delivered', 'returned' ), true ) && (float) $row->shipping_cost <= 0 ) {
			$data['shipping_cost'] = CODM_Shipping::default_cost( $row->shipping_company_id );
		}

		self::update( $order_id, $data );
		self::history( $order_id, 'status', $note, $old, $new );

		if ( $sync_wc ) {
			$user = wp_get_current_user();
			$msg  = sprintf(
				/* translators: 1: status 2: user */
				__( 'COD Manager Pro: status changed to %1$s by %2$s.', 'cod-manager-pro' ),
				CODM_Status::label( $new ),
				$user->exists() ? $user->display_name : __( 'System', 'cod-manager-pro' )
			) . ( '' !== $note ? ' ' . $note : '' );
			CODM_Sync::set_wc_status( $order_id, CODM_Status::to_wc( $new ), $msg );
		}

		do_action( 'codm_order_status_changed', $order_id, $old, $new );
		return true;
	}

	public static function add_note( $order_id, $note, $to_wc = true ) {
		$note = trim( (string) $note );
		if ( '' === $note || ! self::get( $order_id ) ) {
			return false;
		}
		self::history( $order_id, 'note', $note );
		if ( $to_wc ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$user = wp_get_current_user();
				$order->add_order_note( ( $user->exists() ? $user->display_name . ': ' : '' ) . $note );
			}
		}
		return true;
	}

	/** Record an unanswered WhatsApp message. A "New" order becomes "Pending confirmation". */
	public static function log_no_answer( $order_id ) {
		$row = self::get( $order_id );
		if ( ! $row ) {
			return new WP_Error( 'codm_not_found', __( 'Order not found in COD Manager Pro.', 'cod-manager-pro' ), array( 'status' => 404 ) );
		}
		$attempt = (int) $row->call_attempts + 1;
		self::update( $order_id, array( 'call_attempts' => $attempt ) );
		/* translators: %d attempt number */
		$msg = sprintf( __( 'No answer (attempt %d)', 'cod-manager-pro' ), $attempt );
		self::history( $order_id, 'call', $msg );
		$order = wc_get_order( $order_id );
		if ( $order ) {
			/* translators: %s: order note */
			$order->add_order_note( sprintf( __( 'COD Manager Pro: %s', 'cod-manager-pro' ), $msg ) );
		}
		return true;
	}

	/**
	 * Edit order details. Customer fields and tracking are pushed to WooCommerce.
	 *
	 * @return true|WP_Error
	 */
	public static function update_details( $order_id, array $in ) {
		$row = self::get( $order_id );
		if ( ! $row ) {
			return new WP_Error( 'codm_not_found', __( 'Order not found in COD Manager Pro.', 'cod-manager-pro' ), array( 'status' => 404 ) );
		}
		$labels = array(
			'customer_name'       => __( 'name', 'cod-manager-pro' ),
			'phone'               => __( 'phone', 'cod-manager-pro' ),
			'city'                => __( 'city', 'cod-manager-pro' ),
			'address'             => __( 'address', 'cod-manager-pro' ),
			'shipping_company_id' => __( 'shipping company', 'cod-manager-pro' ),
			'tracking_number'     => __( 'tracking number', 'cod-manager-pro' ),
			'shipping_cost'       => __( 'shipping cost', 'cod-manager-pro' ),
			'return_cost'         => __( 'return cost', 'cod-manager-pro' ),
			'parcels'             => __( 'number of parcels', 'cod-manager-pro' ),
			'allow_open'          => __( 'opening allowed', 'cod-manager-pro' ),
		);
		// Only people who can see finances may change costs.
		if ( ! current_user_can( CODM_Roles::CAP_FINANCE ) ) {
			unset( $in['shipping_cost'], $in['return_cost'] );
		}
		$data    = array();
		$changed = array();
		foreach ( $labels as $key => $label ) {
			if ( ! array_key_exists( $key, $in ) ) {
				continue;
			}
			$raw = $in[ $key ];
			switch ( $key ) {
				case 'phone':
					$val = CODM_DB::normalize_phone( $raw );
					break;
				case 'address':
					$val = sanitize_textarea_field( (string) $raw );
					break;
				case 'shipping_company_id':
					$val = absint( $raw );
					break;
				case 'parcels':
					$val = min( 20, max( 1, absint( $raw ) ) );
					break;
				case 'allow_open':
					$val = empty( $raw ) ? 0 : 1;
					break;
				case 'shipping_cost':
				case 'return_cost':
					$val = CODM_DB::decimal( $raw );
					if ( abs( $val - (float) $row->$key ) < 0.005 ) {
						continue 2;
					}
					break;
				default:
					$val = sanitize_text_field( (string) $raw );
			}
			if ( in_array( $key, array( 'shipping_cost', 'return_cost' ), true ) || (string) $val !== (string) $row->$key ) {
				$data[ $key ] = $val;
				$changed[]    = $label;
			}
		}
		if ( ! $data ) {
			return true;
		}
		if ( isset( $data['customer_name'] ) && '' === $data['customer_name'] ) {
			return new WP_Error( 'codm_name', __( 'Enter the customer name.', 'cod-manager-pro' ), array( 'status' => 400 ) );
		}
		// A new company brings its own fees, unless the fees were also edited by hand in the same save.
		if ( isset( $data['shipping_company_id'] ) && (int) $data['shipping_company_id'] !== (int) $row->shipping_company_id ) {
			if ( (int) $row->payout_id > 0 ) {
				return new WP_Error( 'codm_settled', __( 'This order is settled in a courier payout, so its shipping company can no longer change.', 'cod-manager-pro' ), array( 'status' => 409 ) );
			}
			if ( ! isset( $data['shipping_cost'] ) ) {
				$data['shipping_cost'] = CODM_Shipping::default_cost( $data['shipping_company_id'] );
			}
			if ( ! isset( $data['return_cost'] ) && 'returned' === $row->status ) {
				$data['return_cost'] = CODM_Shipping::return_cost( $data['shipping_company_id'] );
			}
		}
		if ( isset( $data['phone'] ) || isset( $data['customer_name'] ) || isset( $data['city'] ) ) {
			$data['customer_id'] = CODM_Customers::upsert(
				isset( $data['phone'] ) ? $data['phone'] : $row->phone,
				isset( $data['customer_name'] ) ? $data['customer_name'] : $row->customer_name,
				isset( $data['city'] ) ? $data['city'] : $row->city
			);
		}
		self::update( $order_id, $data );
		/* translators: %s list of fields */
		self::history( $order_id, 'edit', sprintf( __( 'Updated %s', 'cod-manager-pro' ), implode( ', ', $changed ) ) );
		CODM_Sync::push_details( $order_id, $data );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Queries                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Build WHERE clause and args from filters.
	 *
	 * @param array $a       Filters.
	 * @param bool  $status  Include the status filter.
	 */
	private static function where( array $a, $status = true ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();

		if ( $status && ! empty( $a['status'] ) ) {
			if ( 'open' === $a['status'] ) {
				$where[] = "status IN ('new','pending')";
			} elseif ( 'in_progress' === $a['status'] ) {
				$where[] = "status IN ('confirmed','processing','ready','shipped','out_for_delivery')";
			} elseif ( 'to_ship' === $a['status'] ) {
				$where[] = "status IN ('confirmed','processing','ready')";
			} elseif ( 'with_courier' === $a['status'] ) {
				$where[] = "status IN ('shipped','out_for_delivery')";
			} elseif ( 'done' === $a['status'] ) {
				$where[] = "status IN ('delivered','cancelled','returned')";
			} elseif ( CODM_Status::exists( $a['status'] ) ) {
				$where[] = 'status = %s';
				$args[]  = $a['status'];
			}
		}
		if ( ! empty( $a['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $a['search'] ) . '%';
			$digits  = ltrim( preg_replace( '/\D+/', '', $a['search'] ), '0' );
			$clause  = '(order_number LIKE %s OR customer_name LIKE %s OR city LIKE %s OR tracking_number LIKE %s';
			$args    = array_merge( $args, array( $like, $like, $like, $like ) );
			if ( strlen( $digits ) >= 4 ) {
				$clause .= ' OR phone LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( $digits ) . '%';
			}
			$where[] = $clause . ')';
		}
		if ( ! empty( $a['city'] ) ) {
			$where[] = 'city = %s';
			$args[]  = $a['city'];
		}
		if ( isset( $a['source'] ) && '' !== $a['source'] ) {
			$where[] = 'source = %s';
			$args[]  = '__unknown' === $a['source'] ? '' : $a['source'];
		}
		if ( isset( $a['company'] ) && '' !== $a['company'] && null !== $a['company'] ) {
			$where[] = 'shipping_company_id = %d';
			$args[]  = (int) $a['company'];
		}
		if ( ! empty( $a['from'] ) && CODM_DB::valid_date( $a['from'] ) ) {
			$where[] = 'created_at >= %s';
			$args[]  = $a['from'] . ' 00:00:00';
		}
		if ( ! empty( $a['to'] ) && CODM_DB::valid_date( $a['to'] ) ) {
			$where[] = 'created_at <= %s';
			$args[]  = $a['to'] . ' 23:59:59';
		}
		if ( ! empty( $a['ids'] ) ) {
			$ids     = array_values( array_filter( array_map( 'absint', (array) $a['ids'] ) ) );
			$where[] = 'order_id IN (' . ( $ids ? CODM_DB::placeholders( $ids ) : '0' ) . ')';
			$args    = array_merge( $args, $ids );
		}
		return array( implode( ' AND ', $where ), $args );
	}

	private static function prepare( $sql, $args ) {
		global $wpdb;
		return $args ? $wpdb->prepare( $sql, $args ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * List orders with filters, pagination and per-status counts.
	 */
	public static function query( array $a = array() ) {
		global $wpdb;
		$a = wp_parse_args( $a, array(
			'status'   => '',
			'search'   => '',
			'city'     => '',
			'source'   => '',
			'company'  => '',
			'from'     => '',
			'to'       => '',
			'ids'      => array(),
			'page'     => 1,
			'per_page' => 25,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'counts'   => true,
		) );
		$t = self::table();

		list( $where, $args ) = self::where( $a );
		$orderby  = in_array( $a['orderby'], array( 'created_at', 'total', 'updated_at', 'order_id' ), true ) ? $a['orderby'] : 'created_at';
		$order    = 'ASC' === strtoupper( $a['order'] ) ? 'ASC' : 'DESC';
		$per_page = (int) $a['per_page'];
		$page     = max( 1, (int) $a['page'] );

		$total = (int) $wpdb->get_var( self::prepare( "SELECT COUNT(*) FROM $t WHERE $where", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sql   = "SELECT * FROM $t WHERE $where ORDER BY $orderby $order, id DESC";
		if ( $per_page > 0 ) {
			$sql .= ' LIMIT ' . $per_page . ' OFFSET ' . ( ( $page - 1 ) * $per_page );
		}
		$rows = $wpdb->get_results( self::prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$counts = array();
		if ( $a['counts'] ) {
			list( $cwhere, $cargs ) = self::where( $a, false );
			foreach ( $wpdb->get_results( self::prepare( "SELECT status, COUNT(*) AS n FROM $t WHERE $cwhere GROUP BY status", $cargs ) ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$counts[ $c->status ] = (int) $c->n;
			}
			$counts['all'] = array_sum( $counts );
		}

		return array(
			'rows'   => $rows,
			'total'  => $total,
			'pages'  => $per_page > 0 ? max( 1, (int) ceil( $total / $per_page ) ) : 1,
			'page'   => $page,
			'counts' => $counts,
		);
	}

	/** Distinct cities, for the filter dropdown. */
	public static function cities() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT city FROM ' . self::table() . " WHERE city <> '' GROUP BY city ORDER BY COUNT(*) DESC LIMIT 200" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function wc_edit_url( $order_id ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return '';
		}
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $order_id );
		}
		return admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' );
	}

	/**
	 * Turn DB rows into arrays for the API, with customer risk, duplicates and profit.
	 */
	public static function enrich( array $rows ) {
		$customer_ids = wp_list_pluck( $rows, 'customer_id' );
		$stats        = CODM_Customers::stats( $customer_ids );
		$customers    = CODM_Customers::get_many( $customer_ids );
		$can_finance  = current_user_can( CODM_Roles::CAP_FINANCE );
		$out          = array();

		foreach ( $rows as $r ) {
			$cid      = (int) $r->customer_id;
			$stat     = isset( $stats[ $cid ] ) ? $stats[ $cid ] : null;
			$customer = isset( $customers[ $cid ] ) ? $customers[ $cid ] : null;
			$items    = json_decode( (string) $r->items, true );
			$active   = ! in_array( $r->status, CODM_Status::closed(), true );
			$profit   = CODM_Finance::order_profit( $r );
			$wa_key   = CODM_WhatsApp::suggested( $r->status );

			$item = array(
				'id'                => (int) $r->order_id,
				'number'            => $r->order_number,
				'date'              => mysql2date( 'd M Y, H:i', $r->created_at ),
				'date_short'        => mysql2date( 'd M, H:i', $r->created_at ),
				'customer'          => array(
					'id'          => $cid,
					'name'        => $r->customer_name,
					'phone'       => $r->phone,
					'phone_display' => CODM_DB::format_phone( $r->phone ),
					'phone_ok'    => CODM_DB::phone_looks_valid( $r->phone ),
					'risk'        => CODM_Customers::risk( $stat, $customer ),
					'blacklisted' => $customer ? (bool) $customer->blacklisted : false,
					'reason'      => $customer ? $customer->blacklist_reason : '',
					'orders'      => $stat ? (int) $stat->total : 1,
					'delivered'   => $stat ? (int) $stat->delivered : 0,
					'returned'    => $stat ? (int) $stat->returned : 0,
					'cancelled'   => $stat ? (int) $stat->cancelled : 0,
				),
				'duplicate'         => $active && $stat && (int) $stat->active > 1,
				'city'              => $r->city,
				'address'           => (string) $r->address,
				'items'             => is_array( $items ) ? $items : array(),
				'items_count'       => (int) $r->items_count,
				'subtotal'          => (float) $r->subtotal,
				'delivery_fee'      => (float) $r->delivery_fee,
				'discount'          => (float) $r->discount,
				'total'             => (float) $r->total,
				'status'            => $r->status,
				'status_label'      => CODM_Status::label( $r->status ),
				'company_id'        => (int) $r->shipping_company_id,
				'company'           => CODM_Shipping::name( $r->shipping_company_id ),
				'tracking'          => $r->tracking_number,
				'parcels'           => (int) $r->parcels,
				'allow_open'        => (bool) $r->allow_open,
				'call_attempts'     => (int) $r->call_attempts,
				'delivery_attempts' => (int) $r->delivery_attempts,
				'payout_id'         => (int) $r->payout_id,
				'payment_method'    => $r->payment_method,
				'source'            => isset( $r->source ) ? (string) $r->source : '',
				'campaign'          => isset( $r->campaign ) ? (string) $r->campaign : '',
				'wa'                => CODM_WhatsApp::link( $r->phone, CODM_WhatsApp::message( $r, $wa_key ) ),
				'wc_edit'           => self::wc_edit_url( $r->order_id ),
			);
			if ( $can_finance ) {
				$item['product_cost']  = (float) $r->product_cost;
				$item['shipping_cost'] = (float) $r->shipping_cost;
				$item['return_cost']   = (float) $r->return_cost;
				$item['profit']        = $profit['value'];
				$item['profit_final']  = $profit['final'];
			}
			$out[] = $item;
		}
		return $out;
	}

	public static function enriched( $order_id ) {
		$row = self::get( $order_id );
		if ( ! $row ) {
			return null;
		}
		$list = self::enrich( array( $row ) );
		return $list[0];
	}
}
