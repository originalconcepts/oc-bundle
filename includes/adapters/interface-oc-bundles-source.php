<?php
/**
 * Component source interface — abstracts unit/stock/price/label/decrement
 * so the bundle works with or without the OC Sale Units plugin.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

interface OC_Bundles_Source_Interface {

	/**
	 * The natural unit of this product: 'kg' | 'grams' | 'unit'.
	 *
	 * @return string
	 */
	public function unit();

	/**
	 * Available stock expressed in the product's natural unit.
	 * Returns null when stock is not managed (treat as unlimited).
	 *
	 * @return float|null
	 */
	public function stock();

	/**
	 * Whether the product is currently purchasable / in stock.
	 *
	 * @return bool
	 */
	public function is_in_stock();

	/**
	 * Price contribution for a given quantity in the bundle (used in 'sum' pricing).
	 *
	 * A product priced per kg but ordered by units costs `price x unit weight` per unit,
	 * exactly like the same product on a regular cart line; `$unit_weight_kg` carries that
	 * weight (see OC_Bundles_Helpers::component_unit_weight_kg()). 0 = priced per unit / per kg as listed.
	 *
	 * @param float $qty            Quantity in the bundle.
	 * @param float $unit_weight_kg Weight (kg) of one unit, for units priced per kg.
	 * @return float
	 */
	public function price_for_qty( $qty, $unit_weight_kg = 0 );

	/**
	 * Human-readable quantity label, e.g. "0.5 kg" / "3 pcs".
	 *
	 * @param float  $qty  Quantity.
	 * @param string $unit Unit hint.
	 * @return string
	 */
	public function quantity_label( $qty, $unit );

	/**
	 * Decrease stock by a quantity (in the product's natural unit).
	 *
	 * @param float $qty Quantity to remove.
	 * @return void
	 */
	public function decrement( $qty );

	/**
	 * Increase stock by a quantity (restock).
	 *
	 * @param float $qty Quantity to add.
	 * @return void
	 */
	public function increment( $qty );
}
