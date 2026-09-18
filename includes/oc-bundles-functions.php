<?php
/**
 * Public functions for external integrations (the Giorgio connector).
 *
 * These are the only supported entry points for another plugin to read a bundle
 * order line or to create / update one. Everything they write goes through the
 * same metas the cart path writes (`_oc_bundle_components`, `_oc_bundle_selection`,
 * `_oc_bundle_actual`), so stock (OC_Bundles_Order) and the invoice split
 * (OC_Bundles_Invoice) treat those lines exactly like lines that came from the shop.
 *
 * Save semantics: oc_bundles_add_order_line() and oc_bundles_update_order_line() —
 * when handed a WC_Order — work ONLY on that in-memory instance (the bundle line, its
 * component lines, the stock ledger) and never save or reload it; the caller saves.
 * Component lines added to an unsaved bundle line are linked to it through a token
 * that OC_Bundles_Invoice resolves into `_oc_bundle_parent_item` on the order save.
 *
 * Removing a line: oc_bundles_release_order_line() hands the component stock a bundle
 * line has taken (its `_oc_bundle_reduced` ledger) back before the caller removes the
 * line from the order — WooCommerce's own stock hooks never see bundle components.
 *
 * Functions:
 *   oc_bundles_is_bundle_product( $product_or_id ): bool
 *   oc_bundles_is_bundle_order_item( $item ): bool
 *   oc_bundles_is_component_line( $item ): bool
 *   oc_bundles_get_order_item_bundle_data( WC_Order_Item_Product $item ): ?array
 *   oc_bundles_add_order_line( WC_Order $order, int $bundle_product_id, float $bundle_qty, array $spec = array() ): WC_Order_Item_Product|WP_Error
 *   oc_bundles_update_order_line( WC_Order_Item_Product $item, array $spec = array(), ?WC_Order $order = null ): true|WP_Error
 *   oc_bundles_release_order_line( WC_Order $order, WC_Order_Item_Product $item ): void
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether a product is a bundle.
 *
 * @param WC_Product|int $product_or_id Product object or ID.
 * @return bool
 */
function oc_bundles_is_bundle_product( $product_or_id ): bool {
	if ( $product_or_id instanceof WC_Product ) {
		return $product_or_id->is_type( OC_BUNDLES_PRODUCT_TYPE );
	}
	if ( is_numeric( $product_or_id ) ) {
		$product_or_id = absint( $product_or_id );
	}
	$product = $product_or_id ? wc_get_product( $product_or_id ) : false;
	return $product && $product->is_type( OC_BUNDLES_PRODUCT_TYPE );
}

/**
 * Whether an order item is a bundle line (carries `_oc_bundle_components`).
 *
 * @param mixed $item Order item.
 * @return bool
 */
function oc_bundles_is_bundle_order_item( $item ): bool {
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return false;
	}
	$components = $item->get_meta( '_oc_bundle_components' );
	return ! empty( $components ) && is_array( $components );
}

/**
 * Whether an order item is a component line produced by the invoice split
 * (carries `_oc_bundle_component_of`).
 *
 * @param mixed $item Order item.
 * @return bool
 */
function oc_bundles_is_component_line( $item ): bool {
	if ( ! $item instanceof WC_Order_Item ) {
		return false;
	}
	return '' !== (string) $item->get_meta( OC_Bundles_Invoice::MARKER );
}

/**
 * The bundle data of an order line, in the shape of the connector's `bundle` object
 * (snake_case keys). Null for lines that are not bundle lines.
 *
 * `base_price` is the per-bundle price after the bundle discount and before swap
 * surcharges; nothing here folds the totals of split component lines into anything.
 *
 * @param WC_Order_Item_Product $item Bundle order line.
 * @return array|null
 */
