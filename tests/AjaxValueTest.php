<?php
/**
 * An ajax field keeps a chosen value that is not a number.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use PHPUnit\Framework\TestCase;

/**
 * Its own search callback decides the ids, and sanitize() stores them as
 * text. Rendering read them as integers, so `small` became 0 and was
 * dropped: the saved choice opened as nothing chosen, and the next save
 * emptied it.
 */
final class AjaxValueTest extends TestCase {

	/**
	 * The callback the field searches with, labelling as it is asked.
	 *
	 * @param string $term What was typed.
	 * @param array  $ids  The ids to label, when labelling.
	 *
	 * @return array<int, array{id: string, text: string}>
	 */
	public static function search( string $term, array $ids = [] ): array {
		$all = [
			[ 'id' => 'small', 'text' => 'Small' ],
			[ 'id' => 'large', 'text' => 'Large' ],
			[ 'id' => '12', 'text' => 'Twelve' ],
		];

		return array_values( array_filter( $all, static fn( $one ) => [] === $ids || in_array( $one['id'], array_map( 'strval', $ids ), true ) ) );
	}

	/**
	 * Render the field over a stored value.
	 *
	 * @param mixed $stored   What is stored.
	 * @param bool  $multiple Several values.
	 *
	 * @return string
	 */
	private function render( mixed $stored, bool $multiple = false ): string {
		$set = new FieldSet(
			[
				'size' => [
					'type'            => 'ajax',
					'label'           => 'Size',
					'multiple'        => $multiple,
					'search_callback' => [ self::class, 'search' ],
				],
			],
			new ArrayContext( [ 'size' => $stored ] ),
			''
		);

		return $set->render_field( $set->field( 'size' ) );
	}

	/**
	 * A text id comes back chosen, under its label.
	 */
	public function test_a_text_value_is_shown_chosen(): void {
		$this->assertStringContainsString( '<option value="small" selected>Small</option>', $this->render( 'small' ) );
	}

	/**
	 * Several, and a number among them, the same.
	 */
	public function test_several_values_are_shown_chosen(): void {
		$markup = $this->render( [ 'large', '12' ], true );

		$this->assertStringContainsString( '<option value="large" selected>Large</option>', $markup );
		$this->assertStringContainsString( '<option value="12" selected>Twelve</option>', $markup );
	}

	/**
	 * Nothing stored is nothing chosen.
	 */
	public function test_nothing_stored_chooses_nothing(): void {
		$this->assertStringNotContainsString( ' selected>', $this->render( '' ) );
	}
}
