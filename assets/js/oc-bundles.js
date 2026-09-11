/* Original Concepts Bundles — frontend behavior */
( function ( $ ) {
	'use strict';

	/* ---- Price formatting (mirrors WooCommerce settings) ---- */
	function formatPrice( amount ) {
		var c = ocBundles.currency || {};
		var dec = parseInt( c.decimals, 10 );
		if ( isNaN( dec ) ) { dec = 2; }
		var n = Math.abs( amount ).toFixed( dec );
		var parts = n.split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thousandSep || ',' );
		var num = parts.join( dec > 0 ? ( c.decimalSep || '.' ) : '' );
		var str = ( c.format || '%1$s%2$s' ).replace( '%1$s', c.symbol || '' ).replace( '%2$s', num );
		return ( amount < 0 ? '-' : '' ) + str;
	}

	/* ---- Per-bundle state ---- */
	function state( $bundle ) {
		var s = $bundle.data( 'ocState' );
		if ( ! s ) {
			s = { active: {}, surcharge: {}, pools: {}, base: parseFloat( $bundle.data( 'base-price' ) ) || 0, discount: parseFloat( $bundle.data( 'discount-amount' ) ) || 0 };
			// Seed from server-rendered components (handles auto-swaps on load).
			$bundle.find( '.oc-component' ).each( function () {
				var idx = String( $( this ).data( 'index' ) );
				var key = parseInt( $( this ).data( 'active-key' ), 10 );
				var sur = parseFloat( $( this ).data( 'surcharge' ) ) || 0;
				if ( ! isNaN( key ) && key >= 0 ) {
					s.active[ idx ] = key;
				}
				if ( sur ) {
					s.surcharge[ idx ] = sur;
				}
			} );
			$bundle.data( 'ocState', s );
		}
		return s;
	}

	function getSelection( $form ) {
		var raw = $form.find( '.oc-bundle-selection' ).val();
		if ( ! raw ) { return {}; }
		try { return JSON.parse( raw ); } catch ( e ) { return {}; }
	}
	function setSelection( $form, sel ) {
		$form.find( '.oc-bundle-selection' ).val( JSON.stringify( sel ) );
	}

	function renderPrice( $bundle ) {
		var s = state( $bundle );
		var sale = s.base;
		Object.keys( s.surcharge ).forEach( function ( k ) { sale += s.surcharge[ k ]; } );
		var $price = $bundle.find( '.oc-bundle-price' );
		if ( s.discount > 0 ) {
			$price.find( '.oc-price-current .oc-price-amount' ).html( formatPrice( sale ) );
			$price.find( '.oc-price-was .oc-price-amount' ).html( formatPrice( sale + s.discount ) );
		} else {
			$price.find( '.oc-price-amount' ).html( formatPrice( sale ) );
		}
		$bundle.find( '.oc-add-price' ).html( formatPrice( sale ) );
	}

	/* ---- Quantity stepper (number of bundles) ---- */
	$( document ).on( 'click', '.oc-qty-plus, .oc-qty-minus', function ( e ) {
		e.preventDefault();
		var $input = $( this ).closest( '.oc-qty' ).find( '.oc-qty-input' );
		var val = parseInt( $input.val(), 10 ) || 1;
		var min = parseInt( $input.attr( 'min' ), 10 ) || 1;
		var max = parseInt( $input.attr( 'max' ), 10 ) || 9999;
		val += $( this ).hasClass( 'oc-qty-plus' ) ? 1 : -1;
		$input.val( Math.max( min, Math.min( max, val ) ) );
	} );

	/* ---- Open swap popup ---- */
	$( document ).on( 'click', '.oc-swap-link', function ( e ) {
		e.preventDefault();
		var $link = $( this );
		var $bundle = $link.closest( '.oc-bundle' );
		var $component = $link.closest( '.oc-component' );
		var index = String( $link.data( 'index' ) );
		var s = state( $bundle );

		function show() { openModal( $bundle, $component, index ); }

		if ( s.pools[ index ] ) { show(); return; }

		$.post(
			ocBundles.ajaxUrl,
			{ action: 'oc_bundles_swap_options', nonce: ocBundles.nonce, bundle_id: $bundle.data( 'bundle-id' ), index: index },
			function ( res ) {
				if ( ! res || ! res.success ) { return; }
				s.pools[ index ] = res.data.pool;
				show();
			}
		);
	} );

	function openModal( $bundle, $component, index ) {
		var s = state( $bundle );
		var pool = s.pools[ index ] || [];
		var activeKey = ( index in s.active ) ? s.active[ index ] : -1;

		// True swap: show everything except whatever is currently in the bundle.
		var candidates = pool.filter( function ( e ) { return e.key !== activeKey; } );

		var $overlay = $(
			'<div class="oc-swap-overlay"><div class="oc-swap-modal">' +
			'<div class="oc-swap-head"><span class="oc-swap-title"></span>' +
			'<button type="button" class="oc-swap-close">&times;</button></div>' +
			'<div class="oc-swap-body"></div>' +
			'<div class="oc-swap-foot"></div>' +
			'</div></div>'
		);
		$overlay.find( '.oc-swap-title' ).text( ocBundles.i18n.chooseAlternative );
		$overlay.find( '.oc-swap-close' ).attr( 'aria-label', ocBundles.i18n.close );

		if ( ! candidates.length ) {
			$overlay.find( '.oc-swap-body' ).html( '<p class="oc-swap-empty">' + ( ocBundles.i18n.noAlternatives || '' ) + '</p>' );
		} else {
			var $grid = $( '<div class="oc-swap-grid"></div>' );
			candidates.forEach( function ( e ) {
				$grid.append( e.tile );
			} );
			$overlay.find( '.oc-swap-body' ).append( $grid );
			$overlay.find( '.oc-swap-foot' ).html( '<button type="button" class="oc-swap-apply">' + ocBundles.i18n.apply + '</button>' );
		}

		$( 'body' ).append( $overlay );

		function close() { $overlay.remove(); }
		$overlay.on( 'click', '.oc-swap-close', close );
		$overlay.on( 'click', function ( e ) { if ( e.target === this ) { close(); } } );
		$overlay.on( 'click', '.oc-swap-option:not(.is-disabled)', function () {
			$overlay.find( '.oc-swap-option' ).removeClass( 'is-selected' );
			$( this ).addClass( 'is-selected' );
		} );
		$overlay.on( 'click', '.oc-swap-apply', function () {
			var $sel = $overlay.find( '.oc-swap-option.is-selected' );
			if ( $sel.length ) {
				applySwap( $bundle, $component, index, parseInt( $sel.data( 'key' ), 10 ) );
			}
			close();
		} );
	}

	function applySwap( $bundle, $component, index, key ) {
		var s = state( $bundle );
		var pool = s.pools[ index ] || [];
		var entry = null;
		pool.forEach( function ( e ) { if ( e.key === key ) { entry = e; } } );
		if ( ! entry ) { return; }

		// Swap into the bundle: update image, name, and surcharge.
		$component.find( '.oc-component-media' ).html( entry.image );
		$component.find( '.oc-component-name' ).text( entry.name );

		s.active[ index ] = key;
		s.surcharge[ index ] = entry.surcharge || 0;

		var $form = $bundle.find( '.oc-bundle-form' );
		var sel = getSelection( $form );
		if ( key < 0 ) { delete sel[ index ]; } else { sel[ index ] = key; }
		setSelection( $form, sel );

		renderPrice( $bundle );
	}
} )( jQuery );
