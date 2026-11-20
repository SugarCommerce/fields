/**
 * Conditional logic: a field shown only while the fields its
 * `data-conditions` names hold what they say.
 */

import { bound } from '../lib/dom';

const Conditions = {
	/**
	 * Bind every conditional field within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '[data-conditions]' ).forEach(
			function ( field ) {
				Conditions.evaluate( field );
			}
		);

		if ( bound( root, 'Conditions' ) ) {
			return;
		}

		// One delegated listener rather than one per watched input: the
		// watched field may not exist yet inside a repeater row. And the
		// fields are looked up on each event rather than held from init:
		// a list taken then would miss every row added since and keep
		// every row removed since alive.
		//
		// field-kit:change is the kit's own signal, used where firing a
		// native change would be read by the control that wrote the value
		// as a fresh edit. See ColorPicker.
		[ 'change', 'input', 'field-kit:change' ].forEach( function ( type ) {
			root.addEventListener(
				type,
				function () {
					root.querySelectorAll( '[data-conditions]' ).forEach(
						function ( field ) {
							Conditions.evaluate( field );
						}
					);
				},
				true
			);
		} );
	},

	/**
	 * Show or hide one field.
	 *
	 * @param {Element} field Field wrapper.
	 */
	evaluate( field ) {
		let conditions;

		try {
			conditions = JSON.parse( field.getAttribute( 'data-conditions' ) );
		} catch {
			return;
		}

		// Always a list. A map has no forEach, which is exactly how the
		// predecessor died when config was passed through unnormalised.
		if ( ! Array.isArray( conditions ) ) {
			return;
		}

		const met = conditions.every( function ( condition ) {
			return Conditions.matches(
				condition,
				Conditions.valueOf( field, condition.field )
			);
		} );

		// On a settings, term or user screen a field is one cell of a
		// table row, and hiding the cell's contents leaves the row's
		// header behind — the label of a field that is not there. The row
		// is only taken when this wrapper is the row's own field: a
		// conditional field nested inside a repeater must not take the
		// whole repeater's row with it.
		const row = field.closest( 'tr' );
		const target =
			row && row.querySelector( '.field-kit__field' ) === field
				? row
				: field;

		target.hidden = ! met;

		// Hidden inputs are still submitted, and a disabled one is not —
		// the server deletes a field whose conditions fail, but not
		// submitting it in the first place keeps the two agreeing.
		//
		// A region field is two controls of one name, and the one
		// standing down is hidden and disabled so that only the other
		// posts. Showing the field must not switch it back on. An
		// address is the same twice over: its region, and the parts its
		// country has no place for, kept hidden for the next country.
		field
			.querySelectorAll( 'input, select, textarea' )
			.forEach( function ( input ) {
				const address = input.closest( '.field-kit__address' );
				const standing =
					address &&
					( input.hidden ||
						( input.parentElement &&
							input.parentElement.closest( '[hidden]' ) &&
							address.contains(
								input.parentElement.closest( '[hidden]' )
							) ) );

				input.disabled =
					! met ||
					( input.hidden &&
						!! input.closest( '.field-kit__region' ) ) ||
					!! standing;
			} );
	},

	/**
	 * Read the current value of a watched field.
	 *
	 * @param {Element} field Field wrapper doing the watching.
	 * @param {string}  key   Watched field key.
	 * @return {*} Current value.
	 */
	valueOf( field, key ) {
		const scope =
			field.closest( '.field-kit__repeater-row' ) || field.ownerDocument;
		const target = scope.querySelector(
			'[data-field-key="' + window.CSS.escape( key ) + '"]'
		);

		if ( ! target ) {
			return null;
		}

		const checked = target.querySelectorAll(
			'input[type="checkbox"]:checked, input[type="radio"]:checked'
		);

		if ( checked.length ) {
			return checked.length === 1
				? checked[ 0 ].value
				: Array.from( checked ).map( function ( i ) {
						return i.value;
					} );
		}

		if (
			target.querySelector(
				'input[type="checkbox"], input[type="radio"]'
			)
		) {
			return '';
		}

		// Not the box of a combobox. An enhanced select is drawn as a
		// text box to search in with the real select behind it, and
		// the box comes first: it holds "Germany" where the select
		// holds DE, and a condition is written against the value.
		const input = target.querySelector(
			'input:not(.field-kit__combobox-input), select, textarea'
		);

		return input ? input.value : null;
	},

	/**
	 * Evaluate one condition.
	 *
	 * Comparisons are loose for the same reason they are on the server:
	 * a value read off a form is a string and config is written in PHP,
	 * so "1" has to equal 1.
	 *
	 * @param {Object} condition Condition.
	 * @param {*}      current   Current value.
	 * @return {boolean} Whether it is met.
	 */
	matches( condition, current ) {
		const expected = condition.value;

		switch ( condition.operator ) {
			case '!=':
				return current != expected; // eslint-disable-line eqeqeq
			case '>':
				return parseFloat( current ) > parseFloat( expected );
			case '>=':
				return parseFloat( current ) >= parseFloat( expected );
			case '<':
				return parseFloat( current ) < parseFloat( expected );
			case '<=':
				return parseFloat( current ) <= parseFloat( expected );
			case 'in':
				return [].concat( expected ).some( function ( one ) {
					return one == current; // eslint-disable-line eqeqeq
				} );
			case 'not_in':
				return ! [].concat( expected ).some( function ( one ) {
					return one == current; // eslint-disable-line eqeqeq
				} );
			case 'contains':
				return String( current ).indexOf( String( expected ) ) !== -1;
			case 'not_contains':
				return String( current ).indexOf( String( expected ) ) === -1;
			case 'empty':
				return ! current || ! String( current ).length;
			case 'not_empty':
				return !! current && !! String( current ).length;
			default:
				return current == expected; // eslint-disable-line eqeqeq
		}
	},
};

export default Conditions;
