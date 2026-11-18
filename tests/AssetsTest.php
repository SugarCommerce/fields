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
		$this->assertSame( $base . '/js/field-kit.js', $script );
		$this->assertSame( [ 'jquery', 'wp-api-fetch' ], $deps );
		$this->assertSame( [ 'in_footer' => true ], $args );

		// Each file is versioned by its own modification time.
		$this->assertSame( (string) filemtime( dirname( __DIR__ ) . '/assets/css/field-kit.css' ), $style_version );
		$this->assertSame( (string) filemtime( dirname( __DIR__ ) . '/assets/js/field-kit.js' ), $script_version );
	}

	public function test_registering_twice_registers_once(): void {
		( new Assets() )->register();
		( new Assets() )->register();

		$this->assertCount( 1, $GLOBALS['fk_registered']['inline'][ Runtime::handle() ] );
	}
}
