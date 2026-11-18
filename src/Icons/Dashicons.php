<?php
/**
 * WordPress's Own Dashicons, Read From Core
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Icons;

/**
 * Class Dashicons
 *
 * Dashicons are a font, and a font only draws where its stylesheet is
 * loaded -- the admin, and a theme that remembers to. But core also ships
 * every glyph as SVG: `wp-includes/fonts/dashicons.svg` is a sprite with a
 * `<symbol id="cart">` for each one. Read from there, a Dashicon is an SVG
 * like any other icon, and draws on a storefront, in a headless theme or in
 * an email with nothing enqueued.
 *
 * Nothing is copied into this library. The sprite is core's, versioned with
 * core, and read from the install it is running on. Where it is missing --
 * a stripped-down install, or a core that stops shipping it -- the names
 * come from `dashicons.css` instead and each icon is drawn as the font
 * draws it, which is how the field stored them before there were sets.
 *
 * @since 1.5.0
 */
final class Dashicons {

	/**
	 * Every Dashicon, by name.
	 *
	 * @param string|null $sprite The sprite to read; core's by default.
	 * @param string|null $styles The stylesheet to read names from when there
	 *                            is no sprite; core's by default.
	 *
	 * @return array<string, string> Raw `<symbol>` markup by name, or '' by
	 *                               name when only the font can draw it.
	 * @since 1.5.0
	 */
	public static function icons( ?string $sprite = null, ?string $styles = null ): array {
		$core   = defined( 'ABSPATH' ) && defined( 'WPINC' ) ? ABSPATH . WPINC : '';
		$sprite = $sprite ?? ( '' !== $core ? $core . '/fonts/dashicons.svg' : '' );
		$styles = $styles ?? ( '' !== $core ? $core . '/css/dashicons.css' : '' );

		$icons = self::from_sprite( $sprite );

		return [] !== $icons ? $icons : array_fill_keys( self::from_stylesheet( $styles ), '' );
	}

	/**
	 * Each `<symbol>` in a sprite, by its id.
	 *
	 * A pattern rather than a parser: the sprite is one file of several
	 * hundred symbols, and every one of them is put through Svg::sanitize()
	 * before it is drawn, which is where the parsing that matters happens.
	 *
	 * @param string $path The sprite.
	 *
	 * @return array<string, string>
	 */
	private static function from_sprite( string $path ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return [];
		}

		$markup = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- core's own file.

		preg_match_all( '#<symbol\b[^>]*\bid="([a-z0-9_-]+)"[^>]*>.*?</symbol>#s', $markup, $matches, PREG_SET_ORDER );

		$icons = [];

		foreach ( $matches as $match ) {
			$icons[ $match[1] ] = $match[0];
		}

		return $icons;
	}

	/**
	 * The names `dashicons.css` gives a glyph to.
	 *
	 * @param string $path The stylesheet.
	 *
	 * @return string[]
	 */
	private static function from_stylesheet( string $path ): array {
		if ( '' === $path || ! is_readable( $path ) ) {
			return [];
		}

		$css = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- core's own file.

		preg_match_all( '/\.dashicons-([a-z0-9_-]+):before/', $css, $matches );

		return array_values( array_unique( $matches[1] ) );
	}
}
