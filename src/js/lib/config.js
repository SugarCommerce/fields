/**
 * What PHP handed the scripts.
 *
 * The only module that reads the page's configuration. Each build of this
 * library -- the plain one, or a Strauss-prefixed copy inside another plugin
 * -- publishes its REST URL, nonce, strings and icons into
 * `window.SugarCommerceFields`, keyed by its script's handle (Assets.php,
 * Guard::enable(), BlockEditorPanel). Two copies load two scripts, and a
 * shared bare global would leave whichever localised last owning the REST
 * URL and nonce for both.
 *
 * A script finds its own entry from the id WordPress stamps on its <script>
 * element, "{handle}-js".
 */

// Read while the script runs. Once it has, document.currentScript is null.
const script = document.currentScript;

/**
 * The entry a script's element names in a registry.
 *
 * @param {Element|null} element  The <script> element, or null.
 * @param {Object}       registry window.SugarCommerceFields.
 * @return {Object|undefined} The build's entry, if it published one.
 */
export function resolve( element, registry ) {
	const handle =
		element && element.id ? element.id.replace( /-js$/, '' ) : '';

	return ( registry || {} )[ handle ];
}

/**
 * This script's own entry, if it has one.
 *
 * @return {Object|undefined} The entry.
 */
export function own() {
	return resolve( script, window.SugarCommerceFields );
}

/**
 * This build's configuration: restUrl, restNonce, i18n, icons, and what a
 * screen added -- codeEditors, guard.
 *
 * Read once, as the script loads; what a screen adds later lands in the
 * same object, which is why it is kept rather than copied.
 */
export const config = own() || window.FieldKit || {};

/**
 * Translate, falling back to the key's own English.
 *
 * @param {string} key      Message key.
 * @param {string} fallback English text.
 * @return {string} Message.
 */
export function t( key, fallback ) {
	return ( config.i18n || {} )[ key ] || fallback;
}

/**
 * One of the kit's own icons, as markup.
 *
 * Drawn in PHP (Glyphs::markup()) and handed over in the configuration,
 * so there is no second copy of a path here.
 *
 * @param {string} key A key of Glyphs::DEFAULTS, such as 'close'.
 * @return {string} SVG markup, or '' when there is none.
 */
export function icon( key ) {
	return ( config.icons && config.icons[ key ] ) || '';
}
