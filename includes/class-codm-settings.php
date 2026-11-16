<?php
defined( 'ABSPATH' ) || exit;

/**
 * Settings page handlers: general, shipping companies, customers, WhatsApp, tools.
 */
class CODM_Settings {

	public static function init() {
		add_action( 'admin_post_codm_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_codm_save_company', array( __CLASS__, 'save_company' ) );
		add_action( 'admin_post_codm_tools', array( __CLASS__, 'tools' ) );
	}

	public static function tabs() {
		return array(
			'general'   => __( 'General', 'cod-manager-pro' ),
			'shipping'  => __( 'Shipping companies', 'cod-manager-pro' ),
			'customers' => __( 'Customers & risk', 'cod-manager-pro' ),
			'whatsapp'  => __( 'WhatsApp', 'cod-manager-pro' ),
			'tools'     => __( 'Tools', 'cod-manager-pro' ),
		);
	}

	private static function guard( $action ) {
		if ( ! current_user_can( CODM_Roles::CAP_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cod-manager-pro' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $tab, $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => 'codm-settings', 'tab' => $tab ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save_settings() {
		self::guard( 'codm_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'general';
		$p   = wp_unslash( $_POST );
		// phpcs:enable
		$v = array();

		switch ( $tab ) {
			case 'general':
				$v['country_code']        = preg_replace( '/\D+/', '', sanitize_text_field( $p['country_code'] ?? '' ) );
				$v['currency_label']      = substr( sanitize_text_field( $p['currency_label'] ?? 'DH' ), 0, 10 );
				$v['store_phone']         = substr( sanitize_text_field( $p['store_phone'] ?? '' ), 0, 40 );
				$v['store_city']          = substr( sanitize_text_field( $p['store_city'] ?? '' ), 0, 60 );
				$v['store_address']       = substr( sanitize_text_field( $p['store_address'] ?? '' ), 0, 160 );
				$v['allow_open']          = empty( $p['allow_open'] ) ? 0 : 1;
				$v['fragile']             = empty( $p['fragile'] ) ? 0 : 1;
				$v['hold_cod']            = empty( $p['hold_cod'] ) ? 0 : 1;
				$v['only_cod']            = empty( $p['only_cod'] ) ? 0 : 1;
				$v['low_stock']           = absint( $p['low_stock'] ?? 5 );
				$v['delete_on_uninstall'] = empty( $p['delete_on_uninstall'] ) ? 0 : 1;
				break;
			case 'shipping':
				$v['default_company']       = absint( $p['default_company'] ?? 0 );
				break;
			case 'customers':
				$v['block_blacklisted'] = empty( $p['block_blacklisted'] ) ? 0 : 1;
				$v['risk_returns']      = max( 1, absint( $p['risk_returns'] ?? 2 ) );
				$v['dup_hours']         = min( 720, absint( $p['dup_hours'] ?? 0 ) );
				$v['ma_phone_only']     = empty( $p['ma_phone_only'] ) ? 0 : 1;
				break;
			case 'whatsapp':
				$v['wa_auto']     = empty( $p['wa_auto'] ) ? 0 : 1;
				$v['wa_provider'] = sanitize_key( $p['wa_provider'] ?? 'manual' );
				foreach ( array_keys( CODM_WhatsApp::templates() ) as $key ) {
					if ( isset( $p[ 'wa_' . $key ] ) ) {
						$v[ 'wa_' . $key ] = sanitize_textarea_field( $p[ 'wa_' . $key ] );
					}
				}
				break;
		}
		CODM_DB::save_settings( $v );
		self::back( $tab, array( 'codm_msg' => 'saved' ) );
	}

	public static function save_company() {
		self::guard( 'codm_save_company' );
		// phpcs:disable WordPress.Security.NonceVerification
		$p  = wp_unslash( $_POST );
		$id = absint( $p['id'] ?? 0 );
		// phpcs:enable
		if ( '' === trim( (string) ( $p['name'] ?? '' ) ) ) {
			self::back( 'shipping', array( 'codm_err' => 'company' ) );
		}
		CODM_Shipping::save( array(
			'name'         => $p['name'],
			'phone'        => $p['phone'] ?? '',
			'default_cost' => $p['default_cost'] ?? 0,
			'return_cost'  => $p['return_cost'] ?? 0,
			'carrier'      => sanitize_key( $p['carrier'] ?? 'manual' ),
			'active'       => ! empty( $p['active'] ),
		), $id );
		self::back( 'shipping', array( 'codm_msg' => 'saved' ) );
	}

	public static function tools() {
		self::guard( 'codm_tools' );
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'import' === $do ) {
			$days = min( 365, max( 1, absint( $_POST['days'] ?? 90 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$n    = CODM_Sync::import_recent( $days );
			self::back( 'tools', array( 'codm_msg' => 'imported', 'n' => $n ) );
		}
		if ( 'costs' === $do ) {
			global $wpdb;
			$t   = CODM_DB::table( 'orders' );
			$ids = $wpdb->get_col( "SELECT order_id FROM $t WHERE status NOT IN ('delivered','returned','cancelled')" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $ids as $id ) {
				CODM_Sync::sync_order( (int) $id );
			}
			// Closed orders keep their cost snapshot, except when it was never set.
			$closed = $wpdb->get_results( "SELECT order_id, items FROM $t WHERE status IN ('delivered','returned') AND product_cost = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $closed as $o ) {
				$items = json_decode( (string) $o->items, true );
				$cost  = 0.0;
				foreach ( (array) $items as $k => $i ) {
					$unit                = CODM_Products::get_cost( (int) $i['product_id'], (int) ( $i['variation_id'] ?? 0 ) );
					$items[ $k ]['cost'] = $unit;
					$cost               += $unit * (int) $i['qty'];
				}
				if ( $cost > 0 ) {
					CODM_Orders::update( $o->order_id, array( 'product_cost' => round( $cost, 2 ), 'items' => wp_json_encode( $items ) ) );
				}
			}
			self::back( 'tools', array( 'codm_msg' => 'costs', 'n' => count( $ids ) + count( $closed ) ) );
		}
		self::back( 'tools' );
	}
}
