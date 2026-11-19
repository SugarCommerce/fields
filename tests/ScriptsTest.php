<?php
/**
 * Built script tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SugarCommerce\Fields\Assets;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Plugin\Assets as Files;

// The recorders AssetsTest uses; sibling suites keep their own.
require_once __DIR__ . '/stubs/Assets.php';

/**
 * The scripts are built from src/js into assets/build, and the build is
 * committed: Composer and Strauss copy a package as it is, with nothing run
 * at install. These keep the build and the PHP that loads it in step.
 */
final class ScriptsTest extends TestCase {

	/**
	 * Every entry, and what its build imports.
	 */
	private const ENTRIES = [
		'field-kit'    => [ 'wp-api-fetch', 'wp-dom-ready' ],
		'block-editor' => [ 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-plugins' ],
	];

	/**
	 * A file of the package, for the asset helper to find it from.
	 */
	private const FILE = __DIR__ . '/../src/Assets.php';

	/**
	 * A clean slate.
	 */
	protected function setUp(): void {
		$GLOBALS['fk_registered'] = [];
		( new ReflectionProperty( Assets::class, 'registered' ) )->setValue( null, false );
		Files::clear_cache();
	}

	/**
	 * Every entry is built, with the manifest @wordpress/scripts writes
	 * beside it, and none of them needs jQuery: the field kit runs on a
	 * public form, where there is none.
	 */
	public function test_every_built_entry_has_its_manifest(): void {
		foreach ( self::ENTRIES as $entry => $imports ) {
			$manifest = Files::built_manifest( self::FILE, $entry );

			$this->assertNotNull( $manifest, $entry );
			$this->assertFileExists( dirname( __DIR__ ) . '/assets/build/' . $entry . '.js', $entry );
			$this->assertSame( [], preg_grep( '/^jquery/', $manifest['dependencies'] ), $entry );
			$this->assertNotContains( 'wp-util', $manifest['dependencies'], $entry );
			$this->assertSame( $imports, $manifest['dependencies'], $entry );
			$this->assertMatchesRegularExpression( '/^[a-f0-9]{20}$/', (string) $manifest['version'], $entry );
		}

		$this->assertNull( Files::built_manifest( self::FILE, 'nothing' ) );
	}

	/**
	 * The kit's script is the build, under its handle, in the footer, with
	 * its configuration put in before it runs.
	 */
	public function test_the_kit_is_the_build(): void {
		( new Assets() )->register();

		$handle = Runtime::handle();

		[ $src, $deps, $ver, $args ] = $GLOBALS['fk_registered']['scripts'][ $handle ];

		$this->assertStringEndsWith( '/assets/build/field-kit.js', (string) $src );
		$this->assertSame( self::ENTRIES['field-kit'], $deps );
		$this->assertSame( Files::built_manifest( self::FILE, 'field-kit' )['version'], $ver );
		$this->assertSame( [ 'in_footer' => true ], $args );
		$this->assertStringStartsWith( 'window.SugarCommerceFields=', $GLOBALS['fk_registered']['inline'][ $handle ][0] );
	}

	/**
	 * The sources ship beside the build, and nothing loads a script from
	 * the old hand-written folder.
	 */
	public function test_nothing_loads_the_old_scripts(): void {
		$this->assertDirectoryDoesNotExist( dirname( __DIR__ ) . '/assets/js' );

		foreach ( array_keys( self::ENTRIES ) as $entry ) {
			$this->assertFileExists( dirname( __DIR__ ) . '/src/js/' . $entry . '.js' );
		}

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$this->assertStringNotContainsString( "'js/", (string) file_get_contents( $file->getPathname() ), $file->getFilename() );
			}
		}
	}

	/**
	 * The one module that reaches for jQuery is the colour picker's adapter,
	 * and it asks the page for it rather than importing it.
	 */
	public function test_only_the_adapter_touches_jquery(): void {
		$touching = [];

		foreach ( Scripts::files() as $file ) {
			$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents( $file ) );

			if ( preg_match( '/jQuery|\$\(/', $code ) ) {
				$touching[] = substr( $file, strlen( Scripts::root() ) + 1 );
			}
		}

		$this->assertSame( [ 'lib/jquery.js' ], $touching );
	}
}
