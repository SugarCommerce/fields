<?php
/**
 * Datetime Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A native date and time input.
 */
final class DatetimeType extends AbstractInputType {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	protected function input_type(): string {
		return 'datetime-local';
	}

	/**
	 * Coerce a submitted value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, Field $field ): mixed {
		return sanitize_text_field( $this->scalar( $value ) );
	}

	/**
	 * The config spelling is "datetime"; the HTML type is "datetime-local".
	 *
	 * @return string
	 */
	public function id(): string {
		return 'datetime';
	}
}
