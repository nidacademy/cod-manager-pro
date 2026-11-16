<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugins screen: the links under the plugin description and the "View details" window.
 * "By Nid Academy | View details | Skool Community | Video Tutorials"
 */
class CODM_Plugin_Info {

	const SLUG    = 'cod-manager-pro';
	const SITE    = 'https://www.nidacademy.org';
	const SKOOL   = 'https://www.skool.com/nidacademy-1408/about';
	const YOUTUBE = 'https://www.youtube.com/@nid.academy?sub_confirmation=1';
	const TRUSTPILOT = 'https://www.trustpilot.com/review/nidacademy.org';

	public static function init() {
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( CODM_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_print_footer_scripts', array( __CLASS__, 'details_script' ) );
	}

	/**
	 * In the "View details" window, the rating comes from Trustpilot (Nid Academy), not WordPress.org:
	 * point the star links to Trustpilot and say so in the texts.
	 */
	public static function details_script() {
		global $pagenow;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'plugin-install.php' !== $pagenow || ! isset( $_GET['tab'], $_GET['plugin'] ) || 'plugin-information' !== $_GET['tab'] || self::SLUG !== $_GET['plugin'] ) {
			return;
		}
		$data = array(
			'url'    => self::TRUSTPILOT,
			'based'  => __( '(based on 70 Trustpilot reviews of Nid Academy)', 'cod-manager-pro' ),
			'intro'  => __( 'Reviews of Nid Academy on Trustpilot. Read them all or write your own!', 'cod-manager-pro' ),
		);
		?>
		<script>
		( function ( d ) {
			var fyi = document.querySelector( '.fyi' );
			if ( ! fyi ) { return; }
			fyi.querySelectorAll( '.counter-label a' ).forEach( function ( a ) {
				var m = /filter=(\d)/.exec( a.getAttribute( 'href' ) || '' );
				a.href = d.url + ( m ? '?stars=' + m[1] : '' );
				a.rel = 'noopener noreferrer';
			} );
			var first = fyi.querySelector( '.counter-container' );
			var p = first && first.previousElementSibling;
			if ( p && p.classList.contains( 'fyi-description' ) ) {
				p.textContent = '';
				var link = document.createElement( 'a' );
				link.href = d.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
				link.textContent = d.intro;
				p.appendChild( link );
			}
			var stars = fyi.querySelector( '.star-rating' );
			var based = stars && stars.nextElementSibling;
			if ( based && based.classList.contains( 'fyi-description' ) ) { based.textContent = d.based; }
		}( <?php echo wp_json_encode( $data ); ?> ) );
		</script>
		<?php
	}

	/** "Deactivate | Settings" under the plugin name (WordPress adds Deactivate itself). */
	public static function action_links( $links ) {
		if ( current_user_can( 'manage_options' ) || current_user_can( 'codm_manage_settings' ) ) {
			$links['codm_settings'] = '<a href="' . esc_url( admin_url( 'admin.php?page=codm-settings' ) ) . '">' . esc_html__( 'Settings', 'cod-manager-pro' ) . '</a>';
		}
		return $links;
	}

