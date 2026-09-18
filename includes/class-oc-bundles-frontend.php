<?php
/**
 * Frontend — render the bundle product page (theme-agnostic) and enqueue assets.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Frontend {

	public static function init() {
		add_filter( 'wc_get_template_part', array( __CLASS__, 'single_template' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Swap in our self-contained content template for single bundle pages.
	 *
	 * @param string $template Located template.
	 * @param string $slug     Slug.
	 * @param string $name     Name.
	 * @return string
	 */
	public static function single_template( $template, $slug, $name ) {
		if ( 'content' === $slug && 'single-product' === $name && is_singular( 'product' ) ) {
			global $product;
			$maybe = $product;
			if ( ! $maybe || ! is_a( $maybe, 'WC_Product' ) ) {
				$maybe = wc_get_product( get_the_ID() );
			}
			if ( $maybe && $maybe->is_type( OC_BUNDLES_PRODUCT_TYPE ) ) {
				return OC_BUNDLES_PATH . 'templates/content-single-bundle.php';
			}
		}
		return $template;
	}

	/**
	 * Enqueue CSS/JS where needed.
	 */
	public static function enqueue() {
		$need = is_product() || is_cart() || is_checkout() || ( function_exists( 'is_woocommerce' ) && is_woocommerce() );
		if ( ! $need ) {
			return;
		}
		wp_enqueue_style( 'oc-bundles', OC_BUNDLES_URL . 'assets/css/oc-bundles.css', array(), OC_BUNDLES_VERSION );
		wp_enqueue_script( 'oc-bundles', OC_BUNDLES_URL . 'assets/js/oc-bundles.js', array( 'jquery' ), OC_BUNDLES_VERSION, true );
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
	}

	/**
	 * Render the full bundle block (image + content). Used by the single template
	 * and reusable for the deliz modal.
	 *
	 * @param WC_Product $product Bundle product.
	 */
	public static function render( $product ) {
		$bundle_id = $product->get_id();
		$config    = OC_Bundles_Helpers::get_config( $bundle_id );

		// Auto-swap any out-of-stock component (when configured) for display + pricing.
		$selection = OC_Bundles_Helpers::apply_auto_swaps( $config, array() );
		$resolved  = OC_Bundles_Cart::resolve_components( $config, $selection );
		$applied   = $resolved['selection'];

		$base        = OC_Bundles_Pricing::base_price( $bundle_id, $config['components'], $config );
		$discount    = OC_Bundles_Pricing::discount_amount( $bundle_id, $config['components'], $config );
		$price       = OC_Bundles_Pricing::line_price( $bundle_id, $resolved['components'], $applied, $config );
		$regular     = $price + $discount;
		$available   = OC_Bundles_Stock::available_quantity( $bundle_id, $resolved['components'] );
		$image       = $product->get_image( 'large' );
		$count       = count( $config['components'] );
		?>
		<div class="oc-bundle oc-layout-<?php echo esc_attr( $config['layout'] ); ?>" data-bundle-id="<?php echo esc_attr( $bundle_id ); ?>" data-base-price="<?php echo esc_attr( $base ); ?>" data-discount-amount="<?php echo esc_attr( $discount ); ?>">

			<div class="oc-bundle-media">
				<?php echo wp_kses_post( $image ); ?>
			</div>

			<div class="oc-bundle-body">
				<h1 class="oc-bundle-title"><?php echo esc_html( $product->get_name() ); ?></h1>
				<?php $hide_labels = ( 'yes' === $config['hide_price_labels'] ); ?>
				<div class="oc-bundle-price<?php echo $discount > 0 ? ' has-discount' : ''; ?><?php echo $hide_labels ? ' no-labels' : ''; ?>">
					<?php if ( $discount > 0 ) : ?>
						<span class="oc-price-current">
							<?php if ( ! $hide_labels ) : ?>
								<span class="oc-price-label"><?php esc_html_e( 'Bundle price', 'oc-bundles' ); ?></span>
							<?php endif; ?>
							<span class="oc-price-amount"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
						</span>
						<del class="oc-price-was">
							<?php if ( ! $hide_labels ) : ?>
								<span class="oc-price-label"><?php esc_html_e( 'Regular price', 'oc-bundles' ); ?></span>
							<?php endif; ?>
							<span class="oc-price-amount"><?php echo wp_kses_post( wc_price( $regular ) ); ?></span>
						</del>
					<?php else : ?>
						<span class="oc-price-amount"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
					<?php endif; ?>
				</div>

				<?php if ( $product->get_short_description() ) : ?>
					<div class="oc-bundle-desc"><?php echo wp_kses_post( wc_format_content( $product->get_short_description() ) ); ?></div>
				<?php endif; ?>

				<div class="oc-bundle-components-head">
					<span class="oc-bundle-components-title"><?php esc_html_e( "What's in the bundle", 'oc-bundles' ); ?></span>
					<span class="oc-bundle-count"><?php echo esc_html( sprintf( _n( '%d item', '%d items', $count, 'oc-bundles' ), $count ) ); ?></span>
				</div>

				<?php self::render_components( $config, $bundle_id, $selection, $applied ); ?>

				<form class="oc-bundle-form cart" action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', $product->get_permalink() ) ); ?>" method="post" enctype="multipart/form-data">
					<input type="hidden" name="oc_bundle_selection" class="oc-bundle-selection" value="<?php echo esc_attr( wp_json_encode( (object) $selection ) ); ?>" />

					<?php if ( $available > 0 ) : ?>
						<div class="oc-bundle-footer">
							<div class="oc-qty">
								<button type="button" class="oc-qty-minus" aria-label="<?php esc_attr_e( 'Decrease quantity', 'oc-bundles' ); ?>">&minus;</button>
								<input type="number" name="quantity" class="oc-qty-input" value="1" min="1" max="<?php echo esc_attr( $available ); ?>" />
								<span class="oc-qty-label"><?php esc_html_e( "pcs", 'oc-bundles' ); ?></span>
								<button type="button" class="oc-qty-plus" aria-label="<?php esc_attr_e( 'Increase quantity', 'oc-bundles' ); ?>">&plus;</button>
							</div>
							<button type="submit" name="add-to-cart" value="<?php echo esc_attr( $bundle_id ); ?>" class="single_add_to_cart_button button alt oc-add-to-cart">
								<span class="oc-add-label"><?php echo esc_html( $product->single_add_to_cart_text() ); ?></span>
								<span class="oc-add-price"><?php echo wp_kses_post( wc_price( $price ) ); ?></span>
							</button>
						</div>
					<?php else : ?>
						<p class="stock out-of-stock oc-bundle-oos"><?php esc_html_e( 'Out of stock', 'oc-bundles' ); ?></p>
					<?php endif; ?>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the components grid/list, reflecting the active selection.
	 *
	 * @param array $config    Bundle config.
	 * @param int   $bundle_id Bundle ID.
	 * @param array $selection Active selection (index => key).
	 * @param array $applied   Applied surcharges (index => { surcharge }).
	 */
	public static function render_components( $config, $bundle_id, $selection = array(), $applied = array() ) {
		echo '<div class="oc-components">';
		foreach ( $config['components'] as $index => $component ) {
			$component = OC_Bundles_Helpers::normalize_component( $component, $index );
			$key       = isset( $selection[ $index ] ) ? intval( $selection[ $index ] ) : -1;
			$active_id = OC_Bundles_Helpers::effective_active_id( $component, $key );
			$product   = $active_id ? wc_get_product( $active_id ) : false;
			if ( ! $product ) {
				continue;
			}
			$qty_label = OC_Bundles_Helpers::quantity_label( $component );
			$swappable = ( 'yes' === $component['swappable'] && ! empty( $component['swaps'] ) );
			$surcharge = isset( $applied[ $index ]['surcharge'] ) ? (float) $applied[ $index ]['surcharge'] : 0;
			?>
			<div class="oc-component<?php echo $swappable ? ' is-swappable' : ''; ?>"
				data-index="<?php echo esc_attr( $index ); ?>"
				data-active-key="<?php echo esc_attr( $key ); ?>"
				data-surcharge="<?php echo esc_attr( $surcharge ); ?>"
				data-component-id="<?php echo esc_attr( $active_id ); ?>">
				<div class="oc-component-media">
					<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
				</div>
				<div class="oc-component-info">
					<span class="oc-qty-pill"><?php echo esc_html( $qty_label ); ?></span>
					<span class="oc-component-name"><?php echo esc_html( $product->get_name() ); ?></span>
					<?php if ( '' !== $component['description'] ) : ?>
						<span class="oc-component-desc"><?php echo esc_html( $component['description'] ); ?></span>
					<?php endif; ?>
					<?php if ( $swappable ) : ?>
						<a href="#" class="oc-swap-link" data-bundle-id="<?php echo esc_attr( $bundle_id ); ?>" data-index="<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Swap', 'oc-bundles' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}
		echo '</div>';
	}
}
