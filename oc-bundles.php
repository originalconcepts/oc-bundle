<?php
/**
 * Plugin Name:       Original Concepts Bundles
 * Plugin URI:        https://originalconcepts.co.il/
 * Description:       WooCommerce product bundles: a "Bundle" product type made of products and quantities, with flexible pricing, per-product swapping, and component-level stock. Works with or without the OC Sale Units plugin, in any theme.
 * Version:           1.4.0
 * Author:            Original Concepts
 * Author URI:        https://originalconcepts.co.il/
 * License:           GPL-2.0+
 * Text Domain:       oc-bundles
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * Update URI:        https://github.com/originalconcepts/oc-bundle
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

define( 'OC_BUNDLES_VERSION', '1.4.0' );
define( 'OC_BUNDLES_FILE', __FILE__ );
define( 'OC_BUNDLES_PATH', plugin_dir_path( __FILE__ ) );
define( 'OC_BUNDLES_URL', plugin_dir_url( __FILE__ ) );
define( 'OC_BUNDLES_PRODUCT_TYPE', 'oc_bundle' );

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OC_BUNDLES_FILE, true );
		}
	}
);

/**
 * Ensure the custom product-type term exists.
 */
register_activation_hook(
	__FILE__,
	function () {
		if ( ! taxonomy_exists( 'product_type' ) ) {
			// WooCommerce not fully loaded; the term will be created on first save.
			return;
		}
		if ( ! get_term_by( 'slug', OC_BUNDLES_PRODUCT_TYPE, 'product_type' ) ) {
			wp_insert_term( OC_BUNDLES_PRODUCT_TYPE, 'product_type' );
		}
	}
);

/**
 * Boot the plugin once WooCommerce is loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'The "Bundles" add-on requires WooCommerce to be active.', 'oc-bundles' ) . '</p></div>';
				}
			);
			return;
		}

		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-helpers.php';
		require_once OC_BUNDLES_PATH . 'includes/adapters/interface-oc-bundles-source.php';
		require_once OC_BUNDLES_PATH . 'includes/adapters/class-oc-bundles-source-native.php';
		require_once OC_BUNDLES_PATH . 'includes/adapters/class-oc-bundles-source-ocwsu.php';
		require_once OC_BUNDLES_PATH . 'includes/adapters/class-oc-bundles-source-factory.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-product-type.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-stock.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-pricing.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-admin.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-frontend.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-cart.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-order.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-invoice.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-deliz-popup.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-swap.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-updater.php';
		require_once OC_BUNDLES_PATH . 'includes/class-oc-bundles-api.php';

		OC_Bundles_Product_Type::init();
		OC_Bundles_Pricing::init();
		OC_Bundles_Admin::init();
		OC_Bundles_Frontend::init();
		OC_Bundles_Cart::init();
		OC_Bundles_Order::init();
		OC_Bundles_Invoice::init();
		OC_Bundles_Deliz_Popup::init();
		OC_Bundles_Swap::init();
		OC_Bundles_Updater::init();
		OC_Bundles_API::init();

		load_plugin_textdomain( 'oc-bundles', false, dirname( plugin_basename( OC_BUNDLES_FILE ) ) . '/languages' );
	},
	20
);
