/**
 * Copying a field's value, with a button beside it.
 */

import { t } from '../lib/config';
import { announce, bound, mine } from '../lib/dom';

const Clipboard = {
	/**
	 * Copy a value and say whether it worked.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		if ( bound( root, 'Clipboard' ) ) {
			return;
		}

		root.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '.field-kit__clipboard-copy' );

			// Another plugin's copy of the kit copies its own.
			if ( ! button || ! mine( button ) ) {
				return;
			}

			const wrap = button.closest( '.field-kit__clipboard' );
			const input = wrap.querySelector( '.field-kit__clipboard-value' );
			const status = wrap.querySelector( '.field-kit__clipboard-status' );
			const success = wrap.querySelector(
				'.field-kit__clipboard-success'
			);

			Clipboard.copy( input.value ).then( function ( ok ) {
				announce(
					status,
					ok
						? t( 'copied', 'Copied to the clipboard.' )
						: t( 'copyFailed', 'Could not copy.' )
				);

				if ( ! success || ! ok ) {
					return;
				}

				// Shown, then hidden again after a moment — core does the
				// same in the media modal. aria-hidden stays on it: the
				// live region above is what announces this, and having
				// both would say it twice.
				success.classList.remove( 'hidden' );

				window.clearTimeout( success.fkTimer );

				success.fkTimer = window.setTimeout( function () {
					success.classList.add( 'hidden' );
				}, 3000 );
			} );
		} );
	},

	/**
	 * Copy text, falling back where the async API is unavailable.
	 *
	 * navigator.clipboard needs a secure context, which a local admin
	 * over plain http is not.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise<boolean>} Whether it worked.
	 */
	copy( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard
				.writeText( text )
				.then( function () {
					return true;
				} )
				.catch( function () {
					return false;
				} );
		}

		const area = document.createElement( 'textarea' );
		area.value = text;
		area.setAttribute( 'readonly', '' );
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();

		let ok = false;

		try {
			ok = document.execCommand( 'copy' );
		} catch {
			ok = false;
		}

		document.body.removeChild( area );

		return Promise.resolve( ok );
	},
};

export default Clipboard;
