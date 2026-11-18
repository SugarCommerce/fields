<?php
/**
 * A secret nobody retyped is a secret nobody cleared.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Context\EncryptedContext;
use SugarCommerce\Fields\FieldSet;
use PHPUnit\Framework\TestCase;

/**
 * A password input is always drawn empty, so it is submitted empty by
 * everyone who came to change something else. A settings tab holding an
 * API key deleted the key on every such save, and nothing said so: the
 * page reported "Settings saved." and the integration stopped working.
 */
final class UnechoedTest extends TestCase {

	/**
	 * A set of one secret and one ordinary field, over an array.
	 *
	 * @param array<string, mixed> $stored What is already stored.
	 * @param array<string, mixed> $secret Extra configuration for the secret.
	 *
	 * @return array{0: FieldSet, 1: ArrayContext}
	 */
	private function set( array $stored, array $secret = [] ): array {
		$context = new ArrayContext( $stored );

		return [
			new FieldSet(
				[
					'api_key' => array_merge(
						[
							'type'  => 'password',
							'label' => 'API key',
						],
						$secret
					),
					'name'    => [
						'type'  => 'text',
						'label' => 'Name',
					],
				],
				$context,
				'fk_test'
			),
			$context,
		];
	}

	/**
	 * The save that used to lose it: the secret box empty, something else changed.
	 */
	public function test_an_empty_box_keeps_the_stored_secret(): void {
		[ $set, $context ] = $this->set(
			[
				'api_key' => 'sk_stored',
				'name'    => 'Before',
			]
		);

		$set->save(
			[
				'api_key' => '',
				'name'    => 'After',
			]
		);

		$this->assertSame( 'sk_stored', $context->values()['api_key'] );
		$this->assertSame( 'After', $context->values()['name'] );

		// Whitespace is an empty box, and so is the key being absent.
		$set->save( [ 'api_key' => '   ' ] );
		$this->assertSame( 'sk_stored', $context->values()['api_key'] );

		$set->save( [ 'name' => 'Again' ] );
		$this->assertSame( 'sk_stored', $context->values()['api_key'] );
	}

	/**
	 * Something typed replaces what was there.
	 */
	public function test_a_typed_secret_replaces_the_stored_one(): void {
		[ $set, $context ] = $this->set( [ 'api_key' => 'sk_stored' ] );

		$set->save( [ 'api_key' => 'sk_new' ] );

		$this->assertSame( 'sk_new', $context->values()['api_key'] );
	}

	/**
	 * An ordinary field is still cleared by being emptied.
	 *
	 * Its control shows what is stored, so an empty one is somebody's
	 * decision. Keeping it would be the opposite bug.
	 */
	public function test_an_emptied_text_field_is_still_cleared(): void {
		[ $set, $context ] = $this->set(
			[
				'api_key' => 'sk_stored',
				'name'    => 'Before',
			]
		);

		$set->save(
			[
				'api_key' => '',
				'name'    => '',
			]
		);

		$this->assertArrayNotHasKey( 'name', $context->values() );
		$this->assertArrayHasKey( 'api_key', $context->values() );
	}

	/**
	 * A required secret that was never entered is still refused.
	 *
	 * Keeping what is stored must not become a way past `required` when
	 * nothing is stored.
	 */
	public function test_a_required_secret_never_entered_is_refused(): void {
		[ $set ] = $this->set( [], [ 'required' => true ] );

		$set->save( [ 'api_key' => '' ] );

		$this->assertArrayHasKey( 'api_key', $set->errors() );

		// And one that is stored is not asked for again.
		[ $set, $context ] = $this->set( [ 'api_key' => 'sk_stored' ], [ 'required' => true ] );

		$set->save( [ 'api_key' => '' ] );

		$this->assertSame( [], $set->errors() );
		$this->assertSame( 'sk_stored', $context->values()['api_key'] );
	}

