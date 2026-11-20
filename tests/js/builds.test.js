/**
 * Two plugins' copies of the kit on one page: each publishes its modules
 * into its own build's entry, and neither writes a page global the other
 * would overwrite.
 */

import { afterAll, expect, test, vi } from 'vitest';

vi.mock( '@wordpress/api-fetch', () => ( {
	default: () => Promise.resolve( {} ),
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
} );

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
