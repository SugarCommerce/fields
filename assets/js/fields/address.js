/**
 * An address laid out as its country writes one, again whenever the
 * country changes.
 */

import { t } from '../lib/config';
import { ask, endpoint } from '../lib/lookups';

const Address = {
	layouts: {},

	/**
	 * The layout of a country nothing is known about: every part.
	 */
	unknown: {
		country: '',
		lines: [
			[ 'name' ],
			[ 'company' ],
			[ 'line1' ],
			[ 'line2' ],
			[ 'city' ],
			[ 'state' ],
			[ 'postcode' ],
		],
		required: { line1: true, city: true, state: false, postcode: false },
		regions: [],
		postcode_pattern: '',
		postcode_example: '',
	},

	init( root ) {
		root.querySelectorAll( '.field-kit__address[data-address]' ).forEach(
			function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				const country = wrap.querySelector(
					'.field-kit__address-country'
				);
				const postcode = wrap.querySelector(
					'.field-kit__address-input--postcode'
				);
				let layout = null;

				try {
					layout = JSON.parse( wrap.dataset.layout || 'null' );
				} catch {
					layout = null;
				}

				if ( layout ) {
					Address.layouts[ layout.country || '' ] = layout;
					wrap.fkLayout = layout;
				}

				if ( country ) {
					country.addEventListener( 'change', function () {
						Address.load( wrap, country.value );
					} );
				}

				if ( postcode ) {
					postcode.addEventListener( 'blur', function () {
						Address.check( wrap );
					} );

					postcode.addEventListener( 'input', function () {
						if ( postcode.validationMessage ) {
							Address.check( wrap );
						}
					} );
				}
			}
		);
	},

	load( wrap, code ) {
		const ticket = ( wrap.fkTicket = ( wrap.fkTicket || 0 ) + 1 );
		const url = /^[A-Za-z]{2}$/.test( code )
			? endpoint( 'address/' + code.toUpperCase() )
			: null;

		if ( Address.layouts[ code ] ) {
			Address.apply( wrap, Address.layouts[ code ] );

			return;
		}

		if ( ! url ) {
			Address.apply(
				wrap,
				Object.assign( {}, Address.unknown, {
					labels: ( wrap.fkLayout || {} ).labels || {},
				} )
			);

			return;
		}

		ask( url )
			.then( function ( answer ) {
				if ( answer.ok && answer.json.lines ) {
					Address.layouts[ code ] = answer.json;
				}

				if ( ticket === wrap.fkTicket ) {
					Address.apply(
						wrap,
						answer.ok && answer.json.lines
							? answer.json
							: Object.assign( {}, Address.unknown, {
									labels:
										( wrap.fkLayout || {} ).labels || {},
								} )
					);
				}
			} )
			.catch( function () {} );
	},

	/**
	 * Lay the parts out for a country: move them into its lines, rename
	 * them, mark what it requires, swap the region list, and start the
	 * region and the postcode's check again.
	 *
	 * @param {Element} wrap   The field.
	 * @param {Object}  layout The country's layout.
	 */
	apply( wrap, layout ) {
		const lines = wrap.querySelector( '.field-kit__address-lines' );
		const spare = wrap.querySelector( '.field-kit__address-spare' );
		const required = 'true' === wrap.dataset.required;
		const parts = {};
		const used = {};

		if ( ! lines || ! spare ) {
			return;
		}

		wrap.querySelectorAll( '.field-kit__address-part[data-part]' ).forEach(
			function ( part ) {
				if ( 'country' !== part.dataset.part ) {
					parts[ part.dataset.part ] = part;
				}
			}
		);

		Object.keys( parts ).forEach( function ( name ) {
			spare.appendChild( parts[ name ] );
		} );

		lines.textContent = '';

		( layout.lines || [] ).forEach( function ( line ) {
			const row = document.createElement( 'div' );

			row.className = 'field-kit__address-line';

			line.forEach( function ( name ) {
				if ( parts[ name ] ) {
					row.appendChild( parts[ name ] );
					used[ name ] = true;
				}
			} );

			if ( row.children.length ) {
				lines.appendChild( row );
			}
		} );

		Object.keys( parts ).forEach( function ( name ) {
			const part = parts[ name ];
			const on = !! used[ name ];
			const must = on && required && !! ( layout.required || {} )[ name ];
			const label = part.querySelector( 'label' );
			const marker = part.querySelector( '.field-kit__address-marker' );

			part.hidden = ! on;

			if (
				label &&
				label.firstChild &&
				3 === label.firstChild.nodeType &&
				( layout.labels || {} )[ name ]
			) {
				label.firstChild.nodeValue = layout.labels[ name ];
			}

			if ( marker ) {
				marker.innerHTML = must
					? '<span class="field-kit__required" aria-hidden="true">*</span><span class="screen-reader-text">' +
						Address.escape( t( 'requiredWord', '(required)' ) ) +
						'</span>'
					: '';
			}

			if ( 'state' === name ) {
				Address.state(
					part,
					layout.regions || [],
					on,
					must,
					layout.country !== ( wrap.fkLayout || {} ).country
				);

				return;
			}

			part.querySelectorAll( 'input, select, textarea' ).forEach(
				function ( control ) {
					control.disabled = ! on;
					control.required = must;
				}
			);
		} );

		const postcode = wrap.querySelector(
			'.field-kit__address-input--postcode'
		);

		if ( postcode ) {
			postcode.dataset.pattern = layout.postcode_pattern || '';
			postcode.placeholder = String(
				layout.postcode_example || ''
			).split( ',' )[ 0 ];
			postcode.setCustomValidity( '' );
		}

		Address.hint( wrap, '' );
		wrap.fkLayout = layout;
	},

	/**
	 * The region: the country's list, or a box where it has none.
	 *
	 * @param {Element} part    The region's part.
	 * @param {Array}   regions The country's regions.
	 * @param {boolean} on      Whether the country has the part.
	 * @param {boolean} must    Whether it is required.
	 * @param {boolean} fresh   Whether the country changed, so what
	 *                          was chosen for the last one goes.
	 */
	state( part, regions, on, must, fresh ) {
		const select = part.querySelector( '.field-kit__address-state-select' );
		const text = part.querySelector( '.field-kit__address-state-text' );
		const label = part.querySelector( 'label' );
		const id = label ? label.htmlFor : '';

		if ( ! select || ! text ) {
			return;
		}

		let current = select.disabled ? text.value : select.value;

		if ( fresh ) {
			current = '';
		}
		const listed = regions.length > 0;
		const first = select.options.length ? select.options[ 0 ] : null;

		select.textContent = '';

		if ( first && '' === first.value ) {
			select.appendChild( first );
		}

		regions.forEach( function ( region ) {
			const option = document.createElement( 'option' );

			option.value = String( region.id );
			option.textContent = String( region.text );
			option.selected = option.value === current;
			select.appendChild( option );
		} );

		if ( fresh ) {
			text.value = '';
		}

		select.disabled = ! on || ! listed;
		select.hidden = ! listed;
		select.required = must && listed;
		text.disabled = ! on || listed;
		text.hidden = listed;
		text.required = must && ! listed;

		// The label points at whichever is live.
		select.removeAttribute( 'id' );
		text.removeAttribute( 'id' );
		( listed ? select : text ).id = id;
	},

	/**
	 * Check the postcode against the country's pattern, as the server
	 * will, and say what one looks like when it does not match.
	 *
	 * @param {Element} wrap The field.
	 */
	check( wrap ) {
		const postcode = wrap.querySelector(
			'.field-kit__address-input--postcode'
		);
		const layout = wrap.fkLayout || {};
		const pattern = postcode ? postcode.dataset.pattern : '';
		const value = postcode
			? postcode.value.trim().replace( /\s+/g, ' ' )
			: '';
		let message = '';

		if ( ! postcode ) {
			return;
		}

		if ( pattern && value ) {
			let valid = true;

			try {
				valid = new RegExp( '^(?:' + pattern + ')$', 'i' ).test(
					value
				);
			} catch {
				valid = true;
			}

			if ( ! valid ) {
				message = t(
					'postcodeInvalid',
					'%1$s is not in the form used here, such as %2$s.'
				)
					.replace(
						'%1$s',
						String( ( layout.labels || {} ).postcode || '' )
					)
					.replace(
						'%2$s',
						String( layout.postcode_example || '' ).split(
							','
						)[ 0 ]
					);
			}
		}

		postcode.setCustomValidity( message );

		if ( message ) {
			postcode.setAttribute( 'aria-invalid', 'true' );
		} else {
			postcode.removeAttribute( 'aria-invalid' );
		}

		Address.hint( wrap, message );
	},

	hint( wrap, message ) {
		const hint = wrap.querySelector( '.field-kit__address-hint' );

		if ( ! hint ) {
			return;
		}

		const postcode = wrap.querySelector(
			'.field-kit__address-input--postcode'
		);

		hint.textContent = message;
		hint.classList.toggle( 'is-error', '' !== message );

		if ( postcode && hint.id ) {
			const ids = ( postcode.getAttribute( 'aria-describedby' ) || '' )
				.split( ' ' )
				.filter( function ( id ) {
					return id && id !== hint.id;
				} );

			if ( message ) {
				ids.push( hint.id );
			}

			if ( ids.length ) {
				postcode.setAttribute( 'aria-describedby', ids.join( ' ' ) );
			} else {
				postcode.removeAttribute( 'aria-describedby' );
			}
		}
	},

	escape( text ) {
		const holder = document.createElement( 'span' );

		holder.textContent = text;

		return holder.innerHTML;
	},
};

export default Address;
