<?php
/**
 * Swap — AJAX endpoint returning a component's swap pool (original + alternatives).
 * Each entry includes a ready-to-inject tile HTML plus data for the live card update.
 * The frontend hides whichever entry is currently in the bundle (true swap).
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Swap {

	public static function init() {
		add_action( 'wp_ajax_oc_bundles_swap_options', array( __CLASS__, 'options' ) );
		add_action( 'wp_ajax_nopriv_oc_bundles_swap_options', array( __CLASS__, 'options' ) );
	}

	/**
	 * Return the swap pool for one component.
	 */
	public static function options() {
		check_ajax_referer( 'oc_bundles_swap', 'nonce' );

		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;
		$index     = isset( $_POST['index'] ) ? sanitize_text_field( wp_unslash( $_POST['index'] ) ) : '';

		$config     = OC_Bundles_Helpers::get_config( $bundle_id );
		$components = $config['components'];

		if ( ! isset( $components[ $index ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Component not found.', 'oc-bundles' ) ) );
		}

		$component = OC_Bundles_Helpers::normalize_component( $components[ $index ] );

		$pool = array();

		// key -1 = the original (default) component product.
		$entry = self::entry( OC_Bundles_Helpers::effective_id( $component ), -1, 0 );
		if ( $entry ) {
			$pool[] = $entry;
		}

		// Alternatives.
		foreach ( $component['swaps'] as $oi => $swap ) {
			$pid   = ! empty( $swap['variation_id'] ) ? $swap['variation_id'] : $swap['product_id'];
			$entry = self::entry( $pid, (int) $oi, (float) $swap['surcharge'] );
			if ( $entry ) {
				$pool[] = $entry;
			}
		}

		wp_send_json_success(
			array(
				'title' => __( 'Choose an alternative product', 'oc-bundles' ),
				'pool'  => $pool,
			)
		);
	}

	/**
	 * Build one pool entry (data + ready tile HTML).
	 *
	 * @param int   $product_id Product/variation ID.
	 * @param int   $key        Swap key (-1 = original, else swaps index).
	 * @param float $surcharge  Surcharge.
	 * @return array|null
	 */
	protected static function entry( $product_id, $key, $surcharge ) {
		$product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			return null;
		}

		$name      = $product->get_name();
		$image     = $product->get_image( 'woocommerce_thumbnail' );
		$in_stock  = $product->is_in_stock();

		$surcharge_html = '';
		if ( $surcharge > 0 ) {
			$surcharge_html = '<span class="oc-swap-surcharge">+' . wp_kses_post( wc_price( $surcharge ) ) . '</span>';
		} elseif ( $surcharge < 0 ) {
			$surcharge_html = '<span class="oc-swap-surcharge oc-neg">' . wp_kses_post( wc_price( $surcharge ) ) . '</span>';
		}

		$oos_html = $in_stock ? '' : '<span class="oc-swap-oos">' . esc_html__( 'Out of stock', 'oc-bundles' ) . '</span>';

		$tile = sprintf(
			'<div class="oc-swap-option%1$s" data-key="%2$d" data-surcharge="%3$s">' .
				'<div class="oc-swap-thumbs">%4$s</div>' .
				'<span class="oc-swap-name">%5$s</span>%6$s%7$s' .
			'</div>',
			$in_stock ? '' : ' is-disabled',
			(int) $key,
			esc_attr( $surcharge ),
			$image,
			esc_html( $name ),
			$surcharge_html,
			$oos_html
		);

		return array(
			'key'       => (int) $key,
			'name'      => $name,
			'image'     => $image,
			'surcharge' => (float) $surcharge,
			'in_stock'  => (bool) $in_stock,
			'tile'      => $tile,
		);
	}
}
