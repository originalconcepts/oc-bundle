<?php
/**
 * Deliz theme compatibility — render bundles inside the theme's product popup,
 * plus a cart-line price fix that applies regardless of theme.
 *
 * The deliz-short theme never renders WooCommerce's single-product template:
 * product cards open an "ed-product-popup" modal whose markup is built in JS
 * from the ed/v1/product-popup REST payload, and add-to-cart goes through the
 * theme's own ed/v1/add-to-cart route with a JSON body. This class:
 *
 *   1. Loads the bundle assets on every storefront view that can open a popup
 *      (the plugin's own enqueue only covers is_product() / is_cart()).
 *   2. Appends a rendered components block to the popup REST payload.
 *   3. Copies the chosen swaps from the add-to-cart JSON body into $_POST,
 *      where OC_Bundles_Cart::add_cart_item_data expects to find them.
 *   4. Gives a bundle's contents a full-width line under its float-cart row
 *      instead of the theme's narrow details column, with the theme's edit button
 *      under it (oc-bundles-deliz-cart.js). The popup then reopens with the swaps
 *      that cart line holds — see requested_selection().
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Deliz_Popup {

	const HANDLE       = 'oc-bundles-deliz-popup';
	const REST_POPUP   = '/ed/v1/product-popup';
	const REST_ADDCART = '/ed/v1/add-to-cart';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'filter_popup_payload' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'bridge_selection_to_post' ), 10, 3 );
	}

	/**
	 * Only engage for the deliz-short theme (parent or child).
	 *
	 * @return bool
	 */
	public static function is_supported_theme() {
		$theme = wp_get_theme();
		if ( ! $theme ) {
			return false;
		}
		return in_array( 'deliz-short', array( $theme->get_stylesheet(), $theme->get_template() ), true );
	}

	/**
	 * Cache-busting version for a plugin asset.
	 *
	 * The site sits behind a CDN that caches static assets, so a version tied to
	 * the plugin release would keep serving stale files between releases.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	protected static function asset_version( $relative ) {
		$path  = OC_BUNDLES_PATH . ltrim( $relative, '/' );
		$mtime = file_exists( $path ) ? filemtime( $path ) : 0;
		return $mtime ? OC_BUNDLES_VERSION . '.' . $mtime : OC_BUNDLES_VERSION;
	}

	/**
	 * Load bundle CSS/JS wherever a product popup can be opened.
	 */
	public static function enqueue() {
		if ( is_admin() || ! self::is_supported_theme() ) {
			return;
		}

		// The popup opens from shop, category archives, search, brand pages and the
		// front page — none of which satisfy the plugin's is_product() condition.
		wp_enqueue_style(
			'oc-bundles',
			OC_BUNDLES_URL . 'assets/css/oc-bundles.css',
			array(),
			self::asset_version( 'assets/css/oc-bundles.css' )
		);
		wp_enqueue_style(
			self::HANDLE,
			OC_BUNDLES_URL . 'assets/css/oc-bundles-deliz-popup.css',
			array( 'oc-bundles' ),
			self::asset_version( 'assets/css/oc-bundles-deliz-popup.css' )
		);
		wp_enqueue_script(
			'oc-bundles',
			OC_BUNDLES_URL . 'assets/js/oc-bundles.js',
			array( 'jquery' ),
			self::asset_version( 'assets/js/oc-bundles.js' ),
			true
		);

		// OC_Bundles_Frontend::enqueue() only localizes on product/cart views, so make
		// sure ocBundles exists here too.
		wp_localize_script(
			'oc-bundles',
			'ocBundles',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'oc_bundles_swap' ),
				'currency' => array(
					'symbol'      => get_woocommerce_currency_symbol(),
					'decimals'    => wc_get_price_decimals(),
					'decimalSep'  => wc_get_price_decimal_separator(),
					'thousandSep' => wc_get_price_thousand_separator(),
					'format'      => get_woocommerce_price_format(),
				),
				'i18n'     => array(
					'chooseAlternative' => __( 'Choose an alternative product', 'oc-bundles' ),
					'apply'             => __( 'Confirm swap', 'oc-bundles' ),
					'close'             => __( 'Close', 'oc-bundles' ),
					'noAlternatives'    => __( 'No alternative products available.', 'oc-bundles' ),
				),
			)
		);

		wp_enqueue_script(
			self::HANDLE,
			OC_BUNDLES_URL . 'assets/js/oc-bundles-deliz-popup.js',
			array( 'jquery', 'oc-bundles' ),
			self::asset_version( 'assets/js/oc-bundles-deliz-popup.js' ),
			true
		);

		// The float cart is on every storefront view, not only where a popup opens.
		wp_enqueue_style(
			'oc-bundles-deliz-cart',
			OC_BUNDLES_URL . 'assets/css/oc-bundles-deliz-cart.css',
			array(),
			self::asset_version( 'assets/css/oc-bundles-deliz-cart.css' )
		);
		wp_enqueue_script(
			'oc-bundles-deliz-cart',
			OC_BUNDLES_URL . 'assets/js/oc-bundles-deliz-cart.js',
			array(),
			self::asset_version( 'assets/js/oc-bundles-deliz-cart.js' ),
			true
		);
		wp_localize_script(
			'oc-bundles-deliz-cart',
			'ocBundlesDelizCart',
			array(
				// The theme's own wording, so the bundle's button reads like every other row's.
				'editLabel' => __( 'Edit', 'deliz-short' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
				'editAria'  => __( 'Edit product', 'deliz-short' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			)
		);
	}

	/**
	 * The theme adds to cart via its own REST route with a JSON body, so the
	 * selection never reaches $_POST on its own. Copy it across before dispatch
	 * so OC_Bundles_Cart::add_cart_item_data picks it up unchanged.
	 *
	 * @param mixed           $result  Dispatch result (null to continue).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function bridge_selection_to_post( $result, $server, $request ) {
		if ( ! $request instanceof WP_REST_Request || self::REST_ADDCART !== $request->get_route() ) {
			return $result;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ! isset( $params['oc_bundle_selection'] ) ) {
			return $result;
		}

		$selection = $params['oc_bundle_selection'];
		if ( ! is_string( $selection ) ) {
			$selection = wp_json_encode( $selection );
		}

		// add_cart_item_data() runs json_decode( wp_unslash( ... ) ) on this.
		$_POST['oc_bundle_selection'] = wp_slash( $selection );

		return $result;
	}

	/**
	 * Append bundle data to the theme's product-popup REST response.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_HTTP_Response
	 */
	public static function filter_popup_payload( $response, $server, $request ) {
		if ( ! self::is_supported_theme() ) {
			return $response;
		}
		if ( ! $request instanceof WP_REST_Request || self::REST_POPUP !== $request->get_route() ) {
			return $response;
		}
		if ( ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) || empty( $data['id'] ) ) {
			return $response;
		}

		$product = wc_get_product( (int) $data['id'] );
		if ( ! $product || ! $product->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
			return $response;
		}

		$payload = self::build_bundle_payload( $product, self::requested_selection( $request ) );
		if ( $payload ) {
			$data['oc_bundle'] = $payload;
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Swap choices sent when the float cart's edit button reopens a bundle line
	 * (index => swap index), so the popup shows the bundle the customer put together.
	 * An index or swap that isn't valid for the bundle is dropped by resolve_components().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	protected static function requested_selection( $request ) {
		$raw = $request->get_param( 'oc_bundle_selection' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$selection = array();
		foreach ( $decoded as $index => $key ) {
			if ( is_numeric( $index ) && is_numeric( $key ) && (int) $key >= 0 ) {
				$selection[ (int) $index ] = (int) $key;
			}
		}
		return $selection;
	}

	/**
	 * Build the components block exactly as the plugin's own template would.
	 *
	 * @param WC_Product $product   Bundle product.
	 * @param array      $selection Swap choices to open with (index => swap index).
	 * @return array|null
	 */
	protected static function build_bundle_payload( $product, $selection = array() ) {
		if ( ! class_exists( 'OC_Bundles_Helpers' ) || ! class_exists( 'OC_Bundles_Frontend' ) ) {
			return null;
		}

		$bundle_id = $product->get_id();
		$config    = OC_Bundles_Helpers::get_config( $bundle_id );
		if ( empty( $config['components'] ) ) {
			return null;
		}

		// Mirror OC_Bundles_Frontend::render(): auto-swap out-of-stock components
		// so display and pricing match the single-product template.
		$selection = OC_Bundles_Helpers::apply_auto_swaps( $config, $selection );
		$resolved  = OC_Bundles_Cart::resolve_components( $config, $selection );
		$applied   = $resolved['selection'];

		$base     = OC_Bundles_Pricing::base_price( $bundle_id, $config['components'], $config );
		$discount = OC_Bundles_Pricing::discount_amount( $bundle_id, $config['components'], $config );
		$price    = OC_Bundles_Pricing::line_price( $bundle_id, $resolved['components'], $applied, $config );

		ob_start();
		OC_Bundles_Frontend::render_components( $config, $bundle_id, $selection, $applied );
		$components_html = ob_get_clean();

		return array(
			'bundle_id'       => $bundle_id,
			'base_price'      => (float) $base,
			'discount_amount' => (float) $discount,
			'price'           => (float) $price,
			// Discounted: regular struck through, then the sale price — WooCommerce's sale format.
			'price_html'      => $discount > 0 ? wc_format_sale_price( wc_price( $price + $discount ), wc_price( $price ) ) : wc_price( $price ),
			'layout'          => isset( $config['layout'] ) ? $config['layout'] : 'grid',
			'count'           => count( $config['components'] ),
			'selection'       => (object) $selection,
			'components_html' => $components_html,
			'heading'         => __( "What's in the bundle", 'oc-bundles' ),
		);
	}
}
