/**
 * Whether a field in the sidebar is shown: the metabox's conditions,
 * written for PHP, read the way PHP would read them.
 */

/**
 * Equality the way PHP's == has it, near enough for a condition.
 *
 * The conditions are the metabox's, written for PHP: `true` matches a
 * ticked box stored as 1, and "5" matches 5. Strict equality would leave
 * the same field shown in one place and hidden in the other.
 *
 * @param {*} a One value.
 * @param {*} b The other.
 * @return {boolean} Whether PHP would call them equal.
 */
export function looseEquals( a, b ) {
	if ( typeof a === 'boolean' || typeof b === 'boolean' ) {
		return truthy( a ) === truthy( b );
	}

	if ( a === null || a === undefined || b === null || b === undefined ) {
		return ! truthy( a ) && ! truthy( b );
	}

	if ( isNumeric( a ) && isNumeric( b ) ) {
		return Number( a ) === Number( b );
	}

	return String( a ) === String( b );
}

/**
 * Whether a value is a number, or a string that is one.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is.
 */
export function isNumeric( value ) {
	return (
		( typeof value === 'number' && isFinite( value ) ) ||
		( typeof value === 'string' &&
			value.trim() !== '' &&
			isFinite( Number( value ) ) )
	);
}

/**
 * Truthiness as PHP's empty() sees it: "0" is empty.
 *
 * @param {*} value The value.
 * @return {boolean} Whether it is not empty.
 */
export function truthy( value ) {
	if ( Array.isArray( value ) ) {
		return value.length > 0;
	}

	return !! value && value !== '0';
}

/**
 * Whether one condition holds, by the operators Conditions knows.
 *
 * @param {Object} condition The condition: field, operator, value.
 * @param {*}      current   The named field's value.
 * @return {boolean} Whether it holds.
 */
export function holds( condition, current ) {
	const expected = condition.value;
	const list = Array.isArray( expected ) ? expected : [ expected ];

	switch ( condition.operator ) {
		case '!=':
			return ! looseEquals( current, expected );
		case '>':
			return Number( current ) > Number( expected );
		case '>=':
			return Number( current ) >= Number( expected );
		case '<':
			return Number( current ) < Number( expected );
		case '<=':
			return Number( current ) <= Number( expected );
		case 'in':
			return list.some( ( one ) => looseEquals( current, one ) );
		case 'not_in':
			return ! list.some( ( one ) => looseEquals( current, one ) );
		case 'contains':
			return (
				typeof current === 'string' &&
				String( expected ) !== '' &&
				current.indexOf( String( expected ) ) !== -1
			);
		case 'not_contains':
			return (
				typeof current !== 'string' ||
				String( expected ) === '' ||
				current.indexOf( String( expected ) ) === -1
			);
		case 'empty':
			return ! truthy( current );
		case 'not_empty':
			return truthy( current );
		default:
			return looseEquals( current, expected );
	}
}

/**
 * Whether a field is shown, given the post's meta.
 *
 * @param {Object} field The field, as PHP described it.
 * @param {Object} meta  The post's meta, as the editor holds it.
 * @return {boolean} Whether every one of its conditions holds.
 */
export function shown( field, meta ) {
	return ( field.conditions || [] ).every( ( condition ) =>
		holds( condition, meta[ condition.field ] )
	);
}
