<?php
/**
 * Factory that returns the right component source for a product.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Source_Factory {

	/**
	 * Whether the OC Sale Units plugin is active.
	 *
	 * @return bool
	 */
	public static function ocwsu_active() {
		return function_exists( 'ocwsu_gather_cart_item_data' ) || class_exists( 'Oc_Woo_Sale_Units' );
	}

	/**
	 * Build a source for the given product/variation ID.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return OC_Bundles_Source_Interface
	 */
	public static function get( $product_id ) {
		if ( self::ocwsu_active() ) {
			return new OC_Bundles_Source_OCWSU( $product_id );
		}
		return new OC_Bundles_Source_Native( $product_id );
	}
}
