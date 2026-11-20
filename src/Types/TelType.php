<?php
/**
 * Tel Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A telephone input.
 */
final class TelType extends AbstractInputType {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	#[\Override]
	protected function input_type(): string {
		return 'tel';
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
}
