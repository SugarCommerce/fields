<?php
/**
 * Icon Sets: the Registry and the Helpers
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Icons;

/**
 * Class Icons
 *
 * Every icon set a picker can offer, and the one way to turn a stored icon
 * back into something to draw.
 *
 * An icon is stored as `set:name` -- `heroicons-outline:shopping-cart`,
 * `dashicons:cart`. The set says where it comes from and the name says
 * which; together they are the whole of what a row needs to keep, short
 * enough for a varchar(64) and readable in a database dump.
 *
 * Four sets are built in: Heroicons outline and solid (the Tailwind team's,
 * MIT), WordPress's block-editor icons (`@wordpress/icons`, GPL) and
 * Dashicons (read from core). A theme or plugin adds its own in either of
 * two ways:
 *
 * - `Icons::register( 'my-set', [ ... ] )`, from code that has the class;
 * - the `sugarcommerce_icon_sets` filter, from code that does not.
 *
 * The filter's name is not derived from this build's namespace, which is
 * what every other hook in the kit does, and that is deliberate. Each plugin
 * bundles its own Strauss-prefixed copy of the kit, so a theme cannot know
 * which class to call -- and a set it registers should appear in every
 * plugin's picker, not one. Every copy reads the same filter.
 *
 * @since 1.5.0
 */
final class Icons {

	/**
	 * The filter every copy of the kit reads its sets through.
	 */
	public const FILTER = 'sugarcommerce_icon_sets';

	/**
	 * The class render() puts on every icon it draws, SVG or font.
	 *
	 * Fixed, not derived from the namespace, so a stylesheet written for one
	 * copy of the kit sizes the icons any other copy draws.
	 */
	public const ICON_CLASS = 'sugarcommerce-icon';

	/**
	 * The Heroicons release in assets/icons/heroicons.
	 */
	public const HEROICONS_VERSION = '2.2.0';

	/**
	 * Sets registered from code, as their arguments, by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private static array $registered = [];

	/**
	 * Built-in sets taken away, by id.
	 *
	 * @var array<string, true>
	 */
	private static array $removed = [];

	/**
	 * The sets, once the filter has run.
	 *
	 * @var array<string, IconSet>|null
	 */
	private static ?array $sets = null;

	/**
	 * Add an icon set, or replace one of the same id.
	 *
	 * @param string               $id   Lower-case letters, digits and hyphens;
	 *                                   the part of a value before the colon.
	 * @param array<string, mixed> $args {
	 *     @type string          $label      What the picker's tab says.
	 *     @type string|callable $source     A directory of `.svg` files, each
	 *                                       named for its icon; or a callable
	 *                                       returning `name => svg markup`.
	 *     @type string          $prefix     For an icon font with no SVGs: the
	 *                                       classes an icon is drawn with, up
	 *                                       to its name (`dashicons dashicons-`).
	 *     @type string[]        $names      For an icon font: its names.
	 *     @type string          $stylesheet For an icon font: the style handle
	 *                                       that loads it.
	 *     @type string          $license    The line the picker's footer shows
	 *                                       for the set (`MIT · Tailwind Labs`).
	 *     @type string          $style      `outline` or `solid`, a hint shown
	 *                                       beside the label.
	 *     @type array           $keywords   Extra search words, `name => [words]`.
	 *     @type array           $labels     A label per name, where the name
	 *                                       does not read well on its own.
	 *     @type string          $capability Needed to browse the set over REST.
	 *                                       Default `edit_posts`.
	 *     @type string          $version    Changes when the icons do; the
	 *                                       picker's cache keys on it. Worked
	 *                                       out from the names when absent.
	 * }
	 *
	 * @return bool False when the id is not a valid one.
	 * @since 1.5.0
	 */
	public static function register( string $id, array $args ): bool {
		if ( ! IconSet::valid_id( $id ) ) {
			return false;
		}

		self::$registered[ $id ] = $args;
		unset( self::$removed[ $id ] );
		self::$sets = null;

		return true;
	}

	/**
	 * Take a set away, built-in or not.
	 *
	 * Stored values from it stop resolving: they draw nothing, and are
	 * emptied the next time they are sanitised.
	 *
	 * @param string $id The set's id.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function unregister( string $id ): void {
		unset( self::$registered[ $id ] );
		self::$removed[ $id ] = true;
		self::$sets           = null;
	}

	/**
	 * Every set, in the order a picker offers them.
	 *
	 * @return array<string, IconSet> By id.
	 * @since 1.5.0
	 */
	public static function sets(): array {
		if ( null !== self::$sets ) {
			return self::$sets;
		}

		$definitions = array_diff_key( self::builtin(), self::$removed );
		$definitions = array_merge( $definitions, self::$registered );

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the icon sets every picker offers.
			 *
			 * Add a set by adding its arguments under its id -- see
			 * Icons::register() for what they are -- remove one by
			 * unsetting it, reorder them by returning them in another
			 * order. Every plugin built on the kit reads this filter, so a
			 * theme's set appears in all of their pickers.
			 *
			 * @param array<string, array<string, mixed>> $definitions Arguments by set id.
			 *
			 * @since 1.5.0
			 */
			$definitions = apply_filters( self::FILTER, $definitions );
		}

