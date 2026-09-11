<?php
/**
 * Single product content for bundles. Theme-agnostic, self-contained layout.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
	$product = wc_get_product( get_the_ID() );
}
if ( ! $product || ! $product->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
	return;
}
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'oc-bundle-single', $product ); ?>>
	<?php
	/**
	 * Show WooCommerce notices (add-to-cart messages, errors).
	 */
	do_action( 'woocommerce_before_single_product' );

	OC_Bundles_Frontend::render( $product );

	do_action( 'woocommerce_after_single_product' );
	?>
</div>
