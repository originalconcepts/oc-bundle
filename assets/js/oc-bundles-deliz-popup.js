/* Original Concepts Bundles — deliz-short product popup integration.
 *
 * The theme builds its modal client-side. Core calls
 * window.EDProductPopupRender.fetchPopupHTML(data) — which internally calls its
 * own local renderPopupHTML binding, so only fetchPopupHTML can be intercepted
 * from outside. We wrap it and splice in the bundle components block carried by
 * the REST payload (see class-oc-bundles-deliz-popup.php).
 *
 * The plugin's own handlers are delegated on document, so swapping works inside
 * the modal with no extra wiring. Three things still need bridging:
 *   - the headline price, which WooCommerce reports as the plain base and so
 *     misses any surcharge from a component that was auto-swapped for stock;
 *   - the running total after a manual swap, mirrored into the same node;
 *   - the chosen swaps, which must reach the add-to-cart request. The theme
 *     posts JSON to its own ed/v1/add-to-cart route, so we add the selection to
 *     that body here and copy it into $_POST server-side (see the PHP class),
 *     which is where OC_Bundles_Cart::add_cart_item_data reads it from.
 *
 * It also gives bundles the theme's opening scroll nudge (see below), and keeps a
 * discounted bundle's price in WooCommerce's sale format after a swap.
 */
