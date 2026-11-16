<?php
defined( 'ABSPATH' ) || exit;

/**
 * Interface for shipping provider integrations (e.g. Moroccan carriers' APIs).
 *
 * Register a carrier with:
 *   add_filter( 'codm_carriers', function ( $carriers ) {
 *       $carriers['my_carrier'] = new My_Carrier();
 *       return $carriers;
 *   } );
 * then pick it on a shipping company in COD Manager Pro > Settings > Shipping.
 */
interface CODM_Carrier {

	/** Unique key, e.g. "manual". */
	public function id();

	/** Human-readable name. */
	public function label();

	/** True when the carrier can create shipments through an API. */
	public function supports_api();

	/**
	 * Create a shipment for an order.
	 *
	 * @param object $order COD Manager Pro order row.
	 * @param object $company Shipping company row.
	 * @return array|WP_Error Array with 'tracking_number' on success.
	 */
	public function create_shipment( $order, $company );

	/**
	 * Fetch the delivery status for a tracking number.
	 *
	 * @return string|WP_Error A COD Manager Pro status key (shipped, out_for_delivery, delivered, returned).
	 */
	public function get_status( $tracking_number, $company );
}
