<?php
/**
 * User Relational Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A searchable user reference.
 */
final class UserType extends AbstractRelationalType {

	/**
	 * The search source.
	 *
	 * @return string
	 */
	protected function source(): string {
		return 'user';
	}

	/**
	 * Restrict the search to the configured role.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 */
	protected function search_args( Field $field ): array {
		return $field->has( 'role' ) ? [ 'role' => (string) $field->get( 'role' ) ] : [];
	}

	/**
	 * Resolve display names for the selected ids.
	 *
	 * @param int[] $ids   Selected ids.
	 * @param Field $field The field.
	 *
	 * @return array<string, string>
	 */
	protected function resolve_labels( array $ids, Field $field ): array {
		$labels = [];

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );

			if ( $user ) {
				$labels[ (string) $id ] = $user->display_name . ' (' . $user->user_email . ')';
			}
		}

		return $labels;
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'role' ]
		);
	}
}
