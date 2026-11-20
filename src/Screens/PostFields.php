<?php
/**
 * Post Fields
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Screens;

use SugarCommerce\Fields\Assets;
use SugarCommerce\Fields\Context\EncryptedContext;
use SugarCommerce\Fields\Context\PostMetaContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\MetaRegistrar;
use SugarCommerce\Fields\Registry as TypeRegistry;
use SugarCommerce\Fields\Traits\PostFields\Rendering;
use SugarCommerce\Fields\Traits\PostFields\Saving;
use SugarCommerce\Fields\Traits\PostFields\Instances;

/**
 * Registers a metabox of custom fields on one or more post types.
 *
 * Rendering, sanitizing, conditional logic, accessibility, the search
 * endpoint and the action endpoint all come from the engine. What is left
 * here is what is genuinely about a metabox: registering it with WordPress,
 * the nonce that guards it, and the two capability questions a post screen
 * asks that a term screen does not — may this user edit *this* post, and may
 * they see *this* field.
 *
 * One default changed in the port, deliberately. `show_in_rest` was true for
 * every field, which publishes every custom field a plugin has ever
 * registered to the REST API and the block editor, including the ones holding
 * a licence key. It is off unless a field asks.
 *
 * With `block_editor`, the fields the block editor's sidebar can draw go
 * there instead (BlockEditorPanel), and the metabox keeps the rest. On the
 * classic editor nothing changes: every field is in the metabox, as before.
 */
class PostFields {

	use Rendering;
	use Saving;
	use Instances;

	/**
	 * Where values are read from and written to.
	 *
	 * Built once and shared by every field set this class makes. It used to
	 * be constructed at each `new FieldSet()`, and the one built for a
	 * capability-restricted set was missing the encrypting decorator — so an
	 * encrypted field saved by a user whose permissions narrowed the set was
	 * written in plain text, and only then.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * This metabox's identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Metabox configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Field configuration, keyed by meta key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * The field set doing the work.
	 *
	 * @var FieldSet
	 */
	private FieldSet $set;

	/**
	 * The type registry.
	 *
	 * @var TypeRegistry
	 */
	private TypeRegistry $registry;

	/**
	 * The block editor's sidebar, when the metabox asked for one.
	 *
	 * @var BlockEditorPanel|null
	 */
	private ?BlockEditorPanel $panel = null;

	/**
	 * Registered instances, so fields can be read back by metabox.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * Construct.
	 *
	 * @param string               $id     Metabox identifier.
	 * @param array<string, mixed> $config Metabox configuration.
	 */
	public function __construct( string $id, array $config ) {
		$this->id     = $id;
		$this->config = $this->defaults( $config );
		// Panels are a way of grouping the same fields, not a second place to
		// declare them: they are flattened into one set here so saving,
		// registration and permissions have nothing to know about layout.
		$this->fields = $this->prefixed( $this->from_panels( $config ) );
		// One context, shared by every field set this class builds. The
		// encrypting decorator is what makes `encrypted` mean anything:
		// without it the flag rendered a password control, had REST exposure
		// refused on its account, and stored the value in plain text.
		$this->context = new EncryptedContext( new PostMetaContext() );

		$this->registry = new TypeRegistry();
		$this->set      = new FieldSet( $this->fields, $this->context, '', $this->registry );

		// A field may say it stays in the metabox, which the kit's check for
		// keys nothing reads would otherwise report.
		Field::allow_config_keys( [ 'block_editor' ] );

		if ( (bool) $this->config['block_editor'] ) {
			$this->panel = new BlockEditorPanel( $id, $this->config, $this->fields, $this->context, $this->registry );
		}

		// The configuration carries the prefixed keys too. Two getters
		// disagreeing about what a field is called is how a default lookup
		// silently misses.
		$this->config['fields'] = $this->fields;

		self::$instances[ $id ] = $this;

		// On init, not admin_init: a registered meta key is how REST, the
		// block editor and the capability check learn the field exists, and
		// all three run on requests that never reach the admin.
		if ( did_action( 'init' ) ) {
			$this->register_meta();
		} else {
			add_action( 'init', [ $this, 'register_meta' ] );
		}

		if ( did_action( 'admin_init' ) ) {
			$this->load_hooks();
		} else {
			add_action( 'admin_init', [ $this, 'load_hooks' ] );
		}
	}

