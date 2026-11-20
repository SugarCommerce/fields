/**
 * Range readout: a slider's value, beside it.
 */

import { ours } from '../lib/dom';

const Range = {
	/**
	 * Keep each slider's readout in step with its value.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		ours( root, '.field-kit__range' ).forEach( function ( wrap ) {
			const input = wrap.querySelector( 'input[type="range"]' );
			const output = wrap.querySelector( '.field-kit__range-output' );

			if ( ! input || ! output ) {
				return;
			}

			const unit = wrap.getAttribute( 'data-unit' ) || '';

			input.addEventListener( 'input', function () {
				output.textContent = input.value + unit;
			} );
		} );
	},
};

export default Range;
