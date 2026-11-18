<?php
/**
 * Narrow screen tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * At 390px the post screen, the profile screen and quick edit scrolled
 * sideways, and core's own versions of those screens did not.
 *
 * Every one of those screens sizes a box to its contents before it knows how
 * wide it is -- a form-table cell, the flex row quick edit is drawn in -- so
 * the question that matters is how narrow a field can go, not how wide it is
 * drawn. Two answers were wrong. A card group answered 564px, because auto-fit
 * counted three columns from a bare 45rem cap. A text, URL or email input
 * answered 400px, because core's regular-text is a fixed 25em and a max-width
 * caps what is drawn, not what is asked for.
 *
 * Neither is visible in the markup and neither raises, so the rules are
 * pinned here. The fix in both is a percentage, which is what lets a browser
 * treat the box as shrinkable while it measures.
 */
final class NarrowScreenTest extends TestCase {

	/**
	 * The stylesheet with its comments removed.
	 *
	 * @return string
	 */
	private function css(): string {
		return (string) preg_replace(
			'#/\*.*?\*/#s',
			'',
			(string) file_get_contents( dirname( __DIR__ ) . '/assets/css/field-kit.css' )
		);
	}

	/**
	 * Render a field.
	 *
	 * @param string $type Type id.
	 *
	 * @return string
	 */
	private function render( string $type ): string {
		$field = new Field(
			'value',
			( new Registry() )->get( $type ),
			[
				'label' => 'Value',
				'input_name' => 'value',
			],
			null
		);

		return ( new Renderer() )->render( $field );
	}

	/**
	 * A grid that counts its own columns never counts them from a fixed cap.
	 *
	 * The general form of the card bug: auto-fit and auto-fill take a definite
	 * max-width as the room they have when the width itself is not known yet,
	 * so a cap of 45rem is three 180px columns of min-content in any cell that
	 * sizes itself to what is in it.
	 *
	 * @return void
	 */
	public function test_a_self_counting_grid_has_no_fixed_cap(): void {
		preg_match_all( '/([^{}]+)\{([^{}]*repeat\(\s*auto-(?:fit|fill)[^{}]*)\}/', $this->css(), $rules, PREG_SET_ORDER );

		$this->assertNotEmpty( $rules );

		$cards = false;

		foreach ( $rules as [ , $selector, $body ] ) {
			$cards = $cards || str_contains( $selector, '.field-kit__cards' );

			if ( ! preg_match( '/(?:^|;|\s)max-(?:width|inline-size)\s*:\s*([^;]+)/', $body, $cap ) ) {
				continue;
			}

			$this->assertStringContainsString(
				'%',
				$cap[1],
				trim( $selector ) . ' counts its columns from a fixed max-width; it holds a table cell at that many columns.'
			);
		}

		$this->assertTrue( $cards, 'The card grid no longer counts its own columns; revisit this test.' );
	}

	/**
	 * A field's own text input can be narrower than core's 25em.
	 *
	 * @return void
	 */
	public function test_a_plain_input_can_shrink_below_core_width(): void {
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $this->css(), $rules, PREG_SET_ORDER );

		$width = null;

		foreach ( $rules as [ , $selector, $body ] ) {
			if ( ! preg_match( '/\.field-kit__field\)?\s*>\s*\.regular-text\s*$/', trim( $selector ) ) ) {
				continue;
			}

			if ( preg_match( '/(?:^|;|\s)width\s*:\s*([^;]+)/', $body, $match ) ) {
				$width = trim( $match[1] );
			}
		}

		$this->assertNotNull( $width, 'No rule sizes a field\'s own regular-text input.' );
		$this->assertStringContainsString( '%', $width, 'A fixed width holds quick edit at 400px on a phone.' );
		$this->assertStringContainsString( '25em', $width, 'The input no longer stops at core\'s width where it fits.' );

		// And the selector reaches the inputs it was written for: each plain
		// input is the field wrapper's own child, not inside a composite.
		foreach ( [ 'text', 'url', 'email' ] as $type ) {
			$this->assertMatchesRegularExpression(
				'#<div class="field-kit__field[^"]*"[^>]*>(?:\s*<label[^>]*>.*?</label>)?\s*<input[^>]*class="regular-text#s',
				$this->render( $type ),
				"The {$type} input is not the wrapper's own child, so the shrink rule misses it."
			);
		}
	}
}
