/**
 * Reorderable lists and repeaters.
 *
 * Dragging is added on top of move buttons that already work, never instead
 * of them. Every reorder goes through the same move() call, so the keyboard
 * path and the drag path cannot drift apart -- and the keyboard path is the
 * one that gets exercised by everyone, because the buttons are always there.
 */

import { t } from '../lib/config';
import { kit } from '../lib/kit';
import Combobox from './combobox';
import { ours } from '../lib/dom';

const Reorder = {
	/**
	 * Bind every reorderable list within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours(
			root,
			'.field-kit__sortable, .field-kit__repeater-rows, .field-kit__gallery-items, .field-kit__combobox-chips[data-sortable]'
		).forEach( function ( list ) {
			if ( list.dataset.fkBound ) {
				return;
			}

			list.dataset.fkBound = '1';
			Reorder.bindButtons( list );
			Reorder.bindDrag( list );
		} );
	},

	/**
	 * The item selector for a given list.
	 *
	 * @param {Element} list The list.
	 * @return {string} Selector.
	 */
	itemSelector( list ) {
		if ( list.classList.contains( 'field-kit__sortable' ) ) {
			return '.field-kit__sortable-item';
		}

		if ( list.classList.contains( 'field-kit__gallery-items' ) ) {
			return '.field-kit__gallery-item';
		}

		if ( list.classList.contains( 'field-kit__combobox-chips' ) ) {
			return '.field-kit__combobox-chip';
		}

		return '.field-kit__repeater-row';
	},

	/**
	 * Whether the item's neighbours sit beside it rather than under it.
	 *
	 * Measured rather than configured, so a grid that wraps to a single
	 * column on a narrow screen answers vertically without being told —
	 * and a repeater whose `direction` was changed does too.
	 *
	 * @param {Element} item An item in the list.
	 * @return {boolean} Whether the list runs across at this point.
	 */
	isHorizontal( item ) {
		const neighbour =
			item.nextElementSibling || item.previousElementSibling;

		if ( ! neighbour ) {
			return false;
		}

		// Four pixels of slack: two elements in a row rarely share a top
		// to the pixel once borders and margins are involved.
		return (
			Math.abs(
				neighbour.getBoundingClientRect().top -
					item.getBoundingClientRect().top
			) < 4
		);
	},

	/**
	 * Wire the move buttons.
	 *
	 * @param {Element} list The list.
	 */
	bindButtons( list ) {
		list.addEventListener( 'click', function ( event ) {
			const button = event.target.closest(
				'[data-direction], [data-action="move-up"], [data-action="move-down"]'
			);

			if ( ! button ) {
				return;
			}

			event.preventDefault();

			const direction =
				button.dataset.direction ||
				( button.dataset.action === 'move-up' ? 'up' : 'down' );
			const item = button.closest( Reorder.itemSelector( list ) );

			if ( ! Reorder.owns( list, item ) ) {
				return;
			}

			Reorder.move( list, item, direction );

			// Focus would otherwise be lost when the button it sits on is
			// re-rendered or becomes disabled at the end of the list.
			Reorder.restoreFocus( list, item, direction );
		} );

		// A handle that is only draggable cannot be operated from a
		// keyboard at all, so a list whose only reorder control is a
		// handle would be unorderable without a pointer. Only the button
		// form takes part: the older lists draw the handle as a
		// decorative span and keep their own move buttons.
		list.addEventListener( 'keydown', function ( event ) {
			// Left and right as well as up and down: a gallery is a
			// grid, and its neighbours are beside a tile as often as
			// above it.
			const backward =
				'ArrowUp' === event.key || 'ArrowLeft' === event.key;

			if (
				! backward &&
				'ArrowDown' !== event.key &&
				'ArrowRight' !== event.key
			) {
				return;
			}

			const handle = event.target.closest(
				'button.field-kit__drag-handle'
			);

			if ( ! handle ) {
				return;
			}

			const item = handle.closest( Reorder.itemSelector( list ) );

			if ( ! Reorder.owns( list, item ) ) {
				return;
			}

			// Or the page scrolls under the list while the row moves.
			event.preventDefault();

			Reorder.move( list, item, backward ? 'up' : 'down' );

			// Focus stays on the handle, which has moved with its row --
			// so the arrow keys keep working and the new position is
			// announced by the name sync() just rewrote.
			handle.focus();
		} );
	},

	/**
	 * Whether an item is one of this list's own.
	 *
	 * A row can hold a list of its own -- a price and its files -- and
	 * everything that happens in the inner one bubbles through the outer
	 * one too. The nearest item to whatever was pressed is then the
	 * inner list's, and the outer list moving it is an insertBefore()
	 * on a node that is not its child: an exception on every arrow key
	 * and every drag in a nested list.
	 *
	 * @param {Element}      list The list.
	 * @param {Element|null} item What was found.
	 * @return {boolean} Whether the list holds it directly.
	 */
	owns( list, item ) {
		return !! item && item.parentNode === list;
	},

	/**
	 * Move one item.
	 *
	 * @param {Element} list      The list.
	 * @param {Element} item      The item.
	 * @param {string}  direction Either "up" or "down".
	 */
	move( list, item, direction ) {
		const sibling =
			direction === 'up'
				? item.previousElementSibling
				: item.nextElementSibling;

		if ( ! sibling ) {
			return;
		}

		if ( direction === 'up' ) {
			list.insertBefore( item, sibling );
		} else {
			list.insertBefore( sibling, item );
		}

		Reorder.sync( list );
	},

	/**
	 * Put focus back on the equivalent control after a move.
	 *
	 * @param {Element} list      The list.
	 * @param {Element} item      The moved item.
	 * @param {string}  direction The direction moved.
	 */
	restoreFocus( list, item, direction ) {
		const selector =
			'[data-direction="' +
			direction +
			'"], [data-action="move-' +
			direction +
			'"]';
		const button = item.querySelector( selector );

		if ( button && ! button.disabled ) {
			button.focus();

			return;
		}

		// At the end of the list that direction is gone, so focus moves to
		// the opposite control rather than being dropped onto the body.
		const opposite = direction === 'up' ? 'down' : 'up';
		const fallback = item.querySelector(
			'[data-direction="' +
				opposite +
				'"], [data-action="move-' +
				opposite +
				'"]'
		);

		if ( fallback ) {
			fallback.focus();
		}
	},

	/**
	 * Update everything that depends on order.
	 *
	 * @param {Element} list The list.
	 */
	sync( list ) {
		const items = list.querySelectorAll( Reorder.itemSelector( list ) );
		const total = items.length;

		items.forEach( function ( item, index ) {
			// Disable the moves that are no longer possible, so a keyboard
			// user is not sent to a control that does nothing.
			const up = item.querySelector(
				'[data-direction="up"], [data-action="move-up"]'
			);
			const down = item.querySelector(
				'[data-direction="down"], [data-action="move-down"]'
			);

			if ( up ) {
				up.disabled = index === 0;
			}

			if ( down ) {
				down.disabled = index === total - 1;
			}

			const position = item.querySelector(
				'.field-kit__repeater-position'
			);

			if ( position ) {
				position.textContent =
					t( 'position', 'Item' ) +
					' ' +
					( index + 1 ) +
					' / ' +
					total;
			}

			// A handle that is the only reorder control has to say where
			// the row landed, or a keyboard user gets no feedback that
			// anything happened. The wording is PHP's, kept as a template
			// so there is one translated string rather than two.
			const handle = item.querySelector(
				'button.field-kit__drag-handle[data-label-template]'
			);

			if ( handle ) {
				handle.setAttribute(
					'aria-label',
					handle.dataset.labelTemplate
						.replace( '{position}', String( index + 1 ) )
						.replace( '{total}', String( total ) )
				);
			}

			item.dataset.index = String( index );
		} );

		if ( list.classList.contains( 'field-kit__combobox-chips' ) ) {
			Combobox.syncOrder( list );
		}

		if (
			list.classList.contains( 'field-kit__gallery-items' ) &&
			kit.Gallery
		) {
			kit.Gallery.sync( list );
		}

		if ( list.classList.contains( 'field-kit__repeater-rows' ) ) {
			// Looked up rather than closed over: Repeater is defined in a
			// later block, so a direct reference would be a ReferenceError
			// the first time a sortable is reordered.
			const repeater = kit.Repeater;

			if ( repeater ) {
				repeater.reindex( list );
			}
		}

		list.dataset.empty = total ? 'false' : 'true';
	},

	/**
	 * Add dragging on top of the buttons.
	 *
	 * @param {Element} list The list.
	 */
	bindDrag( list ) {
		const selector = Reorder.itemSelector( list );
		let dragging = null;

		list.addEventListener( 'pointerdown', function ( event ) {
			// Never start a drag from a control: it would swallow the
			// click that operates it. The drag handle is the exception --
			// it is a button so that it can be focused and reordered from
			// the keyboard, and excluding it along with the rest meant the
			// one control drawn as a grab handle was the one control that
			// could not be grabbed.
			if (
				event.target.closest( 'button, input, select, textarea, a' ) &&
				! event.target.closest( 'button.field-kit__drag-handle' )
			) {
				return;
			}

			const item = event.target.closest( selector );

			if ( Reorder.owns( list, item ) ) {
				item.draggable = true;
			}
		} );

		// A drag that ends anywhere but on the list still has to put the
		// item down, or it stays marked draggable and the next pointerdown
		// on a control starts a drag instead of clicking it.
		list.addEventListener( 'pointerup', function () {
			list.querySelectorAll( '[draggable="true"]' ).forEach(
				function ( item ) {
					item.draggable = false;
				}
			);
		} );

		list.addEventListener( 'dragstart', function ( event ) {
			dragging = event.target.closest( selector );

			// A row of a list nested in one of this list's rows is that
			// list's to move.
			if ( ! Reorder.owns( list, dragging ) ) {
				dragging = null;
			}

			if ( dragging ) {
				dragging.classList.add( 'is-dragging' );
				event.dataTransfer.effectAllowed = 'move';
			}
		} );

		list.addEventListener( 'dragover', function ( event ) {
			if ( ! dragging ) {
				return;
			}

			event.preventDefault();

			const over = event.target.closest( selector );

			if ( ! Reorder.owns( list, over ) || over === dragging ) {
				return;
			}

			// Past the halfway line of the item under the pointer, the
			// dragged one belongs after it rather than before — but which
			// halfway line depends on how the list is actually laid out.
			//
			// Chips in a row sit side by side, so the question is
			// left-or-right. Asking top-or-bottom there means two items in
			// the same row give the same answer over most of their area,
			// which is why one could not be dropped into the first
			// position at all.
			const box = over.getBoundingClientRect();

			const past = Reorder.isHorizontal( over )
				? ( event.clientX - box.left ) / box.width > 0.5
				: ( event.clientY - box.top ) / box.height > 0.5;

			const reference = past ? over.nextElementSibling : over;

			// Already exactly there. dragover fires continuously — many
			// times per second — and the list has been reordered
			// underneath the pointer since the last one, so without this
			// a single crossing keeps re-inserting and the item travels
			// two or three places at once instead of one.
			if (
				reference === dragging ||
				dragging.nextElementSibling === reference
			) {
				return;
			}

			list.insertBefore( dragging, reference );
		} );

		[ 'dragend', 'drop' ].forEach( function ( type ) {
			list.addEventListener( type, function ( event ) {
				event.preventDefault();

				if ( dragging ) {
					dragging.classList.remove( 'is-dragging' );
					dragging.draggable = false;
					dragging = null;
					Reorder.sync( list );
				}
			} );
		} );
	},
};

export default Reorder;