function oc_bundles_get_order_item_bundle_data( WC_Order_Item_Product $item ): ?array {
	$components = $item->get_meta( '_oc_bundle_components' );
	if ( empty( $components ) || ! is_array( $components ) ) {
		return null;
	}

	$bundle_id = (int) $item->get_product_id();
	$config    = OC_Bundles_Helpers::get_config( $bundle_id );

	$originals = array();
	foreach ( $config['components'] as $i => $c ) {
		$originals[ $i ] = OC_Bundles_Helpers::normalize_component( $c, $i );
	}

	$selection = $item->get_meta( '_oc_bundle_selection' );
	$selection = is_array( $selection ) ? $selection : array();
	$actual    = $item->get_meta( OC_Bundles_Order::ACTUAL );
	$actual    = is_array( $actual ) ? $actual : array();

	$external_id = OC_Bundles_Helpers::external_id( $bundle_id );
	if ( '' === $external_id ) {
		$external_id = (string) $item->get_meta( OC_Bundles_Helpers::EXTERNAL_ID_META );
	}

	$out = array();
	foreach ( $components as $index => $component ) {
		$component = OC_Bundles_Helpers::normalize_component( $component, $index );
		$pid       = OC_Bundles_Helpers::effective_id( $component );
		$product   = $pid ? wc_get_product( $pid ) : false;

		$swapped_from_product   = null;
		$swapped_from_variation = null;
		if ( isset( $originals[ $index ] ) && OC_Bundles_Helpers::effective_id( $originals[ $index ] ) !== $pid ) {
			$swapped_from_product   = (int) $originals[ $index ]['product_id'];
			$swapped_from_variation = (int) $originals[ $index ]['variation_id'];
		}

		$entry     = ( isset( $selection[ $index ] ) && is_array( $selection[ $index ] ) ) ? $selection[ $index ] : array();
		$surcharge = isset( $entry['surcharge'] ) ? (float) $entry['surcharge'] : 0.0;

		$actual_qty = null;
		if ( isset( $actual[ $index ] ) && '' !== (string) $actual[ $index ] ) {
			$actual_qty = (float) $actual[ $index ];
		}

		$out[] = array(
			'index'                     => (int) $index,
			'key'                       => $component['key'],
			'product_id'                => (int) $component['product_id'],
			'variation_id'              => (int) $component['variation_id'],
			'sku'                       => $product ? (string) $product->get_sku() : '',
			'name'                      => $product ? $product->get_name() : '',
			'qty'                       => (float) $component['qty'],
			'unit'                      => $component['unit'],
			'mode'                      => $component['mode'],
			'unit_weight'               => ( (float) $component['unit_weight'] > 0 ) ? (float) $component['unit_weight'] : null,
			'swapped_from_product_id'   => $swapped_from_product,
			'swapped_from_variation_id' => $swapped_from_variation,
			'surcharge'                 => $surcharge,
			'actual_qty'                => $actual_qty,
			'description'               => $component['description'],
		);
	}

	return array(
		'external_id'     => ( '' !== $external_id ) ? $external_id : null,
		'woo_product_id'  => $bundle_id,
		'item_id'         => (int) $item->get_id(),
		'pricing_mode'    => $config['pricing_mode'],
		'reweigh_price'   => ( 'yes' === $config['reweigh_price'] ),
		'invoice_display' => $config['invoice_display'],
		'base_price'      => (float) OC_Bundles_Pricing::base_price( $bundle_id, $config['components'], $config ),
		'components'      => $out,
	);
}

/**
 * Add a bundle line to an order, in memory (the caller saves the order).
 *
 * The line is built exactly like the cart path builds it: product, quantity, name,
 * the technical metas and the visible "What's in the bundle" meta. Component stock is
 * reconciled on the order instance and, for `invoice_display = components`, the
 * component lines are added to the same instance. Nothing is saved here, so the
 * returned line has no id until the caller saves the order (`$line->get_id()` then).
 *
 * @param WC_Order $order             Order (in-memory instance the caller will save).
 * @param int      $bundle_product_id Bundle product ID.
 * @param float    $bundle_qty        Number of bundles (whole number).
 * @param array    $spec              See oc_bundles_update_order_line().
 * @return WC_Order_Item_Product|WP_Error The created bundle line, or an error.
 */
