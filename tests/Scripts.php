<?php
/**
 * The scripts' sources, for the tests that hold markup and script together.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

/**
 * What the kit's script is made of: the modules under assets/js, which the
 * build (assets/build) is made from.
 *
 * A test that reads the script to check it against the markup -- an
 * attribute the field writes, a string it localises -- reads these. They
 * are what a person edits, and what the build is checked against in CI.
 */
final class Scripts {

	/**
	 * The modules' folder.
	 *
	 * @return string
	 */
	public static function root(): string {
		return dirname( __DIR__ ) . '/assets/js';
	}

	/**
	 * One module's source, or every one the field kit's entry is built from.
	 *
	 * @param string $module A module's path under assets/js, without `.js`, such as `fields/icon-picker`; '' for all.
	 *
	 * @return string
	 */
	public static function source( string $module = '' ): string {
		if ( '' !== $module ) {
			return (string) file_get_contents( self::root() . '/' . $module . '.js' );
		}

		$source = '';

		foreach ( self::files() as $file ) {
			$source .= (string) file_get_contents( $file ) . "\n";
		}

		return $source;
	}

	/**
	 * The field kit's module files, in a stable order. The block editor's
	 * sidebar is its own entry and is left out.
	 *
	 * @return string[]
	 */
	public static function files(): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( self::root(), \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'js' === $file->getExtension() && ! str_contains( $file->getPathname(), 'block-editor' ) ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );

		return $files;
	}
}
