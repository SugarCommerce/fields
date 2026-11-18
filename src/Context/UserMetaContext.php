<?php
/**
 * User Meta Context
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Context;

/**
 * Fields stored as user metadata.
 */
final class UserMetaContext extends AbstractMetaContext {

	/**
	 * The metadata type.
	 *
	 * @return string
	 */
	public function meta_type(): string {
		return 'user';
	}
}
