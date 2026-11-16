<?php
defined( 'ABSPATH' ) || exit;

/**
 * Interface for automatic WhatsApp sending (WhatsApp Cloud API, a BSP, etc.).
 *
 *   add_filter( 'codm_whatsapp_providers', function ( $providers ) {
 *       $providers['cloud_api'] = new My_WhatsApp_Provider();
 *       return $providers;
 *   } );
 *
 * Then choose it in COD Manager Pro > Settings > WhatsApp and enable automatic messages.
 */
interface CODM_WhatsApp_Provider {

	public function id();

	public function label();

	/**
	 * @param string $phone   International digits, e.g. 212612345678.
	 * @param string $message Rendered message.
	 * @param array  $context order_id, template key.
	 * @return true|WP_Error
	 */
	public function send( $phone, $message, array $context = array() );
}
