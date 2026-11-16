<?php
/**
 * Plugin Name:          COD Manager Pro
 * Description:          Manage orders efficiently with COD Manager Pro. Simplify order management, streamline your workflow, and keep your COD store organized with a powerful, easy-to-use solution for WooCommerce.
 * Version:              1.0.0
 * Author:               Nid Academy
 * Author URI:           https://www.nidacademy.org
 * Plugin URI:           https://github.com/nidacademy/cod-manager-pro
 * Update URI:           https://github.com/nidacademy/cod-manager-pro
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least:    6.2
 * Requires PHP:         7.4
 * WC requires at least: 8.0
 * Text Domain:          cod-manager-pro
 * Domain Path:          /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'CODM_VERSION', '1.0.0' );
define( 'CODM_FILE', __FILE__ );
define( 'CODM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CODM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader: CODM_Foo_Bar -> includes/class-codm-foo-bar.php
 */
spl_autoload_register( function ( $class ) {
	if ( 0 !== strpos( $class, 'CODM_' ) ) {
		return;
	}
	$file = CODM_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
} );

// Updates from GitHub releases (Dashboard > Updates).
CODM_Updater::init();

register_activation_hook( __FILE__, array( 'CODM_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CODM_Status', 'deactivate' ) );

// Plugins screen links and the "View details" window (works even before WooCommerce is active).
if ( is_admin() ) {
	CODM_Plugin_Info::init();
	CODM_Data_Tools::init();
}

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

add_action( 'init', function () {
	load_plugin_textdomain( 'cod-manager-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );

add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'COD Manager Pro needs WooCommerce to be installed and active.', 'cod-manager-pro' ) . '</p></div>';
		} );
		return;
	}
	CODM_Plugin::init();
} );
