<?php
/**
 * Action Payload
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Actions;

/**
 * What a button sent, made safe to hand to a handler.
 *
 * Its own class so that the rule can be read, and tested, without a REST
 * request to carry it.
 */
final class Payload {

	/**
	 * Reduce a payload to scalars and flat arrays of scalars.
	 *
	 * A handler decides what it trusts, but nothing nested or object-shaped
	 * reaches one from here, and nothing is markup unless the action said
	 * that input is.
	 *
	 * An input named in `$rich` keeps the markup a post may have and loses
	 * the rest -- a script, an event handler -- which is what the same
	 * input goes through when the form it sits in is saved. Every other
	 * input is a line of plain text.
	 *
	 * @param array<string, mixed> $payload Raw payload.
	 * @param string[]             $rich    The keys that carry markup.
	 *
	 * @return array<string, scalar|array<int, scalar>>
	 */
	public static function clean( array $payload, array $rich = [] ): array {
		$clean = [];

		foreach ( $payload as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_values(
					array_map(
						static fn( $item ) => sanitize_text_field( (string) $item ),
						array_filter( $value, 'is_scalar' )
					)
				);
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = in_array( $key, $rich, true )
				? wp_kses_post( (string) $value )
				: sanitize_text_field( (string) $value );
		}

		return $clean;
	}
}
