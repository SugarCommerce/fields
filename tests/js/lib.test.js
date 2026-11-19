/**
 * The shared modules: finding this build's configuration, requests through
 * apiFetch, the lookup routes' addresses, and the small DOM helpers.
 */

import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';

const api = vi.hoisted( () => ( { calls: [], reply: null } ) );

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options ) => {
		api.calls.push( options );

		return api.reply( options );
	},
} ) );

/**
 * The modules, loaded fresh as a page with this script element and this
 * registry would load them.
 *
 * @param {string|null} id       The script element's id, or null for none.
 * @param {Object}      registry window.SugarCommerceFields.
 * @param {Object}      [legacy] window.FieldKit.
 * @return {Promise<Object>} The config module.
 */
async function load( id, registry, legacy ) {
	vi.resetModules();
	Object.defineProperty( document, 'currentScript', {
		value: id ? { id } : null,
		configurable: true,
	} );
	window.SugarCommerceFields = registry;
	window.FieldKit = legacy;

	return import( '../../src/js/lib/config' );
}

afterEach( () => {
	delete window.SugarCommerceFields;
	delete window.FieldKit;
	api.calls.length = 0;
} );

describe( 'config', () => {
	test( "reads this build's entry by its script's handle", async () => {
		const mine = {
			restUrl: 'https://a.test/',
			i18n: { copied: 'Kopiert.' },
		};
		const { config, t } = await load( 'prefixed-sugarcommerce-fields-js', {
			'sugarcommerce-fields': { restUrl: 'https://other.test/' },
			'prefixed-sugarcommerce-fields': mine,
		} );

		expect( config ).toBe( mine );
		expect( t( 'copied', 'Copied.' ) ).toBe( 'Kopiert.' );
		expect( t( 'missing', 'English.' ) ).toBe( 'English.' );
	} );

	test( 'falls back to a bare FieldKit, then to nothing', async () => {
		const legacy = { restUrl: 'https://legacy.test/' };

		expect( ( await load( 'unknown-js', {}, legacy ) ).config ).toBe(
			legacy
		);
		expect( ( await load( null, undefined ) ).config ).toEqual( {} );
	} );

	test( 'what a screen adds later lands in the same object', async () => {
		const entry = {};
		const { config } = await load( 'sugarcommerce-fields-js', {
			'sugarcommerce-fields': entry,
		} );

		entry.codeEditors = { 'text/css': {} };

		expect( config.codeEditors ).toEqual( { 'text/css': {} } );
	} );

	test( "icon() hands back the server's markup, or nothing", async () => {
		const { icon } = await load( 'sugarcommerce-fields-js', {
			'sugarcommerce-fields': { icons: { close: '<svg/>' } },
		} );

		expect( icon( 'close' ) ).toBe( '<svg/>' );
		expect( icon( 'nope' ) ).toBe( '' );
	} );

	test( 'resolve() takes the handle from the id, and only the id', async () => {
		const { resolve } = await load( null, {} );

		expect( resolve( { id: 'a-js' }, { a: 1 } ) ).toBe( 1 );
		expect( resolve( { id: 'a' }, { a: 2 } ) ).toBe( 2 );
		expect( resolve( { id: 'a-jsx' }, { a: 3 } ) ).toBeUndefined();
		expect( resolve( null, { '': 4 } ) ).toBe( 4 );
		expect( resolve( { id: 'a-js' }, null ) ).toBeUndefined();
	} );
} );

