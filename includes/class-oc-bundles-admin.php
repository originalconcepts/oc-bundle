<?php
/**
 * Admin — the "Bundle contents" product-data tab and saving.
 * Quantity unit is derived automatically from each product's OC Sale Units config.
 * Swaps are configured per-product (no groups).
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Admin {

	public static function init() {
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_oc_bundles_product_config', array( __CLASS__, 'ajax_product_config' ) );
	}

	public static function add_tab( $tabs ) {
		$tabs['oc_bundle'] = array(
			'label'    => __( 'Bundle contents', 'oc-bundles' ),
			'target'   => 'oc_bundle_data',
			'class'    => array( 'show_if_' . OC_BUNDLES_PRODUCT_TYPE ),
			'priority' => 15,
		);
		return $tabs;
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// The order screen only needs the stylesheet, for the weighed-quantity fields
		// OC_Bundles_Order renders under a bundle line ('shop_order' pre-HPOS,
		// 'woocommerce_page_wc-orders' with HPOS on).
		if ( 'shop_order' === $screen->id || 'woocommerce_page_wc-orders' === $screen->id ) {
			wp_enqueue_style( 'oc-bundles-admin', OC_BUNDLES_URL . 'assets/css/oc-bundles-admin.css', array(), OC_BUNDLES_VERSION );
			return;
		}

		if ( 'product' !== $screen->id ) {
			return;
		}
		wp_enqueue_script( 'oc-bundles-admin', OC_BUNDLES_URL . 'assets/js/oc-bundles-admin.js', array( 'jquery', 'wc-enhanced-select', 'jquery-ui-sortable' ), OC_BUNDLES_VERSION, true );
		wp_enqueue_style( 'oc-bundles-admin', OC_BUNDLES_URL . 'assets/css/oc-bundles-admin.css', array(), OC_BUNDLES_VERSION );
		wp_localize_script(
			'oc-bundles-admin',
			'ocBundlesAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'oc_bundles_admin' ),
				'i18n'    => array(
					'chooseFirst'       => __( 'Choose a product first', 'oc-bundles' ),
					'variation'         => __( 'Variation', 'oc-bundles' ),
					'quantity'          => __( 'Quantity', 'oc-bundles' ),
					/* translators: %s is a weight value in kg. */
					'eachUnit'          => __( 'Each unit ≈ %s kg', 'oc-bundles' ),
					'bundleItem'        => __( 'Bundle item', 'oc-bundles' ),
					'minimize'          => __( 'Minimize', 'oc-bundles' ),
					'expand'            => __( 'Expand', 'oc-bundles' ),
					'confirmRemoveItem' => __( 'Remove this product from the bundle?', 'oc-bundles' ),
					'confirmRemoveSwap' => __( 'Remove this alternative product?', 'oc-bundles' ),
				),
			)
		);
	}

	/**
	 * Render the configuration panel.
	 */
	public static function render_panel() {
		global $post;
		$config = OC_Bundles_Helpers::get_config( $post->ID );
		// Owned by an external system (Giorgio): render read-only. The fields are
		// disabled by the admin script and save() refuses to overwrite the config.
		$locked = OC_Bundles_Helpers::is_locked( $post->ID );
		?>
		<div id="oc_bundle_data" class="panel woocommerce_options_panel oc-bundle-panel<?php echo $locked ? ' is-locked' : ''; ?>" data-locked="<?php echo $locked ? '1' : '0'; ?>">

			<?php if ( $locked ) : ?>
				<div class="oc-bundle-locked-notice"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Managed by Giorgio — edit it there.', 'oc-bundles' ); ?></div>
			<?php endif; ?>

			<div class="oc-bundle-section">
				<h4><?php esc_html_e( 'Pricing', 'oc-bundles' ); ?></h4>
				<p class="form-field oc-pricing-mode">
					<label><?php esc_html_e( 'Pricing method', 'oc-bundles' ); ?></label>
					<span class="oc-radios">
						<label class="oc-radio"><input type="radio" name="_oc_bundle_pricing_mode" value="fixed" <?php checked( $config['pricing_mode'], 'fixed' ); ?> /> <?php esc_html_e( 'Fixed bundle price', 'oc-bundles' ); ?></label>
						<label class="oc-radio"><input type="radio" name="_oc_bundle_pricing_mode" value="sum" <?php checked( $config['pricing_mode'], 'sum' ); ?> /> <?php esc_html_e( 'Sum of the products', 'oc-bundles' ); ?></label>
					</span>
				</p>
				<p class="form-field oc-fixed-price-field">
					<label for="_oc_bundle_fixed_price"><?php esc_html_e( 'Fixed price', 'oc-bundles' ); ?></label>
					<input type="number" step="0.01" min="0" name="_oc_bundle_fixed_price" id="_oc_bundle_fixed_price" value="<?php echo esc_attr( $config['fixed_price'] ); ?>" />
				</p>
				<p class="form-field oc-discount-field">
					<label for="_oc_bundle_discount_type"><?php esc_html_e( 'Bundle discount', 'oc-bundles' ); ?></label>
					<select name="_oc_bundle_discount_type" id="_oc_bundle_discount_type" class="oc-inline-select">
						<option value="none" <?php selected( $config['discount_type'], 'none' ); ?>><?php esc_html_e( 'None', 'oc-bundles' ); ?></option>
						<option value="percent" <?php selected( $config['discount_type'], 'percent' ); ?>><?php esc_html_e( 'Percentage (%)', 'oc-bundles' ); ?></option>
						<option value="fixed" <?php selected( $config['discount_type'], 'fixed' ); ?>><?php esc_html_e( 'Fixed amount', 'oc-bundles' ); ?></option>
					</select>
					<input type="number" step="0.01" min="0" name="_oc_bundle_discount_value" class="oc-discount-value" value="<?php echo esc_attr( $config['discount_value'] ); ?>" />
				</p>
				<div class="oc-pricing-toggle oc-reweigh-field">
					<label class="oc-toggle">
						<input type="checkbox" class="oc-switch" name="_oc_bundle_reweigh_price" value="yes" <?php checked( $config['reweigh_price'], 'yes' ); ?> />
						<span class="oc-toggle-ui"></span>
						<span class="oc-toggle-text"><?php esc_html_e( 'Update the price when quantities are re-weighed on the order', 'oc-bundles' ); ?></span>
					</label>
				</div>
				<div class="oc-pricing-toggle">
					<label class="oc-toggle">
						<input type="checkbox" class="oc-switch" name="_oc_bundle_hide_price_labels" value="yes" <?php checked( $config['hide_price_labels'], 'yes' ); ?> />
						<span class="oc-toggle-ui"></span>
						<span class="oc-toggle-text"><?php esc_html_e( 'Hide the price captions on the product page (show prices only)', 'oc-bundles' ); ?></span>
					</label>
				</div>
			</div>

			<div class="oc-bundle-section">
				<h4><?php esc_html_e( 'Bundle components', 'oc-bundles' ); ?></h4>
				<?php if ( OC_Bundles_Helpers::ocwsu_active() ) : ?>
					<p class="oc-hint"><?php esc_html_e( 'The unit and quantity are set automatically from the product settings (weight / units).', 'oc-bundles' ); ?></p>
				<?php endif; ?>
				<div class="oc-components-list">
					<?php
					if ( ! empty( $config['components'] ) ) {
						foreach ( $config['components'] as $i => $component ) {
							self::render_component_row( $i, $component );
						}
					}
					?>
				</div>
				<button type="button" class="button button-primary oc-add-component"><?php esc_html_e( 'Add product', 'oc-bundles' ); ?></button>
			</div>

			<div class="oc-bundle-section">
				<h4><?php esc_html_e( 'Display & stock', 'oc-bundles' ); ?></h4>
				<p class="form-field">
					<label for="_oc_bundle_layout"><?php esc_html_e( 'Component layout', 'oc-bundles' ); ?></label>
					<select name="_oc_bundle_layout" id="_oc_bundle_layout">
						<option value="grid" <?php selected( $config['layout'], 'grid' ); ?>><?php esc_html_e( 'Grid', 'oc-bundles' ); ?></option>
						<option value="list" <?php selected( $config['layout'], 'list' ); ?>><?php esc_html_e( 'List', 'oc-bundles' ); ?></option>
					</select>
				</p>
				<p class="form-field">
					<label for="_oc_bundle_cart_display"><?php esc_html_e( 'Cart display', 'oc-bundles' ); ?></label>
					<select name="_oc_bundle_cart_display" id="_oc_bundle_cart_display">
						<option value="name_only" <?php selected( $config['cart_display'], 'name_only' ); ?>><?php esc_html_e( 'Bundle name only', 'oc-bundles' ); ?></option>
						<option value="name_with_components" <?php selected( $config['cart_display'], 'name_with_components' ); ?>><?php esc_html_e( 'Bundle name + product list', 'oc-bundles' ); ?></option>
						<option value="line" <?php selected( $config['cart_display'], 'line' ); ?>><?php esc_html_e( 'Bundle name + product list on one line', 'oc-bundles' ); ?></option>
					</select>
				</p>
				<p class="form-field">
					<label for="_oc_bundle_invoice_display"><?php esc_html_e( 'Invoice display', 'oc-bundles' ); ?></label>
					<select name="_oc_bundle_invoice_display" id="_oc_bundle_invoice_display">
						<option value="bundle" <?php selected( $config['invoice_display'], 'bundle' ); ?>><?php esc_html_e( 'Bundle line (default)', 'oc-bundles' ); ?></option>
						<option value="components" <?php selected( $config['invoice_display'], 'components' ); ?>><?php esc_html_e( 'Component breakdown', 'oc-bundles' ); ?></option>
					</select>
				</p>
				<p class="form-field">
					<label for="_oc_bundle_oos_behavior"><?php esc_html_e( 'When a component is out of stock', 'oc-bundles' ); ?></label>
					<select name="_oc_bundle_oos_behavior" id="_oc_bundle_oos_behavior">
						<option value="unavailable" <?php selected( $config['oos_behavior'], 'unavailable' ); ?>><?php esc_html_e( 'Mark the bundle as unavailable', 'oc-bundles' ); ?></option>
						<option value="swap" <?php selected( $config['oos_behavior'], 'swap' ); ?>><?php esc_html_e( 'Swap automatically', 'oc-bundles' ); ?></option>
					</select>
				</p>
				<p class="form-field">
					<label for="_oc_bundle_show_components_in_desc"><?php esc_html_e( 'Show the product list in the description', 'oc-bundles' ); ?></label>
					<input type="checkbox" name="_oc_bundle_show_components_in_desc" id="_oc_bundle_show_components_in_desc" value="yes" <?php checked( $config['show_components_in_desc'], 'yes' ); ?> />
				</p>
			</div>

			<?php self::render_templates(); ?>
		</div>
		<?php
	}

	/**
	 * Render a component row (+ its swaps sub-row).
	 *
	 * @param int|string $i         Index.
	 * @param array      $component Component data.
	 */
	public static function render_component_row( $i, $component ) {
		// Existing rows have a numeric index and a (stored or derived) key; the JS
		// template row gets its key from the admin script when it is added.
		$is_stored = is_numeric( $i );
		$component = OC_Bundles_Helpers::normalize_component( $component, $is_stored ? (int) $i : null );
		$key       = $is_stored ? $component['key'] : '';
		$id        = OC_Bundles_Helpers::effective_id( $component );
		$display   = $id ? wc_get_product( $id ) : false;
		// For units_weight (variation weight) the searched product is the parent.
		$search_id = $component['product_id'] ? $component['product_id'] : $id;
		$search    = $search_id ? wc_get_product( $search_id ) : false;
		$name      = $search ? wp_strip_all_tags( $search->get_formatted_name() ) : '';
		$swappable = 'yes' === $component['swappable'];
		?>
		<div class="oc-component-group" data-index="<?php echo esc_attr( $i ); ?>">
			<input type="hidden" class="oc-component-key" name="oc_component_key[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $key ); ?>" />
			<div class="oc-card-head">
				<span class="oc-card-grip dashicons dashicons-menu" aria-hidden="true"></span>
				<span class="oc-card-title"><?php echo ( $search_id && '' !== $name ) ? esc_html( $name ) : esc_html__( 'Bundle item', 'oc-bundles' ); ?></span>
				<a href="#" class="oc-collapse-row" title="<?php esc_attr_e( 'Minimize', 'oc-bundles' ); ?>" aria-label="<?php esc_attr_e( 'Minimize', 'oc-bundles' ); ?>">&minus;</a>
				<a href="#" class="oc-remove-row" title="<?php esc_attr_e( 'Remove product', 'oc-bundles' ); ?>" aria-label="<?php esc_attr_e( 'Remove product', 'oc-bundles' ); ?>">&times;</a>
			</div>

			<div class="oc-card-body">
				<div class="oc-card-fields">
					<div class="oc-field oc-field-product">
						<select class="wc-product-search oc-product-select" name="oc_component_product[<?php echo esc_attr( $i ); ?>]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'oc-bundles' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%">
							<?php if ( $search_id ) : ?><option value="<?php echo esc_attr( $search_id ); ?>" selected><?php echo esc_html( $name ); ?></option><?php endif; ?>
						</select>
					</div>
					<?php
					if ( $search_id ) {
						$spec = OC_Bundles_Helpers::product_spec( $component['product_id'] ? $component['product_id'] : $id );
						self::render_qty_cell( $i, $component, $spec );
					} else {
						echo '<div class="oc-qty-cell" data-mode=""><span class="oc-qty-empty">' . esc_html__( 'Choose a product first', 'oc-bundles' ) . '</span></div>';
					}
					?>
				</div>

				<div class="oc-card-options">
					<label class="oc-toggle">
						<input type="checkbox" class="oc-switch oc-desc-toggle" name="oc_component_has_desc[<?php echo esc_attr( $i ); ?>]" value="yes" <?php checked( '' !== $component['description'] ); ?> />
						<span class="oc-toggle-ui"></span>
						<span class="oc-toggle-text"><?php esc_html_e( 'Add short description', 'oc-bundles' ); ?></span>
					</label>

					<div class="oc-card-desc" <?php echo '' !== $component['description'] ? '' : 'style="display:none"'; ?>>
						<input type="text" class="oc-desc-input" name="oc_component_desc[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $component['description'] ); ?>" placeholder="<?php esc_attr_e( 'Short description shown under the product name…', 'oc-bundles' ); ?>" />
					</div>

					<label class="oc-toggle">
						<input type="checkbox" class="oc-switch oc-swappable-toggle" name="oc_component_swappable[<?php echo esc_attr( $i ); ?>]" value="yes" <?php checked( $swappable ); ?> />
						<span class="oc-toggle-ui"></span>
						<span class="oc-toggle-text"><?php esc_html_e( 'Swappable', 'oc-bundles' ); ?></span>
					</label>
				</div>
			</div>

			<div class="oc-swaps-block" <?php echo $swappable ? '' : 'style="display:none"'; ?>>
				<span class="oc-swaps-title"><?php esc_html_e( 'Alternative products', 'oc-bundles' ); ?></span>
				<table class="oc-swaps-table">
					<tbody>
						<?php foreach ( $component['swaps'] as $oi => $swap ) : ?>
							<?php self::render_swap_row( $i, $oi, $swap ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="button oc-add-swap"><?php esc_html_e( 'Add alternative', 'oc-bundles' ); ?></button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the dynamic quantity cell for a component, based on its product spec.
	 *
	 * @param int|string $i         Index.
	 * @param array      $component Normalized component.
	 * @param array      $spec      Product spec.
	 */
	public static function render_qty_cell( $i, $component, $spec ) {
		$mode = $spec['mode'];
		$qty  = $component['qty'] > 0 ? $component['qty'] : ( 'weight' === $mode ? $spec['min'] : 1 );
		?>
		<div class="oc-qty-cell" data-mode="<?php echo esc_attr( $mode ); ?>">
			<input type="hidden" name="oc_component_mode[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $mode ); ?>" />
			<input type="hidden" name="oc_component_unit[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $spec['unit'] ); ?>" />
			<input type="hidden" name="oc_component_unit_label[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $spec['unit_label'] ); ?>" />
			<input type="hidden" class="oc-variation-field" name="oc_component_variation[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $component['variation_id'] ); ?>" />
			<input type="hidden" class="oc-unit-weight-field" name="oc_component_unit_weight[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $component['unit_weight'] ? $component['unit_weight'] : $spec['unit_weight'] ); ?>" />

			<?php if ( 'units_weight' === $mode ) : ?>
				<div class="oc-field oc-field-variation">
					<select class="oc-weight-select">
						<?php
						foreach ( $spec['weight_options'] as $opt ) {
							$val   = $opt['variation_id'] ? $opt['variation_id'] : $opt['weight'];
							$is_sel = ( $opt['variation_id'] && (int) $opt['variation_id'] === (int) $component['variation_id'] )
								|| ( ! $opt['variation_id'] && abs( (float) $opt['weight'] - (float) $component['unit_weight'] ) < 0.0001 );
							printf(
								'<option value="%s" data-variation="%s" data-weight="%s"%s>%s</option>',
								esc_attr( $val ),
								esc_attr( $opt['variation_id'] ),
								esc_attr( $opt['weight'] ),
								$is_sel ? ' selected' : '',
								esc_html( $opt['label'] )
							);
						}
						?>
					</select>
				</div>
			<?php endif; ?>

			<div class="oc-field oc-field-qty">
				<span class="oc-qty-input-wrap">
					<input type="number" class="oc-qty-input" name="oc_component_qty[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $qty ); ?>"
						step="<?php echo esc_attr( 'weight' === $mode ? $spec['step'] : 1 ); ?>"
						min="<?php echo esc_attr( 'weight' === $mode ? $spec['min'] : 1 ); ?>" />
					<span class="oc-qty-suffix"><?php echo esc_html( 'weight' === $mode ? $spec['weight_unit_label'] : OC_Bundles_Helpers::display_suffix( $spec['unit'], $spec['unit_label'] ) ); ?></span>
				</span>
				<?php
				if ( 'units_weight' === $mode ) :
					$hint_weight = (float) $component['unit_weight'];
					if ( $hint_weight <= 0 && ! empty( $spec['weight_options'] ) ) {
						$hint_weight = (float) $spec['weight_options'][0]['weight'];
						foreach ( $spec['weight_options'] as $o ) {
							if ( $o['variation_id'] && (int) $o['variation_id'] === (int) $component['variation_id'] ) {
								$hint_weight = (float) $o['weight'];
								break;
							}
						}
					}
					$hint_text = $hint_weight > 0 ? sprintf( __( 'Each unit ≈ %s kg', 'oc-bundles' ), OC_Bundles_Source_Native::fmt( $hint_weight ) ) : '';
					?>
					<span class="oc-qty-hint"><?php echo esc_html( $hint_text ); ?></span>
				<?php elseif ( 'units' === $mode && $spec['unit_weight'] > 0 ) : ?>
					<span class="oc-qty-hint"><?php echo esc_html( sprintf( __( 'Each unit ≈ %s kg', 'oc-bundles' ), OC_Bundles_Source_Native::fmt( $spec['unit_weight'] ) ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one swap (alternative product) row.
	 *
	 * @param int|string $i    Component index.
	 * @param int|string $oi   Swap index.
	 * @param array      $swap Swap data.
	 */
	public static function render_swap_row( $i, $oi, $swap ) {
		$pid    = ! empty( $swap['variation_id'] ) ? $swap['variation_id'] : ( isset( $swap['product_id'] ) ? $swap['product_id'] : 0 );
		$name   = $pid && wc_get_product( $pid ) ? wp_strip_all_tags( wc_get_product( $pid )->get_formatted_name() ) : '';
		$surch  = isset( $swap['surcharge'] ) ? $swap['surcharge'] : 0;
		$sqty   = ( isset( $swap['qty'] ) && (float) $swap['qty'] > 0 ) ? $swap['qty'] : '';
		?>
		<tr class="oc-swap-row">
			<td class="oc-swap-product">
				<select class="wc-product-search" name="oc_component_swap_product[<?php echo esc_attr( $i ); ?>][<?php echo esc_attr( $oi ); ?>]" data-placeholder="<?php esc_attr_e( 'Alternative product…', 'oc-bundles' ); ?>" data-action="woocommerce_json_search_products_and_variations" style="width:100%">
					<?php if ( $pid ) : ?><option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $name ); ?></option><?php endif; ?>
				</select>
			</td>
			<td class="oc-swap-surcharge-cell">
				<span class="oc-surcharge-label"><?php esc_html_e( 'Surcharge ₪', 'oc-bundles' ); ?></span>
				<input type="number" step="0.01" name="oc_component_swap_surcharge[<?php echo esc_attr( $i ); ?>][<?php echo esc_attr( $oi ); ?>]" value="<?php echo esc_attr( $surch ); ?>" />
			</td>
			<td class="oc-swap-qty-cell">
				<span class="oc-surcharge-label"><?php esc_html_e( 'Quantity', 'oc-bundles' ); ?></span>
				<input type="number" step="any" min="0" name="oc_component_swap_qty[<?php echo esc_attr( $i ); ?>][<?php echo esc_attr( $oi ); ?>]" value="<?php echo esc_attr( $sqty ); ?>" placeholder="<?php esc_attr_e( 'Same as the component', 'oc-bundles' ); ?>" />
			</td>
			<td class="oc-swap-remove"><a href="#" class="oc-remove-row">&times;</a></td>
		</tr>
		<?php
	}

	/**
	 * JS templates for new rows.
	 */
	public static function render_templates() {
		?>
		<script type="text/html" id="tmpl-oc-component-row">
			<?php self::render_component_row( '{{INDEX}}', array() ); ?>
		</script>
		<script type="text/html" id="tmpl-oc-swap-row">
			<?php self::render_swap_row( '{{INDEX}}', '{{OINDEX}}', array() ); ?>
		</script>
		<?php
	}

	/**
	 * AJAX: return the quantity spec for a product (drives the quantity control).
	 */
	public static function ajax_product_config() {
		check_ajax_referer( 'oc_bundles_admin', 'nonce' );
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error();
		}
		$product_id          = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$spec                = OC_Bundles_Helpers::product_spec( $product_id );
		$spec['unit_suffix'] = OC_Bundles_Helpers::display_suffix( $spec['unit'], $spec['unit_label'] );
		wp_send_json_success( $spec );
	}

	/**
	 * Persist all bundle meta.
	 *
	 * @param int $post_id Product ID.
	 */
	public static function save( $post_id ) {
		// An externally managed bundle keeps its configuration; WooCommerce's own
		// product fields are still saved by WooCommerce as usual. Its price meta is
		// still refreshed here: a `sum` bundle's `_price` follows its components, and an
		// admin save is the moment WooCommerce would otherwise overwrite it with the
		// (empty) regular price field.
		if ( OC_Bundles_Helpers::is_locked( $post_id ) ) {
			OC_Bundles_Pricing::sync_price_meta( $post_id );
			return;
		}

		if ( ! isset( $_POST['_oc_bundle_pricing_mode'] ) && empty( $_POST['oc_component_product'] ) ) {
			return;
		}

		$pricing_mode = isset( $_POST['_oc_bundle_pricing_mode'] ) && 'sum' === $_POST['_oc_bundle_pricing_mode'] ? 'sum' : 'fixed';
		update_post_meta( $post_id, '_oc_bundle_pricing_mode', $pricing_mode );
		update_post_meta( $post_id, '_oc_bundle_fixed_price', isset( $_POST['_oc_bundle_fixed_price'] ) ? (float) wc_clean( wp_unslash( $_POST['_oc_bundle_fixed_price'] ) ) : 0 );

		$discount_type = isset( $_POST['_oc_bundle_discount_type'] ) ? wc_clean( wp_unslash( $_POST['_oc_bundle_discount_type'] ) ) : 'none';
		if ( ! in_array( $discount_type, array( 'none', 'percent', 'fixed' ), true ) ) {
			$discount_type = 'none';
		}
		update_post_meta( $post_id, '_oc_bundle_discount_type', $discount_type );
		update_post_meta( $post_id, '_oc_bundle_discount_value', isset( $_POST['_oc_bundle_discount_value'] ) ? (float) wc_clean( wp_unslash( $_POST['_oc_bundle_discount_value'] ) ) : 0 );
		update_post_meta( $post_id, '_oc_bundle_hide_price_labels', isset( $_POST['_oc_bundle_hide_price_labels'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_oc_bundle_reweigh_price', isset( $_POST['_oc_bundle_reweigh_price'] ) ? 'yes' : 'no' );

		update_post_meta( $post_id, '_oc_bundle_layout', isset( $_POST['_oc_bundle_layout'] ) && 'list' === $_POST['_oc_bundle_layout'] ? 'list' : 'grid' );
		$cart_display = isset( $_POST['_oc_bundle_cart_display'] ) ? wc_clean( wp_unslash( $_POST['_oc_bundle_cart_display'] ) ) : 'name_with_components';
		if ( ! in_array( $cart_display, array( 'name_only', 'name_with_components', 'line' ), true ) ) {
			$cart_display = 'name_with_components';
		}
		update_post_meta( $post_id, '_oc_bundle_cart_display', $cart_display );
		update_post_meta( $post_id, '_oc_bundle_invoice_display', isset( $_POST['_oc_bundle_invoice_display'] ) && 'components' === $_POST['_oc_bundle_invoice_display'] ? 'components' : 'bundle' );
		update_post_meta( $post_id, '_oc_bundle_oos_behavior', isset( $_POST['_oc_bundle_oos_behavior'] ) && 'swap' === $_POST['_oc_bundle_oos_behavior'] ? 'swap' : 'unavailable' );
		update_post_meta( $post_id, '_oc_bundle_show_components_in_desc', isset( $_POST['_oc_bundle_show_components_in_desc'] ) ? 'yes' : 'no' );

		$components = array();
		if ( isset( $_POST['oc_component_product'] ) && is_array( $_POST['oc_component_product'] ) ) {
			foreach ( $_POST['oc_component_product'] as $i => $pid ) {
				$pid = absint( $pid );
				if ( ! $pid ) {
					continue;
				}
				$resolved = self::resolve_id( $pid );
				$spec     = OC_Bundles_Helpers::product_spec( $pid );

				$qty          = isset( $_POST['oc_component_qty'][ $i ] ) ? (float) wc_clean( wp_unslash( $_POST['oc_component_qty'][ $i ] ) ) : 0;
				$variation_id = $resolved['variation_id'];
				$unit_weight  = $spec['unit_weight'];

				if ( 'units_weight' === $spec['mode'] ) {
					$chosen_var = isset( $_POST['oc_component_variation'][ $i ] ) ? absint( $_POST['oc_component_variation'][ $i ] ) : 0;
					if ( $chosen_var ) {
						$variation_id = $chosen_var;
					}
					$unit_weight = isset( $_POST['oc_component_unit_weight'][ $i ] ) ? (float) wc_clean( wp_unslash( $_POST['oc_component_unit_weight'][ $i ] ) ) : 0;
				}

				// Swaps for this component.
				$swaps = array();
				if ( isset( $_POST['oc_component_swap_product'][ $i ] ) && is_array( $_POST['oc_component_swap_product'][ $i ] ) ) {
					foreach ( $_POST['oc_component_swap_product'][ $i ] as $oi => $sp ) {
						$sp = absint( $sp );
						if ( ! $sp ) {
							continue;
						}
						$sr      = self::resolve_id( $sp );
						$surch   = isset( $_POST['oc_component_swap_surcharge'][ $i ][ $oi ] ) ? (float) wc_clean( wp_unslash( $_POST['oc_component_swap_surcharge'][ $i ][ $oi ] ) ) : 0;
						$swaps[] = array(
							'product_id'   => $sr['product_id'],
							'variation_id' => $sr['variation_id'],
							'surcharge'    => $surch,
							'qty'          => isset( $_POST['oc_component_swap_qty'][ $i ][ $oi ] ) ? max( 0, (float) wc_clean( wp_unslash( $_POST['oc_component_swap_qty'][ $i ][ $oi ] ) ) ) : 0,
						);
					}
				}

				$swappable = isset( $_POST['oc_component_swappable'][ $i ] ) ? 'yes' : 'no';

				$has_desc    = isset( $_POST['oc_component_has_desc'][ $i ] );
				$description = ( $has_desc && isset( $_POST['oc_component_desc'][ $i ] ) ) ? sanitize_text_field( wp_unslash( $_POST['oc_component_desc'][ $i ] ) ) : '';

				// Stable slot key: generated per card by the admin script; derived for
				// rows saved before keys existed.
				$key = isset( $_POST['oc_component_key'][ $i ] ) ? OC_Bundles_Helpers::sanitize_key_string( wp_unslash( $_POST['oc_component_key'][ $i ] ) ) : '';
				if ( '' === $key ) {
					$key = 'p' . $resolved['product_id'] . '-v' . $variation_id . '-' . count( $components );
				}

				$components[] = array(
					'key'          => $key,
					'product_id'   => $resolved['product_id'],
					'variation_id' => $variation_id,
					'qty'          => $qty,
					'unit'         => $spec['unit'],
					'unit_label'   => $spec['unit_label'],
					'unit_weight'  => $unit_weight,
					'mode'         => $spec['mode'],
					'description'  => $description,
					'swappable'    => $swappable,
					'swaps'        => ( 'yes' === $swappable ) ? $swaps : array(),
				);
			}
		}
		update_post_meta( $post_id, '_oc_bundle_components', $components );

		// Sync WooCommerce price meta (regular + sale when discounted). Every setting above is in
		// post meta by now, so let it read the WHOLE config: a partial one defaulted `oos_behavior`
		// to "unavailable", and the stored price then lost the automatic-swap surcharge that the
		// product page and the cart do show.
		OC_Bundles_Pricing::sync_price_meta( $post_id );

		self::maybe_update_short_description( $post_id, $components );
	}

	/**
	 * Resolve a chosen ID into product_id + variation_id.
	 *
	 * @param int $id Chosen product/variation ID.
	 * @return array
	 */
	public static function resolve_id( $id ) {
		$product = wc_get_product( $id );
		if ( $product && $product->is_type( 'variation' ) ) {
			return array(
				'product_id'   => $product->get_parent_id(),
				'variation_id' => $product->get_id(),
			);
		}
		return array(
			'product_id'   => $id,
			'variation_id' => 0,
		);
	}

	/**
	 * Optionally write the components list into the short description.
	 *
	 * @param int   $post_id    Product ID.
	 * @param array $components Components.
	 */
	public static function maybe_update_short_description( $post_id, $components ) {
		if ( 'yes' !== get_post_meta( $post_id, '_oc_bundle_show_components_in_desc', true ) ) {
			return;
		}
		$lines = OC_Bundles_Helpers::components_list_lines( $components );
		if ( empty( $lines ) ) {
			return;
		}
		$html = '<ul class="oc-bundle-desc-list"><li>' . implode( '</li><li>', array_map( 'esc_html', $lines ) ) . '</li></ul>';

		remove_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
		wp_update_post( array( 'ID' => $post_id, 'post_excerpt' => $html ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save' ) );
	}
}
