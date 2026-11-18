<?php
/**
 * Sanitiser edge tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Icons\Svg;
use SugarCommerce\Fields\Types\CheckboxType;
use SugarCommerce\Fields\Value;

/**
 * The edges a mutation run of the sanitisers found nothing holding: each
 * test here failed against a deliberately broken copy of the rule it names
 * -- a comparison flipped, a strict check made loose, a default nudged --
 * while the rest of the suite still passed.
 */
final class SanitiserEdgesTest extends TestCase {

	/**
	 * Save one field and return what was stored.
	 *
	 * @param array<string, mixed> $config Field configuration.
	 * @param mixed                $input  Submitted value.
	 *
	 * @return mixed
	 */
	private function stored( array $config, mixed $input ): mixed {
		$context = new ArrayContext();
		$set     = new FieldSet( [ 'x' => $config + [ 'label' => 'X' ] ], $context, '' );

		$set->save( [ 'x' => $input ] );

		return $context->values()['x'] ?? null;
	}

	/**
	 * A checkbox is on for exactly the five spellings of on, compared
	 * strictly: a loose comparison turns every non-empty string into true.
	 */
	public function test_a_checkbox_is_on_only_when_it_says_so(): void {
		foreach ( [ 1, '1', true, 'yes', 'on' ] as $on ) {
			$this->assertSame( 1, $this->stored( [ 'type' => 'checkbox' ], $on ), var_export( $on, true ) );
		}

		foreach ( [ 'no', 'off', 'false', '2', 2, '0', 0, '' ] as $off ) {
			$this->assertSame( 0, $this->stored( [ 'type' => 'checkbox' ], $off ), var_export( $off, true ) );
		}

		// Straight to the type as well: a value that is not a submission --
		// an import, a REST write -- arrives as whatever PHP type it was.
		$type  = new CheckboxType();
		$field = new Field( 'x', $type, [ 'type' => 'checkbox' ] );

		$this->assertSame( 1, $type->sanitize( 1, $field ) );
		$this->assertSame( 1, $type->sanitize( true, $field ) );
		$this->assertSame( 0, $type->sanitize( 2, $field ) );
		$this->assertSame( 0, $type->sanitize( false, $field ) );
	}

	/**
	 * A checkbox draws checked for the same five, and only those.
	 */
	public function test_a_checkbox_draws_checked_for_the_same_values(): void {
		foreach ( [ [ 1, true ], [ true, true ], [ 'yes', true ], [ 'no', false ], [ 2, false ], [ false, false ] ] as [ $stored, $checked ] ) {
			$set  = new FieldSet( [ 'x' => [ 'type' => 'checkbox', 'label' => 'X' ] ], new ArrayContext( [ 'x' => $stored ] ), '' );
			$html = $set->render();

			$this->assertSame( $checked, str_contains( $html, 'checked' ), var_export( $stored, true ) );
		}
	}

	/**
	 * A checkbox group keeps the offered values that were ticked.
	 */
	public function test_a_checkbox_group_keeps_what_was_ticked(): void {
		$config = [
			'type'    => 'checkbox_group',
			'options' => [ 'a' => 'A', 'b' => 'B', 'c' => 'C' ],
		];

		$this->assertSame( [ 'a', 'c' ], $this->stored( $config, [ 'a', '', 'c', 'z' ] ) );
	}

	/**
	 * A choice is matched as text, exactly: "1.0" is not the option "1".
	 */
	public function test_a_choice_is_matched_exactly(): void {
		$options = [ '1' => 'One', '2' => 'Two' ];

		// Refused is stored as nothing at all.
		$this->assertNull( $this->stored( [ 'type' => 'radio', 'options' => $options ], '1.0' ) );
		$this->assertSame( '1', $this->stored( [ 'type' => 'radio', 'options' => $options ], '1' ) );
		$this->assertNull( $this->stored( [ 'type' => 'select', 'options' => $options ], '1.0' ) );
		$this->assertSame( '2', $this->stored( [ 'type' => 'select', 'options' => $options ], '2' ) );
	}