function oc_bundles_add_order_line( WC_Order $order, int $bundle_product_id, float $bundle_qty, array $spec = array() ) {
	$product = $bundle_product_id ? wc_get_product( $bundle_product_id ) : false;
	if ( ! $product || ! $product->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
		return new WP_Error( 'oc_bundles_not_bundle', __( 'The product is not a bundle.', 'oc-bundles' ) );
	}

	$item = new WC_Order_Item_Product();
	$item->set_product( $product );
	$item->set_quantity( max( 1, (int) round( $bundle_qty ) ) );

	// A new line has taken nothing from stock yet. Start its ledger empty so
	// OC_Bundles_Order::ledger() never seeds it from the legacy order-level flag.
	$item->update_meta_data( OC_Bundles_Order::LEDGER, array() );

	$result = _oc_bundles_apply_line_spec( $item, $product, $spec, true );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$order->add_item( $item );
	_oc_bundles_finish_line( $order, $item );

	return $item;
}

/**
 * Update an existing bundle line from a spec.
 *
 * `$spec` (every key optional):
 *
 *   'components' => array(   // one entry per slot that deviates from config OR to supply an actual qty
 *       array( 'index' => 1, 'key' => 'c12', 'product_id' => 2313, 'variation_id' => 0, 'surcharge' => 30.0, 'actual_qty' => 0.6 ),
 *   ),
 *   'line_total'  => 458.0,  // when given: subtotal + total are set to it and the plugin never re-prices the line
 *   'external_id' => 'george-10',
 *
 * A slot is matched by 'index' (position in the bundle config) or by 'key'. A slot whose
 * product differs from the configured one is a swap: `_oc_bundle_selection[index]` gets
 * the configured swap's index when the product is one of the configured alternatives,
 * otherwise `swap_index = -2` with the product and surcharge carried on the entry itself.
 * 'actual_qty' is the weighed line total for that slot (all bundles); null clears it.
 *
 * With `$order` given, everything happens on that in-memory instance (the item is
 * mutated in place, component lines are added to it, nothing is saved or reloaded).
 * Without it the item's order is loaded, updated, re-totalled and saved.
 *
 * @param WC_Order_Item_Product $item  Bundle order line.
 * @param array                 $spec  Spec (see above).
 * @param WC_Order|null         $order The order instance that holds `$item`, when the caller has one.
 * @return true|WP_Error
 */
function oc_bundles_update_order_line( WC_Order_Item_Product $item, array $spec = array(), ?WC_Order $order = null ) {
	if ( ! oc_bundles_is_bundle_order_item( $item ) ) {
		return new WP_Error( 'oc_bundles_not_bundle_line', __( 'The order line is not a bundle.', 'oc-bundles' ) );
	}

	$product = $item->get_product_id() ? wc_get_product( $item->get_product_id() ) : false;
	if ( ! $product || ! $product->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
		return new WP_Error( 'oc_bundles_not_bundle', __( 'The product is not a bundle.', 'oc-bundles' ) );
	}

	$owned = ( null !== $order );
	if ( ! $owned ) {
		$order = $item->get_order();
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'oc_bundles_order_not_found', __( 'Order not found.', 'oc-bundles' ) );
		}
	}

	$result = _oc_bundles_apply_line_spec( $item, $product, $spec, false );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Make sure the instance we work on holds THIS item object (a freshly loaded order
	// would otherwise carry its own copy of the line).
	$order->add_item( $item );
	_oc_bundles_finish_line( $order, $item );

	if ( ! $owned ) {
		// Line totals changed: re-derive taxes and order totals, then persist everything
		// (removed component lines, new ones, the ledger) in one save.
		$order->calculate_totals( true );
	}

	return true;
}

