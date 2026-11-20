/**
 * A text field with a button that fills it in.
 *
 * The alphabets are here rather than sent from the server, and the button
 * names one rather than carrying its characters: a caller cannot put
 * arbitrary text into a page and have this draw from it.
 *
 * Ambiguity is why these are not simply "letters and numbers" — a code
 * gets read off a screen and typed into a box, and O/0 and I/1/l are
 * where that goes wrong.
 */

const CodeGenerator = {
	alphabets: {
		alphanumeric_upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789',
		alphanumeric:
			'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789',
		alpha_upper: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
		hex: '0123456789ABCDEF',
		numeric: '0123456789',
	},

	/**
	 * Wire the generate buttons.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		root.querySelectorAll( '.field-kit__code-generate' ).forEach(
			function ( button ) {
				// Marked, because init() is called again for every repeater
				// row and every flyout that opens, and a twice-bound button
				// generates two codes and keeps the second.
				if ( button.dataset.fkBound ) {
					return;
				}

				button.dataset.fkBound = '1';

				button.addEventListener( 'click', function () {
					const wrap = button.closest( '.field-kit__code-generator' );
					const input =
						wrap && wrap.querySelector( '.field-kit__code-value' );

					if ( ! input ) {
						return;
					}

					input.value = CodeGenerator.build( button.dataset );

					// So a conditional field watching this one, or anything
					// else listening, hears about it. Setting .value fires
					// nothing.
					input.dispatchEvent(
						new Event( 'input', { bubbles: true } )
					);
					input.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);

					input.focus();
				} );
			}
		);
	},

	/**
	 * Build one code from a button's settings.
	 *
	 * @param {DOMStringMap} settings The button's data attributes.
	 * @return {string} The code.
	 */
	build( settings ) {
		const alphabet =
			CodeGenerator.alphabets[ settings.format ] ||
			CodeGenerator.alphabets.alphanumeric_upper;
		const length = parseInt( settings.length, 10 ) || 8;
		const segment = parseInt( settings.segmentLength, 10 ) || 0;
		const separator = settings.separator || '';
		let code = '';

		// crypto rather than Math.random: not because this has to be
		// unpredictable — it is a discount code, and anything that must
		// be should not be generated in a browser — but because
		// Math.random collides sooner than people expect at these
		// lengths, and a duplicate code is a support ticket.
		const bytes = new Uint32Array( length );
		window.crypto.getRandomValues( bytes );

		for ( let i = 0; i < length; i++ ) {
			code += alphabet.charAt( bytes[ i ] % alphabet.length );
		}

		if ( separator && segment > 0 ) {
			code = code
				.match( new RegExp( '.{1,' + segment + '}', 'g' ) )
				.join( separator );
		}

		return ( settings.prefix || '' ) + code;
	},
};

export default CodeGenerator;
