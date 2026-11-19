<?php
/**
 * One look for the controls a field adds, on a page and in a panel.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use PHPUnit\Framework\TestCase;

/**
 * A visual audit found three secondary-button looks (core's blue outline on
 * a page, the panel's white one, underlined links for Clear and Remove), the
 * browser's own listbox for a multiple select, and the browser's thin focus
 * ring on the combobox's clear button.
 */
final class ControlsLookTest extends TestCase {

	/**
	 * The stylesheet.
	 *
	 * @return string
	 */
	private function css(): string {
		return (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/field-kit.css' );
	}

	/**
	 * A button inside a field is white with a grey edge, wherever it is.
	 */
	public function test_a_buttons_secondary_look_is_the_kits(): void {
		$this->assertMatchesRegularExpression(
			'/\.wp-core-ui \.field-kit__field \.button:not\(\.button-primary\)[^{]*\{[^}]*border-color: #c4c7cc;[^}]*background: #fff;[^}]*color: #1f2124;/',
			$this->css()
		);
	}

	/**
	 * Clear and Remove are buttons with room, not underlined text.
	 */
	public function test_clear_and_remove_are_small_buttons(): void {
		$this->assertMatchesRegularExpression(
			'/\.wp-core-ui \.field-kit__color-clear,\s*\.wp-core-ui \.field-kit__icon-clear,\s*\.wp-core-ui \.field-kit__password-remove \{[^}]*min-height: 30px;[^}]*text-decoration: none;/',
			$this->css()
		);

		// Still hidden when hidden.
		$this->assertMatchesRegularExpression( '/\.wp-core-ui \.field-kit__color-clear\[hidden\],\s*\.wp-core-ui \.field-kit__icon-clear\[hidden\] \{\s*display: none;/', $this->css() );
	}

	/**
	 * The combobox's clear button has the kit's focus ring.
	 */
	public function test_the_clear_cross_has_the_focus_ring(): void {
		$this->assertMatchesRegularExpression( '/\.field-kit__combobox-clear:focus-visible \{[^}]*outline: var\(--wp-admin-border-width-focus, 2px\) solid/', $this->css() );
	}

	/**
	 * Render a multiple select.
	 *
	 * @param array<string, mixed> $config Field configuration.
	 *
	 * @return string
	 */
	private function multiple( array $config = [] ): string {
		$set = new FieldSet(
			[
				'sizes' => array_merge(
					[
						'type'    => 'select_multiple',
						'label'   => 'Sizes',
						'options' => [
							's' => 'Small',
							'l' => 'Large',
						],
					],
					$config
				),
			],
			new ArrayContext(),
			''
		);

		return $set->render_field( $set->field( 'sizes' ) );
	}

	/**
	 * A multiple select is the chips combobox, and the listbox only when a
	 * field asks for it.
	 */
	public function test_a_multiple_select_is_the_chips_combobox(): void {
		$this->assertMatchesRegularExpression( '/<select[^>]*class="[^"]*field-kit__select--enhanced[^"]*"[^>]*multiple/', $this->multiple() );
		$this->assertStringNotContainsString( 'field-kit__select--enhanced', $this->multiple( [ 'searchable' => false ] ) );
	}
}
