/**
 * Merge tag chooser: a searchable list of an email's tags, put in where the
 * author was typing.
 */

import { ours } from '../lib/dom';

const TagModal = {
	/**
	 * Bind every Add Tag button within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__tag-button' ).forEach( function ( button ) {
			if ( button.dataset.fkBound ) {
				return;
			}

			button.dataset.fkBound = '1';
			TagModal.bind( button );
		} );
	},

	/**
	 * Wire one button to its dialog.
	 *
	 * @param {Element} button The Add Tag button.
	 */
	bind( button ) {
		const modal = document.getElementById( button.dataset.modal );

		if ( ! modal ) {
			return;
		}

		const backdrop = modal.nextElementSibling;
		const search = modal.querySelector( '.field-kit__tag-search-input' );
		const empty = modal.querySelector( '.field-kit__tag-empty' );
		const rows = Array.prototype.slice.call(
			modal.querySelectorAll( '.field-kit__tag-items li' )
		);

		function open() {
			modal.hidden = false;

			if ( backdrop ) {
				backdrop.hidden = false;
			}

			if ( search ) {
				search.value = '';
				filter( '' );
				search.focus();
			}

			document.addEventListener( 'keydown', onKey );
		}

		function close() {
			modal.hidden = true;

			if ( backdrop ) {
				backdrop.hidden = true;
			}

			document.removeEventListener( 'keydown', onKey );

			// Focus goes back where it came from, or it lands at the top
			// of the document and the next tab starts the page again.
			button.focus();
		}

		function onKey( event ) {
			if ( 'Escape' === event.key ) {
				close();

				return;
			}

			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				step( 'ArrowDown' === event.key ? 1 : -1, event );
			}
		}

		/**
		 * Move through the rows that are showing with the arrow keys.
		 *
		 * Down from the search box lands on the first match, and up
		 * from the first row goes back to the box, so somebody who
		 * typed a word reaches its tag without leaving the keyboard
		 * for a Tab per row. Enter on a row is the button's own click.
		 *
		 * @param {number} by    1 for down, -1 for up.
		 * @param {Event}  event The key press.
		 */
		function step( by, event ) {
			const items = rows
				.filter( function ( row ) {
					return ! row.hidden;
				} )
				.map( function ( row ) {
					return row.querySelector( '.field-kit__tag-item' );
				} )
				.filter( Boolean );

			const at = items.indexOf( search.ownerDocument.activeElement );

			// Only from the box or a row: the arrows belong to whatever
			// else has focus, which is the close button and nothing.
			if ( -1 === at && search.ownerDocument.activeElement !== search ) {
				return;
			}

			let next = items[ at + by ];

			if ( ! next && by < 0 && search ) {
				next = search;
			}

			if ( next ) {
				event.preventDefault();
				next.focus();
			}
		}

		function filter( term ) {
			const needle = term.toLowerCase().trim();
			let shown = 0;

			// The heading of the group being walked through, and whether
			// any row under it is showing: a heading over nothing is
			// hidden, so a search leaves the matches and the names of the
			// groups they are in.
			let heading = null;

			rows.forEach( function ( row ) {
				if ( row.classList.contains( 'field-kit__tag-group' ) ) {
					heading = row;
					heading.hidden = true;

					return;
				}

				const item = row.querySelector( '.field-kit__tag-item' );
				const match =
					'' === needle ||
					( item &&
						item.dataset.search &&
						item.dataset.search.indexOf( needle ) > -1 );

				row.hidden = ! match;

				if ( match ) {
					shown++;

					if ( heading ) {
						heading.hidden = false;
					}
				}
			} );

			if ( empty ) {
				empty.hidden = shown > 0;
			}
		}

		button.addEventListener( 'click', open );

		const closer = modal.querySelector( '.field-kit__tag-close' );

		if ( closer ) {
			closer.addEventListener( 'click', close );
		}

		if ( backdrop ) {
			backdrop.addEventListener( 'click', close );
		}

		if ( search ) {
			search.addEventListener( 'input', function () {
				filter( search.value );
			} );
		}

		rows.forEach( function ( row ) {
			const item = row.querySelector( '.field-kit__tag-item' );

			if ( ! item ) {
				return;
			}

			item.addEventListener( 'click', function () {
				TagModal.insert(
					button.dataset.editor,
					item.dataset.tag || ''
				);
				close();
			} );
		} );
	},

	/**
	 * Put a tag into an editor.
	 *
	 * A visual editor is not an input at all — its content lives in an
	 * iframe — so writing to the textarea underneath it would be
	 * overwritten the moment the editor syncs.
	 *
	 * @param {string} editorId The editor's id.
	 * @param {string} tag      The tag to insert.
	 */
	insert( editorId, tag ) {
		if ( '' === tag ) {
			return;
		}

		const editor = window.tinymce ? window.tinymce.get( editorId ) : null;

		if ( editor && ! editor.isHidden() ) {
			editor.execCommand( 'mceInsertContent', false, tag );

			// Written through to the textarea now. That is what a
			// Preview button beside the editor reads, and without this
			// a tag put in a moment ago was not in the preview.
			editor.save();

			return;
		}

		const textarea = document.getElementById( editorId );

		if ( ! textarea ) {
			return;
		}

		const start = textarea.selectionStart;
		const end = textarea.selectionEnd;

		if ( 'number' !== typeof start ) {
			textarea.value += tag;
		} else {
			textarea.value =
				textarea.value.slice( 0, start ) +
				tag +
				textarea.value.slice( end );
			textarea.selectionStart = start + tag.length;
			textarea.selectionEnd = textarea.selectionStart;
		}

		textarea.focus();
		textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	},
};

export default TagModal;
