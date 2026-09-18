<?php
/**
 * Order stock — keep component stock in step with what the order actually takes.
 *
 * Mirrors WooCommerce's own per-line accounting (wc_maybe_adjust_line_item_product_stock):
 * every component keeps a ledger of how much of it this order has already removed from
 * stock, and any change re-reduces only the DIFFERENCE. That is what makes re-weighing
 * work — a component ordered at 0.5 kg and weighed at 0.6 kg gives 0.5 at checkout and a
 * further 0.1 when the order is saved, never 0.6 on top of 0.5.
 *
 * A bundle is a single order line whose quantity counts bundles, not kilos, so there is no
 * line quantity to edit per component the way there is for a plain weighable product. The
 * weighed amount therefore lives in its own meta, set on the order screen or pushed in by
 * an external system, and is expressed as the TOTAL for the line (all bundles together),
 * matching WooCommerce's line-quantity semantics.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Order {

	/** @var string Per-component ledger: index => quantity already removed from stock. */
	const LEDGER = '_oc_bundle_reduced';

	/** @var string Per-component weighed quantities (line totals) overriding the ordered ones. */
	const ACTUAL = '_oc_bundle_actual';

	/** @var string Pre-ledger all-or-nothing flag, kept only to migrate old orders. */
	const LEGACY_FLAG = '_oc_bundle_stock_reduced';

	/** @var string 'yes' when an external system (Giorgio) owns the line price: never re-price on re-weigh. */
	const EXTERNAL_PRICE = '_oc_bundle_external_price';

	public static function init() {
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'reduce' ), 10, 1 );
		add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'restore' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'restore' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'restore' ), 10, 1 );

		// Weighed quantities on the order screen.
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_actuals' ), 10, 2 );
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'save_actuals' ), 10, 2 );
	}

	/* --------------------------------------------------------------------- *
	 * Stock reconciliation
	 * --------------------------------------------------------------------- */

	/**
	 * Bring component stock in line with what the order currently says.
	 *
	 * @param WC_Order|int $order Order.
	 */
	public static function reduce( $order ) {
		self::reconcile( $order, false );
	}

	/**
	 * Put every component back (cancel / refund).
	 *
	 * @param WC_Order|int $order Order.
	 */
	public static function restore( $order ) {
		self::reconcile( $order, true );
	}

	/**
	 * Walk the order's bundle lines and apply the difference between what stock has
	 * already given this order and what it should be giving it now.
	 *
	 * @param WC_Order|int $order   Order. An object is used as-is (its in-memory items),
	 *                              which is what the oc_bundles_*_order_line functions rely on.
	 * @param bool         $release True to hand everything back to stock.
	 * @param bool         $persist False to only update the in-memory items (ledger, totals)
	 *                              and leave saving — and recalculating totals — to the caller.
	 */
	public static function reconcile( $order, $release = false, $persist = true ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$dirty    = false;
		$repriced = false;

		foreach ( $order->get_items() as $item ) {
			$components = $item->get_meta( '_oc_bundle_components' );
			if ( empty( $components ) || ! is_array( $components ) ) {
				continue;
			}

			$bundle_qty = max( 1, (int) $item->get_quantity() );
			$ledger     = self::ledger( $item, $components, $bundle_qty );
			$actual     = $item->get_meta( self::ACTUAL );
			$actual     = is_array( $actual ) ? $actual : array();
			$changed    = false;

			foreach ( $components as $index => $component ) {
				$component = OC_Bundles_Helpers::normalize_component( $component, $index );
				$pid       = OC_Bundles_Helpers::effective_id( $component );
				if ( ! $pid ) {
					continue;
				}

				$want    = $release ? 0.0 : self::wanted( $component, $index, $actual, $bundle_qty );
				$have    = isset( $ledger[ $index ] ) ? (float) $ledger[ $index ] : 0.0;
				$diff    = round( $want - $have, 4 );

				if ( 0.0 === $diff ) {
					continue;
				}

				$source = OC_Bundles_Source_Factory::get( $pid );
				if ( $diff > 0 ) {
					$source->decrement( $diff, $component['unit'] );
				} else {
					$source->increment( -$diff, $component['unit'] );
				}

				$ledger[ $index ] = $want;
				$changed          = true;
			}

			if ( $changed ) {
				$item->update_meta_data( self::LEDGER, $ledger );
				if ( $persist ) {
					$item->save();
				}
				$dirty = true;
			}

			if ( ! $release && self::maybe_reprice( $order, $item, $components, $actual, $bundle_qty, $persist ) ) {
				$repriced = true;
			}
		}

		if ( $repriced && $persist ) {
			// Line totals moved, so let WooCommerce re-derive taxes and the order total
			// from them -- the same thing the "Recalculate" button does.
			$order->calculate_totals( true );
			$dirty = true;
		}

		if ( ! $dirty ) {
			return;
		}

		// The legacy flag only ever meant "this order has taken its stock". The ledger
		// now carries that per component, so retire it once an order has been migrated.
		if ( '' !== (string) $order->get_meta( self::LEGACY_FLAG ) ) {
			$order->delete_meta_data( self::LEGACY_FLAG );
		}
		if ( $persist ) {
			$order->save();
		}
	}

	/**
	 * Re-price a bundle line from the weighed quantities.
	 *
	 * Only for `sum` pricing, and only when the bundle opts in. With a fixed bundle price
	 * the price is the bundle's, not the components', so re-weighing must never move it.
	 *
	 * A plain weighable product re-prices itself when its quantity changes, because the
	 * weighed amount IS the line quantity. A bundle's line quantity counts bundles, so
	 * nothing recalculates on its own and we have to do it here.
	 *
	 * @param WC_Order              $order      Order.
	 * @param WC_Order_Item_Product $item       Bundle line.
	 * @param array                 $components Components.
	 * @param array                 $actual     Weighed quantities by index.
	 * @param int                   $bundle_qty Number of bundles.
	 * @param bool                  $persist    False to change the in-memory lines only.
	 * @return bool True when a total changed.
	 */
	protected static function maybe_reprice( $order, $item, $components, $actual, $bundle_qty, $persist = true ) {
		$bundle_id = $item->get_product_id();
		$config    = OC_Bundles_Helpers::get_config( $bundle_id );

		if ( 'sum' !== $config['pricing_mode'] || 'yes' !== $config['reweigh_price'] ) {
			return false;
		}

		// Nothing weighed yet (checkout / a status change before picking): the line keeps the
		// price the cart charged. Re-pricing here would replace "original price + swap surcharge"
		// with the swapped product's own price x the slot quantity, and the customer would see a
		// different total on the order than on the cart.
		if ( empty( $actual ) ) {
			return false;
		}

		// An external system pushed this line's total (oc_bundles_*_order_line with
		// `line_total`): it owns the price, so re-weighing here must not move it.
		if ( 'yes' === $item->get_meta( self::EXTERNAL_PRICE ) ) {
			return false;
		}

		$decimals = wc_get_price_decimals();

		// The bundle discount is a percentage of the configured basket, so carry it over
		// as a ratio rather than re-applying it to the re-weighed amounts.
		$raw   = OC_Bundles_Pricing::raw_base_price( $bundle_id, $config['components'], $config );
		$ratio = ( $raw > 0 ) ? ( OC_Bundles_Pricing::base_price( $bundle_id, $config['components'], $config ) / $raw ) : 1.0;

		$selection = $item->get_meta( '_oc_bundle_selection' );
		$selection = is_array( $selection ) ? $selection : array();

		$amounts = array();
		$labels  = array();
		foreach ( $components as $index => $component ) {
			$component = OC_Bundles_Helpers::normalize_component( $component, $index );
			$pid       = OC_Bundles_Helpers::effective_id( $component );
			$qty       = self::wanted( $component, $index, $actual, $bundle_qty );

			// The split line was named at checkout from the ordered quantity. Re-weighing
			// changes what it charges, so it has to change what it says as well -- an
			// invoice reading "0.5 kg" while charging for 1 kg is simply wrong.
			$product = $pid ? wc_get_product( $pid ) : false;
			if ( $product ) {
				$scaled          = $component;
				$scaled['qty']   = $qty;
				$labels[ $index ] = trim( OC_Bundles_Helpers::quantity_label( $scaled ) . ' ' . $product->get_name() );
			}

			$amount = 0.0;
			if ( $pid ) {
				$amount = OC_Bundles_Source_Factory::get( $pid )->price_for_qty( $qty, OC_Bundles_Helpers::component_unit_weight_kg( $component, $pid ) ) * $ratio;
			}
			// A swap surcharge is a flat delta per bundle, untouched by the discount.
			if ( isset( $selection[ $index ]['surcharge'] ) ) {
				$amount += (float) $selection[ $index ]['surcharge'] * $bundle_qty;
			}
			$amounts[ $index ] = round( $amount, $decimals );
		}

		$total = round( array_sum( $amounts ), $decimals );

		// When the order was split for the invoice, the money lives on the component
		// lines; otherwise it all sits on the bundle line.
		$split = self::component_lines( $order, $item );

		if ( ! empty( $split ) ) {
			foreach ( $split as $index => $line ) {
				$amount = isset( $amounts[ $index ] ) ? $amounts[ $index ] : 0;
				$label  = isset( $labels[ $index ] ) ? $labels[ $index ] : $line->get_name();
				if ( (float) $line->get_total() === (float) $amount && $line->get_name() === $label ) {
					continue;
				}
				$line->set_name( $label );
				$line->set_subtotal( $amount );
				$line->set_total( $amount );
				if ( $persist ) {
					$line->save();
				}
			}
			$item->set_subtotal( 0 );
			$item->set_total( 0 );
			if ( $persist ) {
				$item->save();
			}
			return true;
		}

		if ( (float) $item->get_total() === $total ) {
			return false;
		}
		$item->set_subtotal( $total );
		$item->set_total( $total );
		if ( $persist ) {
			$item->save();
		}
		return true;
	}

	/**
	 * The invoice-split lines belonging to a bundle line, keyed by component index.
	 *
	 * A bundle line that still carries the split token (unsaved, split in memory) owns
	 * exactly the lines whose parent token equals it. A saved bundle line owns the lines
	 * that carry its item id. Lines with neither link (split before 1.5.0) are matched by
	 * bundle product only for a parent without a token (ambiguous only when the same
	 * bundle appears twice in one order).
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item  Bundle line item.
	 * @return array
	 */
	public static function component_lines( $order, $item ) {
		if ( ! class_exists( 'OC_Bundles_Invoice' ) || ! $item instanceof WC_Order_Item_Product ) {
			return array();
		}
		$bundle_id = (int) $item->get_product_id();
		$parent_id = (int) $item->get_id();
		$token     = (string) $item->get_meta( OC_Bundles_Invoice::TOKEN );
		$lines     = array();
		foreach ( $order->get_items() as $line ) {
			if ( (int) $line->get_meta( OC_Bundles_Invoice::MARKER ) !== $bundle_id ) {
				continue;
			}
			$linked     = (string) $line->get_meta( OC_Bundles_Invoice::PARENT );
			$line_token = (string) $line->get_meta( OC_Bundles_Invoice::PARENT_TOKEN );
			if ( '' !== $linked ) {
				// Saved line: it must carry THIS parent's item id.
				if ( ! $parent_id || (int) $linked !== $parent_id ) {
					continue;
				}
			} elseif ( '' !== $line_token ) {
				// Unsaved line: it must carry THIS parent's token.
				if ( '' === $token || $line_token !== $token ) {
					continue;
				}
			} elseif ( '' !== $token ) {
				// A legacy (unlinked) line can never belong to a parent that is still unsaved.
				continue;
			}
			$index = $line->get_meta( OC_Bundles_Invoice::INDEX );
			if ( '' === (string) $index ) {
				continue;
			}
			$lines[ (int) $index ] = $line;
		}
		return $lines;
	}

	/**
	 * Hand back to component stock everything ONE bundle line has taken, in memory.
	 *
	 * For a line that is about to leave the order (the connector's rebuild). Only this
	 * line is touched — reconcile() walks every bundle line of the order. The ledger is
	 * left as an EMPTY array rather than deleted: should the line survive after all, the
	 * next reconcile() re-takes its stock in full instead of seeding the ledger from the
	 * legacy order flag. Nothing is saved; the caller saves (or removes the line).
	 *
	 * @param WC_Order_Item_Product $item Bundle line.
	 */
	public static function release_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}
		$components = $item->get_meta( '_oc_bundle_components' );
		if ( empty( $components ) || ! is_array( $components ) ) {
			return;
		}

		$bundle_qty = max( 1, (int) $item->get_quantity() );
		$ledger     = self::ledger( $item, $components, $bundle_qty );

		foreach ( $ledger as $index => $taken ) {
			$taken = (float) $taken;
			if ( $taken <= 0 || ! isset( $components[ $index ] ) ) {
				continue;
			}
			$component = OC_Bundles_Helpers::normalize_component( $components[ $index ], $index );
			$pid       = OC_Bundles_Helpers::effective_id( $component );
			if ( ! $pid ) {
				continue;
			}
			OC_Bundles_Source_Factory::get( $pid )->increment( $taken, $component['unit'] );
		}

		$item->update_meta_data( self::LEDGER, array() );
	}

	/**
	 * How much of a component this order should currently be holding.
	 *
	 * @param array $component  Normalized component.
	 * @param int   $index      Component index.
	 * @param array $actual     Weighed quantities (line totals) by index.
	 * @param int   $bundle_qty Number of bundles on the line.
	 * @return float
	 */
	protected static function wanted( $component, $index, $actual, $bundle_qty ) {
		if ( isset( $actual[ $index ] ) && '' !== $actual[ $index ] ) {
			return max( 0, (float) $actual[ $index ] );
		}
		return (float) $component['qty'] * $bundle_qty;
	}

	/**
	 * Read the ledger, migrating orders placed before per-component accounting existed.
	 *
	 * Those orders carry only the old order-level flag, so what stock already gave them is
	 * exactly the ordered quantities — seeding that is what stops a first save after the
	 * upgrade from reducing everything a second time.
	 *
	 * @param WC_Order_Item $item       Bundle line item.
	 * @param array         $components Components.
	 * @param int           $bundle_qty Number of bundles.
	 * @return array
	 */
	protected static function ledger( $item, $components, $bundle_qty ) {
		$ledger = $item->get_meta( self::LEDGER );
		if ( is_array( $ledger ) ) {
			return $ledger;
		}

		// Only a line that was persisted before per-component accounting existed can
		// have taken stock under the legacy flag; a line with no id yet never did.
		$order  = ( (int) $item->get_id() > 0 ) ? $item->get_order() : null;
		$legacy = ( $order instanceof WC_Order ) && 'yes' === $order->get_meta( self::LEGACY_FLAG );

		$ledger = array();
		if ( $legacy ) {
			foreach ( $components as $index => $component ) {
				$component        = OC_Bundles_Helpers::normalize_component( $component );
				$ledger[ $index ] = (float) $component['qty'] * $bundle_qty;
			}
		}
		return $ledger;
	}

	/* --------------------------------------------------------------------- *
	 * Weighed quantities on the order screen
	 * --------------------------------------------------------------------- */

	/**
	 * Render an actual-quantity field per component under the bundle line.
	 *
	 * @param int           $item_id Item ID.
	 * @param WC_Order_Item $item    Item.
	 */
	public static function render_actuals( $item_id, $item ) {
		$components = $item->get_meta( '_oc_bundle_components' );
		if ( empty( $components ) || ! is_array( $components ) ) {
			return;
		}

		$bundle_qty = max( 1, (int) $item->get_quantity() );
		$actual     = $item->get_meta( self::ACTUAL );
		$actual     = is_array( $actual ) ? $actual : array();
		$ledger     = $item->get_meta( self::LEDGER );
		$ledger     = is_array( $ledger ) ? $ledger : array();

		echo '<div class="oc-order-actuals"><strong>' . esc_html__( 'Weighed quantities', 'oc-bundles' ) . '</strong><table class="oc-order-actuals-table">';

		foreach ( $components as $index => $component ) {
			$component = OC_Bundles_Helpers::normalize_component( $component, $index );
			$pid       = OC_Bundles_Helpers::effective_id( $component );
			$product   = $pid ? wc_get_product( $pid ) : false;
			if ( ! $product ) {
				continue;
			}

			$ordered = (float) $component['qty'] * $bundle_qty;
			$value   = isset( $actual[ $index ] ) ? $actual[ $index ] : '';
			$taken   = isset( $ledger[ $index ] ) ? (float) $ledger[ $index ] : 0.0;
			$suffix  = OC_Bundles_Helpers::display_suffix( $component['unit'], $component['unit_label'] );

			printf(
				'<tr><td>%1$s</td><td><input type="number" step="any" min="0" name="oc_bundle_actual[%2$d][%3$s]" value="%4$s" placeholder="%5$s" class="oc-actual-input" /> %6$s</td><td class="oc-actual-taken">%7$s</td></tr>',
				esc_html( $product->get_name() ),
				(int) $item_id,
				esc_attr( $index ),
				esc_attr( $value ),
				esc_attr( OC_Bundles_Source_Native::fmt( $ordered ) ),
				esc_html( $suffix ),
				esc_html(
					sprintf(
						/* translators: %s is a quantity already removed from stock. */
						__( 'taken from stock: %s', 'oc-bundles' ),
						OC_Bundles_Source_Native::fmt( $taken )
					)
				)
			);
		}

		echo '</table><p class="description">' . esc_html__( 'Leave empty to use the ordered quantity. Saving adjusts stock by the difference only.', 'oc-bundles' ) . '</p></div>';
	}

	/**
	 * Persist weighed quantities, then reconcile stock against them.
	 *
	 * @param int   $order_id Order ID.
	 * @param array $items    Posted items.
	 */
	public static function save_actuals( $order_id, $items ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// wc_save_order_items() is already nonce-checked by its callers; guard the
		// capability too, since this writes stock.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		// The order screen saves items over AJAX, and WooCommerce parse_str()s that
		// serialized form into the $items argument -- so the fields arrive there, not in
		// $_POST. Only a non-AJAX post puts them in $_POST.
		$posted = array();
		if ( isset( $items['oc_bundle_actual'] ) && is_array( $items['oc_bundle_actual'] ) ) {
			$posted = $items['oc_bundle_actual'];
		} elseif ( isset( $_POST['oc_bundle_actual'] ) && is_array( $_POST['oc_bundle_actual'] ) ) {
			$posted = wp_unslash( $_POST['oc_bundle_actual'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per value below.
		}

		if ( ! empty( $posted ) ) {
			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! isset( $posted[ $item_id ] ) || ! is_array( $posted[ $item_id ] ) ) {
					continue;
				}
				$components = $item->get_meta( '_oc_bundle_components' );
				if ( empty( $components ) || ! is_array( $components ) ) {
					continue;
				}

				$actual = array();
				foreach ( $posted[ $item_id ] as $index => $value ) {
					$value = wc_clean( $value );
					if ( '' === $value ) {
						continue;
					}
					$actual[ absint( $index ) ] = max( 0, (float) $value );
				}

				$item->update_meta_data( self::ACTUAL, $actual );
				$item->save();
			}
		}

		self::reconcile( $order, false );
	}
}
