<?php
/**
 * User Search Source
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Search;

use WP_Error;
use WP_User_Query;

/**
 * Searches users.
 *
 * Requires `list_users`, not the blanket `edit_posts` the other sources use:
 * an author can edit posts and has no business enumerating every account and
 * email address on the site.
 *
 * It can also add a user from an email address, for a picker that offers to.
 */
final class UserSource implements Source, Creates {

	/**
	 * The name a field refers to this source by.
	 *
	 * @return string
	 */
	#[\Override]
	public function name(): string {
		return 'user';
	}

	/**
	 * The capability required to search it.
	 *
	 * @return string
	 */
	#[\Override]
	public function capability(): string {
		return 'list_users';
	}

	/**
	 * Search users.
	 *
	 * @param string               $term  Search term.
	 * @param array<string, mixed> $args  Arguments the field supplied.
	 * @param int                  $page  One-based page number.
	 * @param int                  $limit Results per page.
	 *
	 * @return array{results: array<int, array{id: string, text: string}>, more: bool}
	 */
	#[\Override]
	public function search( string $term, array $args, int $page, int $limit ): array {
		$query_args = [
			'search'         => '' === $term ? '' : '*' . $term . '*',
			'search_columns' => [ 'user_login', 'user_email', 'display_name', 'user_nicename' ],
			'number'         => $limit,
			'paged'          => $page,
			'fields'         => [ 'ID', 'display_name', 'user_email' ],
			'count_total'    => true,
		];

		if ( isset( $args['role'] ) && is_string( $args['role'] ) ) {
			$roles = wp_roles()->get_names();

			if ( isset( $roles[ $args['role'] ] ) ) {
				$query_args['role'] = $args['role'];
			}
		}

		$query   = new WP_User_Query( $query_args );
		$results = [];

		foreach ( $query->get_results() as $user ) {
			$results[] = [
				'id'   => (string) $user->ID,
				'text' => sprintf( '%s (%s)', $user->display_name, $user->user_email ),
			];
		}

		return [
			'results' => $results,
			'more'    => ( $page * $limit ) < (int) $query->get_total(),
		];
	}

	/**
	 * Whether the current user may add a user with the role asked for.
	 *
	 * `create_users`, and a role on the list WordPress lets this person hand
	 * out (get_editable_roles()) -- the same two things the Add New User
	 * screen checks. The role is checked rather than trusted because it
	 * arrives from the page: a request is free to ask for `administrator`.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	#[\Override]
	public function can_create( array $args ): bool {
		return current_user_can( 'create_users' ) && null !== $this->role( $args );
	}

	/**
	 * Add a user from an email address.
	 *
	 * An address is the one thing a person typing into a picker is likely
	 * to know and the one thing WordPress will not let two accounts share.
	 * The login is made from the part before the @, numbered when taken,
	 * and the password is random: nothing is emailed, as core's users route
	 * emails nothing, and the person can set one through "Lost your
	 * password?" whenever they need to sign in.
	 *
	 * An address already registered selects that account instead.
	 *
	 * @param string               $text What was typed.
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return array{id: string, text: string, existing: bool}|WP_Error
	 * @since 1.7.0
	 */
	#[\Override]
	public function create( string $text, array $args ): array|WP_Error {
		$email = sanitize_email( trim( $text ) );
		$role  = $this->role( $args );

		if ( '' === $email || ! is_email( $email ) ) {
			return new WP_Error(
				'sugarcommerce_fields_invalid_email',
				/* translators: %s: what was typed */
				sprintf( __( '“%s” is not an email address. A new user is added by their email address.', 'sugarcommerce' ), trim( $text ) ),
				[ 'status' => 400 ]
			);
		}

		if ( null === $role ) {
			return new WP_Error(
				'sugarcommerce_fields_cannot_create',
				__( 'You are not allowed to give a new user that role.', 'sugarcommerce' ),
				[ 'status' => 403 ]
			);
		}

		$existing = email_exists( $email );

		if ( $existing ) {
			return $this->made( (int) $existing, true );
		}

		$id = wp_insert_user(
			[
				'user_login' => $this->login( $email ),
				'user_email' => $email,
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => $role,
			]
		);

		if ( is_wp_error( $id ) ) {
			$id->add_data( [ 'status' => 400 ] );

			return $id;
		}

		return $this->made( (int) $id, false );
	}

	/**
	 * A user, labelled the way the search labels one.
	 *
	 * @param int  $id       The user.
	 * @param bool $existing Whether they were already there.
	 *
	 * @return array{id: string, text: string, existing: bool}
	 * @since 1.7.0
	 */
	private function made( int $id, bool $existing ): array {
		$user = get_userdata( $id );

		return [
			'id'       => (string) $id,
			'text'     => $user ? sprintf( '%s (%s)', $user->display_name, $user->user_email ) : (string) $id,
			'existing' => $existing,
		];
	}

	/**
	 * The role a new user is given, when the current user may give it.
	 *
	 * The field's choice, or the site's default role. Null for one that
	 * does not exist or that this person may not hand out.
	 *
	 * get_editable_roles() lives in an admin include that a REST request
	 * has not loaded.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return string|null
	 * @since 1.7.0
	 */
	private function role( array $args ): ?string {
		$role = $args['role'] ?? '';
		$role = is_string( $role ) && '' !== $role ? $role : (string) get_option( 'default_role', 'subscriber' );

		if ( ! function_exists( 'get_editable_roles' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/user.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		if ( ! function_exists( 'get_editable_roles' ) ) {
			return null;
		}

		return array_key_exists( $role, (array) get_editable_roles() ) ? $role : null;
	}

	/**
	 * A login made from an email address, numbered until it is free.
	 *
	 * @param string $email The address.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	private function login( string $email ): string {
		$base = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base = '' === $base ? 'user' : substr( $base, 0, 50 );
		$try  = $base;

		for ( $n = 2; username_exists( $try ); $n++ ) {
			$try = $base . $n;
		}

		return $try;
	}
}
