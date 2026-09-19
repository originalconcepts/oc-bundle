<?php
/**
 * REST API for external sync (Giorgio).
 *
 * Namespace: oc-bundles/v1
 *   GET    /bundles                    List bundles (paginated).
 *   POST   /bundles                    Create a bundle.
 *   GET    /bundles/{id}               Read a single bundle.
 *   PUT    /bundles/{id}               Update a bundle (full or partial).
 *   DELETE /bundles/{id}               Delete a bundle.
 *   GET    /bundles/{id}/availability  Live price + component-level stock.
 *
 * Auth: send the API key in the `X-OC-Bundles-Key` header (retrieve it under
 * Settings → Bundles API), or authenticate as a user who can manage_woocommerce
 * (WordPress Application Passwords work for this).
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_API {

	const NS         = 'oc-bundles/v1';
	const KEY_OPTION = 'oc_bundles_api_key';

	/**
	 * Hook the API and its settings screen.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_regenerate_key' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );
		// `?external_id=` on GET /bundles: wc_get_products() has no meta filter of its
		// own, so the custom query var is turned into a meta query here.
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'filter_products_query' ), 10, 2 );
	}

	/**
	 * Map the `oc_external_id` query var of wc_get_products() to a meta query.
	 *
	 * @param array $wp_query_args WP_Query args.
	 * @param array $query_vars    Query vars.
	 * @return array
	 */
	public static function filter_products_query( $wp_query_args, $query_vars ) {
		if ( empty( $query_vars['oc_external_id'] ) ) {
			return $wp_query_args;
		}
		if ( empty( $wp_query_args['meta_query'] ) || ! is_array( $wp_query_args['meta_query'] ) ) {
			$wp_query_args['meta_query'] = array();
		}
		$wp_query_args['meta_query'][] = array(
			'key'   => OC_Bundles_Helpers::EXTERNAL_ID_META,
			'value' => (string) $query_vars['oc_external_id'],
		);
		unset( $wp_query_args['oc_external_id'] );
		return $wp_query_args;
	}

	/**
	 * Return the API key, creating one on first use.
	 *
	 * @return string
	 */
	public static function ensure_key() {
		$key = get_option( self::KEY_OPTION );
		if ( ! $key ) {
			$key = wp_generate_password( 40, false );
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	/* --------------------------------------------------------------------- *
	 * Routes
	 * --------------------------------------------------------------------- */

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/bundles',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_bundles' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'args'                => array(
						'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page' => array( 'default' => 20, 'sanitize_callback' => 'absint' ),
						'search'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'status'   => array( 'default' => 'any', 'sanitize_callback' => 'sanitize_key' ),
						'external_id' => array( 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_bundle' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/bundles/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_bundle' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE, // PUT / PATCH
					'callback'            => array( __CLASS__, 'update_bundle' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_bundle' ),
					'permission_callback' => array( __CLASS__, 'check_permission' ),
					'args'                => array(
						'force' => array( 'default' => false ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/bundles/(?P<id>\d+)/availability',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_availability' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Auth
	 * --------------------------------------------------------------------- */

	/**
	 * Allow access via a matching API key header or a capable logged-in user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_permission( $request ) {
		$provided = $request->get_header( 'x_oc_bundles_key' );
		if ( ! $provided ) {
			$provided = $request->get_param( 'api_key' );
		}
		$key = self::ensure_key();
		if ( $provided && hash_equals( $key, (string) $provided ) ) {
			return true;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		return new WP_Error(
			'oc_bundles_unauthorized',
			__( 'Authentication required: provide a valid X-OC-Bundles-Key header.', 'oc-bundles' ),
			array( 'status' => 401 )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Callbacks
	 * --------------------------------------------------------------------- */

	public static function list_bundles( $request ) {
		$page     = max( 1, (int) $request['page'] );
		$per_page = min( 100, max( 1, (int) $request['per_page'] ) );
		$status   = $request['status'] ? $request['status'] : 'any';

		$args = array(
			'type'     => OC_BUNDLES_PRODUCT_TYPE,
			'status'   => ( 'any' === $status ) ? array( 'publish', 'draft', 'pending', 'private' ) : $status,
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'return'   => 'objects',
		);
		if ( ! empty( $request['search'] ) ) {
			$args['s'] = $request['search'];
		}
		if ( ! empty( $request['external_id'] ) ) {
			$args['oc_external_id'] = (string) $request['external_id'];
		}

		$result  = wc_get_products( $args );
		$bundles = array();
		foreach ( $result->products as $product ) {
			$bundles[] = self::bundle_to_array( $product );
		}

		$response = rest_ensure_response( $bundles );
		$response->header( 'X-WP-Total', (int) $result->total );
		$response->header( 'X-WP-TotalPages', (int) $result->max_num_pages );
		return $response;
	}

	public static function get_bundle( $request ) {
		$product = self::get_bundle_product( (int) $request['id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		return rest_ensure_response( self::bundle_to_array( $product ) );
	}

	public static function create_bundle( $request ) {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}

		$product = new WC_Product_OC_Bundle();
		$id      = self::save_bundle( $product, $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$response = rest_ensure_response( self::bundle_to_array( wc_get_product( $id ) ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function update_bundle( $request ) {
		$product = self::get_bundle_product( (int) $request['id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $request->get_params();
		}
		$id = self::save_bundle( $product, $data );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( self::bundle_to_array( wc_get_product( $id ) ) );
	}

	public static function delete_bundle( $request ) {
		$product = self::get_bundle_product( (int) $request['id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$force = filter_var( $request['force'], FILTER_VALIDATE_BOOLEAN );
		$data  = self::bundle_to_array( $product );
		$product->delete( $force );
		return rest_ensure_response(
			array(
				'deleted'  => true,
				'force'    => $force,
				'previous' => $data,
			)
		);
	}

	public static function get_availability( $request ) {
		$product = self::get_bundle_product( (int) $request['id'] );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$id        = $product->get_id();
		$config    = OC_Bundles_Helpers::get_config( $id );
		$selection = OC_Bundles_Helpers::apply_auto_swaps( $config, array() );
		$resolved  = OC_Bundles_Cart::resolve_components( $config, $selection );
		$price     = OC_Bundles_Pricing::line_price( $id, $resolved['components'], $resolved['selection'], $config );
		$available = OC_Bundles_Stock::available_quantity( $id, $resolved['components'] );

		$components = array();
		foreach ( $resolved['components'] as $i => $c ) {
			$c   = OC_Bundles_Helpers::normalize_component( $c, $i );
			$pid = OC_Bundles_Helpers::effective_id( $c );
			$src = OC_Bundles_Source_Factory::get( $pid );
			$components[] = array(
				'key'        => $c['key'],
				'product_id' => $c['product_id'],
				'variation_id' => $c['variation_id'],
				'qty'        => (float) $c['qty'],
				'unit'       => $c['unit'],
				'in_stock'   => $src->is_in_stock(),
				'stock'      => $src->stock(),
			);
		}

		return rest_ensure_response(
			array(
				'id'                 => $id,
				'price'              => (float) $price,
				'available_quantity' => (int) $available,
				'in_stock'           => $available > 0,
				'components'         => $components,
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Serialization / persistence
	 * --------------------------------------------------------------------- */

	/**
	 * Load a product and ensure it is a bundle.
	 *
	 * @param int $id Product ID.
	 * @return WC_Product|WP_Error
	 */
	protected static function get_bundle_product( $id ) {
		$product = $id ? wc_get_product( $id ) : false;
		if ( ! $product || OC_BUNDLES_PRODUCT_TYPE !== $product->get_type() ) {
			return new WP_Error( 'oc_bundles_not_found', __( 'Bundle not found.', 'oc-bundles' ), array( 'status' => 404 ) );
		}
		return $product;
	}

	/**
	 * Full bundle representation.
	 *
	 * @param WC_Product $product Bundle product.
	 * @return array
	 */
	protected static function bundle_to_array( $product ) {
		$id        = $product->get_id();
		$config    = OC_Bundles_Helpers::get_config( $id );
		$selection = OC_Bundles_Helpers::apply_auto_swaps( $config, array() );
		$resolved  = OC_Bundles_Cart::resolve_components( $config, $selection );
		$price     = OC_Bundles_Pricing::line_price( $id, $resolved['components'], $resolved['selection'], $config );
		$base      = OC_Bundles_Pricing::base_price( $id, $config['components'], $config );
		$available = OC_Bundles_Stock::available_quantity( $id, $resolved['components'] );

		$components = array();
		foreach ( $config['components'] as $i => $c ) {
			$c   = OC_Bundles_Helpers::normalize_component( $c, $i );
			$pid = OC_Bundles_Helpers::effective_id( $c );
			$p   = $pid ? wc_get_product( $pid ) : false;

			$swaps = array();
			foreach ( $c['swaps'] as $s ) {
				$sid = $s['variation_id'] ? $s['variation_id'] : $s['product_id'];
				$sp  = $sid ? wc_get_product( $sid ) : false;
				$swaps[] = array(
					'product_id'   => $s['product_id'],
					'variation_id' => $s['variation_id'],
					'surcharge'    => (float) $s['surcharge'],
					'qty'          => ( isset( $s['qty'] ) && (float) $s['qty'] > 0 ) ? (float) $s['qty'] : null,
					'name'         => $sp ? $sp->get_name() : '',
					'sku'          => $sp ? $sp->get_sku() : '',
				);
			}

			$components[] = array(
				'key'            => $c['key'],
				'product_id'     => $c['product_id'],
				'variation_id'   => $c['variation_id'],
				'name'           => $p ? $p->get_name() : '',
				'sku'            => $p ? $p->get_sku() : '',
				'qty'            => (float) $c['qty'],
				'unit'           => $c['unit'],
				'unit_label'     => $c['unit_label'],
				'unit_weight'    => (float) $c['unit_weight'],
				'mode'           => $c['mode'],
				'quantity_label' => OC_Bundles_Helpers::quantity_label( $c ),
				'description'    => $c['description'],
				'swappable'      => ( 'yes' === $c['swappable'] ),
				'swaps'          => $swaps,
			);
		}

		return array(
			'id'                      => $id,
			'external_id'             => OC_Bundles_Helpers::external_id( $id ),
			'name'                    => $product->get_name(),
			'slug'                    => $product->get_slug(),
			'sku'                     => $product->get_sku(),
			'status'                  => $product->get_status(),
			'permalink'               => get_permalink( $id ),
			'date_modified'           => $product->get_date_modified() ? $product->get_date_modified()->date( 'c' ) : null,
			'short_description'       => $product->get_short_description(),
			'price'                   => (float) $price,
			'base_price'              => (float) $base,
			'available_quantity'      => (int) $available,
			'in_stock'                => $available > 0,
			'pricing'                 => array(
				'mode'           => $config['pricing_mode'],
				'fixed_price'    => (float) $config['fixed_price'],
				'discount_type'  => $config['discount_type'],
				'discount_value' => (float) $config['discount_value'],
				'oos_behavior'   => $config['oos_behavior'],
			),
			'layout'                  => $config['layout'],
			'cart_display'            => $config['cart_display'],
			'invoice_display'         => $config['invoice_display'],
			'show_components_in_desc' => ( 'yes' === $config['show_components_in_desc'] ),
			'hide_price_labels'       => ( 'yes' === $config['hide_price_labels'] ),
			'reweigh_price'           => ( 'yes' === $config['reweigh_price'] ),
			'components'              => $components,
		);
	}

	/**
	 * Create/update a bundle product from request data.
	 *
	 * @param WC_Product $product Product (new or existing bundle).
	 * @param array      $data    Request body.
	 * @return int|WP_Error Product ID.
	 */
	protected static function save_bundle( $product, $data ) {
		// Validate the components BEFORE the product is written, so a rejected request
		// never leaves a half-created bundle behind.
		$components = null;
		if ( isset( $data['components'] ) && is_array( $data['components'] ) ) {
			$components = self::sanitize_components( $data['components'] );
			if ( is_wp_error( $components ) ) {
				return $components;
			}
		}

		if ( isset( $data['name'] ) ) {
			$product->set_name( sanitize_text_field( $data['name'] ) );
		}
		if ( isset( $data['sku'] ) ) {
			try {
				$product->set_sku( wc_clean( $data['sku'] ) );
			} catch ( Exception $e ) {
				return new WP_Error( 'oc_bundles_invalid_sku', $e->getMessage(), array( 'status' => 400 ) );
			}
		}
		if ( isset( $data['status'] ) && in_array( $data['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$product->set_status( $data['status'] );
		}
		if ( isset( $data['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $data['short_description'] ) );
		}
		if ( isset( $data['catalog_visibility'] ) && in_array( $data['catalog_visibility'], array( 'visible', 'catalog', 'search', 'hidden' ), true ) ) {
			$product->set_catalog_visibility( $data['catalog_visibility'] );
		}

		$id = $product->save();
		if ( ! $id ) {
			return new WP_Error( 'oc_bundles_save_failed', __( 'Could not save the bundle product.', 'oc-bundles' ), array( 'status' => 500 ) );
		}

		$config = OC_Bundles_Helpers::get_config( $id );

		if ( isset( $data['pricing'] ) && is_array( $data['pricing'] ) ) {
			$pr = $data['pricing'];
			if ( isset( $pr['mode'] ) ) {
				$config['pricing_mode'] = ( 'sum' === $pr['mode'] ) ? 'sum' : 'fixed';
			}
			if ( isset( $pr['fixed_price'] ) ) {
				$config['fixed_price'] = (float) $pr['fixed_price'];
			}
			if ( isset( $pr['discount_type'] ) ) {
				$dtype = ( 'amount' === $pr['discount_type'] ) ? 'fixed' : $pr['discount_type'];
				if ( in_array( $dtype, array( 'none', 'percent', 'fixed' ), true ) ) {
					$config['discount_type'] = $dtype;
				}
			}
			if ( isset( $pr['discount_value'] ) ) {
				$config['discount_value'] = (float) $pr['discount_value'];
			}
			if ( isset( $pr['oos_behavior'] ) && in_array( $pr['oos_behavior'], array( 'unavailable', 'swap' ), true ) ) {
				$config['oos_behavior'] = $pr['oos_behavior'];
			}
		}

		if ( isset( $data['layout'] ) && in_array( $data['layout'], array( 'grid', 'list' ), true ) ) {
			$config['layout'] = $data['layout'];
		}
		if ( isset( $data['cart_display'] ) && in_array( $data['cart_display'], array( 'name_with_components', 'name_only', 'line' ), true ) ) {
			$config['cart_display'] = $data['cart_display'];
		}
		if ( isset( $data['invoice_display'] ) && in_array( $data['invoice_display'], array( 'bundle', 'components' ), true ) ) {
			$config['invoice_display'] = $data['invoice_display'];
		}
		if ( isset( $data['show_components_in_desc'] ) ) {
			$config['show_components_in_desc'] = filter_var( $data['show_components_in_desc'], FILTER_VALIDATE_BOOLEAN ) ? 'yes' : 'no';
		}
		if ( isset( $data['hide_price_labels'] ) ) {
			$config['hide_price_labels'] = filter_var( $data['hide_price_labels'], FILTER_VALIDATE_BOOLEAN ) ? 'yes' : 'no';
		}
		if ( isset( $data['reweigh_price'] ) ) {
			$config['reweigh_price'] = filter_var( $data['reweigh_price'], FILTER_VALIDATE_BOOLEAN ) ? 'yes' : 'no';
		}
		if ( null !== $components ) {
			$config['components'] = $components;
		}

		foreach ( array_keys( OC_Bundles_Helpers::defaults() ) as $key ) {
			update_post_meta( $id, '_oc_bundle_' . $key, $config[ $key ] );
		}

		// External (Giorgio) id: not a pricing key, kept outside the config. Empty clears it.
		if ( isset( $data['external_id'] ) && is_scalar( $data['external_id'] ) ) {
			$external_id = sanitize_text_field( (string) $data['external_id'] );
			$external_id = function_exists( 'mb_substr' ) ? mb_substr( $external_id, 0, 100 ) : substr( $external_id, 0, 100 );
			if ( '' === $external_id ) {
				delete_post_meta( $id, OC_Bundles_Helpers::EXTERNAL_ID_META );
			} else {
				update_post_meta( $id, OC_Bundles_Helpers::EXTERNAL_ID_META, $external_id );
			}
		}

		// Keep WooCommerce's price meta in sync for catalog/sorting (regular + sale).
		OC_Bundles_Pricing::sync_price_meta( $id, $config );

		return $id;
	}

	/**
	 * Sanitize an incoming components array. Unit/mode are auto-derived from the
	 * product when not supplied, so Giorgio can send just product_id + qty. A key sent
	 * by the caller is stored verbatim (trimmed to 40 chars); a missing one is derived.
	 *
	 * @param array $input Components.
	 * @return array|WP_Error Error (400, oc_bundles_invalid_component) when a component's product does not exist.
	 */
	protected static function sanitize_components( $input ) {
		$out = array();
		foreach ( $input as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$product_id   = isset( $c['product_id'] ) ? absint( $c['product_id'] ) : 0;
			$variation_id = isset( $c['variation_id'] ) ? absint( $c['variation_id'] ) : 0;
			if ( ! $product_id && ! $variation_id ) {
				continue;
			}

			$effective = $variation_id ? $variation_id : $product_id;
			if ( ! wc_get_product( $effective ) ) {
				return new WP_Error(
					'oc_bundles_invalid_component',
					/* translators: %d is a product ID. */
					sprintf( __( 'Component product %d does not exist.', 'oc-bundles' ), $effective ),
					array( 'status' => 400 )
				);
			}

			$spec = OC_Bundles_Helpers::product_spec( $effective );

			$swaps = array();
			if ( ! empty( $c['swaps'] ) && is_array( $c['swaps'] ) ) {
				foreach ( $c['swaps'] as $s ) {
					if ( ! is_array( $s ) ) {
						continue;
					}
					$spid = isset( $s['product_id'] ) ? absint( $s['product_id'] ) : 0;
					$svid = isset( $s['variation_id'] ) ? absint( $s['variation_id'] ) : 0;
					if ( ! $spid && ! $svid ) {
						continue;
					}
					$swaps[] = array(
						'product_id'   => $spid,
						'variation_id' => $svid,
						'surcharge'    => isset( $s['surcharge'] ) ? (float) $s['surcharge'] : 0,
						'qty'          => ( isset( $s['qty'] ) && is_numeric( $s['qty'] ) && (float) $s['qty'] > 0 ) ? (float) $s['qty'] : 0,
					);
				}
			}

			$swappable = ! empty( $c['swappable'] ) ? 'yes' : 'no';

			$key = isset( $c['key'] ) ? OC_Bundles_Helpers::sanitize_key_string( $c['key'] ) : '';
			if ( '' === $key ) {
				$key = 'p' . $product_id . '-v' . $variation_id . '-' . count( $out );
			}

			$out[] = array(
				'key'          => $key,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'qty'          => isset( $c['qty'] ) ? (float) $c['qty'] : 1,
				'unit'         => isset( $c['unit'] ) ? sanitize_text_field( $c['unit'] ) : $spec['unit'],
				'unit_label'   => isset( $c['unit_label'] ) ? sanitize_text_field( $c['unit_label'] ) : $spec['unit_label'],
				'unit_weight'  => isset( $c['unit_weight'] ) ? (float) $c['unit_weight'] : (float) $spec['unit_weight'],
				'mode'         => isset( $c['mode'] ) ? sanitize_text_field( $c['mode'] ) : $spec['mode'],
				'description'  => isset( $c['description'] ) ? sanitize_text_field( $c['description'] ) : '',
				'swappable'    => $swappable,
				'swaps'        => ( 'yes' === $swappable ) ? $swaps : array(),
			);
		}
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * Settings screen (retrieve / regenerate the API key)
	 * --------------------------------------------------------------------- */

	public static function add_settings_page() {
		add_options_page(
			__( 'Bundles API', 'oc-bundles' ),
			__( 'Bundles API', 'oc-bundles' ),
			'manage_woocommerce',
			'oc-bundles-api',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function maybe_regenerate_key() {
		if ( empty( $_POST['oc_bundles_regenerate'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'oc_bundles_api_key' ) ) {
			return;
		}
		update_option( self::KEY_OPTION, wp_generate_password( 40, false ), false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'oc-bundles-api', 'regenerated' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Save the plugin-wide settings shown on the Bundles API page.
	 */
	public static function maybe_save_settings() {
		if ( empty( $_POST['oc_bundles_save_settings'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'oc_bundles_settings' ) ) {
			return;
		}
		update_option( OC_Bundles_Helpers::PROMOTIONS_OPTION, empty( $_POST[ OC_Bundles_Helpers::PROMOTIONS_OPTION ] ) ? 'no' : 'yes', false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'oc-bundles-api', 'saved' => '1' ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function render_settings_page() {
		$key  = self::ensure_key();
		$base = esc_url_raw( rest_url( self::NS ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Bundles API', 'oc-bundles' ); ?></h1>
			<?php if ( ! empty( $_GET['regenerated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'A new API key was generated.', 'oc-bundles' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'oc-bundles' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Use these details to connect an external system (e.g. Giorgio) to the bundles API.', 'oc-bundles' ); ?></p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Base URL', 'oc-bundles' ); ?></th>
					<td><code><?php echo esc_html( $base ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'API key', 'oc-bundles' ); ?></th>
					<td>
						<input type="text" class="regular-text code" readonly value="<?php echo esc_attr( $key ); ?>" onclick="this.select();" style="width:30em;" />
						<p class="description"><?php esc_html_e( 'Send this as the HTTP header: X-OC-Bundles-Key', 'oc-bundles' ); ?></p>
					</td>
				</tr>
			</table>
			<form method="post">
				<?php wp_nonce_field( 'oc_bundles_api_key' ); ?>
				<input type="hidden" name="oc_bundles_regenerate" value="1" />
				<?php submit_button( __( 'Regenerate key', 'oc-bundles' ), 'secondary', 'submit', true, array( 'onclick' => "return confirm('" . esc_js( __( 'Regenerating will break existing integrations until they are updated. Continue?', 'oc-bundles' ) ) . "');" ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Promotions', 'oc-bundles' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'oc_bundles_settings' ); ?>
				<input type="hidden" name="oc_bundles_save_settings" value="1" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Promotions', 'oc-bundles' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( OC_Bundles_Helpers::PROMOTIONS_OPTION ); ?>" value="yes" <?php checked( OC_Bundles_Helpers::promotions_allowed() ); ?> />
								<?php esc_html_e( 'Allow store promotions to apply to bundles', 'oc-bundles' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When on, the bundle price is set early in the cart calculation so promotion plugins can discount it. Turn off to restore the previous behaviour.', 'oc-bundles' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
