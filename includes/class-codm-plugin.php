<?php
defined( 'ABSPATH' ) || exit;

/**
 * Boots every module. Each module registers its own hooks in init().
 */
class CODM_Plugin {

	public static function init() {
		CODM_Install::maybe_upgrade();

		CODM_Status::init();      // Order statuses + WooCommerce custom statuses.
		CODM_Roles::init();       // Capabilities and the COD Agent role.
		CODM_Sync::init();        // WooCommerce <-> COD Manager Pro synchronisation.
		CODM_Extra::init();       // Order rules, order source, setup checklist.
		CODM_Customers::init();   // Customer database, risk, blacklist.
		CODM_Products::init();    // Product cost price, low stock.
		CODM_WhatsApp::init();    // Message templates and provider hooks.
		CODM_Finance::init();     // Expenses, payouts, profit.
		CODM_Settings::init();    // Settings, shipping companies, tools.
		CODM_Export::init();      // CSV export and shipping labels.
		CODM_Finance_Export::init(); // Finance export: Excel and PDF report.
		CODM_REST::init();        // REST API used by the dashboard and orders screens.
		CODM_Admin::init();       // Menus, pages and assets.
		CODM_Dashboard_Widget::init(); // Status box on the WordPress Dashboard.
	}
}
