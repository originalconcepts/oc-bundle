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
 * A bundle line's quantity counts bundles, not kilos. When the order is split for the invoice
 * (1.4.7+), each component has its own line whose QUANTITY is what ships, so the shop
 * re-weighs a component the WooCommerce way — by editing that line — and saving applies the
 * difference. Orders without such lines keep the weighed amounts in their own meta, entered in
 * a table under the bundle line. Either way the amount is the TOTAL for the line (all bundles).
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

	public static function init() {
		add_action( 'woocommerce_reduce_order_stock', array( __CLASS__, 'reduce' ), 10, 1 );
		add_action( 'woocommerce_restore_order_stock', array( __CLASS__, 'restore' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'restore' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'restore' ), 10, 1 );

		// Weighed quantities on the order screen.
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_actuals' ), 10, 2 );
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'save_actuals' ), 10, 2 );
		add_filter( 'woocommerce_quantity_input_step_admin', array( __CLASS__, 'admin_quantity_step' ), 10, 2 );

		// Line quantities changed over the WooCommerce REST API (e.g. weights from a POS).
		add_action( 'woocommerce_rest_insert_shop_order_object', array( __CLASS__, 'after_rest_update' ), 20, 1 );
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
	 * @param WC_Order|int $order   Order.
	 * @param bool         $release True to hand everything back to stock.
	 */
	public static function reconcile( $order, $release = false ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$dirty    = false;
		$repriced = false;
		$restored = false;

		foreach ( $order->get_items() as $item ) {
			$components = $item->get_meta( '_oc_bundle_components' );
			if ( empty( $components ) || ! is_array( $components ) ) {
				continue;
			}

			$bundle_qty = max( 1, (int) $item->get_quantity() );
			$ledger     = self::ledger( $item, $components, $bundle_qty );
			$saved      = $item->get_meta( self::ACTUAL );
			$saved      = is_array( $saved ) ? $saved : array();
			$actual     = $saved;
			$changed    = false;

			// Component lines that hold their own quantity ARE the weighed amounts — whatever
			// the order screen (or the REST API) last saved on them. One that no longer matches
			// the amount settled last time has just been re-weighed.
			$lines     = self::component_lines( $order, $item );
			$reweighed = array();
			foreach ( self::weighed_quantities( $item, $components, $lines ) as $index => $qty ) {
				$was = self::wanted( OC_Bundles_Helpers::normalize_component( $components[ $index ] ), $index, $saved, $bundle_qty );
				if ( 0.0 !== round( $qty - $was, 4 ) ) {
					$reweighed[ $index ] = true;
				}
				$actual[ $index ] = $qty;
			}
			if ( $actual !== $saved ) {
				$item->update_meta_data( self::ACTUAL, $actual );
				$changed = true;
			}

			foreach ( $components as $index => $component ) {
				$component = OC_Bundles_Helpers::normalize_component( $component );
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
				$item->save();
				$dirty = true;
			}

			if ( ! $release ) {
				if ( self::maybe_reprice( $order, $item, $components, $actual, $bundle_qty ) ) {
					$repriced = true;
				} elseif ( ! empty( $reweighed ) && self::restore_amounts( $lines, $reweighed ) ) {
					$restored = true;
				}
			}
		}

		if ( $repriced ) {
			// Line totals moved, so let WooCommerce re-derive taxes and the order total
			// from them -- the same thing the "Recalculate" button does.
			$order->calculate_totals( true );
			$dirty = true;
		} elseif ( $restored ) {
			// Amounts and their taxes are back to what checkout gave them; re-add the order.
			$order->update_taxes();
			$order->calculate_totals( false );
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
		$order->save();
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
	 * @return bool True when a total changed.
	 */
	protected static function maybe_reprice( $order, $item, $components, $actual, $bundle_qty ) {
		$bundle_id = $item->get_product_id();
		$config    = OC_Bundles_Helpers::get_config( $bundle_id );

		if ( 'sum' !== $config['pricing_mode'] || 'yes' !== $config['reweigh_price'] ) {
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
			$component = OC_Bundles_Helpers::normalize_component( $component );
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
				$amount = OC_Bundles_Source_Factory::get( $pid )->price_for_qty( $qty ) * $ratio;
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
				// A line holding its own quantity keeps the product name; only an older line that
				// spells the quantity out in its name has to be renamed.
				$label = ( ! $line->get_meta( OC_Bundles_Invoice::QTY_LINE ) && isset( $labels[ $index ] ) ) ? $labels[ $index ] : $line->get_name();
				if ( (float) $line->get_total() === (float) $amount && $line->get_name() === $label ) {
					continue;
				}
				$line->set_name( $label );
				$line->set_subtotal( $amount );
				$line->set_total( $amount );
				$line->update_meta_data( OC_Bundles_Invoice::AMOUNT, $amount );
				$line->save();
			}
			$item->set_subtotal( 0 );
			$item->set_total( 0 );
			$item->save();
			return true;
		}

		if ( (float) $item->get_total() === $total ) {
			return false;
		}
		$item->set_subtotal( $total );
		$item->set_total( $total );
		$item->save();
		return true;
	}

	/**
	 * The invoice-split lines belonging to a bundle line, keyed by component index.
	 *
	 * Lines split since 1.4.7 are tied to their bundle line by LINE_UID, so two lines of the
	 * same bundle in one order never mix; older ones are matched by bundle product.
	 *
	 * @param WC_Order      $order Order.
	 * @param WC_Order_Item $item  Bundle line.
	 * @return array
	 */
	protected static function component_lines( $order, $item ) {
		if ( ! class_exists( 'OC_Bundles_Invoice' ) ) {
			return array();
		}
		$bundle_id = (int) $item->get_product_id();
		$uid       = (string) $item->get_meta( OC_Bundles_Invoice::LINE_UID );
		$lines     = array();
		foreach ( $order->get_items() as $line ) {
			if ( (int) $line->get_meta( OC_Bundles_Invoice::MARKER ) !== $bundle_id ) {
				continue;
			}
			if ( '' !== $uid && (string) $line->get_meta( OC_Bundles_Invoice::PARENT ) !== $uid ) {
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
	 * Quantities held by component lines that carry their own quantity, by index.
	 *
	 * @param array $lines Component lines by index.
	 * @return array
	 */
	protected static function line_quantities( $lines ) {
		$quantities = array();
		foreach ( $lines as $index => $line ) {
			if ( $line->get_meta( OC_Bundles_Invoice::QTY_LINE ) ) {
				$quantities[ $index ] = max( 0.0, (float) $line->get_quantity() );
			}
		}
		return $quantities;
	}

	/**
	 * The weighed amounts a bundle line's component lines hold, by component index.
	 *
	 * For a bundle split into lines that carry their own quantity (1.4.7+), a component whose
	 * line was removed from the order — WooCommerce deletes a line saved at quantity 0 — ships
	 * nothing, so its stock goes back like any removed line's.
	 *
	 * @param WC_Order_Item_Product $item       Bundle line.
	 * @param array                 $components Components.
	 * @param array                 $lines      Component lines by index.
	 * @return array
	 */
	protected static function weighed_quantities( $item, $components, $lines ) {
		if ( ! class_exists( 'OC_Bundles_Invoice' ) ) {
			return array();
		}
		$quantities = self::line_quantities( $lines );
		if ( $item->get_meta( OC_Bundles_Invoice::QTY_LINES ) ) {
			foreach ( $components as $index => $component ) {
				if ( ! isset( $lines[ $index ] ) && OC_Bundles_Helpers::effective_id( OC_Bundles_Helpers::normalize_component( $component ) ) ) {
					$quantities[ $index ] = 0.0;
				}
			}
		}
		return array_intersect_key( $quantities, $components );
	}

	/**
	 * Put a re-weighed component line's amount and taxes back to what checkout gave it.
	 *
	 * The order screen scales a line's total with its quantity as you type. That is right for
	 * a product priced by weight, but a bundle component either costs nothing extra or carries
	 * a flat swap surcharge, so unless the bundle re-prices on re-weigh (maybe_reprice) its
	 * amount must not move.
	 *
	 * @param array $lines     Component lines by index.
	 * @param array $reweighed Indexes whose quantity just changed.
	 * @return bool True when an amount was put back.
	 */
	protected static function restore_amounts( $lines, $reweighed ) {
		$restored = false;
		foreach ( array_keys( $reweighed ) as $index ) {
			if ( ! isset( $lines[ $index ] ) ) {
				continue;
			}
			$line   = $lines[ $index ];
			$amount = $line->get_meta( OC_Bundles_Invoice::AMOUNT );
			if ( '' === (string) $amount || (float) $line->get_total() === (float) $amount ) {
				continue;
			}
			$line->set_subtotal( (float) $amount );
			$line->set_total( (float) $amount );
			$taxes = $line->get_meta( OC_Bundles_Invoice::TAXES );
			if ( is_array( $taxes ) ) {
				$line->set_taxes( $taxes );
			}
			$line->save();
			$restored = true;
		}
		return $restored;
	}

	/**
	 * Let a product-less order line (a bundle component) take a decimal quantity on the order
	 * screen, like a weighable product line — on a store whose quantities keep fractions at all.
	 *
	 * @param string          $step    Input step.
	 * @param WC_Product|bool $product Line product.
	 * @return string
	 */
	public static function admin_quantity_step( $step, $product ) {
		return ( ! $product && 0.5 === (float) wc_stock_amount( 0.5 ) ) ? 'any' : $step;
	}

	/**
	 * Settle stock after an order update over the WooCommerce REST API — but only for an order
	 * that has already taken its stock, so a freshly created one still waits for WooCommerce.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function after_rest_update( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			if ( is_array( $item->get_meta( self::LEDGER ) ) ) {
				self::reconcile( $order, false );
				return;
			}
		}
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

		$order  = $item->get_order();
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

		// A component whose own line holds its quantity is re-weighed on that line, like any
		// order line, so the table lists only the rest — none, for a bundle split since 1.4.7.
		$order = $item->get_order();
		$held  = ( $order instanceof WC_Order ) ? self::weighed_quantities( $item, $components, self::component_lines( $order, $item ) ) : array();

		$bundle_qty = max( 1, (int) $item->get_quantity() );
		$actual     = $item->get_meta( self::ACTUAL );
		$actual     = is_array( $actual ) ? $actual : array();
		$ledger     = $item->get_meta( self::LEDGER );
		$ledger     = is_array( $ledger ) ? $ledger : array();

		$rows = '';
		foreach ( $components as $index => $component ) {
			if ( isset( $held[ $index ] ) ) {
				continue;
			}
			$component = OC_Bundles_Helpers::normalize_component( $component );
			$pid       = OC_Bundles_Helpers::effective_id( $component );
			$product   = $pid ? wc_get_product( $pid ) : false;
			if ( ! $product ) {
				continue;
			}

			$ordered = (float) $component['qty'] * $bundle_qty;
			$value   = isset( $actual[ $index ] ) ? $actual[ $index ] : '';
			$taken   = isset( $ledger[ $index ] ) ? (float) $ledger[ $index ] : 0.0;
			$suffix  = OC_Bundles_Helpers::display_suffix( $component['unit'], $component['unit_label'] );

			$rows .= sprintf(
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

		if ( '' === $rows ) {
			return;
		}

		echo '<div class="oc-order-actuals"><strong>' . esc_html__( 'Weighed quantities', 'oc-bundles' ) . '</strong><table class="oc-order-actuals-table">' . $rows . '</table><p class="description">' . esc_html__( 'Leave empty to use the ordered quantity. Saving adjusts stock by the difference only.', 'oc-bundles' ) . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every part of $rows is escaped as it is built.
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

				// Only the components listed in the table are posted; the others keep their amounts.
				$actual = $item->get_meta( self::ACTUAL );
				$actual = is_array( $actual ) ? $actual : array();
				foreach ( $posted[ $item_id ] as $index => $value ) {
					$value = wc_clean( $value );
					if ( '' === $value ) {
						unset( $actual[ absint( $index ) ] );
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
