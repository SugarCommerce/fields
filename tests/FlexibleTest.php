<?php
/**
 * Flexible content tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Contracts\AdminOnly;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Fields\Support\Dependencies;
use SugarCommerce\Fields\Types\FlexibleType;

/**
 * Rows that are each one of several layouts: the shape they are stored in,
 * each sanitised against its own layout, the limits per layout and in all,
 * a layout taken out of the configuration, and drawing them on the front of
 * the site.
 */
final class FlexibleTest extends TestCase {

	/**
	 * Three layouts: a hero titled by its heading, at most one; a text block
	 * at least one; a divider with nothing to fill in.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function layouts(): array {
		return [
			'hero'    => [
				'label'     => 'Hero',
				'max'       => 1,
				'row_title' => 'heading',
				'fields'    => [
					'heading' => [
						'type' => 'text',
						'label' => 'Heading',
					],
					'dark'    => [
						'type' => 'toggle',
						'label' => 'Dark',
					],
				],
			],
			'text'    => [
				'label'  => 'Text',
				'min'    => 1,
				'fields' => [
					'body'  => [
						'type' => 'textarea',
						'label' => 'Body',
					],
					'count' => [
						'type' => 'number',
						'label' => 'Count',
					],
				],
			],
			'divider' => [ 'label' => 'Divider' ],
		];
	}

	/**
	 * A flexible field.
	 *
	 * @param array<string, mixed> $config Extra configuration.
	 * @param mixed                $value  What it holds.
	 *
	 * @return Field
	 */
	private function field( array $config = [], mixed $value = null ): Field {
		$type = ( new Registry() )->get( 'flexible' );

		return new Field(
			'page',
			$type,
			array_merge(
				$type->defaults(),
				[
					'label'      => 'Page',
					'input_name' => 'page',
					'input_id'   => 'page',
					'layouts'    => $this->layouts(),
				],
				$config
			),
			$value
		);
	}

	/**
	 * Sanitise a submission.
	 *
	 * @param mixed                $value  Submitted rows.
	 * @param array<string, mixed> $config Extra configuration.
	 *
	 * @return mixed
	 */
	private function clean( mixed $value, array $config = [] ): mixed {
		$field = $this->field( $config );

		return $field->type()->sanitize( $value, $field );
	}

	public function test_rows_are_stored_as_a_layout_beside_its_values(): void {
		$this->assertSame(
			[
				[
					'layout' => 'hero',
					'values' => [
						'heading' => 'Welcome',
						'dark'    => 1,
					],
				],
				[
					'layout' => 'text',
					'values' => [
						'body'  => 'Hello',
						'count' => 3,
					],
				],
			],
			$this->clean(
				[
					4 => [
						'layout' => 'hero',
						'values' => [
							'heading' => ' Welcome ',
							'dark'    => '1',
							'smuggle' => 'x',
						],
					],
					9 => [
						'layout' => 'text',
						'values' => [
							'body'  => 'Hello',
							'count' => '3',
						],
					],
				]
			)
		);
	}

	public function test_each_row_is_sanitised_by_its_own_layout(): void {
		$rows = $this->clean(
			[
				[
					'layout' => 'text',
					'values' => [
						'heading' => 'Not a text field',
						'body'    => 'Kept',
					],
				],
			]
		);

		$this->assertSame( [ 'body', 'count' ], array_keys( $rows[0]['values'] ) );
	}

	public function test_a_row_without_a_layout_or_anything_in_it_is_dropped(): void {
		$this->assertSame(
			[
				[
					'layout' => 'divider',
					'values' => [],
				],
			],
			$this->clean(
				[
					[ 'values' => [ 'body' => 'No layout' ] ],
					[
						'layout' => 'text',
						'values' => [
							'body'  => '',
							'count' => '',
						],
					],
					[ 'layout' => 'divider' ],
					'not a row',
				]
			),
			'An empty text row goes; a divider has nothing to fill in and stays.'
		);
	}