	/**
	 * A select_multiple is a list, whatever its configuration says.
	 */
	public function test_a_select_multiple_stores_a_list(): void {
		$config = [
			'type'    => 'select_multiple',
			'options' => [ 'a' => 'A', 'b' => 'B', 'c' => 'C' ],
		];

		$this->assertSame( [ 'a', 'b' ], $this->stored( $config, [ 'a', 'b', 'z' ] ) );
	}

	/**
	 * A number with nothing usable is its minimum, or zero without one; the
	 * step decides whether it keeps a fraction.
	 */
	public function test_a_number_falls_back_and_keeps_its_kind(): void {
		$this->assertSame( 0, $this->stored( [ 'type' => 'number' ], 'abc' ) );
		$this->assertSame( 3, $this->stored( [ 'type' => 'number', 'min' => 3 ], 'abc' ) );
		$this->assertSame( 0.0, $this->stored( [ 'type' => 'number', 'step' => 'any' ], 'abc' ) );
		$this->assertSame( 1.5, $this->stored( [ 'type' => 'number', 'step' => 'any' ], '1.5' ) );
		$this->assertSame( 1.5, $this->stored( [ 'type' => 'number', 'step' => '0.5' ], '1.5' ) );
		$this->assertSame( 1, $this->stored( [ 'type' => 'number', 'step' => 'nonsense' ], '1.5' ) );
		$this->assertSame( 1, $this->stored( [ 'type' => 'number' ], '1.5' ) );
	}

	/**
	 * A dimension of zero is a measurement, not a blank.
	 */
	public function test_a_zero_dimension_is_kept(): void {
		$this->assertSame( [ 'width' => 0.0, 'height' => '' ], $this->stored( [ 'type' => 'dimensions' ], [ 'width' => '0', 'height' => 'wide' ] ) );
	}

	/**
	 * A gallery with no limit keeps every image.
	 */
	public function test_a_gallery_without_a_limit_keeps_every_image(): void {
		$this->assertSame( [ 4, 5, 6 ], $this->stored( [ 'type' => 'gallery' ], '4,5,6' ) );
		$this->assertSame( [ 4, 5 ], $this->stored( [ 'type' => 'gallery', 'max_items' => 2 ], '4,5,6' ) );
		$this->assertSame( [ 4 ], $this->stored( [ 'type' => 'gallery', 'max_items' => 1 ], '4,5,6' ) );
	}

	/**
	 * A link opens in a new tab only when it asks to.
	 */
	public function test_a_link_target_is_only_ever_blank_on_request(): void {
		$plain = $this->stored( [ 'type' => 'link' ], [ 'url' => 'https://example.com', 'text' => 'Home' ] );
		$blank = $this->stored( [ 'type' => 'link' ], [ 'url' => 'https://example.com', 'text' => 'Home', 'target' => '_blank' ] );
		$odd   = $this->stored( [ 'type' => 'link' ], [ 'url' => 'https://example.com', 'target' => '_top' ] );

		$this->assertSame( '', $plain['target'] );
		$this->assertSame( '_blank', $blank['target'] );
		$this->assertSame( '', $odd['target'] );
	}

	/**
	 * A single creatable relational field keeps the one thing typed.
	 */
	public function test_a_single_creatable_relation_keeps_its_text(): void {
		$config = [
			'type'      => 'taxonomy',
			'taxonomy'  => 'post_tag',
			'creatable' => true,
			'multiple'  => false,
		];

		$this->assertSame( 'New tag', $this->stored( $config, [ 'New tag', 'Second' ] ) );
	}

