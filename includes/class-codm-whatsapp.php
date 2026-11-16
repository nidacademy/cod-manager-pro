<?php
defined( 'ABSPATH' ) || exit;

/**
 * WhatsApp templates, click-to-chat links and automatic sending through a provider.
 */
class CODM_WhatsApp {

	public static function init() {
		add_action( 'codm_order_status_changed', array( __CLASS__, 'auto_send' ), 10, 3 );
		add_action( 'codm_order_created', array( __CLASS__, 'auto_send_new' ), 10, 2 );
	}

	public static function templates() {
		return array(
			'ask_confirm' => __( 'Ask to confirm the order', 'cod-manager-pro' ),
			'confirmed'   => __( 'Order confirmed', 'cod-manager-pro' ),
			'shipped'     => __( 'Order shipped', 'cod-manager-pro' ),
			'delivered'   => __( 'Order delivered', 'cod-manager-pro' ),
			'failed'      => __( 'Failed delivery', 'cod-manager-pro' ),
		);
	}

	/** Best template for an order's current status. */
	public static function suggested( $status ) {
		switch ( $status ) {
			case 'confirmed':
			case 'processing':
			case 'ready':
				return 'confirmed';
			case 'shipped':
			case 'out_for_delivery':
				return 'shipped';
			case 'delivered':
				return 'delivered';
			case 'returned':
				return 'failed';
		}
		return 'ask_confirm';
	}

	/** Render a template for an order row. */
	public static function message( $row, $key ) {
		$template = (string) CODM_DB::setting( 'wa_' . $key );
		$items    = json_decode( (string) $row->items, true );
		$products = array();
		foreach ( (array) $items as $i ) {
			$products[] = (int) $i['qty'] . ' x ' . $i['name'];
		}
		return strtr( $template, array(
			'{name}'     => $row->customer_name,
			'{order}'    => $row->order_number,
			'{total}'    => CODM_DB::money( $row->total ),
			'{city}'     => $row->city,
			'{address}'  => (string) $row->address,
			'{products}' => implode( ', ', $products ),
			'{store}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{company}'  => CODM_Shipping::name( $row->shipping_company_id ),
			'{tracking}' => $row->tracking_number ? $row->tracking_number : '-',
		) );
	}

	public static function link( $phone, $message ) {
		if ( ! $phone ) {
			return '';
		}
		return 'https://wa.me/' . rawurlencode( $phone ) . '?text=' . rawurlencode( $message );
	}

	/** @return CODM_WhatsApp_Provider[] */
	public static function providers() {
		$providers = apply_filters( 'codm_whatsapp_providers', array() );
		return array_filter( (array) $providers, function ( $p ) {
			return $p instanceof CODM_WhatsApp_Provider;
		} );
	}

	/** New orders get the "ask to confirm" message automatically. */
	public static function auto_send_new( $order_id, $status ) {
		if ( 'new' === $status ) {
			self::auto_send( $order_id, '', 'new' );
		}
	}

	/** Send automatic messages on status changes when a provider is configured. */
	public static function auto_send( $order_id, $old, $new ) {
		if ( empty( CODM_DB::setting( 'wa_auto' ) ) ) {
			return;
		}
		$providers = self::providers();
		$id        = (string) CODM_DB::setting( 'wa_provider' );
		if ( ! isset( $providers[ $id ] ) ) {
			return;
		}
		$events = array(
			'new'       => 'ask_confirm',
			'confirmed' => 'confirmed',
			'shipped'   => 'shipped',
			'delivered' => 'delivered',
			'returned'  => 'failed',
		);
		if ( ! isset( $events[ $new ] ) ) {
			return;
		}
		$row = CODM_Orders::get( $order_id );
		if ( ! $row || ! $row->phone ) {
			return;
		}
		$key    = $events[ $new ];
		$result = $providers[ $id ]->send( $row->phone, self::message( $row, $key ), array( 'order_id' => $order_id, 'template' => $key ) );
		CODM_Orders::history(
			$order_id,
			'whatsapp',
			is_wp_error( $result )
				/* translators: %s error */
				? sprintf( __( 'WhatsApp message failed: %s', 'cod-manager-pro' ), $result->get_error_message() )
				/* translators: %s template name */
				: sprintf( __( 'WhatsApp message sent: %s', 'cod-manager-pro' ), self::templates()[ $key ] )
		);
	}
}
