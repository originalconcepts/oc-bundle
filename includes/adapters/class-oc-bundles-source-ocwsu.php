<?php
/**
 * OC Sale Units (OCWSU) aware source — used when the weighable-products
 * plugin is active. Reads its meta and helpers; all calls guarded.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Source_OCWSU extends OC_Bundles_Source_Native {

	/** @var int Parent product ID that holds OCWSU meta. */
	protected $meta_id;

	/**
	 * @param int $product_id Product or variation ID.
	 */
	public function __construct( $product_id ) {
		parent::__construct( $product_id );
		// OCWSU meta lives on the parent product for variations.
		$this->meta_id = $this->product_id;
		if ( $this->product && $this->product->is_type( 'variation' ) ) {
			$this->meta_id = $this->product->get_parent_id();
		}
	}

	/**
	 * Is this product flagged weighable by OCWSU.
	 *
	 * @return bool
	 */
	protected function is_weighable() {
		return 'yes' === get_post_meta( $this->meta_id, '_ocwsu_weighable', true );
	}

	public function unit() {
		if ( ! $this->is_weighable() ) {
			return 'unit';
		}
		$sold_by_weight = 'yes' === get_post_meta( $this->meta_id, '_ocwsu_sold_by_weight', true );
		if ( $sold_by_weight ) {
			$label = get_post_meta( $this->meta_id, '_ocwsu_product_weight_units', true );
			return ( 'grams' === $label ) ? 'grams' : 'kg';
		}
		return 'unit';
	}

	public function price_for_qty( $qty, $unit_weight_kg = 0 ) {
		if ( ! $this->product || ! $this->is_weighable() ) {
			return parent::price_for_qty( $qty );
		}
		// For weighable products the catalog price is per kg.
		$unit_price = (float) wc_get_price_to_display( $this->product, array( 'qty' => 1 ) );

		// Ordered by units but priced per kg: one unit costs price x its weight, exactly
		// like the same product on a regular cart line (a 0.2 kg portion of a 145/kg
		// product is 29, not 145).
		if ( 'unit' === $this->unit() && (float) $unit_weight_kg > 0 ) {
			return $unit_price * (float) $unit_weight_kg * (float) $qty;
		}

		return $unit_price * (float) $qty;
	}

	public function quantity_label( $qty, $unit ) {
		$qty_str = self::fmt( $qty );

		if ( 'kg' === $unit ) {
			return sprintf( '%s %s', $qty_str, __( 'kg', 'oc-bundles' ) );
		}
		if ( 'grams' === $unit ) {
			return sprintf( '%s %s', $qty_str, __( 'g', 'oc-bundles' ) );
		}

		// Units: reuse OCWSU's label when available.
		if ( function_exists( 'ocwsu_get_sale_unit_quantity_label_text' ) ) {
			$label = ocwsu_get_sale_unit_quantity_label_text( $this->meta_id );
			if ( $label ) {
				return sprintf( '%s %s', $qty_str, $label );
			}
		}
		return sprintf( '%s %s', $qty_str, _x( "pcs", 'units suffix', 'oc-bundles' ) );
	}

	/**
	 * Convert a bundle quantity to the product's stock unit before decrementing.
	 * OCWSU stores weight stock in the product's own weight unit (kg or grams).
	 *
	 * @param float  $qty  Quantity defined in the bundle.
	 * @param string $unit Unit the bundle quantity is expressed in.
	 * @return float
	 */
	protected function to_stock_qty( $qty, $unit ) {
		$qty           = (float) $qty;
		$product_unit  = $this->unit();
		if ( $product_unit === $unit ) {
			return $qty;
		}
		// Normalize between kg and grams when bundle and product differ.
		if ( 'kg' === $product_unit && 'grams' === $unit ) {
			return $qty / 1000.0;
		}
		if ( 'grams' === $product_unit && 'kg' === $unit ) {
			return $qty * 1000.0;
		}
		return $qty;
	}

	public function decrement( $qty, $unit = null ) {
		if ( $this->product && $this->product->managing_stock() ) {
			$amount = ( null === $unit ) ? (float) $qty : $this->to_stock_qty( $qty, $unit );
			wc_update_product_stock( $this->product, $amount, 'decrease' );
		}
	}

	public function increment( $qty, $unit = null ) {
		if ( $this->product && $this->product->managing_stock() ) {
			$amount = ( null === $unit ) ? (float) $qty : $this->to_stock_qty( $qty, $unit );
			wc_update_product_stock( $this->product, $amount, 'increase' );
		}
	}
}
