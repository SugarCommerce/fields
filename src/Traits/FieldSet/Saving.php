<?php
/**
 * Field Set: Saving
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\FieldSet;

use SugarCommerce\Fields\Conditions;
use SugarCommerce\Fields\Contracts\Flushable;
use SugarCommerce\Fields\Contracts\Unechoed;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Support\Badge;
use SugarCommerce\Fields\Support\Rules;

/**
 * Checking, sanitizing and storing a submission, in one walk shared by all three.
 *
 * Part of FieldSet: only the class uses it.
 */
trait Saving {

	/**
	 * Check a submission without storing any of it.
	 *
	 * The same walk as save() — unslashed once, conditions applied, each
	 * value sanitized by its type or by the field's own `sanitize_callback`,
	 * the same fields skipped — so what this reports is exactly what save()
	 * would refuse. A consumer that would rather reject a whole submission
	 * than store the parts of it that passed asks this first.
	 *
	 * @param array<string, mixed> $input     Raw submitted values, still slashed.
	 * @param int|string           $object_id Object the values belong to.
	 *
	 * @return array<string, string> A message per failing field, keyed by
	 *                               field. Empty when everything passed.
	 * @since 1.2.0
	 */
	public function validate( array $input, int|string $object_id = 0 ): array {
		$errors = [];

		foreach ( $this->sanitized( $input, $object_id ) as [ $field, $met, $value ] ) {
			if ( ! $met ) {
				continue;
			}

			$message = $this->check( $field, $value );

			if ( '' !== $message ) {
				$errors[ $field->key() ] = $message;
			}
		}

		return $errors;
	}

	/**
	 * What a submission would store, without storing it.
	 *
	 * The same walk as save() and validate(), so a value here is exactly the
	 * one save() would write: each field's type, or its own
	 * `sanitize_callback`, over the unslashed input. A field whose conditions
	 * are not met is left out, as one a save would clear; a value a rule
	 * refuses is kept, so a form drawn again after a refusal shows what was
	 * typed beside the message rather than what was stored before.
	 *
	 * @param array<string, mixed> $input     Raw submitted values, still slashed.
	 * @param int|string           $object_id Object the values belong to.
	 *
	 * @return array<string, mixed> Keyed by field.
	 * @since 1.4.0
	 */
	public function sanitize( array $input, int|string $object_id = 0 ): array {
		$values = [];

		foreach ( $this->sanitized( $input, $object_id ) as [ $field, $met, $value ] ) {
			if ( $met && ! $this->is_empty( $value ) ) {
				$values[ $field->key() ] = $value;
			}
		}

		return $values;
	}

	/**
	 * The messages from the last save().
	 *
	 * Empty when every field passed, and when nothing has been saved yet.
	 *
	 * @return array<string, string> Keyed by field.
	 * @since 1.2.0
	 */
	public function errors(): array {
		return $this->errors;
	}

	/**
	 * Sanitize, check and store a submission.
	 *
	 * The raw input is unslashed once here, at the boundary, rather than in
	 * each sanitizer. Storage APIs disagree about whether they want slashed
	 * data, so each context re-slashes as its own API requires.
	 *
	 * A field whose conditions are not met is deleted rather than stored:
	 * the script hides it, but nothing stops a submission carrying it, and a
	 * hidden field silently keeping a stale value is how conditional settings
	 * come back to life.
	 *
	 * A field whose value fails validation is neither written nor cleared.
	 * What it held before the save is what it holds after, the message is
	 * kept for errors() and for the next render(), and every other field is
	 * stored as normal — a page of twenty settings is not thrown away over
	 * one bad email address.
	 *
	 * @param array<string, mixed> $input     Raw submitted values, still slashed.
	 * @param int|string           $object_id Object the values belong to.
	 *
	 * @return array<string, mixed> The values actually stored.
	 */
	public function save( array $input, int|string $object_id = 0 ): array {
		$stored       = [];
		$this->errors = [];

		foreach ( $this->sanitized( $input, $object_id ) as [ $field, $met, $value ] ) {
			if ( ! $met ) {
				$this->context->delete( $object_id, $field );

				continue;
			}

			$message = $this->check( $field, $value );

			if ( '' !== $message ) {
				$this->errors[ $field->key() ] = $message;

				continue;
			}

			if ( $this->is_empty( $value ) ) {
				$this->context->delete( $object_id, $field );

				continue;
			}

			$this->context->write( $object_id, $field, $value );

			$stored[ $field->key() ] = $value;
		}

		// A batching store — an option holds every field in one row — is told
		// once, here, rather than writing per field. Checked against the
		// contract and not against OptionContext, because the context reaching
		// this point is routinely a decorator wrapping one.
		if ( $this->context instanceof Flushable ) {
			$this->context->save();
		}

		return $stored;
	}

