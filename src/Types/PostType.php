<?php
/**
 * Post Relational Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A searchable post reference.
 */
class PostType extends AbstractRelationalType {

	/**
	 * The search source.
	 *
	 * @return string
	 */
	#[\Override]
	protected function source(): string {
		return 'post';
	}

	/**
	 * Restrict the search to the configured post types.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 */
	#[\Override]
	protected function search_args( Field $field ): array {
		return [ 'post_type' => (string) $field->get( 'post_type', 'post' ) ];
	}

	/**
	 * Resolve titles for the selected ids.
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
			// A post this user may not read is named by its number: the id
			// is whatever was submitted, and a draft's or a private post's
			// title is not theirs to see. Still labelled, so it stays chosen.
			if ( ! current_user_can( 'read_post', $id ) ) {
				$labels[ (string) $id ] = '#' . $id;

				continue;
			}

			$title = get_the_title( $id );

			if ( '' !== $title ) {
				$labels[ (string) $id ] = $title;
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
			[ 'post_type' ]
		);
	}

	/**
	 * A new post is called what its post type calls one.
	 *
	 * The singular label, as it reads mid-sentence: a "Collection" post type
	 * offers to add "as a new collection".
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	#[\Override]
	protected function default_create_noun( Field $field ): string {
		$object = get_post_type_object( (string) ( $this->search_args( $field )['post_type'] ?? 'post' ) );
		$label  = $object->labels->singular_name ?? '';

		return '' === $label ? parent::default_create_noun( $field ) : self::in_sentence( (string) $label );
	}

	/**
	 * The status a new post is asked for, when the field chose one.
	 *
	 * `'create' => [ 'status' => 'draft' ]`. Without it the post is published
	 * where the person may publish that type, and a draft where they may not.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 * @since 1.7.0
	 */
	#[\Override]
	protected function create_args( Field $field ): array {
		$status = $this->create_options( $field )['status'] ?? '';

		return is_string( $status ) && '' !== $status ? [ 'status' => sanitize_key( $status ) ] : [];
	}
}
