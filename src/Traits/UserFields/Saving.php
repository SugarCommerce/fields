<?php
/**
 * User Fields: Saving
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\UserFields;

use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Screens\Errors;

/**
 * Saving a user's fields, only the ones the editor may see and change.
 *
 * Part of UserFields: only the class uses it.
 */
trait Saving {

	/**
	 * Save a user's fields.
	 *
	 * @param int $user_id The user id.
	 *
	 * @return void
	 */
	public function save( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core verifies the profile form's nonce before firing these hooks.
		$input = $_POST;

		// Only what this user may set. A field they cannot see is not one a
		// crafted submission gets to write either — the screen hides it, and
		// without this the hiding would be the only thing stopping it.
		$set = $this->restricted( $user_id );

		$set->save( $input, $user_id );

		// A refused value is left as it was and the rest are stored. Core
		// redirects before anything here could say so, so the messages wait
		// for the next load of this user's profile.
		Errors::remember( 'user', $user_id, $this->id, $set->errors() );
	}

	/**
	 * A field set limited to what the current user may write.
	 *
	 * @param int $user_id The user being edited.
	 *
	 * @return FieldSet
	 */
	private function restricted( int $user_id ): FieldSet {
		$allowed = array_filter(
			$this->fields,
			fn( $config ) => $this->permitted( (array) $config, $user_id )
		);

		return $allowed === $this->fields
			? $this->set
			: new FieldSet( $allowed, $this->context, '', new Registry() );
	}

	/**
	 * The fields to draw on a screen, as Field objects.
	 *
	 * @param int    $user_id The user being edited, or 0 on the add screen.
	 * @param string $screen  Which screen: profile or add.
	 *
	 * @return \SugarCommerce\Fields\Field[]
	 */
	private function visible_fields( int $user_id, string $screen ): array {
		$fields = [];

		// FieldSet::fields() returns a list, not a map — the key to look the
		// configuration up by is on the field itself. Reading $key here gave
		// 0, 1, 2, so every lookup missed and every field came back
		// permitted, which is the wrong way round for a permission check to
		// fail.
		foreach ( $this->set->fields( $user_id ) as $field ) {
			$key    = $field->key();
			$config = (array) ( $this->fields[ $key ] ?? [] );

			if ( ! $this->permitted( $config, $user_id ) ) {
				continue;
			}

			// A field can be kept off the add-user screen, where there is no
			// user yet and a default is all it could show.
			if ( 'add' === $screen && ! ( $config['show_on_add'] ?? true ) ) {
				continue;
			}

			$fields[ $key ] = $field;
		}

		return $fields;
	}

	/**
	 * Whether the current user may see and set a field.
	 *
	 * `own_capability` is the profile screen's own problem: the object being
	 * edited may be the person editing it, and a field an administrator may
	 * set on someone else is not always one the subject should set on
	 * themselves.
	 *
	 * @param array<string, mixed> $config  Field configuration.
	 * @param int                  $user_id The user being edited.
	 *
	 * @return bool
	 */
	private function permitted( array $config, int $user_id ): bool {
		$editing_self = $user_id > 0 && get_current_user_id() === $user_id;

		$capability = $editing_self && isset( $config['own_capability'] )
			? (string) $config['own_capability']
			: (string) ( $config['capability'] ?? 'edit_users' );

		return '' === $capability || current_user_can( $capability, $user_id ?: null );
	}
}
