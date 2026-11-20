<?php
/**
 * Password Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Contracts\Unechoed;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Glyphs;

/**
 * A password input. The value is never echoed back into the markup.
 *
 * It can be revealed while it is typed. A key pasted into a box of dots
 * cannot be checked against the one it was copied from, and the usual result
 * is a saved key with a character missing and an integration that fails for
 * no visible reason. The button is the one core draws on the profile screen,
 * with the same classes, so it is the control people have already met.
 *
 * Revealing shows what has been *typed*. The stored value is still never
 * sent to the page, so there is nothing to reveal until something is
 * entered -- which is the point: a reveal button is not a way to read a
 * secret back out of the database.
 *
 * And because the box is always drawn empty, an empty box on save means
 * nobody retyped the secret, not that somebody cleared it: the stored value
 * is kept. That is the Unechoed contract, and FieldSet is what honours it.
 *
 * Which leaves no way to take a secret away by emptying its box, so a box
 * with something stored behind it has a Remove button beside it. Pressing
 * it sends nothing: it marks the field, says so in the box, and turns into
 * Undo. The removal rides the form's own save, with the form's own nonce
 * and capability, as the field set's CLEAR_KEY.
 */
final class PasswordType extends AbstractInputType implements Unechoed {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	#[\Override]
	protected function input_type(): string {
		return 'password';
	}

	/**
	 * The input, with a button to show what has been typed into it.
	 *
	 * Wrapped here rather than in render() so the count a `maxlength` adds
	 * lands after the whole control, not between the input and its button.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	protected function control( Field $field, Attributes $attributes ): string {
		$input  = parent::control( $field, $attributes );
		$remove = $this->remove( $field );

		// Off unless asked for. Core offers the button where somebody is
		// setting a password they will have to type again; an API key pasted
		// once and never shown back is not that.
		if ( ! (bool) $field->get( 'reveal', false ) ) {
			return '' === $remove
				? $input
				: sprintf( '<span class="field-kit__password">%s%s</span>', $input, $remove );
		}

		// hide-if-no-js: without the script the button does nothing, and a
		// button that does nothing is worse than none. aria-pressed carries
		// the state; the label is swapped as well because that is what
		// core's own toggle does, and matching it is the reason for using
		// its classes.
		return sprintf(
			'<span class="field-kit__password">%s<button type="button" class="button button-secondary wp-hide-pw hide-if-no-js field-kit__password-toggle" aria-label="%s" aria-pressed="false" data-toggle="0">%4$s</button>%3$s</span>',
			$input,
			esc_attr__( 'Show password', 'sugarcommerce' ),
			$remove,
			Glyphs::get( 'show' )
		);
	}

	/**
	 * The button that marks a stored secret for removal, and its flag.
	 *
	 * Drawn only where pressing it can mean something: there is a value
	 * stored, the field belongs to a set that told it where to say so
	 * (`clear_name` -- a field inside a repeater row has none), the control
	 * can be changed at all, and nobody turned it off with `'removable' =>
	 * false`. A required secret has none either: the save would refuse it.
	 *
	 * The flag is a hidden input that is disabled, so it is not submitted,
	 * until the button enables it. Without the script the button is not
	 * shown and the flag stays out of every submission.
	 *
	 * Every string the script swaps in is written here, where the field's
	 * label is, so it reads "Remove saved Live restricted key" to somebody
	 * who cannot see which box the button is beside.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	private function remove( Field $field ): string {
		$name   = (string) $field->get( 'clear_name', '' );
		$stored = $field->raw_value();

		if (
			'' === $name
			|| null === $stored
			|| '' === $stored
			|| ! (bool) $field->get( 'removable', true )
			|| (bool) $field->get( 'disabled' )
			|| (bool) $field->get( 'readonly' )
			|| $field->is_required()
		) {
			return '';
		}

		$label = $field->label();

		return sprintf(
			'<input type="hidden" class="field-kit__password-clear" name="%1$s" value="1" disabled />' .
			'<button type="button" class="button-link field-kit__password-remove hide-if-no-js" aria-label="%2$s"' .
			' data-remove="%3$s" data-remove-label="%2$s" data-undo="%4$s" data-undo-label="%5$s" data-pending="%6$s">%3$s</button>',
			esc_attr( $name ),
			/* translators: %s: field label */
			esc_attr( sprintf( __( 'Remove saved %s', 'sugarcommerce' ), $label ) ),
			esc_attr__( 'Remove', 'sugarcommerce' ),
			esc_attr__( 'Undo', 'sugarcommerce' ),
			/* translators: %s: field label */
			esc_attr( sprintf( __( 'Keep saved %s', 'sugarcommerce' ), $label ) ),
			esc_attr__( 'Will be removed when you save', 'sugarcommerce' )
		);
	}

	/**
	 * Coerce a submitted value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return mixed
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): mixed {
		return sanitize_text_field( $this->scalar( $value ) );
	}

	/**
	 * Never render the stored value back into the page.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	protected function render_value( Field $field ): string {
		return '';
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'removable', 'reveal' ]
		);
	}
}