	/**
	 * Encrypted at rest, the stored ciphertext is kept as it is.
	 *
	 * The settings library saves through this decorator. Kept means the
	 * same bytes: not decrypted and encrypted again, and not the
	 * plaintext written where ciphertext was.
	 */
	public function test_an_encrypted_secret_is_kept_as_stored(): void {
		if ( ! EncryptedContext::available() ) {
			$this->markTestSkipped( 'No sodium.' );
		}

		$store = new ArrayContext( [] );
		$set   = new FieldSet(
			[
				'api_key' => [
					'type'      => 'password',
					'encrypted' => true,
				],
				'name'    => [ 'type' => 'text' ],
			],
			new EncryptedContext( $store ),
			'fk_test'
		);

		$set->save( [ 'api_key' => 'sk_stored' ] );

		$cipher = $store->values()['api_key'];

		$this->assertNotSame( 'sk_stored', $cipher );

		$set->save(
			[
				'api_key' => '',
				'name'    => 'After',
			]
		);

		$this->assertSame( $cipher, $store->values()['api_key'] );
		$this->assertSame( 'sk_stored', $set->field( 'api_key' )->value() );
	}

	/**
	 * Asked for by name, a stored secret is removed.
	 *
	 * The box cannot be emptied to say so, so the control says it beside
	 * itself: `__clear[api_key]`, in the same submission.
	 */
	public function test_a_flagged_secret_is_cleared(): void {
		[ $set, $context ] = $this->set(
			[
				'api_key' => 'sk_stored',
				'name'    => 'Before',
			]
		);

		$set->save(
			[
				'api_key'           => '',
				'name'              => 'After',
				FieldSet::CLEAR_KEY => [ 'api_key' => '1' ],
			]
		);

		$this->assertArrayNotHasKey( 'api_key', $context->values() );
		$this->assertSame( 'After', $context->values()['name'] );
		$this->assertSame( [], $set->errors() );
	}

	/**
	 * A flag that is absent, empty, for another field or the wrong shape keeps it.
	 */
	public function test_a_secret_that_is_not_flagged_is_kept(): void {
		foreach (
			[
				[],
				[ FieldSet::CLEAR_KEY => [] ],
				[ FieldSet::CLEAR_KEY => [ 'api_key' => '0' ] ],
				[ FieldSet::CLEAR_KEY => [ 'api_key' => '' ] ],
				[ FieldSet::CLEAR_KEY => [ 'other' => '1' ] ],
				[ FieldSet::CLEAR_KEY => 'api_key' ],
				[ FieldSet::CLEAR_KEY => '1' ],
			] as $flags
		) {
			[ $set, $context ] = $this->set( [ 'api_key' => 'sk_stored' ] );

			$set->save( array_merge( [ 'api_key' => '' ], $flags ) );

			$this->assertSame( 'sk_stored', $context->values()['api_key'] ?? null, (string) wp_json_encode( $flags ) );
		}
	}

	/**
	 * The flag empties nothing but a control that needs one.
	 *
	 * A text input shows what it holds and is emptied by being emptied. A
	 * crafted submission naming one must not be a second way to do it.
	 */
	public function test_the_flag_is_ignored_for_a_field_that_shows_its_value(): void {
		[ $set, $context ] = $this->set(
			[
				'api_key' => 'sk_stored',
				'name'    => 'Before',
			]
		);

		$set->save(
			[
				'name'              => 'Before',
				FieldSet::CLEAR_KEY => [ 'name' => '1' ],
			]
		);

		$this->assertSame( 'Before', $context->values()['name'] );
		$this->assertSame( 'sk_stored', $context->values()['api_key'] );
	}

	/**
	 * Something typed is the newer instruction, and wins over the flag.
	 */
	public function test_a_typed_secret_wins_over_the_flag(): void {
		[ $set, $context ] = $this->set( [ 'api_key' => 'sk_stored' ] );

		$set->save(
			[
				'api_key'           => 'sk_new',
				FieldSet::CLEAR_KEY => [ 'api_key' => '1' ],
			]
		);

		$this->assertSame( 'sk_new', $context->values()['api_key'] );
	}

