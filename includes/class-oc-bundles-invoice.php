<?php
/**
 * Invoice display — optionally break a bundle order line into the bundle itself
 * plus one read-only line per component.
 *
 * With `invoice_display = components` the order shows:
 *
 *   Bundle name          x1     100.00   <- base price, without swap surcharges
 *     5 pcs Entrecote                    <- included in the bundle, no amount
 *     1 kg Lamb chops             5.00   <- this component's swap surcharge
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

	/** @var string Order item ID of the bundle line this component line was split from. */
	const PARENT = '_oc_bundle_parent_item';

	/** @var string Temporary link used while the bundle line has no item id yet (on the bundle line). */
	const TOKEN = '_oc_bundle_split_token';

	/** @var string Temporary link used while the bundle line has no item id yet (on the component line). */
	const PARENT_TOKEN = '_oc_bundle_parent_token';


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
	 *  1. By token — split_item() stamps a shared token on the bundle line and its
	 *     component lines whenever the bundle line is still unsaved. Deterministic.
	 *  2. Legacy lines (split before 1.5.0, no token) by bundle product and order of
	 *     appearance: the lines of one bundle line carry ascending component indexes,
	 *     so an index that does not increase starts the next bundle line of the same
	 *     product. Bundle lines that already have linked children are skipped.
	 * Lines that already carry the link are left alone, so this is safe to repeat.
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

		$by_token = array(); // token => bundle line.
		$parents  = array(); // bundle product id => bundle line item ids without linked children, in order.
		$linked   = array(); // bundle line item ids that already have linked children.
		$orphans  = array(); // bundle product id => component lines lacking the link, in order.
		$tokened  = array(); // component lines carrying a parent token.
		$bundles  = array(); // bundle lines, in order.

		foreach ( $order->get_items() as $line ) {
			if ( ! $line instanceof WC_Order_Item_Product || ! $line->get_id() ) {
				continue;
			}
			$marker = (string) $line->get_meta( self::MARKER );
			if ( '' !== $marker ) {
				$parent = (string) $line->get_meta( self::PARENT );
				if ( '' !== $parent ) {
					$linked[ (int) $parent ] = true;
					continue;
				}
				if ( '' !== (string) $line->get_meta( self::PARENT_TOKEN ) ) {
					$tokened[] = $line;
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
			$token     = (string) $line->get_meta( self::TOKEN );
			if ( '' !== $token ) {
				$by_token[ $token ] = $line;
			}
		}

		if ( empty( $tokened ) && empty( $orphans ) ) {
			return;
		}

		// Pass 1: tokens.
		$resolved_parents = array();
		foreach ( $tokened as $line ) {
			$token = (string) $line->get_meta( self::PARENT_TOKEN );
			if ( ! isset( $by_token[ $token ] ) ) {
				// Parent gone (removed before saving): nothing to link to.
				continue;
			}
			$parent_id = (int) $by_token[ $token ]->get_id();
			$line->update_meta_data( self::PARENT, $parent_id );
			$line->delete_meta_data( self::PARENT_TOKEN );
			$line->save();
			$linked[ $parent_id ]           = true;
			$resolved_parents[ $parent_id ] = $by_token[ $token ];
		}
		foreach ( $resolved_parents as $parent ) {
			$parent->delete_meta_data( self::TOKEN );
			$parent->save();
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
				$line->update_meta_data( self::PARENT, (int) $ids[ $pos ] );
				$line->save();
			}
		}
	}

	/**
	 * Re-do the invoice split for one bundle line, IN MEMORY. When the bundle is
	 * configured for a component breakdown, the component lines it already owns (linked
	 * by item id, or by token while the bundle line is unsaved) are updated IN PLACE and
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
	 * The component lines a bundle line owns through an explicit link — its item id on
	 * `_oc_bundle_parent_item`, or its split token on `_oc_bundle_parent_token` while it
	 * is unsaved — keyed by component index, together with their order-item keys.
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
		$token     = (string) $item->get_meta( self::TOKEN );
		$lines     = array();
		$keys      = array();
		$extra     = array();

		foreach ( $order->get_items() as $key => $line ) {
			if ( (int) $line->get_meta( self::MARKER ) !== $bundle_id ) {
				continue;
			}
			$linked     = (string) $line->get_meta( self::PARENT );
			$line_token = (string) $line->get_meta( self::PARENT_TOKEN );
			if ( '' !== $linked ) {
				if ( ! $parent_id || (int) $linked !== $parent_id ) {
					continue;
				}
			} elseif ( '' !== $line_token ) {
				if ( '' === $token || $line_token !== $token ) {
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
	 * Matches by parent item id (or by token while the bundle line is unsaved); lines
	 * without either link (split before 1.5.0) are matched by bundle product only when
	 * this is the sole line of that bundle in the order.
	 *
	 * @param WC_Order              $order       Order.
	 * @param WC_Order_Item_Product $item        Bundle line item.
	 * @param bool                  $legacy_only True to leave linked lines alone and only
	 *                                           drop the legacy unlinked ones.
	 */
	public static function remove_split_lines( $order, $item, $legacy_only = false ) {
		$parent_id = (int) $item->get_id();
		$bundle_id = (int) $item->get_product_id();
		$token     = (string) $item->get_meta( self::TOKEN );

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
			$linked = (string) $line->get_meta( self::PARENT );
			if ( '' !== $linked ) {
				if ( ! $legacy_only && $parent_id && (int) $linked === $parent_id ) {
					$order->remove_item( $line_id );
				}
				continue;
			}
			$line_token = (string) $line->get_meta( self::PARENT_TOKEN );
			if ( '' !== $line_token ) {
				if ( ! $legacy_only && '' !== $token && $line_token === $token ) {
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

		// No item id yet (unsaved bundle line): link through a shared token that
		// backfill_parent_ids() turns into the real item id after the save.
		$token = '';
		if ( (int) $parent_item_id <= 0 ) {
			$token = (string) $item->get_meta( self::TOKEN );
			if ( '' === $token ) {
				$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ocb', true );
				$item->update_meta_data( self::TOKEN, $token );
			}
		}

		foreach ( $components as $index => $component ) {
			$reuse      = isset( $existing[ $index ] ) && $existing[ $index ] instanceof WC_Order_Item_Product ? $existing[ $index ] : null;
			$actual_qty = ( isset( $actual[ $index ] ) && '' !== (string) $actual[ $index ] ) ? max( 0, (float) $actual[ $index ] ) : null;

			$line = self::build_component_line( $component, $bundle_qty, $item->get_product_id(), $tax_class, $index, $parent_item_id, $actual_qty, $reuse );
			if ( ! $line ) {
				continue; // Product gone: an existing line for this slot stays in `$existing` and is removed by the caller.
			}
			if ( '' !== $token ) {
				$line->add_meta_data( self::PARENT_TOKEN, $token, true );
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

			if ( $reuse ) {
				unset( $existing[ $index ] );
			} else {
				$order->add_item( $line );
			}
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
	 * @return WC_Order_Item_Product|null
	 */
	protected static function build_component_line( $component, $bundle_qty, $bundle_id, $tax_class, $index = null, $parent_item_id = 0, $actual_qty = null, $line = null ) {
		$component = OC_Bundles_Helpers::normalize_component( $component, $index );
		$pid       = OC_Bundles_Helpers::effective_id( $component );
		$product   = $pid ? wc_get_product( $pid ) : false;
		if ( ! $product ) {
			return null;
		}

		// Show what actually ships: the weighed line total when there is one, otherwise
		// the per-bundle quantity times the number of bundles.
		$scaled        = $component;
		$scaled['qty'] = ( null !== $actual_qty ) ? (float) $actual_qty : (float) $component['qty'] * $bundle_qty;
		$label         = OC_Bundles_Helpers::quantity_label( $scaled );

		if ( ! $line instanceof WC_Order_Item_Product ) {
			$line = new WC_Order_Item_Product();
		}
		$line->set_name( trim( $label . ' ' . $product->get_name() ) );
		$line->set_quantity( 1 );
		$line->set_tax_class( $tax_class );
		$line->add_meta_data( self::MARKER, (int) $bundle_id, true );
		if ( null !== $index ) {
			$line->add_meta_data( self::INDEX, (int) $index, true );
		}
		if ( (int) $parent_item_id > 0 ) {
			$line->add_meta_data( self::PARENT, (int) $parent_item_id, true );
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
