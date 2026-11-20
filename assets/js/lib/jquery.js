/**
 * The one thing the kit still reaches through jQuery: core's colour picker.
 *
 * wp-color-picker is a jQuery plugin with no other way in, and a field
 * that asks for it (`'picker' => 'wordpress'`, or `alpha`) brings jQuery
 * and the plugin as its own dependencies (ColorType::dependencies_for()).
 * Read from the page when it is wanted, never imported: the kit itself
 * does not need jQuery, and a page without it -- a public form -- just
 * keeps the plain input, where a hex value can still be typed.
 */

/**
 * Whether the page has core's colour picker.
 *
 * @return {boolean} Whether it does.
 */
export function hasColorPicker() {
	const $ = window.jQuery;

	return !! ( $ && $.fn && $.fn.wpColorPicker );
}

/**
 * Turn an input into core's colour picker.
 *
 * @param {HTMLInputElement} input   The input.
 * @param {Object}           options The picker's options.
 * @return {boolean} False when the page has no picker.
 */
export function colorPicker( input, options ) {
	if ( ! hasColorPicker() ) {
		return false;
	}

	window.jQuery( input ).wpColorPicker( options );

	return true;
}
