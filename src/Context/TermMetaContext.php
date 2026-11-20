<?php
/**
 * Term Meta Context
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Context;

/**
 * Fields stored as term metadata.
 */
final class TermMetaContext extends AbstractMetaContext {

	/**
	 * The metadata type.
	 *
	 * @return string
	 */
	#[\Override]
	public function meta_type(): string {
		return 'term';
	}
}
