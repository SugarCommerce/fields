<?php
/**
 * Date Range Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

/**
 * A start and end date.
 */
final class DateRangeType extends AbstractRangePairType {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	protected function input_type(): string {
		return 'date';
	}
}
