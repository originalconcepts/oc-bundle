<?php
/**
 * Native WooCommerce source — used when OC Sale Units is not active.
 * Treats components as whole-unit products.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Source_Native implements OC_Bundles_Source_Interface {

	/** @var int */
	protected $product_id;

	/** @var WC_Product|false */
	protected $product;

	/**
	 * @param int $product_id Product or variation ID.
	 */
	public function __construct( $product_id ) {
		$this->product_id = absint( $product_id );
		$this->product    = $this->product_id ? wc_get_product( $this->product_id ) : false;
	}

	public function unit() {
		return 'unit';
	}

	public function stock() {
		if ( ! $this->product ) {
			return 0.0;
		}
		if ( ! $this->product->managing_stock() ) {
			// Not managed: limited only by in-stock status.
			return $this->product->is_in_stock() ? null : 0.0;
		}
		if ( $this->product->backorders_allowed() ) {
			return null;
		}
		return (float) $this->product->get_stock_quantity();
	}

	public function is_in_stock() {
		return $this->product ? $this->product->is_in_stock() : false;
	}

	public function price_for_qty( $qty ) {
		if ( ! $this->product ) {
			return 0.0;
		}
		return (float) wc_get_price_to_display( $this->product, array( 'qty' => 1 ) ) * (float) $qty;
	}

	public function quantity_label( $qty, $unit ) {
		$qty = self::fmt( $qty );
		return sprintf( '%s %s', $qty, _x( "pcs", 'units suffix', 'oc-bundles' ) );
	}

	public function decrement( $qty ) {
		if ( $this->product && $this->product->managing_stock() ) {
			wc_update_product_stock( $this->product, (float) $qty, 'decrease' );
		}
	}

	public function increment( $qty ) {
		if ( $this->product && $this->product->managing_stock() ) {
			wc_update_product_stock( $this->product, (float) $qty, 'increase' );
		}
	}

	/**
	 * Format a number without trailing zeros.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	public static function fmt( $n ) {
		$n = (float) $n;
		if ( floor( $n ) === $n ) {
			return (string) (int) $n;
		}
		return rtrim( rtrim( number_format( $n, 3, '.', '' ), '0' ), '.' );
	}
}
