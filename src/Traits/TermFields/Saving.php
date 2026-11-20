<?php
/**
 * Term Fields: Saving
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\TermFields;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Screens\Errors;
use SugarCommerce\Fields\Types\AmountType;

/**
 * Saving a term's fields, only the ones the user may see and change.
 *
 * Part of TermFields: only the class uses it.
 */
trait Saving {

	/**
	 * Save a term's fields.
	 *
	 * Hooked to created_ and edited_, which fire for every write to a term
	 * there is — Quick Edit on the list table, a term-order plugin, a plain
	 * wp_update_term() from anywhere — and none of those carry these fields.
	 * The set reads an absent field as cleared, so without a nonce of this
	 * form's own, any of them would wipe every value the term had.
	 *
	 * @param int $term_id The term id.
	 *
	 * @return void
	 */
	public function save( int $term_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
		$nonce = isset( $_POST[ $this->id . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->id . '_nonce' ] ) ) : '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'save_' . $this->id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$input = $_POST;

		// Only what this user may set. A field they cannot see is not one a
		// crafted submission gets to write either — the screen hides it, and
		// without this the hiding would be the only thing stopping it.
		$allowed = $this->permitted_fields( $term_id );
		$set     = $this->permitted_set( $allowed );

		$set->save( $input, $term_id );
		$this->save_amount_units( $term_id, $input, $allowed );

		// A refused value is left as it was and the rest are stored. Core
		// redirects before anything here could say so, so the messages wait
		// for the next load of this term's edit screen — which is also where
		// they go for a term the add form just created over AJAX.
		Errors::remember( 'term', $term_id, $this->id, $set->errors() );
	}

	/**
	 * The field configuration limited to what the current user may write.
	 *
	 * Decided by the same callback the screens draw with, so "may they see
	 * it" and "may they set it" cannot drift apart.
	 *
	 * @param int $term_id The term being saved.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function permitted_fields( int $term_id ): array {
		$keys = array_map(
			static fn( Field $field ): string => $field->key(),
			$this->visible_fields( $term_id )
		);

		return array_intersect_key( $this->fields, array_flip( $keys ) );
	}

	/**
	 * A field set limited to the given fields.
	 *
	 * The full set is reused when nothing was withheld. A narrower one is
	 * built on the same context, because the encrypting decorator lives
	 * there and a set without it writes an encrypted field in the clear.
	 *
	 * @param array<string, array<string, mixed>> $allowed Field configuration.
	 *
	 * @return FieldSet
	 */
	private function permitted_set( array $allowed ): FieldSet {
		return $allowed === $this->fields
			? $this->set
			: new FieldSet( $allowed, $this->context, '', new Registry() );
	}

	/**
	 * Write the companion unit an amount field stores separately.
	 *
	 * The amount and its unit live under two meta keys because consumers
	 * query and sort on the unit independently. The field set writes the
	 * amount; only this context knows where the unit goes.
	 *
	 * @param int                                 $term_id The term id.
	 * @param array<string, mixed>                $input   Raw submission.
	 * @param array<string, array<string, mixed>> $fields  The fields this user may write.
	 *
	 * @return void
	 */
	private function save_amount_units( int $term_id, array $input, array $fields ): void {
		foreach ( array_keys( $fields ) as $key ) {
			$field = $this->set->field( (string) $key, $term_id );
			$type  = $field ? $field->type() : null;

			if ( ! $type instanceof AmountType ) {
				continue;
			}

			$unit = $type->posted_unit( $field, $input );

			if ( '' !== $unit ) {
				update_term_meta( $term_id, $type->unit_key( $field ), $unit );
			}
		}
	}

	/**
	 * The fields this user may see, with their values loaded.
	 *
	 * @param int $term_id The term id, or 0 on the add screen.
	 *
	 * @return \SugarCommerce\Fields\Field[]
	 */
	private function visible_fields( int $term_id = 0 ): array {
		return array_values(
			array_filter(
				$this->set->fields( $term_id ),
				function ( $field ) {
					$callback = $field->get( 'permission_callback' );

					return ! is_callable( $callback ) || (bool) $callback( $field );
				}
			)
		);
	}
}
