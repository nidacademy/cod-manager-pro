<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Cost price" field to WooCommerce products and variations, used for profit.
 */
class CODM_Products {

	const META = '_codm_cost';

	public static function init() {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'simple_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ) );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 10, 2 );
	}

	public static function simple_field() {
		global $product_object;
		woocommerce_wp_text_input( array(
			'id'          => self::META,
			'label'       => sprintf( /* translators: %s currency */ __( 'Cost price (%s)', 'cod-manager-pro' ), CODM_DB::setting( 'currency_label' ) ),
			'desc_tip'    => true,
			'description' => __( 'What one unit costs you. COD Manager Pro uses it to calculate profit.', 'cod-manager-pro' ),
			'data_type'   => 'price',
			'value'       => $product_object ? $product_object->get_meta( self::META ) : '',
		) );
	}

	public static function save_simple( $product ) {
		if ( isset( $_POST[ self::META ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the product form nonce.
			$product->update_meta_data( self::META, CODM_DB::decimal( wp_unslash( $_POST[ self::META ] ) ) ); // phpcs:ignore WordPress.Security
		}
	}

	public static function variation_field( $loop, $variation_data, $variation ) {
		woocommerce_wp_text_input( array(
			'id'            => self::META . '_' . $loop,
			'name'          => self::META . '[' . $loop . ']',
			'label'         => sprintf( /* translators: %s currency */ __( 'Cost price (%s)', 'cod-manager-pro' ), CODM_DB::setting( 'currency_label' ) ),
			'data_type'     => 'price',
			'wrapper_class' => 'form-row form-row-first',
			'value'         => get_post_meta( $variation->ID, self::META, true ),
		) );
	}

	public static function save_variation( $variation_id, $i ) {
		if ( isset( $_POST[ self::META ][ $i ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$variation->update_meta_data( self::META, CODM_DB::decimal( wp_unslash( $_POST[ self::META ][ $i ] ) ) ); // phpcs:ignore WordPress.Security
				$variation->save();
			}
		}
	}

	/** Unit cost: variation cost, else parent product cost, else 0. */
	public static function get_cost( $product_id, $variation_id = 0 ) {
		if ( $variation_id ) {
			$v = (float) get_post_meta( $variation_id, self::META, true );
			if ( $v > 0 ) {
				return $v;
			}
		}
		return (float) get_post_meta( $product_id, self::META, true );
	}

	/** Products at or below the low-stock threshold. */
	public static function low_stock( $limit = 8 ) {
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		if ( ! CODM_DB::table_exists( $lookup ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT l.product_id, l.stock_quantity, l.sku FROM $lookup l
			 INNER JOIN {$wpdb->posts} p ON p.ID = l.product_id AND p.post_status = 'publish'
			 WHERE l.stock_quantity IS NOT NULL AND l.stock_quantity <= %d
			 ORDER BY l.stock_quantity ASC LIMIT %d",
			(int) CODM_DB::setting( 'low_stock' ), (int) $limit
		) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r->product_id,
				'name'  => html_entity_decode( get_the_title( $r->product_id ), ENT_QUOTES, 'UTF-8' ),
				'sku'   => (string) $r->sku,
				'stock' => (int) $r->stock_quantity,
				'edit'  => get_edit_post_link( $r->product_id, 'raw' ),
			);
		}
		return $out;
	}
}
