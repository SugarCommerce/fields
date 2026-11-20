<?php
/**
 * Page Relational Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A searchable page reference — a post reference fixed to the page type.
 */
final class PageType extends PostType {

	/**
	 * Always pages.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 */
	#[\Override]
	protected function search_args( Field $field ): array {
		return [ 'post_type' => 'page' ];
	}
}
