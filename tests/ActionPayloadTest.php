<?php
/**
 * What a button's request hands its handler.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Actions\Actions;
use SugarCommerce\Fields\Actions\CallbackAction;
use SugarCommerce\Fields\Actions\Payload;
use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Contracts\RichPayload;
use SugarCommerce\Fields\FieldSet;
use PHPUnit\Framework\TestCase;

/**
 * Everything a button sends is plain text, except an input its action says
 * is markup.
 *
 * The exception exists because of one button. Preview, under an email's
 * editor, was handed the body with its paragraphs, its bold and its line
 * breaks stripped: an edited email previewed as a single run-on line, and
 * looked right again only once it had been saved.
 */
final class ActionPayloadTest extends TestCase {

	/**
	 * An input the action calls rich keeps what a post may hold.
	 */
	public function test_a_rich_input_keeps_its_markup(): void {
		$clean = Payload::clean(
			[ 'body' => "<p>Hello <strong>Pat</strong>,</p>\n\n<p>Thanks.</p>" ],
			[ 'body' ]
		);

		$this->assertSame( "<p>Hello <strong>Pat</strong>,</p>\n\n<p>Thanks.</p>", $clean['body'] );
	}

	/**
	 * And loses what a post may not.
	 */
	public function test_a_script_is_taken_out_of_a_rich_input(): void {
		$clean = Payload::clean(
			[ 'body' => '<p>Hello</p><script>alert(1)</script><p onclick="x()">there</p>' ],
			[ 'body' ]
		);

		$this->assertStringNotContainsString( '<script', $clean['body'] );
		$this->assertStringNotContainsString( '</script', $clean['body'] );
		$this->assertStringContainsString( '<p>Hello</p>', $clean['body'] );
	}

	/**
	 * Every other input is a line of plain text, as it always was -- the
	 * same input, when nobody said it was rich, included.
	 */
	public function test_a_plain_input_is_still_stripped(): void {
		$clean = Payload::clean(
			[
				'subject' => "<b>Your</b> order\nis here",
				'body'    => '<p>Hello</p>',
			],
			[ 'body' ]
		);

		$this->assertSame( 'Your order is here', $clean['subject'] );
		$this->assertSame( 'Hello', Payload::clean( [ 'body' => '<p>Hello</p>' ] )['body'] );
	}

	/**
	 * Nothing nested reaches a handler, and a list is a list of plain text
	 * even under a key that is rich.
	 */
	public function test_only_scalars_and_flat_lists_get_through(): void {
		$clean = Payload::clean(
			[
				'body'   => [ '<p>one</p>', [ 'nested' ], 'two' ],
				'object' => new \stdClass(),
				''       => 'no key',
				'Key!'   => 'tidied',
			],
			[ 'body' ]
		);

		$this->assertSame( [ 'one', 'two' ], $clean['body'] );
		$this->assertArrayNotHasKey( 'object', $clean );
		$this->assertSame( 'tidied', $clean['key'] );
		$this->assertCount( 2, $clean );
	}

	/**
	 * An email's buttons are registered knowing its body is markup, and
	 * any other field's are not.
	 */
	public function test_an_emails_actions_say_the_body_is_rich(): void {
		new FieldSet(
			[
				'receipt' => [
					'type'    => 'email_editor',
					'actions' => [ 'preview' => static fn() => [ 'success' => true ] ],
				],
				'summary' => [
					'type'    => 'email_editor',
					'fields'  => [
						'subject' => [ 'type' => 'text' ],
						'intro'   => [ 'type' => 'wysiwyg' ],
					],
					'actions' => [ 'preview' => static fn() => [ 'success' => true ] ],
				],
				'licence' => [
					'type'            => 'action_button',
					'action_callback' => static fn() => [ 'success' => true ],
					'action_rich'     => [ 'notes' ],
				],
				'sync'    => [
					'type'            => 'action_button',
					'action_callback' => static fn() => [ 'success' => true ],
				],
			],
			new ArrayContext(),
			'rich'
		);

		$actions = Actions::shared();

		$this->assertInstanceOf( RichPayload::class, $actions->get( 'rich_receipt_preview' ) );
		$this->assertSame( [ 'body' ], $actions->get( 'rich_receipt_preview' )->rich() );
		$this->assertSame( [ 'intro', 'body' ], $actions->get( 'rich_summary_preview' )->rich() );
		$this->assertSame( [ 'notes' ], $actions->get( 'rich_licence_run' )->rich() );
		$this->assertSame( [], $actions->get( 'rich_sync_run' )->rich() );
	}

	/**
	 * An action built the way it was before any of this says nothing is.
	 */
	public function test_an_action_is_plain_unless_told(): void {
		$this->assertSame( [], ( new CallbackAction( 'demo', static fn() => 'ok' ) )->rich() );
	}
}
