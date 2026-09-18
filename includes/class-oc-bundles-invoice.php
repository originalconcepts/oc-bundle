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
 * re-weighs a component by editing that line's quantity (see OC_Bundles_Order). An
 * integration that pushes weighed quantities (oc_bundles_update_order_line) writes them
 * to the same line quantities.
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

	/** @var string On a split bundle line: tells it apart from other lines of the same bundle (set before it is ever saved). */
	const LINE_UID = '_oc_bundle_line_uid';

	/** @var string On a component line: the LINE_UID of the bundle line it belongs to. */
	const PARENT = '_oc_bundle_component_parent';

	/**
	 * @var string On a component line: the order item ID of the bundle line, once that line has one.
	 * Derived from the uid link after the save (backfill_parent_ids); what integrations (Giorgio) read.
	 */
	const PARENT_ITEM = '_oc_bundle_parent_item';

	/** @var string On a component line: the amount checkout gave it. */
	const AMOUNT = '_oc_bundle_component_amount';

	/** @var string On a component line: the taxes checkout gave it. */
	const TAXES = '_oc_bundle_component_taxes';


	public static function init() {
		// Classic checkout: all line items exist, order not yet saved.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'split_order' ), 20 );
		// Block checkout (Store API).
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'split_order' ), 20 );

		// A bundle line split before it had an item id (classic checkout, or a line added
		// in memory by oc_bundles_add_order_line) gets its component lines linked to it
		// once the order — and with it every item — has been saved.
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'backfill_parent_ids' ), 10, 1 );

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

			self::split_item( $order, $item, $components, (int) $item->get_id() );
			$split = true;
		}

		// The classic checkout saves straight after this hook, but the Store API hands us
		// an order that is already persisted — those added items need an explicit save.
		if ( $split && $order->get_id() ) {
			$order->save();
			self::backfill_parent_ids( $order );
		}
	}

	/**
	 * Link component lines that were split before their bundle line had an item id.
	 *
	 * Runs after every order save, when all items have ids. Two passes:
	 *  1. By uid — split_item() stamps a LINE_UID on the bundle line and the same value
	 *     (PARENT) on its component lines, so the item id can be derived deterministically.
	 *     The uid stays; only the item id (PARENT_ITEM) is added.
	 *  2. Legacy lines (split before 1.4.7, no uid) by bundle product and order of
	 *     appearance: the lines of one bundle line carry ascending component indexes,
	 *     so an index that does not increase starts the next bundle line of the same
	 *     product. Bundle lines that already have linked children are skipped.
	 * Lines that already carry the item id are left alone, so this is safe to repeat.
	 *
	 * @param WC_Order|int $order Order.
	 */
	public static function backfill_parent_ids( $order ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order || ! $order->get_id() ) {
			return;
		}

		$by_uid   = array(); // uid => bundle line.
		$parents  = array(); // bundle product id => bundle line item ids without linked children, in order.
		$linked   = array(); // bundle line item ids that already have linked children.
		$orphans  = array(); // bundle product id => component lines lacking any link, in order.
		$by_link  = array(); // component lines carrying a parent uid but no item id yet.
		$bundles  = array(); // bundle lines, in order.

		foreach ( $order->get_items() as $line ) {
			if ( ! $line instanceof WC_Order_Item_Product || ! $line->get_id() ) {
				continue;
			}
			$marker = (string) $line->get_meta( self::MARKER );
			if ( '' !== $marker ) {
				$parent = (int) $line->get_meta( self::PARENT_ITEM );
				if ( $parent > 0 ) {
					$linked[ $parent ] = true;
					continue;
				}
				if ( '' !== (string) $line->get_meta( self::PARENT ) ) {
					$by_link[] = $line;
				} else {
					$orphans[ (int) $marker ][] = $line;
				}
				continue;
			}
			$components = $line->get_meta( '_oc_bundle_components' );
			if ( empty( $components ) || ! is_array( $components ) ) {
				continue;
			}
			$bundles[] = $line;
			$uid       = (string) $line->get_meta( self::LINE_UID );
			if ( '' !== $uid ) {
				$by_uid[ $uid ] = $line;
			}
		}

		if ( empty( $by_link ) && empty( $orphans ) ) {
			return;
		}

		// Pass 1: uids.
		foreach ( $by_link as $line ) {
			$uid = (string) $line->get_meta( self::PARENT );
			if ( ! isset( $by_uid[ $uid ] ) ) {
				// Parent gone (removed before saving): nothing to link to.
				continue;
			}
			$parent_id = (int) $by_uid[ $uid ]->get_id();
			$line->update_meta_data( self::PARENT_ITEM, $parent_id );
			$line->save();
			$linked[ $parent_id ] = true;
		}

		if ( empty( $orphans ) ) {
			return;
		}

		// Pass 2: legacy lines, by product + order of appearance.
		foreach ( $bundles as $line ) {
			$id = (int) $line->get_id();
			if ( isset( $linked[ $id ] ) ) {
				continue;
			}
			$parents[ (int) $line->get_product_id() ][] = $id;
		}

		foreach ( $orphans as $bundle_id => $lines ) {
			if ( empty( $parents[ $bundle_id ] ) ) {
				continue;
			}
			$ids  = array_values( $parents[ $bundle_id ] );
			$pos  = 0;
			$last = -1;
			foreach ( $lines as $line ) {
				$index = (int) $line->get_meta( self::INDEX );
				if ( $index <= $last && isset( $ids[ $pos + 1 ] ) ) {
					$pos++;
				}
				$last = $index;
				$line->update_meta_data( self::PARENT_ITEM, (int) $ids[ $pos ] );
				$line->save();
			}
		}
	}

	/**
	 * Re-do the invoice split for one bundle line, IN MEMORY. When the bundle is
	 * configured for a component breakdown, the component lines it already owns (linked
	 * by its uid, or by item id) are updated IN PLACE and
	 * only missing ones are added; otherwise its component lines are dropped. Nothing is
	 * saved — the caller saves the order (removals are applied by WooCommerce on that save).
	 *
	 * In place, and removals last, because of how WooCommerce keys unsaved items:
	 * `add_item()` keys a line without an id as `'new:line_items' . count( items )`, so
	 * removing any line and then adding one hands the new line the key of an existing
	 * unsaved line and silently overwrites it. Updating what is there, adding what is
	 * missing, and removing only AFTER every add keeps every key unique. Limitation: a
	 * caller that removes items itself and adds afterwards still hits WooCommerce's
	 * collision — nothing here can prevent it.
	 *
	 * @param WC_Order              $order Order holding the line.
	 * @param WC_Order_Item_Product $item  Bundle line item.
	 * @return bool True when component lines were (re)created.
	 */
	public static function resplit_item( $order, $item ) {
		if ( ! $order instanceof WC_Order || ! $item instanceof WC_Order_Item_Product ) {
			return false;
		}
		$components = $item->get_meta( '_oc_bundle_components' );
		if ( empty( $components ) || ! is_array( $components ) ) {
			return false;
		}

		$config = OC_Bundles_Helpers::get_config( $item->get_product_id() );
		if ( 'components' !== $config['invoice_display'] ) {
			self::remove_split_lines( $order, $item );
			return false;
		}

		$owned  = self::owned_split_lines( $order, $item );
		$unused = self::split_item( $order, $item, $components, (int) $item->get_id(), $owned['lines'] );

		// Removals only now, after every add (see the docblock): lines whose component
		// index is gone or whose product vanished, duplicates, and legacy unlinked lines.
		foreach ( array_keys( $unused ) as $index ) {
			if ( isset( $owned['keys'][ $index ] ) ) {
				$order->remove_item( $owned['keys'][ $index ] );
			}
		}
		foreach ( $owned['extra'] as $key ) {
			$order->remove_item( $key );
		}
		self::remove_split_lines( $order, $item, true );
		return true;
	}

	/**
	 * The component lines a bundle line owns through an explicit link — its uid on
	 * PARENT, or its item id on PARENT_ITEM — keyed by component index, together with
	 * their order-item keys.
	 * Legacy unlinked lines are not included (see remove_split_lines()).
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item  Bundle line item.
	 * @return array {
	 *     @type array $lines index => WC_Order_Item_Product
	 *     @type array $keys  index => order item key (id, or 'new:…' while unsaved)
	 *     @type array $extra order item keys of owned lines with no usable index (duplicates)
	 * }
	 */
	protected static function owned_split_lines( $order, $item ) {
		$parent_id = (int) $item->get_id();
		$bundle_id = (int) $item->get_product_id();
		$uid       = (string) $item->get_meta( self::LINE_UID );
		$lines     = array();
		$keys      = array();
		$extra     = array();

		foreach ( $order->get_items() as $key => $line ) {
			if ( (int) $line->get_meta( self::MARKER ) !== $bundle_id ) {
				continue;
			}
			$line_uid = (string) $line->get_meta( self::PARENT );
			$linked   = (int) $line->get_meta( self::PARENT_ITEM );
			if ( '' !== $line_uid ) {
				if ( '' === $uid || $line_uid !== $uid ) {
					continue;
				}
			} elseif ( $linked > 0 ) {
				if ( ! $parent_id || $linked !== $parent_id ) {
					continue;
				}
			} else {
				continue;
			}
			$index = $line->get_meta( self::INDEX );
			if ( '' === (string) $index || isset( $lines[ (int) $index ] ) ) {
				$extra[] = $key;
				continue;
			}
			$lines[ (int) $index ] = $line;
			$keys[ (int) $index ]  = $key;
		}

		return array(
			'lines' => $lines,
			'keys'  => $keys,
			'extra' => $extra,
		);
	}

	/**
	 * Remove the component lines belonging to a bundle line (queued; applied on save).
	 *
	 * Matches by the bundle line's uid or item id; lines without either link (split
	 * before 1.4.7) are matched by bundle product only when this is the sole line of
	 * that bundle in the order.
	 *
	 * @param WC_Order              $order       Order.
	 * @param WC_Order_Item_Product $item        Bundle line item.
	 * @param bool                  $legacy_only True to leave linked lines alone and only
	 *                                           drop the legacy unlinked ones.
	 */
	public static function remove_split_lines( $order, $item, $legacy_only = false ) {
		$parent_id = (int) $item->get_id();
		$bundle_id = (int) $item->get_product_id();
		$uid       = (string) $item->get_meta( self::LINE_UID );

		$siblings = 0;
		foreach ( $order->get_items() as $line ) {
			$components = $line->get_meta( '_oc_bundle_components' );
			if ( ! empty( $components ) && is_array( $components ) && (int) $line->get_product_id() === $bundle_id ) {
				$siblings++;
			}
		}

		foreach ( $order->get_items() as $line_id => $line ) {
			if ( '' === (string) $line->get_meta( self::MARKER ) ) {
				continue;
			}
			$line_uid = (string) $line->get_meta( self::PARENT );
			if ( '' !== $line_uid ) {
				if ( ! $legacy_only && '' !== $uid && $line_uid === $uid ) {
					$order->remove_item( $line_id );
				}
				continue;
			}
			$linked = (int) $line->get_meta( self::PARENT_ITEM );
			if ( $linked > 0 ) {
				if ( ! $legacy_only && $parent_id && $linked === $parent_id ) {
					$order->remove_item( $line_id );
				}
				continue;
			}
			if ( 1 === $siblings && (int) $line->get_meta( self::MARKER ) === $bundle_id ) {
				$order->remove_item( $line_id );
			}
		}
	}

	/**
	 * Turn one bundle line into a bundle line + component lines.
	 *
	 * @param WC_Order              $order          Order.
	 * @param WC_Order_Item_Product $item           Bundle line item.
	 * @param array                 $components     Resolved components.
	 * @param int                   $parent_item_id Item id of the bundle line (0 when not yet saved).
	 * @param array                 $existing       Component lines this bundle line already owns,
	 *                                              keyed by component index: updated in place
	 *                                              instead of being recreated.
	 * @return array The entries of `$existing` that were NOT reused (index => line); the
	 *               caller removes them.
	 */
	public static function split_item( $order, $item, $components, $parent_item_id = 0, $existing = array() ) {
		$bundle_qty = max( 1, (int) $item->get_quantity() );
		$selection  = $item->get_meta( '_oc_bundle_selection' );
		$selection  = is_array( $selection ) ? $selection : array();
		$decimals   = wc_get_price_decimals();
		$existing   = is_array( $existing ) ? $existing : array();

		// Weighed line totals, when present: the lines are named after what actually ships.
		$actual = $item->get_meta( OC_Bundles_Order::ACTUAL );
		$actual = is_array( $actual ) ? $actual : array();

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
					$amount += OC_Bundles_Source_Factory::get( $pid )->price_for_qty( $priced['qty'], OC_Bundles_Helpers::component_unit_weight_kg( $priced, $pid ) ) * $discount;
				}
			}

			$amounts[ $index ] = round( $amount * $bundle_qty, $decimals );
		}

		$moved           = round( array_sum( $amounts ), $decimals );
		$bundle_total    = (float) $item->get_total();
		$bundle_subtotal = (float) $item->get_subtotal();

		$cap = min( $bundle_total, $bundle_subtotal );

		if ( $share_prices && $moved > 0 && 'yes' === $item->get_meta( OC_Bundles_Order::EXTERNAL_PRICE ) ) {
			// An external system (Giorgio) owns this line's total. In `sum` mode the
			// catalogue shares only give the proportions: the money on the component lines
			// must add up to exactly what was pushed, whether that is below or above the
			// catalogue sum (re-weighed on the picking floor, discounted, ...).
			if ( $cap <= 0 ) {
				$amounts = array_fill_keys( array_keys( $amounts ), 0 );
			} else {
				$scale = $cap / $moved;
				foreach ( $amounts as $index => $amount ) {
					$amounts[ $index ] = round( $amount * $scale, $decimals );
				}
				// Rounding drift lands on the last priced component so Σ equals the total.
				$drift = round( $cap - array_sum( $amounts ), $decimals );
				if ( 0.0 !== $drift ) {
					foreach ( array_reverse( array_keys( $amounts ), true ) as $index ) {
						if ( $amounts[ $index ] > 0 ) {
							$amounts[ $index ] = round( $amounts[ $index ] + $drift, $decimals );
							break;
						}
					}
				}
			}
			$moved = round( array_sum( $amounts ), $decimals );
		} elseif ( $moved > $cap ) {
			// Never let the component lines outrun the line they came from — a coupon can
			// discount the bundle below its own parts, and `sum` mode lands near the whole
			// line total where rounding alone can tip it over. Scale to fit instead of
			// distorting the order.
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

		// The bundle line's uid links its component lines to it whether or not it has an
		// item id yet; backfill_parent_ids() adds the item id after the save.
		$uid = (string) $item->get_meta( self::LINE_UID );
		if ( '' === $uid ) {
			$uid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ocb', true );
			$item->update_meta_data( self::LINE_UID, $uid );
		}
		$qty_lines = false;

		foreach ( $components as $index => $component ) {
			$reuse      = isset( $existing[ $index ] ) && $existing[ $index ] instanceof WC_Order_Item_Product ? $existing[ $index ] : null;
			$actual_qty = ( isset( $actual[ $index ] ) && '' !== (string) $actual[ $index ] ) ? max( 0, (float) $actual[ $index ] ) : null;

			$line = self::build_component_line( $component, $bundle_qty, $item->get_product_id(), $tax_class, $index, $parent_item_id, $actual_qty, $reuse, $uid );
			if ( ! $line ) {
				continue; // Product gone: an existing line for this slot stays in `$existing` and is removed by the caller.
			}

			$amount = isset( $amounts[ $index ] ) ? $amounts[ $index ] : 0;
			$line->set_subtotal( $amount );
			$line->set_total( $amount );

			// Hand this component its share of the tax that left the bundle line (and
			// clear whatever a reused line carried from the previous split).
			$taxes = array();
			if ( $amount > 0 && $moved > 0 && ! empty( $moved_taxes ) ) {
				$share = $amount / $moved;
				foreach ( $moved_taxes as $rate_id => $tax_amount ) {
					$taxes[ $rate_id ] = round( $tax_amount * $share, $decimals );
				}
			}
			$line->set_taxes( array( 'total' => $taxes, 'subtotal' => $taxes ) );

			// Kept so a re-weigh on the order screen that must not move money can put the
			// line back (OC_Bundles_Order::restore_amounts) - to THIS amount, which for a
			// Giorgio-priced line is the amount Giorgio pushed.
			$line->add_meta_data( self::AMOUNT, $amount, true );
			$line->add_meta_data( self::TAXES, $line->get_taxes(), true );

			$qty_lines = $qty_lines || (bool) $line->get_meta( self::QTY_LINE );

			if ( $reuse ) {
				unset( $existing[ $index ] );
			} else {
				$order->add_item( $line );
			}
		}

		// Tells OC_Bundles_Order these lines are the weighed amounts, even after one is removed.
		if ( $qty_lines ) {
			$item->update_meta_data( self::QTY_LINES, 1 );
		} elseif ( '' !== (string) $item->get_meta( self::QTY_LINES ) ) {
			$item->delete_meta_data( self::QTY_LINES );
		}

		return $existing;
	}

	/**
	 * Build one component line item (deliberately product-less — see class docblock),
	 * or refresh an existing one in place.
	 *
	 * @param array                      $component      Resolved component.
	 * @param int                        $bundle_qty     Number of bundles ordered.
	 * @param int                        $bundle_id      Parent bundle product ID.
	 * @param string                     $tax_class      Tax class inherited from the bundle line.
	 * @param int                        $index          Component index.
	 * @param int                        $parent_item_id Item id of the bundle line (0 when not yet saved).
	 * @param float|null                 $actual_qty     Weighed line total for this slot (in the
	 *                                                   component's unit); null = ordered quantity.
	 * @param WC_Order_Item_Product|null $line           Existing line to refresh instead of creating one.
	 * @param string                     $uid            LINE_UID of the bundle line.
	 * @return WC_Order_Item_Product|null
	 */
	protected static function build_component_line( $component, $bundle_qty, $bundle_id, $tax_class, $index = null, $parent_item_id = 0, $actual_qty = null, $line = null, $uid = '' ) {
		$component = OC_Bundles_Helpers::normalize_component( $component, $index );
		$pid       = OC_Bundles_Helpers::effective_id( $component );
		$product   = $pid ? wc_get_product( $pid ) : false;
		if ( ! $product ) {
			return null;
		}

		// What actually ships: the weighed line total when there is one, otherwise the
		// per-bundle quantity times the number of bundles.
		$qty = ( null !== $actual_qty ) ? (float) $actual_qty : (float) $component['qty'] * $bundle_qty;

		if ( ! $line instanceof WC_Order_Item_Product ) {
			$line = new WC_Order_Item_Product();
		}
		$line->set_tax_class( $tax_class );
		$line->add_meta_data( self::MARKER, (int) $bundle_id, true );
		if ( null !== $index ) {
			$line->add_meta_data( self::INDEX, (int) $index, true );
		}
		if ( '' !== $uid ) {
			$line->add_meta_data( self::PARENT, $uid, true );
		}
		if ( (int) $parent_item_id > 0 ) {
			$line->add_meta_data( self::PARENT_ITEM, (int) $parent_item_id, true );
		}

		$unit_key = __( 'Unit', 'oc-bundles' );
		if ( self::quantity_fits( $qty ) ) {
			// An ordinary line: the product's name, the quantity in the quantity column and the
			// unit beside it, so re-weighing is editing the quantity like on any other line.
			$line->set_name( $product->get_name() );
			$line->set_quantity( $qty );
			$line->add_meta_data( self::QTY_LINE, 1, true );
			$line->add_meta_data( $unit_key, OC_Bundles_Helpers::display_suffix( $component['unit'], $component['unit_label'] ), true );
		} else {
			// This store keeps whole-number line quantities (no weighable-products plugin), so a
			// fraction cannot live in the quantity: name it instead, as before 1.4.7.
			$scaled        = $component;
			$scaled['qty'] = $qty;
			$line->set_name( trim( OC_Bundles_Helpers::quantity_label( $scaled ) . ' ' . $product->get_name() ) );
			$line->set_quantity( 1 );
			if ( '' !== (string) $line->get_meta( self::QTY_LINE ) ) {
				$line->delete_meta_data( self::QTY_LINE );
			}
			if ( '' !== (string) $line->get_meta( $unit_key ) ) {
				$line->delete_meta_data( $unit_key );
			}
		}

		// Visible metas follow the product now in the slot (it may have been swapped
		// since the line was first split).
		$sku_key  = __( 'SKU', 'oc-bundles' );
		$note_key = __( 'Note', 'oc-bundles' );
		if ( $product->get_sku() ) {
			$line->add_meta_data( $sku_key, $product->get_sku(), true );
		} elseif ( '' !== (string) $line->get_meta( $sku_key ) ) {
			$line->delete_meta_data( $sku_key );
		}
		if ( '' !== $component['description'] ) {
			$line->add_meta_data( $note_key, $component['description'], true );
		} elseif ( '' !== (string) $line->get_meta( $note_key ) ) {
			$line->delete_meta_data( $note_key );
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
		$item_id   = (int) $item->get_id();
		foreach ( $order->get_items() as $line ) {
			if ( (int) $line->get_meta( self::MARKER ) !== $bundle_id ) {
				continue;
			}
			// Linked lines belong to exactly one bundle line; unlinked ones (split before
			// 1.5.0) can only be matched by product.
			$linked = (string) $line->get_meta( self::PARENT );
			if ( '' === $linked || ( $item_id && (int) $linked === $item_id ) ) {
				return '';
			}
		}

		return $formatted;
	}
}
