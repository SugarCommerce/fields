<?php
/**
 * Taxonomy Relational Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A searchable term reference.
 *
 * `term` is accepted as an alias: post-fields called it that and
 * setting-fields called it `taxonomy`, and both spellings appear in existing
 * config.
 */
final class TaxonomyType extends AbstractRelationalType {

	/**
	 * The search source.
	 *
	 * @return string
	 */
	protected function source(): string {
		return 'term';
	}

	/**
	 * Restrict the search to the configured taxonomy.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 */
	protected function search_args( Field $field ): array {
		return [ 'taxonomy' => (string) $field->get( 'taxonomy', 'category' ) ];
	}

	/**
	 * Resolve names for the selected ids.
	 *
	 * @param int[] $ids   Selected ids.
	 * @param Field $field The field.
	 *
	 * @return array<string, string>
	 */
	protected function resolve_labels( array $ids, Field $field ): array {
		$labels = [];

		foreach ( $ids as $id ) {
			$term = get_term( $id );

			if ( $term && ! is_wp_error( $term ) ) {
				$labels[ (string) $id ] = $term->name;
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
			[ 'taxonomy' ]
		);
	}

	/**
	 * A new term is called what its taxonomy calls one.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	protected function default_create_noun( Field $field ): string {
		$taxonomy = get_taxonomy( (string) $field->get( 'taxonomy', 'category' ) );
		$label    = $taxonomy ? (string) ( $taxonomy->labels->singular_name ?? '' ) : '';

		return '' === $label ? parent::default_create_noun( $field ) : self::in_sentence( $label );
	}
}
