<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, assets and page rendering.
 */
class CODM_Admin {

	const PAGES = array( 'codm', 'codm-orders', 'codm-finance', 'codm-settings' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( 'custom_menu_order', '__return_true' );
		add_filter( 'menu_order', array( __CLASS__, 'menu_order' ), 99 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function current_page() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $page, self::PAGES, true ) ? $page : '';
	}

	/* ------------------------------------------------------------------ */
	/* Menu                                                                */
	/* ------------------------------------------------------------------ */

	private static function open_count() {
		global $wpdb;
		$t = CODM_DB::table( 'orders' );
		if ( ! CODM_DB::table_exists( $t ) ) {
			return 0;
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status IN ('new','pending')" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function menu() {
		$open  = self::open_count();
		$badge = $open ? ' <span class="awaiting-mod"><span class="pending-count">' . (int) $open . '</span></span>' : '';
		$icon  = 'dashicons-money-alt';

		if ( current_user_can( CODM_Roles::CAP_FINANCE ) ) {
			$parent = 'codm';
			add_menu_page( __( 'COD Manager Pro', 'cod-manager-pro' ), __( 'COD Manager Pro', 'cod-manager-pro' ) . $badge, CODM_Roles::CAP_FINANCE, $parent, array( __CLASS__, 'page_dashboard' ), $icon, '55.4' );
			add_submenu_page( $parent, __( 'Dashboard', 'cod-manager-pro' ), __( 'Dashboard', 'cod-manager-pro' ), CODM_Roles::CAP_FINANCE, 'codm', array( __CLASS__, 'page_dashboard' ) );
		} else {
			$parent = 'codm-orders';
			add_menu_page( __( 'COD Manager Pro', 'cod-manager-pro' ), __( 'COD Manager Pro', 'cod-manager-pro' ) . $badge, CODM_Roles::CAP_ORDERS, $parent, array( __CLASS__, 'page_orders' ), $icon, '55.4' );
		}
		add_submenu_page( $parent, __( 'Orders', 'cod-manager-pro' ), __( 'Orders', 'cod-manager-pro' ), CODM_Roles::CAP_ORDERS, 'codm-orders', array( __CLASS__, 'page_orders' ) );
		add_submenu_page( $parent, __( 'Finance & COD payouts', 'cod-manager-pro' ), __( 'Finance & payouts', 'cod-manager-pro' ), CODM_Roles::CAP_FINANCE, 'codm-finance', array( __CLASS__, 'page_finance' ) );
		add_submenu_page( $parent, __( 'Settings', 'cod-manager-pro' ), __( 'Settings', 'cod-manager-pro' ), CODM_Roles::CAP_SETTINGS, 'codm-settings', array( __CLASS__, 'page_settings' ) );
	}

	/** Place COD Manager Pro directly above WooCommerce. */
	public static function menu_order( $order ) {
		if ( ! is_array( $order ) ) {
			return $order;
		}
		$mine = false;
		foreach ( array( 'codm', 'codm-orders' ) as $slug ) {
			$i = array_search( $slug, $order, true );
			if ( false !== $i ) {
				$mine = $slug;
				unset( $order[ $i ] );
				break;
			}
		}
		if ( ! $mine ) {
			return $order;
		}
		$order  = array_values( $order );
		$target = array_search( 'separator-woocommerce', $order, true );
		if ( false === $target ) {
			$target = array_search( 'woocommerce', $order, true );
		}
		if ( false === $target ) {
			$order[] = $mine;
		} else {
			array_splice( $order, $target, 0, array( $mine ) );
		}
		return $order;
	}

	public static function body_class( $classes ) {
		return self::current_page() ? $classes . ' codm-screen' : $classes;
	}

	/* ------------------------------------------------------------------ */
	/* Assets                                                              */
	/* ------------------------------------------------------------------ */

	public static function assets() {
		$page = self::current_page();
		if ( ! $page ) {
			return;
		}
		$v = CODM_VERSION . '.' . (int) @filemtime( CODM_DIR . 'assets/css/admin.css' );
		wp_enqueue_style( 'codm-admin', CODM_URL . 'assets/css/admin.css', array(), $v );
		wp_enqueue_script( 'codm-common', CODM_URL . 'assets/js/common.js', array( 'wp-i18n' ), $v, true );

		$statuses = array();
		foreach ( CODM_Status::flow() as $key => $label ) {
			$statuses[] = array( 'key' => $key, 'label' => $label );
		}
		wp_localize_script( 'codm-common', 'CODM', array(
			'rest'      => esc_url_raw( rest_url( CODM_REST::NS . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'currency'  => CODM_DB::setting( 'currency_label' ),
			'statuses'  => $statuses,
			'status_labels' => CODM_Status::all(),
			'default_company' => (int) CODM_DB::setting( 'default_company' ),
			'companies' => CODM_Shipping::for_js(),
			'cities'    => 'codm-orders' === $page ? CODM_Orders::cities() : array(),
			'sources'   => 'codm-orders' === $page ? CODM_Extra::sources() : array(),
			'can'       => array(
				'finance'  => current_user_can( CODM_Roles::CAP_FINANCE ),
				'settings' => current_user_can( CODM_Roles::CAP_SETTINGS ),
			),
			'urls'      => array(
				'orders'   => admin_url( 'admin.php?page=codm-orders' ),
				'finance'  => admin_url( 'admin.php?page=codm-finance' ),
				'settings' => admin_url( 'admin.php?page=codm-settings' ),
				'export'   => add_query_arg( array( 'action' => 'codm_export', '_wpnonce' => wp_create_nonce( 'codm_export' ) ), admin_url( 'admin-post.php' ) ),
				'labels'   => add_query_arg( array( 'action' => 'codm_labels', '_wpnonce' => wp_create_nonce( 'codm_labels' ) ), admin_url( 'admin-post.php' ) ),
			),
		) );

		if ( 'codm' === $page ) {
			wp_enqueue_script( 'codm-charts', CODM_URL . 'assets/js/charts.js', array( 'wp-i18n' ), $v, true );
			wp_enqueue_script( 'codm-dashboard', CODM_URL . 'assets/js/dashboard.js', array( 'codm-common', 'codm-charts' ), $v, true );
		} elseif ( 'codm-orders' === $page ) {
			wp_enqueue_script( 'codm-orders', CODM_URL . 'assets/js/orders.js', array( 'codm-common' ), $v, true );
		} elseif ( 'codm-finance' === $page ) {
			wp_enqueue_script( 'codm-finance', CODM_URL . 'assets/js/finance.js', array( 'codm-common' ), $v, true );
		}

		// Translations for the screens (languages/cod-manager-pro-{locale}-{md5}.json).
		foreach ( array( 'codm-common', 'codm-charts', 'codm-dashboard', 'codm-orders', 'codm-finance' ) as $codm_handle ) {
			if ( wp_script_is( $codm_handle, 'enqueued' ) ) {
				wp_set_script_translations( $codm_handle, 'cod-manager-pro', CODM_DIR . 'languages' );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Notices                                                             */
	/* ------------------------------------------------------------------ */

	public static function notices() {
		if ( ! self::current_page() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$msg = isset( $_GET['codm_msg'] ) ? sanitize_key( wp_unslash( $_GET['codm_msg'] ) ) : '';
		$err = isset( $_GET['codm_err'] ) ? sanitize_key( wp_unslash( $_GET['codm_err'] ) ) : '';
		$n   = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		// phpcs:enable
		$messages = array(
			'saved'           => __( 'Settings saved.', 'cod-manager-pro' ),
			'expense_added'   => __( 'Expense added.', 'cod-manager-pro' ),
			'expense_deleted' => __( 'Expense deleted.', 'cod-manager-pro' ),
			'payout_recorded' => __( 'Payout recorded. The included orders are now marked as settled.', 'cod-manager-pro' ),
			/* translators: %d count */
			'imported'        => sprintf( _n( '%d order imported from WooCommerce.', '%d orders imported from WooCommerce.', $n, 'cod-manager-pro' ), $n ),
			/* translators: %d count */
			'costs'           => sprintf( _n( 'Costs recalculated on %d order.', 'Costs recalculated on %d orders.', $n, 'cod-manager-pro' ), $n ),
		);
		$errors   = array(
			'expense' => __( 'Enter an amount above 0 and a valid date to add an expense.', 'cod-manager-pro' ),
			'payout'  => __( 'Select at least one unsettled order and a valid date to record a payout.', 'cod-manager-pro' ),
			'company' => __( 'Enter a name for the shipping company.', 'cod-manager-pro' ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $msg ] ) . '</p></div>';
		}
		if ( isset( $errors[ $err ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $err ] ) . '</p></div>';
		}
	}

	/* ------------------------------------------------------------------ */
	/* Pages                                                               */
	/* ------------------------------------------------------------------ */

	/** App header shared by every page. */
	public static function header( $current, $title ) {
		$links = array(
			'codm'          => array( __( 'Dashboard', 'cod-manager-pro' ), CODM_Roles::CAP_FINANCE ),
			'codm-orders'   => array( __( 'Orders', 'cod-manager-pro' ), CODM_Roles::CAP_ORDERS ),
			'codm-finance'  => array( __( 'Finance & payouts', 'cod-manager-pro' ), CODM_Roles::CAP_FINANCE ),
			'codm-settings' => array( __( 'Settings', 'cod-manager-pro' ), CODM_Roles::CAP_SETTINGS ),
		);
		?>
		<div class="codm-top">
			<div class="codm-brand">
				<span class="codm-mark" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 2l2.6 4.5L19.8 5l-1.5 5.2L22 12l-3.7 1.8 1.5 5.2-5.2-1.5L12 22l-2.6-4.5L4.2 19l1.5-5.2L2 12l3.7-1.8L4.2 5l5.2 1.5z"/></svg>
				</span>
				<span><?php esc_html_e( 'COD Manager Pro', 'cod-manager-pro' ); ?></span>
			</div>
			<nav class="codm-nav" aria-label="<?php esc_attr_e( 'COD Manager Pro sections', 'cod-manager-pro' ); ?>">
				<?php foreach ( $links as $slug => $link ) : ?>
					<?php if ( current_user_can( $link[1] ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo $slug === $current ? 'is-active' : ''; ?>" <?php echo $slug === $current ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $link[0] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
		</div>
		<h1 class="codm-title"><?php echo esc_html( $title ); ?></h1>
		<hr class="wp-header-end">
		<?php
	}

	private static function guard( $cap ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'cod-manager-pro' ) );
		}
	}

	public static function page_dashboard() {
		self::guard( CODM_Roles::CAP_FINANCE );
		include CODM_DIR . 'includes/views/dashboard.php';
	}

	public static function page_orders() {
		self::guard( CODM_Roles::CAP_ORDERS );
		include CODM_DIR . 'includes/views/orders.php';
	}

	public static function page_finance() {
		self::guard( CODM_Roles::CAP_FINANCE );
		include CODM_DIR . 'includes/views/finance.php';
	}

	public static function page_settings() {
		self::guard( CODM_Roles::CAP_SETTINGS );
		include CODM_DIR . 'includes/views/settings.php';
	}
}
