<?php
/**
 * Adding a post, term or user from a picker.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Fields\Rest\SearchController;
use SugarCommerce\Fields\Search\CallbackSource;
use SugarCommerce\Fields\Search\Sources;
use SugarCommerce\Fields\Utils\Runtime;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

require_once __DIR__ . '/stubs/Create.php';

/**
 * A picker that found nothing can make the thing it was looking for.
 *
 * What these hold down, in order of what it would cost to get wrong:
 *
 * - Nothing is made by someone who could not have made it on WordPress's own
 *   screens. A taxonomy's `edit_terms`, a post type's `create_posts`, and
 *   `create_users` with a role the person may hand out. Each refusal is
 *   checked to have written nothing.
 * - What comes back is an id, and the field stores it as one: the schema
 *   stays integers, unlike `creatable`, which stores the typed text.
 * - Something that already exists is chosen rather than made twice.
 * - The control is only told it may add when the field asked.
 */
final class CreateTest extends TestCase {

	/**
	 * A site with a collection post type, a collection taxonomy and a
	 * hidden taxonomy, and everything allowed until a test says otherwise.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['fk_post_types'] = [
			'collection' => self::type( 'collection', 'Collection', [ 'create_posts' => 'create_collections', 'publish_posts' => 'publish_collections', 'edit_posts' => 'edit_collections' ] ),
			'attachment' => self::type( 'attachment', 'Media', [ 'create_posts' => 'upload_files', 'publish_posts' => 'upload_files', 'edit_posts' => 'edit_posts' ] ),
			'page'       => self::type( 'page', 'Page', [ 'create_posts' => 'edit_pages', 'publish_posts' => 'publish_pages', 'edit_posts' => 'edit_pages' ] ),
			'post'       => self::type( 'post', 'Post', [ 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts', 'edit_posts' => 'edit_posts' ] ),
		];

		$GLOBALS['fk_taxonomies'] = [
			'pack_collection' => self::taxonomy( 'pack_collection', 'Collection', true ),
			'faq_group'       => self::taxonomy( 'faq_group', 'FAQ Group', true ),
			'internal'        => self::taxonomy( 'internal', 'Internal', false ),
			'category'        => self::taxonomy( 'category', 'Category', true ),
		];

		$GLOBALS['fk_terms']    = [];
		$GLOBALS['fk_posts']    = [];
		$GLOBALS['fk_users']    = [];
		$GLOBALS['fk_inserted'] = [];
	}

	/**
	 * Put the globals back for the suites that run after this one.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['fk_post_types'],
			$GLOBALS['fk_taxonomies'],
			$GLOBALS['fk_terms'],
			$GLOBALS['fk_posts'],
			$GLOBALS['fk_users'],
			$GLOBALS['fk_inserted'],
			$GLOBALS['fk_user_can'],
			$GLOBALS['fk_logged_in'],
			$GLOBALS['fk_editable_roles'],
			$GLOBALS['fk_term_exists_blind'],
			$GLOBALS['fk_attachments']
		);
	}

	/**
	 * A post type as get_post_type_object() returns one.
	 *
	 * @param string                $name     Its name.
	 * @param string                $singular Its singular label.
	 * @param array<string, string> $caps     Its capability map.
	 *
	 * @return object
	 */
	private static function type( string $name, string $singular, array $caps ): object {
		return (object) [
			'name'    => $name,
			'show_ui' => true,
			'labels'  => (object) [ 'singular_name' => $singular ],
			'cap'     => (object) $caps,
		];
	}

	/**
	 * A taxonomy as get_taxonomy() returns one.
	 *
	 * @param string $name     Its name.
	 * @param string $singular Its singular label.
	 * @param bool   $show_ui  Whether it is in the admin.
	 *
	 * @return object
	 */
	private static function taxonomy( string $name, string $singular, bool $show_ui ): object {
		return (object) [
			'name'    => $name,
			'show_ui' => $show_ui,
			'labels'  => (object) [ 'singular_name' => $singular ],
			'cap'     => (object) [
				'edit_terms'   => 'edit_' . $name,
				'assign_terms' => 'assign_' . $name,
			],
		];
	}

