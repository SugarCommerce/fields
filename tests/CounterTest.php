<?php
/**
 * The character count.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * A limit worth setting is worth showing. The browser stops anyone typing
 * past a maxlength and says nothing about it, so a meta description cut
 * off at 160 characters looks like a keyboard that stopped working.
 *
 * The count is rendered with the saved value's length already in it, so
 * the field reads right before the script runs; the script only keeps it
 * moving.
 */
final class CounterTest extends TestCase {

	public function test_nothing_counts_without_a_limit(): void {
		$this->assertStringNotContainsString( 'data-counter', $this->render( 'text', [], 'hello' ) );
	}

	public function test_the_count_starts_at_the_current_length(): void {
		$html = $this->render( 'text', [ 'maxlength' => 200 ], 'Hello' );

		$this->assertStringContainsString( 'maxlength="200"', $html );
		$this->assertMatchesRegularExpression(
			'/<input[^>]*\/><span class="field-kit__count description regular-text" data-counter aria-live="polite">5 \/ 200<\/span>/',
			$html
		);
	}

	public function test_characters_are_counted_and_not_bytes(): void {
		$this->assertStringContainsString( '>5 / 10<', $this->render( 'text', [ 'maxlength' => 10 ], 'héllo' ) );
	}

	/**
	 * A postcode has a limit and no use for a count.
	 */
	public function test_it_can_be_turned_off(): void {
		$html = $this->render( 'text', [ 'maxlength' => 8, 'counter' => false ], 'AB1 2CD' );

		$this->assertStringContainsString( 'maxlength="8"', $html );
		$this->assertStringNotContainsString( 'data-counter', $html );
	}

	/**
	 * The count is as wide as the control it sits under, so its number
	 * lands at the control's end rather than the row's.
	 */
	public function test_the_count_is_as_wide_as_its_control(): void {
		$this->assertStringContainsString(
			'class="field-kit__count description large-text"',
			$this->render( 'text', [ 'maxlength' => 5, 'size' => 'large' ], '' )
		);
		$this->assertStringContainsString(
			'class="field-kit__count description"',
			$this->render( 'text', [ 'maxlength' => 5, 'size' => 'none' ], '' )
		);
	}

	public function test_a_textarea_counts_too(): void {
		$this->assertMatchesRegularExpression(
			'/<\/textarea><span class="field-kit__count description large-text" data-counter aria-live="polite">7 \/ 500<\/span>/',
			$this->render( 'textarea', [ 'maxlength' => 500 ], "one\ntwo" )
		);
		$this->assertStringNotContainsString(
			'data-counter',
			$this->render( 'textarea', [ 'maxlength' => 500, 'counter' => false ], 'x' )
		);
	}

	/**
	 * A password never renders its value, so it has nothing to count -- and
	 * a count of the saved secret's length would be a small leak.
	 */
	public function test_a_password_counts_nothing_it_has_not_shown(): void {
		$html = $this->render( 'password', [ 'maxlength' => 64, 'reveal' => true ], 'hunter2' );

		$this->assertStringContainsString( '>0 / 64<', $html );

		// After the whole control, not between the input and its button.
		$this->assertMatchesRegularExpression( '/<\/button><\/span><span class="field-kit__count/', $html );
	}

	/**
	 * The browser stops anyone typing past the limit; it does not truncate
	 * what was saved before the limit was lowered.
	 */
	public function test_a_prefilled_value_over_the_limit_is_marked(): void {
		$html = $this->render( 'text', [ 'maxlength' => 3 ], 'four' );

		$this->assertStringContainsString( 'field-kit__count--over', $html );
		$this->assertStringContainsString( '>4 / 3<', $html );
		$this->assertStringNotContainsString(
			'field-kit__count--over',
			$this->render( 'text', [ 'maxlength' => 4 ], 'four' )
		);
	}

	public function test_it_follows_an_adorned_control(): void {
		$this->assertMatchesRegularExpression(
			'/adornment--suffix.*<\/span><\/span><span class="field-kit__count/s',
			$this->render( 'text', [ 'maxlength' => 20, 'suffix' => 'px' ], '12' )
		);
	}

	/**
	 * A soft limit is a length to aim for: counted against, warned past,
	 * and never a refusal.
	 */
	public function test_a_soft_limit_counts_and_warns(): void {
		$html = $this->render( 'text', [ 'soft_limit' => 5 ], 'abcdef' );

		$this->assertStringContainsString( 'data-soft="5"', $html );
		$this->assertStringContainsString( '>6 / 5<', $html );
		$this->assertStringContainsString( 'field-kit__count--soft', $html );
		$this->assertStringNotContainsString( 'field-kit__count--over', $html );
		$this->assertStringNotContainsString( 'maxlength', $html );
		$this->assertStringNotContainsString( 'field-kit__count--soft', $this->render( 'text', [ 'soft_limit' => 6 ], 'abcdef' ) );
	}

	public function test_both_limits_name_each_other(): void {
		$this->assertStringContainsString( '>3 / 60 (up to 160)<', $this->render( 'textarea', [ 'soft_limit' => 60, 'maxlength' => 160 ], 'abc' ) );
		$this->assertStringNotContainsString( 'data-counter', $this->render( 'textarea', [ 'soft_limit' => 60, 'counter' => false ], 'abc' ) );
	}

	/**
	 * The hard limit is the server's too, and the soft one never refuses.
	 */
	public function test_the_hard_limit_is_refused_on_save_and_the_soft_one_is_not(): void {
		$set = new FieldSet(
			[
				'title' => [ 'type' => 'text', 'label' => 'Title', 'maxlength' => 5, 'soft_limit' => 3 ],
				'blurb' => [ 'type' => 'textarea', 'label' => 'Blurb', 'maxlength' => 4 ],
			],
			new ArrayContext(),
			'c'
		);

		$this->assertSame(
			[
				'title' => 'Title can be at most 5 characters long.',
				'blurb' => 'Blurb can be at most 4 characters long.',
			],
			$set->validate( [ 'title' => 'héllo!', 'blurb' => "on\ntwo" ] )
		);
		$this->assertSame( [], $set->validate( [ 'title' => 'héllo', 'blurb' => 'four' ] ), 'Characters, not bytes; past soft is fine.' );
	}

	public function test_a_hidden_input_counts_nothing(): void {
		$this->assertStringNotContainsString(
			'data-counter',
			$this->render( 'hidden', [ 'maxlength' => 5 ], 'abc' )
		);
	}

	/**
	 * Render a field of a type.
	 *
	 * @param string               $type   Type id.
	 * @param array<string, mixed> $config Field configuration.
	 * @param string               $value  Its value.
	 *
	 * @return string
	 */
	private function render( string $type, array $config, string $value ): string {
		$field = new Field(
			'demo',
			( new Registry() )->get( $type ),
			array_merge( [ 'label' => 'Demo', 'input_name' => 'demo' ], $config ),
			null
		);

		return ( new Renderer() )->render( $field->with_value( $value ) );
	}
}