/**
 * Write the bundle metas and the line price onto an order line from a spec.
 *
 * @internal
 *
 * @param WC_Order_Item_Product $item    Line (new or existing).
 * @param WC_Product            $product Bundle product.
 * @param array                 $spec    Spec.
 * @param bool                  $is_new  True when the line is being created.
 * @return true|WP_Error
 */
function _oc_bundles_apply_line_spec( $item, $product, $spec, $is_new ) {
	$bundle_id = (int) $product->get_id();
	$config    = OC_Bundles_Helpers::get_config( $bundle_id );
	$decimals  = wc_get_price_decimals();

	$originals = array();
	foreach ( $config['components'] as $i => $c ) {
		$originals[ $i ] = OC_Bundles_Helpers::normalize_component( $c, $i );
	}
	if ( empty( $originals ) ) {
		return new WP_Error( 'oc_bundles_no_components', __( 'The bundle has no components.', 'oc-bundles' ) );
	}

	// Slot overrides from the spec, keyed by slot index (last one wins).
	$overrides = array();
	if ( ! empty( $spec['components'] ) && is_array( $spec['components'] ) ) {
		foreach ( $spec['components'] as $o ) {
			if ( ! is_array( $o ) ) {
				continue;
			}
			$slot = null;
			if ( isset( $o['index'] ) && '' !== (string) $o['index'] && isset( $originals[ (int) $o['index'] ] ) ) {
				$slot = (int) $o['index'];
			} elseif ( isset( $o['key'] ) && '' !== (string) $o['key'] ) {
				$key = OC_Bundles_Helpers::sanitize_key_string( $o['key'] );
				foreach ( $originals as $i => $orig ) {
					if ( $orig['key'] === $key ) {
						$slot = $i;
						break;
					}
				}
			}
			if ( null === $slot ) {
				continue;
			}
			$overrides[ $slot ] = $o;
		}
	}

	$actual = array();
	if ( ! $is_new ) {
		$existing = $item->get_meta( OC_Bundles_Order::ACTUAL );
		$actual   = is_array( $existing ) ? $existing : array();
	}

	$components = array();
	$selection  = array();

	foreach ( $originals as $index => $orig ) {
		$resolved = $orig;

		if ( isset( $overrides[ $index ] ) ) {
			$o   = $overrides[ $index ];
			$pid = isset( $o['product_id'] ) ? absint( $o['product_id'] ) : 0;
			$vid = isset( $o['variation_id'] ) ? absint( $o['variation_id'] ) : 0;

			if ( ! $pid && $vid ) {
				$variation = wc_get_product( $vid );
				if ( $variation && $variation->is_type( 'variation' ) ) {
					$pid = (int) $variation->get_parent_id();
				}
			}

			$swapped = $pid && ! ( $pid === (int) $orig['product_id'] && ( 0 === $vid || $vid === (int) $orig['variation_id'] ) );

			if ( $swapped ) {
				if ( ! wc_get_product( $vid ? $vid : $pid ) ) {
					return new WP_Error(
						'oc_bundles_invalid_component',
						/* translators: %d is a product ID. */
						sprintf( __( 'Component product %d does not exist.', 'oc-bundles' ), $vid ? $vid : $pid ),
						array( 'status' => 400 )
					);
				}

				$k = OC_Bundles_Helpers::find_swap_index( $orig, $pid, $vid );
				if ( isset( $o['surcharge'] ) && '' !== (string) $o['surcharge'] ) {
					$surcharge = (float) $o['surcharge'];
				} else {
					$surcharge = ( $k >= 0 ) ? (float) $orig['swaps'][ $k ]['surcharge'] : 0.0;
				}

				// Same shape OC_Bundles_Cart::resolve_components() gives a swapped slot.
				$resolved = array(
					'key'          => $orig['key'],
					'product_id'   => $pid,
					'variation_id' => $vid,
					'qty'          => $orig['qty'],
					'unit'         => $orig['unit'],
					'unit_label'   => $orig['unit_label'],
					'unit_weight'  => $orig['unit_weight'],
					'mode'         => $orig['mode'],
					'swappable'    => 'no',
					'swaps'        => array(),
				);

				if ( $k >= 0 ) {
					$selection[ $index ] = array(
						'swap_index' => $k,
						'surcharge'  => $surcharge,
					);
				} else {
					$selection[ $index ] = array(
						'swap_index'   => -2,
						'product_id'   => $pid,
						'variation_id' => $vid,
						'surcharge'    => $surcharge,
					);
				}
			}

			if ( array_key_exists( 'actual_qty', $o ) ) {
				if ( null === $o['actual_qty'] || '' === (string) $o['actual_qty'] ) {
					unset( $actual[ $index ] );
				} else {
					$actual[ $index ] = max( 0, (float) $o['actual_qty'] );
				}
			}
		}

		$components[ $index ] = $resolved;
	}

	// Price: base (from the ORIGINAL components, after discount) + surcharges of the
	// active swaps, times the number of bundles — unless the caller owns the total.
	$bundle_qty = max( 1, (int) $item->get_quantity() );
	$unit_price = OC_Bundles_Pricing::line_price( $bundle_id, $components, $selection, $config );

	if ( isset( $spec['line_total'] ) && null !== $spec['line_total'] && '' !== (string) $spec['line_total'] ) {
		$total = round( (float) $spec['line_total'], $decimals );
		$item->update_meta_data( OC_Bundles_Order::EXTERNAL_PRICE, 'yes' );
	} else {
		$total = round( $unit_price * $bundle_qty, $decimals );
		if ( '' !== (string) $item->get_meta( OC_Bundles_Order::EXTERNAL_PRICE ) ) {
			$item->delete_meta_data( OC_Bundles_Order::EXTERNAL_PRICE );
		}
	}
	$item->set_subtotal( $total );
	$item->set_total( $total );

	if ( ! $is_new ) {
		_oc_bundles_release_replaced_components( $item, $components );
	}

	// Technical payload, as the cart writes it.
	$item->update_meta_data( '_oc_bundle_components', $components );
	$item->update_meta_data( '_oc_bundle_selection', $selection );
	if ( empty( $actual ) ) {
		if ( '' !== (string) $item->get_meta( OC_Bundles_Order::ACTUAL ) ) {
			$item->delete_meta_data( OC_Bundles_Order::ACTUAL );
		}
	} else {
		$item->update_meta_data( OC_Bundles_Order::ACTUAL, $actual );
	}

	if ( isset( $spec['external_id'] ) && is_scalar( $spec['external_id'] ) ) {
		$external_id = sanitize_text_field( (string) $spec['external_id'] );
		$external_id = function_exists( 'mb_substr' ) ? mb_substr( $external_id, 0, 100 ) : substr( $external_id, 0, 100 );
		if ( '' !== $external_id ) {
			$item->update_meta_data( OC_Bundles_Helpers::EXTERNAL_ID_META, $external_id );
		}
	}

	// Human-readable list for picking / display (the invoice split drops it again
	// when the components become lines of their own).
	$summary = __( "What's in the bundle", 'oc-bundles' );
	$lines   = OC_Bundles_Helpers::components_list_lines( $components );
	if ( ! empty( $lines ) ) {
		$item->update_meta_data( $summary, implode( ' · ', $lines ) );
	} elseif ( '' !== (string) $item->get_meta( $summary ) ) {
		$item->delete_meta_data( $summary );
	}

	return true;
}

