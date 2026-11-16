<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shipping companies and the carrier integration registry.
 */
class CODM_Shipping {

	private static $cache = null;

	public static function table() {
		return CODM_DB::table( 'shipping_companies' );
	}

	/** @return CODM_Carrier[] */
	public static function carriers() {
		$carriers = apply_filters( 'codm_carriers', array( 'manual' => new CODM_Carrier_Manual() ) );
		return array_filter( (array) $carriers, function ( $c ) {
			return $c instanceof CODM_Carrier;
		} );
	}

	/** All companies keyed by id. */
	public static function companies( $active_only = false ) {
		global $wpdb;
		if ( null === self::$cache ) {
			self::$cache = array();
			foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' ORDER BY active DESC, name ASC' ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				self::$cache[ (int) $r->id ] = $r;
			}
		}
		if ( ! $active_only ) {
			return self::$cache;
		}
		return array_filter( self::$cache, function ( $c ) {
			return (int) $c->active === 1;
		} );
	}

	public static function get( $id ) {
		$all = self::companies();
		return isset( $all[ (int) $id ] ) ? $all[ (int) $id ] : null;
	}

	public static function name( $id ) {
		$c = self::get( $id );
		return $c ? $c->name : '';
	}

	public static function default_cost( $company_id ) {
		$c = self::get( $company_id );
		return $c && (float) $c->default_cost > 0 ? (float) $c->default_cost : (float) CODM_DB::setting( 'default_shipping_cost' );
	}

	public static function return_cost( $company_id ) {
		$c = self::get( $company_id );
		return $c && (float) $c->return_cost > 0 ? (float) $c->return_cost : (float) CODM_DB::setting( 'default_return_cost' );
	}

	public static function save( array $data, $id = 0 ) {
		global $wpdb;
		$row = array(
			'name'         => substr( sanitize_text_field( $data['name'] ), 0, 100 ),
			'phone'        => substr( sanitize_text_field( isset( $data['phone'] ) ? $data['phone'] : '' ), 0, 40 ),
			'default_cost' => CODM_DB::decimal( isset( $data['default_cost'] ) ? $data['default_cost'] : 0 ),
			'return_cost'  => CODM_DB::decimal( isset( $data['return_cost'] ) ? $data['return_cost'] : 0 ),
			'carrier'      => isset( $data['carrier'] ) && array_key_exists( $data['carrier'], self::carriers() ) ? $data['carrier'] : 'manual',
			'active'       => empty( $data['active'] ) ? 0 : 1,
		);
		self::$cache = null;
		if ( $id ) {
			return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		}
		$row['created_at'] = CODM_DB::now();
		return (bool) $wpdb->insert( self::table(), $row );
	}

	/** Companies as a simple list for JavaScript. */
	public static function for_js() {
		$out     = array();
		$finance = current_user_can( CODM_Roles::CAP_FINANCE );
		foreach ( self::companies() as $c ) {
			$row = array(
				'id'     => (int) $c->id,
				'name'   => $c->name,
				'active' => (int) $c->active === 1,
			);
			// Courier fees are finance data: only managers get them.
			if ( $finance ) {
				$row['cost']   = (float) $c->default_cost;
				$row['return'] = (float) $c->return_cost;
			}
			$out[] = $row;
		}
		return $out;
	}
}
