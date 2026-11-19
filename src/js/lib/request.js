/**
 * Requests to the REST API.
 */

import apiFetch from '@wordpress/api-fetch';

/**
 * A REST request, the way fetch() makes one.
 *
 * Through apiFetch, because the nonce printed with the page runs out after
 * a day: a settings screen left open overnight then gets a 403 from every
 * search, icon list and button. apiFetch notices the stale nonce, asks for
 * a fresh one and sends the request again. Its answer comes back shaped
 * like a fetch Response (ok, status, json()), so the callers read it the
 * same way they read fetch's; a body that was not JSON makes json() reject,
 * as it would have.
 *
 * @param {string|URL} url  The route's full URL.
 * @param {Object}     init As fetch's: method, headers, body.
 * @return {Promise<Object>} Something shaped like a Response.
 */
export function request( url, init ) {
	init = init || {};

	return apiFetch( {
		url: String( url ),
		method: init.method || 'GET',
		headers: init.headers || {},
		body: init.body,
		parse: true,
	} ).then(
		( json ) => answer( true, 200, json, false ),
		( error ) => {
			if (
				! error ||
				typeof error !== 'object' ||
				error.name === 'AbortError' ||
				error.code === 'fetch_error' ||
				error.code === 'offline_error'
			) {
				throw error instanceof Error
					? error
					: new Error( ( error && error.message ) || '' );
			}

			return answer(
				false,
				( error.data && error.data.status ) || 400,
				error,
				error.code === 'invalid_json'
			);
		}
	);
}

/**
 * An apiFetch answer, shaped like a fetch Response.
 *
 * @param {boolean} ok         Whether it succeeded.
 * @param {number}  status     The HTTP status.
 * @param {*}       json       The decoded body.
 * @param {boolean} unreadable Whether the body was not JSON.
 * @return {Object} The answer.
 */
export function answer( ok, status, json, unreadable ) {
	return {
		ok,
		status,
		json() {
			return unreadable
				? Promise.reject(
						new SyntaxError( ( json && json.message ) || '' )
					)
				: Promise.resolve( json );
		},
	};
}
