=== Original Concepts Bundles ===
Contributors: originalconcepts
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.5.2
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

= 1.5.2 =
* Catalog / archive price of a bundle now includes the surcharge of AUTOMATIC swaps: with
  "Swap automatically", an out-of-stock component is replaced by its first in-stock
  alternative, and the listed price (regular and sale) is the price the customer gets in
  the cart - it used to show the price with the component that cannot be bought.
* Alternatives can carry their OWN quantity: `qty` on a swap entry (REST API, and a
  "Quantity" field next to the surcharge in the admin). Empty / 0 keeps inheriting the
  component's quantity. An alternative with its own quantity is measured the way its
  product is sold (kg for a product sold by weight, units otherwise), in the cart, on
  the order line and for stock.
* oc_bundles_add_order_line() / oc_bundles_update_order_line(): a swapped component may
  state `qty` (per bundle) and `unit` explicitly - an integration that re-expresses a
  swap to a differently measured product by weight (2 portions of 0.2 kg -> 0.4 kg)
  keeps the order line, its label and its stock in that unit.

= 1.5.1 =
* Merged with 1.4.7: a bundle split for the invoice keeps the ordinary component lines
  (product name, shipped quantity in the quantity column, unit as meta). Weighed
  quantities pushed by an integration (oc_bundles_update_order_line `actual_qty`) are
  written to those line quantities, so the order screen and the integration see the
  same numbers; a line whose total the integration owns is never re-priced or "put
  back" by an admin re-weigh - it keeps the pushed amount. Component lines are linked
  to their bundle line by its uid (1.4.7) and, once saved, by its item id
  (`_oc_bundle_parent_item`, what Giorgio reads).
* Pricing: a component ordered by units but priced per kg (OC Sale Units "sold by
  units" with a unit weight - e.g. a ~200 g portion of a 145/kg product) now costs
  price x unit weight per unit in a "sum" bundle, exactly like the same product on a
  regular cart line (was: price x units, i.e. 145 per portion). Applies to the bundle
  price, to re-weighing and to the invoice split. For a "choose a weight" product the
  REST API accepts `unit_weight` (kg) per component; without it the first weight
  option is used.
* Re-pricing of a "sum + re-weigh" bundle line no longer runs before anything was
  weighed (checkout / status changes): the cart price stands until picking. It used
  to replace "original price + swap surcharge" with the swapped product's own price
  x the slot quantity, so an order could total more than the cart it came from.

= 1.5.0 =
* Giorgio sync: bundles can carry an external id (`external_id` in the REST API,
  `?external_id=` filter on the list). A bundle that has one is managed from Giorgio:
  the "Bundle contents" tab turns read-only with a notice, and saving the product no
  longer overwrites its configuration (filter `oc_bundles_lock_managed`).
* Every component now has a stable `key` (accepted and returned by the REST API,
  generated per card in the admin, derived for older data). It follows the slot
  through swaps and onto the order line.
* REST API hardening: `invoice_display` is validated, and a component whose product
  does not exist is rejected with a 400 (`oc_bundles_invalid_component`) before
  anything is written.
* Order lines can now carry a swap to a product that is not one of the configured
  alternatives (`swap_index = -2`, product and surcharge on the selection entry).
  Only external integrations produce it; the shop front end is unchanged.
* Component lines created by the invoice split are linked to their bundle line by
  item id, so two lines of the same bundle in one order no longer share their
  component lines.
* New public functions for integrations: oc_bundles_is_bundle_product(),
  oc_bundles_is_bundle_order_item(), oc_bundles_is_component_line(),
  oc_bundles_get_order_item_bundle_data(), oc_bundles_add_order_line(),
  oc_bundles_update_order_line(), oc_bundles_release_order_line() (returns a
  bundle line's component stock before the line is removed from an order).
* Updating a bundle line swaps stock correctly: a component replaced on the line
  gives its taken quantity back to the old product and the new product is reduced
  in full. A line whose total is owned by Giorgio splits its components (sum
  pricing) so they add up to exactly that total, and the component lines are named
  after the weighed quantities. Re-splitting updates existing component lines in
  place instead of removing and re-adding them. Saving a Giorgio-managed bundle in
  the admin refreshes its price meta.
* Store promotions can now reach bundles: the cart price is set early and captured
  last, so a promotion engine's discount is what gets charged; the regular price of a
  bundle is its pre-discount base, so it reads as "on sale" when discounted. New
  setting under Settings → Bundles API (on by default) restores the old ordering
  when turned off.

= 1.4.7 =
* Orders: a bundle split for the invoice now lists each component as an ordinary
  WooCommerce line — the product's name, the quantity that ships in the quantity column,
  and its unit — instead of spelling the quantity out in the name. The shop re-weighs a
  component by editing that line's quantity, like any weighable product; saving settles
  stock by the difference, and re-prices only when the bundle is set to. The separate
  "Weighed quantities" table under the bundle line no longer appears for these orders
  (it stays for orders without component lines).
* Component lines are tied to their own bundle line, so two lines of the same bundle in
  one order are never mixed up.
* Order line changes through the WooCommerce REST API settle component stock too.
* Removing a component line from the order (or saving it at quantity 0) returns that
  component's stock, the same as removing any WooCommerce line.
* Like any WooCommerce line, a component line is edited while the order is in an
  editable status (pending payment / on hold).

= 1.4.6 =
* Deliz float cart: a bundle line now has the theme's "Edit" button under its contents
  list, looking and behaving like every other row's. It opens the product popup with the
  bundle exactly as the customer put it together — quantity and chosen swaps, with the
  matching price — and saving replaces the cart line with the changes.
* The popup REST payload accepts oc_bundle_selection (index => swap index) to open with
  given swaps; invalid choices are ignored.

= 1.4.5 =
* A discounted bundle now reports its prices the way WooCommerce expects: regular =
  before the bundle discount, sale = after it. Everywhere a product on sale shows its
  regular price struck through and the sale price in the theme's sale colour — the
  product popup, shop cards, the block cart — a bundle now does too. Before, the
  regular price was reported already discounted, so the bundle never counted as on
  sale and its price showed in plain black. Cart lines add their swap surcharges to both.
* Note: a discounted bundle is now "on sale" for WooCommerce, so a coupon set to
  exclude sale items skips it, as it does any other product on sale.
* Deliz popup: bundles get the theme's opening scroll nudge (a short scroll down and
  back when the list continues below the fold). The theme skips bundles for its fade;
  the plugin adds only the nudge, with the theme's timing.

= 1.4.4 =
* Deliz float cart: a bundle shown with its contents (as rows, or as a one-line list)
  now keeps exactly the cart row every other product has — thumbnail, name, quantity
  and price where the theme puts them — and lists its contents on a full-width line
  under that row. The contents used to sit inside the theme's narrow name column,
  widening it and pushing the quantity and price out of the cart with a sideways scroll.
* Done in the plugin only: the cart item data carries a marker class and label, and a
  small script moves just that block. No theme styles are overridden.

= 1.4.3 =
* Reverted the mini-cart layout overrides added in 1.4.1/1.4.2. They reshaped the
  theme's own cart row (display:contents on its detail wrapper, flex-wrap and
  ordering on the row) to win width for the bundle contents. That is the theme's
  layout to own, not the plugin's, and it moved the item's variations/meta and the
  quantity and price out of their proper places. The theme now lays its cart row
  out exactly as it did before the plugin was involved.
* The plugin-side fixes are kept: contents no longer break mid-word, and they keep
  a small inset from the product image.

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
