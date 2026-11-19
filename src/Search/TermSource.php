<?php
/**
 * Term Search Source
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Search;

use WP_Error;
use WP_Term_Query;

/**
 * Searches terms in a requested taxonomy.
 *
 * The taxonomy is checked against the registered ones for the same reason
 * the post source checks its post type: it arrives from the page.
 *
 * It can also add a term, for a picker that offers to.
 */
final class TermSource implements Source, Creates {

	/**
	 * The name a field refers to this source by.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'term';
	}

	/**
	 * The capability required to search it.
	 *
	 * @return string
	 */
	public function capability(): string {
		return 'edit_posts';
	}

	/**
	 * Search terms.
	 *
	 * @param string               $term  Search term.
	 * @param array<string, mixed> $args  Arguments the field supplied.
	 * @param int                  $page  One-based page number.
	 * @param int                  $limit Results per page.
	 *
	 * @return array{results: array<int, array{id: string, text: string}>, more: bool}
	 */
	public function search( string $term, array $args, int $page, int $limit ): array {
		$taxonomy = $this->taxonomy( $args );

		$query = new WP_Term_Query(
			[
				'taxonomy'   => $taxonomy,
				'search'     => $term,
				'number'     => $limit,
				'offset'     => ( $page - 1 ) * $limit,
				'hide_empty' => false,
			]
		);

		$results = [];

		foreach ( (array) $query->get_terms() as $found ) {
			if ( ! $found instanceof \WP_Term ) {
				continue;
			}

			$results[] = [
				'id'   => (string) $found->term_id,
				'text' => $found->name,
			];
		}

		$total = (int) wp_count_terms(
			[
				'taxonomy'   => $taxonomy,
				'search'     => $term,
				'hide_empty' => false,
			]
		);

		return [
			'results' => $results,
			'more'    => ( $page * $limit ) < $total,
		];
	}

	/**
	 * The taxonomy to query, restricted to what really exists.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return string|string[]
	 */
	private function taxonomy( array $args ): string|array {
		$requested = $args['taxonomy'] ?? 'category';
		$requested = is_array( $requested ) ? $requested : [ (string) $requested ];

		$allowed = get_taxonomies( [ 'show_ui' => true ], 'names' );
		$allowed = array_values( array_intersect( $requested, $allowed ) );

		return [] === $allowed ? 'category' : $allowed;
	}

	/**
	 * Whether the current user may add a term to the taxonomy asked for.
	 *
	 * The taxonomy's own `edit_terms`, the capability core's terms route
	 * asks of a category. Core lets a tag be added with `assign_terms`
	 * alone, because the tag box on a post does; a picker is not that box,
	 * and the stricter rule is the one that cannot surprise anybody.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function can_create( array $args ): bool {
		$taxonomy = $this->creatable_taxonomy( $args );

		return null !== $taxonomy && current_user_can( $taxonomy->cap->edit_terms );
	}

	/**
	 * Add a term named with what was typed.
	 *
	 * A term of that name or slug already in the taxonomy is handed back
	 * rather than refused: core will not make two, and the editor who typed
	 * it wanted that term whichever of them made it first.
	 *
	 * @param string               $text What was typed.
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return array{id: string, text: string, existing: bool}|WP_Error
	 * @since 1.7.0
	 */
	public function create( string $text, array $args ): array|WP_Error {
		$taxonomy = $this->creatable_taxonomy( $args );
		$name     = trim( $text );

		if ( null === $taxonomy ) {
			return new WP_Error(
				'sugarcommerce_fields_cannot_create',
				__( 'Nothing can be added here.', 'sugarcommerce' ),
				[ 'status' => 400 ]
			);
		}

		if ( '' === $name ) {
			return new WP_Error(
				'sugarcommerce_fields_empty_name',
				__( 'Type a name to add.', 'sugarcommerce' ),
				[ 'status' => 400 ]
			);
		}

		$existing = term_exists( $name, $taxonomy->name );

		if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
			return $this->existing( (int) $existing['term_id'], $name );
		}

		$made = wp_insert_term( $name, $taxonomy->name );

		if ( is_wp_error( $made ) ) {
			// The check above and the insert are two queries, and another
			// request can add the same term between them. Core says which
			// term it collided with.
			if ( 'term_exists' === $made->get_error_code() && is_numeric( $made->get_error_data() ) ) {
				return $this->existing( (int) $made->get_error_data(), $name );
			}

			$made->add_data( [ 'status' => 400 ] );

			return $made;
		}

		return [
			'id'       => (string) $made['term_id'],
			'text'     => $name,
			'existing' => false,
		];
	}

	/**
	 * A term that was already there, labelled with its own name.
	 *
	 * @param int    $term_id  The term.
	 * @param string $fallback What was typed, should the term not load.
	 *
	 * @return array{id: string, text: string, existing: bool}
	 * @since 1.7.0
	 */
	private function existing( int $term_id, string $fallback ): array {
		$term = get_term( $term_id );

		return [
			'id'       => (string) $term_id,
			'text'     => $term instanceof \WP_Term ? $term->name : $fallback,
			'existing' => true,
		];
	}

	/**
	 * The one taxonomy a create request names, when it is one shown in the
	 * admin.
	 *
	 * Unlike the search there is no falling back to categories: a term made
	 * in a taxonomy nobody asked for is a term in the wrong place.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return object|null The taxonomy object.
	 * @since 1.7.0
	 */
	private function creatable_taxonomy( array $args ): ?object {
		$requested = $args['taxonomy'] ?? 'category';

		if ( ! is_string( $requested ) || ! in_array( $requested, get_taxonomies( [ 'show_ui' => true ], 'names' ), true ) ) {
			return null;
		}

		return get_taxonomy( $requested ) ?: null;
	}
}