/**
 * Before the new component list replaces the old one on an existing line: give back
 * to the OLD product whatever the ledger says this slot took, for every slot whose
 * product changed (a swap) or that no longer exists, and drop that ledger entry.
 * OC_Bundles_Order::reconcile() then sees an empty ledger for the slot and takes the
 * new product's quantity in full instead of only the difference — which would have
 * left the old product short and the new one untouched.
 *
 * @internal
 *
 * @param WC_Order_Item_Product $item       Existing bundle line (old metas still on it).
 * @param array                 $components New resolved components, by slot index.
 */
function _oc_bundles_release_replaced_components( $item, $components ) {
	$ledger = $item->get_meta( OC_Bundles_Order::LEDGER );
	if ( empty( $ledger ) || ! is_array( $ledger ) ) {
		return;
	}
	$old = $item->get_meta( '_oc_bundle_components' );
	$old = is_array( $old ) ? $old : array();

	$changed = false;
	foreach ( $ledger as $index => $taken ) {
		if ( ! isset( $old[ $index ] ) ) {
			// No record of what took it: nothing to give back, but the entry must not
			// count against the product that now fills the slot.
			unset( $ledger[ $index ] );
			$changed = true;
			continue;
		}
		$old_component = OC_Bundles_Helpers::normalize_component( $old[ $index ], $index );
		$old_pid       = OC_Bundles_Helpers::effective_id( $old_component );

		$new_pid = 0;
		if ( isset( $components[ $index ] ) ) {
			$new_pid = OC_Bundles_Helpers::effective_id( OC_Bundles_Helpers::normalize_component( $components[ $index ], $index ) );
		}
		if ( $new_pid && $new_pid === $old_pid ) {
			continue; // Same product in the slot: reconcile() applies the difference only.
		}

		$taken = (float) $taken;
		if ( $old_pid && $taken > 0 ) {
			OC_Bundles_Source_Factory::get( $old_pid )->increment( $taken, $old_component['unit'] );
		}
		unset( $ledger[ $index ] );
		$changed = true;
	}

	if ( $changed ) {
		$item->update_meta_data( OC_Bundles_Order::LEDGER, $ledger );
	}
}