	public function test_a_layout_past_its_maximum_loses_the_later_rows(): void {
		$rows = $this->clean(
			[
				[
					'layout' => 'hero',
					'values' => [ 'heading' => 'First' ],
				],
				[
					'layout' => 'hero',
					'values' => [ 'heading' => 'Second' ],
				],
				[
					'layout' => 'text',
					'values' => [ 'body' => 'After' ],
				],
			]
		);

		$this->assertSame( [ 'hero', 'text' ], array_column( $rows, 'layout' ) );
		$this->assertSame( 'First', $rows[0]['values']['heading'] );
	}

	public function test_the_field_keeps_at_most_max_rows(): void {
		$rows = $this->clean(
			[
				[ 'layout' => 'divider' ],
				[ 'layout' => 'divider' ],
				[ 'layout' => 'divider' ],
			],
			[ 'max_rows' => 2 ]
		);

		$this->assertCount( 2, $rows );
	}

	public function test_a_row_of_a_layout_that_has_gone_is_kept_as_text(): void {
		$rows = $this->clean(
			[
				[
					'layout' => 'quote',
					'values' => [
						'text'   => '<b>Kept</b>',
						'nested' => [ 'a' => 'b' ],
						'Odd Key' => 'x',
					],
				],
			]
		);

		$this->assertSame(
			[
				[
					'layout' => 'quote',
					'values' => [
						'text'   => 'Kept',
						'nested' => [ 'a' => 'b' ],
						'oddkey' => 'x',
					],
				],
			],
			$rows
		);
	}

	public function test_a_row_of_a_layout_that_has_gone_is_drawn_as_a_notice_and_posted_back(): void {
		$html = ( new Renderer() )->render(
			$this->field(
				[],
				[
					[
						'layout' => 'quote',
						'values' => [
							'text'   => 'Kept',
							'nested' => [ 'a' => 'b' ],
						],
					],
				]
			)
		);

		$this->assertStringContainsString( 'The “quote” layout is no longer available.', $html );
		$this->assertStringContainsString( 'name="page[0][values][text]" value="Kept"', $html );
		$this->assertStringContainsString( 'name="page[0][values][nested][a]" value="b"', $html );
		$this->assertMatchesRegularExpression( '/field-kit__repeater-duplicate[^>]*disabled/', $html, 'A row with no layout cannot be copied.' );
	}

	public function test_fewer_rows_than_a_minimum_are_refused_but_empty_is_a_choice(): void {
		$field = $this->field( [ 'min_rows' => 2 ] );
		$type  = $field->type();

		$this->assertSame( '', $type->validate( [], $field ) );
		$this->assertSame(
			'Page needs at least 2 rows.',
			$type->validate(
				[
					[
						'layout' => 'text',
						'values' => [ 'body' => 'x' ],
					],
				],
				$field
			)
		);
		$this->assertSame(
			'Page needs at least 1 “Text” row.',
			$type->validate(
				[
					[ 'layout' => 'divider' ],
					[ 'layout' => 'divider' ],
				],
				$field
			)
		);
		$this->assertSame(
			'',
			$type->validate(
				[
					[ 'layout' => 'divider' ],
					[
						'layout' => 'text',
						'values' => [ 'body' => 'x' ],
					],
				],
				$field
			)
		);
	}

	public function test_a_minimum_is_refused_through_the_set_too(): void {
		$set = new FieldSet(
			[
				'page' => [
					'type'    => 'flexible',
					'label'   => 'Page',
					'layouts' => $this->layouts(),
				],
			],
			new ArrayContext( [] ),
			''
		);

		$errors = $set->validate( [ 'page' => [ [ 'layout' => 'divider' ] ] ] );

		$this->assertSame( 'Page needs at least 1 “Text” row.', $errors['page'] ?? '' );
	}

