<?php
/**
 * Removes COD Manager Pro data only when "Delete all data on uninstall" is enabled in Settings.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$codm_settings = get_option( 'codm_settings', array() );
if ( empty( $codm_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'orders', 'order_history', 'customers', 'shipping_companies', 'expenses', 'payouts' ) as $codm_table ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'codm_' . $codm_table ); // phpcs:ignore WordPress.DB
}
delete_option( 'codm_settings' );
delete_option( 'codm_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_codm_cost'" ); // phpcs:ignore WordPress.DB

remove_role( 'codm_agent' );
foreach ( array( 'administrator', 'shop_manager' ) as $codm_role_name ) {
	$codm_role = get_role( $codm_role_name );
	if ( $codm_role ) {
		$codm_role->remove_cap( 'codm_manage_orders' );
		$codm_role->remove_cap( 'codm_view_finance' );
		$codm_role->remove_cap( 'codm_manage_settings' );
	}
}
