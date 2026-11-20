/**
 * Region: a state or province, as a list for a country that has one and a
 * box for one that does not.
 */

import { config, t } from '../lib/config';
import { ours, sibling } from '../lib/dom';
import { request } from '../lib/request';
import Combobox from './combobox';

const Region = {
	/**
	 * Fit each region control to the country of its row.
	 *
	 * PHP drew two controls -- a text input, live, and a select, empty
	 * and disabled -- because it could not know the country at render
	 * time. This asks the server for that country's regions and keeps
	 * whichever control fits: the select where there is a list, the text
	 * input where there is not. Every step is guarded so that a field
	 * with no country beside it, or a request that fails, is left as it
	 * was drawn, with the text input live and submitting.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__region[data-country-key]' ).forEach(
			function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				const source = sibling( wrap, wrap.dataset.countryKey );

				if ( ! source ) {
					return;
				}

				Region.load( wrap, source.value );

				source.addEventListener( 'change', function () {
					// Another country, so whatever was chosen or typed for
					// the last one is not an answer any more: "TX" is not
					// a county, and Ontario's 'ON' is a state of Nigeria.
					// The first load keeps what was stored; a change of
					// country starts again.
					if ( source.value !== wrap.fieldKitCountry ) {
						Region.empty( wrap );
					}

					Region.load( wrap, source.value );
				} );
			}
		);
	},

	/**
	 * Fetch a country's regions and fit the control to them.
	 *
	 * Numbered, as the combobox numbers its searches: a slow answer for
	 * the previous country must not land after the answer for this one
	 * and put the wrong list in the select.
	 *
	 * @param {Element} wrap    The region field.
	 * @param {string}  country The country code.
	 */
	load( wrap, country ) {
		const endpoint =
			wrap.dataset.searchEndpoint ||
			( config.restUrl ? config.restUrl + 'search' : '' );
		let url;

		wrap.fieldKitCountry = country;

		wrap.fieldKitTicket = ( wrap.fieldKitTicket || 0 ) + 1;

		const ticket = wrap.fieldKitTicket;

		if ( ! country || ! endpoint || typeof fetch !== 'function' ) {
			Region.apply( wrap, [] );

			return;
		}

		try {
			url = new URL( endpoint, window.location.origin );
		} catch {
			Region.apply( wrap, [] );

			return;
		}

		// On a public form there may be nobody logged in to search,
		// so the regions come from the address route, which is open.
		if (
			wrap.closest &&
			wrap.closest( '[data-sugarcommerce-form]' ) &&
			/^[A-Za-z]{2}$/.test( country )
		) {
			url = new URL(
				( config.restUrl || '' ) + 'address/' + country.toUpperCase(),
				window.location.origin
			);
		} else {
			url.searchParams.set( 'source', 'region' );
			url.searchParams.set( 'args[country]', country );
		}

		request( url, {
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce':
					wrap.dataset.searchNonce || config.restNonce || '',
			},
		} )
			.then( function ( response ) {
				return response.ok ? response.json() : { results: [] };
			} )
			.then( function ( data ) {
				if ( ticket === wrap.fieldKitTicket ) {
					Region.apply( wrap, data.results || data.regions || [] );
				}
			} )
			.catch( function () {
				if ( ticket === wrap.fieldKitTicket ) {
					Region.apply( wrap, [] );
				}
			} );
	},

	/**
	 * Make one control live and the other inert.
	 *
	 * Only one may submit, so the one standing down is disabled as well
	 * as hidden: a hidden control still posts. Nothing is announced. A
	 * select appearing where a text box was is a change of control, not
	 * of state, and the label reads the same either way.
	 *
	 * @param {Element} wrap    The region field.
	 * @param {Array}   regions Rows of {id, text}; empty for a country without any.
	 */
	apply( wrap, regions ) {
		const select = wrap.querySelector( '.field-kit__region-select' );
		const text = wrap.querySelector( '.field-kit__region-text' );

		if ( ! select || ! text ) {
			return;
		}

		// Whichever control is live holds the current value: the stored
		// one from the text input before the first swap, the chosen one
		// from the select after it.
		const current = select.disabled ? text.value : select.value;

		select.textContent = '';

		// A searchable select is inside the combobox built over it,
		// once one has been: that is then the thing to show and hide.
		const box = select.closest( '.field-kit__combobox' );

		if ( ! regions.length ) {
			select.disabled = true;
			select.hidden = true;
			text.disabled = false;
			text.hidden = false;

			if ( box ) {
				box.hidden = true;
			}

			return;
		}

		if ( ! select.hasAttribute( 'required' ) ) {
			select.appendChild(
				Region.option(
					'',
					select.dataset.placeholder ||
						t( 'selectRegion', 'Select a region' )
				)
			);
		}

		regions.forEach( function ( region ) {
			const option = Region.option(
				String( region.id ),
				String( region.text )
			);

			if ( option.value === current ) {
				option.selected = true;
			}

			select.appendChild( option );
		} );

		text.disabled = true;
		text.hidden = true;
		select.disabled = false;
		select.hidden = false;

		Region.search( select, box );
	},

	/**
	 * Put a search box over a select that asked for one.
	 *
	 * Built here and not with every other combobox on the page, because
	 * until now the select was empty: the combobox takes its placeholder
	 * and its way back to nothing from the empty option, and there was
	 * none. Once built it is only told that its list has changed.
	 *
	 * @param {HTMLSelectElement} select The region select, filled.
	 * @param {Element|null}      box    Its combobox, if it has one already.
	 */
	search( select, box ) {
		if ( 'true' !== select.dataset.searchable ) {
			return;
		}

		if ( box ) {
			box.hidden = false;
			select.dispatchEvent( new Event( 'field-kit:refresh' ) );

			return;
		}

		select.classList.add( 'field-kit__select--enhanced' );
		select.dataset.fkBound = '1';
		Combobox.build( select );
	},

	/**
	 * Forget what was chosen or typed.
	 *
	 * @param {Element} wrap The region field.
	 */
	empty( wrap ) {
		const select = wrap.querySelector( '.field-kit__region-select' );
		const text = wrap.querySelector( '.field-kit__region-text' );

		if ( text ) {
			text.value = '';
		}

		if ( select ) {
			select.textContent = '';
		}
	},

	/**
	 * One option.
	 *
	 * @param {string} value Its value.
	 * @param {string} label Its text.
	 * @return {Element} The option.
	 */
	option( value, label ) {
		const option = document.createElement( 'option' );

		option.value = value;
		option.textContent = label;

		return option;
	},
};

export default Region;
