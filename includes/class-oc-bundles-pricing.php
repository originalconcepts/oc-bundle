<?php
/**
 * Pricing — base price (fixed / sum) with discount, plus swap surcharges.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Pricing {

	/** @var bool Guard against recursion in the price filter. */
	protected static $in_filter = false;

	/** @var array<int,float> Cart-line prices by product object (spl_object_id => price). */
	protected static $line_prices = array();

	/** @var array<int,float> Cart-line regular (pre-discount) prices, same keys. */
	protected static $line_regular = array();

	public static function init() {
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'filter_price' ), 10, 2 );
		// Regular = before the bundle discount, sale = after it. WooCommerce's own
		// is_on_sale() and get_price_html() then show a discounted bundle like any product
		// on sale: regular price struck through, sale price in the theme's sale colour.
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'filter_regular_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'filter_sale_price' ), 10, 2 );

		// Keep a cart line at the price the cart computed for it, surcharges included.
		if ( OC_Bundles_Helpers::promotions_allowed() ) {
			// Cart sets the price at 5. Capture it right away (so a promotion engine
			// reading get_price() at 20 sees base + surcharges), then capture again LAST
			// so whatever a promotion engine set_price()'d is what wins.
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'capture_line_prices' ), 6 );
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'capture_line_prices' ), PHP_INT_MAX );
		} else {
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'capture_line_prices' ), 21 );
		}
		add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'restore_line_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_regular_price', array( __CLASS__, 'restore_line_regular_price' ), 20, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( __CLASS__, 'restore_line_sale_price' ), 20, 2 );
	}

	/**
	 * Record what each bundle cart line should actually cost.
	 *
	 * OC_Bundles_Cart::before_totals() calls set_price() with base + swap surcharges.
	 * With promotions allowed we read the price currently ON the product object (raw
	 * prop, no filters) so a later set_price() by a promotion engine is honoured;
	 * otherwise the cart's own unit price is used, as before. Keyed by the line's
	 * product object.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function capture_line_prices( $cart ) {
		self::$line_prices  = array();
		self::$line_regular = array();

		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		$from_object = OC_Bundles_Helpers::promotions_allowed();

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['oc_bundle']['unit_price'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			if ( ! is_object( $cart_item['data'] ) ) {
				continue;
			}
			$price = (float) $cart_item['oc_bundle']['unit_price'];
			if ( $from_object && is_a( $cart_item['data'], 'WC_Product' ) ) {
				$current = $cart_item['data']->get_price( 'edit' );
				if ( '' !== (string) $current && null !== $current ) {
					$price = (float) $current;
				}
			}
			$key                       = spl_object_id( $cart_item['data'] );
			self::$line_prices[ $key ] = $price;
			// Swap surcharges are never discounted, so the line's regular price is simply its
			// price plus the bundle discount.
			self::$line_regular[ $key ] = self::$line_prices[ $key ] + self::discount_amount( $cart_item['product_id'] );
		}
	}

	/**
	 * Give the cart line its real price back.
	 *
	 * filter_price() above returns base_price() for every bundle product with no regard
	 * for a price already set on the object, so OC_Bundles_Cart::before_totals()'s
	 * set_price() would otherwise have no effect: WooCommerce reads the price back
	 * through get_price(), that filter fires, and the swap surcharges are dropped — the
	 * customer is shown base + surcharges but charged base.
	 *
	 * Running at priority 20 puts this after that filter, and it only touches product
	 * objects belonging to a bundle cart line.
	 *
	 * @param string     $price   Price from the earlier filters.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function restore_line_price( $price, $product ) {
		if ( empty( self::$line_prices ) || ! $product || ! is_object( $product ) ) {
			return $price;
		}

		$key = spl_object_id( $product );

		return isset( self::$line_prices[ $key ] ) ? (string) self::$line_prices[ $key ] : $price;
	}

	/**
	 * A cart line's regular price: its own price plus the bundle discount.
	 *
	 * @param string     $price   Price from the earlier filters.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function restore_line_regular_price( $price, $product ) {
		if ( empty( self::$line_regular ) || ! $product || ! is_object( $product ) ) {
			return $price;
		}

		$key = spl_object_id( $product );

		return isset( self::$line_regular[ $key ] ) ? (string) self::$line_regular[ $key ] : $price;
	}

	/**
	 * A cart line's sale price: its own price when the bundle is discounted, else none.
	 *
	 * @param string     $price   Price from the earlier filters.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function restore_line_sale_price( $price, $product ) {
		if ( empty( self::$line_prices ) || ! $product || ! is_object( $product ) ) {
			return $price;
		}

		$key = spl_object_id( $product );
		if ( ! isset( self::$line_prices[ $key ], self::$line_regular[ $key ] ) ) {
			return $price;
		}

		return self::$line_regular[ $key ] > self::$line_prices[ $key ] ? (string) self::$line_prices[ $key ] : '';
	}

	/**
	 * Return the computed base price for bundle products.
	 *
	 * @param string     $price   Stored price.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function filter_price( $price, $product ) {
		if ( self::$in_filter ) {
			return $price;
		}
		if ( ! $product || ! is_a( $product, 'WC_Product' ) || ! $product->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
			return $price;
		}
		self::$in_filter = true;
		$computed        = self::base_price( $product->get_id() );
		self::$in_filter = false;
		return (string) $computed;
	}

	/**
	 * Regular price of a bundle: the price before the bundle discount.
	 *
	 * @param string     $price   Stored regular price.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function filter_regular_price( $price, $product ) {
		if ( self::$in_filter || ! self::is_bundle( $product ) ) {
			return $price;
		}
		self::$in_filter = true;
		$computed        = self::raw_base_price( $product->get_id() );
		self::$in_filter = false;
		return (string) $computed;
	}

	/**
	 * Sale price of a bundle: the discounted price when a discount applies, otherwise
	 * none — so a stale _sale_price meta can never decide whether it is on sale.
	 *
	 * @param string     $price   Stored sale price.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function filter_sale_price( $price, $product ) {
		if ( self::$in_filter || ! self::is_bundle( $product ) ) {
			return $price;
		}
		self::$in_filter = true;
		$config          = OC_Bundles_Helpers::get_config( $product->get_id() );
		$raw             = self::raw_base_price( $product->get_id(), null, $config );
		$base            = self::base_price( $product->get_id(), null, $config );
		self::$in_filter = false;
		return $base < $raw ? (string) $base : '';
	}

	/**
	 * @param mixed $product Product.
	 * @return bool
	 */
	protected static function is_bundle( $product ) {
		return $product && is_a( $product, 'WC_Product' ) && $product->is_type( OC_BUNDLES_PRODUCT_TYPE );
	}

	/**
	 * Base bundle price (before swap surcharges), after discount.
	 *
	 * @param int   $bundle_id  Bundle product ID.
	 * @param array $components  Optional resolved components.
	 * @param array $config      Optional config.
	 * @return float
	 */
	public static function raw_base_price( $bundle_id, $components = null, $config = null ) {
		if ( null === $config ) {
			$config = OC_Bundles_Helpers::get_config( $bundle_id );
		}
		if ( null === $components ) {
			$components = $config['components'];
		}

		if ( 'sum' === $config['pricing_mode'] ) {
			$price = 0.0;
			foreach ( $components as $component ) {
				$component = OC_Bundles_Helpers::normalize_component( $component );
				$pid       = OC_Bundles_Helpers::effective_id( $component );
				if ( ! $pid ) {
					continue;
				}
				$source = OC_Bundles_Source_Factory::get( $pid );
				$price += $source->price_for_qty( $component['qty'], OC_Bundles_Helpers::component_unit_weight_kg( $component, $pid ) );
			}
		} else {
			$price = (float) $config['fixed_price'];
		}

		return max( 0, round( $price, wc_get_price_decimals() ) );
	}

	/**
	 * Bundle base price after the bundle-level discount (no swap surcharges).
	 *
	 * @param int        $bundle_id  Bundle ID.
	 * @param array|null $components Components (defaults to config).
	 * @param array|null $config     Config.
	 * @return float
	 */
	public static function base_price( $bundle_id, $components = null, $config = null ) {
		if ( null === $config ) {
			$config = OC_Bundles_Helpers::get_config( $bundle_id );
		}
		$raw = self::raw_base_price( $bundle_id, $components, $config );
		return max( 0, round( self::apply_discount( $raw, $config ), wc_get_price_decimals() ) );
	}

	/**
	 * The bundle-level discount amount (raw base minus discounted base).
	 *
	 * @param int        $bundle_id  Bundle ID.
	 * @param array|null $components Components.
	 * @param array|null $config     Config.
	 * @return float
	 */
	public static function discount_amount( $bundle_id, $components = null, $config = null ) {
		if ( null === $config ) {
			$config = OC_Bundles_Helpers::get_config( $bundle_id );
		}
		$raw = self::raw_base_price( $bundle_id, $components, $config );
		return max( 0, round( $raw - self::apply_discount( $raw, $config ), wc_get_price_decimals() ) );
	}

	/**
	 * Keep WooCommerce's price meta in sync so archives/search show the
	 * regular (struck) + sale price natively when a discount applies.
	 *
	 * @param int        $bundle_id Bundle ID.
	 * @param array|null $config    Config.
	 */
	public static function sync_price_meta( $bundle_id, $config = null ) {
		if ( null === $config ) {
			$config = OC_Bundles_Helpers::get_config( $bundle_id );
		}
		$raw  = self::raw_base_price( $bundle_id, $config['components'], $config );
		$base = self::base_price( $bundle_id, $config['components'], $config );

		update_post_meta( $bundle_id, '_price', $base );
		if ( $base < $raw ) {
			update_post_meta( $bundle_id, '_regular_price', $raw );
			update_post_meta( $bundle_id, '_sale_price', $base );
		} else {
			update_post_meta( $bundle_id, '_regular_price', $base );
			update_post_meta( $bundle_id, '_sale_price', '' );
		}
	}

	/**
	 * Apply the bundle-level discount.
	 *
	 * @param float $price  Price before discount.
	 * @param array $config Config.
	 * @return float
	 */
	public static function apply_discount( $price, $config ) {
		$type  = isset( $config['discount_type'] ) ? $config['discount_type'] : 'none';
		$value = isset( $config['discount_value'] ) ? (float) $config['discount_value'] : 0;

		if ( 'percent' === $type && $value > 0 ) {
			$price = $price - ( $price * ( $value / 100 ) );
		} elseif ( 'fixed' === $type && $value > 0 ) {
			$price = $price - $value;
		}

		return max( 0, $price );
	}

	/**
	 * Sum of surcharges from the chosen swap options in a resolved selection.
	 *
	 * @param array $selection Map of group_id => option (with 'surcharge').
	 * @return float
	 */
	public static function selection_surcharge( $selection ) {
		$total = 0.0;
		if ( empty( $selection ) || ! is_array( $selection ) ) {
			return $total;
		}
		foreach ( $selection as $option ) {
			if ( isset( $option['surcharge'] ) ) {
				$total += (float) $option['surcharge'];
			}
		}
		return $total;
	}

	/**
	 * Final unit price for a resolved bundle line: base (from the original
	 * components) plus the surcharges of the chosen swaps.
	 *
	 * @param int   $bundle_id  Bundle ID.
	 * @param array $components  Resolved components (unused for base; kept for API).
	 * @param array $selection   Applied swap selection (index => { surcharge }).
	 * @param array $config       Config.
	 * @return float
	 */
	public static function line_price( $bundle_id, $components, $selection = array(), $config = null ) {
		if ( null === $config ) {
			$config = OC_Bundles_Helpers::get_config( $bundle_id );
		}
		$base      = self::base_price( $bundle_id, $config['components'], $config );
		$surcharge = self::selection_surcharge( $selection );
		return max( 0, round( $base + $surcharge, wc_get_price_decimals() ) );
	}
}
