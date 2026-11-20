<?php
/**
 * Post Meta Context
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Context;

/**
 * Fields stored as post metadata.
 */
final class PostMetaContext extends AbstractMetaContext {

	/**
	 * The metadata type.
	 *
	 * @return string
	 */
	#[\Override]
	public function meta_type(): string {
		return 'post';
	}
}
