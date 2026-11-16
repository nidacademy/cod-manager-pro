<?php
defined( 'ABSPATH' ) || exit;

/**
 * Printable shipping labels: thermal 100x150 mm, or A4 with 4 labels per page.
 */
class CODM_Labels {

	const MAX_ITEMS = 6;

	public static function formats() {
		return array(
			'thermal' => __( 'Thermal 10×15 cm', 'cod-manager-pro' ),
			'a4'      => __( 'A4 · 4 per page', 'cod-manager-pro' ),
		);
	}

	private static function is_arabic( $text ) {
		return (bool) preg_match( '/\p{Arabic}/u', (string) $text );
	}

	private static function logo_url() {
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $id ) {
			$id = (int) get_option( 'site_icon' );
		}
		return $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
	}

	/**
	 * @param object[] $rows   COD Manager Pro order rows.
	 * @param string   $format thermal|a4
	 * @param string   $base   URL of this page without the format argument (for the toolbar).
	 */
	public static function render( array $rows, $format, $base ) {
		$format = array_key_exists( $format, self::formats() ) ? $format : 'thermal';
		$store  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$phone  = CODM_DB::format_phone( CODM_DB::normalize_phone( CODM_DB::setting( 'store_phone' ) ) );
		$logo   = self::logo_url();
		$css    = (string) file_get_contents( CODM_DIR . 'assets/css/labels.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$page   = 'a4' === $format ? 'A4' : '100mm 150mm';
		$count  = 0;
		foreach ( $rows as $row ) {
			$count += max( 1, (int) $row->parcels );
		}

		nocache_headers();
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( sprintf( /* translators: %d labels */ _n( '%d shipping label', '%d shipping labels', $count, 'cod-manager-pro' ), $count ) ); ?></title>
<style>
@page { size: <?php echo esc_html( $page ); ?>; margin: 0; }
<?php echo $css; // phpcs:ignore WordPress.Security.EscapeOutput -- static file shipped with the plugin. ?>
</style>
</head>
<body>
<div class="toolbar">
	<strong><?php echo esc_html( sprintf( /* translators: %d labels */ _n( '%d label', '%d labels', $count, 'cod-manager-pro' ), $count ) ); ?></strong>
	<?php foreach ( self::formats() as $key => $label ) : ?>
		<a class="<?php echo $key === $format ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'format', $key, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
	<?php endforeach; ?>
	<button type="button" class="primary" onclick="window.print()"><?php esc_html_e( 'Print', 'cod-manager-pro' ); ?></button>
</div>
<main class="sheet sheet--<?php echo esc_attr( $format ); ?>">
		<?php
		foreach ( $rows as $r ) {
			self::label( $r, $store, $phone, $logo );
		}
		?>
</main>
<script>
( document.fonts ? document.fonts.ready : Promise.resolve() ).then( function () { setTimeout( function () { window.print(); }, 250 ); } );
</script>
</body>
</html>
		<?php
		exit;
	}

	/** Total weight in kg from WooCommerce product weights, or 0 when products have no weight. */
	private static function weight_kg( $wc_order ) {
		if ( ! $wc_order || ! function_exists( 'wc_get_weight' ) ) {
			return 0.0;
		}
		$total = 0.0;
		foreach ( $wc_order->get_items() as $item ) {
			$product = is_callable( array( $item, 'get_product' ) ) ? $item->get_product() : null;
			if ( $product && $product->get_weight() ) {
				$total += (float) wc_get_weight( (float) $product->get_weight(), 'kg' ) * (int) $item->get_quantity();
			}
		}
		return round( $total, 2 );
	}

	private static function label( $r, $store, $store_phone, $logo ) {
		$items    = json_decode( (string) $r->items, true );
		$items    = is_array( $items ) ? $items : array();
		$shown    = array_slice( $items, 0, self::MAX_ITEMS );
		$hidden   = count( $items ) - count( $shown );
		$company  = CODM_Shipping::name( $r->shipping_company_id );
		$wc_order = wc_get_order( $r->order_id );
		$is_cod   = ! $wc_order || 'cod' === $wc_order->get_payment_method();
		$note     = $wc_order ? trim( (string) $wc_order->get_customer_note() ) : '';
		$weight   = self::weight_kg( $wc_order );
		$currency = (string) CODM_DB::setting( 'currency_label' );
		$amount   = $is_cod ? number_format( (float) $r->total, 2, ',', ' ' ) : '0,00';
		$parcels  = max( 1, (int) $r->parcels );
		$open     = (bool) $r->allow_open;
		$fragile  = (bool) CODM_DB::setting( 'fragile' );
		$s_city   = (string) CODM_DB::setting( 'store_city' );
		$s_addr   = (string) CODM_DB::setting( 'store_address' );
		$units    = (int) $r->items_count;
		if ( function_exists( 'mb_strimwidth' ) && $note ) {
			$note = mb_strimwidth( $note, 0, 160, '…' );
		}

		for ( $n = 1; $n <= $parcels; $n++ ) :
			?>
	<article class="lbl">
		<header class="lbl-from">
			<div class="lbl-from-main">
				<?php if ( $logo ) : ?>
					<img src="<?php echo esc_url( $logo ); ?>" alt="">
				<?php endif; ?>
				<div class="lbl-from-text">
					<span class="lbl-k"><?php esc_html_e( 'Expéditeur', 'cod-manager-pro' ); ?> <span lang="ar" dir="rtl">المرسل</span></span>
					<strong><?php echo esc_html( $store ); ?></strong>
					<span><?php echo esc_html( implode( ' · ', array_filter( array( $store_phone, $s_city ) ) ) ); ?></span>
					<?php if ( $s_addr ) : ?>
						<span class="lbl-from-addr"><?php echo esc_html( $s_addr ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $company ) : ?>
				<div class="lbl-courier"><?php echo esc_html( $company ); ?></div>
			<?php endif; ?>
		</header>

		<section class="lbl-to">
			<div class="lbl-k lbl-k--split"><span><?php esc_html_e( 'Destinataire', 'cod-manager-pro' ); ?></span><span lang="ar" dir="rtl">المرسل إليه</span></div>
			<div class="lbl-city" dir="auto"><?php echo esc_html( $r->city ? $r->city : '—' ); ?></div>
			<?php if ( $r->address ) : ?>
				<div class="lbl-addr" dir="auto"><?php echo esc_html( (string) $r->address ); ?></div>
			<?php endif; ?>
			<div class="lbl-person">
				<span class="lbl-name" dir="auto"><?php echo esc_html( $r->customer_name ); ?></span>
				<span class="lbl-phone" dir="ltr"><?php echo esc_html( CODM_DB::format_phone( $r->phone ) ); ?></span>
			</div>
		</section>

		<section class="lbl-cod<?php echo $is_cod ? '' : ' lbl-cod--paid'; ?>">
			<div class="lbl-k lbl-k--split">
				<span><?php echo $is_cod ? esc_html__( 'Montant à encaisser (CRBT)', 'cod-manager-pro' ) : esc_html__( 'Déjà payé · rien à encaisser', 'cod-manager-pro' ); ?></span>
				<span lang="ar" dir="rtl"><?php echo $is_cod ? 'المبلغ المطلوب' : 'مدفوع مسبقا'; ?></span>
			</div>
			<div class="lbl-amount" dir="ltr"><?php echo esc_html( $amount ); ?><small><?php echo esc_html( $currency ); ?></small></div>
		</section>

		<section class="lbl-facts">
			<div class="lbl-fact<?php echo $open ? '' : ' is-alert'; ?>">
				<span class="lbl-k"><?php esc_html_e( 'Ouverture', 'cod-manager-pro' ); ?> <span lang="ar" dir="rtl">فتح الطرد</span></span>
				<b><?php echo $open ? esc_html__( 'Autorisée', 'cod-manager-pro' ) : esc_html__( 'Interdite', 'cod-manager-pro' ); ?></b>
			</div>
			<div class="lbl-fact">
				<span class="lbl-k"><?php esc_html_e( 'Colis', 'cod-manager-pro' ); ?> <span lang="ar" dir="rtl">الطرود</span></span>
				<b><?php echo (int) $n . ' / ' . (int) $parcels; ?></b>
			</div>
			<div class="lbl-fact">
				<span class="lbl-k"><?php esc_html_e( 'Poids', 'cod-manager-pro' ); ?> <span lang="ar" dir="rtl">الوزن</span></span>
				<b><?php echo $weight > 0 ? esc_html( sprintf( /* translators: %s: parcel weight in kilograms */ __( '%s kg', 'cod-manager-pro' ), str_replace( '.', ',', (string) $weight ) ) ) : '—'; ?></b>
			</div>
			<?php if ( $fragile ) : ?>
				<div class="lbl-fact is-alert">
					<span class="lbl-k"><?php esc_html_e( 'Attention', 'cod-manager-pro' ); ?> <span lang="ar" dir="rtl">انتباه</span></span>
					<b><?php esc_html_e( 'Fragile', 'cod-manager-pro' ); ?></b>
				</div>
			<?php endif; ?>
		</section>

		<section class="lbl-items">
			<div class="lbl-k lbl-k--split"><span><?php echo esc_html( sprintf( /* translators: %d: number of items in the parcel */ _n( 'Contenu · %d article', 'Contenu · %d articles', $units, 'cod-manager-pro' ), $units ) ); ?></span><span lang="ar" dir="rtl">المحتوى</span></div>
			<ul>
				<?php foreach ( $shown as $i ) : ?>
					<li<?php echo self::is_arabic( $i['name'] ) ? ' dir="rtl"' : ''; ?>><b><?php echo (int) $i['qty']; ?></b><span><?php echo esc_html( $i['name'] ); ?></span></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $hidden > 0 ) : ?>
				<div class="lbl-more"><?php echo esc_html( sprintf( /* translators: %d: number of products not listed on the label */ _n( '+ %d autre produit', '+ %d autres produits', $hidden, 'cod-manager-pro' ), $hidden ) ); ?></div>
			<?php endif; ?>
			<?php if ( $note ) : ?>
				<div class="lbl-note" dir="auto"><b><?php esc_html_e( 'Note', 'cod-manager-pro' ); ?></b> <?php echo esc_html( $note ); ?></div>
			<?php endif; ?>
		</section>

		<footer class="lbl-foot<?php echo $r->tracking_number ? ' lbl-foot--tracked' : ''; ?>">
			<div class="lbl-code">
				<?php echo CODM_Barcode::svg( $r->tracking_number ? $r->tracking_number : $r->order_number ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped values. ?>
				<div class="lbl-ref">
					<strong>#<?php echo esc_html( $r->order_number ); ?></strong>
					<span><?php echo esc_html( mysql2date( 'd/m/Y', $r->created_at ) ); ?></span>
				</div>
				<?php if ( $r->tracking_number ) : ?>
					<div class="lbl-track"><?php echo esc_html( sprintf( /* translators: %s: tracking number */ __( 'Suivi %s', 'cod-manager-pro' ), $r->tracking_number ) ); ?></div>
				<?php endif; ?>
			</div>
			<?php if ( ! $r->tracking_number ) : ?>
				<div class="lbl-carrier">
					<span class="lbl-k"><?php esc_html_e( 'Réservé au transporteur', 'cod-manager-pro' ); ?></span>
					<span class="lbl-k" lang="ar" dir="rtl">خاص بشركة التوصيل</span>
				</div>
			<?php endif; ?>
		</footer>
	</article>
			<?php
		endfor;
	}
}