	/**
	 * Merge the metabox configuration with its defaults.
	 *
	 * @param array<string, mixed> $config Supplied configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults( array $config ): array {
		$config = array_merge(
			[
				'title'      => __( 'Additional Information', 'sugarcommerce' ),
				'post_types' => [ 'post' ],
				'context'    => 'normal',
				'priority'   => 'high',
				'prefix'     => '',
				'capability' => 'edit_posts',
				'layout'       => 'stacked',
				'panels'       => [],
				'fields'       => [],
				// Off unless asked. The sidebar puts each field it draws in
				// REST, and moves it out of a metabox someone may be relying
				// on, and neither should happen to a plugin by updating.
				'block_editor' => false,
			],
			$config
		);

		$config['post_types'] = (array) $config['post_types'];

		return $config;
	}

	/**
	 * Every field, whether it was declared flat or inside a panel.
	 *
	 * A panel is a layout. Flattening here means the save path, the meta
	 * registration and the capability checks never learn there was one —
	 * which is the difference between a layout and a second concept.
	 *
	 * @param array<string, mixed> $config Metabox configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function from_panels( array $config ): array {
		$fields = (array) ( $config['fields'] ?? [] );

		foreach ( (array) ( $config['panels'] ?? [] ) as $panel ) {
			$fields = array_merge( $fields, (array) ( $panel['fields'] ?? [] ) );
		}

		return $fields;
	}

	/**
	 * Apply the metabox's meta-key prefix to every field.
	 *
	 * Done once, here, so everything downstream — rendering, saving,
	 * registration — sees the key the database actually uses.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function prefixed( array $fields ): array {
		$prefix = (string) $this->config['prefix'];

		if ( '' === $prefix ) {
			return $fields;
		}

		$prefixed = [];

		foreach ( $fields as $key => $config ) {
			$prefixed[ $prefix . $key ] = $config;
		}

		return $prefixed;
	}

	/**
	 * Declare these meta keys to WordPress.
	 *
	 * One registration per post type, because a key registered without a
	 * subtype applies to every post type there is.
	 *
	 * The sidebar's fields go to REST with their schema, which is how the
	 * block editor reads and saves them, and the REST save's own hook is
	 * attached here: on init, because a REST request never reaches admin_init.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$fields = $this->panel ? $this->panel->registration( $this->fields ) : $this->fields;

		foreach ( (array) $this->config['post_types'] as $post_type ) {
			( new MetaRegistrar( $this->context, (string) $post_type, $this->registry ) )->register( $fields );
		}

		$this->panel?->register_hooks();
	}

	/**
	 * Attach the metabox's hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		if ( $this->panel ) {
			add_action( 'enqueue_block_editor_assets', [ $this->panel, 'enqueue' ] );
		}
	}

	/**
	 * Register the metabox with WordPress.
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		foreach ( (array) $this->config['post_types'] as $post_type ) {
			// In the block editor with every field in the sidebar, a box
			// would be a heading over nothing.
			if ( ! $this->has_metabox_fields( (string) $post_type ) ) {
				continue;
			}

			add_meta_box(
				$this->id,
				(string) $this->config['title'],
				[ $this, 'render' ],
				(string) $post_type,
				(string) $this->config['context'],
				(string) $this->config['priority']
			);
		}
	}

	/**
	 * Enqueue the kit on this metabox's screens only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		if ( ! in_array( $screen->post_type, (array) $this->config['post_types'], true ) ) {
			return;
		}

		if ( ! $this->has_metabox_fields( (string) $screen->post_type ) ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * The fields the sidebar draws on a post type, whatever the screen.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return string[]
	 */
	private function panel_keys( string $post_type ): array {
		return $this->panel ? $this->panel->keys_for( $post_type ) : [];
	}

	/**
	 * The fields the sidebar draws on the screen being drawn: none, unless
	 * it is the block editor.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return string[]
	 */
	private function sidebar_keys( string $post_type ): array {
		return null !== $this->panel && BlockEditorPanel::on_screen() ? $this->panel_keys( $post_type ) : [];
	}

	/**
	 * Whether the metabox has anything left to hold on this screen.
	 *
	 * A heading, a tab or a message holds nothing, and left alone in a box
	 * whose fields all moved to the sidebar it would label an empty space.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return bool
	 */
	private function has_metabox_fields( string $post_type ): bool {
		$skip = $this->sidebar_keys( $post_type );

		if ( [] === $skip ) {
			return true;
		}

		foreach ( array_diff_key( $this->fields, array_flip( $skip ) ) as $config ) {
			$type = (string) ( $config['type'] ?? 'text' );

			if ( $this->registry->has( $type ) && $this->registry->get( $type )->stores_value() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get this metabox's identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Get the metabox configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Get the field configuration, with prefixes applied.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Get the block editor's sidebar, when the metabox asked for one.
	 *
	 * @return BlockEditorPanel|null
	 */
	public function get_panel(): ?BlockEditorPanel {
		return $this->panel;
	}
}
