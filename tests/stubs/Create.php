<?php
/**
 * What the create route writes with, as far as the tests need it.
 *
 * Posts, terms and users held in globals a test fills, so a test can say
 * "a Sample Packs collection already exists" and then watch whether the
 * source makes another. Each insert is recorded, which is how a test sees
 * that a refused request wrote nothing.
 *
 * In a file of its own rather than in stubs.php because only the create
 * tests reach any of it, and it carries a class (WP_Query) as well as
 * functions.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $post_type ) {
		return $GLOBALS['fk_post_types'][ (string) $post_type ] ?? null;
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	function get_taxonomy( $taxonomy ) {
		return $GLOBALS['fk_taxonomies'][ (string) $taxonomy ] ?? false;
	}
}

if ( ! function_exists( 'term_exists' ) ) {
	/**
	 * By name or slug, in one taxonomy, as core matches.
	 *
	 * Blind when a test says so, to stand for the request that added the
	 * same term between the check and the insert.
	 */
	function term_exists( $term, $taxonomy = '', $parent_term = null ) {
		if ( ! empty( $GLOBALS['fk_term_exists_blind'] ) ) {
			return null;
		}

		foreach ( $GLOBALS['fk_terms'] ?? [] as $id => $found ) {
			if ( ( $found->taxonomy ?? '' ) !== $taxonomy ) {
				continue;
			}

			if ( 0 === strcasecmp( (string) ( $found->name ?? '' ), (string) $term ) || sanitize_title( (string) $term ) === sanitize_title( (string) ( $found->name ?? '' ) ) ) {
				return [
					'term_id'          => (string) $id,
					'term_taxonomy_id' => (string) $id,
				];
			}
		}

		return null;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $term, $taxonomy, $args = [] ) {
		foreach ( $GLOBALS['fk_terms'] ?? [] as $id => $found ) {
			if ( ( $found->taxonomy ?? '' ) === $taxonomy && 0 === strcasecmp( (string) ( $found->name ?? '' ), (string) $term ) ) {
				return new WP_Error( 'term_exists', 'A term with the name provided already exists in this taxonomy.', $id );
			}
		}

		$id = 1000 + count( $GLOBALS['fk_terms'] ?? [] );

		$GLOBALS['fk_terms'][ $id ] = fk_test_term( $id, (string) $term, (string) $taxonomy );
		$GLOBALS['fk_inserted'][]   = [ 'term', $term, $taxonomy ];

		return [
			'term_id'          => $id,
			'term_taxonomy_id' => $id,
		];
	}
}

if ( ! function_exists( 'fk_test_term' ) ) {
	/**
	 * A term with a name, which the screens' WP_Term stub does not carry.
	 */
	function fk_test_term( int $id, string $name, string $taxonomy ): object {
		$term = new class() extends WP_Term {

			/**
			 * The term's name.
			 *
			 * @var string
			 */
			public string $name = '';
		};

		$term->term_id  = $id;
		$term->name     = $name;
		$term->taxonomy = $taxonomy;

		return $term;
	}
}

if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( $postarr, $wp_error = false, $fire_after_hooks = true ) {
		$id = 5000 + count( $GLOBALS['fk_posts'] ?? [] );

		$GLOBALS['fk_posts'][ $id ] = $postarr;
		$GLOBALS['fk_inserted'][]   = [ 'post', $postarr ];

		// get_the_title() reads its stub's names from here.
		$GLOBALS['fk_attachments'][ $id ] = [ 'name' => (string) ( $postarr['post_title'] ?? '' ) ];

		return $id;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Posts by type and exact title, which is all the create route asks.
	 */
	class WP_Query {
		public $posts = [];

		public $max_num_pages = 0;

		public function __construct( $args = [] ) {
			foreach ( $GLOBALS['fk_posts'] ?? [] as $id => $post ) {
				if ( ( $post['post_type'] ?? '' ) !== ( $args['post_type'] ?? '' ) ) {
					continue;
				}

				if ( isset( $args['title'] ) && 0 !== strcasecmp( (string) ( $post['post_title'] ?? '' ), (string) $args['title'] ) ) {
					continue;
				}

				$this->posts[] = $id;
			}
		}
	}
}

if ( ! function_exists( 'email_exists' ) ) {
	function email_exists( $email ) {
		foreach ( $GLOBALS['fk_users'] ?? [] as $id => $user ) {
			if ( 0 === strcasecmp( (string) $user['user_email'], (string) $email ) ) {
				return $id;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'username_exists' ) ) {
	function username_exists( $username ) {
		foreach ( $GLOBALS['fk_users'] ?? [] as $id => $user ) {
			if ( (string) $user['user_login'] === (string) $username ) {
				return $id;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'wp_insert_user' ) ) {
	function wp_insert_user( $userdata ) {
		$id = 7000 + count( $GLOBALS['fk_users'] ?? [] );

		$GLOBALS['fk_users'][ $id ] = $userdata;
		$GLOBALS['fk_inserted'][]   = [ 'user', $userdata ];

		return $id;
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
		return str_repeat( 'x', (int) $length );
	}
}

if ( ! function_exists( 'sanitize_user' ) ) {
	function sanitize_user( $username, $strict = false ) {
		$username = strtolower( (string) $username );

		return (string) preg_replace( $strict ? '/[^a-z0-9 _.\-@]/' : '/[<>]/', '', $username );
	}
}

if ( ! function_exists( 'get_editable_roles' ) ) {
	/**
	 * The roles the current user may hand out: subscriber and editor unless a
	 * test says otherwise. Never administrator, which is the one a request
	 * would try.
	 */
	function get_editable_roles() {
		return $GLOBALS['fk_editable_roles'] ?? [
			'subscriber' => [ 'name' => 'Subscriber' ],
			'editor'     => [ 'name' => 'Editor' ],
		];
	}
}
