<?php
/**
 * Email Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * An email input. Note that is_email() and filter_var() disagree in both directions;
 * sanitize_email() is what WordPress itself trusts, so it is what is used here.
 */
final class EmailType extends AbstractInputType {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	#[\Override]
	protected function input_type(): string {
		return 'email';
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
		return sanitize_email( $this->scalar( $value ) );
	}
}
