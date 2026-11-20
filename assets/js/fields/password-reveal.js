/**
 * Password reveal: the eye beside a password that shows what was typed.
 */

import { t } from '../lib/config';
import { ours, swapIcon } from '../lib/dom';

const PasswordReveal = {
	/**
	 * Bind every reveal button within a root.
	 *
	 * The button is rendered hide-if-no-js, so one this never reaches
	 * is one nobody sees rather than one that does nothing.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__password-toggle' ).forEach(
			function ( button ) {
				if ( button.dataset.fkBound ) {
					return;
				}

				button.dataset.fkBound = '1';

				button.addEventListener( 'click', function () {
					PasswordReveal.toggle( button );
				} );
			}
		);
	},

	/**
	 * Show or hide what has been typed.
	 *
	 * The input's type is what does it; everything else -- the icon, the
	 * pressed state, the label -- is the button describing the state it
	 * has put the input in. Mirrors core's own toggle on the profile
	 * screen, which swaps the label along with the icon.
	 *
	 * @param {Element} button The toggle.
	 */
	toggle( button ) {
		const wrap = button.closest( '.field-kit__password' );
		const input = wrap ? wrap.querySelector( 'input' ) : null;

		if ( ! input ) {
			return;
		}

		const glyph = button.querySelector( '.sugarcommerce-icon' );

		const show = 'password' === input.type;

		input.type = show ? 'text' : 'password';

		button.setAttribute( 'data-toggle', show ? '1' : '0' );
		button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
		button.setAttribute(
			'aria-label',
			show
				? t( 'hidePassword', 'Hide password' )
				: t( 'showPassword', 'Show password' )
		);

		swapIcon( glyph, show ? 'hide' : 'show' );
	},
};

export default PasswordReveal;
