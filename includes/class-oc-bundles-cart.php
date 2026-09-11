<?php
/**
 * Cart — build resolved bundle data, price the line, display components,
 * and persist to the order line item.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Cart {

	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'get_from_session' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'before_totals' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'create_order_line_item' ), 10, 4 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate' ), 10, 3 );
	}

	/**
	 * Resolve and attach bundle data when adding to cart.
	 *
	 * @param array $cart_item_data Cart item data.
	 * @param int   $product_id     Product ID.
	 * @param int   $variation_id   Variation ID.
	 * @return array
	 */
	public static function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
			return $cart_item_data;
		}

		$config    = OC_Bundles_Helpers::get_config( $product_id );
		$selection = array();
		if ( isset( $_POST['oc_bundle_selection'] ) && '' !== $_POST['oc_bundle_selection'] ) {
			$raw = json_decode( wp_unslash( $_POST['oc_bundle_selection'] ), true );
			if ( is_array( $raw ) ) {
				$selection = $raw;
			}
		}

		// Auto-swap any out-of-stock component when configured to do so.
		$selection = OC_Bundles_Helpers::apply_auto_swaps( $config, $selection );

		$resolved = self::resolve_components( $config, $selection );

		$cart_item_data['oc_bundle'] = array(
			'components' => $resolved['components'],
			'selection'  => $resolved['selection'],
			'unit_price' => OC_Bundles_Pricing::line_price( $product_id, $resolved['components'], $resolved['selection'], $config ),
		);
		// Make each combination unique so swaps don't merge with defaults.
		$cart_item_data['oc_bundle_hash'] = md5( wp_json_encode( $cart_item_data['oc_bundle'] ) );

		return $cart_item_data;
	}

	/**
	 * Resolve the final component set given a selection of swap options.
	 *
	 * @param array $config    Bundle config.
	 * @param array $selection Map component_index => swap_index.
	 * @return array { components, selection }
	 */
	public static function resolve_components( $config, $selection ) {
		$out     = array();
		$applied = array();

		foreach ( $config['components'] as $index => $component ) {
			$component = OC_Bundles_Helpers::normalize_component( $component );

			$choice = isset( $selection[ $index ] ) ? intval( $selection[ $index ] ) : -1;

			if ( 'yes' === $component['swappable'] && $choice >= 0 && isset( $component['swaps'][ $choice ] ) ) {
				$swap  = $component['swaps'][ $choice ];
				$out[] = array(
					'product_id'   => absint( $swap['product_id'] ),
					'variation_id' => absint( $swap['variation_id'] ),
					'qty'          => $component['qty'],
					'unit'         => $component['unit'],
					'unit_label'   => $component['unit_label'],
					'unit_weight'  => $component['unit_weight'],
					'mode'         => $component['mode'],
					'swappable'    => 'no',
					'swaps'        => array(),
				);
				$applied[ $index ] = array(
					'swap_index' => $choice,
					'surcharge'  => (float) $swap['surcharge'],
				);
				continue;
			}

			$out[] = $component;
		}

		return array(
			'components' => $out,
			'selection'  => $applied,
		);
	}

	/**
	 * Restore bundle data from the session.
	 *
	 * @param array $cart_item Cart item.
	 * @param array $values    Session values.
	 * @return array
	 */
	public static function get_from_session( $cart_item, $values ) {
		if ( isset( $values['oc_bundle'] ) ) {
			$cart_item['oc_bundle'] = $values['oc_bundle'];
		}
		return $cart_item;
	}

	/**
	 * Apply the computed unit price to the line.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function before_totals( $cart ) {
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['oc_bundle']['unit_price'] ) ) {
				continue;
			}
			$cart_item['data']->set_price( (float) $cart_item['oc_bundle']['unit_price'] );
		}
	}

	/**
	 * Show component breakdown under the cart line.
	 *
	 * @param array $item_data Existing data.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function item_data( $item_data, $cart_item ) {
		if ( empty( $cart_item['oc_bundle']['components'] ) ) {
			return $item_data;
		}
		$config = OC_Bundles_Helpers::get_config( $cart_item['product_id'] );
		if ( 'name_only' === $config['cart_display'] ) {
			return $item_data;
		}

		if ( 'line' === $config['cart_display'] ) {
			$lines = OC_Bundles_Helpers::components_list_lines( $cart_item['oc_bundle']['components'] );
			$value = empty( $lines ) ? '' : esc_html( implode( ' · ', $lines ) );
		} else {
			$value = OC_Bundles_Helpers::components_rows_html( $cart_item['oc_bundle']['components'] );
		}

		if ( '' !== $value ) {
			$item_data[] = array(
				'key'   => __( "What's in the bundle", 'oc-bundles' ),
				'value' => $value,
			);
		}
		return $item_data;
	}

	/**
	 * Persist bundle composition to the order line item.
	 *
	 * @param WC_Order_Item_Product $item          Item.
	 * @param string                $cart_item_key Key.
	 * @param array                 $values        Values.
	 * @param WC_Order              $order         Order.
	 */
	public static function create_order_line_item( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['oc_bundle']['components'] ) ) {
			return;
		}
		$components = $values['oc_bundle']['components'];

		// Hidden technical payload for stock/sync.
		$item->add_meta_data( '_oc_bundle_components', $components, true );
		$item->add_meta_data( '_oc_bundle_selection', isset( $values['oc_bundle']['selection'] ) ? $values['oc_bundle']['selection'] : array(), true );

		// Human-readable list for picking / display.
		$lines = OC_Bundles_Helpers::components_list_lines( $components );
		if ( ! empty( $lines ) ) {
			$item->add_meta_data( __( "What's in the bundle", 'oc-bundles' ), implode( ' · ', $lines ), true );
		}
	}

	/**
	 * Validate availability before add to cart.
	 *
	 * @param bool $passed     Passed.
	 * @param int  $product_id Product ID.
	 * @param int  $quantity   Quantity.
	 * @return bool
	 */
	public static function validate( $passed, $product_id, $quantity ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
			return $passed;
		}
		$available = OC_Bundles_Stock::available_quantity( $product_id );
		if ( $available < $quantity ) {
			wc_add_notice( __( 'Not enough stock for the requested bundle quantity.', 'oc-bundles' ), 'error' );
			return false;
		}
		return $passed;
	}
}
