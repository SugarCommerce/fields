<?php
/**
 * Merge tag tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Support\MergeTags;
use PHPUnit\Framework\TestCase;

/**
 * The kit does not own merge tags. It has no idea what `{customer_name}`
 * resolves to or when. What it owns is presenting a registered list and
 * putting a chosen one into the editor — so what is worth pinning down is
 * that every shape a consumer might register in resolves the same way, and
 * that nothing a consumer puts in a name reaches the page unescaped.
 */
final class MergeTagsTest extends TestCase {

	/**
	 * The shape to write: a name to search on, the tag, and what it means.
	 */
	public function test_the_full_shape_resolves(): void {
		$this->assertSame(
			[
				[
					'name'        => 'Customer name',
					'tag'         => '{customer_name}',
					'description' => 'The buyer’s name',
					'group'       => '',
				],
			],
			MergeTags::resolve(
				[
					[
						'name'        => 'Customer name',
						'tag'         => '{customer_name}',
						'description' => 'The buyer’s name',
					],
				]
			)
		);
	}

	/**
	 * A map of tag to description — the predecessor libraries' shape.
	 */
	public function test_a_map_resolves(): void {
		$this->assertSame(
			[
				[
					'name'        => '{customer_name}',
					'tag'         => '{customer_name}',
					'description' => 'The buyer’s name',
					'group'       => '',
				],
			],
			MergeTags::resolve( [ '{customer_name}' => 'The buyer’s name' ] )
		);
	}

	/**
	 * A bare list resolves, with the tag standing in as its own name.
	 */
	public function test_a_bare_list_resolves(): void {
		$this->assertSame(
			[
				[
					'name'        => '{site_name}',
					'tag'         => '{site_name}',
					'description' => '',
					'group'       => '',
				],
			],
			MergeTags::resolve( [ '{site_name}' ] )
		);
	}

	/**
	 * An entry with a tag and no name is still searchable by its tag.
	 */
	public function test_an_entry_without_a_name_falls_back_to_its_tag(): void {
		$resolved = MergeTags::resolve( [ [ 'tag' => '{order_total}' ] ] );

		$this->assertSame( '{order_total}', $resolved[0]['name'] );
	}

	/**
	 * Tags that share a group are listed together, in the order the groups
	 * first appear, and any with no group come after them.
	 */
	public function test_tags_are_kept_together_by_group(): void {
		$resolved = MergeTags::resolve(
			[
				[ 'tag' => '{order_number}', 'group' => 'Order' ],
				[ 'tag' => '{loose}' ],
				[ 'tag' => '{customer_name}', 'group' => 'Customer' ],
				[ 'tag' => '{order_total}', 'group' => 'Order' ],
			]
		);

		$this->assertSame(
			[ '{order_number}', '{order_total}', '{customer_name}', '{loose}' ],
			array_column( $resolved, 'tag' )
		);
		$this->assertSame( [ 'Order', 'Order', 'Customer', '' ], array_column( $resolved, 'group' ) );
	}

	/**
	 * The dialog captions each group once, captions what has no group as
	 * the rest, and lets a search find a tag by its group's name.
	 */
	public function test_the_dialog_has_a_heading_for_each_group(): void {
		$modal = MergeTags::modal(
			'm',
			MergeTags::resolve(
				[
					[ 'tag' => '{order_number}', 'group' => 'Order' ],
					[ 'tag' => '{order_total}', 'group' => 'Order' ],
					[ 'tag' => '{card}', 'group' => 'Payment & risk' ],
					[ 'tag' => '{loose}' ],
				]
			)
		);

		$this->assertSame( 3, substr_count( $modal, 'class="field-kit__tag-group"' ) );
		$this->assertSame( 1, substr_count( $modal, '>Order</li>' ) );
		$this->assertStringContainsString( '>Payment &amp; risk</li>', $modal );
		$this->assertStringContainsString( '>More</li>', $modal );
		$this->assertMatchesRegularExpression( '/data-search="[^"]*\{card\}[^"]*payment &amp; risk"/', $modal );

		// A heading is a caption: nothing in it can be pressed.
		$this->assertStringNotContainsString( 'field-kit__tag-group"><button', $modal );
	}

