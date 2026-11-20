<?php
/**
 * Tab Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.1.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;

/**
 * Class TabType
 *
 * Divides the fields that follow it into a tab, up to the next tab.
 *
 * It is a marker, not a control. It renders nothing here: the field set sees
 * the marker and builds the tab list and panels around the fields between
 * markers, because that grouping cannot be expressed by a field that only
 * knows about itself.
 *
 * A form with one tab is not a form with tabs, so a single marker is ignored
 * and its fields render as they always did.
 */
final class TabType extends AbstractLayoutType {

	/**
	 * This field opens a tab.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	#[\Override]
	public function opens_section(): string {
		return 'tab';
	}

	/**
	 * Nothing: the field set draws the tab from the marker.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Control attributes.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		return '';
	}
}
