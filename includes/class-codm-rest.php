<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST API (namespace cod-manager/v1). Authenticated with the standard WordPress cookie + wp_rest nonce.
 */
class CODM_REST {

	const NS = 'cod-manager/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function can_orders() {
		return current_user_can( CODM_Roles::CAP_ORDERS );
	}

	public static function can_finance() {
		return current_user_can( CODM_Roles::CAP_FINANCE );
	}

	public static function can_settings() {
		return current_user_can( CODM_Roles::CAP_SETTINGS );
	}

	public static function routes() {
		$id = '(?P<id>\d+)';

		register_rest_route( self::NS, '/dashboard', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'dashboard' ),
			'permission_callback' => array( __CLASS__, 'can_finance' ),
		) );
		register_rest_route( self::NS, '/orders', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_orders' ),
			'permission_callback' => array( __CLASS__, 'can_orders' ),
		) );
		register_rest_route( self::NS, '/orders/bulk', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'bulk_status' ),
			'permission_callback' => array( __CLASS__, 'can_orders' ),
		) );
		register_rest_route( self::NS, '/orders/' . $id, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_order' ),
				'permission_callback' => array( __CLASS__, 'can_orders' ),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'edit_order' ),
				'permission_callback' => array( __CLASS__, 'can_orders' ),
			),
		) );
		register_rest_route( self::NS, '/orders/' . $id . '/status', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'set_status' ),
			'permission_callback' => array( __CLASS__, 'can_orders' ),
		) );
		register_rest_route( self::NS, '/orders/' . $id . '/notes', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'add_note' ),
			'permission_callback' => array( __CLASS__, 'can_orders' ),
		) );
		register_rest_route( self::NS, '/orders/' . $id . '/no-answer', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'no_answer' ),
			'permission_callback' => array( __CLASS__, 'can_orders' ),
		) );
		register_rest_route( self::NS, '/customers/' . $id . '/blacklist', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'blacklist' ),
			'permission_callback' => array( __CLASS__, 'can_orders' ),
		) );
	}

	private static function str( WP_REST_Request $r, $key ) {
		$v = $r->get_param( $key );
		return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
	}

	public static function dashboard( WP_REST_Request $r ) {
		list( $from, $to ) = CODM_DB::date_range( self::str( $r, 'from' ), self::str( $r, 'to' ) );
		$group             = in_array( $r->get_param( 'group' ), array( 'day', 'week', 'month' ), true ) ? $r->get_param( 'group' ) : 'day';
		$recent            = CODM_Orders::query( array( 'per_page' => 8, 'counts' => false ) );
		return rest_ensure_response( array(
			'summary'   => CODM_Finance::summary( $from, $to ),
			'series'    => CODM_Finance::series( $from, $to, $group ),
			'recent'    => CODM_Orders::enrich( $recent['rows'] ),
			'low_stock' => CODM_Products::low_stock(),
			'payouts'   => CODM_Finance::pending_payouts(),
			'sources'   => CODM_Extra::sources_summary( $from, $to ),
		) );
	}

	public static function list_orders( WP_REST_Request $r ) {
		$result = CODM_Orders::query( array(
			'status'   => sanitize_key( self::str( $r, 'status' ) ),
			'search'   => self::str( $r, 'search' ),
			'city'     => self::str( $r, 'city' ),
			'source'   => self::str( $r, 'source' ),
			'company'  => self::str( $r, 'company' ),
			'from'     => self::str( $r, 'from' ),
			'to'       => self::str( $r, 'to' ),
			'page'     => max( 1, absint( $r->get_param( 'page' ) ) ),
			'per_page' => min( 100, max( 10, absint( $r->get_param( 'per_page' ) ? $r->get_param( 'per_page' ) : 25 ) ) ),
			'orderby'  => sanitize_key( self::str( $r, 'orderby' ) ),
			'order'    => self::str( $r, 'order' ),
		) );
		$result['rows'] = CODM_Orders::enrich( $result['rows'] );
		return rest_ensure_response( $result );
	}

	private static function order_response( $order_id ) {
		$order = CODM_Orders::enriched( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'codm_not_found', __( 'Order not found in COD Manager Pro.', 'cod-manager-pro' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $order );
	}

	public static function get_order( WP_REST_Request $r ) {
		$id    = (int) $r['id'];
		$order = CODM_Orders::enriched( $id );
		if ( ! $order ) {
			return new WP_Error( 'codm_not_found', __( 'Order not found in COD Manager Pro.', 'cod-manager-pro' ), array( 'status' => 404 ) );
		}
		$row       = CODM_Orders::get( $id );
		$templates = array();
		foreach ( CODM_WhatsApp::templates() as $key => $label ) {
			$templates[] = array(
				'key'   => $key,
				'label' => $label,
				'url'   => CODM_WhatsApp::link( $row->phone, CODM_WhatsApp::message( $row, $key ) ),
			);
		}
		$order['history']   = CODM_Orders::get_history( $id );
		$order['templates'] = $templates;
		return rest_ensure_response( $order );
	}

	public static function edit_order( WP_REST_Request $r ) {
		$params = $r->get_json_params();
		$result = CODM_Orders::update_details( (int) $r['id'], is_array( $params ) ? $params : array() );
		return is_wp_error( $result ) ? $result : self::order_response( (int) $r['id'] );
	}

	public static function set_status( WP_REST_Request $r ) {
		$result = CODM_Orders::change_status( (int) $r['id'], sanitize_key( self::str( $r, 'status' ) ), sanitize_textarea_field( (string) $r->get_param( 'note' ) ) );
		return is_wp_error( $result ) ? $result : self::order_response( (int) $r['id'] );
	}

	public static function add_note( WP_REST_Request $r ) {
		$note = sanitize_textarea_field( (string) $r->get_param( 'note' ) );
		if ( '' === trim( $note ) ) {
			return new WP_Error( 'codm_empty_note', __( 'Write a note before saving.', 'cod-manager-pro' ), array( 'status' => 400 ) );
		}
		CODM_Orders::add_note( (int) $r['id'], $note );
		return self::order_response( (int) $r['id'] );
	}

	public static function no_answer( WP_REST_Request $r ) {
		$result = CODM_Orders::log_no_answer( (int) $r['id'] );
		return is_wp_error( $result ) ? $result : self::order_response( (int) $r['id'] );
	}

	public static function bulk_status( WP_REST_Request $r ) {
		$ids    = array_slice( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ) ), 0, 200 );
		$status = sanitize_key( self::str( $r, 'status' ) );
		if ( ! $ids || ! CODM_Status::exists( $status ) ) {
			return new WP_Error( 'codm_bulk', __( 'Select orders and a status.', 'cod-manager-pro' ), array( 'status' => 400 ) );
		}
		// Closing many orders at once moves money figures: managers only.
		if ( in_array( $status, array( 'delivered', 'returned' ), true ) && ! current_user_can( CODM_Roles::CAP_FINANCE ) ) {
			return new WP_Error( 'codm_bulk_cap', __( 'Only a manager can mark several orders as delivered or returned at once.', 'cod-manager-pro' ), array( 'status' => 403 ) );
		}
		$done   = 0;
		$errors = array();
		foreach ( $ids as $id ) {
			$res = CODM_Orders::change_status( $id, $status, __( 'Bulk update', 'cod-manager-pro' ) );
			if ( is_wp_error( $res ) ) {
				$errors[] = '#' . $id . ': ' . $res->get_error_message();
			} else {
				$done++;
			}
		}
		return rest_ensure_response( array( 'updated' => $done, 'errors' => $errors ) );
	}

	public static function blacklist( WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! CODM_Customers::get( $id ) ) {
			return new WP_Error( 'codm_not_found', __( 'Customer not found.', 'cod-manager-pro' ), array( 'status' => 404 ) );
		}
		$flag   = (bool) $r->get_param( 'blacklisted' );
		$reason = sanitize_text_field( (string) $r->get_param( 'reason' ) );
		CODM_Customers::set_blacklist( $id, $flag, $reason );
		$order_id = absint( $r->get_param( 'order_id' ) );
		if ( $order_id ) {
			CODM_Orders::history(
				$order_id,
				'customer',
				$flag
					/* translators: %s reason */
					? sprintf( __( 'Customer blacklisted. %s', 'cod-manager-pro' ), $reason )
					: __( 'Customer removed from blacklist', 'cod-manager-pro' )
			);
			return self::order_response( $order_id );
		}
		return rest_ensure_response( array( 'blacklisted' => $flag ) );
	}

}
