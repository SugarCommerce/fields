<?php
/**
 * Colour field tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Text\Display\Color;
use SugarCommerce\Fields\Types\ColorType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The colour field is the browser's own picker with a hex box beside it.
 *
 * It used to be core's Iris picker, which a flyout panel's stylesheet
 * squashed into a rounded box with its "Select Color" button cut in half.
 * The native control has no chrome of its own to fight, and opens the
 * operating system's picker -- the one people already know.
 */
final class ColorFieldTest extends TestCase {

	/**
	 * Build a colour field.
	 *
	 * @param array<string, mixed> $config Configuration.
	 * @param mixed                $value  Stored value.
	 *
	 * @return Field
	 */
	private function field( array $config = [], mixed $value = null ): Field {
		return new Field(
			'tint',
			( new Registry() )->get( 'color' ),
			array_merge( [ 'label' => 'Colour', 'input_name' => 'tint' ], $config ),
			$value
		);
	}

	/**
	 * Render a colour field.
	 *
	 * @param array<string, mixed> $config Configuration.
	 * @param mixed                $value  Stored value.
	 *
	 * @return string
	 */
	private function render( array $config = [], mixed $value = null ): string {
		return ( new Renderer() )->render( $this->field( $config, $value ) );
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
		return ( new Registry() )->get( 'color' )->sanitize( $value, $this->field( $config ) );
	}

	/**
	 * The swatch is a native colour input, and the hex box is the field.
	 *
	 * The box has the name and the id the label points at, so the form
	 * still posts the colour with the script off. The swatch has no name
	 * and is never submitted.
	 */
	public function test_native_by_default(): void {
		$html = $this->render( [], '#E8A5C4' );

		$this->assertMatchesRegularExpression( '/<input class="field-kit__color-swatch" type="color" value="#e8a5c4"/', $html );
		$this->assertMatchesRegularExpression( '/<input class="[^"]*field-kit__color-hex[^"]*" id="tint" name="tint"/', $html );
		$this->assertMatchesRegularExpression( '/<label for="tint">/', $html );

		// One named input: the swatch is not a second copy of the value.
		$this->assertSame( 1, preg_match_all( '/name="tint"/', $html ) );
		$this->assertDoesNotMatchRegularExpression( '/type="color"[^>]*name=/', $html );

		// Shown as it will be stored.
		$this->assertStringContainsString( 'value="#e8a5c4"', $html );
		$this->assertStringNotContainsString( 'wp-color-picker', $html );
		$this->assertStringNotContainsString( 'field-kit__color--wordpress', $html );
	}

	/**
	 * The swatch is named for the field, so two colour fields are two
	 * different controls to a screen reader.
	 */
	public function test_the_swatch_is_named_for_its_field(): void {
		$this->assertStringContainsString( 'aria-label="Colour, from the colour picker"', $this->render() );
	}

	/**
	 * Empty is said, not shown as black.
	 *
	 * A colour input is always some colour, and black when not told, so an
	 * empty field would otherwise look like a black one. The wrapper says it
	 * is empty and the stylesheet draws "none"; the box says it in words;
	 * and there is nothing to clear.
	 */
	public function test_empty_is_not_black(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'data-empty="true"', $html );
		$this->assertStringContainsString( 'placeholder="No colour"', $html );
		$this->assertMatchesRegularExpression( '/<button type="button" class="button-link field-kit__color-clear" hidden>/', $html );

		$filled = $this->render( [], '#123456' );

