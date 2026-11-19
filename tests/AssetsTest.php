<?php
/**
 * Where the script and stylesheet are served from.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCommerce\Fields\Assets;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Plugin\Assets as PackageAssets;

// Here rather than in stubs.php: sibling suites load those stubs first and
// keep their own recorders for these functions.
require_once __DIR__ . '/stubs/Assets.php';

final class AssetsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['fk_registered'] = [];
		( new ReflectionProperty( Assets::class, 'registered' ) )->setValue( null, false );
		PackageAssets::clear_cache();
	}

	public function test_the_files_are_found_by_plugin_assets(): void {
		( new Assets() )->register();

		$base   = 'https://example.test/wp-content/' . basename( dirname( __DIR__ ) ) . '/assets';
		$handle = Runtime::handle();

		[ $style, , $style_version ]                  = $GLOBALS['fk_registered']['styles'][ $handle ];
		[ $script, $deps, $script_version, $args ] = $GLOBALS['fk_registered']['scripts'][ $handle ];

		$this->assertSame( $base . '/css/field-kit.css', $style );
		$this->assertSame( $base . '/build/field-kit.js', $script );
		$this->assertSame( [ 'wp-api-fetch', 'wp-dom-ready' ], $deps );
		$this->assertSame( [ 'in_footer' => true ], $args );

		// The stylesheet is versioned by its modification time, the script
		// by the hash its build wrote.
		$this->assertSame( (string) filemtime( dirname( __DIR__ ) . '/assets/css/field-kit.css' ), $style_version );
		$this->assertSame( PackageAssets::built_manifest( dirname( __DIR__ ) . '/src/Assets.php', 'field-kit' )['version'], $script_version );
	}

	public function test_registering_twice_registers_once(): void {
		( new Assets() )->register();
		( new Assets() )->register();

		$this->assertCount( 1, $GLOBALS['fk_registered']['inline'][ Runtime::handle() ] );
	}

	/**
	 * A visual editor among the fields asks for core's editor scripts.
	 *
	 * wp_editor() asks for them itself while it renders on the page; one
	 * rendered later, in a panel fetched over REST, cannot -- and a page
	 * without them has no TinyMCE and no defaults to start one from.
	 */
	public function test_a_visual_editor_enqueues_the_editor(): void {
		( new Assets() )->enqueue( [ 'editor' => true ] );

		$this->assertSame( 1, $GLOBALS['fk_registered']['editor'] ?? 0 );
	}

	/**
	 * And a screen without one does not.
	 */
	public function test_no_visual_editor_enqueues_no_editor(): void {
		( new Assets() )->enqueue( [ 'editor' => false ] );
		( new Assets() )->enqueue( [] );

		$this->assertArrayNotHasKey( 'editor', $GLOBALS['fk_registered'] );
	}
}
