/**
 * Toggle: a switch's aria-checked, kept in step with its checkbox.
 */

import { bound, mine } from '../lib/dom';

const Toggle = {
	/**
	 * Keep aria-checked in step with the underlying checkbox.
	 *
	 * role="switch" makes the state aria-checked rather than the native
	 * checked state, so it has to be maintained or the control announces
	 * the wrong thing after every interaction.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		if ( bound( root, 'Toggle' ) ) {
			return;
		}

		root.addEventListener( 'change', function ( event ) {
			const toggle = event.target.closest( '.field-kit__toggle' );

			if ( toggle && mine( toggle ) ) {
				toggle.setAttribute(
					'aria-checked',
					toggle.checked ? 'true' : 'false'
				);
			}
		} );
	},
};

export default Toggle;
