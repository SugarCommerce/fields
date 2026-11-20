<?php
/**
 * Search REST Controller
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Rest;

use SugarCommerce\Fields\Search\Creates;
use SugarCommerce\Fields\Search\Sources;
use SugarCommerce\Fields\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The one endpoint every searchable field talks to.
 *
 * It replaces three implementations: setting-fields had a REST route,
 * post-fields had a second one, and flyouts went through admin-ajax with a
 * callback named in the field config.
 *
 * The namespace is derived rather than written, because
 * `WP_REST_Server::register_route()` merges same-path registrations with
 * array_merge over a numerically-indexed handler list. Two plugins bundling
 * a prefixed copy of this library under one namespace would mean the first
 * to register answered the other's requests — under its own capability check
 * and against its own sources.
 *
 * Beside it, `POST /search/create`: a picker that found nothing can make the
 * item it was looking for, when its source can (Search\Creates) and the
 * current user may. It is here rather than in a controller of its own
 * because it is the same sources, resolved the same way and claimed under
 * the same namespace -- a second controller would be a second claim that
 * could disagree with this one.
 */
final class SearchController {

	/**
	 * Largest page a request may ask for.
	 */
	private const int MAX_LIMIT = 50;

	/**
	 * Longest name a create request may send.
	 *
	 * A post title or a term name, typed into a search box. Anything longer
	 * is not a name someone typed.
	 */
	private const int MAX_NAME = 200;

	/**
	 * The available sources.
	 *
	 * @var Sources
	 */
	private Sources $sources;

	/**
	 * Paths already claimed, so a second instance cannot double-register.
	 *
	 * @var array<string, bool>
	 */
	private static array $claimed = [];

	/**
	 * Construct.
	 *
	 * @param Sources|null $sources The available sources.
	 */
	public function __construct( ?Sources $sources = null ) {
		$this->sources = $sources ?? Sources::shared();
	}

