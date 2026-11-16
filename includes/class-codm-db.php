<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared helpers: table names, settings, money, phones, dates.
 */
class CODM_DB {

	private static $settings = null;

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'codm_' . $name;
	}

	public static function now() {
		return current_time( 'mysql' );
	}

	public static function today() {
		return current_time( 'Y-m-d' );
	}

	public static function defaults() {
		return array(
			// General.
			'country_code'          => '212',
			'currency_label'        => 'DH',
			'store_phone'           => '',
			'store_city'            => '',
			'store_address'         => '',
			'allow_open'            => 1,
			'fragile'               => 0,
			'hold_cod'              => 1,
			'only_cod'              => 0,
			'low_stock'             => 5,
			'delete_on_uninstall'   => 0,
			// Shipping defaults (used when an order has no company cost yet).
			'default_company'       => 0,
			'default_shipping_cost' => 0,
			'default_return_cost'   => 0,
			// Customers and risk.
			'block_blacklisted'     => 1,
			'risk_returns'          => 2,
			'dup_hours'             => 0,
			'ma_phone_only'         => 0,
			// WhatsApp.
			'wa_provider'           => 'manual',
			'wa_auto'               => 0,
			'wa_ask_confirm'        => "Salam {name},\nMerci pour votre commande #{order} sur {store}.\n\nProduits : {products}\nTotal à payer à la livraison : {total}\nVille : {city}\n\nPouvez-vous confirmer votre commande ?",
			'wa_confirmed'          => "Salam {name},\nVotre commande #{order} est confirmée. Elle sera expédiée très bientôt.\nTotal à payer à la livraison : {total}\n\nMerci pour votre confiance !",
			'wa_shipped'            => "Salam {name},\nVotre commande #{order} a été expédiée avec {company}.\nNuméro de suivi : {tracking}\nPréparez {total} pour le livreur.",
			'wa_delivered'          => "Salam {name},\nVotre commande #{order} a bien été livrée. Merci pour votre achat chez {store} !",
			'wa_failed'             => "Salam {name},\nLe livreur n'a pas pu vous livrer la commande #{order}.\nMerci de nous indiquer quand vous êtes disponible pour la recevoir.",
		);
	}

	public static function settings() {
		if ( null === self::$settings ) {
			self::$settings = wp_parse_args( (array) get_option( 'codm_settings', array() ), self::defaults() );
		}
		return self::$settings;
	}

	public static function setting( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function save_settings( array $values ) {
		$merged = array_merge( self::settings(), $values );
		update_option( 'codm_settings', $merged );
		self::$settings = null;
	}

	/**
	 * Normalise to international digits: 0612345678 / 612345678 / +212612345678 -> 212612345678.
	 */
	public static function normalize_phone( $phone ) {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		if ( '' === $d ) {
			return '';
		}
		$cc = preg_replace( '/\D+/', '', (string) self::setting( 'country_code' ) );
		if ( 0 === strpos( $d, '00' ) ) {
			$d = substr( $d, 2 );
		} elseif ( $cc && 0 === strpos( $d, '0' ) ) {
			$d = $cc . substr( $d, 1 );
		} elseif ( $cc && 9 === strlen( $d ) ) {
			$d = $cc . $d;
		}
		// "+212 06 12 34 56 78": drop the trunk 0 typed after the country code.
		if ( $cc && 0 === strpos( $d, $cc . '0' ) && strlen( $d ) === strlen( $cc ) + 10 ) {
			$d = $cc . substr( $d, strlen( $cc ) + 1 );
		}
		return $d;
	}

	/**
	 * Readable phone: 212612345678 -> "06 12 34 56 78" for Moroccan numbers, "+<digits>" otherwise.
	 */
	public static function format_phone( $digits ) {
		$digits = preg_replace( '/\D+/', '', (string) $digits );
		if ( '' === $digits ) {
			return '';
		}
		if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '212' ) ) {
			return '0' . substr( $digits, 3, 1 ) . ' ' . implode( ' ', str_split( substr( $digits, 4 ), 2 ) );
		}
		return '+' . $digits;
	}

	/** True when a Moroccan number has the right length (212 + 9 digits). Other countries are not checked. */
	public static function phone_looks_valid( $digits ) {
		$digits = (string) $digits;
		if ( '' === $digits ) {
			return false;
		}
		return 0 !== strpos( $digits, '212' ) || 12 === strlen( $digits );
	}

	/** Parse a user-typed amount ("35", "35,50", "1 200.00") into a float. */
	public static function decimal( $value ) {
		$v = str_replace( array( ' ', "\xC2\xA0", ',' ), array( '', '', '.' ), (string) $value );
		return round( (float) $v, 2 );
	}

	/** 1234.5 -> "1 234,50 DH" */
	public static function money( $amount, $decimals = 2 ) {
		return number_format( (float) $amount, $decimals, ',', ' ' ) . ' ' . self::setting( 'currency_label' );
	}

	public static function valid_date( $date ) {
		return is_string( $date ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && strtotime( $date );
	}

	/**
	 * Returns [from, to] as Y-m-d. Defaults to the last 30 days.
	 */
	public static function date_range( $from, $to, $default_days = 30 ) {
		$today = self::today();
		$to    = self::valid_date( $to ) ? $to : $today;
		$from  = self::valid_date( $from ) ? $from : gmdate( 'Y-m-d', strtotime( $to . ' -' . ( $default_days - 1 ) . ' days' ) );
		if ( $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		return array( $from, $to );
	}

	public static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/** Build "%d,%d,%d" placeholders for IN() clauses. */
	public static function placeholders( array $values, $format = '%d' ) {
		return implode( ',', array_fill( 0, count( $values ), $format ) );
	}
}