	/**
	 * Deny the current user some capabilities.
	 *
	 * @param string ...$caps The capabilities.
	 *
	 * @return void
	 */
	private function deny( string ...$caps ): void {
		foreach ( $caps as $cap ) {
			$GLOBALS['fk_user_can'][ $cap ] = false;
		}
	}

	/**
	 * Send a create request the way the server would: the permission check
	 * first, and the callback only if it passes.
	 *
	 * @param string               $source  Source name.
	 * @param string               $text    What was typed.
	 * @param array<string, mixed> $args    Arguments.
	 * @param Sources|null         $sources The registry.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	private function create( string $source, string $text, array $args = [], ?Sources $sources = null ): WP_REST_Response|WP_Error {
		$controller = new SearchController( $sources ?? new Sources() );
		$request    = new WP_REST_Request( 'POST', '/' . Runtime::rest_namespace() . '/search/create' );

		$request->set_param( 'source', $source );
		$request->set_param( 'text', $text );
		$request->set_param( 'args', $args );

		$allowed = $controller->check_create_permission( $request );

		if ( true !== $allowed ) {
			return $allowed;
		}

		return $controller->create( $request );
	}

	/**
	 * Assert a request was refused and nothing was written.
	 *
	 * @param WP_REST_Response|WP_Error $result What came back.
	 * @param string                    $why    What the refusal is for.
	 *
	 * @return void
	 */
	private function assertRefused( WP_REST_Response|WP_Error $result, string $why ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $why );
		$this->assertSame( [], $GLOBALS['fk_inserted'], $why . ' -- and yet something was written.' );
	}

	/**
	 * Render one field.
	 *
	 * @param string               $type   Field type.
	 * @param array<string, mixed> $config Configuration.
	 *
	 * @return string
	 */
	private function render( string $type, array $config = [] ): string {
		$field = new Field(
			'demo',
			( new Registry() )->get( $type ),
			array_merge( [ 'label' => 'Demo', 'input_name' => 'demo' ], $config )
		);

		return ( new Renderer() )->render( $field );
	}

	/**
	 * The route is registered beside the search, under the build's namespace.
	 */
	public function test_the_route_is_registered_beside_the_search(): void {
		$GLOBALS['fk_routes'] = [];

		// A namespace already claimed by an earlier test registers nothing,
		// so the claim list is cleared first.
		$claimed = new \ReflectionProperty( SearchController::class, 'claimed' );
		$claimed->setValue( null, [] );

		( new SearchController( new Sources() ) )->register_routes();

		$this->assertContains( Runtime::rest_namespace() . '/search', $GLOBALS['fk_routes'] );
		$this->assertContains( Runtime::rest_namespace() . '/search/create', $GLOBALS['fk_routes'] );
	}

	/**
	 * A term is made in the taxonomy asked for, and its id comes back.
	 */
	public function test_a_term_is_added(): void {
		$result = $this->create( 'term', 'Sample Packs', [ 'taxonomy' => 'pack_collection' ] );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 'Sample Packs', $result->get_data()['text'] );
		$this->assertFalse( $result->get_data()['existing'] );
		$this->assertMatchesRegularExpression( '/^\d+$/', $result->get_data()['id'] );
		$this->assertSame( [ [ 'term', 'Sample Packs', 'pack_collection' ] ], $GLOBALS['fk_inserted'] );
	}

	/**
	 * Without the taxonomy's own edit_terms, nothing is made.
	 *
	 * Not assign_terms, which core accepts for a tag: a picker is not the
	 * tag box, and the stricter rule is the documented one.
	 */
	public function test_a_term_needs_the_taxonomys_edit_terms(): void {
		$this->deny( 'edit_pack_collection' );

		$this->assertRefused(
			$this->create( 'term', 'Sample Packs', [ 'taxonomy' => 'pack_collection' ] ),
			'A term was made without edit_terms.'
		);
	}

	/**
	 * A taxonomy that is not in the admin cannot be added to, and there is no
	 * falling back to categories.
	 */
	public function test_a_hidden_or_unknown_taxonomy_is_refused(): void {
		$this->assertRefused( $this->create( 'term', 'Sample Packs', [ 'taxonomy' => 'internal' ] ), 'A hidden taxonomy was added to.' );
		$this->assertRefused( $this->create( 'term', 'Sample Packs', [ 'taxonomy' => 'nope' ] ), 'An unknown taxonomy was added to.' );
	}

	/**
	 * A term that already exists is chosen, not refused and not doubled.
	 */
	public function test_an_existing_term_is_chosen(): void {
		$GLOBALS['fk_terms'][42] = fk_test_term( 42, 'Sample Packs', 'pack_collection' );

		$result = $this->create( 'term', 'sample packs', [ 'taxonomy' => 'pack_collection' ] );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame(
			[
				'id'       => '42',
				'text'     => 'Sample Packs',
				'existing' => true,
			],
			$result->get_data()
		);
		$this->assertSame( [], $GLOBALS['fk_inserted'] );
	}

	/**
	 * The same term added by another request between the check and the
	 * insert is chosen too, from the id core reports.
	 */
	public function test_a_term_added_meanwhile_is_chosen(): void {
		$GLOBALS['fk_terms'][43]            = fk_test_term( 43, 'Sample Packs', 'pack_collection' );
		$GLOBALS['fk_term_exists_blind'] = true;

		$result = $this->create( 'term', 'Sample Packs', [ 'taxonomy' => 'pack_collection' ] );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( '43', $result->get_data()['id'] );
		$this->assertTrue( $result->get_data()['existing'] );
	}

	/**
	 * A post is made with the type's create_posts, published when the
	 * person may publish.
	 */
	public function test_a_post_is_published_by_someone_who_may_publish(): void {
		$result = $this->create( 'post', 'Lo-fi Drums', [ 'post_type' => 'collection' ] );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );
		$this->assertSame( 'Lo-fi Drums', $result->get_data()['text'] );
		$this->assertSame( 'publish', $GLOBALS['fk_inserted'][0][1]['post_status'] );
		$this->assertSame( 'collection', $GLOBALS['fk_inserted'][0][1]['post_type'] );
	}

	/**
	 * Someone who may make one but not publish it gets a draft, which the
	 * search still lists for them.
	 */
	public function test_a_post_is_a_draft_for_someone_who_may_not_publish(): void {
		$this->deny( 'publish_collections' );

		$this->create( 'post', 'Lo-fi Drums', [ 'post_type' => 'collection' ] );

		$this->assertSame( 'draft', $GLOBALS['fk_inserted'][0][1]['post_status'] );

		// Asking for private does not get round it.
		$GLOBALS['fk_inserted'] = [];
		$this->create( 'post', 'Lo-fi Keys', [ 'post_type' => 'collection', 'status' => 'private' ] );

		$this->assertSame( 'draft', $GLOBALS['fk_inserted'][0][1]['post_status'] );
	}

	/**
	 * A field may ask for less than publishing; nonsense is ignored.
	 */
	public function test_a_post_status_can_be_asked_for(): void {
		$this->create( 'post', 'One', [ 'post_type' => 'collection', 'status' => 'pending' ] );
		$this->create( 'post', 'Two', [ 'post_type' => 'collection', 'status' => 'trash' ] );

		$this->assertSame( 'pending', $GLOBALS['fk_inserted'][0][1]['post_status'] );
		$this->assertSame( 'publish', $GLOBALS['fk_inserted'][1][1]['post_status'] );
	}

	/**
	 * Without the post type's create_posts, nothing is made.
	 */
	public function test_a_post_needs_the_types_create_posts(): void {
		$this->deny( 'create_collections' );

		$this->assertRefused(
			$this->create( 'post', 'Lo-fi Drums', [ 'post_type' => 'collection' ] ),
			'A post was made without create_posts.'
		);
	}

	/**
	 * An attachment is not a title, and a type not in the admin is not on
	 * offer.
	 */
	public function test_attachments_and_unknown_types_are_refused(): void {
		$this->assertRefused( $this->create( 'post', 'A file', [ 'post_type' => 'attachment' ] ), 'An attachment was made from a title.' );
		$this->assertRefused( $this->create( 'post', 'A thing', [ 'post_type' => 'nope' ] ), 'An unknown post type was added to.' );
		$this->assertRefused( $this->create( 'post', 'Two at once', [ 'post_type' => [ 'collection', 'page' ] ] ), 'A post was made in a list of types.' );
	}

	/**
	 * A post of that title already there is chosen rather than doubled.
	 */
	public function test_an_existing_post_title_is_chosen(): void {
		$GLOBALS['fk_posts'][ 9 ]       = [
			'post_type'  => 'collection',
			'post_title' => 'Lo-fi Drums',
		];
		$GLOBALS['fk_attachments'][ 9 ] = [ 'name' => 'Lo-fi Drums' ];

		$result = $this->create( 'post', 'lo-fi drums', [ 'post_type' => 'collection' ] );

		$this->assertSame( '9', $result->get_data()['id'] );
		$this->assertTrue( $result->get_data()['existing'] );
		$this->assertSame( [], $GLOBALS['fk_inserted'] );
	}

	/**
	 * A user is added by address, with a login made from it and the site's
	 * default role.
	 */
	public function test_a_user_is_added_by_email(): void {
		$GLOBALS['fk_users'][3] = [
			'user_login' => 'jane',
			'user_email' => 'jane@elsewhere.test',
		];

		$result = $this->create( 'user', 'Jane@Example.test' );

		$this->assertInstanceOf( WP_REST_Response::class, $result );
		$this->assertSame( 201, $result->get_status() );

		$made = $GLOBALS['fk_inserted'][0][1];

		$this->assertSame( 'jane@example.test', strtolower( $made['user_email'] ) );
		$this->assertSame( 'jane2', $made['user_login'], 'The login was not numbered past the one taken.' );
		$this->assertSame( 'subscriber', $made['role'] );
		$this->assertNotSame( '', $made['user_pass'] );
	}

	/**
	 * Without create_users, nothing is made, whatever the role.
	 */
	public function test_a_user_needs_create_users(): void {
		$this->deny( 'create_users' );

		$this->assertRefused( $this->create( 'user', 'jane@example.test' ), 'A user was made without create_users.' );
	}

	/**
	 * A role the person may not hand out is refused, not swapped.
	 */
	public function test_a_user_role_must_be_one_the_person_may_give(): void {
		$this->assertRefused(
			$this->create( 'user', 'jane@example.test', [ 'role' => 'administrator' ] ),
			'A user was made with a role the person may not give.'
		);

		$this->create( 'user', 'jane@example.test', [ 'role' => 'editor' ] );

		$this->assertSame( 'editor', $GLOBALS['fk_inserted'][0][1]['role'] );
	}

	/**
	 * What is typed has to be an address, and the refusal says so.
	 */
	public function test_a_user_needs_an_email_address(): void {
		$result = $this->create( 'user', 'Jane Doe' );

		$this->assertRefused( $result, 'A user was made from something that is not an address.' );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertStringContainsString( 'not an email address', $result->get_error_message() );
	}

	/**
	 * An address already registered chooses that account.
	 */
	public function test_an_existing_email_is_chosen(): void {
		$GLOBALS['fk_users'][12] = [
			'user_login' => 'jane',
			'user_email' => 'jane@example.test',
		];

		$result = $this->create( 'user', 'jane@example.test' );

		$this->assertSame( '12', $result->get_data()['id'] );
		$this->assertTrue( $result->get_data()['existing'] );
		$this->assertSame( [], $GLOBALS['fk_inserted'] );
	}

	/**
	 * A source that cannot create is refused, and so is a visitor.
	 */
	public function test_sources_that_cannot_create_and_visitors_are_refused(): void {
		$this->assertRefused( $this->create( 'region', 'Atlantis', [ 'country' => 'US' ] ), 'The region list was added to.' );
		$this->assertRefused( $this->create( 'nope', 'Anything' ), 'An unknown source answered.' );

		$GLOBALS['fk_logged_in'] = false;

		$this->assertRefused( $this->create( 'term', 'Sample Packs', [ 'taxonomy' => 'pack_collection' ] ), 'A visitor made a term.' );
	}

	/**
	 * The route's permission check is where each refusal happens, before the
	 * callback is reached at all.
	 */
	public function test_the_permission_check_refuses_each_kind(): void {
		$this->deny( 'edit_pack_collection', 'create_collections', 'create_users' );

		$controller = new SearchController( new Sources() );

		foreach ( [ [ 'term', [ 'taxonomy' => 'pack_collection' ] ], [ 'post', [ 'post_type' => 'collection' ] ], [ 'user', [] ], [ 'region', [] ] ] as [ $source, $args ] ) {
			$request = new WP_REST_Request( 'POST' );

			$request->set_param( 'source', $source );
			$request->set_param( 'text', 'x@example.test' );
			$request->set_param( 'args', $args );

			$this->assertInstanceOf( WP_Error::class, $controller->check_create_permission( $request ), $source . ' passed the permission check.' );
		}
	}

	/**
	 * The create method asks again, for a consumer that calls it directly.
	 */
	public function test_create_checks_the_capability_itself(): void {
		$this->deny( 'edit_pack_collection' );

		$controller = new SearchController( new Sources() );
		$request    = new WP_REST_Request( 'POST' );

		$request->set_param( 'source', 'term' );
		$request->set_param( 'text', 'Sample Packs' );
		$request->set_param( 'args', [ 'taxonomy' => 'pack_collection' ] );

		$this->assertRefused( $controller->create( $request ), 'create() made a term the permission check would have refused.' );
	}

	/**
	 * A callback source creates through its own callable, under its own
	 * capability, and not at all without one.
	 */
	public function test_a_callback_source_can_create(): void {
		$sources = new Sources();
		$search  = static fn() => [];

		$sources->register( new CallbackSource( 'sizes', $search ) );
		$sources->register(
			new CallbackSource(
				'colours',
				$search,
				'edit_posts',
				[ 'palette' ],
				static fn( string $text, array $args ) => [
					'id'   => 'c-' . strtolower( $text ) . '-' . ( $args['palette'] ?? '' ),
					'text' => $text,
				],
				'manage_colours'
			)
		);

		$this->assertRefused( $this->create( 'sizes', 'XXL', [], $sources ), 'A callback source with no creator created.' );
		$this->assertFalse( $sources->get( 'sizes' )->can_create( [] ), 'A callback source with no creator says it can create.' );

		$made = $this->create( 'colours', 'Teal', [ 'palette' => 'brand', 'other' => 'x' ], $sources );

		$this->assertSame( 'c-teal-brand', $made->get_data()['id'] );

		$this->deny( 'manage_colours' );

		$this->assertRefused( $this->create( 'colours', 'Teal', [], $sources ), 'The creator ran without its capability.' );
	}

	/**
	 * A field set registers a field's creator with its search.
	 */
	public function test_a_field_set_registers_the_creator(): void {
		$set = new FieldSet(
			[
				'size' => [
					'type'            => 'ajax',
					'search_callback' => static fn() => [],
					'create'          => [
						'callback' => static fn( string $text ) => 'made-' . $text,
						'noun'     => 'size',
					],
				],
			],
			new ArrayContext(),
			'createtest'
		);

		$name = $set->source_name( 'size' );

		$this->assertTrue( Sources::shared()->get( $name )->can_create( [] ) );
		$this->assertSame( [ 'id' => 'made-XL', 'text' => 'XL', 'existing' => false ], Sources::shared()->get( $name )->create( 'XL', [] ) );
	}

	/**
	 * The control is told it may add only when the field asked.
	 *
	 * @dataProvider typeProvider
	 *
	 * @param string $type Field type.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'typeProvider' )]
	public function test_the_add_attributes_follow_create( string $type ): void {
		$this->assertStringNotContainsString( 'data-create', $this->render( $type ) );
		$this->assertStringNotContainsString( 'data-create', $this->render( $type, [ 'create' => false ] ) );

		$on = $this->render( $type, [ 'create' => true ] );

		$this->assertStringContainsString( 'data-create-endpoint="https://example.test/wp-json/' . Runtime::rest_namespace() . '/search/create"', $on );
		$this->assertStringContainsString( 'data-create-noun="', $on );
		$this->assertStringNotContainsString( 'data-creatable', $on );
	}

	/**
	 * The four types that create.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function typeProvider(): array {
		return [
			'post'     => [ 'post' ],
			'page'     => [ 'page' ],
			'user'     => [ 'user' ],
			'taxonomy' => [ 'taxonomy' ],
		];
	}

	/**
	 * Someone who could only be refused is not offered the row.
	 *
	 * The route is still what decides; this is so the row is not a promise
	 * the route then breaks.
	 */
	public function test_the_add_row_is_not_offered_to_someone_who_may_not(): void {
		$config = [
			'taxonomy' => 'pack_collection',
			'create'   => true,
		];

		$this->assertStringContainsString( 'data-create-endpoint', $this->render( 'taxonomy', $config ) );

		$this->deny( 'edit_pack_collection', 'create_users' );

		$html = $this->render( 'taxonomy', $config );

		$this->assertStringNotContainsString( 'data-create', $html );
		$this->assertStringNotContainsString( 'data-creatable', $html, 'Refused the Add row, the field fell back to keeping text.' );
		$this->assertStringNotContainsString( 'data-create', $this->render( 'user', [ 'create' => true ] ) );
		$this->assertStringNotContainsString( 'data-create', $this->render( 'taxonomy', [ 'taxonomy' => 'internal', 'create' => true ] ) );
	}

	/**
	 * An ajax field offers to add only with a callback to do it, registered
	 * by its field set.
	 */
	public function test_an_ajax_field_needs_a_create_callback(): void {
		$set = new FieldSet(
			[
				'plain' => [
					'type'            => 'ajax',
					'search_callback' => static fn() => [],
					'create'          => true,
					'creatable'       => true,
				],
				'size'  => [
					'type'            => 'ajax',
					'search_callback' => static fn() => [],
					'create'          => [
						'callback' => static fn() => 'x',
						'noun'     => 'size',
					],
				],
			],
			new ArrayContext(),
			'ajaxcreate'
		);

		$plain = $set->render_field( $set->field( 'plain' ) );

		// With nothing to make items, `create` is not on, so `creatable`
		// keeps doing what it did rather than being switched off by it.
		$this->assertStringNotContainsString( 'data-create-', $plain );
		$this->assertStringContainsString( 'data-creatable="true"', $plain );
		$this->assertStringContainsString( 'data-create-noun="size"', $set->render_field( $set->field( 'size' ) ) );
	}

	/**
	 * The noun is the label, as it reads in a sentence, unless the field
	 * names its own.
	 */
	public function test_the_noun_comes_from_the_label(): void {
		$this->assertStringContainsString( 'data-create-noun="collection"', $this->render( 'taxonomy', [ 'taxonomy' => 'pack_collection', 'create' => true ] ) );
		$this->assertStringContainsString( 'data-create-noun="FAQ group"', $this->render( 'taxonomy', [ 'taxonomy' => 'faq_group', 'create' => true ] ) );
		$this->assertStringContainsString( 'data-create-noun="collection"', $this->render( 'post', [ 'post_type' => 'collection', 'create' => true ] ) );
		$this->assertStringContainsString( 'data-create-noun="page"', $this->render( 'page', [ 'create' => true ] ) );
		$this->assertStringContainsString( 'data-create-noun="user"', $this->render( 'user', [ 'create' => true ] ) );
		$this->assertStringContainsString( 'data-create-noun="pack"', $this->render( 'taxonomy', [ 'taxonomy' => 'pack_collection', 'create' => [ 'noun' => 'pack' ] ] ) );
	}

	/**
	 * What the route is asked to make it with: a status, a role, and an
	 * address for a user.
	 */
	public function test_the_create_arguments_reach_the_page(): void {
		$this->assertStringContainsString( 'data-create-args="{&quot;status&quot;:&quot;draft&quot;}"', $this->render( 'post', [ 'create' => [ 'status' => 'draft' ] ] ) );
		$this->assertStringNotContainsString( 'data-create-args', $this->render( 'post', [ 'create' => true ] ) );

		$GLOBALS['fk_editable_roles'] = [
			'customer' => [ 'name' => 'Customer' ],
			'editor'   => [ 'name' => 'Editor' ],
		];

		$user = $this->render( 'user', [ 'role' => 'customer', 'create' => true ] );

		$this->assertStringContainsString( 'data-create-args="{&quot;role&quot;:&quot;customer&quot;}"', $user );
		$this->assertStringContainsString( 'data-create-input="email"', $user );
		$this->assertStringContainsString( 'data-create-args="{&quot;role&quot;:&quot;editor&quot;}"', $this->render( 'user', [ 'role' => 'customer', 'create' => [ 'role' => 'editor' ] ] ) );
	}

	/**
	 * A created item is stored as its id: the schema stays integers, and
	 * `create` wins over `creatable` when both are set.
	 */
	public function test_the_value_stays_an_id(): void {
		$registry = new Registry();

		foreach ( [ [ 'create' => true ], [ 'create' => true, 'creatable' => true ] ] as $config ) {
			$field = new Field( 'demo', $registry->get( 'taxonomy' ), array_merge( [ 'label' => 'Demo', 'multiple' => true ], $config ) );
			$type  = $registry->get( 'taxonomy' );

			$this->assertSame( 'integer', $type->schema( $field )['items']['type'] );
			$this->assertSame( [ 1001, 7 ], $type->sanitize( [ '1001', 'a word', '7' ], $field ) );
		}

		// Nor is the control told to keep text, or it would let the editor
		// add a value the save then throws away.
		$this->assertStringNotContainsString( 'data-creatable', $this->render( 'taxonomy', [ 'create' => true, 'creatable' => true ] ) );

		// creatable alone still stores the text, as it always has.
		$field = new Field( 'demo', $registry->get( 'taxonomy' ), [ 'label' => 'Demo', 'multiple' => true, 'creatable' => true ] );

		$this->assertSame( 'string', $registry->get( 'taxonomy' )->schema( $field )['items']['type'] );
		$this->assertSame( [ '1001', 'a word' ], $registry->get( 'taxonomy' )->sanitize( [ '1001', 'a word' ], $field ) );
	}

	/**
	 * `create` is a key the relational types read, so it is not reported as
	 * a mistake.
	 *
	 * @dataProvider typeProvider
	 *
	 * @param string $type Field type.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'typeProvider' )]
	public function test_create_is_a_known_key( string $type ): void {
		$field = new Field(
			'demo',
			( new Registry() )->get( $type ),
			[
				'label'  => 'Demo',
				'create' => [ 'noun' => 'thing' ],
			]
		);

		$this->assertSame( [], $field->unknown_keys() );
	}
}
