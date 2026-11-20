/**
 * The Remove beside a secret that has something stored.
 *
 * A password box is always drawn empty and an empty one is saved as
 * "unchanged", so emptying it cannot mean "remove". The button says it
 * instead: it enables the hidden flag the field set reads, says in the
 * box what the save will do, and becomes Undo. Nothing is sent until
 * the form is.
 *
 * Typing a new value takes the mark away again. The server would let
 * the typed value win in any case; this keeps the box from promising a
 * removal that will not happen.
 */

const PasswordRemove = {
	/**
	 * Bind every remove button within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '.field-kit__password-remove' ).forEach(
			function ( button ) {
				const wrap = button.closest( '.field-kit__password' );
				const input = wrap
					? wrap.querySelector( 'input:not([type="hidden"])' )
					: null;

				if ( button.dataset.fkBound || ! input ) {
					return;
				}

				button.dataset.fkBound = '1';

				button.addEventListener( 'click', function () {
					PasswordRemove.mark(
						button,
						! wrap.classList.contains( 'is-removing' )
					);

					// The form has changed although no control was typed in.
					input.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				} );

				input.addEventListener( 'input', function () {
					if (
						'' !== input.value &&
						wrap.classList.contains( 'is-removing' )
					) {
						PasswordRemove.mark( button, false );
					}
				} );
			}
		);
	},

	/**
	 * Mark a field for removal, or take the mark away.
	 *
	 * @param {Element} button   The remove button.
	 * @param {boolean} removing Whether the stored value is to go.
	 */
	mark( button, removing ) {
		const wrap = button.closest( '.field-kit__password' );
		const flag = wrap.querySelector( '.field-kit__password-clear' );

		if ( ! flag ) {
			return;
		}

		const input = wrap.querySelector( 'input:not([type="hidden"])' );

		flag.disabled = ! removing;
		wrap.classList.toggle( 'is-removing', removing );

		if ( removing ) {
			// What the box said before, to put back on Undo.
			input.dataset.fkPlaceholder =
				input.getAttribute( 'placeholder' ) || '';
			input.value = '';
			input.setAttribute( 'placeholder', button.dataset.pending || '' );
		} else if ( undefined !== input.dataset.fkPlaceholder ) {
			input.setAttribute( 'placeholder', input.dataset.fkPlaceholder );
			delete input.dataset.fkPlaceholder;
		}

		button.textContent = removing
			? button.dataset.undo
			: button.dataset.remove;
		button.setAttribute(
			'aria-label',
			removing ? button.dataset.undoLabel : button.dataset.removeLabel
		);
	},
};

export default PasswordRemove;