/**
 * After the line itself is written: re-do the invoice split (component lines updated
 * in place, missing ones added, stale ones dropped), then reconcile component stock
 * for the order (in memory). Nothing is saved.
 *
 * The split goes FIRST: OC_Bundles_Order::reconcile() re-prices a `sum` + re-weigh
 * bundle from the weighed quantities, and when the line is split it writes those
 * amounts onto the component lines (found by item id, or by the split token while the
 * line is unsaved). Split lines built from the ordered quantities are therefore
 * corrected by the reconcile that follows; the other way round, the split would
 * re-derive the component amounts from the catalogue and undo the re-weigh.
 *
 * @internal
 *
 * @param WC_Order              $order Order instance.
 * @param WC_Order_Item_Product $item  Bundle line held by `$order`.
 */
function _oc_bundles_finish_line( $order, $item ) {
	OC_Bundles_Invoice::resplit_item( $order, $item );
	OC_Bundles_Order::reconcile( $order, false, false );
}

/**
 * Hand the component stock a bundle line has taken back, in memory, before the line
 * is removed from the order.
 *
 * Only this line's ledger (`_oc_bundle_reduced`) is returned to the component
 * products — the order's other bundle lines are untouched — and the ledger is then
 * cleared (left as an empty array, so a line that is kept after all is re-taken in
 * full by the next reconcile rather than re-seeded from the legacy order flag).
 * Nothing is saved: the caller removes the line and saves the order. Component
 * (split) lines carry no product and need no release.
 *
 * @param WC_Order              $order Order holding the line (the caller's in-memory instance).
 * @param WC_Order_Item_Product $item  Bundle line about to be removed.
 */
function oc_bundles_release_order_line( WC_Order $order, WC_Order_Item_Product $item ): void {
	if ( ! oc_bundles_is_bundle_order_item( $item ) ) {
		return;
	}
	OC_Bundles_Order::release_item( $item );
}