( function () {
	'use strict';

	var PRICE_SELECTOR = '.ed-product-popup__price-value';
	var ADD_TO_CART_ROUTE = '/ed/v1/add-to-cart';
	var POPUP_ROUTE = '/ed/v1/product-popup';

	/* ---------------------------------------------------------------- markup */

	function buildBundleMarkup( b ) {
		var wrap = document.createElement( 'div' );
		wrap.className = 'oc-bundle oc-bundle--in-popup oc-layout-' + ( b.layout || 'grid' );
		wrap.setAttribute( 'data-bundle-id', b.bundle_id );
		wrap.setAttribute( 'data-base-price', b.base_price );
		wrap.setAttribute( 'data-discount-amount', b.discount_amount );

		var head = document.createElement( 'div' );
		head.className = 'oc-bundle-components-head';
		var title = document.createElement( 'span' );
		title.className = 'oc-bundle-components-title';
		title.textContent = b.heading || '';
		head.appendChild( title );
		if ( b.count ) {
			var count = document.createElement( 'span' );
			count.className = 'oc-bundle-count';
			count.textContent = b.count;
			head.appendChild( count );
		}
		wrap.appendChild( head );

		var host = document.createElement( 'div' );
		host.innerHTML = b.components_html;
		while ( host.firstChild ) {
			wrap.appendChild( host.firstChild );
		}

		// applySwap() writes the selection into .oc-bundle-selection inside
		// .oc-bundle-form, and mirrors the running total into .oc-add-price.
		var form = document.createElement( 'div' );
		form.className = 'oc-bundle-form';
		var sel = document.createElement( 'input' );
		sel.type = 'hidden';
		sel.className = 'oc-bundle-selection';
		sel.name = 'oc_bundle_selection';
		sel.value = JSON.stringify( b.selection || {} );
		form.appendChild( sel );
		var mirror = document.createElement( 'span' );
		mirror.className = 'oc-add-price';
		mirror.style.display = 'none';
		if ( b.price_html ) {
			mirror.innerHTML = b.price_html;
		}
		form.appendChild( mirror );
		wrap.appendChild( form );

		return wrap;
	}

	function injectInto( doc, bundle ) {
		var info = doc.querySelector( '.ed-product-popup__info' );
		if ( ! info ) {
			return false;
		}

		// The theme prints WooCommerce's price, which is the bundle base only. When
		// a component is out of stock the plugin auto-swaps it and that swap can
		// carry a surcharge, so the amount actually charged is higher. Show the
		// bundle's own computed price instead of letting the two disagree.
		if ( bundle.price_html ) {
			var priceNode = doc.querySelector( PRICE_SELECTOR );
			if ( priceNode ) {
				priceNode.innerHTML = bundle.price_html;
			}
		}

		var block = buildBundleMarkup( bundle );

		// Keep the theme's own order intact — short description stays directly
		// under the price, as on every other product — and place the component
		// cards after it, above the options group.
		var opts = info.querySelector( '.ed-product-popup__options' );
		if ( opts ) {
			info.insertBefore( block, opts );
			return true;
		}

		var desc = info.querySelector( '.ed-product-popup__description' );
		if ( desc && desc.nextSibling ) {
			info.insertBefore( block, desc.nextSibling );
			return true;
		}

		info.appendChild( block );
		return true;
	}

	function transform( html, data ) {
		try {
			if ( ! data || ! data.oc_bundle || ! data.oc_bundle.components_html ) {
				return html;
			}
			if ( typeof html !== 'string' || html.indexOf( 'ed-product-popup__info' ) === -1 ) {
				return html;
			}
			var doc = new DOMParser().parseFromString( html, 'text/html' );
			if ( ! injectInto( doc, data.oc_bundle ) ) {
				return html;
			}
			return doc.body.innerHTML;
		} catch ( e ) {
			// Never let the integration break the popup — fall back to stock markup.
			if ( window.console && console.warn ) {
				console.warn( 'oc-bundles: popup injection failed', e );
			}
			return html;
		}
	}

	function wrapRenderer() {
		var R = window.EDProductPopupRender;
		if ( ! R || R.__ocBundlesWrapped ) {
			return !! ( R && R.__ocBundlesWrapped );
		}
		if ( typeof R.fetchPopupHTML !== 'function' ) {
			return false;
		}

		// Core awaits this one, and it is reached through the global object —
		// unlike renderPopupHTML, which fetchPopupHTML calls via a local binding.
		var originalFetch = R.fetchPopupHTML;
		R.fetchPopupHTML = function ( data ) {
			return Promise.resolve( originalFetch.apply( this, arguments ) ).then( function ( html ) {
				return transform( html, data );
			} );
		};

		if ( typeof R.renderPopupHTML === 'function' ) {
			var originalRender = R.renderPopupHTML;
			R.renderPopupHTML = function ( data ) {
				return transform( originalRender.apply( this, arguments ), data );
			};
		}

		R.__ocBundlesWrapped = true;
		return true;
	}

	function boot() {
		if ( wrapRenderer() ) {
			return;
		}
		var tries = 0;
		var timer = setInterval( function () {
			tries++;
			if ( wrapRenderer() || tries > 60 ) {
				clearInterval( timer );
			}
		}, 50 );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	/* ------------------------------------------------------------- price sync */

	function currentSelectionValue() {
		var popup = document.getElementById( 'ed-product-popup' );
		if ( ! popup ) {
			return null;
		}
		var input = popup.querySelector( '.oc-bundle--in-popup .oc-bundle-selection' );
		return input ? ( input.value || '{}' ) : null;
	}

	// Same rules as formatPrice() in oc-bundles.js, wrapped the way wc_price() wraps.
	function formatAmount( amount ) {
		var c = ( window.ocBundles && window.ocBundles.currency ) || {};
		var dec = parseInt( c.decimals, 10 );
		if ( isNaN( dec ) ) {
			dec = 2;
		}
		var parts = Math.abs( amount ).toFixed( dec ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thousandSep || ',' );
		var num = parts.join( dec > 0 ? ( c.decimalSep || '.' ) : '' );
		var str = ( c.format || '%1$s%2$s' ).replace( '%1$s', c.symbol || '' ).replace( '%2$s', num );
		return '<span class="woocommerce-Price-amount amount"><bdi>' + ( amount < 0 ? '-' : '' ) + str + '</bdi></span>';
	}

	function syncPrice() {
		var popup = document.getElementById( 'ed-product-popup' );
		if ( ! popup ) {
			return;
		}
		var bundle = popup.querySelector( '.oc-bundle--in-popup' );
		var mirror = bundle ? bundle.querySelector( '.oc-add-price' ) : null;
		var target = popup.querySelector( PRICE_SELECTOR );
		if ( ! mirror || ! target || ! mirror.innerHTML.trim() ) {
			return;
		}
		var sale = parseFloat( mirror.getAttribute( 'data-amount' ) );
		if ( isNaN( sale ) ) {
			target.innerHTML = mirror.innerHTML;
			return;
		}
		// A discounted bundle keeps WooCommerce's sale format — regular struck through,
		// sale after it — which the theme colours like any other product on sale.
		var discount = parseFloat( bundle.getAttribute( 'data-discount-amount' ) ) || 0;
		target.innerHTML = discount > 0
			? '<del aria-hidden="true">' + formatAmount( sale + discount ) + '</del> <ins>' + formatAmount( sale ) + '</ins>'
			: formatAmount( sale );
	}

	if ( window.jQuery ) {
		// applySwap() runs on this click; let it finish, then mirror the total.
		window.jQuery( document ).on( 'click', '.oc-swap-apply', function () {
			setTimeout( syncPrice, 0 );
		} );
	}

	/* ----------------------------------------------------------- scroll nudge */

	// When there is more below the fold the theme opens its product modal with a short
	// scroll down and back, but it skips bundles on purpose: its bottom fade looked like
	// a broken bar over the component headings. Bundles get the nudge alone, with the
	// theme's own timing, so they hint at the rest of the list like any other product.
	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function easeInOut( p ) {
		return p < 0.5 ? 2 * p * p : 1 - Math.pow( -2 * p + 2, 2 ) / 2;
	}

	function animateScroll( el, from, to, duration, done ) {
		var started = null;
		function step( now ) {
			if ( started === null ) {
				started = now;
			}
			var p = Math.min( 1, ( now - started ) / duration );
			el.scrollTop = from + ( to - from ) * easeInOut( p );
			if ( p < 1 ) {
				window.requestAnimationFrame( step );
			} else if ( done ) {
				done();
			}
		}
		window.requestAnimationFrame( step );
	}

	function nudgeBundle( popup ) {
		var tries = 0;
		( function check() {
			if ( ! popup.classList.contains( 'is-open' ) ) {
				return;
			}
			var scroller = popup.querySelector( '.ed-product-popup__content' );
			var overflow = scroller ? scroller.scrollHeight - scroller.clientHeight : 0;
			if ( ! scroller || ! popup.querySelector( '.oc-bundle--in-popup' ) || overflow <= 24 ) {
				if ( tries++ < 60 ) {
					setTimeout( check, 90 );
				}
				return;
			}
			setTimeout( function () {
				// A theme that hints bundles itself keeps its fade element in place — leave it
				// to the theme rather than nudging twice.
				if ( ! popup.classList.contains( 'is-open' ) || popup.querySelector( '.ed-pp-scrollhint' ) ) {
					return;
				}
				var start = scroller.scrollTop;
				var distance = Math.min( 46, overflow );
				animateScroll( scroller, start, start + distance, 340, function () {
					setTimeout( function () {
						if ( popup.classList.contains( 'is-open' ) ) {
							animateScroll( scroller, start + distance, start, 400 );
						}
					}, 280 );
				} );
			}, 300 );
		} )();
	}

	function watchPopup( popup ) {
		if ( popup.__ocBundlesNudge ) {
			return;
		}
		popup.__ocBundlesNudge = true;
		var wasOpen = popup.classList.contains( 'is-open' );
		new MutationObserver( function () {
			var open = popup.classList.contains( 'is-open' );
			if ( open && ! wasOpen ) {
				nudgeBundle( popup );
			}
			wasOpen = open;
		} ).observe( popup, { attributes: true, attributeFilter: [ 'class' ] } );
		if ( wasOpen ) {
			nudgeBundle( popup );
		}
	}

	if ( ! reduceMotion && window.MutationObserver && document.body ) {
		var findPopup = function () {
			var popup = document.querySelector( '.ed-product-popup' );
			if ( popup ) {
				watchPopup( popup );
			}
		};
		new MutationObserver( findPopup ).observe( document.body, { childList: true, subtree: true } );
		findPopup();
	}

	/* ------------------------------------------------- add-to-cart bridging */

	// The theme posts a JSON body to its own REST route rather than using the
	// WooCommerce wc-ajax endpoint, and builds that body in a private function.
	// The request itself is the only place left to add the selection. Guarded
	// tightly: only that route, only a JSON string body, only while a bundle
	// popup is open.
	var originalWindowFetch = window.fetch;
	if ( typeof originalWindowFetch === 'function' ) {
		window.fetch = function ( input, init ) {
			try {
				var url = ( typeof input === 'string' ) ? input : ( input && input.url );

				// Reopening a bundle from the float cart's edit button: ask the popup payload for
				// the swaps that line holds, so the popup shows the customer's own bundle.
				var pending = window.ocBundlesPendingEdit;
				if ( pending && typeof input === 'string' && input.indexOf( POPUP_ROUTE ) !== -1 && Date.now() - pending.at < 4000 ) {
					var id = input.match( /[?&]id=(\d+)/ );
					if ( id && id[ 1 ] === String( pending.productId ) ) {
						window.ocBundlesPendingEdit = null;
						var withSelection = input + ( input.indexOf( '?' ) === -1 ? '?' : '&' ) + 'oc_bundle_selection=' + encodeURIComponent( pending.selection );
						return originalWindowFetch.call( this, withSelection, init );
					}
				}
				if ( url && String( url ).indexOf( ADD_TO_CART_ROUTE ) !== -1 && init && typeof init.body === 'string' ) {
					var selection = currentSelectionValue();
					if ( selection ) {
						var payload = JSON.parse( init.body );
						if ( payload && typeof payload === 'object' && ! ( 'oc_bundle_selection' in payload ) ) {
							payload.oc_bundle_selection = selection;
							init = Object.assign( {}, init, { body: JSON.stringify( payload ) } );
							return originalWindowFetch.call( this, input, init );
						}
					}
				}
			} catch ( e ) {
				// A failure here must never block the request.
			}
			return originalWindowFetch.apply( this, arguments );
		};
	}
} )();