describe( 'request', () => {
	let request;

	beforeEach( async () => {
		await load( 'sugarcommerce-fields-js', {} );
		( { request } = await import( '../../src/js/lib/request' ) );
	} );

	test( 'asks apiFetch, keeping the method, headers and body', async () => {
		api.reply = () => Promise.resolve( { items: [ 1 ] } );

		const answer = await request( new URL( 'https://a.test/x?y=1' ), {
			method: 'POST',
			headers: { 'X-WP-Nonce': 'n' },
			body: '{}',
		} );

		expect( api.calls[ 0 ] ).toEqual( {
			url: 'https://a.test/x?y=1',
			method: 'POST',
			headers: { 'X-WP-Nonce': 'n' },
			body: '{}',
			parse: true,
		} );
		expect( answer.ok ).toBe( true );
		expect( answer.status ).toBe( 200 );
		expect( await answer.json() ).toEqual( { items: [ 1 ] } );
	} );

	test( 'a GET with nothing else is a GET', async () => {
		api.reply = () => Promise.resolve( {} );

		await request( 'https://a.test/x' );

		expect( api.calls[ 0 ] ).toMatchObject( {
			method: 'GET',
			headers: {},
			body: undefined,
		} );
	} );

	test( 'a refusal reads like a fetch Response that was not ok', async () => {
		const error = {
			code: 'rest_forbidden',
			message: 'No.',
			data: { status: 403 },
		};

		api.reply = () => Promise.reject( error );

		const answer = await request( 'https://a.test/x' );

		expect( answer.ok ).toBe( false );
		expect( answer.status ).toBe( 403 );
		expect( await answer.json() ).toBe( error );
	} );

	test( 'a refusal without a status is a 400', async () => {
		api.reply = () => Promise.reject( { code: 'x', message: 'No.' } );

		expect( ( await request( 'https://a.test/x' ) ).status ).toBe( 400 );
	} );

	test( 'a body that was not JSON fails to parse, as fetch would', async () => {
		api.reply = () =>
			Promise.reject( { code: 'invalid_json', message: 'Not JSON.' } );

		const answer = await request( 'https://a.test/x' );

		await expect( answer.json() ).rejects.toThrow( SyntaxError );
	} );

	test.each( [
		[ { name: 'AbortError', message: 'aborted' } ],
		[ { code: 'fetch_error', message: 'offline' } ],
		[ { code: 'offline_error', message: 'offline' } ],
	] )( 'a request that never arrived throws (%o)', async ( error ) => {
		api.reply = () => Promise.reject( error );

		await expect( request( 'https://a.test/x' ) ).rejects.toThrow(
			error.message
		);
	} );

	test( 'what is not an object throws an Error', async () => {
		api.reply = () => Promise.reject( 'boom' );

		await expect( request( 'https://a.test/x' ) ).rejects.toBeInstanceOf(
			Error
		);

		// An abort keeps the error it was.
		const thrown = Object.assign( new Error( 'stopped' ), {
			name: 'AbortError',
		} );

		api.reply = () => Promise.reject( thrown );

		await expect( request( 'https://a.test/x' ) ).rejects.toBe( thrown );
	} );
} );

describe( 'lookups', () => {
	test( "endpoint() builds under the kit's REST root, with a query", async () => {
		await load( 'sugarcommerce-fields-js', {
			'sugarcommerce-fields': {
				restUrl: 'https://a.test/wp-json/sugarcommerce-fields/v1/',
			},
		} );

		const { endpoint } = await import( '../../src/js/lib/lookups' );
		const url = endpoint( 'embed', {
			url: 'https://v.test/1',
			platforms: [ 'youtube', 'vimeo' ],
		} );

		expect( url.pathname ).toBe( '/wp-json/sugarcommerce-fields/v1/embed' );
		expect( url.searchParams.get( 'url' ) ).toBe( 'https://v.test/1' );
		expect( url.searchParams.getAll( 'platforms[]' ) ).toEqual( [
			'youtube',
			'vimeo',
		] );
	} );

	test( 'endpoint() keeps a plain-permalinks route ahead of its query', async () => {
		await load( 'sugarcommerce-fields-js', {
			'sugarcommerce-fields': {
				restUrl: 'https://a.test/?rest_route=/sugarcommerce-fields/v1/',
			},
		} );

		const { endpoint } = await import( '../../src/js/lib/lookups' );
		const url = endpoint( 'slug', { slug: 'a' } );

		expect( url.searchParams.get( 'rest_route' ) ).toBe(
			'/sugarcommerce-fields/v1/slug'
		);
		expect( url.searchParams.get( 'slug' ) ).toBe( 'a' );
	} );

	test( 'endpoint() is null without a REST root', async () => {
		await load( 'sugarcommerce-fields-js', {} );

		const { endpoint } = await import( '../../src/js/lib/lookups' );

		expect( endpoint( 'slug', {} ) ).toBeNull();
	} );

	test( 'ask() sends the nonce and reads the answer whatever the status', async () => {
		await load( 'sugarcommerce-fields-js', {
			'sugarcommerce-fields': { restNonce: 'abc' },
		} );

		const { ask } = await import( '../../src/js/lib/lookups' );

		api.reply = () => Promise.resolve( null );

		expect( await ask( 'https://a.test/x', { method: 'POST' } ) ).toEqual( {
			ok: true,
			json: {},
		} );
		expect( api.calls[ 0 ] ).toMatchObject( {
			method: 'POST',
			headers: { 'X-WP-Nonce': 'abc' },
		} );

		api.reply = () =>
			Promise.reject( { code: 'nope', data: { status: 422 } } );

		expect( await ask( 'https://a.test/x' ) ).toEqual( {
			ok: false,
			json: { code: 'nope', data: { status: 422 } },
		} );

		api.reply = () => Promise.reject( { code: 'invalid_json' } );

		expect( await ask( 'https://a.test/x' ) ).toEqual( {
			ok: false,
			json: {},
		} );
	} );

	test( 'fieldOf() is the closest field wrapper', async () => {
		await load( 'sugarcommerce-fields-js', {} );

		const { fieldOf } = await import( '../../src/js/lib/lookups' );

		document.body.innerHTML =
			'<div data-field-key="a"><span><input></span></div><input id="loose">';

		expect(
			fieldOf( document.querySelector( 'span input' ) ).dataset.fieldKey
		).toBe( 'a' );
		expect( fieldOf( document.getElementById( 'loose' ) ) ).toBeNull();
		expect( fieldOf( null ) ).toBeNull();
	} );
} );

