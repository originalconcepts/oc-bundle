<?php
/**
 * Registers the "oc_bundle" product type.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product object for bundles. Stock is virtual (computed from components).
 */
class WC_Product_OC_Bundle extends WC_Product {

	public function get_type() {
		return OC_BUNDLES_PRODUCT_TYPE;
	}

	/**
	 * Bundles are purchasable when they have a price and are in stock.
	 */
	public function is_purchasable() {
		$purchasable = '' !== $this->get_price() && $this->is_in_stock();
		return apply_filters( 'woocommerce_is_purchasable', $purchasable, $this );
	}

	/**
	 * In stock = at least one full bundle can be assembled from components.
	 */
	public function is_in_stock() {
		$in_stock = OC_Bundles_Stock::available_quantity( $this->get_id() ) > 0;
		return apply_filters( 'woocommerce_product_is_in_stock', $in_stock, $this );
	}

	/**
	 * Bundle never manages its own WooCommerce stock.
	 */
	public function managing_stock() {
		return false;
	}

	public function add_to_cart_text() {
		return apply_filters( 'woocommerce_product_add_to_cart_text', __( 'Add to cart', 'oc-bundles' ), $this );
	}

	public function single_add_to_cart_text() {
		return apply_filters( 'woocommerce_product_single_add_to_cart_text', __( 'Add to cart', 'oc-bundles' ), $this );
	}
}

class OC_Bundles_Product_Type {

	public static function init() {
		add_filter( 'product_type_selector', array( __CLASS__, 'add_type_selector' ) );
		add_filter( 'woocommerce_product_class', array( __CLASS__, 'map_product_class' ), 10, 2 );

		// Show the General tab (price) and hide irrelevant tabs for bundles.
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_data_tabs' ) );
	}

	/**
	 * Add "Bundle" to the product type dropdown.
	 *
	 * @param array $types Types.
	 * @return array
	 */
	public static function add_type_selector( $types ) {
		$types[ OC_BUNDLES_PRODUCT_TYPE ] = __( 'Bundle', 'oc-bundles' );
		return $types;
	}

	/**
	 * Map our type to the product class.
	 *
	 * @param string $classname Class name.
	 * @param string $product_type Type.
	 * @return string
	 */
	public static function map_product_class( $classname, $product_type ) {
		if ( OC_BUNDLES_PRODUCT_TYPE === $product_type ) {
			return 'WC_Product_OC_Bundle';
		}
		return $classname;
	}

	/**
	 * Keep the General tab visible (for price), drop shipping/attributes complexity hints.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function product_data_tabs( $tabs ) {
		if ( isset( $tabs['general'] ) ) {
			$tabs['general']['class'][] = 'show_if_' . OC_BUNDLES_PRODUCT_TYPE;
		}
		if ( isset( $tabs['inventory'] ) ) {
			$tabs['inventory']['class'][] = 'hide_if_' . OC_BUNDLES_PRODUCT_TYPE;
		}
		return $tabs;
	}
}
