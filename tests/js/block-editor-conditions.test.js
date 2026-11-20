/**
 * The sidebar's conditions, read the way PHP reads the metabox's: a field
 * shown in one place and hidden in the other is the failure.
 */

import { describe, expect, test } from 'vitest';

import {
	holds,
	isNumeric,
	looseEquals,
	shown,
	truthy,
} from '../../assets/js/block-editor/conditions';

describe( 'looseEquals', () => {
	test.each( [
		[ true, 1, true ],
		[ true, '1', true ],
		[ false, 0, true ],
		[ false, '0', true ],
		[ false, '', true ],
		[ true, 0, false ],
		[ '5', 5, true ],
		[ '5.0', 5, true ],
		[ '05', 5, true ],
		[ 'a', 'a', true ],
		[ 'a', 'b', false ],
		[ null, '', true ],
		[ undefined, 0, true ],
		[ null, 'x', false ],
		[ 'x', undefined, false ],
		[ ' ', 0, false ],
	] )( '%j == %j is %s', ( a, b, equal ) => {
		expect( looseEquals( a, b ) ).toBe( equal );
		expect( looseEquals( b, a ) ).toBe( equal );
	} );
} );

describe( 'truthy and isNumeric', () => {
	test( 'empty() as PHP has it', () => {
		expect( truthy( '0' ) ).toBe( false );
		expect( truthy( '' ) ).toBe( false );
		expect( truthy( [] ) ).toBe( false );
		expect( truthy( [ 0 ] ) ).toBe( true );
		expect( truthy( 'a' ) ).toBe( true );
		expect( truthy( 1 ) ).toBe( true );
	} );

	test( 'a number, or a string that is one', () => {
		expect( isNumeric( 1.5 ) ).toBe( true );
		expect( isNumeric( '1.5' ) ).toBe( true );
		expect( isNumeric( ' ' ) ).toBe( false );
		expect( isNumeric( 'x' ) ).toBe( false );
		expect( isNumeric( Infinity ) ).toBe( false );
	} );
} );

describe( 'holds', () => {
	test.each( [
		[ '=', 'a', 'a', true ],
		[ undefined, 'a', 'a', true ],
		[ '!=', 'a', 'a', false ],
		[ '!=', 'a', 'b', true ],
		[ '>', 5, '3', true ],
		[ '>', 3, 3, false ],
		[ '>=', 3, 3, true ],
		[ '<', 2, 3, true ],
		[ '<', 3, 3, false ],
		[ '<=', 3, 3, true ],
		[ 'in', 'b', [ 'a', 'b' ], true ],
		[ 'in', '2', [ 1, 2 ], true ],
		[ 'in', 'c', [ 'a', 'b' ], false ],
		[ 'in', 'a', 'a', true ],
		[ 'not_in', 'c', [ 'a', 'b' ], true ],
		[ 'not_in', 'a', [ 'a', 'b' ], false ],
		[ 'contains', 'apple pie', 'pie', true ],
		[ 'contains', 'apple', 'pie', false ],
		[ 'contains', 'apple', '', false ],
		[ 'contains', 5, '5', false ],
		[ 'not_contains', 'apple', 'pie', true ],
		[ 'not_contains', 'apple pie', 'pie', false ],
		[ 'not_contains', 5, '5', true ],
		[ 'not_contains', 'apple', '', true ],
		[ 'empty', '0', null, true ],
		[ 'empty', 'x', null, false ],
		[ 'not_empty', 'x', null, true ],
		[ 'not_empty', '', null, false ],
	] )( '%s: %j against %j is %s', ( operator, current, value, result ) => {
		expect( holds( { operator, value }, current ) ).toBe( result );
	} );
} );

describe( 'shown', () => {
	const field = {
		conditions: [
			{ field: 'featured', operator: '=', value: true },
			{ field: 'price', operator: '>', value: 10 },
		],
	};

	test( 'every condition has to hold', () => {
		expect( shown( field, { featured: 1, price: '11' } ) ).toBe( true );
		expect( shown( field, { featured: 1, price: '10' } ) ).toBe( false );
		expect( shown( field, { featured: 0, price: '11' } ) ).toBe( false );
	} );

	test( 'a field with no conditions is always shown', () => {
		expect( shown( {}, {} ) ).toBe( true );
		expect( shown( { conditions: [] }, {} ) ).toBe( true );
	} );
} );