	/**
	 * Hook the route registration.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$namespace = Runtime::rest_namespace();

		if ( isset( self::$claimed[ $namespace ] ) ) {
			return;
		}

		self::$claimed[ $namespace ] = true;

		register_rest_route(
			$namespace,
			'/search',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->args(),
			]
		);

		register_rest_route(
			$namespace,
			'/search/create',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create' ],
				'permission_callback' => [ $this, 'check_create_permission' ],
				'args'                => $this->create_args(),
			]
		);
	}

	/**
	 * Arguments to the create route.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.7.0
	 */
	private function create_args(): array {
		return [
			'source' => [
				'description'       => __( 'Name of the source to add to.', 'sugarcommerce' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			],
			'text'   => [
				'description'       => __( 'What was typed: a name, or an email address for a user.', 'sugarcommerce' ),
				'type'              => 'string',
				'required'          => true,
				'minLength'         => 1,
				'maxLength'         => self::MAX_NAME,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'args'   => [
				'description' => __( 'Arguments the field supplied, such as taxonomy, status or role.', 'sugarcommerce' ),
				'type'        => 'object',
				'default'     => [],
			],
		];
	}

	/**
	 * Endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function args(): array {
		return [
			'source' => [
				'description'       => __( 'Name of the source to search.', 'sugarcommerce' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			],
			'q'      => [
				'description'       => __( 'Search term.', 'sugarcommerce' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'page'   => [
				'description' => __( 'Page of results, one-based.', 'sugarcommerce' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			],
			'limit'  => [
				'description' => __( 'Results per page.', 'sugarcommerce' ),
				'type'        => 'integer',
				'default'     => 20,
				'minimum'     => 1,
				'maximum'     => self::MAX_LIMIT,
			],
			'args'   => [
				'description' => __( 'Arguments the field supplied, such as post type.', 'sugarcommerce' ),
				'type'        => 'object',
				'default'     => [],
			],
		];
	}

	/**
	 * Whether the request may search the named source.
	 *
	 * The capability comes from the source rather than being one blanket
	 * check: searching users is not the same risk as searching categories.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ): bool|WP_Error {
		$allowed = $this->check_logged_in_and_known( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		$source = $this->sources->get( (string) $request->get_param( 'source' ) );

		if ( ! current_user_can( $source->capability() ) ) {
			return new WP_Error(
				'sugarcommerce_fields_forbidden',
				__( 'You are not allowed to search this.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * The two checks both routes start with: someone is signed in, and the
	 * source they named exists.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 * @since 1.7.0
	 */
	private function check_logged_in_and_known( WP_REST_Request $request ): bool|WP_Error {
		// Before the lookup, so an anonymous request cannot learn which
		// names are registered from whether it gets a 404 or a 401. The
		// names are built from field keys, which is more than nothing.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'sugarcommerce_fields_forbidden',
				__( 'You are not allowed to search this.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		if ( null === $this->sources->get( (string) $request->get_param( 'source' ) ) ) {
			return new WP_Error(
				'sugarcommerce_fields_unknown_source',
				__( 'Unknown search source.', 'sugarcommerce' ),
				[ 'status' => 404 ]
			);
		}

		return true;
	}

	/**
	 * Whether the request may add to the named source.
	 *
	 * Not the search's capability: being able to find a category is not
	 * being able to make one. The source answers for the arguments it was
	 * sent, so a taxonomy, a post type or a role the person may not use is a
	 * 403 before anything is written.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 * @since 1.7.0
	 */
	public function check_create_permission( WP_REST_Request $request ): bool|WP_Error {
		$allowed = $this->check_logged_in_and_known( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		$source = $this->sources->get( (string) $request->get_param( 'source' ) );

		if ( ! $source instanceof Creates || ! $source->can_create( $this->clean_args( (array) $request->get_param( 'args' ) ) ) ) {
			return new WP_Error(
				'sugarcommerce_fields_cannot_create',
				__( 'You are not allowed to add that here.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Add an item to a source, and answer with it as a search would.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 * @since 1.7.0
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = $this->sources->get( (string) $request->get_param( 'source' ) );

		// Asked again, not assumed from the permission check: a consumer
		// calling this method directly has not been through it.
		if ( ! $source instanceof Creates ) {
			return new WP_Error(
				'sugarcommerce_fields_cannot_create',
				__( 'You are not allowed to add that here.', 'sugarcommerce' ),
				[ 'status' => 404 ]
			);
		}

		$args = $this->clean_args( (array) $request->get_param( 'args' ) );

		if ( ! $source->can_create( $args ) ) {
			return new WP_Error(
				'sugarcommerce_fields_cannot_create',
				__( 'You are not allowed to add that here.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$text = trim( (string) $request->get_param( 'text' ) );
		$made = $source->create( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_NAME ) : substr( $text, 0, self::MAX_NAME ), $args );

		if ( is_wp_error( $made ) ) {
			return $made;
		}

		return new WP_REST_Response(
			[
				'id'       => (string) $made['id'],
				'text'     => (string) $made['text'],
				'existing' => (bool) $made['existing'],
			],
			$made['existing'] ? 200 : 201
		);
	}

	/**
	 * Search a source.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response {
		$source = $this->sources->get( (string) $request->get_param( 'source' ) );

		$found = $source->search(
			(string) $request->get_param( 'q' ),
			$this->clean_args( (array) $request->get_param( 'args' ) ),
			(int) $request->get_param( 'page' ),
			min( self::MAX_LIMIT, (int) $request->get_param( 'limit' ) )
		);

		return new WP_REST_Response(
			[
				'results' => array_values( $found['results'] ?? [] ),
				'more'    => (bool) ( $found['more'] ?? false ),
			]
		);
	}

	/**
	 * Reduce the supplied arguments to scalars.
	 *
	 * A source decides for itself which of these it trusts — the post source
	 * checks its post type against the registered ones — but nothing nested
	 * or object-shaped reaches a query from here.
	 *
	 * @param array<string, mixed> $args Raw arguments.
	 *
	 * @return array<string, scalar|array<int, scalar>>
	 */
	private function clean_args( array $args ): array {
		$clean = [];

		foreach ( $args as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_values(
					array_map(
						static fn( $item ) => sanitize_text_field( (string) $item ),
						array_filter( $value, 'is_scalar' )
					)
				);
				continue;
			}

			if ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $clean;
	}
}