	/**
	 * With no groups given the list is flat, as it always was.
	 */
	public function test_a_list_without_groups_has_no_headings(): void {
		$modal = MergeTags::modal( 'm', MergeTags::resolve( [ '{site_name}', '{year}' ] ) );

		$this->assertStringNotContainsString( 'field-kit__tag-group', $modal );
		$this->assertSame( 2, substr_count( $modal, 'class="field-kit__tag-item"' ) );
	}

	/**
	 * Nothing usable resolves to nothing.
	 */
	public function test_unusable_entries_are_dropped(): void {
		$this->assertSame( [], MergeTags::resolve( null ) );
		$this->assertSame( [], MergeTags::resolve( 'not an array' ) );
		$this->assertSame( [], MergeTags::resolve( [ [ 'description' => 'no tag' ] ] ) );
		$this->assertSame( [], MergeTags::resolve( [ '' ] ) );
	}

	/**
	 * No tags means no dialog at all.
	 */
	public function test_no_tags_renders_no_dialog(): void {
		$this->assertSame( '', MergeTags::modal( 'x', [] ) );
	}

	/**
	 * The dialog is core's, and is announced as one.
	 */
	public function test_the_dialog_is_a_labelled_modal(): void {
		$html = MergeTags::modal( 'body_tags', MergeTags::resolve( [ '{site_name}' ] ) );

		$this->assertStringContainsString( 'class="field-kit__tag-modal wp-core-ui"', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'aria-modal="true"', $html );
		$this->assertStringContainsString( 'aria-labelledby="body_tags-title"', $html );
		$this->assertStringContainsString( 'id="body_tags-title"', $html );

		// Closed until asked for, backdrop and all.
		$this->assertStringContainsString( 'hidden>', $html );
		$this->assertStringContainsString( 'field-kit__tag-backdrop', $html );

		// Not core's media modal. Its stylesheet is only on the page when
		// something has called wp_enqueue_media(), so a screen with an email
		// and no media field had a dialog with no styling at all.
		$this->assertStringNotContainsString( 'media-modal', $html );
	}

	/**
	 * The search box is labelled, not just placeheld.
	 *
	 * A placeholder is not a label: it disappears the moment anyone types.
	 */
	public function test_the_search_box_is_labelled(): void {
		$html = MergeTags::modal( 'body_tags', MergeTags::resolve( [ '{site_name}' ] ) );

		$this->assertStringContainsString( '<label class="screen-reader-text" for="body_tags-search"', $html );
		$this->assertStringContainsString( 'id="body_tags-search"', $html );
	}

	/**
	 * Each row carries what to insert and what to search against.
	 */
	public function test_each_row_carries_its_tag_and_search_text(): void {
		$html = MergeTags::modal(
			'body_tags',
			MergeTags::resolve(
				[
					[
						'name'        => 'Customer Name',
						'tag'         => '{customer_name}',
						'description' => 'The Buyer',
					],
				]
			)
		);

		$this->assertStringContainsString( 'data-tag="{customer_name}"', $html );

		// Lowercased once here rather than on every keystroke, and built from
		// all three fields so a search matches what someone can see.
		$this->assertStringContainsString( 'data-search="customer name {customer_name} the buyer"', $html );
	}

	/**
	 * A consumer's text is escaped.
	 */
	public function test_consumer_text_is_escaped(): void {
		$html = MergeTags::modal(
			'body_tags',
			MergeTags::resolve(
				[
					[
						'name'        => '<script>alert(1)</script>',
						'tag'         => '{x}',
						'description' => '"><img src=x>',
					],
				]
			)
		);

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	/**
	 * The button says which editor it inserts into and which dialog it opens.
	 */
	public function test_the_button_points_at_its_editor_and_dialog(): void {
		$html = MergeTags::button( 'body_editor', 'body_tags' );

		$this->assertStringContainsString( 'data-editor="body_editor"', $html );
		$this->assertStringContainsString( 'data-modal="body_tags"', $html );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $html );
		$this->assertStringContainsString( 'class="button field-kit__tag-button"', $html );
	}
}
