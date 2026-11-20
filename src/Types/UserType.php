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
	#[\Override]
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
	#[\Override]
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
	#[\Override]
	protected function resolve_labels( array $ids, Field $field ): array {
		$labels = [];

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );

			// The address only for someone who may list users: the id is
			// whatever was submitted, and a label is printed for any of them.
			if ( $user ) {
				$labels[ (string) $id ] = current_user_can( 'list_users' )
					? $user->display_name . ' (' . $user->user_email . ')'
					: $user->display_name;
			}
		}

		return $labels;
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'role' ]
		);
	}

	/**
	 * A new user is a user.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	#[\Override]
	protected function default_create_noun( Field $field ): string {
		return __( 'user', 'sugarcommerce' );
	}

	/**
	 * The role a new user is asked for.
	 *
	 * `'create' => [ 'role' => 'customer' ]`, or else the role the field
	 * searches, so the person just added is one the same picker can find
	 * again. Neither, and the site's default role is used.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 * @since 1.7.0
	 */
	#[\Override]
	protected function create_args( Field $field ): array {
		$role = $this->create_options( $field )['role'] ?? $field->get( 'role', '' );

		return is_string( $role ) && '' !== $role ? [ 'role' => $role ] : [];
	}

	/**
	 * A user is added by their email address.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	#[\Override]
	protected function create_input(): string {
		return 'email';
	}
}