	/**
	 * Walk a submission the way save() does, producing each field's value.
	 *
	 * One walk shared by save() and validate(), because the two have to
	 * agree about which fields count and what their values are. A check
	 * that runs on slightly different input from the store it guards is a
	 * check that passes values the store then refuses, or the reverse — and
	 * two copies of a pipeline are never slightly different on purpose.
	 *
	 * A field a save would not touch — a type that stores nothing, a locked
	 * or disabled control — is left out entirely. One whose conditions are
	 * not met is included and marked so, since save() has to clear it.
	 *
	 * @param array<string, mixed> $input     Raw submitted values, still slashed.
	 * @param int|string           $object_id Object the values belong to.
	 *
	 * @return array<int, array{0: Field, 1: bool, 2: mixed}> Per field: the
	 *                                                        field, whether its
	 *                                                        conditions are met,
	 *                                                        and its sanitized
	 *                                                        value — null when
	 *                                                        they are not.
	 */
	private function sanitized( array $input, int|string $object_id ): array {
		$input   = wp_unslash( $input );
		$entries = [];

		// The fields somebody asked to have emptied. Read once, as an
		// array or as nothing: a string under this key is not a list.
		$clear = is_array( $input[ self::CLEAR_KEY ] ?? null ) ? $input[ self::CLEAR_KEY ] : [];

		foreach ( $this->fields( $object_id ) as $field ) {
			if ( ! $field->type()->stores_value() ) {
				continue;
			}

			// A locked or disabled control sends nothing, so the rules below would
			// read it as cleared and delete the stored value. An install that lost
			// a licence would have its premium settings wiped by the next unrelated
			// save, and get them back as blanks when the licence returned.
			if ( (bool) $field->get( 'disabled' ) || Badge::locks( $field ) ) {
				continue;
			}

			// A read-only control -- `readonly`, or a rating drawn for
			// `display` -- sends what it was drawn with, but nobody could
			// change it there, so whatever arrives under its name was written
			// by someone other than the form: a crafted request setting a
			// computed average, say. It keeps what it holds.
			if ( (bool) $field->get( 'readonly' ) || ( 'rating' === $field->type()->id() && (bool) $field->get( 'display' ) ) ) {
				continue;
			}

			$conditions = Conditions::from( $field->get( 'show_when', $field->get( 'depends', [] ) ) );

			if ( ! $conditions->is_empty() && ! $conditions->are_met( $input ) ) {
				$entries[] = [ $field, false, null ];

				continue;
			}

			$raw = $input[ $field->key() ] ?? null;

			// A control that never shows what is stored comes back empty
			// from everybody who did not retype it. Over a stored value
			// that is "unchanged", not "cleared", and the field is left out
			// of the walk the same way a locked one is: a tab with an API
			// key on it deleted the key every time anything else on the tab
			// was saved. With nothing stored there is nothing to keep, and
			// the field goes on to be checked -- so a required secret that
			// was never entered is still refused.
			//
			// Unless it was asked for. The control cannot be emptied to say
			// "remove this", so it says so beside itself, under CLEAR_KEY,
			// and the field goes on as the empty value it would have been:
			// deleted, or refused if it is required. Only here -- the flag
			// is read for a control that needs one and for no other, so it
			// cannot empty a field whose own box says what it holds -- and
			// only over an empty box: something typed is the newer
			// instruction and replaces what is stored.
			if (
				$field->type() instanceof Unechoed
				&& ( null === $raw || ( is_string( $raw ) && '' === trim( $raw ) ) )
				&& ! $this->is_empty( $this->context->read( $object_id, $field ) )
			) {
				if ( empty( $clear[ $field->key() ] ) ) {
					continue;
				}

				$raw = '';
			}

			// A consumer's own cleaning, which replaces the type's rather
			// than running after it: someone who supplies one has an opinion
			// about the whole value, and running the type's first would mean
			// they never see what was actually submitted. The key is listed
			// on Field as common, so every type has to honour it — only
			// `custom` did, which made it true of one type and documented for
			// all of them.
			$override = $field->get( 'sanitize_callback' );

			$entries[] = [
				$field,
				true,
				is_callable( $override )
					? $override( $raw, $field )
					: $field->type()->sanitize( $raw, $field ),
			];
		}

		return $entries;
	}

	/**
	 * The message for one sanitized value, or nothing.
	 *
	 * `required` is checked here rather than by the type, because it is the
	 * one rule every field shares and the one whose answer depends on what
	 * this set considers empty: zero is a value, an empty list is not, and
	 * that line is drawn once, in is_empty(), rather than by each of fifty
	 * types. The field's own `validate` rule runs only on a value that is
	 * there — an optional field left blank is not wrong.
	 *
	 * Only the set's own fields are checked. A required field inside a group
	 * or a repeater row is marked required in the markup, which the browser
	 * enforces, but its parent's value is stored whole and is not walked
	 * here.
	 *
	 * @param Field $field The field.
	 * @param mixed $value Its sanitized value.
	 *
	 * @return string
	 */
	private function check( Field $field, mixed $value ): string {
		if ( ! $this->is_empty( $value ) ) {
			return $field->type()->validate( $value, $field );
		}

		if ( ! $field->is_required() ) {
			return '';
		}

		return sprintf(
			/* translators: %s: field label */
			__( '%s is required.', 'sugarcommerce' ),
			Rules::name( $field )
		);
	}

	/**
	 * Whether a sanitized value is worth storing.
	 *
	 * Zero and "0" are values, not emptiness — an unchecked checkbox stores
	 * 0 deliberately, and treating it as empty is how a saved "off" reverts
	 * to the default on the next load.
	 *
	 * @param mixed $value Sanitized value.
	 *
	 * @return bool
	 */
	private function is_empty( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return [] === $value;
		}

		return null === $value || '' === $value;
	}
}
