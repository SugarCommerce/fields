<?php
/**
 * Field Dependencies
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.6.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Support;

use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Types\FlexibleType;

/**
 * What a list of field configurations needs loaded on the page: script and
 * style handles, the code editor languages, and whether a visual editor is
 * among them.
 *
 * Read from the configuration, nested fields included. A field set asked
 * only its top-level fields, so a code field inside a group, or an image
 * inside a repeater's rows, never had its editor or its media frame
 * enqueued: the field rendered and its control did nothing. And a flyout,
 * which renders its fields over REST after the page has loaded, asked its
 * own copy of the same question and dropped the code editors altogether.
 * One walk, used by both.
 *
 * @since 1.6.0
 */
final class Dependencies {

	/**
	 * Everything a set of fields needs.
	 *
	 * @param array<array-key, mixed> $configs  Field configuration, keyed by field.
	 * @param Registry|null           $registry The type registry.
	 *
	 * @return array{scripts: string[], styles: string[], code_editors: string[], editor: bool}
	 */
	public static function of( array $configs, ?Registry $registry = null ): array {
		$registry = $registry ?? new Registry();
		$needs    = [
			'scripts'      => [],
			'styles'       => [],
			'code_editors' => [],
			'editor'       => false,
		];

		self::walk( $configs, $registry, $needs );

		return [
			'scripts'      => array_values( array_unique( $needs['scripts'] ) ),
			'styles'       => array_values( array_unique( $needs['styles'] ) ),
			'code_editors' => array_values( array_unique( $needs['code_editors'] ) ),
			'editor'       => $needs['editor'],
		];
	}

	/**
	 * Add one level of fields, and every level inside it.
	 *
	 * @param array<array-key, mixed> $configs  Field configuration.
	 * @param Registry                $registry The type registry.
	 * @param array<string, mixed>    $needs    What has been found so far.
	 *
	 * @return void
	 */
	private static function walk( array $configs, Registry $registry, array &$needs ): void {
		foreach ( $configs as $config ) {
			if ( ! is_array( $config ) ) {
				continue;
			}

			$id       = (string) ( $config['type'] ?? 'text' );
			$children = $config['fields'] ?? null;

			if ( $registry->has( $id ) ) {
				$type = $registry->get( $id );

				// A type that brings its own columns -- a file list's name
				// and file -- has them as defaults, not in the field's
				// configuration, and they need what they need all the same.
				if ( ! is_array( $children ) ) {
					$children = $type->defaults()['fields'] ?? null;
				}

				// A type whose needs depend on how a field is configured -- a
				// colour field wants core's picker only when it was asked
				// for it -- says so per field.
				$found = method_exists( $type, 'dependencies_for' ) ? $type->dependencies_for( $config ) : $type->dependencies();

				$needs['scripts']      = array_merge( $needs['scripts'], (array) ( $found['scripts'] ?? [] ) );
				$needs['styles']       = array_merge( $needs['styles'], (array) ( $found['styles'] ?? [] ) );
				$needs['code_editors'] = array_merge( $needs['code_editors'], (array) ( $found['code_editors'] ?? [] ) );
				$needs['editor']       = $needs['editor'] || ! empty( $found['editor'] );
			}

			if ( is_array( $children ) ) {
				self::walk( $children, $registry, $needs );
			}

			// Flexible content keeps its fields under each layout.
			self::walk( FlexibleType::layout_fields( $config ), $registry, $needs );
		}
	}
}
