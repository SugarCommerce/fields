<?php
/**
 * Toggle Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;

/**
 * A switch.
 *
 * Still a real checkbox underneath, styled as a switch and given
 * `role="switch"`. A div with click handlers would need tabindex, key
 * handling and state management rebuilt by hand, and usually ships without
 * them; a checkbox is focusable and operable from the keyboard already, and
 * role="switch" is the one thing that makes it announce as on/off rather
 * than checked/unchecked.
 */
final class ToggleType extends CheckboxType {

	/**
	 * Announce as a switch rather than a checkbox.
	 *
	 * @param Attributes $attributes Attributes being built.
	 * @param Field      $field      The field.
	 *
	 * @return void
	 */
	#[\Override]
	protected function apply_role( Attributes $attributes, Field $field ): void {
		$attributes->set( 'role', 'switch' );
		$attributes->set( 'aria-checked', $this->is_checked( $field ) ? 'true' : 'false' );
		$attributes->add_class( 'field-kit__toggle' );
	}
}
