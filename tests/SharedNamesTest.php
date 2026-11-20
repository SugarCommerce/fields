<?php
/**
 * Nothing a second copy of the kit would also register.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Form;
use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Fields\Support\PanelTabs;
use SugarCommerce\Fields\Support\Tooltip;
use SugarCommerce\Fields\Utils\Runtime;

/**
 * Several plugins each bundle their own copy of the kit, and two of them can
 * be on one page. Every name a copy puts on the page or in WordPress has to
 * be its build's own: a page global is overwritten by whichever copy ran
 * last, and a hook fired by one copy reaches every other copy's listeners.
 *
 * It reads the source as well as calling the accessors: the failure is
 * invisible with one plugin installed and only shows on a site running two.
 */
final class SharedNamesTest extends TestCase {

	/**
	 * The page globals the scripts may write: the kit's own registry, keyed
	 * by build, and the two of core's the visual editor fills in.
	 */
	private const WRITABLE = [ 'SugarCommerceFields', 'tinyMCEPreInit', 'wpActiveEditor' ];

	/**
	 * Every script source, the block editor's sidebar included, without its
	 * comments.
	 *
	 * @return array<string, string> Code by path under assets/js.
	 */
	private function scripts(): array {
		$files    = [];
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( Scripts::root(), \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'js' === $file->getExtension() ) {
				$code = (string) preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents( $file->getPathname() ) );

				$files[ substr( $file->getPathname(), strlen( Scripts::root() ) + 1 ) ] = $code;
			}
		}

		$this->assertNotEmpty( $files );

		return $files;
	}

	/**
	 * The scripts write no page global but the keyed registry, and read none
	 * of the bare ones they used to publish into.
	 */
	public function test_the_scripts_publish_into_the_registry_only(): void {
		$found = [];

		foreach ( $this->scripts() as $path => $code ) {
			preg_match_all( '/\bwindow\.([A-Za-z_$][\w$]*)\s*=(?!=)/', $code, $matches );

			foreach ( array_diff( $matches[1], self::WRITABLE ) as $name ) {
				$found[] = $path . ': window.' . $name;
			}

			if ( preg_match( '/\bSugarCommerceFieldsModules\b|\bwindow\.FieldKit\b/', $code, $match ) ) {
				$found[] = $path . ': ' . $match[0];
			}
		}

		$this->assertSame( [], $found, "A page global another copy of the kit would share:\n" . implode( "\n", $found ) );
	}

	/**
	 * What ships -- the build -- carries neither of the old globals either.
	 */
	public function test_the_build_carries_no_old_global(): void {
		$built = (string) file_get_contents( dirname( __DIR__ ) . '/assets/build/field-kit.js' );

		$this->assertNotSame( '', $built );
		$this->assertStringNotContainsString( 'SugarCommerceFieldsModules', $built );
		$this->assertDoesNotMatchRegularExpression( '/\bwindow\.FieldKit\b/', $built );
		$this->assertStringContainsString( '.modules=', $built );
	}

	/**
	 * Every hook the kit fires is named for its build.
	 */
	public function test_every_hook_is_the_builds_own(): void {
		$root  = dirname( __DIR__ ) . '/src';
		$found = [];

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}

			preg_match_all( '/\b(?:do_action|apply_filters)(?:_ref_array)?\(\s*([^,)]+)/', (string) file_get_contents( $file->getPathname() ), $matches );

			foreach ( $matches[1] as $name ) {
				if ( ! str_starts_with( $name, 'Runtime::hook(' ) && 'self::filter(' !== trim( $name ) ) {
					$found[] = $file->getFilename() . ': ' . $name;
				}
			}
		}

		$this->assertSame( [], $found, "A hook not named by Runtime::hook():\n" . implode( "\n", $found ) );

		$this->assertSame( Runtime::hook( 'icon_sets' ), Icons::filter() );
		$this->assertSame( 'sugarcommerce_fields_icon_sets', Icons::filter() );
	}

	/**
	 * The hidden input naming which form was sent is the build's own, so a
	 * second plugin with a form of the same id does not take it as well.
	 */
	public function test_the_sent_form_field_is_the_builds_own(): void {
		$this->assertSame( '_' . Runtime::key( 'form' ), Form::field() );
		$this->assertSame( '_sugarcommerce_fields_form', Form::field() );
	}

	/**
	 * What the scripts bind is stamped with the build that drew it: a
	 * field's wrapper, a set of panel tabs, a tooltip.
	 */
	public function test_the_markup_names_its_build(): void {
		$stamp = 'data-field-kit-build="' . Runtime::handle() . '"';
		$field = new Field( 'demo', ( new Registry() )->get( 'text' ), [ 'label' => 'Demo' ] );

		$this->assertSame( ' ' . $stamp, Runtime::stamp() );
		$this->assertMatchesRegularExpression( '/^<div class="field-kit__field[^"]*"[^>]*\s' . preg_quote( $stamp, '/' ) . '/', ( new Renderer() )->render( $field ) );
		$this->assertStringContainsString( '<div class="field-kit__panel-tabs" ' . $stamp, PanelTabs::render( 'box', [ 'a' => [ 'label' => 'A', 'content' => '' ] ] ) );
		$this->assertStringContainsString( '<span class="field-kit__tooltip" ' . $stamp . '>', Tooltip::render( 'Help', 'tip' ) );
	}

	/**
	 * Every module looks for its controls with ours(), which passes over
	 * another build's, rather than querySelectorAll() on the root.
	 */
	public function test_every_module_finds_only_its_own_builds_controls(): void {
		$found = [];

		foreach ( $this->scripts() as $path => $code ) {
			if ( ( str_starts_with( $path, 'fields/' ) || str_starts_with( $path, 'forms/' ) ) && preg_match( '/\b(?:root|document)\.querySelectorAll\(/', $code ) ) {
				$found[] = $path;
			}
		}

		$this->assertSame( [], $found, "A module that would find another build's controls:\n" . implode( "\n", $found ) );
	}
}