		$sets = [];

		foreach ( is_array( $definitions ) ? $definitions : [] as $id => $args ) {
			if ( is_array( $args ) && IconSet::valid_id( (string) $id ) ) {
				$sets[ (string) $id ] = new IconSet( (string) $id, $args );
			}
		}

		self::$sets = $sets;

		return self::$sets;
	}

	/**
	 * One set.
	 *
	 * @param string $id The set's id.
	 *
	 * @return IconSet|null
	 * @since 1.5.0
	 */
	public static function get( string $id ): ?IconSet {
		return self::sets()[ $id ] ?? null;
	}

	/**
	 * A stored value as its set and name.
	 *
	 * Only the shape is checked here, not whether the icon exists. The old
	 * form, a whole Dashicons class (`dashicons-cart`), is read as the
	 * Dashicons set's icon of that name, so a value stored before there
	 * were sets still draws.
	 *
	 * @param mixed $value A stored value.
	 *
	 * @return array{0: string, 1: string}|null `[ set, name ]`.
	 * @since 1.5.0
	 */
	public static function parse( mixed $value ): ?array {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = strtolower( trim( $value ) );

		if ( ! str_contains( $value, ':' ) && str_starts_with( $value, 'dashicons-' ) ) {
			$value = 'dashicons:' . substr( $value, strlen( 'dashicons-' ) );
		}

		$parts = explode( ':', $value, 2 );

		if ( 2 !== count( $parts ) || ! IconSet::valid_id( $parts[0] ) || ! IconSet::valid_name( $parts[1] ) ) {
			return null;
		}

		return [ $parts[0], $parts[1] ];
	}

	/**
	 * Whether a value names an icon that is there to draw.
	 *
	 * @param mixed    $value A stored value.
	 * @param string[] $sets  Only these sets count; empty for every one.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function exists( mixed $value, array $sets = [] ): bool {
		return '' !== self::sanitize( $value, $sets );
	}

	/**
	 * A value as it should be stored: `set:name` of a real icon, or ''.
	 *
	 * Held to the registry, because the value is drawn into pages later and
	 * a name that is not in a set is not an icon -- it is text somebody
	 * typed. The old `dashicons-cart` form comes back as `dashicons:cart`.
	 *
	 * @param mixed    $value A submitted value.
	 * @param string[] $sets  Only these sets are accepted; empty for every one.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public static function sanitize( mixed $value, array $sets = [] ): string {
		$parsed = self::parse( $value );

		if ( null === $parsed ) {
			return '';
		}

		[ $id, $name ] = $parsed;

		if ( [] !== $sets && ! in_array( $id, array_map( 'strval', $sets ), true ) ) {
			return '';
		}

		$set = self::get( $id );

		return null !== $set && $set->has( $name ) ? $id . ':' . $name : '';
	}

	/**
	 * An icon, ready to print.
	 *
	 * Inline SVG wherever the set has one: it takes the colour of the text
	 * around it (`currentColor`) and is the size it is asked to be. An icon
	 * font with no SVG comes back as the font's `<span>`, with its
	 * stylesheet enqueued. Decorative unless `label` is given.
	 *
	 * @param mixed                $value A stored value.
	 * @param array<string, mixed> $attrs `size` (pixels, both sides), `width`,
	 *                                    `height`, `class`, `style`, `label`.
	 *
	 * @return string Safe markup, or '' when the value is not an icon.
	 * @since 1.5.0
	 */
	public static function svg( mixed $value, array $attrs = [] ): string {
		$parsed = self::parse( $value );
		$set    = null !== $parsed ? self::get( $parsed[0] ) : null;

		if ( null === $set || ! $set->has( $parsed[1] ) ) {
			return '';
		}

		$markup = $set->svg( $parsed[1] );

		if ( '' !== $markup ) {
			return Svg::render( $markup, $attrs );
		}

		if ( '' !== $set->stylesheet() && function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( $set->stylesheet() );
		}

		return $set->font( $parsed[1], $attrs );
	}

	/**
	 * An icon a library was handed, in whichever form it came, ready to print.
	 *
	 * The one call for a library that takes an `icon` argument. Two forms are
	 * read:
	 *
	 * - `set:name` (`heroicons-outline:chart-bar`) is drawn by svg() -- inline
	 *   SVG in `currentColor`, the size asked;
	 * - a bare Dashicons name (`chart-bar`, or the whole class
	 *   `dashicons-chart-bar`), the form every library took before there were
	 *   sets, is drawn as it always was: the font's `<span>`, with the
	 *   stylesheet enqueued, so a caller that passes one sees no change.
	 *
	 * Either way the element carries the `sugarcommerce-icon` class
	 * (ICON_CLASS), so one rule sizes and colours both -- give it `width`,
	 * `height` and `font-size`. With no `size`, an SVG is 20 by 20, a
	 * Dashicon's own box, set as attributes so a stylesheet still overrides
	 * it. Decorative (`aria-hidden`) unless `label` is given.
	 *
	 * @param mixed                $value A `set:name` value or a Dashicons name.
	 * @param array<string, mixed> $args  `size` (pixels, both sides), `class`,
	 *                                    `style`, `label`.
	 *
	 * @return string Safe markup, or '' when the value is neither form, or
	 *                names an icon no set has.
	 * @since 1.6.0
	 */
	public static function render( mixed $value, array $args = [] ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$value = strtolower( trim( $value ) );
		$class = trim( self::ICON_CLASS . ' ' . (string) ( $args['class'] ?? '' ) );

		if ( str_contains( $value, ':' ) ) {
			// A Dashicon's box unless told otherwise. As attributes, not a
			// style, so the library's stylesheet still wins.
			$size = isset( $args['size'] ) || isset( $args['width'] ) || isset( $args['height'] ) ? [] : [ 'size' => 20 ];

			return self::svg( $value, array_merge( $size, $args, [ 'class' => $class ] ) );
		}

		$name = str_starts_with( $value, 'dashicons-' ) ? substr( $value, strlen( 'dashicons-' ) ) : $value;

		if ( ! IconSet::valid_name( $name ) ) {
			return '';
		}

		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'dashicons' );
		}

		$size  = (int) ( $args['size'] ?? 0 );
		$label = trim( (string) ( $args['label'] ?? '' ) );
		$style = $size > 0 ? sprintf( 'width:%1$dpx;height:%1$dpx;font-size:%1$dpx;', $size ) : '';
		$style = $style . trim( (string) ( $args['style'] ?? '' ) );

		return sprintf(
			'<span class="%1$s"%2$s%3$s></span>',
			esc_attr( 'dashicons dashicons-' . $name . ' ' . $class ),
			'' !== $style ? sprintf( ' style="%s"', esc_attr( $style ) ) : '',
			'' !== $label ? sprintf( ' role="img" aria-label="%s"', esc_attr( $label ) ) : ' aria-hidden="true"'
		);
	}

	/**
	 * Whether a value is in the `set:name` form rather than a bare
	 * Dashicons name.
	 *
	 * For a library that has to tell an icon from a URL or an emoji it also
	 * accepts, or that lays the two forms out differently. Only the shape is
	 * checked; exists() says whether the icon is there.
	 *
	 * @param mixed $value A value.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	public static function is_set_value( mixed $value ): bool {
		return is_string( $value ) && str_contains( $value, ':' ) && null !== self::parse( $value );
	}

	/**
	 * What an icon is called, for a person: "Shopping cart".
	 *
	 * @param mixed $value A stored value.
	 *
	 * @return string '' when the value is not an icon.
	 * @since 1.5.0
	 */
	public static function label( mixed $value ): string {
		$parsed = self::parse( $value );
		$set    = null !== $parsed ? self::get( $parsed[0] ) : null;

		return null !== $set && $set->has( $parsed[1] ) ? $set->label_for( $parsed[1] ) : '';
	}

	/**
	 * Forget every registration and the filtered sets.
	 *
	 * For tests, and for a long-running process that changes its filters.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public static function reset(): void {
		self::$registered = [];
		self::$removed    = [];
		self::$sets       = null;
	}

	/**
	 * The sets that come with the kit.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function builtin(): array {
		$icons = dirname( __DIR__, 2 ) . '/assets/icons';

		// Heroicons stay files of this library's own, drawn through
		// Svg::sanitize(), and are not registered with core's Icons API: the
		// registry runs every icon through kses with an allow-list that has
		// no stroke, stroke-width, stroke-linecap or stroke-linejoin, so an
		// outline icon would lose its lines and fill solid.
		return [
			'heroicons-outline' => [
				'label'   => 'Heroicons',
				'style'   => 'outline',
				'source'  => $icons . '/heroicons/outline',
				'license' => 'Heroicons ' . self::HEROICONS_VERSION . ' · MIT · Tailwind Labs',
				'version' => self::HEROICONS_VERSION,
			],
			'heroicons-solid'   => [
				'label'   => 'Heroicons',
				'style'   => 'solid',
				'source'  => $icons . '/heroicons/solid',
				'license' => 'Heroicons ' . self::HEROICONS_VERSION . ' · MIT · Tailwind Labs',
				'version' => self::HEROICONS_VERSION,
			],
			// Core's own icon library, from WP 7.1's Icons API.
			'wordpress'         => [
				'label'   => 'WordPress',
				'source'  => [ CoreIcons::class, 'icons' ],
				'license' => 'WordPress icons · GPL-2.0-or-later · WordPress',
				'version' => self::core_version(),
			],
			'dashicons'         => [
				'label'      => 'Dashicons',
				'source'     => [ Dashicons::class, 'icons' ],
				'prefix'     => 'dashicons dashicons-',
				'stylesheet' => 'dashicons',
				'license'    => 'Dashicons · GPL-2.0-or-later · WordPress',
				'version'    => self::core_version(),
			],
		];
	}

	/**
	 * The running core's version, which is the version of its Dashicons and
	 * of its icon library.
	 *
	 * @return string
	 */
	private static function core_version(): string {
		global $wp_version;

		return is_string( $wp_version ?? null ) ? $wp_version : '';
	}
}