	public function test_it_round_trips_through_a_field_set(): void {
		$context = new ArrayContext( [] );
		$set     = new FieldSet(
			[
				'page' => [
					'type'    => 'flexible',
					'label'   => 'Page',
					'layouts' => $this->layouts(),
				],
			],
			$context,
			''
		);

		$set->save(
			[
				'page' => [
					[
						'layout' => 'text',
						'values' => [ 'body' => 'Stored' ],
					],
				],
			]
		);

		$this->assertSame( 'Stored', $context->read( 0, $set->field( 'page' ) )[0]['values']['body'] );
		$this->assertStringContainsString( '>Stored</textarea>', $set->render() );
	}

	public function test_each_row_is_drawn_against_its_layout_with_its_name_and_controls(): void {
		$html = ( new Renderer() )->render(
			$this->field(
				[],
				[
					[
						'layout' => 'hero',
						'values' => [ 'heading' => 'Welcome' ],
					],
					[
						'layout' => 'text',
						'values' => [ 'body' => '' ],
					],
				]
			)
		);

		$this->assertStringContainsString( '<input type="hidden" name="page[0][layout]" value="hero" />', $html );
		$this->assertStringContainsString( 'name="page[0][values][heading]"', $html );
		$this->assertStringContainsString( 'name="page[1][values][body]"', $html );
		$this->assertStringContainsString( '<span class="field-kit__flexible-badge">Hero</span>', $html );
		$this->assertStringContainsString( 'data-fallback="">Welcome</span>', $html, 'A hero is titled by its heading.' );
		$this->assertStringContainsString( 'data-row-title="heading"', $html );
		$this->assertStringContainsString( 'Row 1 of 2, Hero', $html );
		$this->assertStringContainsString( 'aria-label="Duplicate — Row 2 of 2, Text"', $html );
		$this->assertStringContainsString( 'aria-label="Remove — Row 2 of 2, Text"', $html );
		$this->assertMatchesRegularExpression( '/class="[^"]*field-kit__flexible-row is-closed"[^>]*data-layout="hero"/', $html, 'A row with content starts closed.' );
		$this->assertDoesNotMatchRegularExpression( '/is-closed"[^>]*data-layout="text"/', $html, 'An empty one starts open.' );
	}

	public function test_a_template_per_layout_and_a_button_per_layout(): void {
		$html = ( new Renderer() )->render(
			$this->field(
				[],
				[
					[
						'layout' => 'hero',
						'values' => [ 'heading' => 'Only one' ],
					],
				]
			)
		);

		foreach ( [ 'hero', 'text', 'divider' ] as $layout ) {
			$this->assertStringContainsString( '<template class="field-kit__repeater-template" data-layout="' . $layout . '">', $html );
		}

		$this->assertStringContainsString( 'name="page[-1][layout]" value="text"', $html );
		$this->assertMatchesRegularExpression( '/data-layout="hero" data-min="0" data-max="1" disabled>Add Hero</', $html, 'A layout at its maximum cannot be added.' );
		$this->assertStringContainsString( 'data-layout="text" data-min="1" data-max="0">Add Text<', $html );
		$this->assertStringNotContainsString( 'field-kit__flexible-layout', $html );
	}

	public function test_many_layouts_are_a_list_and_one_button(): void {
		$layouts = $this->layouts();

		foreach ( [ 'a', 'b', 'c' ] as $extra ) {
			$layouts[ $extra ] = [ 'label' => strtoupper( $extra ) ];
		}

		$html = ( new Renderer() )->render( $this->field( [ 'layouts' => $layouts ] ) );

		$this->assertStringContainsString( '<select class="field-kit__flexible-layout" aria-label="Layout to add">', $html );
		$this->assertStringContainsString( '<option value="text" data-min="1" data-max="0">Text</option>', $html );
		$this->assertSame( 1, substr_count( $html, 'field-kit__repeater-add' ) );
	}