	/**
	 * A tags field with no rule is checked only for being required.
	 */
	public function test_tags_without_a_rule_validate(): void {
		$context = new ArrayContext();
		$set     = new FieldSet( [ 'x' => [ 'type' => 'tags', 'label' => 'X' ] ], $context, '' );

		$set->save( [ 'x' => [ 'one', 'two' ] ] );

		$this->assertSame( [], $set->errors() );
		$this->assertSame( [ 'one', 'two' ], $context->values()['x'] );
	}

	/**
	 * A url rule wants a well-formed address, not merely a scheme and a host.
	 */
	public function test_the_url_rule_refuses_a_malformed_address(): void {
		$context = new ArrayContext();
		$set     = new FieldSet( [ 'x' => [ 'type' => 'text', 'label' => 'X', 'validate' => 'url' ] ], $context, '' );

		$set->save( [ 'x' => 'http://exa mple.com' ] );

		$this->assertArrayHasKey( 'x', $set->errors() );
	}

	/**
	 * Post 1 is a post.
	 */
	public function test_the_first_post_can_be_on_screen(): void {
		$GLOBALS['fk_is_singular']    = true;
		$GLOBALS['fk_queried_object'] = 1;

		try {
			$this->assertTrue( Value::is_viewing( 1 ) );
		} finally {
			unset( $GLOBALS['fk_is_singular'], $GLOBALS['fk_queried_object'] );
		}
	}

	/**
	 * A DOCTYPE is refused on its own, entity or not.
	 */
	public function test_an_icon_with_a_doctype_is_refused(): void {
		$this->assertSame( '', Svg::sanitize( '<!DOCTYPE svg><svg viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></svg>' ) );
	}

	/**
	 * Only an svg or a symbol is an icon.
	 */
	public function test_only_an_svg_or_a_symbol_is_an_icon(): void {
		$this->assertSame( '', Svg::sanitize( '<g viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></g>' ) );
		$this->assertNotSame( '', Svg::sanitize( '<symbol viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></symbol>' ) );
		$this->assertNotSame( '', Svg::sanitize( '<SVG viewBox="0 0 24 24"><path d="M0 0h24v24H0z"/></SVG>' ) );
	}

	/**
	 * Without a viewBox, a width and a height make one -- both of them, and
	 * both above zero.
	 */
	public function test_a_viewbox_is_made_from_a_size(): void {
		$this->assertStringContainsString( 'viewBox="0 0 1 1"', Svg::sanitize( '<svg width="1" height="1"><path d="M0 0h1v1H0z"/></svg>' ) );
		$this->assertSame( '', Svg::sanitize( '<svg width="0" height="24"><path d="M0 0h24v24H0z"/></svg>' ) );
		$this->assertSame( '', Svg::sanitize( '<svg width="24" height="0"><path d="M0 0h24v24H0z"/></svg>' ) );
		$this->assertSame( '', Svg::sanitize( '<svg><path d="M0 0h24v24H0z"/></svg>' ) );
	}

	/**
	 * An icon that strokes is not also filled.
	 */
	public function test_a_stroked_icon_is_not_given_a_fill(): void {
		$svg = Svg::sanitize( '<svg viewBox="0 0 24 24" stroke="currentColor"><path d="M0 0h24"/></svg>' );

		$this->assertStringNotContainsString( 'fill="currentColor"', $svg );
		$this->assertStringContainsString( 'fill="currentColor"', Svg::sanitize( '<svg viewBox="0 0 24 24"><path d="M0 0h24"/></svg>' ) );
	}

	/**
	 * The size limit is inclusive: exactly the limit is read, a byte more
	 * is not.
	 */
	public function test_the_size_limit_is_inclusive(): void {
		$head = '<svg viewBox="0 0 24 24"';
		$tail = '><path d="M0 0h24"/></svg>';
		$fill = 65536 - strlen( $head ) - strlen( $tail );

		$this->assertNotSame( '', Svg::sanitize( $head . str_repeat( ' ', $fill ) . $tail ) );
		$this->assertSame( '', Svg::sanitize( $head . str_repeat( ' ', $fill + 1 ) . $tail ) );
	}
}
