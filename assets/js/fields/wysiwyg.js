/**
 * Visual editor: one that arrived after the page did -- in a panel, a new
 * row -- started the way core starts the ones it printed.
 */

const Wysiwyg = {
	/**
	 * Start every visual editor that nothing else has started.
	 *
	 * wp_editor() prints the editor's markup where it is called and its
	 * settings in the page footer, and core starts the editor from those
	 * settings once the page has loaded. Markup that arrives later -- a
	 * flyout fetched over REST -- comes without them: the Visual tab did
	 * nothing, the Code toolbar was empty, and only the bare textarea
	 * worked. So an editor core does not know about is started here,
	 * from core's own defaults (wp_enqueue_editor() prints them), on the
	 * markup wp_editor() already drew.
	 *
	 * An editor core did start is left alone.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll(
			'.field-kit__wysiwyg-wrap[data-editor]'
		).forEach( function ( wrap ) {
			const id = wrap.dataset.editor;
			const textarea = document.getElementById( id );

			if (
				wrap.dataset.fkBound ||
				! textarea ||
				! wrap.contains( textarea )
			) {
				return;
			}

			if ( Wysiwyg.startedByCore( id, textarea ) ) {
				wrap.dataset.fkBound = '1';
				return;
			}

			if (
				! window.wp ||
				! window.wp.editor ||
				typeof window.wp.editor.getDefaultSettings !== 'function' ||
				! window.tinymce
			) {
				return;
			}

			wrap.dataset.fkBound = '1';
			Wysiwyg.start( wrap, id, textarea );
		} );
	},

	/**
	 * Whether core started this editor, from settings printed with the
	 * page.
	 *
	 * @param {string}  id       Editor id.
	 * @param {Element} textarea Its textarea.
	 * @return {boolean} Yes when core owns it.
	 */
	startedByCore( id, textarea ) {
		const pre = window.tinyMCEPreInit;
		const editor = window.tinymce && window.tinymce.get( id );
		const qt =
			window.QTags &&
			window.QTags.instances &&
			window.QTags.instances[ id ];

		if (
			! pre ||
			! (
				( pre.mceInit && pre.mceInit[ id ] ) ||
				( pre.qtInit && pre.qtInit[ id ] )
			) ||
			Wysiwyg.ours[ id ]
		) {
			return false;
		}

		return !! (
			( editor && editor.getElement() === textarea ) ||
			( qt && qt.canvas === textarea ) ||
			! window.tinymce
		);
	},

	/**
	 * Ids of the editors this module started.
	 */
	ours: {},

	/**
	 * Start one editor on markup wp_editor() drew.
	 *
	 * @param {Element} wrap     The field's wrapper.
	 * @param {string}  id       Editor id.
	 * @param {Element} textarea Its textarea.
	 */
	start( wrap, id, textarea ) {
		const defaults = window.wp.editor.getDefaultSettings();
		const editorWrap = document.getElementById( 'wp-' + id + '-wrap' );
		const mce = {};
		let key;

		// The same editor id came back -- a panel opened a second time --
		// and whatever was started on the copy that has gone is stopped
		// first, or TinyMCE keeps the old instance and the new textarea
		// never becomes an editor.
		Wysiwyg.stop( id );

		for ( key in defaults.tinymce ) {
			if (
				Object.prototype.hasOwnProperty.call( defaults.tinymce, key )
			) {
				mce[ key ] = defaults.tinymce[ key ];
			}
		}

		mce.selector = '#' + id;
		mce.body_class = id;

		// wp_editor()'s toolbars rather than the two-button row core's
		// script defaults to, so the same field has the same editor
		// wherever it is drawn.
		if ( wrap.dataset.teeny ) {
			mce.toolbar1 =
				'bold,italic,underline,blockquote,strikethrough,bullist,numlist,alignleft,aligncenter,alignright,undo,redo,link,fullscreen';
			mce.toolbar2 = '';
		} else {
			mce.toolbar1 =
				'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,wp_more,fullscreen,wp_adv';
			mce.toolbar2 =
				'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo,wp_help';
		}

		if ( wrap.dataset.height ) {
			mce.height = parseInt( wrap.dataset.height, 10 );
		}

		// The textarea is what the form sends, so it is kept in step with
		// what is typed, and told -- a panel watching for changes listens
		// to the form, not to an iframe.
		mce.setup = function ( editor ) {
			editor.on( 'change input keyup undo redo SetContent', function () {
				if ( editor.isHidden() ) {
					return;
				}

				const before = textarea.value;

				editor.save();

				if ( textarea.value !== before ) {
					textarea.dispatchEvent(
						new Event( 'input', { bubbles: true } )
					);
					textarea.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} );
		};

		// Where core's Visual and Code tabs look for the settings, so
		// switching starts TinyMCE when the editor opened on Code.
		window.tinyMCEPreInit = window.tinyMCEPreInit || {
			mceInit: {},
			qtInit: {},
		};
		window.tinyMCEPreInit.mceInit = window.tinyMCEPreInit.mceInit || {};
		window.tinyMCEPreInit.qtInit = window.tinyMCEPreInit.qtInit || {};
		window.tinyMCEPreInit.mceInit[ id ] = mce;
		// wp_editor()'s own buttons, not the shorter list core's script
		// defaults to: the same field has the same toolbar wherever it
		// is drawn.
		window.tinyMCEPreInit.qtInit[ id ] = {
			id,
			buttons:
				'strong,em,link,block,del,ins,img,ul,ol,li,code,more,close',
		};

		Wysiwyg.ours[ id ] = true;

		if (
			window.quicktags &&
			editorWrap &&
			editorWrap.querySelector( '.quicktags-toolbar' )
		) {
			window.quicktags( window.tinyMCEPreInit.qtInit[ id ] );

			if (
				window.QTags &&
				typeof window.QTags._buttonsInit === 'function'
			) {
				window.QTags._buttonsInit();
			}
		}

		// Opened on whichever tab the person last used, as wp_editor()
		// said by the class it gave the wrapper.
		if ( ! editorWrap || editorWrap.classList.contains( 'tmce-active' ) ) {
			window.tinymce.init( mce );
		}

		if ( ! window.wpActiveEditor ) {
			window.wpActiveEditor = id;
		}
	},

	/**
	 * Stop an editor this module started, whether or not its markup is
	 * still on the page.
	 *
	 * @param {string} id Editor id.
	 */
	stop( id ) {
		const editor = window.tinymce && window.tinymce.get( id );

		if ( editor ) {
			if (
				! editor.isHidden() &&
				document.body.contains( editor.getElement() )
			) {
				editor.save();
			}

			editor.remove();
		}

		if (
			window.QTags &&
			window.QTags.instances &&
			window.QTags.instances[ id ]
		) {
			delete window.QTags.instances[ id ];
		}

		if ( window.wpActiveEditor === id ) {
			window.wpActiveEditor = null;
		}

		delete Wysiwyg.ours[ id ];
	},

	/**
	 * Stop every editor this module started within a root that is about
	 * to go.
	 *
	 * @param {Element} root Container.
	 */
	destroy( root ) {
		root.querySelectorAll(
			'.field-kit__wysiwyg-wrap[data-editor]'
		).forEach( function ( wrap ) {
			const id = wrap.dataset.editor;

			if ( Wysiwyg.ours[ id ] ) {
				Wysiwyg.stop( id );
			}

			delete wrap.dataset.fkBound;
		} );
	},
};

export default Wysiwyg;
