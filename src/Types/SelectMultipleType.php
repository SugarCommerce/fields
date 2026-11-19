<?php
/**
 * Multiple Select Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A select that accepts more than one value.
 *
 * Kept as its own type because config written as `'type' => 'select_multiple'`
 * reads better than a select carrying a flag, and because both predecessor
 * spellings have to keep resolving.
 */
final class SelectMultipleType extends SelectType {

	/**
	 * Always multiple, whatever the config says.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	protected function is_multiple( Field $field ): bool {
		return true;
	}

	/**
	 * The chips combobox unless told otherwise.
	 *
	 * A bare `<select multiple>` is the browser's fixed-height listbox, held
	 * open with a modifier key -- the one control on the screen that looked
	 * like nothing else and that nobody knows how to use. `'searchable' =>
	 * false` keeps it, for a form that wants the listbox.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 * @since 1.6.0
	 */
	protected function uses_enhanced_ui( Field $field ): bool {
		return (bool) $field->get( 'searchable', true );
	}
}
