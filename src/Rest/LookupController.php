<?php
/**
 * Lookup REST Controller
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Rest;

use SugarCommerce\Fields\Support\Lookups;
use SugarCommerce\Fields\Types\AddressType;
use SugarCommerce\Fields\Types\EmbedType;
use SugarCommerce\Fields\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * What a control asks the server while somebody is using it.
 *
 * Three routes, under this build's namespace:
 *
 * - `GET /lookup?name=&q=` -- a field's own handler, registered by its field
 *   set: is this slug free, where is this address. Each handler names the
 *   capability asking needs; an empty one is open to anyone, which a public
 *   form's handler has to be.
 * - `GET /address/{country}` -- how an address is written in a country: the
 *   lines, the labels, the required parts, the regions and the postcode
 *   pattern. Public, like the data it is made from.
 * - `GET /embed?url=` -- the player a pasted link becomes, built from the
 *   link's id and never from the link itself. Public: it makes no request
 *   and frames only the platforms it knows.
 */
final class LookupController {

	/**
	 * Namespaces whose routes are registered, so two copies of the library
	 * under one prefix register them once.
	 *
	 * @var array<string, bool>
	 */
	private static array $claimed = [];

	/**
	 * Hook the routes in.
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
			'/lookup',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'lookup' ],
				'permission_callback' => [ $this, 'check_lookup' ],
				'args'                => [
					'name' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'q'    => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/address/(?P<country>[A-Za-z]{2})',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'address' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			$namespace,
			'/embed',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'embed' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'url'       => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'esc_url_raw',
					],
					'platforms' => [
						'type'    => 'array',
						'default' => [],
						'items'   => [ 'type' => 'string' ],
					],
					'title'     => [
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Whether the person asking may ask this handler.
	 *
	 * An unknown name answers as a refusal would for anybody without a
	 * login, so the names registered cannot be listed by asking.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return bool|WP_Error
	 */
	public function check_lookup( WP_REST_Request $request ): bool|WP_Error {
		$capability = Lookups::shared()->capability( (string) $request->get_param( 'name' ) );

		if ( '' === $capability ) {
			return true;
		}

		if ( null === $capability ) {
			return is_user_logged_in()
				? new WP_Error( 'sugarcommerce_fields_unknown_lookup', __( 'Unknown lookup.', 'sugarcommerce' ), [ 'status' => 404 ] )
				: $this->forbidden();
		}

		return is_user_logged_in() && current_user_can( $capability ) ? true : $this->forbidden();
	}

	/**
	 * Ask a field's handler.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$handler = Lookups::shared()->handler( (string) $request->get_param( 'name' ) );

		if ( null === $handler ) {
			return new WP_Error( 'sugarcommerce_fields_unknown_lookup', __( 'Unknown lookup.', 'sugarcommerce' ), [ 'status' => 404 ] );
		}

		$answer = $handler( (string) $request->get_param( 'q' ) );

		if ( is_wp_error( $answer ) ) {
			$data = (array) $answer->get_error_data();

			$answer->add_data( [ 'status' => (int) ( $data['status'] ?? 422 ) ] + $data );

			return $answer;
		}

		return new WP_REST_Response( is_array( $answer ) ? $answer : [ 'result' => $answer ] );
	}

	/**
	 * How an address is written in a country.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function address( WP_REST_Request $request ): WP_REST_Response {
		$response = new WP_REST_Response( AddressType::layout( (string) $request->get_param( 'country' ) ) );

		// The same answer for everybody, and it changes with a release.
		$response->header( 'Cache-Control', 'public, max-age=86400' );

		return $response;
	}

	/**
	 * The player a link becomes.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function embed( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$preview = EmbedType::preview(
			(string) $request->get_param( 'url' ),
			array_map( 'strval', (array) $request->get_param( 'platforms' ) ),
			(string) $request->get_param( 'title' )
		);

		if ( null === $preview ) {
			return new WP_Error(
				'sugarcommerce_fields_not_embeddable',
				EmbedType::refusal( array_map( 'strval', (array) $request->get_param( 'platforms' ) ) ),
				[ 'status' => 422 ]
			);
		}

		return new WP_REST_Response( $preview );
	}

	/**
	 * The refusal for somebody who may not ask.
	 *
	 * @return WP_Error
	 */
	private function forbidden(): WP_Error {
		return new WP_Error(
			'sugarcommerce_fields_forbidden',
			__( 'You are not allowed to ask this.', 'sugarcommerce' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}
}