		$this->assertStringContainsString( 'data-empty="false"', $filled );
		$this->assertMatchesRegularExpression( '/<button type="button" class="button-link field-kit__color-clear">Clear<span class="screen-reader-text"> Colour<\/span>/', $filled );
	}

	/**
	 * The box tells the browser what a colour looks like.
	 */
	public function test_the_box_says_what_it_takes(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'maxlength="7"', $html );
		$this->assertStringContainsString( 'spellcheck="false"', $html );
		$this->assertStringContainsString( 'pattern="#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})"', $html );
	}

	/**
	 * A disabled field disables every part of it.
	 */
	public function test_disabled_reaches_every_part(): void {
		$html = $this->render( [ 'disabled' => true, 'presets' => [ '#ff0000' ] ], '#00ff00' );

		$this->assertMatchesRegularExpression( '/type="color"[^>]*disabled/', $html );
		$this->assertMatchesRegularExpression( '/field-kit__color-clear"[^>]*disabled/', $html );
		$this->assertMatchesRegularExpression( '/field-kit__color-preset"[^>]*disabled/', $html );
	}

	/**
	 * Presets are buttons with a name and a pressed state.
	 *
	 * @param mixed                 $presets What the field was given.
	 * @param array<string, string> $expect  Name by colour.
	 */
	#[DataProvider( 'presetProvider' )]
	public function test_presets_are_read_in_every_form( mixed $presets, array $expect ): void {
		$this->assertSame( $expect, ColorType::presets( $presets ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: array<string, string>}>
	 */
	public static function presetProvider(): array {
		return [
			'a list'                   => [ [ '#E8A5C4', '#abc' ], [ '#e8a5c4' => '', '#aabbcc' => '' ] ],
			'named'                    => [ [ '#e8a5c4' => 'Rose', '#3858e9' => 'Blue' ], [ '#e8a5c4' => 'Rose', '#3858e9' => 'Blue' ] ],
			"Iris's comma string"      => [ '#e8a5c4,#3858E9', [ '#e8a5c4' => '', '#3858e9' => '' ] ],
			'rubbish and repeats out'  => [ [ 'red', '#e8a5c4', 'javascript:alert(1)', '#E8A5C4', '' ], [ '#e8a5c4' => '' ] ],
			'nothing'                  => [ null, [] ],
		];
	}

	/**
	 * The swatch matching the colour is pressed; the others are not.
	 */
	public function test_the_matching_preset_is_pressed(): void {
		$html = $this->render( [ 'presets' => [ '#e8a5c4' => 'Rose', '#3858e9' => 'Blue' ] ], '#E8A5C4' );

		$this->assertStringContainsString( 'role="group" aria-label="Suggested colours"', $html );
		$this->assertMatchesRegularExpression( '/data-color="#e8a5c4" aria-pressed="true" aria-label="Rose #e8a5c4"/', $html );
		$this->assertMatchesRegularExpression( '/data-color="#3858e9" aria-pressed="false" aria-label="Blue #3858e9"/', $html );
		$this->assertStringContainsString( 'style="--field-kit-swatch:#3858e9"', $html );
	}

	/**
	 * A field written for Iris keeps its swatches.
	 */
	public function test_a_palette_becomes_presets(): void {
		$html = $this->render( [ 'palette' => '#e8a5c4,#3858e9' ] );

		$this->assertStringContainsString( 'data-color="#3858e9"', $html );
	}

	/**
	 * No presets, no empty group for a screen reader to land in.
	 */
	public function test_no_presets_no_group(): void {
		$this->assertStringNotContainsString( 'field-kit__color-presets', $this->render() );
	}

	/**
	 * Core's picker is still there for anybody who asks.
	 */
	public function test_wordpress_picker_on_request(): void {
		$html = $this->render( [ 'picker' => 'wordpress', 'presets' => [ '#e8a5c4', '#3858e9' ] ], '#e8a5c4' );

		$this->assertMatchesRegularExpression( '/<input[^>]*class="[^"]*field-kit__color field-kit__color--wordpress/', $html );
		$this->assertStringContainsString( 'data-palette="#e8a5c4,#3858e9"', $html );
		$this->assertStringNotContainsString( 'type="color"', $html );

		// Alpha is only core's, so it implies core's picker; and a field
		// that says native and alpha gets native, because it said so.
		$this->assertStringContainsString( 'data-alpha-enabled="true"', $this->render( [ 'alpha' => true ] ) );
		$this->assertStringContainsString( 'type="color"', $this->render( [ 'alpha' => true, 'picker' => 'native' ] ) );
	}

	/**
	 * Every form of a colour is stored as one.
	 *
	 * @param mixed  $submitted What came in.
	 * @param string $stored    What is kept.
	 */
	#[DataProvider( 'sanitizeProvider' )]
	public function test_stored_as_lowercase_hex_or_nothing( mixed $submitted, string $stored ): void {
		$this->assertSame( $stored, $this->sanitize( $submitted ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function sanitizeProvider(): array {
		return [
			'lowercase'          => [ '#e8a5c4', '#e8a5c4' ],
			'uppercase'          => [ '#E8A5C4', '#e8a5c4' ],
			'pasted without #'   => [ 'e8a5c4', '#e8a5c4' ],
			'short'              => [ '#abc', '#aabbcc' ],
			'padded'             => [ '  #E8A5C4 ', '#e8a5c4' ],
			'empty'              => [ '', '' ],
			'a name'             => [ 'red', '' ],
			'rgb()'              => [ 'rgb(1,2,3)', '' ],
			'too long'           => [ '#e8a5c4a', '' ],
			'an injection'       => [ '#fff;background:url(x)', '' ],
			'an array'           => [ [ '#ffffff' ], '' ],
			// Read as the digits a form would have posted.
			'six digits'         => [ 123456, '#123456' ],
		];
	}

	/**
	 * rgba() is kept only where it can have been chosen: core's picker with
	 * alpha on.
	 */
	public function test_rgba_only_with_alpha(): void {
		$this->assertSame( 'rgba(10, 20, 30, 0.5)', $this->sanitize( 'rgba(10, 20, 30, 0.5)', [ 'alpha' => true ] ) );
		$this->assertSame( '', $this->sanitize( 'rgba(10, 20, 30, 0.5)' ) );
		$this->assertSame( '', $this->sanitize( 'rgba(10, 20, 30, 0.5)', [ 'alpha' => true, 'picker' => 'native' ] ) );
		$this->assertSame( '#aabbcc', $this->sanitize( '#ABC', [ 'alpha' => true ] ) );
	}

	/**
	 * The kit's rule, on its own.
	 */
	public function test_the_hex_rule(): void {
		$this->assertSame( '#e8a5c4', Color::hex( '#E8A5C4' ) );
		$this->assertSame( '#000000', Color::hex( 'nonsense', '#000000' ) );
		$this->assertSame( '', Color::hex( null ) );
		$this->assertSame( '', Color::hex( [ '#fff' ] ) );
	}

	/**
	 * Only a field that asked for core's picker loads it.
	 */
	public function test_dependencies_follow_the_picker(): void {
		$type = ( new Registry() )->get( 'color' );

		$this->assertSame( [], $type->dependencies_for( [] )['scripts'] );
		$this->assertSame( [ 'jquery', 'wp-color-picker' ], $type->dependencies_for( [ 'picker' => 'wordpress' ] )['scripts'] );
		$this->assertSame( [ 'wp-color-picker' ], $type->dependencies_for( [ 'alpha' => true ] )['styles'] );
	}

	/**
	 * The stylesheet keeps the swatch square beside the box and draws the
	 * empty state, and the script keeps the two in step.
	 */
	public function test_the_assets_drive_it(): void {
		$css = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/field-kit.css' );
		$js  = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/field-kit.js' );

		$this->assertMatchesRegularExpression( '/\.field-kit__color-field\[data-empty="true"\] \.field-kit__color-swatch\s*\{/', $css );
		$this->assertMatchesRegularExpression( '/\.field-kit__color-preset\[aria-pressed="true"\]/', $css );
		$this->assertStringContainsString( "querySelectorAll( '.field-kit__color-field' )", $js );
		$this->assertStringContainsString( "swatch.addEventListener( 'input'", $js );
	}
	/**
	 * The hex starts after the chip wherever the field is drawn.
	 *
	 * The chip sits over the start of the box and the box's 42px of
	 * padding is what keeps the hex clear of it. A rule for every text
	 * input of a context -- a settings card's `padding: 6px 12px` at two
	 * classes, an attribute and an element -- that outranks the hex rule
	 * takes that room back, and the chip covers "#e8" of "#e8a5c4". The
	 * cascade is invisible in markup, so it is checked here: every rule
	 * that pads a text input must lose to the hex rule, by specificity or,
	 * at the same specificity, by coming first.
	 */
	public function test_no_text_input_rule_takes_the_chips_room(): void {
		$css   = (string) file_get_contents( dirname( __DIR__ ) . '/assets/css/field-kit.css' );
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$rules = [];

		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			foreach ( explode( ',', $match[1][0] ) as $selector ) {
				$rules[] = [ trim( $selector ), $match[2][0], $match[0][1] ];
			}
		}

		$hex = null;

		foreach ( $rules as $rule ) {
			if ( str_ends_with( $rule[0], 'field-kit__color-hex' ) && str_contains( $rule[1], 'padding-inline-start: 42px' ) ) {
				$hex = $rule;
			}
		}

		$this->assertNotNull( $hex, 'the hex rule pads the box for the chip' );

		$checked = 0;

		foreach ( $rules as $rule ) {
			if ( ! preg_match( '/input\[type="text"\](:[a-z-]+(\([^)]*\))?)*$/', $rule[0] ) || ! preg_match( '/(^|;)\s*padding(-left|-inline|-inline-start)?\s*:/', $rule[1] ) ) {
				continue;
			}

			++$checked;
			$theirs = $this->specificity( $rule[0] );
			$ours   = $this->specificity( $hex[0] );

			$this->assertTrue(
				$ours > $theirs || ( $ours === $theirs && $hex[2] > $rule[2] ),
				sprintf( '"%s" (%s) outranks the hex rule (%s)', $rule[0], implode( ',', $theirs ), implode( ',', $ours ) )
			);
		}

		$this->assertGreaterThan( 0, $checked, 'the settings card pads its text inputs' );
	}

	/**
	 * A selector's specificity, as ids, classes and elements.
	 *
	 * Enough of the rules for this stylesheet: `:not()` counts as its
	 * argument, a pseudo-element as an element.
	 *
	 * @param string $selector The selector.
	 *
	 * @return int[]
	 */
	private function specificity( string $selector ): array {
		$score    = [ 0, 0, 0 ];
		$selector = (string) preg_replace_callback(
			'/:not\(([^)]*)\)/',
			function ( array $inner ) use ( &$score ): string {
				$add   = $this->specificity( $inner[1] );
				$score = [ $score[0] + $add[0], $score[1] + $add[1], $score[2] + $add[2] ];

				return '';
			},
			$selector
		);

		$score[0] += preg_match_all( '/#[\w-]+/', $selector );
		$score[1] += preg_match_all( '/\.[\w-]+|\[[^\]]*\]|(?<!:):[\w-]+/', $selector );
		$score[2] += preg_match_all( '/::[\w-]+|(?:^|[\s>+~])[a-z][\w-]*/i', $selector );

		return $score;
	}
}
