<?php
/**
 * Form REST Controller
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Rest;

use SugarCommerce\Fields\Form;
use SugarCommerce\Fields\Utils\Runtime;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Where a form registered with `'rest' => true` is sent without leaving
 * the page: `POST /forms/{id}`.
 *
 * Open to anyone at the route, because a public form is; the form itself
 * checks its nonce and its `capability` exactly as it does for a post, so
 * the two ways in are the same door. The answer is the outcome as data --
 * whether it was accepted, the message, a refusal per field with the id of
 * the control it belongs to -- and is a 422 when it was not accepted.
 */
final class FormController {

	/**
	 * Namespaces whose route is registered.
	 *
	 * @var array<string, bool>
	 */
	private static array $claimed = [];

	/**
	 * Hook the route in.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the route.
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
			'/forms/(?P<id>[a-z0-9_-]+)',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle a submission.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response {
		$form = Form::get( (string) $request->get_param( 'id' ) );

		if ( null === $form ) {
			return new WP_REST_Response(
				[
					'ok'      => false,
					'message' => __( 'That form does not exist.', 'sugarcommerce' ),
				],
				404
			);
		}

		// The body as it was posted, slashed the way $_POST is, so the field
		// set unslashes it once exactly as it does a post.
		$answer = $form->respond( wp_slash( (array) $request->get_body_params() ) );

		return new WP_REST_Response( $answer, $answer['ok'] ? 200 : 422 );
	}
}
