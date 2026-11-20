/**
 * Money: each amount's symbol kept in step with the currency of its row.
 */

import { ours, sibling } from '../lib/dom';

const Money = {
	/**
	 * Keep each amount's symbol in step with the currency of its row.
	 *
	 * Only where there is more than one currency to be in: a store with
	 * one gets static text from PHP and never reaches this.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__money[data-currency-key]' ).forEach(
			function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				const source = Money.source( wrap );

				if ( ! source ) {
					return;
				}

				Money.apply( wrap, source.value );

				source.addEventListener( 'change', function () {
					Money.apply( wrap, source.value );
				} );
			}
		);
	},

	/**
	 * The control holding this amount's currency code.
	 *
	 * The kit's sibling lookup, which is scoped to the row: a repeater
	 * has one per row, and the first match in the form would give every
	 * row the first row's currency.
	 *
	 * @param {Element} wrap The money field.
	 * @return {Element|null} The control, if there is one.
	 */
	source( wrap ) {
		return sibling( wrap, wrap.dataset.currencyKey );
	},

	/**
	 * Draw the symbol for a code.
	 *
	 * @param {Element} wrap The money field.
	 * @param {string}  code The currency code.
	 */
	apply( wrap, code ) {
		const symbol = wrap.querySelector( '.field-kit__money-symbol' );

		if ( ! symbol ) {
			return;
		}

		let symbols = {};

		try {
			symbols = JSON.parse( wrap.dataset.symbols || '{}' );
		} catch {
			return;
		}

		// The code itself when it is not one we know, which is what PHP
		// draws too -- an empty affix is a control with a gap where its
		// symbol should be.
		symbol.textContent = symbols[ code ] || code || symbol.textContent;

		// The room kept for it follows it. PHP decides this for the
		// symbol it draws, and a switch from "£" to "CHF" here left
		// the code drawn over the first digits.
		wrap.classList.toggle(
			'field-kit__money--wide',
			symbol.textContent.length > 1
		);
	},
};

export default Money;
