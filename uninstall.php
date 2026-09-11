<?php
/**
 * Uninstall — left intentionally conservative (keeps product data).
 *
 * @package OC_Bundles
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Intentionally does not delete bundle product meta on uninstall,
// to avoid destroying catalog data. Remove products manually if desired.
