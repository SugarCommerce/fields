<?php
/**
 * Icon endpoint tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Fields\Rest\IconController;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

/**
 * The picker asks for one page of one set at a time, searched, and a page
 * it has had before comes back as a 304 with no body.
 */
final class IconControllerTest extends TestCase {

	/**
	 * Reset the stubs' state.
	 */
	protected function tearDown(): void {
		Icons::reset();
		unset( $GLOBALS['fk_logged_in'], $GLOBALS['fk_user_can'] );
	}

	/**
	 * A request as the server would hand it over, defaults applied.
	 *
	 * @param array<string, mixed>  $params  Parameters.
	 * @param array<string, string> $headers Headers.
	 *
	 * @return WP_REST_Request
	 */
	private function request( array $params, array $headers = [] ): WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/sugarcommerce-fields/v1/icons' );
		$params  = array_merge(
			[
				'search'   => '',
				'page'     => 1,
				'per_page' => IconController::PER_PAGE,
			],
			$params
		);

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		foreach ( $headers as $key => $value ) {
			$request->set_header( $key, $value );
		}

		return $request;
	}

	/**
	 * The route is registered under this build's namespace.
	 */
	public function test_the_route_is_registered(): void {
		$GLOBALS['fk_routes'] = [];

		( new IconController() )->register_routes();

		// Once per namespace; a second controller does not double it.
		( new IconController() )->register_routes();

		$this->assertSame( [ 'sugarcommerce-fields/v1/icons' ], array_values( array_filter( $GLOBALS['fk_routes'], static fn( $route ) => str_ends_with( $route, '/icons' ) ) ) );
	}

	/**
	 * Logged out is refused before the set is looked up, so which sets exist
	 * cannot be learned from a 404.
	 */
	public function test_logged_out_is_refused_first(): void {
		$GLOBALS['fk_logged_in'] = false;

		$controller = new IconController();

		foreach ( [ 'heroicons-outline', 'no-such-set' ] as $set ) {
			$result = $controller->check_permission( $this->request( [ 'set' => $set ] ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 401, $result->get_error_data()['status'] );
		}
	}

	/**
	 * Logged in: an unknown set is a 404, and the set's capability is asked.
	 */
	public function test_permission_is_the_sets_capability(): void {
		$controller = new IconController();

		$this->assertTrue( $controller->check_permission( $this->request( [ 'set' => 'heroicons-outline' ] ) ) );

		$unknown = $controller->check_permission( $this->request( [ 'set' => 'no-such-set' ] ) );
		$this->assertSame( 404, $unknown->get_error_data()['status'] );

		$GLOBALS['fk_user_can'] = [ 'edit_posts' => false ];
		$this->assertInstanceOf( WP_Error::class, $controller->check_permission( $this->request( [ 'set' => 'heroicons-outline' ] ) ) );

		// A set that asks for something else is checked against that.
		Icons::register(
			'private',
			[
				'source'     => static fn() => [ 'x' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0h1v1z"/></svg>' ],
				'capability' => 'manage_options',
			]
		);

		$GLOBALS['fk_user_can'] = [ 'manage_options' => false ];
		$this->assertInstanceOf( WP_Error::class, $controller->check_permission( $this->request( [ 'set' => 'private' ] ) ) );

		$GLOBALS['fk_user_can'] = [ 'manage_options' => true ];
		$this->assertTrue( $controller->check_permission( $this->request( [ 'set' => 'private' ] ) ) );
	}

	/**
	 * A page: the icons as values, labels and clean inline SVG, and how many
	 * there are in all.
	 */
	public function test_a_page_of_icons(): void {
		$response = ( new IconController() )->icons( $this->request( [ 'set' => 'heroicons-outline', 'per_page' => 100 ] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 324, $data['total'] );
		$this->assertSame( 4, $data['pages'] );
		$this->assertCount( 100, $data['icons'] );
		$this->assertSame( 'heroicons-outline', $data['set']['id'] );

		$first = $data['icons'][0];

		$this->assertSame( [ 'value', 'name', 'label', 'svg' ], array_keys( $first ) );
		$this->assertSame( 'heroicons-outline:' . $first['name'], $first['value'] );
		$this->assertStringStartsWith( '<svg aria-hidden="true" focusable="false"', $first['svg'] );

		// The last page has what is left.
		$last = ( new IconController() )->icons( $this->request( [ 'set' => 'heroicons-outline', 'per_page' => 100, 'page' => 4 ] ) )->get_data();
		$this->assertCount( 24, $last['icons'] );

		// Past the end is an empty page, not an error.
		$past = ( new IconController() )->icons( $this->request( [ 'set' => 'heroicons-outline', 'per_page' => 100, 'page' => 9 ] ) )->get_data();
		$this->assertSame( [], $past['icons'] );

		// A page larger than the most allowed is the most allowed.
		$huge = ( new IconController() )->icons( $this->request( [ 'set' => 'heroicons-outline', 'per_page' => 5000 ] ) )->get_data();
		$this->assertCount( IconController::MAX_PER_PAGE, $huge['icons'] );
	}

	/**
	 * A search narrows the set, best match first.
	 */
	public function test_search(): void {
		$data = ( new IconController() )->icons( $this->request( [ 'set' => 'wordpress', 'search' => 'cart' ] ) )->get_data();

		$this->assertGreaterThanOrEqual( 1, $data['total'] );
		$this->assertSame( 'wordpress:cart', $data['icons'][0]['value'] );

		$none = ( new IconController() )->icons( $this->request( [ 'set' => 'wordpress', 'search' => 'zzzz' ] ) )->get_data();

		$this->assertSame( 0, $none['total'] );
		$this->assertSame( 0, $none['pages'] );
	}

	/**
	 * Every icon in a page from a hostile set is clean.
	 */
	public function test_a_hostile_set_is_served_clean(): void {
		Icons::register( 'theme', [ 'source' => __DIR__ . '/fixtures/icons/theme' ] );

		$data = ( new IconController() )->icons( $this->request( [ 'set' => 'theme' ] ) )->get_data();
		$json = (string) wp_json_encode( $data );

		$this->assertStringNotContainsStringIgnoringCase( 'script', $json );
		$this->assertStringNotContainsStringIgnoringCase( 'onload', $json );
		$this->assertStringNotContainsStringIgnoringCase( 'javascript', $json );
	}

	/**
	 * A page has a tag, and the browser is let keep it.
	 */
	public function test_a_page_is_cacheable(): void {
		$response = ( new IconController() )->icons( $this->request( [ 'set' => 'heroicons-solid' ] ) );
		$headers  = $response->get_headers();

		$this->assertMatchesRegularExpression( '/^"[a-f0-9]{32}"$/', $headers['ETag'] );
		$this->assertSame( 'private, max-age=86400', $headers['Cache-Control'] );

		// Core sends no-cache headers to anyone logged in, after these and
		// over them; this route lets itself off.
		$this->assertFalse( IconController::allow_caching( true ) );
	}

	/**
	 * The tag changes with what the page holds, and only with that.
	 */
	public function test_the_tag_follows_the_answer(): void {
		$controller = new IconController();
		$tag        = static fn( array $params ) => $controller->icons(
			( new self( 'tag' ) )->request( $params )
		)->get_headers()['ETag'];

		$base = $tag( [ 'set' => 'heroicons-solid' ] );

		$this->assertSame( $base, $tag( [ 'set' => 'heroicons-solid' ] ) );
		$this->assertNotSame( $base, $tag( [ 'set' => 'heroicons-outline' ] ) );
		$this->assertNotSame( $base, $tag( [ 'set' => 'heroicons-solid', 'search' => 'cart' ] ) );
		$this->assertNotSame( $base, $tag( [ 'set' => 'heroicons-solid', 'page' => 2 ] ) );
		$this->assertNotSame( $base, $tag( [ 'set' => 'heroicons-solid', 'per_page' => 10 ] ) );

		// A set whose icons change has a new tag.
		Icons::register( 'heroicons-solid', [ 'source' => __DIR__ . '/fixtures/icons/theme', 'version' => 'changed' ] );
		$this->assertNotSame( $base, $tag( [ 'set' => 'heroicons-solid' ] ) );
	}

	/**
	 * A repeat is a 304 with no body.
	 */
	public function test_a_repeat_is_not_modified(): void {
		$controller = new IconController();
		$etag       = $controller->icons( $this->request( [ 'set' => 'wordpress' ] ) )->get_headers()['ETag'];

		$again = $controller->icons( $this->request( [ 'set' => 'wordpress' ], [ 'If-None-Match' => $etag ] ) );

		$this->assertSame( 304, $again->get_status() );
		$this->assertNull( $again->get_data() );
		$this->assertSame( $etag, $again->get_headers()['ETag'] );

		// A list, and a weakened tag from a proxy, both count.
		$this->assertSame( 304, $controller->icons( $this->request( [ 'set' => 'wordpress' ], [ 'If-None-Match' => '"other", W/' . $etag ] ) )->get_status() );

		// A different tag is a full answer.
		$this->assertSame( 200, $controller->icons( $this->request( [ 'set' => 'wordpress' ], [ 'If-None-Match' => '"stale"' ] ) )->get_status() );
	}
}
