/**
 * Tooltips: a field's help, opened from the button beside its label and
 * kept on screen.
 */

const Tooltip = {
	/**
	 * Bind every tooltip within a root.
	 *
	 * The panel is already in the markup and already hidden, so a tooltip
	 * that this never reaches is a button that does nothing rather than
	 * an explanation that does not exist — the panel is still associated
	 * with the button through aria-describedby, so it is still announced.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '.field-kit__tooltip' ).forEach(
			function ( wrap ) {
				if ( wrap.dataset.fkBound ) {
					return;
				}

				wrap.dataset.fkBound = '1';

				const button = wrap.querySelector(
					'.field-kit__tooltip-toggle'
				);
				const panel = wrap.querySelector( '.field-kit__tooltip-panel' );

				if ( ! button || ! panel ) {
					return;
				}

				function open() {
					panel.hidden = false;
					button.setAttribute( 'aria-expanded', 'true' );
					Tooltip.place( panel, button );
				}

				function close() {
					panel.hidden = true;
					button.setAttribute( 'aria-expanded', 'false' );
				}

				// Hover and focus both, because a tooltip that only opens on
				// hover is one that does not exist for anyone using a
				// keyboard — which is the failing this component was written
				// to avoid.
				wrap.addEventListener( 'mouseenter', open );
				wrap.addEventListener( 'mouseleave', close );
				button.addEventListener( 'focus', open );
				button.addEventListener( 'blur', close );

				// Click as well, for touch, where there is no hover at all.
				button.addEventListener( 'click', function ( event ) {
					event.preventDefault();

					if ( panel.hidden ) {
						open();
					} else {
						close();
					}
				} );

				button.addEventListener( 'keydown', function ( event ) {
					if ( 'Escape' === event.key && ! panel.hidden ) {
						event.stopPropagation();
						close();
					}
				} );
			}
		);
	},

	/**
	 * The left and right edges a panel has to stay inside.
	 *
	 * @param {Element} panel The panel.
	 * @return {{left: number, right: number}} Viewport coordinates.
	 */
	edges( panel ) {
		let left = 0;
		let right = window.innerWidth || document.documentElement.clientWidth;
		let node = panel.parentElement;

		while ( node && node !== document.body ) {
			const style = window.getComputedStyle( node );

			if (
				/(auto|scroll|hidden|clip)/.test(
					style.overflowX + style.overflowY
				)
			) {
				const rect = node.getBoundingClientRect();

				left = Math.max( left, rect.left );
				right = Math.min( right, rect.right );
			}

			node = node.parentElement;
		}

		return { left, right };
	},

	/**
	 * Keep the panel on the screen.
	 *
	 * It is centred on its icon, which is wrong twice: a tooltip on the
	 * first field of a metabox opens off the top of the scrolled area,
	 * and one on a label in the left-hand column of a settings table
	 * hangs half its width off the left of the window — far enough that
	 * the first words are behind the admin menu.
	 *
	 * So it flips below when there is no room above, and slides sideways
	 * when there is none beside. The arrow is moved back onto the icon by
	 * the same distance, because an arrow that no longer points at
	 * anything is worse than no arrow.
	 *
	 * @param {Element} panel  The panel.
	 * @param {Element} button The icon it belongs to.
	 */
	place( panel, button ) {
		const gap = 8;

		// Measured from a clean slate: this runs again every time the
		// panel opens, and a shift left over from the last position would
		// be measured as though it were the natural one.
		delete panel.dataset.position;
		panel.style.transform = '';
		panel.style.removeProperty( '--fk-tooltip-arrow' );

		const box = panel.getBoundingClientRect();

		if ( box.top < gap ) {
			panel.dataset.position = 'below';
		}

		// The window, or whatever is nearer than the window and cuts off
		// what leaves it: a slide-in panel scrolls its own body, so a
		// tooltip near its left edge was still inside the window and lost
		// its first words to the panel's edge.
		const edges = this.edges( panel );
		const room = edges.right - gap;
		let shift = 0;

		if ( box.left < edges.left + gap ) {
			shift = edges.left + gap - box.left;
		} else if ( box.right > room ) {
			shift = room - box.right;
		}

		if ( 0 === shift ) {
			return;
		}

		panel.style.transform =
			'translateX(calc(-50% + ' + Math.round( shift ) + 'px))';

		// The arrow follows the icon rather than the panel. Clamped
		// inside the panel's own corners, so it stays a pointer rather
		// than becoming a notch in the edge.
		const icon = button.getBoundingClientRect();
		const centre = icon.left + icon.width / 2 - ( box.left + shift );

		panel.style.setProperty(
			'--fk-tooltip-arrow',
			Math.round( Math.min( Math.max( centre, 12 ), box.width - 12 ) ) +
				'px'
		);
	},
};

export default Tooltip;
