<?php
/**
 * Text Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

/**
 * A plain single-line text input.
 */
final class TextType extends AbstractInputType {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	#[\Override]
	protected function input_type(): string {
		return 'text';
	}
}
