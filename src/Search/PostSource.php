<?php
/**
 * Post Search Source
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Search;

use WP_Error;
use WP_Query;

/**
 * Searches posts of a requested type.
 *
 * The requested post type is checked against the ones actually registered
 * and shown in the admin, rather than passed to WP_Query as given: the type
 * arrives from the page, so treating it as trusted would let a request
 * enumerate any post type on the site, including private ones.
 *
 * It can also make a post from a title, for a picker that offers to: see
 * create() for the status it is given.
 */
final class PostSource implements Source, Creates {

	/**
	 * The name a field refers to this source by.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'post';
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
	 * Search post titles.
	 *
	 * @param string               $term  Search term.
	 * @param array<string, mixed> $args  Arguments the field supplied.
	 * @param int                  $page  One-based page number.
	 * @param int                  $limit Results per page.
	 *
	 * @return array{results: array<int, array{id: string, text: string}>, more: bool}
	 */
	public function search( string $term, array $args, int $page, int $limit ): array {
		$post_types = $this->post_type( $args );

		if ( [] === $post_types ) {
			return [
				'results' => [],
				'more'    => false,
			];
		}

		$query = new WP_Query(
			[
				'post_type'              => $post_types,
				'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
				// Unpublished work is listed for whoever may edit it, which
				// is what `perm` decides. Without it an explicit status list
				// shows every author's drafts to anyone holding edit_posts.
				'perm'                   => 'editable',
				's'                      => $term,
				'posts_per_page'         => $limit,
				'paged'                  => $page,
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$results = [];

		foreach ( $query->posts as $post ) {
			$title = get_the_title( $post );

			$results[] = [
				'id'   => (string) $post->ID,
				/* translators: %d: post ID */
				'text' => '' === $title ? sprintf( __( '(no title) #%d', 'sugarcommerce' ), $post->ID ) : $title,
			];
		}

		return [
			'results' => $results,
			'more'    => $page < (int) $query->max_num_pages,
		];
	}

	/**
	 * The post types to query: those asked for that exist, and that the
	 * current user may edit.
	 *
	 * The route's capability is a blanket edit_posts, because a picker has
	 * to be reachable to be useful. A post type with a capability map of its
	 * own -- an order, a subscription -- is a different question, and one its
	 * own edit_posts answers. Nothing is substituted for a request naming
	 * none the user may see: an empty list is an empty result, not a search
	 * of `post` instead.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return string[]
	 */
	private function post_type( array $args ): array {
		$requested = $args['post_type'] ?? 'post';
		$requested = is_array( $requested ) ? $requested : [ (string) $requested ];

		$allowed = get_post_types( [ 'show_ui' => true ], 'names' );
		$allowed = array_intersect( $requested, $allowed );

		return array_values( array_filter( $allowed, [ $this, 'can_edit' ] ) );
	}

	/**
	 * Whether the current user may edit a post type at all.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return bool
	 */
	private function can_edit( string $post_type ): bool {
		$object = get_post_type_object( $post_type );

		return null !== $object && current_user_can( $object->cap->edit_posts );
	}

	/**
	 * Whether the current user may make a post of the type asked for.
	 *
	 * The post type's own `create_posts`, which is what core's posts route
	 * and the Add New screen both ask. It is `edit_posts` on most types and
	 * something stricter on one with a capability map of its own.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function can_create( array $args ): bool {
		$object = $this->creatable_type( $args );

		return null !== $object && current_user_can( $object->cap->create_posts );
	}

	/**
	 * Make a post titled with what was typed.
	 *
	 * Published when the person may publish that type, and a draft when they
	 * may not, which is the most they could have done on the Add New screen.
	 * A field can ask for `draft` or `pending` instead; asking for `publish`
	 * or `private` without the right to is quietly a draft rather than a
	 * refusal, because the editor wanted the item and there is a version of
	 * it they are allowed to have. Either way it is theirs and so it stays
	 * findable: the search lists drafts to whoever may edit them.
	 *
	 * Post titles are not unique in WordPress, but one typed into a picker
	 * that already holds a post of that title is far more likely a second
	 * click than a second post, so the one already there is handed back.
	 *
	 * @param string               $text What was typed.
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return array{id: string, text: string, existing: bool}|WP_Error
	 * @since 1.7.0
	 */
	public function create( string $text, array $args ): array|WP_Error {
		$object = $this->creatable_type( $args );
		$title  = trim( $text );

		if ( null === $object ) {
			return new WP_Error(
				'sugarcommerce_fields_cannot_create',
				__( 'Nothing can be added here.', 'sugarcommerce' ),
				[ 'status' => 400 ]
			);
		}

		if ( '' === $title ) {
			return new WP_Error(
				'sugarcommerce_fields_empty_name',
				__( 'Type a name to add.', 'sugarcommerce' ),
				[ 'status' => 400 ]
			);
		}

		$existing = $this->titled( $title, $object->name );

		if ( null !== $existing ) {
			return [
				'id'       => (string) $existing,
				'text'     => get_the_title( $existing ),
				'existing' => true,
			];
		}

		$id = wp_insert_post(
			[
				'post_type'   => $object->name,
				'post_title'  => $title,
				'post_status' => $this->status( $args, $object ),
				'post_author' => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $id ) ) {
			$id->add_data( [ 'status' => 500 ] );

			return $id;
		}

		return [
			'id'       => (string) $id,
			'text'     => get_the_title( $id ),
			'existing' => false,
		];
	}

	/**
	 * The one post type a create request names, when it is one that can be
	 * made here.
	 *
	 * One, not a list: a post is made in a single type, and choosing one out
	 * of several for the editor would be guessing. Attachments are left out
	 * -- they have an editing screen and an upload capability, and a title
	 * alone is not a file.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return object|null The post type object.
	 * @since 1.7.0
	 */
	private function creatable_type( array $args ): ?object {
		$requested = $args['post_type'] ?? 'post';

		if ( ! is_string( $requested ) || 'attachment' === $requested ) {
			return null;
		}

		if ( ! in_array( $requested, get_post_types( [ 'show_ui' => true ], 'names' ), true ) ) {
			return null;
		}

		return get_post_type_object( $requested ) ?: null;
	}

	/**
	 * The status a new post is given.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 * @param object               $type The post type object.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	private function status( array $args, object $type ): string {
		$asked = is_string( $args['status'] ?? null ) ? $args['status'] : 'publish';

		if ( ! in_array( $asked, [ 'publish', 'private', 'pending', 'draft' ], true ) ) {
			$asked = 'publish';
		}

		if ( in_array( $asked, [ 'publish', 'private' ], true ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return 'draft';
		}

		return $asked;
	}

	/**
	 * A post of this type already carrying this title, that the current
	 * user can see in the picker.
	 *
	 * @param string $title     The title.
	 * @param string $post_type The post type.
	 *
	 * @return int|null
	 * @since 1.7.0
	 */
	private function titled( string $title, string $post_type ): ?int {
		$query = new WP_Query(
			[
				'post_type'              => $post_type,
				'post_status'            => [ 'publish', 'draft', 'pending', 'private' ],
				'perm'                   => 'editable',
				'title'                  => $title,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			]
		);

		$found = $query->posts[0] ?? null;

		return null === $found ? null : (int) $found;
	}
}
