<?php
/**
 * Availability — how many bundles can be assembled from current component stock.
 *
 * @package OC_Bundles
 */

defined( 'ABSPATH' ) || exit;

class OC_Bundles_Stock {

	/**
	 * How many whole bundles can be assembled right now.
	 * Returns a large number when nothing limits availability.
	 *
	 * @param int   $bundle_id  Bundle product ID.
	 * @param array $components  Optional resolved components (defaults to stored).
	 * @return int
	 */
	public static function available_quantity( $bundle_id, $components = null ) {
		if ( null === $components ) {
			$config    = OC_Bundles_Helpers::get_config( $bundle_id );
			$selection = OC_Bundles_Helpers::apply_auto_swaps( $config, array() );
			$resolved  = OC_Bundles_Cart::resolve_components( $config, $selection );
			$components = $resolved['components'];
		}

		if ( empty( $components ) ) {
			return 0;
		}

		$min = PHP_INT_MAX;

		foreach ( $components as $component ) {
			$component = OC_Bundles_Helpers::normalize_component( $component );
			$pid       = OC_Bundles_Helpers::effective_id( $component );
			if ( ! $pid || $component['qty'] <= 0 ) {
				continue;
			}

			$source = OC_Bundles_Source_Factory::get( $pid );

			if ( ! $source->is_in_stock() ) {
				return 0;
			}

			$stock = $source->stock();
			if ( null === $stock ) {
				// Unlimited / not managed — does not constrain availability.
				continue;
			}

			$possible = (int) floor( $stock / $component['qty'] );
			if ( $possible < $min ) {
				$min = $possible;
			}
		}

		if ( PHP_INT_MAX === $min ) {
			// All components unlimited.
			return 999;
		}

		return max( 0, $min );
	}
}
