<?php
/**
 * What the script needs to start a visual editor nothing else will.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use PHPUnit\Framework\TestCase;

/**
 * An editor drawn after the page loaded -- in a panel fetched over REST --
 * comes without the settings wp_editor() prints in the footer. The script
 * starts it from core's defaults, and has to be told which textarea, and
 * what the field asked for that the defaults do not know.
 */
final class WysiwygStartTest extends TestCase {

	/**
	 * Render one editor.
	 *
	 * @param array<string, mixed> $config Field configuration.
	 *
	 * @return string
	 */
	private function render( array $config = [] ): string {
		$set = new FieldSet(
			[ 'body' => array_merge( [ 'type' => 'wysiwyg', 'label' => 'Body' ], $config ) ],
			new ArrayContext(),
			''
		);

		return $set->render_field( $set->field( 'body' ) );
	}

	/**
	 * The wrapper names its editor's id.
	 */
	public function test_the_wrapper_names_its_editor(): void {
		$markup = $this->render();

		$this->assertMatchesRegularExpression( '/class="field-kit__wysiwyg-wrap" data-field-id="body" data-editor="body"/', $markup );
		$this->assertStringNotContainsString( 'data-teeny', $markup );
		$this->assertStringNotContainsString( 'data-height', $markup );
	}

	/**
	 * The short toolbar and a height in pixels travel with it.
	 */
	public function test_teeny_and_height_travel_with_it(): void {
		$markup = $this->render(
			[
				'teeny'  => true,
				'height' => 260,
			]
		);

		$this->assertStringContainsString( 'data-teeny="1"', $markup );
		$this->assertStringContainsString( 'data-height="260"', $markup );
	}
}
