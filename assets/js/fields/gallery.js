/**
 * Gallery: choosing images from the media library, removing them, and
 * keeping the hidden value in step. Reordering is Reorder's.
 */

import { t } from '../lib/config';
import { announce, ours } from '../lib/dom';
import Reorder from './reorder';

const Gallery = {
	/**
	 * Bind every gallery within a root.
	 *
	 * Reordering is Reorder's, which knows the grid; this is choosing,
	 * removing and keeping the hidden value in step.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__gallery' ).forEach( function ( wrap ) {
			if ( wrap.dataset.fkBound ) {
				return;
			}

			wrap.dataset.fkBound = '1';

			wrap.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '.field-kit__gallery-add' ) ) {
					event.preventDefault();
					Gallery.open( wrap );
				}

				const remove = event.target.closest(
					'.field-kit__gallery-remove'
				);

				if ( remove ) {
					event.preventDefault();
					Gallery.remove(
						wrap,
						remove.closest( '.field-kit__gallery-item' )
					);
				}
			} );
		} );
	},

	/**
	 * Open the media library, adding to what is chosen.
	 *
	 * @param {Element} wrap The gallery.
	 */
	open( wrap ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		const frame = window.wp.media( {
			title: wrap.dataset.frameTitle,
			library: { type: wrap.dataset.mimeType || 'image' },
			multiple: 'add',
			button: { text: wrap.dataset.chooseLabel },
		} );

		frame.on( 'select', function () {
			const list = wrap.querySelector( '.field-kit__gallery-items' );
			let added = 0;

			frame
				.state()
				.get( 'selection' )
				.forEach( function ( attachment ) {
					added += Gallery.append( wrap, attachment.toJSON() )
						? 1
						: 0;
				} );

			Reorder.sync( list );

			if ( added ) {
				announce(
					wrap.querySelector( '.field-kit__gallery-status' ),
					t( 'imagesAdded', '%d added.' ).replace(
						'%d',
						String( added )
					)
				);
			}
		} );

		frame.open();
	},

	/**
	 * Add one picture, from the tile the server drew to clone.
	 *
	 * @param {Element} wrap       The gallery.
	 * @param {Object}  attachment The attachment, as the media library has it.
	 * @return {boolean} Whether it was added.
	 */
	append( wrap, attachment ) {
		const list = wrap.querySelector( '.field-kit__gallery-items' );
		const template = wrap.querySelector( '.field-kit__gallery-template' );

		if ( ! list || ! template || ! attachment || ! attachment.id ) {
			return false;
		}

		if (
			list.querySelector(
				'[data-id="' +
					window.CSS.escape( String( attachment.id ) ) +
					'"]'
			)
		) {
			return false;
		}

		// The frame takes several at once, so the limit is checked as
		// each arrives rather than once before it opened.
		const max = parseInt( wrap.dataset.max || '0', 10 );

		if ( max > 0 && list.children.length >= max ) {
			return false;
		}

		const item = template.content.firstElementChild.cloneNode( true );
		const sizes = attachment.sizes || {};
		const thumb = sizes.medium || sizes.thumbnail || {};
		const image = item.querySelector( 'img' );
		const name =
			attachment.title || attachment.filename || String( attachment.id );
		const alt = String( attachment.alt || '' ).trim();

		item.dataset.id = String( attachment.id );

		// Built, not written: the address and the alt text are whatever
		// the library holds, and markup is where they must not go.
		if ( image ) {
			image.src = thumb.url || attachment.url;
			image.alt = alt;
		}

		const missing = item.querySelector( '.field-kit__gallery-alt' );

		if ( missing ) {
			missing.hidden = '' !== alt;
		}

		item.querySelectorAll( '[aria-label], [data-label-template]' ).forEach(
			function ( button ) {
				[ 'aria-label', 'data-label-template' ].forEach(
					function ( attribute ) {
						const value = button.getAttribute( attribute );

						if ( null !== value ) {
							button.setAttribute(
								attribute,
								value.split( '{name}' ).join( name )
							);
						}
					}
				);
			}
		);

		list.appendChild( item );

		return true;
	},

	/**
	 * Take one picture out.
	 *
	 * @param {Element} wrap The gallery.
	 * @param {Element} item The tile.
	 */
	remove( wrap, item ) {
		if ( ! item ) {
			return;
		}

		const list = item.parentNode;
		const next = item.nextElementSibling || item.previousElementSibling;

		item.remove();
		Reorder.sync( list );

		// Focus would land on the body otherwise, which loses a
		// keyboard user's place in the grid.
		const target = next
			? next.querySelector( '.field-kit__gallery-remove' )
			: wrap.querySelector( '.field-kit__gallery-add' );

		if ( target ) {
			target.focus();
		}

		announce(
			wrap.querySelector( '.field-kit__gallery-status' ),
			t( 'imageRemoved', 'Image removed.' )
		);
	},

	/**
	 * Write the order back to the hidden input, and settle the button.
	 *
	 * Called by Reorder after every move, add and removal.
	 *
	 * @param {Element} list The grid.
	 */
	sync( list ) {
		const wrap = list.closest( '.field-kit__gallery' );

		if ( ! wrap ) {
			return;
		}

		const input = wrap.querySelector( '.field-kit__media-value' );
		const items = Array.prototype.slice.call(
			list.querySelectorAll( '.field-kit__gallery-item' )
		);
		const value = items
			.map( function ( item ) {
				return item.dataset.id;
			} )
			.join( ',' );

		if ( input && input.value !== value ) {
			input.value = value;
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		list.dataset.empty = items.length ? 'false' : 'true';

		// A full gallery says so by turning its button off, rather than
		// by opening the library and quietly dropping the choice.
		const max = parseInt( wrap.dataset.max || '0', 10 );
		const add = wrap.querySelector( '.field-kit__gallery-add' );

		if ( add ) {
			add.disabled = max > 0 && items.length >= max;
		}
	},
};

export default Gallery;