	public function test_the_add_buttons_go_off_at_the_fields_maximum(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'max_rows' => 1 ], [ [ 'layout' => 'divider' ] ] ) );

		$this->assertSame( 3, preg_match_all( '/field-kit__repeater-add"[^>]*disabled/', $html ) );
	}

	public function test_a_field_with_no_layouts_says_so(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'layouts' => [] ] ) );

		$this->assertStringContainsString( 'This field has no layouts configured.', $html );
	}

	public function test_layouts_are_normalised(): void {
		$field = $this->field(
			[
				'layouts' => [
					'Big Hero!' => [ 'fields' => 'nope' ],
					''          => [ 'label' => 'Nameless' ],
					'plain'     => 'not a layout',
					'limits'    => [
						'min' => -2,
						'max' => '3',
					],
				],
			]
		);

		$layouts = $field->type()->layouts( $field );

		$this->assertSame( [ 'bighero', 'limits' ], array_keys( $layouts ) );
		$this->assertSame( 'bighero', $layouts['bighero']['label'] );
		$this->assertSame( [], $layouts['bighero']['fields'] );
		$this->assertSame( 0, $layouts['limits']['min'] );
		$this->assertSame( 3, $layouts['limits']['max'] );
	}

	public function test_display_hands_each_row_to_what_draws_its_layout(): void {
		$rows = [
			[
				'layout' => 'hero',
				'values' => [ 'heading' => 'Hi' ],
			],
			[ 'layout' => 'divider' ],
			[
				'layout' => 'quote',
				'values' => [ 'text' => 'Q' ],
			],
			[ 'values' => [ 'no' => 'layout' ] ],
		];

		$this->assertSame(
			'<h2>Hi</h2><hr data-index="1" />',
			FlexibleType::display(
				$rows,
				[
					'hero'    => static fn( array $values ): string => '<h2>' . $values['heading'] . '</h2>',
					'divider' => static fn( array $values, string $layout, int $index ): string => '<hr data-index="' . $index . '" />',
				]
			)
		);

		$this->assertSame(
			'[hero][divider][quote]',
			FlexibleType::display( $rows, [ '*' => static fn( array $values, string $layout ): string => '[' . $layout . ']' ] )
		);

		$this->assertSame( '', FlexibleType::display( 'not rows', [ '*' => 'strtoupper' ] ) );
	}

	public function test_display_can_use_a_template_part_per_layout(): void {
		$GLOBALS['fk_template_parts'] = [];

		$html = FlexibleType::display(
			[
				[
					'layout' => 'hero',
					'values' => [ 'heading' => 'Hi' ],
				],
			],
			'parts/flexible'
		);

		$this->assertSame( '<part parts/flexible-hero>', $html );
		$this->assertSame(
			[
				[
					'parts/flexible',
					'hero',
					[
						'layout' => 'hero',
						'values' => [ 'heading' => 'Hi' ],
						'index'  => 0,
					],
				],
			],
			$GLOBALS['fk_template_parts']
		);
	}

	public function test_a_layouts_fields_are_asked_what_they_need(): void {
		$needs = Dependencies::of(
			[
				[
					'type'    => 'flexible',
					'layouts' => [
						'text'  => [ 'fields' => [ 'body' => [ 'type' => 'wysiwyg' ] ] ],
						'photo' => [ 'fields' => [ 'image' => [ 'type' => 'image' ] ] ],
					],
				],
			]
		);

		$this->assertTrue( $needs['editor'] );
		$this->assertContains( 'media-views', $needs['scripts'] );
	}

	public function test_its_schema_says_what_every_row_has(): void {
		$schema = $this->field()->type()->schema( $this->field() );

		$this->assertSame( 'array', $schema['type'] );
		$this->assertSame( [ 'type' => 'string' ], $schema['items']['properties']['layout'] );
		$this->assertSame( 'object', $schema['items']['properties']['values']['type'] );
	}

	public function test_it_is_edited_in_wp_admin_and_drawn_elsewhere(): void {
		$this->assertInstanceOf( AdminOnly::class, ( new Registry() )->get( 'flexible' ) );
	}
}
