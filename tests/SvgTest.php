<?php
/**
 * SVG cleaning tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Security\Svg;
use PHPUnit\Framework\TestCase;

/**
 * An icon is somebody else's markup printed into an admin screen.
 *
 * security's Svg is the cleaner, tested there; these are the icon sets'
 * own guarantees: a registered folder is printed clean, and what ships is
 * already in the form it is printed in.
 *
 * A theme's folder, a plugin's callback, a file dropped in by whoever had
 * FTP: inlined, an SVG runs its scripts and its event handlers with the
 * page's privileges -- in the admin, an administrator's. So every icon is
 * rebuilt from an allow-list before it is printed, and these are the things
 * that list exists to stop.
 */
final class SvgTest extends TestCase {

	/**
	 * The fixtures.
	 *
	 * @return string
	 */
	private static function fixtures(): string {
		return __DIR__ . '/fixtures/icons';
	}

	/**
	 * Forget registrations between tests.
	 */
	protected function tearDown(): void {
		Icons::reset();
	}

	/**
	 * A malicious file in a registered folder is printed clean.
	 *
	 * The end-to-end version of the above: through the registry and the
	 * helper a template calls, which is the path a theme's folder takes.
	 */
	public function test_a_registered_folder_is_cleaned_on_the_way_out(): void {
		Icons::register( 'theme', [ 'source' => self::fixtures() . '/theme' ] );

		$this->assertTrue( Icons::exists( 'theme:evil' ) );

		$html = Icons::svg( 'theme:evil', [ 'size' => 16 ] );

		$this->assertStringStartsWith( '<svg width="16" height="16" aria-hidden="true" focusable="false" xmlns=', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'script', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'onload', $html );
		$this->assertStringNotContainsStringIgnoringCase( 'javascript', $html );
	}

	/**
	 * Every icon the kit ships is already in the form it is printed in.
	 *
	 * bin/build-icons.php writes them through sanitize(); if somebody edits
	 * a file by hand, or the cleaning changes, this says so.
	 */
	public function test_the_shipped_icons_are_already_clean(): void {
		$files = glob( dirname( __DIR__ ) . '/assets/icons/*/{,*/}*.svg', GLOB_BRACE ) ?: [];

		$this->assertSame( 648, count( $files ), 'Heroicons outline and solid, and nothing else.' );

		foreach ( $files as $file ) {
			$markup = (string) file_get_contents( $file );

			$this->assertSame( $markup, Svg::sanitize( $markup ), basename( $file ) . ' is not as sanitize() writes it.' );
		}
	}
}
