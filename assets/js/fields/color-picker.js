/**
 * Colour: core's own picker (Iris), for a field that asked for it.
 *
 * A jQuery plugin, reached through lib/jquery.js -- the one module that
 * touches jQuery, and only when the page has it.
 */

import { colorPicker, hasColorPicker } from '../lib/jquery';
import { ours } from '../lib/dom';

const ColorPicker = {
	/**
	 * Upgrade every colour field to core's picker.
	 *
	 * Enqueueing wp-color-picker is not enough: it is a jQuery plugin and
	 * does nothing until it is called. The control was a plain text input
	 * on screen for exactly that reason.
	 *
	 * Only a field that asked for core's picker (`'picker' =>
	 * 'wordpress'`, or `alpha`) is one of these; every other colour
	 * field is the native control, which ColorField drives. The input
	 * keeps working if jQuery or the plugin is missing -- a hex value
	 * can still be typed.
	 *
	 * @param {Element} root Container.
	 */
	init( root ) {
		if ( ! hasColorPicker() ) {
			return;
		}

		ours( root, 'input.field-kit__color' ).forEach( function ( input ) {
			if ( input.dataset.fkBound ) {
				return;
			}

			input.dataset.fkBound = '1';

			/**
			 * Tell the kit the value moved, without telling iris.
			 *
			 * iris fires its change callback *before* it writes the new
			 * value to the input — verified in iris.min.js `_change`,
			 * which calls `_trigger("change")` and only then
			 * `element.val()`. It also listens for `change` on the input
			 * itself and re-reads the value when one arrives.
			 *
			 * So dispatching a native change from here handed iris the
			 * *old* value and it reset the picker: the palette opened,
			 * swatches highlighted, and clicking one changed nothing.
			 * From the clear callback it was worse — that one is reached
			 * from iris's own change listener, so a native change there
			 * loops.
			 *
			 * A custom event does neither. Conditions listens for it.
			 */
			function notify() {
				input.dispatchEvent(
					new CustomEvent( 'field-kit:change', { bubbles: true } )
				);
			}

			colorPicker( input, {
				palettes: input.dataset.palette
					? input.dataset.palette.split( ',' )
					: true,
				change: notify,
				clear: notify,
			} );
		} );
	},
};

export default ColorPicker;
