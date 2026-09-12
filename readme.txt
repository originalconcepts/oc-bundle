=== Original Concepts Bundles ===
Contributors: originalconcepts
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.4.2
License: GPL-2.0+

WooCommerce product bundles: a "Bundle" product type made of products and
quantities, with flexible pricing, per-product swapping, and component-level
stock. Works with or without the OC Sale Units plugin, in any theme.

== Description ==

A bundle is a product made of other products and quantities. Features:

* Custom "Bundle" product type.
* Fixed price or sum-of-products pricing, with an optional bundle discount.
* Per-component swapping with optional surcharges (live price update).
* Component-level stock: availability is the limiting component.
* Out-of-stock behaviour: mark the bundle unavailable, or auto-swap to the
  first in-stock alternative.
* Optional short description per component.
* Weighable products via OC Sale Units: by weight, by units with a fixed unit
  weight, by units with a chosen weight from a list, or by units whose weight
  comes from each variation.
* Grid or list layout; configurable cart display.

== Internationalization ==

All interface strings use the `oc-bundles` text domain. Source strings are in
English; a Hebrew (he_IL) translation ships in `/languages`. The interface
follows the site/user language and direction (RTL or LTR) automatically.

To translate into another language, copy `languages/oc-bundles.pot` and create
`oc-bundles-{locale}.po` / `.mo` (e.g. with Loco Translate or Poedit).

== Automatic updates from GitHub ==

The plugin can update itself from a GitHub repository's Releases.

1. Push the plugin to a GitHub repo.
2. Point the updater at the repo, either by editing the header in
   oc-bundles.php:

       Update URI: https://github.com/YOUR-USER/YOUR-REPO

   or by defining a constant in wp-config.php (overrides the header):

       define( 'OC_BUNDLES_GITHUB_REPO', 'YOUR-USER/YOUR-REPO' );

3. Publish a Release whose tag is the version (e.g. v1.0.1 or 1.0.1) and bump
   the Version: header to match. WordPress then shows the update on the Plugins
   screen and installs it on demand.

Background auto-updates are enabled by default. To disable:

       define( 'OC_BUNDLES_AUTO_UPDATE', false );

For a private repo or to avoid GitHub rate limits, add a token:

       define( 'OC_BUNDLES_GITHUB_TOKEN', 'ghp_xxx' );

== Changelog ==

= 1.4.2 =
* Mini-cart: the bundle's quantity and price now stay on the first row beside the
  thumbnail and name, with the contents listed underneath, instead of being pushed
  below the list.
* Bundle contents no longer sit flush against the product image in the block-based
  cart, which had no padding of its own on that edge.

= 1.4.1 =
* Fixed bundle contents in the mini-cart. The theme pins its cart-row detail column
  to a fixed width, which left the product name about 32px -- narrower than a single
  Hebrew word -- so names broke one character per line. The contents block now takes
  a full-width line of its own inside the cart row, and every component fits on one
  line as "quantity + name". Measured on deliz.co.il: name column 32px -> 292px.
* Core cart styles no longer use `overflow-wrap: anywhere` with a zero min-width,
  which is what allowed the mid-word breaking in the first place.

= 1.4.0 =
* Merged the Deliz product-popup module into the plugin, and moved the cart-line
  price repair it carried into OC_Bundles_Pricing where it belongs: swap
  surcharges were being shown to the customer but not charged, on every theme.
* New per-bundle option: update the price when quantities are re-weighed on the
  order. Sum-of-products pricing only -- a fixed bundle price never moves.
* Bundle contents in the cart now lay out as flex rows, so the product name uses
  the width it has instead of collapsing to one word per line in the mini-cart.

= 1.3.0 =
* Component stock is now tracked per component with a ledger on the order line, the
  way WooCommerce tracks it per line item. Changing what an order takes re-applies
  only the difference, so a component ordered at 0.5 kg and weighed at 0.6 kg gives
  0.5 at checkout and a further 0.1 on save -- never 0.6 on top of 0.5.
* New "Weighed quantities" fields under a bundle line on the order screen, for
  entering what each component actually weighed. Empty means "as ordered".
* Orders placed before this version are migrated on first save: what stock already
  gave them is seeded from the ordered quantities, so nothing is reduced twice.

= 1.2.1 =
* Component breakdown now follows the pricing method. With "Sum of the products"
  the price belongs to the components, so each invoice line carries its own share
  (discount applied proportionally) and the bundle line drops to zero. With a
  fixed bundle price the amount stays on the bundle line as before.

= 1.2.0 =
* Invoice display "Component breakdown" now works: the order line is split into
  the bundle at its base price plus one line per component. A component's swap
  surcharge is shown on that component's own line; components included in the
  bundle price show no amount (rather than 0.00, which reads as "free"). The
  order total is unchanged.
* New cart display option: bundle name + product list on a single line.

= 1.0.0 =
* Full internationalization (English source + Hebrew translation, RTL/LTR aware).
* GitHub Releases self-update with optional automatic background updates.
* Support for unit weight taken from product variations.
* Optional per-component short description.
* Cart "name + list" display as styled rows.
* Working auto-swap on out-of-stock components.
