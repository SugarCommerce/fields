<?php
/**
 * Hidden Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A hidden input.
 */
final class HiddenType extends AbstractInputType {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	#[\Override]
	protected function input_type(): string {
		return 'hidden';
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
	 * Does not fit an inline row.
	 *
	 * Nothing to edit, and a hidden input in a bulk edit would write the same value to every selected object.
	 *
	 * @return bool
	 */
	#[\Override]
	public function supports_inline(): bool {
		return false;
	}
}
