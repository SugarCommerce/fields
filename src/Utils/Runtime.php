<?php
/**
 * Runtime Key Derivation
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.1.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Utils;

/**
 * Every runtime string this library registers, derived from its own namespace.
 *
 * Strauss rewrites class namespaces and leaves string literals alone. Two
 * plugins each bundling a prefixed copy of this library therefore get
 * distinct classes but would otherwise register the same REST routes and the
 * same script handles.
 *
 * That is not merely untidy, and neither failure announces itself:
 *
 * - `WP_REST_Server::register_route()` keys endpoints by route path and, with
 *   no `$override`, merges a second registration into the first with
 *   `array_merge()`. The handler list is numerically indexed, so handlers are
 *   appended rather than replaced and dispatch runs the first whose methods
 *   match. The plugin that registered first answers the other's requests --
 *   resolving the source or action against its own registry, and checking
 *   that registry's capability. The second plugin's own sources come back as
 *   404 "unknown source" on an endpoint that is plainly there.
 * - `wp_enqueue_script()` ignores a handle that is already registered, so the
 *   plugin that enqueued second gets the other plugin's JavaScript -- from a
 *   build whose field types may not be the ones its markup expects.
 *
 * The derivation exploits the one thing Strauss does rewrite: this file's
 * namespace. In a prefixed build `__NAMESPACE__` begins with the consumer's
 * prefix ("MyPlugin\SugarCommerce\Fields\Utils"), unique per plugin by
 * construction, so every key comes out distinct with no configuration.
 */
final class Runtime {

	/**
	 * This library's own identifier, used when running unprefixed.
	 */
	private const LIBRARY = 'sugarcommerce-fields';

	/**
	 * How many segments this class's own namespace has --
	 * SugarCommerce\Fields\Utils -- before any prefix. Counted rather than
	 * written out: Strauss rewrites a namespace it finds in a string, and
	 * would prefix the very thing being compared against.
	 */
	private const OWN_SEGMENTS = 3;

	/**
	 * The per-build prefix.
	 *
	 * "sugarcommerce-fields" for a plain Composer install -- development, or a single
	 * consumer that does not use Strauss -- and "{prefix}-sugarcommerce-fields" for a
	 * prefixed build.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		return self::prefix_for( __NAMESPACE__ );
	}

	/**
	 * The prefix a given namespace would produce.
	 *
	 * Split out so the rule can be tested. prefix() reads __NAMESPACE__,
	 * which cannot be changed at runtime, so a test can only reach the
	 * prefixed case by asking the rule directly — this takes the namespace
	 * as an argument, and prefix() hands it __NAMESPACE__.
	 *
	 * The alternative, compiling a copy of this file under another namespace
	 * at runtime, was tried and abandoned: the interpreter that does it
	 * refuses the `declare( strict_types=1 )` at the top of this file on PHP
	 * 8.3 and 8.4, so the test passed on 8.5 alone and reported the local PHP
	 * version rather than the code. It is also a construct that has no place
	 * in a file that ships inside somebody's plugin.
	 *
	 * @param string $under The namespace this class is compiled under, prefixed or not.
	 *
	 * @return string
	 */
	public static function prefix_for( string $under ): string {
		$build = self::build_for( $under );

		return '' === $build ? self::LIBRARY : $build . '-' . self::LIBRARY;
	}

	/**
	 * The name of the build a namespace belongs to, as a hyphenated slug.
	 *
	 * Empty when the namespace is this package's own, unprefixed. Otherwise
	 * what the build put around it, in one of two shapes:
	 *
	 * - A prefix in front of the whole namespace,
	 *   "MyShop\SugarCommerce\…\Utils": the prefix's first segment, as it
	 *   always was ("SugarCart\Vendor" is "sugarcart"), or all of it when it
	 *   starts "SugarCommerce" too -- a family plugin's
	 *   "SugarCommerce\Playground\Vendor" would otherwise be taken for the
	 *   library itself.
	 * - A plugin's own namespace with the vendor segment dropped,
	 *   "ArrayPress\EDD\FraudFilter\Lib\…\Utils": every segment before "Lib".
	 *   The first alone is the vendor every such plugin shares, and two of
	 *   them would answer each other's routes and load each other's scripts.
	 *
	 * @param string $under The namespace this class is compiled under, prefixed or not.
	 *
	 * @return string
	 */
	public static function build_for( string $under ): string {
		$under = array_values( array_filter( explode( '\\', $under ), 'strlen' ) );

		// Everything in front of this class's own segments after the vendor
		// one ("…\Utils" and the package segment before it).
		$segments = array_slice( $under, 0, -( self::OWN_SEGMENTS - 1 ) );
		$last     = end( $segments );

		if ( 'SugarCommerce' === $last ) {
			array_pop( $segments );

			if ( [] !== $segments && 'SugarCommerce' !== $segments[0] ) {
				$segments = [ $segments[0] ];
			}
		} elseif ( 'Lib' === $last ) {
			array_pop( $segments );
		}

		return strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', implode( '-', $segments ) ), '-' ) );
	}

	/**
	 * Get the REST namespace for this build.
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return self::prefix() . '/v1';
	}

	/**
	 * Get a script or style handle for this build.
	 *
	 * @param string $suffix Optional handle suffix.
	 *
	 * @return string
	 */
	public static function handle( string $suffix = '' ): string {
		return '' === $suffix ? self::prefix() : self::prefix() . '-' . $suffix;
	}

	/**
	 * Get an option, transient or nonce key for this build.
	 *
	 * @param string $suffix Optional key suffix.
	 *
	 * @return string
	 */
	public static function key( string $suffix = '' ): string {
		$base = str_replace( '-', '_', self::prefix() );

		return '' === $suffix ? $base : $base . '_' . $suffix;
	}

	/**
	 * Get the JavaScript object name for this build.
	 *
	 * `wp_localize_script()` writes `var <name> = {...}`, so this has to be a
	 * valid JS identifier — hyphens are not allowed.
	 *
	 * @param string $suffix Optional name suffix.
	 *
	 * @return string
	 */
	public static function js_object( string $suffix = '' ): string {
		$parts = preg_split( '/[^A-Za-z0-9]+/', self::prefix(), -1, PREG_SPLIT_NO_EMPTY ) ?: [];
		$name  = implode( '', array_map( 'ucfirst', $parts ) );

		return $name . $suffix;
	}

	/**
	 * Get a hook name for this build.
	 *
	 * @param string $suffix Hook suffix.
	 *
	 * @return string
	 */
	public static function hook( string $suffix ): string {
		return self::key() . '_' . $suffix;
	}
}
