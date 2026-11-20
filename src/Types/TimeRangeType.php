<?php
/**
 * Time Range Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

/**
 * A start and end time.
 */
final class TimeRangeType extends AbstractRangePairType {

	/**
	 * The HTML input type.
	 *
	 * @return string
	 */
	#[\Override]
	protected function input_type(): string {
		return 'time';
	}
}
