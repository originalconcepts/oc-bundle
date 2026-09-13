# CLAUDE.md — OC Bundles (מארז) plugin

This file is auto-loaded by Claude Code at the start of every session in this
folder. It is the working context for this plugin. Read it fully before editing.

Communicate with the user (George) in **Hebrew**. He prefers concise, direct
answers, ships incrementally with version bumps, and tests on the
**deliz-short.deliz.co.il** staging site. Implement changes directly rather than
only describing them.

---

## What this is

**OC Bundles** — a self-contained WooCommerce plugin that adds a "bundle"
(מארז) product type for food retailers (butchers, fish shops, delis). A bundle
is a set of component products with per-component quantities, flexible pricing,
per-component swapping, and **stock tracked at the component level**.

- Custom product type: `oc_bundle`.
- Works on any theme, **with or without** the OC Sale Units (OCWSU) weighable-
  products plugin, and **with or without** Giorgio. Giorgio is an optional sync
  layer over the REST API — there is no separate "Giorgio mode" in the code.
- Current version: see `OC_BUNDLES_VERSION` in `oc-bundles.php`.

---

## Architecture / file map

```
oc-bundles.php                 Bootstrap: constants, HPOS decl, activation
                               (creates oc_bundle term + API key), requires +
                               inits all classes on plugins_loaded (prio 20).
uninstall.php                  Cleanup.
includes/
  class-oc-bundles-helpers.php     Config defaults + get_config(); component
                                   normalize; product_spec() derives unit/mode/
                                   weight; quantity_label / display_suffix;
                                   components list/rows HTML.
  class-oc-bundles-product-type.php WC_Product_OC_Bundle (get_type=oc_bundle).
  class-oc-bundles-pricing.php     raw_base_price (pre-discount), base_price
                                   (post-discount), discount_amount,
                                   sync_price_meta (sets _regular/_sale so
                                   archives show struck+sale), line_price
                                   (base + swap surcharges), apply_discount.
  class-oc-bundles-stock.php       available_quantity = min floor(stock/qty)
                                   across components; applies auto-swaps.
  class-oc-bundles-admin.php       Product-data "Bundle contents" tab: pricing
                                   radios, discount select+value, sortable
                                   component cards, save().
  class-oc-bundles-frontend.php    Single-bundle template swap + render():
                                   media, dual price block (Bundle price /
                                   Regular price, honors hide_price_labels),
                                   components with swap links; enqueues assets.
  class-oc-bundles-cart.php        add_cart_item_data, resolve_components,
                                   item_data, order line items, validation.
  class-oc-bundles-order.php       Per-component stock ledger on the order line
                                   (mirrors WC's _reduced_stock): reconcile()
                                   applies only the DIFFERENCE, so re-weighing
                                   works. Renders the "Weighed quantities"
                                   fields on the order screen.
  class-oc-bundles-invoice.php     invoice_display=components: splits the order
                                   line into bundle (base price) + one line per
                                   component (surcharge, or blank when included).
  class-oc-bundles-swap.php        AJAX swap options (true swap).
  class-oc-bundles-updater.php     GitHub Releases self-update (see Releases).
  class-oc-bundles-api.php         REST API oc-bundles/v1 (see API section).
  adapters/
    interface-oc-bundles-source.php
    class-oc-bundles-source-native.php   Native WC pricing/stock + fmt().
    class-oc-bundles-source-ocwsu.php     Extends Native; only when OCWSU active.
    class-oc-bundles-source-factory.php   ocwsu_active? OCWSU : Native.
templates/content-single-bundle.php   Single bundle markup.
assets/css/oc-bundles.css              Front styles (deliz green #13433A, RTL-safe).
assets/css/oc-bundles-admin.css        Admin card + toggle styles.
assets/js/oc-bundles.js                Front: qty stepper, swap popup, live price.
assets/js/oc-bundles-admin.js          Admin: component cards, sortable, qty cell.
assets/css/oc-bundles-deliz-cart.css   Deliz float cart: full-width bundle contents line.
assets/js/oc-bundles-deliz-cart.js     Deliz float cart: moves the plugin's contents block
                                       out of the theme's narrow details column (plugin-only),
                                       adds the theme's edit button (reopens the line's swaps).
languages/oc-bundles.pot               Source strings.
languages/oc-bundles-he_IL.po/.mo      Hebrew translation (compiled).
readme.txt                             Plugin readme.
```

**Stock** — component stock is reconciled, never re-reduced. Each bundle order line
keeps `_oc_bundle_reduced` (index => qty already taken) and optional
`_oc_bundle_actual` (index => weighed line total); `Order::reconcile()` applies
`want - have` per component. A bundle line's quantity counts bundles, not kilos, so
the weighed amount cannot live in the line quantity the way it does for a plain
OCWSU product — hence the separate meta. Orders from before the ledger carry only
`_oc_bundle_stock_reduced` and are seeded from the ordered quantities on first save.

**Invoice split** — with `invoice_display=components` the bundle order line is
rebuilt at checkout, and **`pricing_mode` decides who owns the money**:
`fixed` keeps the price on the bundle line and the components show nothing but
their own swap surcharge (blank, never `0.00`, when included); `sum` gives each
component its own share (discount applied proportionally, surcharge on top
undiscounted) and the bundle line drops to zero. The order total is unchanged — what leaves the bundle line lands
on the component lines, taxes moved proportionally. Component lines carry **no
product ID** on purpose: every WooCommerce stock path skips items whose
`get_product()` is falsy, so `Order` stays the only place component stock moves.

