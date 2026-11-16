<?php
defined( 'ABSPATH' ) || exit;

/**
 * Default carrier: statuses and tracking numbers are entered by hand.
 */
class CODM_Carrier_Manual implements CODM_Carrier {

	public function id() {
		return 'manual';
	}

	public function label() {
		return __( 'Manual (no API)', 'cod-manager-pro' );
	}

	public function supports_api() {
		return false;
	}

	public function create_shipment( $order, $company ) {
		return new WP_Error( 'codm_manual_carrier', __( 'This shipping company has no API connection. Enter the tracking number by hand.', 'cod-manager-pro' ) );
	}

	public function get_status( $tracking_number, $company ) {
		return new WP_Error( 'codm_manual_carrier', __( 'This shipping company has no API connection.', 'cod-manager-pro' ) );
	}
}
