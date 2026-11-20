/**
 * Requests to the lookup routes: a slug's availability, a place's
 * coordinates, a video's player, a country's address format.
 */

import { config } from './config';
import { request } from './request';

/**
 * An address under the kit's REST namespace, with a query.
 *
 * Built with URL so a site on plain permalinks, whose REST root is
 * `?rest_route=/…/`, gets its parameters after the route rather than
 * inside it.
 *
 * @param {string} path   The route under the namespace.
 * @param {Object} params Query parameters; an array value repeats.
 * @return {URL|null} Null without a REST root.
 */
export function endpoint( path, params ) {
	let url;

	if ( ! config.restUrl ) {
		return null;
	}

	try {
		url = new URL( config.restUrl + path, window.location.origin );
	} catch {
		return null;
	}

	Object.keys( params || {} ).forEach( ( key ) => {
		[].concat( params[ key ] ).forEach( ( value ) => {
			url.searchParams.append(
				Array.isArray( params[ key ] ) ? key + '[]' : key,
				String( value )
			);
		} );
	} );

	return url;
}

/**
 * Ask a route and read its answer, whatever the status.
 *
 * @param {URL}    url  The address.
 * @param {Object} init Fetch options.
 * @return {Promise<{ok: boolean, json: Object}>} The answer.
 */
export function ask( url, init ) {
	return request(
		url,
		Object.assign(
			{
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.restNonce || '' },
			},
			init || {}
		)
	).then( ( response ) =>
		response.json().then(
			( json ) => ( { ok: response.ok, json: json || {} } ),
			() => ( { ok: false, json: {} } )
		)
	);
}

/**
 * The closest field wrapper a control belongs to.
 *
 * @param {Element} el An element inside a field.
 * @return {Element|null} The field.
 */
export function fieldOf( el ) {
	return el && el.closest ? el.closest( '[data-field-key]' ) : null;
}
