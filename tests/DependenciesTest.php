<?php
/**
 * What a set of fields needs on the page, nested fields included.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Support\Dependencies;
use PHPUnit\Framework\TestCase;

/**
 * A field nested in a group or a repeater needs what it needs at the top
 * level. Only the top level used to be asked, so an image in a repeater's
 * rows had no media frame and a code field in a group no editor -- and a
 * visual editor drawn after the page loaded had nothing at all.
 */
final class DependenciesTest extends TestCase {

	/**
	 * A code field inside a group still gets an editor for its language.
	 */
	public function test_a_nested_code_field_gets_its_editor(): void {
		$needs = Dependencies::of(
			[
				'look' => [
					'type'   => 'group',
					'fields' => [
						'css' => [ 'type' => 'code', 'language' => 'text/css' ],
					],
				],
				'html' => [ 'type' => 'code' ],
			]
		);

		$this->assertSame( [ 'text/css', 'text/html' ], $needs['code_editors'] );
	}

	/**
	 * An image in a repeater's rows needs the media frame, and a colour
	 * there that asked for core's picker needs core's picker.
	 */
	public function test_fields_in_a_repeater_bring_their_scripts(): void {
		$needs = Dependencies::of(
			[
				'rows' => [
					'type'   => 'repeater',
					'fields' => [
						'picture' => [ 'type' => 'image' ],
						'tint'    => [ 'type' => 'color', 'picker' => 'wordpress' ],
					],
				],
			]
		);

		$this->assertContains( 'media-views', $needs['scripts'] );
		$this->assertContains( 'wp-color-picker', $needs['scripts'] );
		$this->assertContains( 'wp-color-picker', $needs['styles'] );
	}

	/**
	 * A visual editor asks for the editor, and for the media frame its Add
	 * Media button opens -- unless it has no such button.
	 */
	public function test_a_visual_editor_asks_for_the_editor(): void {
		$with = Dependencies::of( [ 'body' => [ 'type' => 'wysiwyg' ] ] );

		$this->assertTrue( $with['editor'] );
		$this->assertContains( 'media-views', $with['scripts'] );

		$without = Dependencies::of(
			[
				'note' => [
					'type'          => 'wysiwyg',
					'media_buttons' => false,
				],
			]
		);

		$this->assertTrue( $without['editor'] );
		$this->assertSame( [], $without['scripts'] );
	}

	/**
	 * An email editor's body is a visual editor, and a nested one counts.
	 */
	public function test_an_email_editor_and_a_nested_editor_ask_for_it_too(): void {
		$this->assertTrue( Dependencies::of( [ 'receipt' => [ 'type' => 'email_editor' ] ] )['editor'] );

		$nested = Dependencies::of(
			[
				'address' => [
					'type'   => 'group',
					'fields' => [
						'note' => [ 'type' => 'wysiwyg' ],
					],
				],
			]
		);

		$this->assertTrue( $nested['editor'] );
	}

	/**
	 * Plain fields need nothing, and a type nobody registered is skipped
	 * rather than thrown on.
	 */
	public function test_plain_fields_need_nothing(): void {
		$needs = Dependencies::of(
			[
				'name'  => [ 'type' => 'text' ],
				'stats' => [ 'type' => 'not_a_field_type' ],
				'odd'   => 'not a configuration',
			]
		);

		$this->assertSame(
			[
				'scripts'      => [],
				'styles'       => [],
				'code_editors' => [],
				'editor'       => false,
			],
			$needs
		);
	}

	/**
	 * A field set answers the same, nested fields included.
	 */
	public function test_a_field_set_reads_nested_fields(): void {
		$set = new FieldSet(
			[
				'rows' => [
					'type'   => 'repeater',
					'fields' => [
						'snippet' => [ 'type' => 'code', 'language' => 'application/javascript' ],
						'body'    => [ 'type' => 'wysiwyg' ],
					],
				],
			],
			new ArrayContext(),
			''
		);

		$needs = $set->dependencies();

		$this->assertSame( [ 'application/javascript' ], $needs['code_editors'] );
		$this->assertTrue( $needs['editor'] );
	}

	/**
	 * A file list's own columns are asked too: its file column opens the
	 * media frame, though nobody configured it.
	 */
	public function test_a_types_own_columns_are_asked(): void {
		$this->assertContains( 'media-views', Dependencies::of( [ 'downloads' => [ 'type' => 'files' ] ] )['scripts'] );
	}

	/**
	 * One visual editor anywhere is enough: a plain field after it does
	 * not take it back.
	 */
	public function test_one_editor_anywhere_is_enough(): void {
		$needs = Dependencies::of(
			[
				'body' => [ 'type' => 'wysiwyg' ],
				'name' => [ 'type' => 'text' ],
			]
		);

		$this->assertTrue( $needs['editor'] );
	}
}
