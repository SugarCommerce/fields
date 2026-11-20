<?php
/**
 * Textarea Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Support\Markup;

/**
 * A multi-line text control.
 */
class TextareaType extends AbstractType {

	/**
	 * Config defaults.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function defaults(): array {
		return [ 'rows' => 5 ];
	}

	/**
	 * Render the textarea.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		$attributes->add_class( 'large-text', 'field-kit__textarea' );
		$attributes->set( 'rows', (int) $field->get( 'rows', 5 ) );
		$attributes->set_if( $field->has( 'cols' ), 'cols', $field->get( 'cols' ) );
		$attributes->set_if( $field->has( 'maxlength' ), 'maxlength', $field->get( 'maxlength' ) );

		$value = (string) $field->value();

		// A count against the limit, where there is one. A textarea is the
		// control most likely to have a limit worth watching -- a meta
		// description, an excerpt -- and the one where a person is furthest
		// from the end of what they have typed.
		return sprintf(
			'<textarea%s>%s</textarea>',
			$attributes->render(),
			esc_textarea( $value )
		) . Markup::counter(
			mb_strlen( $value ),
			(int) $field->get( 'maxlength', 0 ),
			(bool) $field->get( 'counter', true ),
			'large-text',
			(int) $field->get( 'soft_limit', 0 )
		);
	}

	/**
	 * Refuse a value longer than the field's `maxlength`, as the inputs do.
	 *
	 * @param mixed $value The sanitized value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	public function validate( mixed $value, Field $field ): string {
		$message = Markup::too_long( $value, $field );

		return '' === $message ? parent::validate( $value, $field ) : $message;
	}

	/**
	 * Coerce a submitted value.
	 *
	 * sanitize_textarea_field() rather than sanitize_text_field(), which
	 * collapses the newlines the control exists to accept.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): string {
		return sanitize_textarea_field( $this->scalar( $value ) );
	}

	/**
	 * A textarea takes a placeholder.
	 *
	 * @return bool
	 */
	#[\Override]
	public function supports_placeholder(): bool {
		return true;
	}

	/**
	 * Fits an inline row.
	 *
	 * A plain textarea, which needs nothing started.
	 *
	 * @return bool
	 */
	#[\Override]
	public function supports_inline(): bool {
		return true;
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
			[ 'cols', 'counter', 'maxlength', 'rows', 'soft_limit' ]
		);
	}
}
