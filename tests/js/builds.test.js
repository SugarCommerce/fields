/**
 * Two plugins' copies of the kit on one page: each publishes its modules
 * into its own build's entry, neither writes a page global the other would
 * overwrite, and each drives only the controls its own build drew.
 */

import { afterAll, describe, expect, test, vi } from 'vitest';

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

/**
 * A password field written by hand, as a plugin might, with no stamp of its
 * own: `<span class="field-kit__password">` and its reveal button.
 *
 * @param {string} id The reveal button's id.
 * @return {string} Markup.
 */
const handWritten = ( id ) =>
	`<span class="field-kit__password">
		<input type="password" value="secret">
		<button type="button" class="field-kit__password-toggle" id="${ id }"></button>
	</span>`;

describe( 'markup a plugin wrote by hand', () => {
	const builds = [ 'one-sugarcommerce-fields', 'two-sugarcommerce-fields' ];

	/**
	 * Load both builds, the first first, and start both on the document.
	 *
	 * @param {string} html The page.
	 * @return {Promise<Object[]>} Each build's modules, in load order.
	 */
	async function page( html ) {
		window.SugarCommerceFields = {};
		builds.forEach( ( build ) => {
			window.SugarCommerceFields[ build ] = {
				i18n: { hidePassword: 'Hide (' + build + ')' },
			};
		} );

		document.body.innerHTML = html;

		for ( const build of builds ) {
			await loadBuild( build );
		}

		return builds.map( ( build ) => {
			const modules = window.SugarCommerceFields[ build ].modules;

			modules.init( document );

			return modules;
		} );
	}

	test( 'unstamped, no copy takes it -- not even the one that runs first', async () => {
		await page( handWritten( 'loose' ) );

		const reveal = document.getElementById( 'loose' );

		reveal.click();

		expect( reveal.hasAttribute( 'aria-pressed' ) ).toBe( false );
		expect( reveal.dataset.fkBound ).toBeUndefined();
	} );

	test( 'stamped for the second copy, the second copy drives it, though the first ran first', async () => {
		await page(
			`<div data-field-kit-build="two-sugarcommerce-fields">${ handWritten(
				'claimed'
			) }</div>`
		);

		const reveal = document.getElementById( 'claimed' );

		reveal.click();

		expect( reveal.getAttribute( 'aria-label' ) ).toBe(
			'Hide (two-sugarcommerce-fields)'
		);
	} );

	test( 'one stamp on a wrapper claims what is inside; a field the other copy drew keeps its own', async () => {
		await page(
			`<div class="wrap" data-field-kit-build="one-sugarcommerce-fields">
				${ handWritten( 'mine' ) }
				<div data-field-kit-build="two-sugarcommerce-fields">${ handWritten(
					'theirs'
				) }</div>
			</div>`
		);

		document.getElementById( 'mine' ).click();
		document.getElementById( 'theirs' ).click();

		expect(
			document.getElementById( 'mine' ).getAttribute( 'aria-label' )
		).toBe( 'Hide (one-sugarcommerce-fields)' );
		expect(
			document.getElementById( 'theirs' ).getAttribute( 'aria-label' )
		).toBe( 'Hide (two-sugarcommerce-fields)' );
	} );

	test( 'claim() stamps markup a script built for that build alone', async () => {
		const [ , two ] = await page( '' );
		const holder = document.createElement( 'div' );

		holder.innerHTML = handWritten( 'built' );
		document.body.appendChild( holder );

		expect( two.claim( holder ) ).toBe( holder );
		expect( holder.getAttribute( 'data-field-kit-build' ) ).toBe(
			'two-sugarcommerce-fields'
		);

		window.SugarCommerceFields[ builds[ 0 ] ].modules.init( holder );
		two.init( holder );
		document.getElementById( 'built' ).click();

		expect(
			document.getElementById( 'built' ).getAttribute( 'aria-label' )
		).toBe( 'Hide (two-sugarcommerce-fields)' );
	} );

	test( 'a guard a build asked for by selector watches the form, stamp or none', async () => {
		window.SugarCommerceFields = {};
		document.body.innerHTML =
			'<form class="core-form"><input name="title" value="a"></form>' +
			'<form data-fk-guard><input name="loose" value="a"></form>';

		window.SugarCommerceFields[ builds[ 0 ] ] = { guard: [ '.core-form' ] };
		await loadBuild( builds[ 0 ] );

		window.SugarCommerceFields[ builds[ 0 ] ].modules.init( document );

		expect( document.querySelector( '.core-form' ).fkGuard ).toBeDefined();
		expect(
			document.querySelector( '[data-fk-guard]' ).fkGuard
		).toBeUndefined();

		// Started on the form itself, as a panel's content is.
		const late = document.createElement( 'form' );

		late.className = 'core-form';
		late.innerHTML = '<input name="late" value="a">';
		document.body.appendChild( late );
		window.SugarCommerceFields[ builds[ 0 ] ].modules.init( late );

		expect( late.fkGuard ).toBeDefined();
	} );
} );
