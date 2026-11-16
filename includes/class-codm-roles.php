<?php
defined( 'ABSPATH' ) || exit;

/**
 * Capabilities:
 *  - codm_manage_orders   : Orders screen (agents, managers, admins)
 *  - codm_view_finance    : Dashboard and Finance (managers, admins)
 *  - codm_manage_settings : Settings (admins)
 */
class CODM_Roles {

	const ROLE         = 'codm_agent';
	const CAP_ORDERS   = 'codm_manage_orders';
	const CAP_FINANCE  = 'codm_view_finance';
	const CAP_SETTINGS = 'codm_manage_settings';

	public static function add_caps() {
		add_role( self::ROLE, __( 'COD Agent', 'cod-manager-pro' ), array(
			'read'           => true,
			self::CAP_ORDERS => true,
		) );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::CAP_ORDERS );
			$admin->add_cap( self::CAP_FINANCE );
			$admin->add_cap( self::CAP_SETTINGS );
		}
		$manager = get_role( 'shop_manager' );
		if ( $manager ) {
			$manager->add_cap( self::CAP_ORDERS );
			$manager->add_cap( self::CAP_FINANCE );
		}
	}

	public static function init() {
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_agents' ) );
		add_filter( 'woocommerce_disable_admin_bar', array( __CLASS__, 'allow_agents' ) );
		add_action( 'admin_menu', array( __CLASS__, 'agent_menu' ), 999 );
		add_action( 'admin_init', array( __CLASS__, 'agent_redirect' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
	}

	public static function is_agent( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user instanceof WP_User
			&& in_array( self::ROLE, (array) $user->roles, true )
			&& ! user_can( $user, 'manage_woocommerce' );
	}

	/** WooCommerce keeps low-capability users out of wp-admin; agents need it. */
	public static function allow_agents( $value ) {
		return self::is_agent() ? false : $value;
	}

	public static function agent_menu() {
		if ( self::is_agent() ) {
			remove_menu_page( 'index.php' );
		}
	}

	public static function agent_redirect() {
		global $pagenow;
		if ( self::is_agent() && ! wp_doing_ajax() && 'index.php' === $pagenow ) {
			wp_safe_redirect( admin_url( 'admin.php?page=codm-orders' ) );
			exit;
		}
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		return self::is_agent( $user ) ? admin_url( 'admin.php?page=codm-orders' ) : $redirect_to;
	}
}
