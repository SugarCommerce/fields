<?php
/**
 * Action REST Controller
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Rest;

use SugarCommerce\Fields\Actions\Actions;
use SugarCommerce\Fields\Actions\Payload;
use SugarCommerce\Fields\Contracts\RichPayload;
use SugarCommerce\Fields\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The one endpoint every interactive button talks to.
 *
 * Activating a licence, sending a test email, previewing one and whatever a
 * consumer wires up are all the same shape: send a payload, run a named
 * handler, get back success and a message. The predecessor libraries grew a
 * route per feature — setting-fields alone had /license, /email/preview,
 * /email/send-test and /action — each with its own permission check, which
 * is four places for one of them to be wrong.
 *
 * The namespace is derived rather than written for the same reason the
 * search endpoint's is: WP_REST_Server::register_route() array_merges
 * same-path registrations, so two plugins bundling a prefixed copy under one
 * namespace would leave the first to register answering the other's
 * requests, under its own capability check.
 */
final class ActionController {

	/**
	 * The available actions.
	 *
	 * @var Actions
	 */
	private Actions $actions;

	/**
	 * Namespaces already claimed, so a second instance cannot double-register.
	 *
	 * @var array<string, bool>
	 */
	private static array $claimed = [];

	/**
	 * Construct.
	 *
	 * @param Actions|null $actions The available actions.
	 */
	public function __construct( ?Actions $actions = null ) {
		$this->actions = $actions ?? Actions::shared();
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
			'/action',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'run' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'action'  => [
						'description'       => __( 'Name of the action to run.', 'sugarcommerce' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					],
					'payload' => [
						'description' => __( 'Data the field is sending.', 'sugarcommerce' ),
						'type'        => 'object',
						'default'     => [],
					],
				],
			]
		);
	}

	/**
	 * Whether the request may run the named action.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission( WP_REST_Request $request ): bool|WP_Error {
		// Before the lookup, for the same reason as the search route: an
		// anonymous request must not learn which actions exist.
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'sugarcommerce_fields_forbidden',
				__( 'You are not allowed to do that.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$action = $this->actions->get( (string) $request->get_param( 'action' ) );

		if ( null === $action ) {
			return new WP_Error(
				'sugarcommerce_fields_unknown_action',
				__( 'Unknown action.', 'sugarcommerce' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( $action->capability() ) ) {
			return new WP_Error(
				'sugarcommerce_fields_forbidden',
				__( 'You are not allowed to do that.', 'sugarcommerce' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Run the action.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public function run( WP_REST_Request $request ): WP_REST_Response {
		$action = $this->actions->get( (string) $request->get_param( 'action' ) );
		// Plain text, except the inputs the action says are markup: an
		// email's body goes to its preview as it was written.
		$rich   = $action instanceof RichPayload ? $action->rich() : [];
		$result = $action->handle( Payload::clean( (array) $request->get_param( 'payload' ), $rich ) );

		return new WP_REST_Response(
			[
				'success' => (bool) ( $result['success'] ?? false ),
				'message' => (string) ( $result['message'] ?? '' ),
				'data'    => (array) ( $result['data'] ?? [] ),
			],
			// A failed action is still a handled request. Returning 4xx would
			// make the script treat a licence server saying "no" the same as
			// the endpoint being unreachable, which are different problems
			// and want different messages.
			200
		);
	}
}
