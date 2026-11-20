/**
 * Two plugins' copies of the kit on one page: each publishes its modules
 * into its own build's entry, neither writes a page global the other would
 * overwrite, and each drives only the controls its own build drew.
 */

import { afterAll, expect, test, vi } from 'vitest';

const sent = vi.hoisted( () => [] );

vi.mock( '@wordpress/api-fetch', () => ( {
	default: ( options ) => {
		sent.push( options );

		return Promise.resolve( {} );
	},
} ) );

/**
 * Run the kit's script as WordPress prints one build's copy of it.
 *
 * @param {string} handle The build's script handle.
 * @return {Promise<void>} Once it has run.
 */
async function loadBuild( handle ) {
	vi.resetModules();
	Object.defineProperty( document, 'currentScript', {
		value: { id: handle + '-js' },
		configurable: true,
	} );

	await import( '../../assets/js/field-kit.js' );
}

afterAll( () => {
	delete window.SugarCommerceFields;
	document.body.innerHTML = '';
} );

/**
 * A field as one build's Renderer draws it: a password with its reveal
 * button, and an action button that takes its nonce from the build.
 *
 * @param {string} build The build's handle.
 * @return {string} Markup.
 */
const field = ( build ) =>
	`<div class="field-kit__field" data-field-kit-build="${ build }">
		<span class="field-kit__password">
			<input type="password" value="secret">
			<button type="button" class="field-kit__password-toggle" id="${ build }-reveal"></button>
		</span>
		<button type="button" id="${ build }-action" data-endpoint="https://example.test/${ build }/action" data-action="check"></button>
	</div>`;

test( 'each build publishes its own modules in its own entry, and no page global', async () => {
	window.SugarCommerceFields = {
		'sugarcommerce-fields': { restUrl: 'https://a.test/' },
		'other-sugarcommerce-fields': { restUrl: 'https://b.test/' },
	};

	const before = new Set( Object.keys( window ) );

	await loadBuild( 'sugarcommerce-fields' );
	await loadBuild( 'other-sugarcommerce-fields' );

	const plain = window.SugarCommerceFields[ 'sugarcommerce-fields' ];
	const other = window.SugarCommerceFields[ 'other-sugarcommerce-fields' ];

	expect( typeof plain.modules.init ).toBe( 'function' );
	expect( typeof other.modules.init ).toBe( 'function' );
	expect( plain.modules ).not.toBe( other.modules );
	expect( plain.modules.config.restUrl ).toBe( 'https://a.test/' );
	expect( other.modules.config.restUrl ).toBe( 'https://b.test/' );

	// Nothing new on the window: the registry was already there.
	const added = Object.keys( window ).filter(
		( key ) => ! before.has( key )
	);

	expect( added ).toEqual( [] );
	expect( window.SugarCommerceFieldsModules ).toBeUndefined();
} );

test( 'each build drives only the controls its own build drew', async () => {
	// Not the builds above: those copies are still listening on this
	// document, as they would be on a page.
	const builds = [ 'shop-sugarcommerce-fields', 'blog-sugarcommerce-fields' ];

	window.SugarCommerceFields = {};
	builds.forEach( ( build ) => {
		window.SugarCommerceFields[ build ] = {
			restNonce: build + '-nonce',
			i18n: { hidePassword: 'Hide (' + build + ')' },
		};
	} );

	document.body.innerHTML = builds.map( field ).join( '' );

	for ( const build of builds ) {
		await loadBuild( build );
	}

	// As a page does: started at DOMContentLoaded and again at load, by
	// both copies.
	builds.forEach( ( build ) => {
		window.SugarCommerceFields[ build ].modules.init( document );
		window.SugarCommerceFields[ build ].modules.init( document );
	} );

	builds.forEach( ( build ) => {
		const reveal = document.getElementById( build + '-reveal' );

		// Bound once, by its own build: a second binding would show and
		// hide again in the same click.
		reveal.click();

		expect( reveal.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		expect( reveal.getAttribute( 'aria-label' ) ).toBe(
			'Hide (' + build + ')'
		);
	} );

	builds.forEach( ( build ) => {
		sent.length = 0;
		document.getElementById( build + '-action' ).click();

		// Listened for on the document by both copies: answered once, by
		// the build that drew it, with its own nonce.
		expect( sent ).toHaveLength( 1 );
		expect( sent[ 0 ].headers[ 'X-WP-Nonce' ] ).toBe( build + '-nonce' );
	} );
} );
