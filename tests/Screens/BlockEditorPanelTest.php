<?php
/**
 * The block editor's sidebar tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests\Screens;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Context\PostMetaContext;
use SugarCommerce\Fields\MetaRegistrar;
use SugarCommerce\Fields\Screens\PostFields;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Plugin\Assets as PackageAssets;
use WP_Post;

// Here rather than in stubs.php: sibling suites load those stubs first and
// keep their own recorders for these functions.
require_once dirname( __DIR__ ) . '/stubs/Assets.php';
require_once dirname( __DIR__ ) . '/stubs/BlockEditor.php';

/**
 * What a metabox with `block_editor` decides: which fields go to the sidebar
 * and which stay, what WordPress is told about the ones that go, and that
 * the box the block editor posts after its REST save leaves them alone.
 *
 * That last is the one with a history. The block editor saves the post over
 * REST and then posts the metaboxes as a form of their own, and a metabox
 * that reads a field missing from its form as cleared empties every field in
 * the sidebar on every save -- a checkbox first, since unticked and missing
 * are the same thing in a form.
 */
final class BlockEditorPanelTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		pf_reset_globals();

		$_POST = [];

		$GLOBALS['fk_registered']        = [];
		$GLOBALS['pfb_no_custom_fields'] = [];

		PackageAssets::clear_cache();
	}

	/**
	 * Build a metabox with the sidebar on.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 * @param array<string, mixed>                $config Configuration overrides.
	 *
	 * @return PostFields
	 */
	private function metabox( array $fields, array $config = [] ): PostFields {
		return new PostFields(
			'demo_box',
			array_merge(
				[
					'title'        => 'Demo',
					'post_types'   => [ 'post' ],
					'block_editor' => true,
					'fields'       => $fields,
				],
				$config
			)
		);
	}

	/**
	 * A screen, in the block editor or the classic one.
	 *
	 * @param bool   $block_editor Whether it is the block editor.
	 * @param string $post_type    The post type being edited.
	 *
	 * @return void
	 */
	private function on_screen( bool $block_editor, string $post_type = 'post' ): void {
		$GLOBALS['pf_screen'] = new class( $block_editor, $post_type ) {

			/**
			 * The screen id.
			 *
			 * @var string
			 */
			public string $id = 'post';

			/**
			 * The screen base.
			 *
			 * @var string
			 */
			public string $base = 'post';

			/**
			 * Construct.
			 *
			 * @param bool   $block     Whether it is the block editor.
			 * @param string $post_type The post type.
			 */
			public function __construct( private bool $block, public string $post_type ) {}

			/**
			 * Whether it is the block editor.
			 *
			 * @return bool
			 */
			public function is_block_editor(): bool {
				return $this->block;
			}
		};
	}

	/**
	 * A post.
	 *
	 * @param int $id Post id.
	 *
	 * @return WP_Post
	 */
	private function post( int $id = 7 ): WP_Post {
		$post            = new WP_Post();
		$post->ID        = $id;
		$post->post_type = 'post';

		return $post;
	}

	/**
	 * Render the metabox and return what it printed.
	 *
	 * @param PostFields $metabox The metabox.
	 *
	 * @return string
	 */
	private function render( PostFields $metabox ): string {
		ob_start();

		try {
			$metabox->render( $this->post() );
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * A field of each kind the sidebar refuses, beside some it takes.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function mixed_fields(): array {
		return [
			'tagline'    => [
				'type'  => 'text',
				'label' => 'Tagline',
			],
			'count'      => [
				'type'  => 'number',
				'label' => 'Count',
				'min'   => 0,
			],
			'share'      => [
				'type'  => 'percentage',
				'label' => 'Share',
			],
			'size'       => [
				'type'    => 'select',
				'label'   => 'Size',
				'options' => [
					's' => 'Small',
					'l' => 'Large',
				],
			],
			'featured'   => [
				'type'  => 'checkbox',
				'label' => 'Featured',
			],
			'colour'     => [
				'type'    => 'color',
				'label'   => 'Colour',
				'presets' => [ '#ff0000' ],
			],
			'cover'      => [
				'type'  => 'image',
				'label' => 'Cover',
			],
			'api_key'    => [
				'type'      => 'text',
				'label'     => 'API key',
				'encrypted' => true,
			],
			'admin_only' => [
				'type'       => 'text',
				'label'      => 'Admin only',
				'capability' => 'manage_options',
			],
			'needed'     => [
				'type'     => 'text',
				'label'    => 'Needed',
				'required' => true,
			],
			'contact'    => [
				'type'     => 'text',
				'label'    => 'Contact',
				'validate' => 'email',
			],
			'cleaned'    => [
				'type'              => 'text',
				'label'             => 'Cleaned',
				'sanitize_callback' => static fn( $value ) => strtoupper( (string) $value ),
			],
			'kept'       => [
				'type'         => 'text',
				'label'        => 'Kept',
				'block_editor' => false,
			],
			'sizes'      => [
				'type'     => 'select',
				'label'    => 'Sizes',
				'multiple' => true,
				'options'  => [ 's' => 'Small' ],
			],
			'tint'       => [
				'type'  => 'color',
				'label' => 'Tint',
				'alpha' => true,
			],
			'locked'     => [
				'type'     => 'text',
				'label'    => 'Locked',
				'disabled' => true,
			],
		];
	}

	/**
	 * The sidebar takes the types it has a control for, and nothing it
	 * would have to save differently from the metabox.
	 */
	public function test_the_sidebar_takes_only_what_it_can_save_as_the_metabox_would(): void {
		$panel = $this->metabox( $this->mixed_fields() )->get_panel();

		$this->assertNotNull( $panel );
		$this->assertSame( [ 'tagline', 'count', 'share', 'size', 'featured', 'colour' ], $panel->keys() );
	}

	/**
	 * Without `block_editor`, there is no sidebar: the metabox is what it was.
	 */
	public function test_the_sidebar_is_off_unless_asked_for(): void {
		$metabox = $this->metabox( $this->mixed_fields(), [ 'block_editor' => false ] );

		$this->assertNull( $metabox->get_panel() );

		$metabox = new PostFields( 'plain', [ 'fields' => $this->mixed_fields() ] );

		$this->assertNull( $metabox->get_panel() );
	}

	/**
	 * A metabox asking for more than the default capability keeps its
	 * fields: REST gives a post's meta to anyone who may edit the post.
	 */
	public function test_a_restricted_metabox_keeps_every_field(): void {
		$panel = $this->metabox( $this->mixed_fields(), [ 'capability' => 'manage_options' ] )->get_panel();

		$this->assertSame( [], $panel->keys() );
	}

	/**
	 * A condition and the field it names end up on the same side, however
	 * many moves that takes.
	 */
	public function test_a_condition_never_crosses_from_sidebar_to_metabox(): void {
		$panel = $this->metabox(
			[
				// Names `e`, which is only moved once `f` is reached -- so
				// this needs a second pass to follow it.
				'g'      => [
					'type'      => 'text',
					'show_when' => [ 'e' => 'yes' ],
				],
				'e'      => [ 'type' => 'text' ],
				// Unsupported, and names `e`: takes `e` with it.
				'f'      => [
					'type'      => 'image',
					'show_when' => [ 'e' => 'yes' ],
				],
				// Both sides in the sidebar: they stay.
				'a'      => [ 'type' => 'checkbox' ],
				'b'      => [
					'type'      => 'text',
					'show_when' => [ 'a' => 1 ],
				],
				// Names a field the sidebar cannot draw: stays with it.
				'cover'  => [ 'type' => 'image' ],
				'credit' => [
					'type'      => 'text',
					'show_when' => [ 'cover' => [ 'operator' => 'not_empty' ] ],
				],
			]
		)->get_panel();

		$this->assertSame( [ 'a', 'b' ], $panel->keys() );
	}

	/**
	 * REST leaves a post's meta out unless its type supports custom fields,
	 * so on such a type every field stays in the metabox.
	 */
	public function test_a_post_type_without_custom_fields_keeps_the_metabox(): void {
		$GLOBALS['pfb_no_custom_fields'] = [ 'post' ];

		$metabox = $this->metabox( $this->mixed_fields() );

		$this->assertSame( [], $metabox->get_panel()->keys_for( 'post' ) );

		$this->on_screen( true );

		$this->assertStringContainsString( 'name="tagline"', $this->render( $metabox ) );
	}

	/**
	 * A sidebar field is declared to REST with its type's schema, in the edit
	 * context only, sanitised by its type and guarded by the capability.
	 */
	public function test_a_sidebar_field_is_registered_for_rest(): void {
		$this->metabox( $this->mixed_fields() )->register_meta();

		$args = $GLOBALS['fk_meta_registry']['post']['count'];

		$this->assertSame( 'integer', $args['type'] );
		$this->assertSame( 'post', $args['object_subtype'] );
		$this->assertSame(
			[
				'type'    => 'integer',
				'minimum' => 0,
				'context' => [ 'edit' ],
			],
			$args['show_in_rest']['schema']
		);

		// Sanitised by the type, as the metabox would.
		$this->assertSame( 12, $args['sanitize_callback']( '12' ) );
		$this->assertSame( 0, $args['sanitize_callback']( '-4' ) );

		// A key starting with an underscore is protected and refuses every
		// REST write without one of these.
		$this->assertIsCallable( $args['auth_callback'] );
		$this->assertTrue( $args['auth_callback']() );

		$GLOBALS['pf_denied'] = [ 'edit_posts' ];

		$this->assertFalse( $args['auth_callback']() );
	}

	/**
	 * What stays in the metabox stays out of REST, as it always was.
	 */
	public function test_a_metabox_field_is_not_put_in_rest(): void {
		$this->metabox( $this->mixed_fields() )->register_meta();

		$registry = $GLOBALS['fk_meta_registry']['post'];

		foreach ( [ 'cover', 'api_key', 'admin_only', 'needed', 'kept' ] as $key ) {
			$this->assertFalse( $registry[ $key ]['show_in_rest'], $key );
		}
	}

	/**
	 * Without the sidebar the registration is exactly what it was.
	 */
	public function test_without_the_sidebar_nothing_is_put_in_rest(): void {
		$this->metabox( $this->mixed_fields(), [ 'block_editor' => false ] )->register_meta();

		$this->assertFalse( $GLOBALS['fk_meta_registry']['post']['tagline']['show_in_rest'] );
		$this->assertArrayNotHasKey( 'auth_callback', $GLOBALS['fk_meta_registry']['post']['tagline'] );
	}

	/**
	 * A key with nothing stored reads as null, which the editor sends back as
	 * null and REST leaves unset -- not as the zero it would then store.
	 */
	public function test_nothing_stored_reads_as_null_over_rest(): void {
		$this->metabox( $this->mixed_fields() )->register_meta();

		$prepare = $GLOBALS['fk_meta_registry']['post']['count']['show_in_rest']['prepare_callback'];
		$args    = [
			'single' => true,
			'schema' => [ 'type' => 'integer' ],
		];

		$this->assertNull( $prepare( 0, [ 'id' => 7 ], $args ) );

		$GLOBALS['fk_meta']['post'][7]['count'] = 0;

		$this->assertSame( 0, $prepare( 0, [ 'id' => 7 ], $args ) );
	}

	/**
	 * A field that asked for REST itself keeps what it asked for.
	 */
	public function test_a_field_already_in_rest_keeps_its_own_exposure(): void {
		$this->metabox(
			[
				'public' => [
					'type'         => 'text',
					'show_in_rest' => true,
				],
			]
		)->register_meta();

		$this->assertSame( [ 'schema' => [ 'type' => 'string' ] ], $GLOBALS['fk_meta_registry']['post']['public']['show_in_rest'] );
	}

	/**
	 * An array `show_in_rest` is WordPress's own, with its schema laid over
	 * the type's rather than replacing it.
	 */
	public function test_an_array_show_in_rest_is_merged_over_the_types_schema(): void {
		( new MetaRegistrar( new PostMetaContext(), 'post' ) )->register(
			[
				'stock' => [
					'type'         => 'number',
					'max'          => 9,
					'show_in_rest' => [
						'name'   => 'stock_level',
						'schema' => [ 'context' => [ 'edit' ] ],
					],
				],
			]
		);

		$this->assertSame(
			[
				'name'   => 'stock_level',
				'schema' => [
					'type'    => 'integer',
					'maximum' => 9,
					'context' => [ 'edit' ],
				],
			],
			$GLOBALS['fk_meta_registry']['post']['stock']['show_in_rest']
		);
	}

	/**
	 * The trap: the block editor posts the metabox after its REST save, with
	 * none of the sidebar's fields in it. A ticked box in the sidebar must
	 * not come back unticked.
	 */
	public function test_the_block_editors_metabox_save_leaves_the_sidebar_alone(): void {
		$metabox = $this->metabox( $this->mixed_fields() );

		$GLOBALS['fk_meta']['post'][7]['featured'] = 1;
		$GLOBALS['fk_meta']['post'][7]['tagline']  = 'Fresh';

		$_POST = [
			'demo_box_nonce'        => 'nonce-save_demo_box',
			'demo_box_block_editor' => '1',
			'kept'                  => 'Still here',
		];

		$metabox->save( 7, $this->post() );

		$this->assertSame( 1, $GLOBALS['fk_meta']['post'][7]['featured'] );
		$this->assertSame( 'Fresh', $GLOBALS['fk_meta']['post'][7]['tagline'] );
		$this->assertSame( 'Still here', $GLOBALS['fk_meta']['post'][7]['kept'] );
	}

	/**
	 * The classic editor's submission carries every field, and is saved the
	 * way it always was: a box missing from it is unticked.
	 */
	public function test_the_classic_editors_save_is_unchanged(): void {
		$metabox = $this->metabox( $this->mixed_fields() );

		$GLOBALS['fk_meta']['post'][7]['featured'] = 1;

		$_POST = [
			'demo_box_nonce' => 'nonce-save_demo_box',
			'tagline'        => 'Classic',
		];

		$metabox->save( 7, $this->post() );

		$this->assertSame( 0, $GLOBALS['fk_meta']['post'][7]['featured'] );
		$this->assertSame( 'Classic', $GLOBALS['fk_meta']['post'][7]['tagline'] );
	}

	/**
	 * In the block editor the metabox draws only what the sidebar does not,
	 * and says so in its own submission.
	 */
	public function test_the_block_editors_metabox_draws_only_the_rest(): void {
		$metabox = $this->metabox( $this->mixed_fields() );

		$this->on_screen( true );

		$html = $this->render( $metabox );

		$this->assertStringNotContainsString( 'name="tagline"', $html );
		$this->assertStringNotContainsString( 'name="featured"', $html );
		$this->assertStringContainsString( 'name="kept"', $html );
		$this->assertStringContainsString( 'name="demo_box_block_editor" value="1"', $html );
	}

	/**
	 * On the classic editor the metabox draws everything, with no marker.
	 */
	public function test_the_classic_metabox_draws_everything(): void {
		$metabox = $this->metabox( $this->mixed_fields() );

		$this->on_screen( false );

		$html = $this->render( $metabox );

		$this->assertStringContainsString( 'name="tagline"', $html );
		$this->assertStringContainsString( 'name="featured"', $html );
		$this->assertStringNotContainsString( 'demo_box_block_editor', $html );
	}

	/**
	 * With every field in the sidebar there is no box in the block editor,
	 * and the classic editor still has one.
	 */
	public function test_an_empty_metabox_is_not_added_to_the_block_editor(): void {
		$metabox = $this->metabox(
			[
				'intro'   => [ 'type' => 'heading' ],
				'tagline' => [ 'type' => 'text' ],
			]
		);

		$this->on_screen( true );
		$metabox->add_meta_box();

		$this->assertSame( [], $GLOBALS['pf_boxes'] );

		$this->on_screen( false );
		$metabox->add_meta_box();

		$this->assertCount( 1, $GLOBALS['pf_boxes'] );
	}

	/**
	 * A metabox with something left in it is still added to the block editor.
	 */
	public function test_a_metabox_with_fields_left_is_added_to_the_block_editor(): void {
		$this->on_screen( true );

		$this->metabox( $this->mixed_fields() )->add_meta_box();

		$this->assertCount( 1, $GLOBALS['pf_boxes'] );
	}

	/**
	 * The script loads on the block editor for this metabox's post type, and
	 * nowhere else.
	 */
	public function test_the_script_loads_on_the_block_editor_only(): void {
		$handle  = Runtime::handle( 'block-editor' );
		$metabox = $this->metabox( $this->mixed_fields() );

		$this->on_screen( false );
		$metabox->get_panel()->enqueue();

		$this->on_screen( true, 'page' );
		$metabox->get_panel()->enqueue();

		$this->assertSame( [], $GLOBALS['fk_registered'] );

		$this->on_screen( true );
		$metabox->get_panel()->enqueue();

		[ $src, $deps ] = $GLOBALS['fk_registered']['scripts'][ $handle ];

		$this->assertStringEndsWith( '/assets/build/block-editor.js', $src );
		$this->assertSame( [ 'wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-plugins' ], $deps );
		$this->assertSame( [ $handle ], $GLOBALS['fk_registered']['enqueued'] );

		// Handed over under this build's handle, not a bare global.
		$inline = implode( '', $GLOBALS['fk_registered']['inline'][ $handle ] );

		$this->assertStringContainsString( 'window.SugarCommerceFields[' . wp_json_encode( $handle ) . ']', $inline );
		$this->assertStringContainsString( '"key":"featured"', $inline );
		$this->assertStringNotContainsString( '"key":"kept"', $inline );
	}

	/**
	 * Nothing for the sidebar to draw, nothing loaded.
	 */
	public function test_no_sidebar_fields_load_no_script(): void {
		$this->on_screen( true );

		$this->metabox( [ 'cover' => [ 'type' => 'image' ] ] )->get_panel()->enqueue();

		$this->assertSame( [], $GLOBALS['fk_registered'] );
	}

	/**
	 * What the script is handed: each field resolved, in order, with what its
	 * control needs and nothing to look up.
	 */
	public function test_the_script_is_handed_resolved_fields(): void {
		$data = $this->metabox(
			[
				'share'    => [
					'type'        => 'percentage',
					'label'       => 'Share',
					'description' => 'Of <strong>the</strong> total.',
				],
				'price'    => [
					'type' => 'number',
					'step' => 0.01,
				],
				'size'     => [
					'type'    => 'select',
					'label'   => 'Size',
					'options' => [
						'10' => 'Ten',
						'2'  => 'Two',
						'Big' => [ 'xl' => 'Extra large' ],
					],
				],
				'layout'   => [
					'type'    => 'radio',
					'options' => [
						'Grids' => [ 'grid' => 'Grid' ],
						'list'  => 'List',
					],
				],
				'featured' => [
					'type'           => 'toggle',
					'label'          => 'Featured',
					'checkbox_label' => 'Show on the home page',
				],
				'note'     => [
					'type'      => 'text',
					'maxlength' => 40,
					'show_when' => [ 'featured' => true ],
				],
			]
		)->get_panel()->data( 'post' );

		$this->assertMatchesRegularExpression( '/^[a-z][a-z0-9-]*$/', $data['plugin'] );
		$this->assertSame( 'demo-box', $data['name'] );
		$this->assertSame( 'Demo', $data['title'] );

		[ $share, $price, $size, $layout, $featured, $note ] = $data['fields'];

		$this->assertSame( 'number', $share['control'] );
		$this->assertTrue( $share['integer'] );
		$this->assertSame( 100, $share['max'] );
		$this->assertSame( 'Of the total.', $share['help'] );

		$this->assertFalse( $price['integer'] );

		$this->assertSame( 'select', $size['control'] );
		$this->assertSame( '— Select —', $size['emptyLabel'] );
		$this->assertSame(
			[
				[
					'value' => '10',
					'label' => 'Ten',
				],
				[
					'value' => '2',
					'label' => 'Two',
				],
				[
					'label'   => 'Big',
					'options' => [
						[
							'value' => 'xl',
							'label' => 'Extra large',
						],
					],
				],
			],
			$size['options']
		);

		// Radios have no groups to keep.
		$this->assertSame( [ 'grid', 'list' ], array_column( $layout['options'], 'value' ) );

		$this->assertSame( 'toggle', $featured['control'] );
		$this->assertSame( 'Show on the home page', $featured['checkboxLabel'] );

		$this->assertSame( 40, $note['maxLength'] );
		$this->assertSame(
			[
				[
					'field'    => 'featured',
					'operator' => '=',
					'value'    => true,
				],
			],
			$note['conditions']
		);
	}

	/**
	 * After a REST save, a sidebar field whose conditions are not met is
	 * deleted, as the metabox's own save would have.
	 */
	public function test_a_rest_save_clears_a_field_whose_conditions_are_not_met(): void {
		$panel = $this->metabox(
			[
				'featured' => [ 'type' => 'checkbox' ],
				'note'     => [
					'type'      => 'text',
					'show_when' => [ 'featured' => 1 ],
				],
			]
		)->get_panel();

		$GLOBALS['fk_meta']['post'][7] = [
			'featured' => 1,
			'note'     => 'Shown',
		];

		$panel->clear_unmet( $this->post(), [ 'meta' => [] ] );

		$this->assertSame( 'Shown', $GLOBALS['fk_meta']['post'][7]['note'] );

		$GLOBALS['fk_meta']['post'][7]['featured'] = 0;

		// A save that carried no meta changed no condition.
		$panel->clear_unmet( $this->post(), [ 'title' => 'New' ] );

		$this->assertSame( 'Shown', $GLOBALS['fk_meta']['post'][7]['note'] );

		$panel->clear_unmet( $this->post(), [ 'meta' => [ 'featured' => 0 ] ] );

		$this->assertArrayNotHasKey( 'note', $GLOBALS['fk_meta']['post'][7] );
	}

	/**
	 * The REST save's hook is attached at registration, since a REST request
	 * never reaches admin_init.
	 */
	public function test_the_rest_hook_is_attached_on_init(): void {
		$GLOBALS['fk_actions'] = [];

		$this->metabox( $this->mixed_fields(), [ 'post_types' => [ 'post', 'page' ] ] )->register_meta();

		$this->assertArrayHasKey( 'rest_after_insert_post', $GLOBALS['fk_actions'] );
		$this->assertArrayHasKey( 'rest_after_insert_page', $GLOBALS['fk_actions'] );
	}
}
