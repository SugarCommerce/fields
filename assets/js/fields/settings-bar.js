/**
 * The bar a settings page's save button rides in.
 *
 * wp-register-setting-fields draws "Saved" in it on the load that
 * follows a clean save. That is true until something on the page
 * changes, and from then on it is describing a form that no longer
 * exists -- so the first edit replaces it with "Unsaved changes", which
 * is true from then until the form is sent. The same element, and a
 * status region: the change of words is what is announced.
 *
 * The bar is stuck to the foot of the window while there is more page
 * under it and is the last thing on the page once there is not. Which
 * of the two it is cannot be asked of CSS, so a one-pixel sentinel
 * after the bar is watched: in view, the bar has come to rest and is
 * drawn as the last line of the form (`is-resting`); out of view, it
 * is floating over content and is the docked bar the stylesheet draws
 * by default.
 *
 * A collapsed card also opens when a required control inside it stops
 * the form being sent. The browser reports that against the control,
 * and a control in a closed <details> cannot be focused or shown a
 * message, so without this the Save button would appear to do nothing.
 */

import { t } from '../lib/config';
import { ours } from '../lib/dom';

const SettingsBar = {
	init( root ) {
		// On a phone the tabs are one row that scrolls sideways, and
		// the tab being shown may be off the end of it.
		ours( root, '.field-kit__settings-sidebar-link--current' ).forEach(
			function ( link ) {
				const list = link.closest(
					'.field-kit__settings-sidebar-list'
				);

				if ( list && list.scrollWidth > list.clientWidth ) {
					list.scrollLeft =
						link.offsetLeft -
						list.offsetLeft -
						( list.clientWidth - link.offsetWidth ) / 2;
				}
			}
		);

		ours( root, '.field-kit__settings--modern form' ).forEach(
			function ( form ) {
				if ( form.dataset.fkBarBound ) {
					return;
				}

				const status = form.querySelector(
					'.field-kit__settings-status'
				);

				form.dataset.fkBarBound = '1';

				function changed() {
					if (
						status &&
						! status.classList.contains( 'is-unsaved' )
					) {
						status.classList.add( 'is-unsaved' );
						status.textContent = t(
							'unsavedChanges',
							'Unsaved changes'
						);
					}
				}

				form.addEventListener( 'input', changed );
				form.addEventListener( 'change', changed );

				SettingsBar.watch(
					form.querySelector( '.field-kit__settings-savebar' )
				);

				// Capturing: `invalid` does not bubble.
				form.addEventListener(
					'invalid',
					function ( event ) {
						const card = event.target.closest(
							'details.field-kit__settings-card'
						);

						if ( card ) {
							card.open = true;
						}
					},
					true
				);
			}
		);
	},

	/**
	 * Say on the bar whether it is floating over the page or at rest.
	 *
	 * @param {Element|null} bar The save bar.
	 */
	watch( bar ) {
		if ( ! bar || ! window.IntersectionObserver ) {
			return;
		}

		const sentinel = document.createElement( 'span' );

		sentinel.className = 'field-kit__settings-savebar-end';
		sentinel.setAttribute( 'aria-hidden', 'true' );
		bar.insertAdjacentElement( 'afterend', sentinel );

		new window.IntersectionObserver( function ( entries ) {
			bar.classList.toggle( 'is-resting', entries[ 0 ].isIntersecting );
		} ).observe( sentinel );
	},
};

export default SettingsBar;
