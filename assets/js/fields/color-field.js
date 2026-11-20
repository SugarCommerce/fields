/**
 * Colour: the browser's picker and a hex box, kept in step, with named
 * presets and a way back to no colour.
 */

import { ours } from '../lib/dom';

const ColorField = {
	/**
	 * A hex colour as the field stores it, or '' for anything else.
	 *
	 * The server's rule (Color::hex()), so what the box shows after a
	 * paste is what will be saved: `E8A5C4`, `#e8a5c4` and `#E8A5C4`
	 * are all `#e8a5c4`, and `#abc` is `#aabbcc`.
	 *
	 * @param {string} value What was typed, pasted or picked.
	 * @return {string} `#rrggbb`, or ''.
	 */
	normalize( value ) {
		let hex = String( value || '' )
			.trim()
			.replace( /^#/, '' );

		if ( /^[0-9a-f]{3}$/i.test( hex ) ) {
			hex =
				hex.charAt( 0 ) +
				hex.charAt( 0 ) +
				hex.charAt( 1 ) +
				hex.charAt( 1 ) +
				hex.charAt( 2 ) +
				hex.charAt( 2 );
		}

		return /^[0-9a-f]{6}$/i.test( hex ) ? '#' + hex.toLowerCase() : '';
	},

	/**
	 * Bind every native colour field within a root.
	 *
	 * The hex box is the field -- it has the name and is what is
	 * saved -- and the swatch, the clear button and the presets all
	 * write to it. Whatever writes, the box announces an `input` and a
	 * `change`, so conditional fields and a panel's unsaved-changes
	 * check hear a picked colour exactly as they hear a typed one.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__color-field' ).forEach( function ( wrap ) {
			if ( wrap.dataset.fkBound ) {
				return;
			}

			wrap.dataset.fkBound = '1';

			const swatch = wrap.querySelector( '.field-kit__color-swatch' );
			const hex = wrap.querySelector( '.field-kit__color-hex' );

			if ( ! swatch || ! hex ) {
				return;
			}

			const clear = wrap.querySelector( '.field-kit__color-clear' );
			const presets = wrap.querySelectorAll( '.field-kit__color-preset' );

			/**
			 * Show a colour everywhere but the box.
			 *
			 * @param {string} value `#rrggbb`, or '' for none.
			 */
			function paint( value ) {
				wrap.dataset.empty = value ? 'false' : 'true';

				if ( value ) {
					swatch.value = value;
				}

				if ( clear ) {
					clear.hidden = ! value;
				}

				presets.forEach( function ( preset ) {
					preset.setAttribute(
						'aria-pressed',
						preset.dataset.color === value ? 'true' : 'false'
					);
				} );
			}

			/**
			 * Put a colour in the box, and say so.
			 *
			 * @param {string} value `#rrggbb`, or '' for none.
			 */
			function set( value ) {
				hex.value = value;
				paint( value );
				hex.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				hex.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}

			// While the picker is open the swatch fires `input` as the
			// colour moves, and the box follows it.
			swatch.addEventListener( 'input', function () {
				set( ColorField.normalize( swatch.value ) );
			} );

			// Typing: the swatch follows as soon as the box holds a
			// colour, and the box is left alone until it is done with --
			// rewriting "#E8A" to "#ee88aa" mid-word would fight the
			// person typing it.
			hex.addEventListener( 'input', function () {
				const value = ColorField.normalize( hex.value );

				if ( value || '' === hex.value.trim() ) {
					paint( value );
				}
			} );

			// Done with: tidied into the form it will be saved in.
			hex.addEventListener( 'change', function () {
				const value = ColorField.normalize( hex.value );

				if ( value && value !== hex.value ) {
					hex.value = value;
				}

				if ( value || '' === hex.value.trim() ) {
					paint( value );
				}
			} );

			if ( clear ) {
				clear.addEventListener( 'click', function () {
					set( '' );

					// The button hides itself; focus goes where the next
					// colour would be typed rather than to the page.
					hex.focus();
				} );
			}

			presets.forEach( function ( preset ) {
				preset.addEventListener( 'click', function () {
					set( ColorField.normalize( preset.dataset.color ) );
				} );
			} );
		} );
	},
};

export default ColorField;
