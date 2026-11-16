<?php
defined( 'ABSPATH' ) || exit;

/**
 * Database installation and upgrades.
 */
class CODM_Install {

	const DB_VERSION = '1.3.0';

	public static function activate() {
		self::create_tables();
		CODM_Roles::add_caps();
		add_option( 'codm_settings', CODM_DB::defaults() );
		update_option( 'codm_db_version', self::DB_VERSION );
		CODM_Status::restore();
		self::fold_statuses();
	}

	/** v1.3: the detailed statuses become the six steps (New, Confirmed, Shipped, Delivered, Cancelled, Returned). */
	private static function fold_statuses() {
		global $wpdb;
		$t = CODM_DB::table( 'orders' );
		if ( ! CODM_DB::table_exists( $t ) ) {
			return;
		}
		$wpdb->query( "UPDATE $t SET status = 'new' WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( "UPDATE $t SET status = 'confirmed' WHERE status IN ('processing','ready')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( "UPDATE $t SET status = 'shipped' WHERE status = 'out_for_delivery'" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function maybe_upgrade() {
		if ( get_option( 'codm_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		$orders    = CODM_DB::table( 'orders' );
		$history   = CODM_DB::table( 'order_history' );
		$customers = CODM_DB::table( 'customers' );
		$companies = CODM_DB::table( 'shipping_companies' );
		$expenses  = CODM_DB::table( 'expenses' );
		$payouts   = CODM_DB::table( 'payouts' );

		dbDelta( "CREATE TABLE $orders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			order_number varchar(50) NOT NULL DEFAULT '',
			customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
			customer_name varchar(190) NOT NULL DEFAULT '',
			phone varchar(40) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			address text NULL,
			items longtext NULL,
			items_count int(11) unsigned NOT NULL DEFAULT 0,
			subtotal decimal(14,2) NOT NULL DEFAULT 0,
			delivery_fee decimal(14,2) NOT NULL DEFAULT 0,
			discount decimal(14,2) NOT NULL DEFAULT 0,
			total decimal(14,2) NOT NULL DEFAULT 0,
			product_cost decimal(14,2) NOT NULL DEFAULT 0,
			shipping_cost decimal(14,2) NOT NULL DEFAULT 0,
			return_cost decimal(14,2) NOT NULL DEFAULT 0,
			status varchar(30) NOT NULL DEFAULT 'new',
			payment_method varchar(100) NOT NULL DEFAULT '',
			source varchar(60) NOT NULL DEFAULT '',
			campaign varchar(190) NOT NULL DEFAULT '',
			shipping_company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tracking_number varchar(100) NOT NULL DEFAULT '',
			parcels smallint(5) unsigned NOT NULL DEFAULT 1,
			allow_open tinyint(1) NOT NULL DEFAULT 1,
			call_attempts int(11) unsigned NOT NULL DEFAULT 0,
			delivery_attempts int(11) unsigned NOT NULL DEFAULT 0,
			payout_id bigint(20) unsigned NOT NULL DEFAULT 0,
			agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			confirmed_at datetime NULL,
			shipped_at datetime NULL,
			delivered_at datetime NULL,
			returned_at datetime NULL,
			cancelled_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY status (status),
			KEY created_at (created_at),
			KEY phone (phone),
			KEY customer_id (customer_id),
			KEY shipping_company_id (shipping_company_id),
			KEY payout_id (payout_id),
			KEY source (source)
		) $c;" );

		dbDelta( "CREATE TABLE $history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(20) NOT NULL DEFAULT '',
			from_status varchar(30) NOT NULL DEFAULT '',
			to_status varchar(30) NOT NULL DEFAULT '',
			message text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id)
		) $c;" );

		dbDelta( "CREATE TABLE $customers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			phone varchar(40) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			city varchar(100) NOT NULL DEFAULT '',
			notes text NULL,
			blacklisted tinyint(1) NOT NULL DEFAULT 0,
			blacklist_reason varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY phone (phone)
		) $c;" );

		dbDelta( "CREATE TABLE $companies (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(100) NOT NULL,
			phone varchar(40) NOT NULL DEFAULT '',
			default_cost decimal(14,2) NOT NULL DEFAULT 0,
			return_cost decimal(14,2) NOT NULL DEFAULT 0,
			carrier varchar(50) NOT NULL DEFAULT 'manual',
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;" );

		dbDelta( "CREATE TABLE $expenses (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			category varchar(30) NOT NULL DEFAULT 'other',
			amount decimal(14,2) NOT NULL DEFAULT 0,
			expense_date date NOT NULL,
			description varchar(255) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY expense_date (expense_date)
		) $c;" );

		dbDelta( "CREATE TABLE $payouts (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			shipping_company_id bigint(20) unsigned NOT NULL DEFAULT 0,
			orders_count int(11) unsigned NOT NULL DEFAULT 0,
			amount_expected decimal(14,2) NOT NULL DEFAULT 0,
			amount_received decimal(14,2) NOT NULL DEFAULT 0,
			reference varchar(100) NOT NULL DEFAULT '',
			payout_date date NOT NULL,
			notes text NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY payout_date (payout_date)
		) $c;" );
	}
}
