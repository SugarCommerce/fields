/**
 * Media: one image or file, chosen from the media library or a file source
 * another plugin offers.
 */

import { ours } from '../lib/dom';

const Media = {
	/**
	 * Bind every single-item media field within a root.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__media' ).forEach( function ( wrap ) {
			// Before the bound check: init() runs again at load, and a
			// source's script that arrived late shows its button then.
			Media.sources( wrap );

			if ( wrap.dataset.fkBound ) {
				return;
			}

			wrap.dataset.fkBound = '1';

			wrap.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '.field-kit__media-choose' ) ) {
					event.preventDefault();
					Media.open( wrap );
				}

				const sourceButton = event.target.closest(
					'.field-kit__media-source'
				);

				if ( sourceButton ) {
					event.preventDefault();
					Media.fromSource( wrap, sourceButton.dataset.source );
				}

				if ( event.target.closest( '.field-kit__media-clear' ) ) {
					event.preventDefault();
					Media.clear( wrap );
				}
			} );
		} );
	},

	/**
	 * Show a source's button only when its script registered it.
	 *
	 * A source that failed to load must not leave a button that does
	 * nothing.
	 *
	 * @param {Element} wrap The field.
	 */
	sources( wrap ) {
		const registry = window.sugarcommerceFileSources || {};

		wrap.querySelectorAll( '.field-kit__media-source' ).forEach(
			function ( button ) {
				const source = registry[ button.dataset.source ];

				button.hidden = ! source || typeof source.pick !== 'function';
			}
		);
	},

	/**
	 * Fill the field from another source: a bucket, a cloud drive.
	 *
	 * Through apply(), the path a media-library choice takes, so the
	 * preview, the file name, the button's label and Remove follow.
	 *
	 * @param {Element} wrap The field.
	 * @param {string}  id   The source's key in window.sugarcommerceFileSources.
	 * @return {Promise|undefined} The pick, when there was a source to ask.
	 */
	fromSource( wrap, id ) {
		const source = ( window.sugarcommerceFileSources || {} )[ id ];

		if ( ! source || typeof source.pick !== 'function' ) {
			return;
		}

		return Promise.resolve(
			source.pick( {
				accept: ( wrap.dataset.accept || '' )
					.split( ',' )
					.filter( Boolean ),
				returns: 'address',
			} )
		).then(
			function ( chosen ) {
				// Dismissed.
				if ( ! chosen || ! chosen.value ) {
					return;
				}

				Media.apply( wrap, {
					url: chosen.value,
					id: chosen.value,
					filename: chosen.name || '',
					title: chosen.name || '',
				} );
			},
			function () {}
		);
	},

	/**
	 * Open the media frame.
	 *
	 * @param {Element} wrap The field.
	 */
	open( wrap ) {
		if ( ! window.wp || ! window.wp.media ) {
			return;
		}

		const frame = window.wp.media( {
			title: wrap.dataset.frameTitle,
			library: { type: wrap.dataset.mimeType || undefined },
			multiple: false,
			button: { text: wrap.dataset.chooseLabel },
		} );

		frame.on( 'select', function () {
			Media.apply(
				wrap,
				frame.state().get( 'selection' ).first().toJSON()
			);
		} );

		frame.open();
	},

	/**
	 * Write the choose button's words.
	 *
	 * Into its label where it has one: an image field's empty state draws
	 * a picture beside the words, and writing the whole button's text
	 * took the picture away the first time an image was chosen and
	 * removed again.
	 *
	 * @param {Element} button The button.
	 * @param {string}  text   What it says.
	 */
	label( button, text ) {
		const own = button.querySelector( '.field-kit__media-choose-label' );

		( own || button ).textContent = text;
	},

	/**
	 * Store and preview a selection.
	 *
	 * @param {Element} wrap       The field.
	 * @param {Object}  attachment Attachment data.
	 */
	apply( wrap, attachment ) {
		const input = wrap.querySelector( '.field-kit__media-value' );
		const preview = wrap.querySelector( '.field-kit__media-preview' );
		const clear = wrap.querySelector( '.field-kit__media-clear' );
		const filenameInput = wrap.querySelector(
			'input.field-kit__media-filename'
		);

		// file_url stores the URL; every other media field stores the id.
		input.value =
			wrap.dataset.returns === 'url' ? attachment.url : attachment.id;
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		// `file` holds an attachment id, which is nothing to show anyone,
		// so a readonly input beside it carries the name.
		if ( filenameInput ) {
			filenameInput.value = attachment.filename || attachment.title || '';
		}

		// The button under a picture changes the picture.
		const chooser = wrap.querySelector( '.field-kit__media-choose' );

		if ( chooser && wrap.dataset.replaceLabel ) {
			Media.label( chooser, wrap.dataset.replaceLabel );
		}

		if ( preview ) {
			const sizes = attachment.sizes || {};
			const thumb = sizes.medium || sizes.thumbnail;

			preview.dataset.empty = 'false';
			preview.textContent = '';

			// Built, not written: the URL and alt text are whatever the
			// media library holds, and a string of markup is the one
			// place they must not be interpolated.
			if ( thumb ) {
				const image = document.createElement( 'img' );
				image.src = thumb.url;
				image.alt = attachment.alt || '';
				preview.appendChild( image );
			} else {
				const name = document.createElement( 'span' );
				name.className = 'field-kit__media-filename';
				preview.appendChild( name );
			}

			const filename = preview.querySelector(
				'.field-kit__media-filename'
			);

			if ( filename ) {
				filename.textContent =
					attachment.filename || attachment.title || '';
			}
		}

		if ( clear ) {
			clear.hidden = false;
		}
	},

	/**
	 * Clear a selection.
	 *
	 * @param {Element} wrap The field.
	 */
	clear( wrap ) {
		const input = wrap.querySelector( '.field-kit__media-value' );
		const preview = wrap.querySelector( '.field-kit__media-preview' );
		const clear = wrap.querySelector( '.field-kit__media-clear' );
		const choose = wrap.querySelector( '.field-kit__media-choose' );
		const filenameInput = wrap.querySelector(
			'input.field-kit__media-filename'
		);

		input.value = '';
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		if ( filenameInput ) {
			filenameInput.value = '';
		}

		// And with nothing chosen it goes back to offering to choose.
		if ( choose && wrap.dataset.replaceLabel && wrap.dataset.chooseLabel ) {
			Media.label( choose, wrap.dataset.chooseLabel );
		}

		if ( preview ) {
			preview.innerHTML = '';
			preview.dataset.empty = 'true';
		}

		if ( clear ) {
			clear.hidden = true;
		}

		// The button that was just used is now hidden, so focus has to go
		// somewhere deliberate rather than to the body.
		if ( choose ) {
			choose.focus();
		}
	},
};

export default Media;
