<?php
/**
 * WordPress's Own Icon Library, Read From Core
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Icons;

use WP_Icons_Registry;

/**
 * Class CoreIcons
 *
 * WordPress 7.1 has an Icons API: `WP_Icons_Registry`, `wp_register_icon()`
 * and `wp_get_icon()`, with the block editor's icon library registered in
 * the `core` collection from `wp-includes/images/icon-library`. The
 * `wordpress` set is that collection, read from the registry, so it is the
 * running core's icons, versioned and translated with core, and nothing is
 * copied into this library -- as with {@see Dashicons}.
 *
 * Only the `core` collection: another plugin's collection is its own, and
 * its names would not be unique in one set.
 *
 * @since 1.5.0
 */
final class CoreIcons {

	/**
	 * The collection core registers its own icons in.
	 *
	 * @since 1.5.0
	 */
	public const COLLECTION = 'core';

	/**
	 * Every icon in core's collection, by its name without the collection.
	 *
	 * The markup is the registry's, which core has already put through
	 * kses; the set puts it through Svg::sanitize() as well, like any other.
	 * Empty before `init`, when core registers them, and on a WordPress
	 * without the API.
	 *
	 * @return array<string, string> Markup by name.
	 * @since 1.5.0
	 */
	public static function icons(): array {
		if ( ! class_exists( WP_Icons_Registry::class ) ) {
			return [];
		}

		$icons = [];

		foreach ( WP_Icons_Registry::get_instance()->get_registered_icons() as $icon ) {
			$name = (string) ( $icon['name'] ?? '' );

			if ( self::COLLECTION !== ( $icon['collection'] ?? '' ) || ! is_string( $icon['content'] ?? null ) || '' === $icon['content'] ) {
				continue;
			}

			$icons[ substr( $name, strlen( self::COLLECTION ) + 1 ) ] = $icon['content'];
		}

		return $icons;
	}
}
