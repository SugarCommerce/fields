/**
 * Email panels: a merge tag goes in where the author was last typing.
 */

const EmailPanel = {
	/**
	 * Bind every email panel within a root.
	 *
	 * One behaviour the markup promises and core does not supply: a merge
	 * tag inserts itself where the author was last typing. Folding the
	 * panel is the Accordion module's, since the header is core's
	 * accordion trigger like every collapsible section's.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '.field-kit__email' ).forEach(
			function ( panel ) {
				if ( panel.dataset.fkBound ) {
					return;
				}

				panel.dataset.fkBound = '1';
				EmailPanel.bindTags( panel );
				EmailPanel.bindStatus( panel );
			}
		);
	},

	/**
	 * Keep the pill beside the name saying what its switch says.
	 *
	 * The pill is drawn from what was saved. Throw the switch and it
	 * would go on saying On beside a switch that is off, until a save
	 * and a reload agreed with it.
	 *
	 * @param {Element} panel The panel.
	 */
	bindStatus( panel ) {
		const pill = panel.querySelector(
			'.privacy-settings-accordion-trigger [data-follows]'
		);

		if ( ! pill ) {
			return;
		}

		const follows = pill.dataset.follows;
		let input = null;

		// The switch is the checkbox whose name ends in the part's key;
		// the hidden input of the same name before it is its "off".
		panel
			.querySelectorAll( 'input[type="checkbox"][name]' )
			.forEach( function ( box ) {
				if (
					! input &&
					box.name.slice( -( follows.length + 2 ) ) ===
						'[' + follows + ']'
				) {
					input = box;
				}
			} );

		if ( ! input ) {
			return;
		}

		input.addEventListener( 'change', function () {
			pill.textContent = input.checked
				? pill.dataset.on
				: pill.dataset.off;
			pill.classList.toggle(
				'field-kit__settings-card-status--success',
				input.checked
			);
			pill.classList.toggle(
				'field-kit__settings-card-status--neutral',
				! input.checked
			);
		} );
	},

	/**
	 * Insert a merge tag where the author was last typing.
	 *
	 * @param {Element} panel The panel.
	 */
	bindTags( panel ) {
		let target = null;

		panel
			.querySelectorAll( 'input[type="text"], textarea' )
			.forEach( function ( input ) {
				input.addEventListener( 'focus', function () {
					target = input;
				} );
			} );

		panel
			.querySelectorAll( '.field-kit__email-tag' )
			.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					EmailPanel.insert(
						panel,
						target,
						button.dataset.tag || ''
					);
				} );
			} );
	},

	/**
	 * Put a tag into whichever editor is in play.
	 *
	 * @param {Element}      panel The panel.
	 * @param {Element|null} input The last focused input, if any.
	 * @param {string}       tag   The tag to insert.
	 */
	insert( panel, input, tag ) {
		if ( '' === tag ) {
			return;
		}

		// A visual editor is not an input at all — its content lives in an
		// iframe — so writing to the textarea underneath it would be
		// overwritten the moment the editor syncs.
		const editor = EmailPanel.activeEditor( panel );

		if ( editor && ! editor.isHidden() ) {
			editor.execCommand( 'mceInsertContent', false, tag );

			return;
		}

		const field =
			input || panel.querySelector( 'textarea, input[type="text"]' );

		if ( ! field ) {
			return;
		}

		const start = field.selectionStart;
		const end = field.selectionEnd;

		if ( 'number' !== typeof start ) {
			field.value += tag;
		} else {
			field.value =
				field.value.slice( 0, start ) + tag + field.value.slice( end );
			field.selectionStart = start + tag.length;
			field.selectionEnd = field.selectionStart;
		}

		field.focus();
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	},

	/**
	 * The TinyMCE instance for this panel's body, if there is one.
	 *
	 * @param {Element} panel The panel.
	 * @return {Object|null} The editor.
	 */
	activeEditor( panel ) {
		const textarea = panel.querySelector( 'textarea.wp-editor-area' );

		if ( ! textarea || ! window.tinymce ) {
			return null;
		}

		return window.tinymce.get( textarea.id ) || null;
	},
};

export default EmailPanel;
