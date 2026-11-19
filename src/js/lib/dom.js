/**
 * Small things every module does to the page.
 */

import { icon } from './config';

/**
 * Put one of the kit's icons in place of another element.
 *
 * For a control whose icon says its state -- shown or hidden, active or
 * not -- and changes with it.
 *
 * @param {Element|null} el  The icon now there.
 * @param {string}       key A key of Glyphs::DEFAULTS.
 * @return {Element|null} The icon now there.
 */
export function swapIcon( el, key ) {
	const markup = icon( key );

	if ( ! el || ! markup || ! el.parentNode ) {
		return el;
	}

	const holder = document.createElement( 'template' );

	holder.innerHTML = markup;

	const next = holder.content.firstElementChild;

	el.parentNode.replaceChild( next, el );

	return next;
}

/**
 * Whether a module has already bound a root, marking it if not.
 *
 * init() runs twice on a normal page load -- once at DOMContentLoaded and
 * once at load, to pick up a control whose library arrived late -- and
 * again for every repeater row added. A module that listens on the root
 * itself, rather than marking each control it enhances, would otherwise
 * bind a second handler each time: two copies to the clipboard, every
 * conditional field evaluated twice per keystroke.
 *
 * @param {Element} root Container.
 * @param {string}  name Module name.
 * @return {boolean} True when the root was already bound.
 */
export function bound( root, name ) {
	const marks = root.fieldKitBound || ( root.fieldKitBound = {} );

	if ( marks[ name ] ) {
		return true;
	}

	marks[ name ] = true;

	return false;
}

/**
 * Announce a message to assistive technology.
 *
 * Writes into a field's own live region where it has one, so the message
 * is associated with the control it describes rather than shouted from a
 * page-level region.
 *
 * @param {Element} region Live region element.
 * @param {string}  text   Message.
 */
export function announce( region, text ) {
	if ( ! region ) {
		return;
	}

	// Clearing first forces a re-announcement when the text is unchanged,
	// which is what happens when the same action is repeated.
	region.textContent = '';
	window.setTimeout( () => {
		region.textContent = text;
	}, 50 );
}

/**
 * A sibling control, found by its field key.
 *
 * Scoped to the repeater row the caller sits in, then the form, then the
 * document: a repeater has one of each key per row, and the first match
 * in the form would give every row the first row's value. The key is
 * matched as a bare name or as the last segment of a bracketed one,
 * which is how a row's inputs are named.
 *
 * Money reads its currency this way and region its country -- one lookup
 * rather than two copies of the same query, free to drift apart.
 *
 * The sibling named like the asking control is looked for first:
 * `address[region]` sits beside `address[country]`, a bare `region`
 * beside a bare `country`. Matching any name that ends in `[country]`
 * found whichever came first in the form -- in a panel with a table of
 * rates above an address group, the region followed a rate's country.
 *
 * @param {Element} wrap The control asking.
 * @param {string}  key  Field key of the sibling.
 * @return {Element|null} The control, if there is one.
 */
export function sibling( wrap, key ) {
	if ( ! key ) {
		return null;
	}

	const scope =
		wrap.closest( '.field-kit__repeater-row' ) ||
		wrap.closest( 'form' ) ||
		document;
	const own =
		typeof wrap.getAttribute === 'function' && wrap.getAttribute( 'name' )
			? wrap
			: wrap.querySelector( '[name]' );
	const name =
		own && typeof own.getAttribute === 'function'
			? String( own.getAttribute( 'name' ) || '' ).replace( /\[\]$/, '' )
			: '';

	if ( name ) {
		const cut = name.lastIndexOf( '[' );
		const exact = scope.querySelector(
			'[name="' +
				( -1 === cut ? key : name.slice( 0, cut ) + '[' + key + ']' ) +
				'"]'
		);

		if ( exact ) {
			return exact;
		}
	}

	return scope.querySelector(
		'[name$="[' + key + ']"], [name="' + key + '"]'
	);
}
