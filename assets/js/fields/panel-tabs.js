/**
 * Panel tabs: the tab markers' tab list, from the keyboard as well.
 */

import { ours } from '../lib/dom';

const PanelTabs = {
	/**
	 * Bind every tab list within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__panel-tabs' ).forEach( function ( wrap ) {
			if ( wrap.dataset.fkBound ) {
				return;
			}

			wrap.dataset.fkBound = '1';
			PanelTabs.bind( wrap );
		} );
	},

	/**
	 * Wire one tab list.
	 *
	 * @param {Element} wrap The tabbed panel.
	 */
	bind( wrap ) {
		const tabs = Array.prototype.slice.call(
			wrap.querySelectorAll( '.field-kit__panel-tab' )
		);

		if ( ! tabs.length ) {
			return;
		}

		/**
		 * Show one tab's panel and hide the rest.
		 *
		 * @param {number}  index     Which tab.
		 * @param {boolean} moveFocus Whether to focus it.
		 */
		function select( index, moveFocus ) {
			tabs.forEach( function ( tab, i ) {
				const chosen = i === index;
				const panel = wrap.querySelector(
					'#' +
						window.CSS.escape( tab.getAttribute( 'aria-controls' ) )
				);

				tab.setAttribute( 'aria-selected', chosen ? 'true' : 'false' );

				// Only the selected tab is in the tab order: arrow keys
				// move between them, and tabbing leaves the list.
				tab.tabIndex = chosen ? 0 : -1;
				tab.classList.toggle( 'is-active', chosen );

				if ( panel ) {
					panel.hidden = ! chosen;
				}
			} );

			if ( moveFocus ) {
				tabs[ index ].focus();
			}
		}

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function () {
				select( index, false );
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				const last = tabs.length - 1;

				switch ( event.key ) {
					// Vertical list, so up and down are the ones that
					// matter — but left and right are bound too, because
					// a tab list is a tab list whichever way it is drawn.
					case 'ArrowDown':
					case 'ArrowRight':
						event.preventDefault();
						select( index === last ? 0 : index + 1, true );
						break;
					case 'ArrowUp':
					case 'ArrowLeft':
						event.preventDefault();
						select( index === 0 ? last : index - 1, true );
						break;
					case 'Home':
						event.preventDefault();
						select( 0, true );
						break;
					case 'End':
						event.preventDefault();
						select( last, true );
						break;
					default:
						break;
				}
			} );
		} );
	},
};

export default PanelTabs;
