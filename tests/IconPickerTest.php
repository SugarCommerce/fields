<?php
/**
 * Icon picker tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Field;
use PHPUnit\Framework\TestCase;

/**
 * The icon field is a button showing the chosen icon, which opens a dialog
 * of every registered set.
 *
 * It was a select of forty curated Dashicons. The owner of the sites this
 * builds wanted every Tailwind icon, searchable, and sets a theme can add.
 */
final class IconPickerTest extends TestCase {

	/**
	 * Forget registrations between tests.
	 */
	protected function tearDown(): void {
		Icons::reset();
	}

	/**
	 * Render an icon field through a field set.
	 *
	 * @param string               $value  Stored value.
	 * @param array<string, mixed> $config Extra configuration.
	 *
	 * @return string
	 */
	private function render( string $value = '', array $config = [] ): string {
		$set = new FieldSet(
			[ 'icon' => array_merge( [ 'type' => 'icon', 'label' => 'Icon' ], $config ) ],
			new ArrayContext( '' === $value ? [] : [ 'icon' => $value ] )
		);

		return $set->render();
	}

	/**
	 * Sanitise through the type.
	 *
	 * @param mixed                $value  Submitted value.
	 * @param array<string, mixed> $config Configuration.
	 *
	 * @return mixed
	 */
	private function sanitize( mixed $value, array $config = [] ): mixed {
		$type = ( new Registry() )->get( 'icon' );

		return $type->sanitize( $value, new Field( 'icon', $type, $config ) );
	}

	/**
	 * The button shows the icon and its name, and the value rides beside it.
	 */
	public function test_the_button_shows_the_choice(): void {
		$html = $this->render( 'heroicons-outline:shopping-cart' );

		$this->assertStringContainsString( '<input type="hidden" class="field-kit__icon-value" name="icon" value="heroicons-outline:shopping-cart" />', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*id="icon"[^>]*>\s*<span class="field-kit__icon-glyph" aria-hidden="true"><svg width="20" height="20"/', $html );
		$this->assertStringContainsString( '<span class="field-kit__icon-name">Shopping cart</span>', $html );
		$this->assertStringContainsString( 'data-empty="false"', $html );
		$this->assertMatchesRegularExpression( '/class="button-link field-kit__icon-clear">Clear/', $html );
	}

	/**
	 * The label names the control a person operates, which is announced as
	 * a combobox whose value is the icon's name and whose popup is a dialog.
	 */
	public function test_the_control_is_a_labelled_combobox(): void {
		$html = $this->render( 'heroicons-outline:star', [ 'required' => true, 'description' => 'Shown on the tile.' ] );

		$this->assertStringContainsString( '<label for="icon">', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*role="combobox"[^>]*aria-haspopup="dialog"[^>]*aria-expanded="false"/', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*aria-describedby="icon__description"/', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*aria-required="true"/', $html );

		// The hidden input is not what anything is announced from.
		$this->assertDoesNotMatchRegularExpression( '/type="hidden"[^>]*aria-/', $html );
	}

	/**
	 * Nothing chosen: the placeholder, and nothing to clear.
	 */
	public function test_empty(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-empty="true"', $html );
		$this->assertStringContainsString( '<span class="field-kit__icon-name">Choose an icon</span>', $html );
		$this->assertStringContainsString( '<span class="field-kit__icon-glyph" aria-hidden="true"></span>', $html );
		$this->assertStringContainsString( 'field-kit__icon-clear" hidden>', $html );

		$this->assertStringContainsString( '<span class="field-kit__icon-name">No icon</span>', $this->render( '', [ 'placeholder' => 'No icon' ] ) );
	}

	/**
	 * The picker is told which sets it offers, without the icons themselves.
	 */
	public function test_the_sets_are_described_not_shipped(): void {
		$html = $this->render( '', [ 'sets' => [ 'heroicons-solid', 'wordpress' ] ] );

		preg_match( '/data-sets="([^"]+)"/', $html, $match );

		$sets = json_decode( html_entity_decode( $match[1] ?? '', ENT_QUOTES ), true );

		$this->assertSame( [ 'heroicons-solid', 'wordpress' ], array_column( (array) $sets, 'id' ) );
		$this->assertSame( [ 324, 344 ], array_column( (array) $sets, 'count' ) );

		// A page with one icon field does not carry six hundred icons.
		$this->assertLessThan( 4000, strlen( $html ) );
		$this->assertStringContainsString( 'data-endpoint="https://example.test/wp-json/sugarcommerce-fields/v1/icons"', $html );
	}

	/**
	 * Only an icon from an offered set is stored.
	 */
	public function test_only_an_offered_icon_is_stored(): void {
		$this->assertSame( 'heroicons-outline:star', $this->sanitize( 'heroicons-outline:star' ) );
		$this->assertSame( '', $this->sanitize( 'heroicons-outline:not-real' ) );
		$this->assertSame( '', $this->sanitize( 'nope:star' ) );
		$this->assertSame( '', $this->sanitize( '" onload="alert(1)' ) );
		$this->assertSame( '', $this->sanitize( [ 'heroicons-outline:star' ] ) );

		$this->assertSame( '', $this->sanitize( 'heroicons-outline:star', [ 'sets' => [ 'wordpress' ] ] ) );
		$this->assertSame( 'wordpress:star-filled', $this->sanitize( 'wordpress:star-filled', [ 'sets' => [ 'wordpress' ] ] ) );
	}

	/**
	 * A stored value that is no longer an icon shows as empty rather than
	 * as a broken picture.
	 */
	public function test_a_stale_value_renders_empty(): void {
		$html = $this->render( 'heroicons-outline:gone' );

		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'data-empty="true"', $html );
	}

	/**
	 * The script drives it, and the stylesheet draws the dialog.
	 */
	public function test_the_assets_drive_it(): void {
		$js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/field-kit.js' );
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/field-kit.css' );

		// A native modal dialog, in the top layer over any flyout.
		$this->assertStringContainsString( "document.createElement( 'dialog' )", $js );
		$this->assertStringContainsString( 'dialog.showModal()', $js );
		$this->assertStringContainsString( 'document.body.appendChild( dialog )', $js );

		// Escape is the dialog's, not the panel's under it.
		$this->assertMatchesRegularExpression( "/'Escape' === event\\.key \\) \\{\\s*event\\.preventDefault\\(\\);\\s*event\\.stopPropagation\\(\\);/", $js );

		// Focus goes back to the field's button however it closes.
		$this->assertStringContainsString( "dialog.addEventListener( 'close'", $js );

		// The grid is a listbox the arrow keys move through.
		$this->assertStringContainsString( 'role="listbox"', $js );
		foreach ( [ 'ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End' ] as $key ) {
			$this->assertStringContainsString( "case '" . $key . "':", $js );
		}

		$this->assertMatchesRegularExpression( '/\.field-kit__icon-dialog::backdrop\s*\{/', $css );
		$this->assertMatchesRegularExpression( '/\.field-kit__icon-option\[aria-selected="true"\]\s*\{/', $css );
	}
}
