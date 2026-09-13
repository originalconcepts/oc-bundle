<?php
/**
 * Invoice display — optionally break a bundle order line into the bundle itself
 * plus one read-only line per component.
 *
 * With `invoice_display = components` the order shows:
 *
 *   Bundle name          x1     100.00   <- base price, without swap surcharges
 *   Entrecote            x5              <- included in the bundle, no amount
 *   Lamb chops           x1       5.00   <- this component's swap surcharge
 *
 * Each component line's quantity is what ships (per-bundle quantity times the number of
 * bundles), with its unit as item meta — an ordinary WooCommerce line — so the shop
 * re-weighs a component by editing that line's quantity (see OC_Bundles_Order).
 *
 * The order total never changes: whatever is taken off the bundle line is put
 * back on the component lines that caused it. Components included at no extra
 * cost show no amount at all — a "0.00" would read as "free" rather than
 * "part of the bundle".
 *
 * The component lines carry no product ID on purpose. Every WooCommerce stock
 * path (wc_reduce_stock_levels / wc_increase_stock_levels) skips items whose
 * get_product() is falsy, so these lines can never double-reduce component
 * stock — OC_Bundles_Order remains the single place that touches it.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Invoice {

	/** @var string Marks a synthetic component line (value: parent bundle product ID). */
	const MARKER = '_oc_bundle_component_of';

	/** @var string Which component of the bundle this line represents. */
	const INDEX = '_oc_bundle_component_index';

	/** @var string On a component line: its quantity is the component's quantity (1.4.7+). */
	const QTY_LINE = '_oc_bundle_component_qty_line';

	/** @var string On a split bundle line: its component lines hold their own quantities (1.4.7+). */
	const QTY_LINES = '_oc_bundle_qty_lines';

	/** @var string On a split bundle line: tells it apart from other lines of the same bundle. */
	const LINE_UID = '_oc_bundle_line_uid';

	/** @var string On a component line: the LINE_UID of the bundle line it belongs to. */
	const PARENT = '_oc_bundle_component_parent';

	/** @var string On a component line: the amount checkout gave it. */
	const AMOUNT = '_oc_bundle_component_amount';

	/** @var string On a component line: the taxes checkout gave it. */
	const TAXES = '_oc_bundle_component_taxes';


	public static function init() {
		// Classic checkout: all line items exist, order not yet saved.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'split_order' ), 20 );
		// Block checkout (Store API).
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'split_order' ), 20 );

		// An included component must not read as "free".
		add_filter( 'woocommerce_order_formatted_line_subtotal', array( __CLASS__, 'blank_zero_subtotal' ), 10, 3 );
	}

	/**
	 * Split every bundle line configured for a component breakdown.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function split_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		// Already split. Deliberately derived from the items themselves rather than an
		// order meta flag: a resumed checkout wipes and rebuilds the line items, and a
		// sticky flag would leave that retry with no component lines at all.
		foreach ( $order->get_items() as $existing ) {
			if ( '' !== (string) $existing->get_meta( self::MARKER ) ) {
				return;
			}
		}

		$split = false;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$components = $item->get_meta( '_oc_bundle_components' );
			if ( empty( $components ) || ! is_array( $components ) ) {
				continue;
			}

			$config = OC_Bundles_Helpers::get_config( $item->get_product_id() );
			if ( 'components' !== $config['invoice_display'] ) {
				continue;
			}

			self::split_item( $order, $item, $components );
			$split = true;
		}

		// The classic checkout saves straight after this hook, but the Store API hands us
		// an order that is already persisted — those added items need an explicit save.
		if ( $split && $order->get_id() ) {
			$order->save();
		}
	}

	/**
	 * Turn one bundle line into a bundle line + component lines.
	 *
	 * @param WC_Order              $order      Order.
	 * @param WC_Order_Item_Product $item       Bundle line item.
	 * @param array                 $components Resolved components.
	 */
	protected static function split_item( $order, $item, $components ) {
		$bundle_qty = max( 1, (int) $item->get_quantity() );
		$selection  = $item->get_meta( '_oc_bundle_selection' );
		$selection  = is_array( $selection ) ? $selection : array();
		$decimals   = wc_get_price_decimals();

		$bundle_id = $item->get_product_id();
		$config    = OC_Bundles_Helpers::get_config( $bundle_id );
		$originals = is_array( $config['components'] ) ? $config['components'] : array();

		/*
		 * Who owns the money decides the breakdown:
		 *
		 *  - `fixed`: the price belongs to the bundle. It stays on the bundle line and
		 *    the components show nothing except a swap surcharge they caused.
		 *  - `sum`:   the price IS the components. Each one carries its own share and the
		 *    bundle line drops to zero.
		 *
		 * In `sum` mode the base is built from the ORIGINAL components (a swap only adds
		 * its surcharge on top — see Pricing::line_price), so the share is priced from the
		 * original while the line is named after what actually ships.
		 */
		$share_prices = ( 'sum' === $config['pricing_mode'] );
		$discount     = 1.0;
		if ( $share_prices ) {
			$raw = OC_Bundles_Pricing::raw_base_price( $bundle_id, $originals, $config );
			if ( $raw > 0 ) {
				$discount = OC_Bundles_Pricing::base_price( $bundle_id, $originals, $config ) / $raw;
			}
		}

		// What each component line should carry, in order.
		$amounts = array();
		foreach ( $components as $index => $component ) {
			// A surcharge is a delta on top of the base, so it is never discounted.
			$amount = isset( $selection[ $index ]['surcharge'] ) ? (float) $selection[ $index ]['surcharge'] : 0;

			if ( $share_prices ) {
				$priced = OC_Bundles_Helpers::normalize_component( isset( $originals[ $index ] ) ? $originals[ $index ] : $component );
				$pid    = OC_Bundles_Helpers::effective_id( $priced );
				if ( $pid ) {
					$amount += OC_Bundles_Source_Factory::get( $pid )->price_for_qty( $priced['qty'] ) * $discount;
				}
			}

			$amounts[ $index ] = round( $amount * $bundle_qty, $decimals );
		}

		$moved           = round( array_sum( $amounts ), $decimals );
		$bundle_total    = (float) $item->get_total();
		$bundle_subtotal = (float) $item->get_subtotal();

		// Never let the component lines outrun the line they came from — a coupon can
		// discount the bundle below its own parts, and `sum` mode lands near the whole
		// line total where rounding alone can tip it over. Scale to fit instead of
		// distorting the order.
		$cap = min( $bundle_total, $bundle_subtotal );
		if ( $moved > $cap ) {
			if ( $cap <= 0 ) {
				$amounts = array_fill_keys( array_keys( $amounts ), 0 );
			} else {
				$scale = $cap / $moved;
				foreach ( $amounts as $index => $amount ) {
					$amounts[ $index ] = round( $amount * $scale, $decimals );
				}
			}
			// Re-derive from the rounded values so the split stays exact.
			$moved = round( array_sum( $amounts ), $decimals );
		}

		$ratio = ( $bundle_total > 0 && $moved > 0 ) ? ( ( $bundle_total - $moved ) / $bundle_total ) : 1.0;

		// Capture the tax leaving the bundle line BEFORE scaling the line down.
		$moved_taxes = self::moved_taxes( $item, $bundle_total, $ratio, $decimals );

		if ( $moved > 0 ) {
			$item->set_total( round( $bundle_total - $moved, $decimals ) );
			$item->set_subtotal( round( max( 0, $bundle_subtotal - $moved ), $decimals ) );
			self::scale_taxes( $item, $ratio, $decimals );
		}

		// The components are about to become real lines; drop the duplicate summary meta.
		$summary = __( "What's in the bundle", 'oc-bundles' );
		if ( '' !== (string) $item->get_meta( $summary ) ) {
			$item->delete_meta_data( $summary );
		}

		$tax_class = $item->get_tax_class();
		$uid       = wp_generate_uuid4();
		$item->update_meta_data( self::LINE_UID, $uid );
		$qty_lines = false;

		foreach ( $components as $index => $component ) {
			$line = self::build_component_line( $component, $bundle_qty, $item->get_product_id(), $tax_class, $index, $uid );
			if ( ! $line ) {
				continue;
			}

			$amount = isset( $amounts[ $index ] ) ? $amounts[ $index ] : 0;
			$line->set_subtotal( $amount );
			$line->set_total( $amount );

			// Hand this component its share of the tax that left the bundle line.
			if ( $amount > 0 && $moved > 0 && ! empty( $moved_taxes ) ) {
				$share = $amount / $moved;
				$taxes = array();
				foreach ( $moved_taxes as $rate_id => $tax_amount ) {
					$taxes[ $rate_id ] = round( $tax_amount * $share, $decimals );
				}
				$line->set_taxes( array( 'total' => $taxes, 'subtotal' => $taxes ) );
			}

			// Kept so a re-weigh that must not move money can put the line back.
			$line->add_meta_data( self::AMOUNT, $amount, true );
			$line->add_meta_data( self::TAXES, $line->get_taxes(), true );

			$qty_lines = $qty_lines || (bool) $line->get_meta( self::QTY_LINE );
			$order->add_item( $line );
		}

		// Tells OC_Bundles_Order these lines are the weighed amounts, even after one is removed.
		if ( $qty_lines ) {
			$item->update_meta_data( self::QTY_LINES, 1 );
		}
	}

	/**
	 * Build one component line item (deliberately product-less — see class docblock).
	 *
	 * @param array  $component  Resolved component.
	 * @param int    $bundle_qty Number of bundles ordered.
	 * @param int    $bundle_id  Parent bundle product ID.
	 * @param string $tax_class  Tax class inherited from the bundle line.
	 * @param int    $index      Component index, so stock and re-pricing find this line again.
	 * @param string $uid        LINE_UID of the bundle line.
	 * @return WC_Order_Item_Product|null
	 */
	protected static function build_component_line( $component, $bundle_qty, $bundle_id, $tax_class, $index = null, $uid = '' ) {
		$component = OC_Bundles_Helpers::normalize_component( $component );
		$pid       = OC_Bundles_Helpers::effective_id( $component );
		$product   = $pid ? wc_get_product( $pid ) : false;
		if ( ! $product ) {
			return null;
		}

		// What actually ships: per-bundle quantity times the number of bundles.
		$qty = (float) $component['qty'] * $bundle_qty;

		$line = new WC_Order_Item_Product();
		$line->set_tax_class( $tax_class );
		$line->add_meta_data( self::MARKER, (int) $bundle_id, true );
		if ( null !== $index ) {
			$line->add_meta_data( self::INDEX, (int) $index, true );
		}
		if ( '' !== $uid ) {
			$line->add_meta_data( self::PARENT, $uid, true );
		}

		if ( self::quantity_fits( $qty ) ) {
			// An ordinary line: the product's name, the quantity in the quantity column and the
			// unit beside it, so re-weighing is editing the quantity like on any other line.
			$line->set_name( $product->get_name() );
			$line->set_quantity( $qty );
			$line->add_meta_data( self::QTY_LINE, 1, true );
			$line->add_meta_data( __( 'Unit', 'oc-bundles' ), OC_Bundles_Helpers::display_suffix( $component['unit'], $component['unit_label'] ), true );
		} else {
			// This store keeps whole-number line quantities (no weighable-products plugin), so a
			// fraction cannot live in the quantity: name it instead, as before 1.4.7.
			$scaled        = $component;
			$scaled['qty'] = $qty;
			$line->set_name( trim( OC_Bundles_Helpers::quantity_label( $scaled ) . ' ' . $product->get_name() ) );
			$line->set_quantity( 1 );
		}

		if ( $product->get_sku() ) {
			$line->add_meta_data( __( 'SKU', 'oc-bundles' ), $product->get_sku(), true );
		}
		if ( '' !== $component['description'] ) {
			$line->add_meta_data( __( 'Note', 'oc-bundles' ), $component['description'], true );
		}

		return $line;
	}

	/**
	 * Whether a line quantity can hold this amount as-is. WooCommerce's own wc_stock_amount()
	 * rounds to whole numbers; a weighable-products plugin lets it keep fractions.
	 *
	 * @param float $qty Quantity.
	 * @return bool
	 */
	protected static function quantity_fits( $qty ) {
		return abs( (float) wc_stock_amount( $qty ) - (float) $qty ) < 0.00001;
	}

	/**
	 * The tax amounts (per rate) removed from the bundle line, keyed by rate ID.
	 *
	 * @param WC_Order_Item_Product $item     Bundle line.
	 * @param float                 $total    Bundle total before the split.
	 * @param float                 $ratio    Share of the line that stays on it.
	 * @param int                   $decimals Price decimals.
	 * @return array
	 */
	protected static function moved_taxes( $item, $total, $ratio, $decimals ) {
		if ( $total <= 0 || $ratio >= 1.0 ) {
			return array();
		}
		$taxes = $item->get_taxes();
		if ( empty( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return array();
		}
		$moved = array();
		foreach ( $taxes['total'] as $rate_id => $amount ) {
			$moved[ $rate_id ] = round( (float) $amount * ( 1 - $ratio ), $decimals );
		}
		return $moved;
	}

	/**
	 * Scale a line's taxes by a ratio (used when money leaves the bundle line).
	 *
	 * @param WC_Order_Item_Product $item     Item.
	 * @param float                 $ratio    Multiplier.
	 * @param int                   $decimals Price decimals.
	 */
	protected static function scale_taxes( $item, $ratio, $decimals ) {
		$taxes = $item->get_taxes();
		if ( empty( $taxes['total'] ) || ! is_array( $taxes['total'] ) ) {
			return;
		}
		foreach ( array( 'total', 'subtotal' ) as $bucket ) {
			if ( empty( $taxes[ $bucket ] ) || ! is_array( $taxes[ $bucket ] ) ) {
				continue;
			}
			foreach ( $taxes[ $bucket ] as $rate_id => $amount ) {
				$taxes[ $bucket ][ $rate_id ] = round( (float) $amount * $ratio, $decimals );
			}
		}
		$item->set_taxes( $taxes );
	}

	/**
	 * Render no amount at all where a zero would only mislead.
	 *
	 * Two cases, both worth nothing on the line and both reading as "free" if printed
	 * as 0.00: a component included in the bundle price, and — under `sum` pricing —
	 * the bundle line itself, whose money has all moved onto its components.
	 *
	 * This filter is what WooCommerce's order-details AND email item templates both
	 * call, so the two stay consistent.
	 *
	 * @param string        $formatted Formatted subtotal.
	 * @param WC_Order_Item $item      Item.
	 * @param WC_Order      $order     Order.
	 * @return string
	 */
	public static function blank_zero_subtotal( $formatted, $item, $order ) {
		if ( ! $item instanceof WC_Order_Item_Product || (float) $item->get_total() > 0 ) {
			return $formatted;
		}

		// A synthetic component line.
		if ( '' !== (string) $item->get_meta( self::MARKER ) ) {
			return '';
		}

		// The bundle line, but only once its components are carrying the money — a
		// genuinely unsplit bundle that happens to cost nothing still shows its zero.
		$components = $item->get_meta( '_oc_bundle_components' );
		if ( empty( $components ) || ! is_array( $components ) || ! $order instanceof WC_Order ) {
			return $formatted;
		}
		$bundle_id = (int) $item->get_product_id();
		foreach ( $order->get_items() as $line ) {
			if ( (int) $line->get_meta( self::MARKER ) === $bundle_id ) {
				return '';
			}
		}

		return $formatted;
	}
}
