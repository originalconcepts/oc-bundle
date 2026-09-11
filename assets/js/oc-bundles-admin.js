/* Original Concepts Bundles — admin */
( function ( $ ) {
	'use strict';

	function reinit() {
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	}

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}

	/* Build the dynamic quantity cell from a product spec (mirrors PHP render_qty_cell). */
	function buildQtyCell( spec, index ) {
		var mode = spec.mode || 'units';
		var isWeight = ( mode === 'weight' );
		var qty = isWeight ? ( spec.min || 0.1 ) : 1;
		var suffix = isWeight ? spec.weight_unit_label : ( spec.unit_suffix || spec.unit_label );
		var step = isWeight ? spec.step : 1;
		var min = isWeight ? spec.min : 1;

		var html = '<div class="oc-qty-cell" data-mode="' + esc( mode ) + '">';
		html += '<input type="hidden" name="oc_component_mode[' + index + ']" value="' + esc( mode ) + '" />';
		html += '<input type="hidden" name="oc_component_unit[' + index + ']" value="' + esc( spec.unit ) + '" />';
		html += '<input type="hidden" name="oc_component_unit_label[' + index + ']" value="' + esc( spec.unit_label ) + '" />';
		html += '<input type="hidden" class="oc-variation-field" name="oc_component_variation[' + index + ']" value="0" />';
		html += '<input type="hidden" class="oc-unit-weight-field" name="oc_component_unit_weight[' + index + ']" value="' + esc( spec.unit_weight || 0 ) + '" />';

		if ( mode === 'units_weight' && spec.weight_options && spec.weight_options.length ) {
			html += '<div class="oc-field oc-field-variation">';
			html += '<select class="oc-weight-select">';
			spec.weight_options.forEach( function ( opt ) {
				var val = opt.variation_id ? opt.variation_id : opt.weight;
				html += '<option value="' + esc( val ) + '" data-variation="' + esc( opt.variation_id ) + '" data-weight="' + esc( opt.weight ) + '">' + esc( opt.label ) + '</option>';
			} );
			html += '</select></div>';
		}

		html += '<div class="oc-field oc-field-qty">';
		html += '<span class="oc-qty-input-wrap">';
		html += '<input type="number" class="oc-qty-input" name="oc_component_qty[' + index + ']" value="' + qty + '" step="' + step + '" min="' + min + '" />';
		html += '<span class="oc-qty-suffix">' + esc( suffix ) + '</span>';
		html += '</span>';
		if ( mode === 'units' && parseFloat( spec.unit_weight ) > 0 ) {
			html += '<span class="oc-qty-hint">' + esc( ocBundlesAdmin.i18n.eachUnit.replace( '%s', spec.unit_weight ) ) + '</span>';
		} else if ( mode === 'units_weight' ) {
			var w0 = ( spec.weight_options && spec.weight_options.length ) ? parseFloat( spec.weight_options[0].weight ) : 0;
			html += '<span class="oc-qty-hint">' + ( w0 > 0 ? esc( ocBundlesAdmin.i18n.eachUnit.replace( '%s', w0 ) ) : '' ) + '</span>';
		}
		html += '</div>';

		html += '</div>';
		return html;
	}

	function syncWeightHidden( $select ) {
		var $opt = $select.find( 'option:selected' );
		var $cell = $select.closest( '.oc-qty-cell' );
		var weight = parseFloat( $opt.data( 'weight' ) ) || 0;
		$cell.find( '.oc-variation-field' ).val( $opt.data( 'variation' ) || 0 );
		$cell.find( '.oc-unit-weight-field' ).val( $opt.data( 'weight' ) || 0 );

		// Reflect the selected variation's weight as the per-unit weight hint.
		var $hint = $cell.find( '.oc-qty-hint' );
		if ( ! $hint.length ) {
			$cell.find( '.oc-field-qty' ).append( '<span class="oc-qty-hint"></span>' );
			$hint = $cell.find( '.oc-qty-hint' );
		}
		$hint.text( weight > 0 ? ocBundlesAdmin.i18n.eachUnit.replace( '%s', weight ) : '' );
	}

	/* Product chosen → fetch spec and (re)build the quantity cell. */
	$( document ).on( 'change', '.oc-product-select', function () {
		var $select = $( this );
		var productId = $select.val();
		var $group = $select.closest( '.oc-component-group' );
		var index = $group.data( 'index' );
		var $cell = $group.find( '.oc-card-fields .oc-qty-cell' ).first();
		var $title = $group.find( '.oc-card-title' ).first();

		if ( ! productId ) {
			$title.text( ocBundlesAdmin.i18n.bundleItem );
			$cell.replaceWith( '<div class="oc-qty-cell" data-mode=""><span class="oc-qty-empty">' + ocBundlesAdmin.i18n.chooseFirst + '</span></div>' );
			return;
		}

		var label = $.trim( $select.find( 'option:selected' ).text() );
		if ( label ) {
			$title.text( label );
		}

		$.post(
			ocBundlesAdmin.ajaxUrl,
			{ action: 'oc_bundles_product_config', nonce: ocBundlesAdmin.nonce, product_id: productId },
			function ( res ) {
				if ( res && res.success ) {
					$group.find( '.oc-card-fields .oc-qty-cell' ).first().replaceWith( buildQtyCell( res.data, index ) );
					var $sel = $group.find( '.oc-weight-select' );
					if ( $sel.length ) {
						syncWeightHidden( $sel );
					}
				}
			}
		);
	} );

	$( document ).on( 'change', '.oc-weight-select', function () {
		syncWeightHidden( $( this ) );
	} );

	/* Add component. */
	$( document ).on( 'click', '.oc-add-component', function ( e ) {
		e.preventDefault();
		var index = 'c' + Date.now();
		var html = $( '#tmpl-oc-component-row' ).html().replace( /\{\{INDEX\}\}/g, index );
		$( '.oc-components-list' ).append( html );
		reinit();
		initSortable();
	} );

	/* Swappable toggle → show/hide the alternatives block. */
	$( document ).on( 'change', '.oc-swappable-toggle', function () {
		$( this ).closest( '.oc-component-group' ).find( '.oc-swaps-block' ).toggle( $( this ).is( ':checked' ) );
	} );

	/* Short-description toggle → show/hide the description input. */
	$( document ).on( 'change', '.oc-desc-toggle', function () {
		$( this ).closest( '.oc-component-group' ).find( '.oc-card-desc' ).toggle( $( this ).is( ':checked' ) );
	} );

	/* Add an alternative product. */
	$( document ).on( 'click', '.oc-add-swap', function ( e ) {
		e.preventDefault();
		var $block = $( this ).closest( '.oc-swaps-block' );
		var index = $block.closest( '.oc-component-group' ).data( 'index' );
		var oindex = 'o' + Date.now();
		var html = $( '#tmpl-oc-swap-row' ).html()
			.replace( /\{\{INDEX\}\}/g, index )
			.replace( /\{\{OINDEX\}\}/g, oindex );
		$block.find( '.oc-swaps-table tbody' ).append( html );
		reinit();
	} );

	/* Remove a row. */
	$( document ).on( 'click', '.oc-remove-row', function ( e ) {
		e.preventDefault();
		var $swapRow = $( this ).closest( '.oc-swap-row' );
		if ( $swapRow.length ) {
			if ( window.confirm( ocBundlesAdmin.i18n.confirmRemoveSwap ) ) {
				$swapRow.remove();
			}
			return;
		}
		if ( window.confirm( ocBundlesAdmin.i18n.confirmRemoveItem ) ) {
			$( this ).closest( '.oc-component-group' ).remove();
		}
	} );

	/* Minimize / expand a bundle-item card. */
	$( document ).on( 'click', '.oc-collapse-row', function ( e ) {
		e.preventDefault();
		var $group = $( this ).closest( '.oc-component-group' );
		var collapsed = $group.toggleClass( 'is-collapsed' ).hasClass( 'is-collapsed' );
		$( this )
			.html( collapsed ? '&plus;' : '&minus;' )
			.attr( { title: collapsed ? ocBundlesAdmin.i18n.expand : ocBundlesAdmin.i18n.minimize, 'aria-label': collapsed ? ocBundlesAdmin.i18n.expand : ocBundlesAdmin.i18n.minimize } );
	} );

	/* Drag to reorder components. */
	function initSortable() {
		if ( ! $.fn.sortable ) { return; }
		var $list = $( '.oc-components-list' );
		if ( ! $list.length ) { return; }
		if ( $list.data( 'ocSortable' ) ) {
			$list.sortable( 'refresh' );
			return;
		}
		$list.sortable( {
			items: '> .oc-component-group',
			handle: '.oc-card-head',
			cancel: '.oc-remove-row, a, button, input, select',
			axis: 'y',
			cursor: 'move',
			opacity: 0.9,
			tolerance: 'pointer',
			forcePlaceholderSize: true,
			placeholder: 'oc-sort-placeholder oc-component-group'
		} );
		$list.data( 'ocSortable', true );
	}
	$( initSortable );

	/* Sync hidden weight + hint for already-rendered variation selects. */
	$( function () {
		$( '.oc-weight-select' ).each( function () {
			syncWeightHidden( $( this ) );
		} );
	} );

	/* Hide the discount value field when the discount type is "None". */
	function toggleDiscountValue() {
		var $sel = $( '#_oc_bundle_discount_type' );
		if ( ! $sel.length ) {
			return;
		}
		$( '.oc-discount-value' ).toggle( 'none' !== $sel.val() );
	}
	$( document ).on( 'change', '#_oc_bundle_discount_type', toggleDiscountValue );
	$( toggleDiscountValue );

	/* The product-data panel may be hidden on load; (re)init when the tab opens. */
	$( document ).on( 'click', '.product_data_tabs a[href="#oc_bundle_data"], .oc_bundle_options a, .oc_bundle_tab a', function () {
		setTimeout( initSortable, 50 );
	} );
	// Also re-init shortly after load in case the panel was hidden at ready.
	setTimeout( initSortable, 800 );

	/* Toggle fixed-price field by pricing mode. */
	function togglePrice() {
		var mode = $( 'input[name="_oc_bundle_pricing_mode"]:checked' ).val();
		$( '.oc-fixed-price-field' ).toggle( 'sum' !== mode );
	}
	$( document ).on( 'change', 'input[name="_oc_bundle_pricing_mode"]', togglePrice );
	$( togglePrice );
} )( jQuery );
