<?php
/**
 * The Kit's Own Glyphs
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.6.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Icons;

/**
 * Class Glyphs
 *
 * The icons on the controls the kit draws itself -- a tooltip's "?", the
 * "×" that clears a value, a drag handle, a row's bin -- named once, by what
 * they are for.
 *
 * They are Heroicons (outline), drawn through Icons::render() like any icon
 * a caller hands the kit, so they carry the `sugarcommerce-icon` class and are
 * inline SVG in `currentColor`: no icon font to load and nothing to wait
 * for. The script builds some of the same controls, and is handed these
 * already drawn (see markup()), so there is no second copy of a path in
 * JavaScript.
 *
 * An icon a caller gives -- a provider's, a badge's, a card's -- is not one
 * of these; it goes through Icons::render() as given.
 *
 * @since 1.6.0
 */
final class Glyphs {

	/**
	 * The kit's own icons, by what they are for.
	 *
	 * @var array<string, string>
	 */
	public const DEFAULTS = [
		'help'      => 'heroicons-outline:question-mark-circle',
		'close'     => 'heroicons-outline:x-mark',
		'drag'      => 'heroicons-outline:bars-3',
		'up'        => 'heroicons-outline:chevron-up',
		'down'      => 'heroicons-outline:chevron-down',
		'trash'     => 'heroicons-outline:trash',
		'duplicate' => 'heroicons-outline:document-duplicate',
		'upload'    => 'heroicons-outline:arrow-up-tray',
		'show'      => 'heroicons-outline:eye',
		'hide'      => 'heroicons-outline:eye-slash',
		'active'    => 'heroicons-outline:check-circle',
		'inactive'  => 'heroicons-outline:minus-circle',
		'refresh'   => 'heroicons-outline:arrow-path',
		'file'      => 'heroicons-outline:document',
		'document'  => 'heroicons-outline:document-text',
		'audio'     => 'heroicons-outline:musical-note',
		'video'     => 'heroicons-outline:film',
		'image'     => 'heroicons-outline:photo',
	];

	/**
	 * One of the kit's own icons, ready to print.
	 *
	 * @param string               $key  A key of DEFAULTS.
	 * @param array<string, mixed> $args As Icons::render(): `size`, `class`,
	 *                                   `style`, `label`.
	 *
	 * @return string Safe markup, or '' for a key there is no icon for.
	 * @since 1.6.0
	 */
	public static function get( string $key, array $args = [] ): string {
		return isset( self::DEFAULTS[ $key ] ) ? Icons::render( self::DEFAULTS[ $key ], $args ) : '';
	}

	/**
	 * The kit's own icons, drawn, for the script.
	 *
	 * Published in the script's configuration as `icons`. Each is the kit's
	 * cleaned SVG, so the script inserts it as it is.
	 *
	 * @return array<string, string> Key => markup.
	 * @since 1.6.0
	 */
	public static function markup(): array {
		$drawn = [];

		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			$drawn[ $key ] = self::get( $key );
		}

		return $drawn;
	}
}