**Config meta** — all under prefix `_oc_bundle_`. Keys (defaults in
`Helpers::defaults()`): `components`, `pricing_mode` (fixed|sum), `fixed_price`,
`discount_type` (none|percent|fixed), `discount_value`, `hide_price_labels`,
`oos_behavior` (unavailable|swap), `show_components_in_desc`, `layout`
(grid|list), `cart_display` (name_with_components|name_only|line),
`invoice_display` (bundle|components).

**Component model** (normalized): `product_id`, `variation_id`, `qty`, `unit`,
`unit_label`, `unit_weight`, `mode` (units|units_weight|weight), `description`,
`swappable` (yes|no), `swaps[{product_id,variation_id,surcharge}]`.
`unit`/`mode`/`unit_weight` are auto-derived from the product by
`Helpers::product_spec()` — the admin/API only need product + qty.

---

## Pricing model (important)

- `raw_base_price` = fixed_price (fixed mode) or sum of component prices (sum
  mode), **before** discount.
- `base_price` = raw after the bundle discount (percent or fixed amount).
- `line_price` = base_price + surcharges of the currently-active swaps.
- WooCommerce getters (filters in `Pricing::init`): `get_price()` = base,
  `get_regular_price()` = raw, `get_sale_price()` = base when discounted else '' — so
  `is_on_sale()` / `get_price_html()` render struck regular + sale natively. Cart line
  objects get their captured unit price, with the discount added back for regular.
- `sync_price_meta` writes `_price` = base; if discounted, `_regular_price` =
  raw and `_sale_price` = base (so shop/category shows struck regular + sale
  natively, no labels). Single product page renders its own dual price with
  captions "Bundle price" / "Regular price" unless `hide_price_labels` is on.
- Call `Pricing::sync_price_meta()` after any change to components/pricing
  (admin save and API save already do).

---

## REST API (Giorgio sync)

Namespace `oc-bundles/v1`: `GET/POST /bundles`, `GET/PUT/DELETE /bundles/{id}`,
`GET /bundles/{id}/availability`. Auth: `X-OC-Bundles-Key` header (Settings →
Bundles API) or Application Password (`manage_woocommerce`). Full reference and
the Giorgio integration spec live outside the repo (ask George if needed).
Keep the API in lockstep with admin behavior: anything the API writes must go
through the same config + `sync_price_meta` path as `Admin::save()`.

---

## Conventions — follow these

- **Version bump on every shipped change:** update BOTH the `Version:` header
  and the `OC_BUNDLES_VERSION` constant in `oc-bundles.php` (keep them equal).
  Semver: patch for fixes, minor for features.
- **i18n:** all user-facing strings are English `__()/_e()/esc_html_e()` msgids
  with text domain `oc-bundles`. When you add/change a string, add it to
  `languages/oc-bundles.pot` and `oc-bundles-he_IL.po`, then **recompile the
  .mo**. Prefer `msgfmt languages/oc-bundles-he_IL.po -o languages/oc-bundles-he_IL.mo`;
  if gettext isn't installed, use Python: `python3 -c "import polib;
  po=polib.pofile('languages/oc-bundles-he_IL.po');
  po.save_as_mofile('languages/oc-bundles-he_IL.mo')"`.
- **No build step, no frameworks.** Vanilla PHP + jQuery. Match existing style.
- **Security:** escape on output (`esc_html`, `esc_attr`, `wp_kses_post`),
  sanitize on input, `wp_unslash`, nonces for admin/AJAX, `hash_equals` for the
  API key. HPOS-compatible (no direct post-based order queries).
- **RTL:** front CSS uses logical properties / `text-align:start` — don't
  hardcode `rtl`/`left`/`right`.
- **Standalone first:** never hard-depend on OCWSU or Giorgio. Guard optional
  calls with `function_exists`/`class_exists` and fall back to Native.
- **Verify before shipping:** `node --check` each JS file; sanity-check PHP
  (run `php -l` on changed files if PHP is available locally).

---

## Git & versioning workflow

This folder is the plugin root and the git repo root.

1. **One-time:** set the real repo in the `Update URI:` header of
   `oc-bundles.php` (replace `OWNER/REPO`, e.g. `original-concepts/oc-bundles`).
   The updater reads GitHub **Releases** from that repo.
2. Commit with clear messages (e.g. `feat: hide price captions toggle`,
   `fix: variation label weight`). Reference the version.
3. **Release =** git tag `vX.Y.Z` (matching `OC_BUNDLES_VERSION`) + a GitHub
   Release with a zip whose top folder is `oc-bundles/`. The self-updater pulls
   the newest release; the folder is renamed to `oc-bundles` on install.
   With the GitHub CLI: `gh release create vX.Y.Z --title "vX.Y.Z" --notes "..."`
   (attach a built zip, or rely on the auto-generated source zip — the updater
   normalizes the folder name either way).

Do not push secrets. The API key lives in the site DB (option
`oc_bundles_api_key`), never in the repo.

---

## Testing

Staging: **deliz-short.deliz.co.il**. Typical loop: bump version → build/zip →
install on staging → verify on a real bundle product (single page + shop
archive + cart + order). George often verifies via screenshots.

## Site access (if using the Proginter MCP)

Editing live sites is a **separate** concern from this repo. If a Proginter MCP
is connected, follow the site-scope guardrails defined for site work — do not
touch any site not explicitly authorized. This repo file governs plugin code and
git only.