	private static function external( $url, $text ) {
		return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $text ) . '</a>';
	}

	/** Replace the default "Version | By | Visit plugin site" line with our own links. */
	public static function row_meta( $meta, $file ) {
		if ( plugin_basename( CODM_FILE ) !== $file ) {
			return $meta;
		}
		$details = add_query_arg(
			array(
				'tab'       => 'plugin-information',
				'plugin'    => self::SLUG,
				'TB_iframe' => 'true',
				'width'     => 772,
				'height'    => 600,
			),
			self_admin_url( 'plugin-install.php' )
		);
		return array(
			/* translators: %s: author name linked to the author website */
			sprintf( __( 'By %s', 'cod-manager-pro' ), self::external( self::SITE, 'Nid Academy' ) ),
			'<a href="' . esc_url( $details ) . '" class="thickbox open-plugin-details-modal" aria-label="' . esc_attr__( 'More information about COD Manager Pro', 'cod-manager-pro' ) . '" data-title="COD Manager Pro">' . esc_html__( 'View details', 'cod-manager-pro' ) . '</a>',
			self::external( self::SKOOL, __( 'Skool Community', 'cod-manager-pro' ) ),
			self::external( self::YOUTUBE, __( 'Video Tutorials', 'cod-manager-pro' ) ),
		);
	}

	/** List of features: bold title followed by a short explanation. */
	private static function features( array $items ) {
		$html = '<ul>';
		foreach ( $items as $f ) {
			$html .= '<li><strong>' . esc_html( $f[0] ) . '</strong> ' . esc_html( $f[1] ) . '</li>';
		}
		return $html . '</ul>';
	}

	private static function ul( array $items ) {
		return '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
	}

	/** Questions and answers for the FAQ tab. */
	private static function faq() {
		$qa = array(
			array( __( 'Does it work with my normal WooCommerce checkout?', 'cod-manager-pro' ), __( 'Yes. Every cash on delivery order from the classic or the block checkout appears in COD Manager Pro automatically. Orders you already have can be imported from Settings > Tools.', 'cod-manager-pro' ) ),
			array( __( 'Do I need an external app or account?', 'cod-manager-pro' ), __( 'No. Everything runs inside your WordPress admin and your data stays on your own website.', 'cod-manager-pro' ) ),
			array( __( 'Can my confirmation team work without seeing my profit?', 'cod-manager-pro' ), __( 'Yes. Give them the "COD Agent" role: they see the orders to call, confirm and ship, but never revenue, costs, profit or customer exports.', 'cod-manager-pro' ) ),
			array( __( 'How is profit calculated?', 'cod-manager-pro' ), __( 'Revenue from delivered orders, minus the cost price of the products, shipping and return fees, advertising and your other expenses. Fill in the "Cost price" field on each product for exact figures.', 'cod-manager-pro' ) ),
			array( __( 'How do I know how much my courier owes me?', 'cod-manager-pro' ), __( 'Mark orders as Delivered or Returned. The Finance page shows, for each shipping company, the cash collected, its fees and the payout you should receive. Record the payout when the money arrives.', 'cod-manager-pro' ) ),
			array( __( 'Can I print shipping labels?', 'cod-manager-pro' ), __( 'Yes: 10x15 cm thermal labels or A4 sheets with 4 labels, with a barcode, the amount to collect and your store details.', 'cod-manager-pro' ) ),
			array( __( 'Does it help against fake orders?', 'cod-manager-pro' ), __( 'Yes. Customers are recognised by phone number with their history (delivered, refused, cancelled), you can blacklist numbers, block repeat orders of the same product and accept only Moroccan phone numbers at checkout.', 'cod-manager-pro' ) ),
			array( __( 'Can I contact customers on WhatsApp?', 'cod-manager-pro' ), __( 'Yes, in one click from each order, with ready-made messages you can edit (ask to confirm, confirmed, shipped, delivered, failed delivery). Automatic sending needs a WhatsApp API provider add-on.', 'cod-manager-pro' ) ),
			array( __( 'Which languages are included?', 'cod-manager-pro' ), __( 'English, French and Arabic. The plugin follows the language of your WordPress admin.', 'cod-manager-pro' ) ),
			array( __( 'Is it compatible with the new WooCommerce order storage (HPOS)?', 'cod-manager-pro' ), __( 'Yes, it works with both the classic and the High-Performance Order Storage.', 'cod-manager-pro' ) ),
			array( __( 'What happens to my orders if I deactivate the plugin?', 'cod-manager-pro' ), __( 'They are moved to standard WooCommerce statuses so they stay visible, and put back when you activate the plugin again. No emails are sent and stock is not changed.', 'cod-manager-pro' ) ),
			array( __( 'Is my data deleted when I delete the plugin?', 'cod-manager-pro' ), __( 'No, unless you tick "Delete all data when the plugin is deleted" in Settings > General.', 'cod-manager-pro' ) ),
			array( __( 'Where can I learn how to use it?', 'cod-manager-pro' ), __( 'Watch the video tutorials on the Nid Academy YouTube channel and ask your questions in the Nid Academy community on Skool.', 'cod-manager-pro' ) ),
		);
		$html = '';
		foreach ( $qa as $q ) {
			$html .= '<h4>' . esc_html( $q[0] ) . '</h4><p>' . esc_html( $q[1] ) . '</p>';
		}
		return $html;
	}

	/** Screenshots tab: images shipped in assets/screenshots/. */
	private static function screenshots() {
		$shots = array(
			1 => __( 'Dashboard: net profit, delivery and return rates, orders per status, sales chart and results by source.', 'cod-manager-pro' ),
			2 => __( 'Orders work queue: To call, To ship, With courier and Done, with one-click Confirm, No answer and Cancel.', 'cod-manager-pro' ),
			3 => __( 'Order panel: next step, customer history, WhatsApp messages and order details.', 'cod-manager-pro' ),
			4 => __( 'Finance and courier payouts: profit and loss, COD reconciliation, Excel export and PDF report.', 'cod-manager-pro' ),
			5 => __( 'Printable finance report with your store name.', 'cod-manager-pro' ),
		);
		$html = '<ol>';
		foreach ( $shots as $n => $caption ) {
			$url   = CODM_URL . 'assets/screenshots/screenshot-' . $n . '.jpg';
			$html .= '<li><a href="' . esc_url( $url ) . '" target="_blank"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $caption ) . '"></a><p>' . esc_html( $caption ) . '</p></li>';
		}
		return $html . '</ol>';
	}

	/** Reviews tab: what learners say about Nid Academy on Trustpilot (snapshot, link to the live page). */
	private static function reviews() {
		$picks = array(
			array( 'Strong digital hub', 'Matrix', 'Jun 2025' ),
			array( 'Nidacademy is truly exceptional', 'Rayan Ali', 'Jun 2025' ),
			array( 'great experience with nid academy', 'Anoir', 'Jul 2026' ),
		);
		$html  = '<p>' . esc_html__( 'COD Manager Pro is made by Nid Academy. These are reviews of Nid Academy on Trustpilot, not of this plugin (snapshot of October 2026).', 'cod-manager-pro' ) . '</p>';
		$html .= '<p><strong>' . esc_html__( 'TrustScore 4.8 out of 5 (Excellent), 70 reviews, 97% five stars.', 'cod-manager-pro' ) . '</strong></p>';
		$html .= '<p>' . esc_html__( 'Learners mostly mention free and detailed lessons, SEO and digital marketing, e-commerce and WordPress, and quick, clear answers to their questions.', 'cod-manager-pro' ) . '</p><ul>';
		foreach ( $picks as $r ) {
			$html .= '<li>&#9733;&#9733;&#9733;&#9733;&#9733; <strong>&ldquo;' . esc_html( $r[0] ) . '&rdquo;</strong> &mdash; ' . esc_html( $r[1] ) . ', ' . esc_html( $r[2] ) . '</li>';
		}
		$html .= '</ul><p>' . self::external( self::TRUSTPILOT, __( 'Read all reviews on Trustpilot', 'cod-manager-pro' ) ) . '</p>';
		return $html;
	}

	/** Content of the "View details" window (WordPress asks plugins_api for it). */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$data = get_file_data( CODM_FILE, array( 'Version' => 'Version', 'RequiresWP' => 'Requires at least', 'RequiresPHP' => 'Requires PHP' ) );

		$description =
			'<p><strong>' . esc_html__( 'Stop losing money on fake orders, missed calls and couriers who pay late.', 'cod-manager-pro' ) . '</strong></p>' .
			'<p>' . esc_html__( 'COD Manager Pro turns your WooCommerce cash-on-delivery orders into one simple daily routine: call, ship, collect. Your team always knows the next step, and you always know your real profit.', 'cod-manager-pro' ) . '</p>' .
			'<p>' . esc_html__( 'Made for online stores that sell with cash on delivery, from solo sellers to teams of confirmation agents.', 'cod-manager-pro' ) . '</p>' .
			'<h3>' . esc_html__( 'Confirm more orders, faster', 'cod-manager-pro' ) . '</h3>' .
			self::features( array(
				array( __( 'A work queue, not a list.', 'cod-manager-pro' ), __( 'Four tabs (To call, To ship, With courier, Done) show your team exactly what to do next.', 'cod-manager-pro' ) ),
				array( __( 'One click per step.', 'cod-manager-pro' ), __( 'Confirm, No answer or Cancel; ship with the courier and tracking number; then mark it Delivered or Returned.', 'cod-manager-pro' ) ),
				array( __( 'Call or WhatsApp in one tap.', 'cod-manager-pro' ), __( 'Ready-made message templates, notes and the full order history on every order.', 'cod-manager-pro' ) ),
				array( __( 'Shipping labels in seconds.', 'cod-manager-pro' ), __( 'Print 10x15 cm thermal or A4 labels with a barcode, one by one or in bulk.', 'cod-manager-pro' ) ),
			) ) .
			'<h3>' . esc_html__( 'Know your real profit', 'cod-manager-pro' ) . '</h3>' .
			self::features( array(
				array( __( 'Profit on every order.', 'cod-manager-pro' ), __( 'Add a cost price to each product and variation. Ads, packaging and salaries are counted as expenses.', 'cod-manager-pro' ) ),
				array( __( 'Follow the courier money.', 'cod-manager-pro' ), __( 'See what each shipping company still owes you, the payouts you received and any difference.', 'cod-manager-pro' ) ),
				array( __( 'Reports you can share.', 'cod-manager-pro' ), __( 'Export to Excel or print a clean PDF report with your store name.', 'cod-manager-pro' ) ),
			) ) .
			'<h3>' . esc_html__( 'Block fake orders before they cost you', 'cod-manager-pro' ) . '</h3>' .
			self::features( array(
				array( __( 'Every customer remembered.', 'cod-manager-pro' ), __( 'Customers are recognised by phone number, with their delivered, refused and cancelled orders and a risk level.', 'cod-manager-pro' ) ),
				array( __( 'Blacklist and checkout checks.', 'cod-manager-pro' ), __( 'Refuse blacklisted numbers, repeat orders of the same product or non-Moroccan numbers. Each check is optional.', 'cod-manager-pro' ) ),
				array( __( 'Duplicate alerts.', 'cod-manager-pro' ), __( 'Spot a customer who ordered twice before you ship twice.', 'cod-manager-pro' ) ),
			) ) .
			'<h3>' . esc_html__( 'Built for teams', 'cod-manager-pro' ) . '</h3>' .
			self::features( array(
				array( __( 'COD Agent role.', 'cod-manager-pro' ), __( 'Agents confirm orders without seeing revenue, costs or customer exports.', 'cod-manager-pro' ) ),
				array( __( 'See which ads bring real sales.', 'cod-manager-pro' ), __( 'Every order shows where it came from (Facebook Ads, Instagram, TikTok Ads, Google, Direct...), with confirmed and delivered orders per source.', 'cod-manager-pro' ) ),
				array( __( 'Your day at a glance.', 'cod-manager-pro' ), __( 'A status box on the WordPress Dashboard shows what is waiting.', 'cod-manager-pro' ) ),
				array( __( 'Speaks your language.', 'cod-manager-pro' ), __( 'English, French and Arabic included.', 'cod-manager-pro' ) ),
			) ) .
			'<h3>' . esc_html__( 'Made by Nid Academy', 'cod-manager-pro' ) . '</h3>' .
			'<p>' . esc_html__( 'Learn to set it up and grow your store with our free video tutorials and our community:', 'cod-manager-pro' ) . ' ' .
			self::external( self::YOUTUBE, __( 'Video Tutorials', 'cod-manager-pro' ) ) . ' &middot; ' .
			self::external( self::SKOOL, __( 'Skool Community', 'cod-manager-pro' ) ) . '</p>';

		$installation =
			'<ol><li>' . implode( '</li><li>', array_map( 'esc_html', array(
				__( 'Plugins > Add New > Upload Plugin, choose the zip file, then Activate. WooCommerce must be active.', 'cod-manager-pro' ),
				__( 'COD Manager Pro > Settings > Shipping companies: add your couriers with their delivery and return fees, and choose a default company.', 'cod-manager-pro' ),
				__( 'Products: fill in "Cost price" on each product so profit is calculated.', 'cod-manager-pro' ),
				__( 'Settings > General: store phone and address (printed on shipping labels).', 'cod-manager-pro' ),
				__( 'Settings > Tools: import your recent WooCommerce orders.', 'cod-manager-pro' ),
				__( 'Users: give confirmation agents the "COD Agent" role.', 'cod-manager-pro' ),
			) ) ) . '</li></ol>';

		$support =
			'<p>' . esc_html__( 'Learn the plugin step by step and get help from other sellers:', 'cod-manager-pro' ) . '</p>' .
			'<ul>' .
				'<li>' . self::external( self::YOUTUBE, __( 'Video Tutorials on YouTube', 'cod-manager-pro' ) ) . '</li>' .
				'<li>' . self::external( self::SKOOL, __( 'Nid Academy community on Skool', 'cod-manager-pro' ) ) . '</li>' .
				'<li>' . self::external( self::SITE, 'www.nidacademy.org' ) . '</li>' .
			'</ul>';

		return (object) array(
			'name'          => 'COD Manager Pro',
			'slug'          => self::SLUG,
			'version'       => $data['Version'],
			'author'        => self::external( self::SITE, 'Nid Academy' ),
			'homepage'      => self::SITE,
			'requires'      => $data['RequiresWP'],
			'tested'        => '7.1.3',
			// Nid Academy on Trustpilot (4.8 / 5, 70 reviews, October 2026), see the Reviews tab.
			'rating'        => 96,
			'num_ratings'   => 70,
			'ratings'       => array( 5 => 68, 4 => 2, 3 => 0, 2 => 0, 1 => 0 ),
			'last_updated'  => '2026-10-09 12:00:00',
			'contributors'  => array(
				'nidacademy' => array(
					'display_name' => 'Nid Academy',
					'profile'      => self::SITE,
					'avatar'       => CODM_URL . 'assets/banner/icon-128x128.png',
				),
			),
			'requires_php'  => $data['RequiresPHP'],
			'external'      => true,
			'banners'       => array(
				'low'  => CODM_URL . 'assets/banner/banner-772x250.jpg',
				'high' => CODM_URL . 'assets/banner/banner-1544x500.jpg',
			),
			'sections'      => array(
				'description'  => $description,
				'installation' => $installation,
				'faq'          => self::faq(),
				'screenshots'  => self::screenshots(),
				'reviews'      => self::reviews(),
				'support'      => $support,
				'changelog'    => '<h4>1.0.0</h4><ul><li>' . esc_html__( 'First release of COD Manager Pro.', 'cod-manager-pro' ) . '</li></ul>',
			),
		);
	}
}
