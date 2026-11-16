<?php
defined( 'ABSPATH' ) || exit;

/**
 * Extras:
 *  - Order rules for cash on delivery: blacklisted numbers, repeat orders and (optionally) Moroccan numbers only.
 *    Applied to the WooCommerce checkout (classic and block). Custom order forms can use the
 *    `codm_check_order` and `codm_order_initial_status` filters.
 *  - Order source tracking (Facebook, Instagram, TikTok, Google... paid or organic) with a source filter
 *    on the Orders screen and a "Results by source" table on the Dashboard.
 *  - Setup checklist on the Dashboard.
 */
class CODM_Extra {

	public static function init() {
		add_filter( 'codm_check_order', array( __CLASS__, 'block' ), 10, 2 );
		add_filter( 'codm_order_initial_status', array( __CLASS__, 'initial_status' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Order rules                                                         */
	/* ------------------------------------------------------------------ */

	/** For custom order forms: status a new COD order should start with (setting "Hold new COD orders"). */
	public static function initial_status( $status, $order = null ) {
		return CODM_DB::setting( 'hold_cod' ) ? CODM_Status::WC_AWAITING : $status;
	}

	/** One neutral message, so the form never reveals whether a number is blacklisted or already ordered. */
	public static function refused_message() {
		return __( 'We cannot place this order with this phone number. If you have already ordered, we will call you soon to confirm it. Otherwise, please contact us.', 'cod-manager-pro' );
	}

	public static function invalid_phone_message() {
		return __( 'Please enter a valid Moroccan phone number (for example 0612345678).', 'cod-manager-pro' );
	}

	/** Visitors who keep getting refused are stopped for a while (prevents checking numbers one by one). */
	private static function visitor_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return 'codm_refused_' . md5( $ip );
	}

	private static function too_many_refusals() {
		return (int) get_transient( self::visitor_key() ) >= 5;
	}

	private static function count_refusal() {
		$k = self::visitor_key();
		set_transient( $k, (int) get_transient( $k ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Check an order before it is placed. Returns the message to show, or '' to accept.
	 *
	 * @param string $phone       Phone as typed.
	 * @param int[]  $product_ids Products in the order.
	 */
	public static function check( $phone, array $product_ids = array() ) {
		global $wpdb;
		if ( self::too_many_refusals() ) {
			return self::refused_message();
		}
		$phone = CODM_DB::normalize_phone( $phone );
		if ( CODM_DB::setting( 'ma_phone_only' ) && ! preg_match( '/^212[5-7]\d{8}$/', $phone ) ) {
			return self::invalid_phone_message();
		}
		if ( CODM_DB::setting( 'block_blacklisted' ) && CODM_Customers::is_blacklisted_phone( $phone ) ) {
			self::count_refusal();
			return self::refused_message();
		}
		$hours = (int) CODM_DB::setting( 'dup_hours' );
		$ids   = array_values( array_filter( array_map( 'absint', $product_ids ) ) );
		if ( $hours > 0 && $phone && $ids ) {
			$since = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $hours * HOUR_IN_SECONDS ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			foreach ( $ids as $pid ) {
				$found = $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . CODM_DB::table( 'orders' ) . " WHERE phone = %s AND created_at >= %s AND status <> 'cancelled' AND items LIKE %s",
					$phone,
					$since,
					'%' . $wpdb->esc_like( '"product_id":' . $pid . ',' ) . '%'
				) ); // phpcs:ignore WordPress.DB.PreparedSQL
				if ( $found ) {
					self::count_refusal();
					return self::refused_message();
				}
			}
		}
		return '';
	}

	/**
	 * Filter `codm_check_order` for custom order forms.
	 *
	 * @param string $msg Message from an earlier filter.
	 * @param array  $d   phone, product_id (or product_ids).
	 */
	public static function block( $msg, $d ) {
		if ( '' !== (string) $msg ) {
			return $msg;
		}
		$ids = isset( $d['product_ids'] ) ? (array) $d['product_ids'] : ( isset( $d['product_id'] ) ? array( $d['product_id'] ) : array() );
		return self::check( isset( $d['phone'] ) ? (string) $d['phone'] : '', $ids );
	}

	/* ------------------------------------------------------------------ */
	/* Order source                                                        */
	/* ------------------------------------------------------------------ */

	private static function networks() {
		return array(
			'facebook'  => array( 'Facebook', array( 'facebook', 'fb', 'meta' ), array( 'facebook.com', 'fb.com', 'fb.me' ) ),
			'instagram' => array( 'Instagram', array( 'instagram', 'ig' ), array( 'instagram.com' ) ),
			'tiktok'    => array( 'TikTok', array( 'tiktok', 'tt' ), array( 'tiktok.com' ) ),
			'google'    => array( 'Google', array( 'google', 'adwords', 'gads' ), array( 'google.' ) ),
			'snapchat'  => array( 'Snapchat', array( 'snapchat', 'snap' ), array( 'snapchat.com' ) ),
			'youtube'   => array( 'YouTube', array( 'youtube', 'yt' ), array( 'youtube.com', 'youtu.be' ) ),
			'whatsapp'  => array( 'WhatsApp', array( 'whatsapp', 'wa' ), array( 'whatsapp.com', 'wa.me' ) ),
			'x'         => array( 'X (Twitter)', array( 'twitter', 'x' ), array( 'twitter.com', 'x.com', 't.co' ) ),
			'pinterest' => array( 'Pinterest', array( 'pinterest' ), array( 'pinterest.' ) ),
			'bing'      => array( 'Bing', array( 'bing', 'microsoft' ), array( 'bing.com' ) ),
			'email'     => array( 'Email', array( 'email', 'newsletter', 'mail' ), array() ),
		);
	}

	/**
	 * Turn raw tracking data into a readable source, e.g. "Facebook Ads", "Instagram", "Google", "Direct".
	 *
	 * @param array $a utm_source, utm_medium, fbclid, ttclid, gclid, gbraid, wbraid, ScCid, msclkid, ref (referrer host).
	 */
	public static function classify( array $a ) {
		$us   = strtolower( trim( (string) ( isset( $a['utm_source'] ) ? $a['utm_source'] : '' ) ) );
		$um   = strtolower( trim( (string) ( isset( $a['utm_medium'] ) ? $a['utm_medium'] : '' ) ) );
		$ref  = strtolower( preg_replace( '/^(www\.|m\.|l\.|lm\.|web\.|mobile\.)/', '', (string) ( isset( $a['ref'] ) ? $a['ref'] : '' ) ) );
		$paid = (bool) preg_match( '/cpc|ppc|paid|ads?\b|cpm|sponsored|social_paid|display/', $um );
		$net  = '';
		$nets = self::networks();

		if ( '' !== $us ) {
			foreach ( $nets as $key => $n ) {
				foreach ( $n[1] as $alias ) {
					if ( $us === $alias || ( strlen( $alias ) > 3 && false !== strpos( $us, $alias ) ) ) {
						$net = $key;
						break 2;
					}
				}
			}
		}
		// Ad click ids.
		$clicks = array( 'ttclid' => 'tiktok', 'gclid' => 'google', 'gbraid' => 'google', 'wbraid' => 'google', 'ScCid' => 'snapchat', 'msclkid' => 'bing' );
		foreach ( $clicks as $param => $key ) {
			if ( ! empty( $a[ $param ] ) ) {
				$paid = true;
				if ( '' === $net ) {
					$net = $key;
				}
			}
		}
		if ( '' === $net && ! empty( $a['fbclid'] ) ) {
			$net = ( false !== strpos( $ref, 'instagram' ) ) ? 'instagram' : 'facebook';
		}
		if ( '' === $net && '' !== $ref ) {
			foreach ( $nets as $key => $n ) {
				foreach ( $n[2] as $host ) {
					if ( 0 === strpos( $ref, $host ) || false !== strpos( $ref, '.' . $host ) ) {
						$net = $key;
						break 2;
					}
				}
			}
		}
		if ( '' !== $net ) {
			return $nets[ $net ][0] . ( $paid && 'email' !== $net && 'whatsapp' !== $net ? ' Ads' : '' );
		}
		if ( '' !== $us ) {
			return substr( ucfirst( sanitize_text_field( $us ) ), 0, 50 );
		}
		if ( '' !== $ref ) {
			return substr( sanitize_text_field( $ref ), 0, 50 );
		}
		return 'Direct';
	}

	/**
	 * Source and campaign of a WooCommerce order.
	 *
	 * @return array [ source, campaign ] ('' when unknown).
	 */
	public static function source_of( WC_Order $order ) {
		// Custom forms can store their own tracking data (utm_*, fbclid, ttclid, gclid, ref) in this meta.
		$attr = $order->get_meta( '_codm_attr' );
		if ( is_array( $attr ) ) {
			return array( self::classify( $attr ), isset( $attr['utm_campaign'] ) ? substr( (string) $attr['utm_campaign'], 0, 190 ) : '' );
		}
		// Orders from the WooCommerce checkout: WooCommerce's own order attribution.
		$type = (string) $order->get_meta( '_wc_order_attribution_source_type' );
		if ( '' !== $type ) {
			if ( 'typein' === $type ) {
				return array( 'Direct', '' );
			}
			if ( 'admin' === $type ) {
				return array( 'Added by admin', '' );
			}
			$ref = (string) $order->get_meta( '_wc_order_attribution_referrer' );
			return array(
				self::classify( array(
					'utm_source' => (string) $order->get_meta( '_wc_order_attribution_utm_source' ),
					'utm_medium' => (string) $order->get_meta( '_wc_order_attribution_utm_medium' ),
					'ref'        => $ref ? (string) wp_parse_url( $ref, PHP_URL_HOST ) : '',
				) ),
				substr( (string) $order->get_meta( '_wc_order_attribution_utm_campaign' ), 0, 190 ),
			);
		}
		if ( 'admin' === $order->get_created_via() ) {
			return array( 'Added by admin', '' );
		}
		return array( '', '' );
	}

	/** Sources seen so far (for the Orders filter). */
	public static function sources() {
		global $wpdb;
		return $wpdb->get_col( 'SELECT source FROM ' . CODM_DB::table( 'orders' ) . " WHERE source <> '' GROUP BY source ORDER BY COUNT(*) DESC LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Orders, confirmation, delivery and revenue per source, for the Dashboard. */
	public static function sources_summary( $from, $to ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT source, COUNT(*) AS orders,
				SUM(status IN ('confirmed','processing','ready','shipped','out_for_delivery','delivered','returned')) AS confirmed,
				SUM(status = 'delivered') AS delivered,
				SUM(status = 'returned') AS returned,
				SUM(status = 'cancelled') AS cancelled,
				SUM(CASE WHEN status = 'delivered' THEN total ELSE 0 END) AS revenue
			FROM " . CODM_DB::table( 'orders' ) . ' WHERE created_at BETWEEN %s AND %s GROUP BY source ORDER BY orders DESC LIMIT 20',
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = array();
		foreach ( $rows as $r ) {
			$done  = (int) $r->delivered + (int) $r->returned;
			$out[] = array(
				'source'            => '' !== $r->source ? $r->source : __( 'Unknown', 'cod-manager-pro' ),
				'orders'            => (int) $r->orders,
				'confirmed'         => (int) $r->confirmed,
				'delivered'         => (int) $r->delivered,
				'returned'          => (int) $r->returned,
				'cancelled'         => (int) $r->cancelled,
				'revenue'           => round( (float) $r->revenue, 2 ),
				'confirmation_rate' => (int) $r->orders ? round( 100 * (int) $r->confirmed / (int) $r->orders, 1 ) : null,
				'delivery_rate'     => $done ? round( 100 * (int) $r->delivered / $done, 1 ) : null,
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Setup checklist                                                     */
	/* ------------------------------------------------------------------ */

	/** Unfinished setup steps: array of [ text, link ]. */
	public static function setup_steps() {
		global $wpdb;
		$steps     = array();
		$settings  = admin_url( 'admin.php?page=codm-settings' );
		$companies = CODM_Shipping::companies( true );
		if ( ! $companies ) {
			$steps[] = array( __( 'Add your shipping companies and their delivery / return fees, so profit and courier payouts are correct.', 'cod-manager-pro' ), $settings . '&tab=shipping' );
		} elseif ( ! (int) CODM_DB::setting( 'default_company' ) ) {
			$steps[] = array( __( 'Choose a default shipping company for new orders.', 'cod-manager-pro' ), $settings . '&tab=shipping' );
		}
		$missing = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'product' AND p.post_status = 'publish'
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} c ON c.ID = m.post_id
				WHERE ( c.ID = p.ID OR c.post_parent = p.ID ) AND m.meta_key = '_codm_cost' AND m.meta_value + 0 > 0
			)"
		); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $missing ) {
			$steps[] = array(
				/* translators: %d number of products */
				sprintf( _n( '%d product has no cost price. Add it in Products > Edit > "Cost price" so profit is calculated.', '%d products have no cost price. Add it in Products > Edit > "Cost price" so profit is calculated.', $missing, 'cod-manager-pro' ), $missing ),
				admin_url( 'edit.php?post_type=product' ),
			);
		}
		if ( '' === trim( (string) CODM_DB::setting( 'store_phone' ) ) || '' === trim( (string) CODM_DB::setting( 'store_address' ) ) ) {
			$steps[] = array( __( 'Add your store phone and address. They are printed on the shipping labels.', 'cod-manager-pro' ), $settings . '&tab=general' );
		}
		return $steps;
	}
}
