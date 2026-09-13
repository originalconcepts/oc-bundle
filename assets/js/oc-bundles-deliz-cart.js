/**
 * OC Bundles — Deliz float cart: a bundle's contents on a full-width line.
 *
 * The deliz-short float cart lays every row out as thumbnail | details | quantity |
 * price and prints WooCommerce item data inside the details column, which is only a
 * few dozen pixels wide. A bundle's contents list does not fit there: it squeezes the
 * product names and widens the column, pushing the quantity and price out of the cart.
 *
 * This moves the plugin's own contents block — nothing of the theme's — out of that
 * column onto a line of its own under the row, so the row keeps exactly the layout
 * the theme gives any other product. The float cart is rebuilt from AJAX fragments
 * on every change, so the move runs again whenever new nodes arrive.
 *
 * @package OC_Bundles
 */
(function () {
	'use strict';

	var SOURCE = '.ed-float-cart__item .ed-float-cart__details .oc-bundle-contents';

	/**
	 * WooCommerce prints item data as "Label: value". The label travels with the
	 * block, so drop the copy left in front of it.
	 */
	function removeLabelText(block, label) {
		var prev = block.previousSibling;
		if (!label || !prev || prev.nodeType !== 3) {
			return;
		}
		var text = prev.nodeValue;
		var at = text.lastIndexOf(label + ':');
		if (at !== -1 && text.slice(at + label.length + 1).trim() === '') {
			prev.nodeValue = text.slice(0, at);
		}
	}

	function relocate() {
		var blocks = document.querySelectorAll(SOURCE);
		for (var i = 0; i < blocks.length; i++) {
			var block = blocks[i];
			var item = block.closest('.ed-float-cart__item');
			var inner = item ? item.querySelector(':scope > .cart_item_inner') : null;
			if (!inner) {
				continue;
			}

			var label = block.getAttribute('data-oc-label') || '';
			removeLabelText(block, label);

			var line = document.createElement('div');
			line.className = 'oc-bundle-cart-contents';
			if (label) {
				var heading = document.createElement('div');
				heading.className = 'oc-bundle-cart-contents__label';
				heading.textContent = label;
				line.appendChild(heading);
			}
			line.appendChild(block);
			inner.insertAdjacentElement('afterend', line);
		}
	}

	var queued = false;

	function schedule() {
		if (queued) {
			return;
		}
		queued = true;
		// A microtask still runs before the next paint, so the contents never show up
		// inside the narrow column first.
		Promise.resolve().then(function () {
			queued = false;
			relocate();
		});
	}

	relocate();

	if (window.MutationObserver) {
		new MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				if (mutations[i].addedNodes.length) {
					schedule();
					return;
				}
			}
		}).observe(document.body, { childList: true, subtree: true });
	}
})();