describe( 'dom', () => {
	test( 'bound() marks a root once per module', async () => {
		const { bound } = await import( '../../src/js/lib/dom' );
		const root = document.createElement( 'div' );

		expect( bound( root, 'A' ) ).toBe( false );
		expect( bound( root, 'A' ) ).toBe( true );
		expect( bound( root, 'B' ) ).toBe( false );
	} );

	test( 'swapIcon() replaces an icon with the configured one', async () => {
		await load( 'sugarcommerce-fields-js', {
			'sugarcommerce-fields': {
				icons: { hide: '<svg data-icon="hide"></svg>' },
			},
		} );

		const { swapIcon } = await import( '../../src/js/lib/dom' );
		const holder = document.createElement( 'span' );

		holder.innerHTML = '<svg data-icon="show"></svg>';

		const next = swapIcon( holder.firstElementChild, 'hide' );

		expect( holder.innerHTML ).toBe( '<svg data-icon="hide"></svg>' );
		expect( next ).toBe( holder.firstElementChild );

		// No such icon, or nowhere to put it: left as it was.
		expect( swapIcon( next, 'nope' ) ).toBe( next );
		expect( swapIcon( null, 'hide' ) ).toBeNull();
	} );

	test( 'announce() clears the region first, then says it', async () => {
		vi.useFakeTimers();

		const { announce } = await import( '../../src/js/lib/dom' );
		const region = document.createElement( 'div' );

		region.textContent = 'Saved.';
		announce( region, 'Saved.' );

		expect( region.textContent ).toBe( '' );

		vi.advanceTimersByTime( 50 );

		expect( region.textContent ).toBe( 'Saved.' );
		expect( () => announce( null, 'x' ) ).not.toThrow();

		vi.useRealTimers();
	} );

	test( 'sibling() finds the row’s own control, then the form’s, by its key', async () => {
		const { sibling } = await import( '../../src/js/lib/dom' );

		document.body.innerHTML = `
			<form>
				<select name="rates[0][country]"></select>
				<div class="field-kit__repeater-row">
					<select name="rows[1][country]"></select>
					<div id="row-region"><input name="rows[1][region]"></div>
				</div>
				<select name="address[country]"></select>
				<div id="group-region"><input name="address[region]"></div>
				<select name="country"></select>
				<div id="bare-region"><input name="region"></div>
			</form>`;

		const name = ( id ) =>
			sibling( document.getElementById( id ), 'country' ).name;

		expect( name( 'row-region' ) ).toBe( 'rows[1][country]' );
		expect( name( 'group-region' ) ).toBe( 'address[country]' );
		expect( name( 'bare-region' ) ).toBe( 'country' );
		expect(
			sibling( document.getElementById( 'bare-region' ), '' )
		).toBeNull();
	} );
} );
