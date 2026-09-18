<?php
/**
 * Shared helpers: read bundle configuration, derive a component's unit/quantity
 * mode from the product (OC Sale Units aware), resolve components, and format.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Helpers {

	/**
	 * Default bundle configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'components'              => array(),
			'pricing_mode'            => 'fixed',
			'fixed_price'             => 0,
			'discount_type'           => 'none',
			'discount_value'          => 0,
			'hide_price_labels'       => 'no',
			'oos_behavior'            => 'unavailable',
			'show_components_in_desc' => 'no',
			'layout'                  => 'grid',
			'cart_display'            => 'name_with_components',
			'invoice_display'         => 'bundle',
			'reweigh_price'           => 'no',
		);
	}

	/**
	 * Read the full bundle configuration from post meta.
	 *
	 * @param int $product_id Bundle product ID.
	 * @return array
	 */
	public static function get_config( $product_id ) {
		$defaults = self::defaults();
		$config   = array();

		foreach ( $defaults as $key => $default ) {
			$value          = get_post_meta( $product_id, '_oc_bundle_' . $key, true );
			$config[ $key ] = ( '' === $value || null === $value ) ? $default : $value;
		}

		if ( ! is_array( $config['components'] ) ) {
			$config['components'] = array();
		}

		return $config;
	}

	/** @var string Post meta holding the external (Giorgio) id of a bundle product. */
	const EXTERNAL_ID_META = '_oc_bundle_external_id';

	/** @var string Option: let store promotions reach bundle cart lines (yes|no). */
	const PROMOTIONS_OPTION = 'oc_bundles_allow_promotions';

	/**
	 * Normalize a stored component into a clean array (new per-product swap model).
	 *
	 * @param array    $component Raw component.
	 * @param int|null $index     Position of the component in its list, when known.
	 *                            Only used to derive a key for components that have none.
	 * @return array
	 */
	public static function normalize_component( $component, $index = null ) {
		if ( ! is_array( $component ) ) {
			$component = array();
		}

		$swaps = array();
		if ( ! empty( $component['swaps'] ) && is_array( $component['swaps'] ) ) {
			foreach ( $component['swaps'] as $swap ) {
				$swaps[] = array(
					'product_id'   => isset( $swap['product_id'] ) ? absint( $swap['product_id'] ) : 0,
					'variation_id' => isset( $swap['variation_id'] ) ? absint( $swap['variation_id'] ) : 0,
					'surcharge'    => isset( $swap['surcharge'] ) ? (float) $swap['surcharge'] : 0,
				);
			}
		}

		$product_id   = isset( $component['product_id'] ) ? absint( $component['product_id'] ) : 0;
		$variation_id = isset( $component['variation_id'] ) ? absint( $component['variation_id'] ) : 0;

		return array(
			'key'          => self::component_key( $component, $product_id, $variation_id, $index ),
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'qty'          => isset( $component['qty'] ) ? (float) $component['qty'] : 0,
			'unit'         => isset( $component['unit'] ) ? sanitize_text_field( $component['unit'] ) : 'unit',
			'unit_label'   => isset( $component['unit_label'] ) ? sanitize_text_field( $component['unit_label'] ) : '',
			'unit_weight'  => isset( $component['unit_weight'] ) ? (float) $component['unit_weight'] : 0,
			'mode'         => isset( $component['mode'] ) ? sanitize_text_field( $component['mode'] ) : 'units',
			'description'  => isset( $component['description'] ) ? wp_kses_post( $component['description'] ) : '',
			'swappable'    => ( isset( $component['swappable'] ) && 'yes' === $component['swappable'] ) ? 'yes' : 'no',
			'swaps'        => $swaps,
		);
	}

	/**
	 * Stable component key: the stored one verbatim (trimmed to 40 chars), else
	 * `p{product_id}-v{variation_id}-{index}` (no index suffix when unknown).
	 *
	 * @param array    $component    Raw component.
	 * @param int      $product_id   Product ID.
	 * @param int      $variation_id Variation ID.
	 * @param int|null $index        Position in the list, when known.
	 * @return string
	 */
	public static function component_key( $component, $product_id, $variation_id, $index = null ) {
		$key = ( is_array( $component ) && isset( $component['key'] ) ) ? self::sanitize_key_string( $component['key'] ) : '';
		if ( '' !== $key ) {
			return $key;
		}
		$key = 'p' . absint( $product_id ) . '-v' . absint( $variation_id );
		if ( null !== $index && '' !== (string) $index ) {
			$key .= '-' . sanitize_text_field( (string) $index );
		}
		return $key;
	}

	/**
	 * Sanitize a component key (string, at most 40 characters).
	 *
	 * @param mixed $key Raw key.
	 * @return string
	 */
	public static function sanitize_key_string( $key ) {
		if ( is_array( $key ) || is_object( $key ) ) {
			return '';
		}
		$key = sanitize_text_field( (string) $key );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $key, 0, 40 );
		}
		return substr( $key, 0, 40 );
	}

	/**
	 * External (Giorgio) id of a bundle product, '' when unmanaged.
	 *
	 * @param int $product_id Bundle product ID.
	 * @return string
	 */
	public static function external_id( $product_id ) {
		$value = get_post_meta( absint( $product_id ), self::EXTERNAL_ID_META, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Whether the admin "Bundle contents" tab is read-only for this product because an
	 * external system owns it. Filter `oc_bundles_lock_managed` (default true) can undo it.
	 *
	 * @param int $product_id Bundle product ID.
	 * @return bool
	 */
	public static function is_locked( $product_id ) {
		if ( '' === self::external_id( $product_id ) ) {
			return false;
		}
		return (bool) apply_filters( 'oc_bundles_lock_managed', true, absint( $product_id ) );
	}

	/**
	 * Whether store promotions are allowed to re-price bundle cart lines (option, default on).
	 *
	 * @return bool
	 */
	public static function promotions_allowed() {
		return 'no' !== get_option( self::PROMOTIONS_OPTION, 'yes' );
	}

	/**
	 * Index of the configured swap matching a product, or -1 when none does.
	 *
	 * @param array $component    Normalized component.
	 * @param int   $product_id   Product ID.
	 * @param int   $variation_id Variation ID (0 for none).
	 * @return int
	 */
	public static function find_swap_index( $component, $product_id, $variation_id = 0 ) {
		$component  = self::normalize_component( $component );
		$product_id = absint( $product_id );
		$want       = $variation_id ? absint( $variation_id ) : $product_id;
		foreach ( $component['swaps'] as $k => $swap ) {
			$have = $swap['variation_id'] ? $swap['variation_id'] : $swap['product_id'];
			if ( $have && $have === $want ) {
				return (int) $k;
			}
		}
		return -1;
	}

	/**
	 * Effective product ID to operate on (variation if set).
	 *
	 * @param array $component Component.
	 * @return int
	 */
	public static function effective_id( $component ) {
		if ( ! empty( $component['variation_id'] ) ) {
			return absint( $component['variation_id'] );
		}
		return isset( $component['product_id'] ) ? absint( $component['product_id'] ) : 0;
	}

	/**
	 * The effective product ID for a given swap selection key.
	 *
	 * @param array $component Component.
	 * @param int   $key       -1 = original, else index into swaps.
	 * @return int
	 */
	public static function effective_active_id( $component, $key ) {
		$component = self::normalize_component( $component );
		if ( $key >= 0 && isset( $component['swaps'][ $key ] ) ) {
			$swap = $component['swaps'][ $key ];
			return $swap['variation_id'] ? absint( $swap['variation_id'] ) : absint( $swap['product_id'] );
		}
		return self::effective_id( $component );
	}

	/**
	 * For oos_behavior=swap, replace any out-of-stock active component with the
	 * first in-stock alternative. Returns an updated selection map.
	 *
	 * @param array $config    Bundle config.
	 * @param array $selection Current selection (index => key).
	 * @return array
	 */
	public static function apply_auto_swaps( $config, $selection = array() ) {
		if ( ! is_array( $selection ) ) {
			$selection = array();
		}
		if ( empty( $config['oos_behavior'] ) || 'swap' !== $config['oos_behavior'] ) {
			return $selection;
		}

		foreach ( $config['components'] as $index => $component ) {
			$component = self::normalize_component( $component, $index );
			if ( 'yes' !== $component['swappable'] || empty( $component['swaps'] ) ) {
				continue;
			}

			$key = isset( $selection[ $index ] ) ? intval( $selection[ $index ] ) : -1;
			$pid = self::effective_active_id( $component, $key );
			if ( $pid && OC_Bundles_Source_Factory::get( $pid )->is_in_stock() ) {
				continue;
			}

			$candidates = array( -1 );
			foreach ( array_keys( $component['swaps'] ) as $k ) {
				$candidates[] = (int) $k;
			}
			foreach ( $candidates as $cand ) {
				if ( $cand === $key ) {
					continue;
				}
				$cpid = self::effective_active_id( $component, $cand );
				if ( $cpid && OC_Bundles_Source_Factory::get( $cpid )->is_in_stock() ) {
					if ( $cand < 0 ) {
						unset( $selection[ $index ] );
					} else {
						$selection[ $index ] = $cand;
					}
					break;
				}
			}
		}

		return $selection;
	}

	/**
	 * Whether OC Sale Units is active.
	 *
	 * @return bool
	 */
	public static function ocwsu_active() {
		return OC_Bundles_Source_Factory::ocwsu_active();
	}

	/**
	 * Parent product ID that holds OCWSU meta (variations read from parent).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return int
	 */
	public static function parent_meta_id( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product && $product->is_type( 'variation' ) ) {
			return $product->get_parent_id();
		}
		return absint( $product_id );
	}

	/**
	 * Derive the quantity mode + parameters for a product, from its OC Sale Units
	 * configuration. Drives the admin quantity control automatically.
	 *
	 * Modes:
	 *  - 'weight'        sold by weight: quantity is a weight (kg/grams) with min/step.
	 *  - 'units_weight'  sold by units, weight chosen from variations / fixed list.
	 *  - 'units'         sold by units (fixed/plain) or non-weighable: quantity in units.
	 *
	 * @param int $product_id Product or variation ID.
	 * @return array
	 */
	public static function product_spec( $product_id ) {
		$product = wc_get_product( $product_id );
		$spec    = array(
			'mode'              => 'units',
			'unit'              => 'unit',
			'unit_label'        => '',
			'weight_unit'       => 'kg',
			'weight_unit_label' => 'kg',
			'min'               => 1,
			'step'              => 1,
			'unit_weight'       => 0,
			'weight_options'    => array(),
		);

		if ( ! $product ) {
			return $spec;
		}

		$parent = self::parent_meta_id( $product_id );

		if ( ! self::ocwsu_active() || 'yes' !== get_post_meta( $parent, '_ocwsu_weighable', true ) ) {
			return $spec;
		}

		$weight_unit              = get_post_meta( $parent, '_ocwsu_product_weight_units', true );
		$weight_unit              = ( 'grams' === $weight_unit ) ? 'grams' : 'kg';
		$spec['weight_unit']      = $weight_unit;
		$spec['weight_unit_label'] = ( 'grams' === $weight_unit ) ? __( 'g', 'oc-bundles' ) : __( 'kg', 'oc-bundles' );

		$sold_by_weight = 'yes' === get_post_meta( $parent, '_ocwsu_sold_by_weight', true );
		$sold_by_units  = 'yes' === get_post_meta( $parent, '_ocwsu_sold_by_units', true );

		if ( $sold_by_weight ) {
			$spec['mode']       = 'weight';
			$spec['unit']       = $weight_unit;
			$spec['unit_label'] = '';
			$min                = (float) get_post_meta( $parent, '_ocwsu_min_weight', true );
			$step               = (float) get_post_meta( $parent, '_ocwsu_weight_step', true );
			$spec['min']        = $min > 0 ? $min : ( 'grams' === $weight_unit ? 100 : 0.1 );
			$spec['step']       = $step > 0 ? $step : ( 'grams' === $weight_unit ? 50 : 0.1 );
			return $spec;
		}

		if ( $sold_by_units ) {
			$spec['unit']       = 'unit';
			$spec['unit_label'] = function_exists( 'ocwsu_get_sale_unit_quantity_label_text' ) ? ocwsu_get_sale_unit_quantity_label_text( $parent ) : '';
			$spec['min']        = 1;
			$spec['step']       = 1;

			$unit_type   = get_post_meta( $parent, '_ocwsu_unit_weight_type', true );
			$from_var    = 'yes' === get_post_meta( $parent, '_ocwsu_get_weight_from_variation', true );
			$options_raw = get_post_meta( $parent, '_ocwsu_unit_weight_options', true );

			// (4) Weight taken from each variation's own weight field.
			if ( $from_var ) {
				$spec['mode']           = 'units_weight';
				$spec['weight_options'] = self::variation_weight_options( $parent );
				return $spec;
			}

			// (3) Weight chosen from an admin-prepared fixed list.
			if ( 'variable' === $unit_type && ! empty( $options_raw ) ) {
				$spec['mode']           = 'units_weight';
				$spec['weight_options'] = self::list_weight_options( $options_raw, $spec['weight_unit_label'] );
				return $spec;
			}

			// (2) Fixed unit weight (each unit weighs X) — still ordered by units.
			$spec['mode']        = 'units';
			$spec['unit_weight'] = function_exists( 'ocwsu_get_fixed_unit_weight_kg' ) ? ocwsu_get_fixed_unit_weight_kg( $parent ) : (float) get_post_meta( $parent, '_ocwsu_unit_weight', true );
			return $spec;
		}

		return $spec;
	}

	/**
	 * Weight (kg) of ONE unit of a component that is ordered by units but priced per kg
	 * (OCWSU "sold by units"). 0 for everything else (sold by weight, plain products).
	 *
	 * - fixed unit weight (`units`): the product's own unit weight;
	 * - chosen weight (`units_weight`): the weight stored on the component (admin select /
	 *   REST `unit_weight`, kg), else the chosen variation's weight, else the first option.
	 *
	 * @param array $component  Component (raw or normalized).
	 * @param int   $product_id Product actually priced (defaults to the component's own).
	 * @return float
	 */
	public static function component_unit_weight_kg( $component, $product_id = 0 ) {
		$product_id = $product_id ? absint( $product_id ) : self::effective_id( $component );
		if ( ! $product_id ) {
			return 0.0;
		}
		$spec = self::product_spec( $product_id );

		if ( 'units' === $spec['mode'] ) {
			return max( 0.0, (float) $spec['unit_weight'] );
		}
		if ( 'units_weight' !== $spec['mode'] ) {
			return 0.0;
		}

		// The stored weight belongs to the component's own product, not to a swap.
		$own    = ( self::effective_id( $component ) === $product_id );
		$weight = ( $own && isset( $component['unit_weight'] ) ) ? (float) $component['unit_weight'] : 0.0;
		$from_list = false;
		if ( $weight <= 0 ) {
			foreach ( $spec['weight_options'] as $opt ) {
				if ( $opt['variation_id'] && (int) $opt['variation_id'] === (int) $product_id ) {
					$weight = (float) $opt['weight'];
					break;
				}
			}
		}
		if ( $weight <= 0 && ! empty( $spec['weight_options'] ) ) {
			$weight    = (float) $spec['weight_options'][0]['weight'];
			$from_list = empty( $spec['weight_options'][0]['variation_id'] );
		}

		// A fixed list is written in the product's weight unit; variation weights and the
		// REST value are kg. A "unit" of 20 kg or more can only be a grams figure.
		if ( 'grams' === $spec['weight_unit'] && ( $from_list || $weight >= 20 ) ) {
			$weight = $weight / 1000;
		}

		return max( 0.0, $weight );
	}

	/**
	 * Weight options coming from product variations.
	 *
	 * @param int $parent_id Variable product ID.
	 * @return array List of { variation_id, weight, label }.
	 */
	protected static function variation_weight_options( $parent_id ) {
		$options = array();
		$product = wc_get_product( $parent_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $options;
		}
		$wunit = get_option( 'woocommerce_weight_unit', 'kg' );
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}

			// OCWSU's "weight from variation" uses the standard WooCommerce
			// variation weight; convert it to kg for display.
			$raw_weight = (float) $variation->get_weight();
			$weight     = $raw_weight;
			if ( 'g' === $wunit ) {
				$weight = $raw_weight / 1000;
			} elseif ( 'lbs' === $wunit ) {
				$weight = $raw_weight * 0.45359237;
			} elseif ( 'oz' === $wunit ) {
				$weight = $raw_weight * 0.0283495231;
			}

			// Fall back to OCWSU per-variation meta if the WC weight is empty.
			if ( $weight <= 0 && function_exists( 'ocwsu_get_fixed_unit_weight_kg' ) ) {
				$weight = (float) ocwsu_get_fixed_unit_weight_kg( $variation_id );
			}

			$attr  = function_exists( 'wc_get_formatted_variation' ) ? wc_get_formatted_variation( $variation, true, false, false ) : '';
			$label = $attr ? $attr : wp_strip_all_tags( $variation->get_name() );

			$options[] = array(
				'variation_id' => $variation_id,
				'weight'       => $weight,
				'label'        => $label,
			);
		}
		return $options;
	}

	/**
	 * Weight options from a newline-separated list (simple product).
	 *
	 * @param string $raw   Raw options text.
	 * @param string $label Weight unit label.
	 * @return array List of { variation_id:0, weight, label }.
	 */
	protected static function list_weight_options( $raw, $label ) {
		$options = array();
		$lines   = preg_split( '/\r\n|\r|\n/', (string) $raw );
		foreach ( $lines as $line ) {
			$w = (float) trim( $line );
			if ( $w <= 0 ) {
				continue;
			}
			$options[] = array(
				'variation_id' => 0,
				'weight'       => $w,
				'label'        => OC_Bundles_Source_Native::fmt( $w ) . ' ' . $label,
			);
		}
		return $options;
	}

	/**
	 * Translated unit suffix from a unit code (+ optional merchant label).
	 *
	 * @param string $unit       Unit code: kg | grams | unit.
	 * @param string $unit_label Optional merchant-provided label (OCWSU).
	 * @return string
	 */
	public static function display_suffix( $unit, $unit_label = '' ) {
		if ( 'grams' === $unit ) {
			return __( 'g', 'oc-bundles' );
		}
		if ( 'kg' === $unit ) {
			return __( 'kg', 'oc-bundles' );
		}
		return ( '' !== $unit_label ) ? $unit_label : _x( 'pcs', 'units suffix', 'oc-bundles' );
	}

	/**
	 * Human-readable quantity label for a normalized component.
	 *
	 * @param array $component Normalized component.
	 * @return string
	 */
	public static function quantity_label( $component ) {
		$component = self::normalize_component( $component );
		$qty       = OC_Bundles_Source_Native::fmt( $component['qty'] );
		$suffix    = self::display_suffix( $component['unit'], $component['unit_label'] );

		return $qty . ' ' . $suffix;
	}

	/**
	 * Build the textual components list (for the short description / cart).
	 *
	 * @param array $components Components.
	 * @return array
	 */
	public static function components_list_lines( $components ) {
		$lines = array();
		foreach ( $components as $component ) {
			$component = self::normalize_component( $component );
			$pid       = self::effective_id( $component );
			$product   = $pid ? wc_get_product( $pid ) : false;
			$name      = $product ? $product->get_name() : '';
			if ( '' === $name ) {
				continue;
			}
			$lines[] = trim( self::quantity_label( $component ) . ' ' . $name );
		}
		return $lines;
	}

	/**
	 * Build the components list as styled rows (qty pill + name per line).
	 * Inline styles keep it consistent in cart, checkout, order details and emails.
	 *
	 * @param array $components Components.
	 * @return string HTML.
	 */
	public static function components_rows_html( $components ) {
		// Flex rather than inline-block: in a narrow column (the mini-cart especially) an
		// inline-block pill followed by bare text collapses the name into one word per
		// line. A flex row keeps the pill on its own track and lets the name use whatever
		// width is left, wrapping normally under itself.
		$wrap = 'display:block;';
		$row  = 'display:flex;align-items:flex-start;gap:6px;line-height:1.5;margin:0 0 4px;';
		$pill = 'flex:0 0 auto;background:#eef3f0;color:#13433a;border-radius:4px;padding:1px 6px;font-weight:600;font-size:.92em;text-align:center;white-space:nowrap;';
		$name = 'flex:1 1 auto;min-width:0;overflow-wrap:anywhere;';

		$html = '';
		foreach ( $components as $component ) {
			$component = self::normalize_component( $component );
			$pid       = self::effective_id( $component );
			$product   = $pid ? wc_get_product( $pid ) : false;
			if ( ! $product ) {
				continue;
			}
			$html .= '<span class="oc-cart-line" style="' . esc_attr( $row ) . '">'
				. '<span class="oc-cart-qty" style="' . esc_attr( $pill ) . '">' . esc_html( self::quantity_label( $component ) ) . '</span>'
				. '<span class="oc-cart-name" style="' . esc_attr( $name ) . '">' . esc_html( $product->get_name() ) . '</span>'
				. '</span>';
		}
		if ( '' === $html ) {
			return '';
		}
		return '<span class="oc-cart-lines" style="' . esc_attr( $wrap ) . '">' . $html . '</span>';
	}
}
