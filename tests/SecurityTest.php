<?php
/**
 * Security tests: what a crafted request could do, and no longer can.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Form;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Security\RateLimit\Limiter;
use SugarCommerce\Security\RateLimit\Stores\Memory;

/**
 * Class SecurityTest
 */
final class SecurityTest extends TestCase {

	/**
	 * Reset what the stubs read.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['fk_user_can'], $GLOBALS['fk_userdata'], $GLOBALS['fk_attachments'] );
		$_POST = [];
	}

	/**
	 * A relational field drawn with the ids it holds.
	 *
	 * @param string               $type   The type.
	 * @param array<string, mixed> $config Extra configuration.
	 * @param mixed                $value  The stored ids.
	 *
	 * @return string
	 */
	private function draw( string $type, array $config, mixed $value ): string {
		$field = new Field( 'pick', ( new Registry() )->get( $type ), [ 'label' => 'Pick' ] + $config, $value );

		return ( new Renderer() )->render( $field );
	}

	/**
	 * A user field does not print an address to someone who may not list
	 * users.
	 *
	 * The ids are whatever was submitted, so an author could store every
	 * user's id in a field and read each address back from the labels.
	 */
	public function test_a_user_label_shows_the_address_only_to_who_may_list_users(): void {
		$GLOBALS['fk_userdata'][3] = (object) [
			'display_name' => 'Grace',
			'user_email'   => 'grace@example.com',
		];

		$GLOBALS['fk_user_can']['list_users'] = false;

		$html = $this->draw( 'user', [], 3 );

		$this->assertStringContainsString( 'Grace', $html );
		$this->assertStringNotContainsString( 'grace@example.com', $html );

		$GLOBALS['fk_user_can']['list_users'] = true;

		$this->assertStringContainsString( 'grace@example.com', $this->draw( 'user', [], 3 ) );
	}

	/**
	 * A post field does not print the title of a post the user may not read,
	 * and still keeps it chosen.
	 */
	public function test_a_post_label_hides_a_title_the_user_may_not_read(): void {
		$GLOBALS['fk_attachments'][12] = [ 'name' => 'Private plans' ];
		$GLOBALS['fk_user_can']        = [ 'read_post' => false ];

		$html = $this->draw( 'post', [], 12 );

		$this->assertStringNotContainsString( 'Private plans', $html );
		$this->assertStringContainsString( 'value="12" selected', $html );

		$GLOBALS['fk_user_can'] = [];

		$this->assertStringContainsString( 'Private plans', $this->draw( 'post', [], 12 ) );
	}

	/**
	 * A read-only field keeps what it holds, whatever a request sends.
	 */
	public function test_a_read_only_field_is_not_written(): void {
		$context = new ArrayContext(
			[
				'average' => 4,
				'total'   => 10,
			]
		);

		$set = new FieldSet(
			[
				'name'    => [ 'type' => 'text' ],
				'average' => [
					'type'    => 'rating',
					'display' => true,
				],
				'total'   => [
					'type'     => 'number',
					'readonly' => true,
				],
			],
			$context
		);

		$stored = $set->save(
			[
				'name'    => 'Ada',
				'average' => '1',
				'total'   => '0',
			]
		);

		$this->assertSame( [ 'name' => 'Ada' ], $stored );
		$this->assertSame( 4, $set->field( 'average' )->value() );
		$this->assertSame( 10, $set->field( 'total' )->value() );
	}

	/**
	 * A public form with a limiter refuses a visitor past the limit, before
	 * its fields are checked or on_submit runs.
	 */
	public function test_a_public_form_refuses_past_its_limit(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$sent = 0;
		$form = new Form(
			'contact',
			[ 'name' => [ 'type' => 'text' ] ],
			[
				'nonce'     => false,
				'limiter'   => new Limiter( new Memory( 1000 ), 'test-secret', 1000 ),
				'limit'     => [ 2, 600 ],
				'on_submit' => function () use ( &$sent ) {
					++$sent;
				},
			]
		);

		$post = [
			Form::FIELD        => 'contact',
			'contact'          => [ 'name' => 'Ada' ],
			'_contact_website' => '',
		];

		$this->assertTrue( $form->handle( $post )['ok'] );
		$this->assertTrue( $form->handle( $post )['ok'] );

		$third = $form->handle( $post );

		$this->assertFalse( $third['ok'] );
		$this->assertStringContainsString( 'too many times', $third['message'] );
		$this->assertSame( 2, $sent );
	}
}
