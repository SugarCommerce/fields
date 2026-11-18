<?php
/**
 * The kit's own glyphs.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Icons\Glyphs;
use SugarCommerce\Fields\Icons\Icons;
use PHPUnit\Framework\TestCase;

/**
 * The controls the kit draws itself -- a tooltip's "?", a clear "×", a drag
 * handle, a bin -- are Heroicons through Icons::render(), not Dashicons: no
 * icon font to load, and the same `sugarcommerce-icon` class as every icon a
 * caller hands the kit.
 */
final class GlyphsTest extends TestCase {

	protected function setUp(): void {
		Icons::reset();
		$GLOBALS['fk_enqueued_styles'] = [];
	}

	/**
	 * Every glyph names an icon that is there. A typo in a name draws
	 * nothing at all, and an empty button is the kind of thing nobody
	 * notices until someone cannot find the control.
	 */
	public function test_every_glyph_draws(): void {
		foreach ( Glyphs::DEFAULTS as $key => $value ) {
			$this->assertStringStartsWith( 'heroicons-outline:', $value, $key );
			$this->assertTrue( Icons::exists( $value ), $key . ' names no icon' );

			$svg = Glyphs::get( $key );

			$this->assertStringStartsWith( '<svg width="20" height="20" class="sugarcommerce-icon"', $svg, $key );
			$this->assertStringContainsString( 'aria-hidden="true"', $svg, $key );
		}

		// Drawn as SVG, so nothing loads the icon font for them.
		$this->assertNotContains( 'dashicons', $GLOBALS['fk_enqueued_styles'] );
	}

	public function test_an_unknown_glyph_draws_nothing(): void {
		$this->assertSame( '', Glyphs::get( 'nope' ) );
	}

	public function test_a_glyph_takes_render_arguments(): void {
		$this->assertStringContainsString( 'class="sugarcommerce-icon is-extra"', Glyphs::get( 'close', [ 'class' => 'is-extra' ] ) );
		$this->assertStringContainsString( 'width="16" height="16"', Glyphs::get( 'close', [ 'size' => 16 ] ) );
	}

	/**
	 * The script is handed every glyph, drawn, under the same keys.
	 */
	public function test_the_script_is_handed_every_glyph(): void {
		$markup = Glyphs::markup();

		$this->assertSame( array_keys( Glyphs::DEFAULTS ), array_keys( $markup ) );

		foreach ( $markup as $key => $svg ) {
			$this->assertSame( Glyphs::get( $key ), $svg, $key );
		}
	}

	/**
	 * The controls themselves, rendered.
	 */
	public function test_the_kits_controls_draw_no_dashicons(): void {
		$html = ( new FieldSet(
			[
				'key'    => [ 'type' => 'text', 'label' => 'API key', 'tooltip' => 'From your account.' ],
				'secret' => [ 'type' => 'password', 'label' => 'Secret', 'reveal' => true ],
				'link'   => [ 'type' => 'link', 'label' => 'Link' ],
				'order'  => [ 'type' => 'sortable', 'label' => 'Order', 'options' => [ 'a' => 'A', 'b' => 'B' ] ],
				'file'   => [ 'type' => 'file_url', 'label' => 'File' ],
				'rows'   => [
					'type'        => 'repeater',
					'label'       => 'Rows',
					'collapsible' => true,
					'fields'      => [ 'name' => [ 'type' => 'text', 'label' => 'Name' ] ],
				],
			],
			new ArrayContext( [ 'rows' => [ [ 'name' => 'One' ] ], 'file' => 'https://example.test/a.pdf' ] ),
			''
		) )->render();

		$this->assertStringNotContainsString( 'dashicons', $html );

		foreach ( [ 'help', 'show', 'link', 'up', 'down', 'drag', 'trash' ] as $key ) {
			$this->assertStringContainsString( Glyphs::get( $key ), $html, $key );
		}

		// The row's fold indicator is the up chevron, turned by the
		// stylesheet when the row is closed.
		$this->assertMatchesRegularExpression( '/<span class="field-kit__repeater-indicator" aria-hidden="true"><svg[^>]*>.*?<\/svg><\/span>/', $html );
	}

	/**
	 * A guard on the source: no control the kit draws reaches for a Dashicon
	 * again. The icon sets themselves (src/Icons) read Dashicons on purpose,
	 * and a caller's icon may still be one -- neither is a control.
	 */
	public function test_no_control_reaches_for_a_dashicon(): void {
		$root  = dirname( __DIR__ );
		$found = [];
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $files as $file ) {
			if ( str_contains( $file->getPathname(), '/src/Icons/' ) || str_ends_with( $file->getPathname(), '/IconType.php' ) ) {
				continue;
			}

			if ( preg_match( '/dashicons/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$found[] = substr( $file->getPathname(), strlen( $root ) + 1 );
			}
		}

		// The one Dashicon left in the script is a search result's own icon,
		// handed over by whoever supplies the results.
		$script = (string) file_get_contents( $root . '/assets/js/field-kit.js' );

		$this->assertSame( 1, preg_match_all( '/dashicons/', $script ), 'The script draws a Dashicon.' );
		$this->assertSame( [], $found );
	}
}
