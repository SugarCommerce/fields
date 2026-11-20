/**
 * A code field as core's code editor (CodeMirror), started with the
 * settings the screen printed for its language. Without them -- the user
 * turned syntax highlighting off -- it stays the textarea it always was.
 */

import { config } from '../lib/config';

const CodeEditor = {
	/**
	 * Upgrade every code field to CodeMirror.
	 *
	 * The control stays a textarea underneath, so a user who has turned
	 * syntax highlighting off in their profile — in which case
	 * wp_enqueue_code_editor() returns false and there are no settings
	 * to apply — still gets a working, keyboard-accessible field rather
	 * than nothing.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		if ( ! window.wp || ! window.wp.codeEditor || ! config.codeEditors ) {
			return;
		}

		root.querySelectorAll( 'textarea.field-kit__code' ).forEach(
			function ( textarea ) {
				if ( textarea.dataset.fkBound ) {
					return;
				}

				const settings =
					config.codeEditors[ textarea.dataset.language ];

				if ( ! settings ) {
					return;
				}

				textarea.dataset.fkBound = '1';

				const editor = window.wp.codeEditor.initialize(
					textarea,
					settings
				);

				// CodeMirror renders into its own element and stops firing
				// the textarea's events, so the value is written back on
				// change — otherwise the field submits whatever it loaded
				// with, however much was typed.
				if ( editor && editor.codemirror ) {
					editor.codemirror.on( 'change', function ( instance ) {
						textarea.value = instance.getValue();
					} );

					// The generated element is not a form control and gets no
					// label, so the field's own label is pointed at it.
					const wrapper = editor.codemirror.getWrapperElement();

					if ( wrapper ) {
						wrapper.setAttribute(
							'aria-label',
							textarea.getAttribute( 'aria-label' ) || ''
						);
					}
				}
			}
		);
	},
};

export default CodeEditor;
