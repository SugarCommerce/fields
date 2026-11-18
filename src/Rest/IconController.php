<?php
/**
 * Icon REST Controller
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Rest;

use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Fields\Icons\Svg;
use SugarCommerce\Fields\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * One page of one icon set, searched.
 *
 * The picker never has a whole set on the page. A theme's set may be a few
 * thousand icons, and an admin screen that printed every one into every icon
 * field would be paying for a picker nobody opened. So it asks for a page at
 * a time -- the icons, already cleaned, as inline SVG -- and only when it is
 * opened, scrolled or searched.
 *
 * A page is the same until the set changes, so it says so: an ETag that
 * answers a repeat with a 304 and no body, and a `private` cache lifetime
 * so the browser does not ask again at all. The picker puts the set's
 * version in the URL, so a set that changes is a different URL and nothing
 * stale is served from the cache.
 *
 * @since 1.5.0
 */
final class IconController {

	/**
	 * Icons per page when the request does not say.
	 */
	public const PER_PAGE = 96;

	/**
	 * The most icons a page may ask for.
	 */
	public const MAX_PER_PAGE = 200;

	/**
	 * How long a browser may keep a page without asking, in seconds.
	 */
	private const MAX_AGE = 86400;

	/**
	 * Changes when what a page of results looks like changes, so a page
	 * cached under the old shape is not served to a script expecting the
	 * new one.
	 */
	private const SHAPE = '1';

	/**
	 * Paths already claimed, so a second instance cannot double-register.
	 *
	 * @var array<string, bool>
	 */
	private static array $claimed = [];

	/**
	 * Whether the response being served may be cached.
	 *
	 * @var bool
	 */
	private static bool $cacheable = false;

	/**
	 * Hook the route registration.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function register_routes(): void {
		$namespace = Runtime::rest_namespace();

		if ( isset( self::$claimed[ $namespace ] ) ) {
			return;
		}

		self::$claimed[ $namespace ] = true;

		register_rest_route(
			$namespace,
			'/icons',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'icons' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->args(),
			]
		);

		// Core sends no-cache headers to anyone logged in, after the
		// response's own, and they would replace the ones this sends. This
		// route's answers are the same for everybody allowed to ask, so it
		// alone is let off.
		add_filter( 'rest_send_nocache_headers', [ self::class, 'allow_caching' ], 20 );
	}

	/**
	 * Endpoint arguments.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function args(): array {
		return [
			'set'      => [
				'description'       => __( 'The icon set.', 'sugarcommerce' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			],
			'search'   => [
				'description'       => __( 'Words to look for in the icons’ names.', 'sugarcommerce' ),
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'page'     => [
				'description' => __( 'Page of results, one-based.', 'sugarcommerce' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			],
			'per_page' => [
				'description' => __( 'Icons per page.', 'sugarcommerce' ),
				'type'        => 'integer',
				'default'     => self::PER_PAGE,
				'minimum'     => 1,
				'maximum'     => self::MAX_PER_PAGE,
			],
		];
	}

	/**
	 * Whether the request may browse the named set.
	 *
	 * Logged in before anything else, so an anonymous request cannot learn
	 * which sets exist from the difference between a 404 and a 401. The
	 * capability is the set's own, `edit_posts` unless it said otherwise:
	 * icons are artwork somebody published, and the check is there to keep
	 * the endpoint from being an open mirror, not to guard a secret.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 * @since 1.5.0
	 */
	public function check_permission( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'sugarcommerce_fields_forbidden',
				__( 'You are not allowed to browse icons.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$set = Icons::get( (string) $request->get_param( 'set' ) );

		if ( null === $set ) {
			return new WP_Error(
				'sugarcommerce_fields_unknown_icon_set',
				__( 'Unknown icon set.', 'sugarcommerce' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( $set->capability() ) ) {
			return new WP_Error(
				'sugarcommerce_fields_forbidden',
				__( 'You are not allowed to browse icons.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * A page of a set's icons.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 * @since 1.5.0
	 */
	public function icons( WP_REST_Request $request ): WP_REST_Response {
		$set      = Icons::get( (string) $request->get_param( 'set' ) );
		$search   = trim( (string) $request->get_param( 'search' ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) $request->get_param( 'per_page' ) ) );

		// What the answer depends on, and so what its tag is made of. The
		// kit's build is in it because the shape of an answer can change
		// with the kit while the set stays the same.
		$etag = '"' . md5( implode( '|', [ Runtime::prefix(), $set->id(), $set->version(), strtolower( $search ), $page, $per_page, self::SHAPE ] ) ) . '"';

		self::$cacheable = true;

		$headers = [
			'ETag'          => $etag,
			'Cache-Control' => 'private, max-age=' . self::MAX_AGE,
			'Vary'          => 'Cookie',
		];

		if ( $this->matches( $request, $etag ) ) {
			$response = new WP_REST_Response( null, 304 );
			$response->set_headers( $headers );

			return $response;
		}

		$names = $set->search( $search );
		$total = count( $names );
		$slice = array_slice( $names, ( $page - 1 ) * $per_page, $per_page );
		$icons = [];

		foreach ( $slice as $name ) {
			$markup = $set->svg( $name );

			$icons[] = [
				'value' => $set->id() . ':' . $name,
				'name'  => $name,
				'label' => $set->label_for( $name ),
				'svg'   => '' !== $markup ? Svg::render( $markup ) : $set->font( $name ),
			];
		}

		$response = new WP_REST_Response(
			[
				'set'   => $set->describe(),
				'total' => $total,
				'page'  => $page,
				'pages' => (int) ceil( $total / $per_page ),
				'icons' => $icons,
			]
		);

		$response->set_headers( $headers );

		return $response;
	}

	/**
	 * Keep core's no-cache headers off this route's answers.
	 *
	 * @param bool $send Whether core would send them.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function allow_caching( bool $send ): bool {
		return self::$cacheable ? false : $send;
	}

	/**
	 * Whether the browser already has this answer.
	 *
	 * @param WP_REST_Request $request The request.
	 * @param string          $etag    This answer's tag.
	 *
	 * @return bool
	 */
	private function matches( WP_REST_Request $request, string $etag ): bool {
		$sent = (string) $request->get_header( 'if_none_match' );

		if ( '' === $sent ) {
			return false;
		}

		foreach ( explode( ',', $sent ) as $candidate ) {
			// A proxy may have weakened it; the content is the same.
			if ( trim( (string) preg_replace( '/^W\//', '', trim( $candidate ) ) ) === $etag ) {
				return true;
			}
		}

		return false;
	}
}