	/**
	 * A required secret cannot be removed, and says so.
	 *
	 * Flagged, it is the empty value it would have been -- and an empty
	 * required field is refused and keeps what it held.
	 */
	public function test_a_required_secret_is_not_cleared_by_the_flag(): void {
		[ $set, $context ] = $this->set( [ 'api_key' => 'sk_stored' ], [ 'required' => true ] );

		$set->save(
			[
				'api_key'           => '',
				FieldSet::CLEAR_KEY => [ 'api_key' => '1' ],
			]
		);

		$this->assertArrayHasKey( 'api_key', $set->errors() );
		$this->assertSame( 'sk_stored', $context->values()['api_key'] );
	}

	/**
	 * An encrypted secret is removed from the store underneath.
	 */
	public function test_a_flagged_encrypted_secret_is_cleared(): void {
		if ( ! EncryptedContext::available() ) {
			$this->markTestSkipped( 'No sodium.' );
		}

		$store = new ArrayContext( [] );
		$set   = new FieldSet(
			[
				'api_key' => [
					'type'      => 'password',
					'encrypted' => true,
				],
			],
			new EncryptedContext( $store ),
			'fk_test'
		);

		$set->save( [ 'api_key' => 'sk_stored' ] );
		$this->assertArrayHasKey( 'api_key', $store->values() );

		$set->save(
			[
				'api_key'           => '',
				FieldSet::CLEAR_KEY => [ 'api_key' => '1' ],
			]
		);

		$this->assertArrayNotHasKey( 'api_key', $store->values() );
	}

	/**
	 * The control offers Remove only where there is something to remove.
	 */
	public function test_remove_is_offered_beside_a_stored_secret(): void {
		[ $set ] = $this->set( [ 'api_key' => 'sk_stored' ] );

		$html = $set->render_field( $set->field( 'api_key' ) );

		// The flag, named for this set, and not submitted until asked.
		$this->assertStringContainsString(
			'<input type="hidden" class="field-kit__password-clear" name="fk_test[__clear][api_key]" value="1" disabled />',
			$html
		);
		$this->assertStringContainsString( 'class="button-link field-kit__password-remove hide-if-no-js" aria-label="Remove saved API key"', $html );
		$this->assertStringContainsString( 'data-undo="Undo"', $html );
		$this->assertStringContainsString( 'data-pending="Will be removed when you save"', $html );
		$this->assertStringContainsString( '>Remove</button>', $html );

		// And the secret is still not in the page.
		$this->assertStringNotContainsString( 'sk_stored', $html );

		// Nothing stored, nothing to remove.
		[ $set ] = $this->set( [] );

		$this->assertStringNotContainsString( 'field-kit__password-remove', $set->render_field( $set->field( 'api_key' ) ) );
		$this->assertStringNotContainsString( '__clear', $set->render_field( $set->field( 'api_key' ) ) );
	}

	/**
	 * Not where pressing it could not mean anything.
	 */
	public function test_remove_is_not_offered_where_it_would_be_refused(): void {
		foreach (
			[
				[ 'required' => true ],
				[ 'disabled' => true ],
				[ 'readonly' => true ],
				[ 'removable' => false ],
			] as $config
		) {
			[ $set ] = $this->set( [ 'api_key' => 'sk_stored' ], $config );

			$this->assertStringNotContainsString(
				'field-kit__password-remove',
				$set->render_field( $set->field( 'api_key' ) ),
				(string) wp_json_encode( $config )
			);
		}
	}

	/**
	 * A set with no input prefix names the flag at the top of the submission.
	 */
	public function test_the_flag_is_named_for_a_set_with_no_prefix(): void {
		$set = new FieldSet(
			[ 'api_key' => [ 'type' => 'password' ] ],
			new ArrayContext( [ 'api_key' => 'sk_stored' ] ),
			''
		);

		$this->assertStringContainsString( 'name="__clear[api_key]"', $set->render_field( $set->field( 'api_key' ) ) );
	}

	/**
	 * validate() agrees with save(): it does not report the kept field.
	 */
	public function test_validate_leaves_the_kept_secret_out(): void {
		[ $set ] = $this->set( [ 'api_key' => 'sk_stored' ], [ 'required' => true ] );

		$this->assertSame( [], $set->validate( [ 'api_key' => '' ] ) );
	}
}
