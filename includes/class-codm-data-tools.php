<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugins screen: when "Deactivate" is clicked, ask whether to keep or delete the plugin data,
 * with a backup download (ZIP of CSV files) before anything is deleted.
 */
class CODM_Data_Tools {

	const TABLES = array( 'orders', 'order_history', 'customers', 'shipping_companies', 'expenses', 'payouts' );

	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_codm_backup_download', array( __CLASS__, 'download' ) );
		add_action( 'admin_post_codm_delete_and_deactivate', array( __CLASS__, 'delete_and_deactivate' ) );
	}

	private static function can() {
		return current_user_can( 'activate_plugins' );
	}

	/* ------------------------------------------------------------------ */
	/* Popup on the Plugins screen                                         */
	/* ------------------------------------------------------------------ */

	public static function assets( $hook ) {
		if ( 'plugins.php' !== $hook || ! self::can() ) {
			return;
		}
		wp_register_script( 'codm-deactivate', false, array(), CODM_VERSION, true );
		wp_enqueue_script( 'codm-deactivate' );
		wp_localize_script( 'codm-deactivate', 'CODMDeactivate', array(
			'plugin'   => plugin_basename( CODM_FILE ),
			'download' => wp_nonce_url( admin_url( 'admin-post.php?action=codm_backup_download' ), 'codm_backup_download' ),
			'post'     => admin_url( 'admin-post.php' ),
			'nonce'    => wp_create_nonce( 'codm_delete_and_deactivate' ),
			'counts'   => self::counts(),
			't'        => array(
				'title'       => __( 'Deactivate COD Manager Pro', 'cod-manager-pro' ),
				'intro'       => __( 'What should happen to your COD Manager Pro data?', 'cod-manager-pro' ),
				/* translators: 1: orders 2: customers 3: expenses 4: payouts */
				'summary'     => __( 'Saved now: %1$s orders, %2$s customers, %3$s expenses, %4$s courier payouts, plus your shipping companies, cost prices and settings.', 'cod-manager-pro' ),
				'keep'        => __( 'Keep my data', 'cod-manager-pro' ),
				'keep_help'   => __( 'Recommended. Everything is still here when you activate the plugin again.', 'cod-manager-pro' ),
				'delete'      => __( 'Delete all COD Manager Pro data', 'cod-manager-pro' ),
				'delete_help' => __( 'Orders follow-up, call notes, customers and blacklist, shipping companies, expenses, payouts, cost prices and settings are deleted for good. Your WooCommerce orders themselves are not deleted.', 'cod-manager-pro' ),
				'backup'      => __( 'Download a backup', 'cod-manager-pro' ),
				'backup_help' => __( 'ZIP file with your data in CSV files (opens in Excel or Google Sheets).', 'cod-manager-pro' ),
				'confirm'     => __( 'I understand that deleted data cannot be recovered.', 'cod-manager-pro' ),
				'cancel'      => __( 'Cancel', 'cod-manager-pro' ),
				'deactivate'  => __( 'Deactivate', 'cod-manager-pro' ),
				'delete_btn'  => __( 'Delete data and deactivate', 'cod-manager-pro' ),
				'downloading' => __( 'Your backup is downloading.', 'cod-manager-pro' ),
			),
		) );
		wp_add_inline_script( 'codm-deactivate', self::js() );
		wp_register_style( 'codm-deactivate', false, array(), CODM_VERSION );
		wp_enqueue_style( 'codm-deactivate' );
		wp_add_inline_style( 'codm-deactivate', self::css() );
	}

	private static function counts() {
		global $wpdb;
		$out = array();
		foreach ( array( 'orders', 'customers', 'expenses', 'payouts' ) as $t ) {
			$table     = CODM_DB::table( $t );
			$out[ $t ] = CODM_DB::table_exists( $table ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL
		}
		return $out;
	}

	private static function css() {
		return '
.codm-dm-back{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px}
.codm-dm{background:#fff;border-radius:8px;max-width:560px;width:100%;box-shadow:0 10px 40px rgba(0,0,0,.25);font-size:14px;color:#1d2327;max-height:calc(100vh - 32px);overflow:auto}
.codm-dm h2{margin:0;padding:18px 22px;border-bottom:1px solid #dcdcde;font-size:18px}
.codm-dm-body{padding:16px 22px}
.codm-dm-sum{margin:6px 0 14px;color:#50575e}
.codm-dm-opt{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border:1px solid #dcdcde;border-radius:6px;margin-bottom:10px;cursor:pointer}
.codm-dm-opt:hover{border-color:#8c8f94}
.codm-dm-opt.is-on{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1}
.codm-dm-opt.is-danger.is-on{border-color:#d63638;box-shadow:0 0 0 1px #d63638;background:#fcf0f1}
.codm-dm-opt input{margin-top:3px}
.codm-dm-opt b{display:block;margin-bottom:2px}
.codm-dm-opt span{color:#50575e;font-size:13px}
.codm-dm-backup{display:flex;gap:12px;align-items:center;flex-wrap:wrap;padding:12px 14px;background:#f6f7f7;border-radius:6px;margin:4px 0 10px}
.codm-dm-backup span{color:#50575e;font-size:13px;flex:1 1 200px}
.codm-dm-ok{color:#007017;font-size:13px}
.codm-dm-check{display:flex;gap:8px;align-items:flex-start;margin:8px 0 0;font-size:13px}
.codm-dm-foot{display:flex;justify-content:flex-end;gap:8px;padding:14px 22px;border-top:1px solid #dcdcde;background:#f6f7f7;border-radius:0 0 8px 8px}
.codm-dm-foot .button-danger{background:#d63638;border-color:#d63638;color:#fff}
.codm-dm-foot .button-danger:hover{background:#b32d2e;border-color:#b32d2e;color:#fff}
.codm-dm-foot .button-danger[disabled]{opacity:.5}
';
	}

	private static function js() {
		return <<<'JS'
( function () {
	var D = window.CODMDeactivate;
	if ( ! D ) { return; }
	function esc( s ) { return String( s ).replace( /[&<>"']/g, function ( c ) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ]; } ); }
	function fmt( s, a ) { return s.replace( /%(\d)\$s/g, function ( m, i ) { return a[ i - 1 ]; } ); }
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( 'tr[data-plugin="' + D.plugin + '"] .deactivate a' );
		if ( ! link ) { return; }
		e.preventDefault();
		open( link.href );
	} );
	function open( href ) {
		var t = D.t, c = D.counts, last = document.activeElement;
		var back = document.createElement( 'div' );
		back.className = 'codm-dm-back';
		back.innerHTML =
			'<div class="codm-dm" role="dialog" aria-modal="true" aria-labelledby="codm-dm-title">' +
			'<h2 id="codm-dm-title">' + esc( t.title ) + '</h2>' +
			'<div class="codm-dm-body">' +
				'<p><strong>' + esc( t.intro ) + '</strong></p>' +
				'<p class="codm-dm-sum">' + esc( fmt( t.summary, [ c.orders, c.customers, c.expenses, c.payouts ] ) ) + '</p>' +
				'<label class="codm-dm-opt is-on"><input type="radio" name="codm-dm-choice" value="keep" checked><div><b>' + esc( t.keep ) + '</b><span>' + esc( t.keep_help ) + '</span></div></label>' +
				'<label class="codm-dm-opt is-danger"><input type="radio" name="codm-dm-choice" value="delete"><div><b>' + esc( t.delete ) + '</b><span>' + esc( t.delete_help ) + '</span></div></label>' +
				'<div class="codm-dm-backup"><a class="button" href="' + esc( D.download ) + '" id="codm-dm-dl"><span class="dashicons dashicons-download" style="margin:4px 4px 0 -2px"></span>' + esc( t.backup ) + '</a><span>' + esc( t.backup_help ) + '</span></div>' +
				'<p class="codm-dm-ok" id="codm-dm-ok" hidden>' + esc( t.downloading ) + '</p>' +
				'<label class="codm-dm-check" id="codm-dm-check" hidden><input type="checkbox" id="codm-dm-sure"> ' + esc( t.confirm ) + '</label>' +
			'</div>' +
			'<div class="codm-dm-foot"><button type="button" class="button" id="codm-dm-cancel">' + esc( t.cancel ) + '</button>' +
			'<button type="button" class="button button-primary" id="codm-dm-go">' + esc( t.deactivate ) + '</button></div>' +
			'</div>';
		document.body.appendChild( back );
		var go = back.querySelector( '#codm-dm-go' ), sure = back.querySelector( '#codm-dm-sure' ), check = back.querySelector( '#codm-dm-check' );
		function choice() { return back.querySelector( 'input[name="codm-dm-choice"]:checked' ).value; }
		function refresh() {
			var del = choice() === 'delete';
			back.querySelectorAll( '.codm-dm-opt' ).forEach( function ( o ) { o.classList.toggle( 'is-on', o.querySelector( 'input' ).checked ); } );
			check.hidden = ! del;
			go.textContent = del ? t.delete_btn : t.deactivate;
			go.className = 'button ' + ( del ? 'button-danger' : 'button-primary' );
			go.disabled = del && ! sure.checked;
		}
		function close() { back.remove(); document.removeEventListener( 'keydown', onKey ); if ( last && last.focus ) { last.focus(); } }
		function onKey( ev ) { if ( ev.key === 'Escape' ) { close(); } }
		back.addEventListener( 'change', refresh );
		back.addEventListener( 'click', function ( ev ) { if ( ev.target === back ) { close(); } } );
		back.querySelector( '#codm-dm-cancel' ).addEventListener( 'click', close );
		back.querySelector( '#codm-dm-dl' ).addEventListener( 'click', function () { back.querySelector( '#codm-dm-ok' ).hidden = false; } );
		go.addEventListener( 'click', function () {
			if ( choice() === 'keep' ) { window.location = href; return; }
			if ( ! sure.checked ) { return; }
			go.disabled = true;
			var f = document.createElement( 'form' );
			f.method = 'post'; f.action = D.post;
			[ [ 'action', 'codm_delete_and_deactivate' ], [ '_wpnonce', D.nonce ] ].forEach( function ( p ) {
				var i = document.createElement( 'input' ); i.type = 'hidden'; i.name = p[ 0 ]; i.value = p[ 1 ]; f.appendChild( i );
			} );
			document.body.appendChild( f );
			f.submit();
		} );
		document.addEventListener( 'keydown', onKey );
		refresh();
		go.focus();
	}
}() );
JS;
	}

	/* ------------------------------------------------------------------ */
	/* Backup download                                                     */
	/* ------------------------------------------------------------------ */

	private static function csv_cell( $v ) {
		$v = (string) $v;
		return ( preg_match( '/^[=+\-@\t\r|]/', $v ) && ! is_numeric( $v ) ) ? "'" . $v : $v;
	}

	private static function csv( array $head, array $rows ) {
		$fh = fopen( 'php://temp', 'w+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, $head );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), array_values( (array) $r ) ) );
		}
		rewind( $fh );
		$out = stream_get_contents( $fh );
		fclose( $fh );
		return $out;
	}

	public static function download() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cod-manager-pro' ), 403 );
		}
		check_admin_referer( 'codm_backup_download' );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'The backup could not be created on this server (the PHP Zip extension is missing).', 'cod-manager-pro' ) );
		}
		global $wpdb;
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = wp_tempnam( 'codm-backup.zip' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'The backup could not be created.', 'cod-manager-pro' ) );
		}
		foreach ( self::TABLES as $t ) {
			$table = CODM_DB::table( $t );
			if ( ! CODM_DB::table_exists( $table ) ) {
				continue;
			}
			$cols = $wpdb->get_col( "SHOW COLUMNS FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$rows = $wpdb->get_results( "SELECT * FROM $table", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
			$zip->addFromString( $t . '.csv', self::csv( $cols, (array) $rows ) );
		}
		// Cost prices of products and variations.
		$costs = $wpdb->get_results(
			"SELECT p.ID, p.post_type, p.post_parent, p.post_title, sku.meta_value AS sku, m.meta_value AS cost
			FROM {$wpdb->postmeta} m
			INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
			LEFT JOIN {$wpdb->postmeta} sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
			WHERE m.meta_key = '_codm_cost' ORDER BY p.ID",
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL
		$zip->addFromString( 'product_costs.csv', self::csv( array( 'id', 'type', 'parent_id', 'name', 'sku', 'cost' ), (array) $costs ) );
		$zip->addFromString( 'settings.json', wp_json_encode( array(
			'plugin'     => 'COD Manager Pro',
			'version'    => CODM_VERSION,
			'db_version' => get_option( 'codm_db_version' ),
			'site'       => home_url(),
			'exported'   => current_time( 'mysql' ),
			'settings'   => get_option( 'codm_settings', array() ),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		$zip->close();

		$slug = sanitize_title( get_bloginfo( 'name' ) );
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . ( $slug ? $slug . '-' : '' ) . 'cod-manager-pro-backup-' . gmdate( 'Y-m-d' ) . '.zip' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		wp_delete_file( $file );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Delete everything, then deactivate                                  */
	/* ------------------------------------------------------------------ */

	/** Remove all plugin data (same as uninstall with "delete all data" ticked). WooCommerce orders stay. */
	public static function wipe() {
		global $wpdb;
		foreach ( self::TABLES as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . CODM_DB::table( $t ) ); // phpcs:ignore WordPress.DB
		}
		delete_option( 'codm_settings' );
		delete_option( 'codm_db_version' );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_codm_cost'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'codm_label_format'" ); // phpcs:ignore WordPress.DB
		remove_role( 'codm_agent' );
		foreach ( array( 'administrator', 'shop_manager' ) as $name ) {
			$role = get_role( $name );
			if ( $role ) {
				$role->remove_cap( 'codm_manage_orders' );
				$role->remove_cap( 'codm_view_finance' );
				$role->remove_cap( 'codm_manage_settings' );
			}
		}
	}

	public static function delete_and_deactivate() {
		if ( ! self::can() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'cod-manager-pro' ), 403 );
		}
		check_admin_referer( 'codm_delete_and_deactivate' );
		self::wipe();
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		// Runs the normal deactivation too: orders in COD statuses move to standard WooCommerce statuses.
		deactivate_plugins( plugin_basename( CODM_FILE ) );
		wp_safe_redirect( admin_url( 'plugins.php?deactivate=true' ) );
		exit;
	}
}
