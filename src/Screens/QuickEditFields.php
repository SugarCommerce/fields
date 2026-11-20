<?php
/**
 * Quick Edit Fields
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Screens;

use SugarCommerce\Fields\Assets;
use SugarCommerce\Fields\Context\PostMetaContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Utils\Runtime;
use Exception;

/**
 * Adds fields to the quick edit panel on a post type's list table.
 *
 * Quick edit is unlike every other context in one way that shapes all of
 * this: the panel is cloned from a hidden template by core's inline-edit
 * script, so the markup is rendered once with no values in it and populated
 * from the row afterwards. That is why the values live in a data attribute on
 * each row rather than in the fields themselves.
 *
 * Everything else — rendering, sanitizing, accessibility — comes from
 * the engine.
 */
class QuickEditFields {

	/**
	 * The column added when a post type has none of its own.
	 *
	 * @var string
	 */
	private const string COLUMN = 'field-kit-inline';

	/**
	 * Where values are read from and written to.
	 *
	 * Built once and shared by every field set this class makes, so the one
	 * built for a permission-restricted save behaves exactly as the full one.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The post type these fields belong to.
	 *
	 * @var string
	 */
	private string $post_type;

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
	 * Registered instances by post type.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * Whether this set has already drawn its panel this request.
	 *
	 * @var bool
	 */
	private bool $rendered = false;

	/**
	 * Construct.
	 *
	 * @param array<string, array<string, mixed>> $fields    Field configuration.
	 * @param string                              $post_type The post type.
	 *
	 * @throws Exception If the post type is empty.
	 */
	public function __construct( array $fields, string $post_type ) {
		self::declare_config_keys();

		if ( '' === trim( $post_type ) ) {
			throw new Exception( 'Post type cannot be empty.' );
		}

		$this->post_type = $post_type;
		$this->fields    = $this->inline_only( $fields );
		$this->context   = new PostMetaContext();
		$this->set       = new FieldSet( $this->fields, $this->context, '', new Registry() );

		self::$instances[ $post_type ] = $this;

		if ( did_action( 'admin_init' ) ) {
			$this->load_hooks();
		} else {
			add_action( 'admin_init', [ $this, 'load_hooks' ] );
		}
	}

	/**
	 * Drop the fields that cannot live in a cloned panel.
	 *
	 * Dropped rather than rendered badly. Quick edit clones its panel from a
	 * hidden template before the values are in it, so anything that has to be
	 * started in JavaScript — an editor, a colour picker's sibling — comes up
	 * dead in the clone; and the panel is one row of a list table, so a
	 * list of files or a stack of repeater rows has nowhere to go.
	 *
	 * The judgement is the kit's, not this library's: a type knows whether it
	 * survives being cloned. Two libraries each keeping their own whitelist
	 * is why the same field worked in one and not the other.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function inline_only( array $fields ): array {
		return Inline::fields( $fields );
	}

	/**
	 * Attach the hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		add_action( 'quick_edit_custom_box', [ $this, 'render' ], 10, 2 );

		// Without a column of some kind the hook above never fires.
		add_filter( "manage_{$this->post_type}_posts_columns", [ $this, 'ensure_column' ] );
		add_action( "manage_{$this->post_type}_posts_custom_column", [ $this, 'render_column' ] );
		add_action( 'save_post_' . $this->post_type, [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );

		// The panel is cloned before any value is in it, so each row carries
		// its own values for the script to read back.
		add_filter( 'post_class', [ $this, 'add_row_values' ], 10, 3 );
	}

	/**
	 * Enqueue the kit on this post type's list table only.
	 *
	 * @param string $hook_suffix The current screen's hook.
	 *
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'edit.php' !== $hook_suffix ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || $screen->post_type !== $this->post_type ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * Render the fields into the quick edit panel.
	 *
	 * Called once per column, so it runs only for the first one — the panel
	 * is a single form and the fields belong to it, not to a column.
	 *
	 * @param string $column_name The column being rendered.
	 * @param string $post_type   The post type.
	 *
	 * @return void
	 */
	public function render( string $column_name, string $post_type ): void {
		// An instance property, not a static. A static inside a method lives
		// for the whole request, so a second set of fields registered against
		// the same post type would find the flag already set and draw
		// nothing at all.
		if ( $post_type !== $this->post_type || $this->rendered ) {
			return;
		}

		$this->rendered = true;

		echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col">';

		wp_nonce_field( $this->nonce_action(), $this->nonce_name() );

		foreach ( $this->set->fields() as $field ) {
			if ( ! $this->permitted( $field ) ) {
				continue;
			}

			printf(
				'<label class="inline-edit-group field-kit__inline-field" data-field="%s">%s</label>',
				esc_attr( $field->key() ),
				// The kit escapes every value as it builds an attribute, so
				// this is already safe markup.
				$this->set->render_field( $field ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see comment.
			);
		}

		echo '</div></fieldset>';
	}

	/**
	 * Attach each row's values for the inline editor to read.
	 *
	 * @param string[] $classes Row classes.
	 * @param string[] $css     Additional classes.
	 * @param int      $post_id The post id.
	 *
	 * @return string[]
	 */
	public function add_row_values( array $classes, array $css, int $post_id ): array {
		// Only a row this user can quick edit, and only the fields they may
		// change: the values are printed into the page, and a list table
		// shows other authors' posts too.
		if ( get_post_type( $post_id ) !== $this->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			return $classes;
		}

		$values = [];

		foreach ( $this->set->fields() as $field ) {
			if ( $this->permitted( $field, $post_id ) ) {
				$values[ $field->key() ] = get_post_meta( $post_id, $field->key(), true );
			}
		}

		// Carried on the row rather than printed separately, so it travels
		// with the row through any list-table filtering.
		add_action(
			'admin_footer',
			static function () use ( $post_id, $values ): void {
				printf(
					'<script type="application/json" class="field-kit-inline-values" data-post="%d">%s</script>',
					absint( $post_id ),
					wp_json_encode( $values, JSON_HEX_TAG | JSON_HEX_AMP )
				);
			}
		);

		return $classes;
	}

	/**
	 * Save a quick edit submission.
	 *
	 * @param int $post_id The post id.
	 *
	 * @return void
	 */
	public function save( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Quick edit posts its own nonce; a bulk edit or a normal save does
		// not, and must not be treated as one.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		$nonce = isset( $_POST[ $this->nonce_name() ] )
			? sanitize_text_field( wp_unslash( $_POST[ $this->nonce_name() ] ) )
			: '';

		if ( '' === $nonce || ! wp_verify_nonce( $nonce, $this->nonce_action() ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$input = $_POST;

		// Only what this user may set. The callback filtered what was drawn
		// and nothing else, so anyone who knew a hidden field's key could
		// post it.
		//
		// A value that fails validation is skipped by the set — kept as it
		// was, with the rest stored — and that is all that happens to it.
		// Quick edit is answered over AJAX with the redrawn row, so there is
		// no screen load to carry a notice to; the row shows the value that
		// stayed.
		$this->permitted_set( $post_id )->save( $input, $post_id );
	}

	/**
	 * A field set limited to what the current user may write.
	 *
	 * Decided by the same callback the panel is drawn with, so "may they see
	 * it" and "may they set it" cannot drift apart. The full set is reused
	 * when nothing was withheld.
	 *
	 * @param int $post_id The post being saved.
	 *
	 * @return FieldSet
	 */
	private function permitted_set( int $post_id ): FieldSet {
		$keys = [];

		foreach ( $this->set->fields() as $field ) {
			if ( $this->permitted( $field, $post_id ) ) {
				$keys[] = $field->key();
			}
		}

		$allowed = array_intersect_key( $this->fields, array_flip( $keys ) );

		return $allowed === $this->fields
			? $this->set
			: new FieldSet( $allowed, $this->context, '', new Registry() );
	}

	/**
	 * The nonce's action: this build's own, so another plugin's copy of the
	 * kit on the same list neither prints nor accepts it.
	 *
	 * @return string
	 */
	private function nonce_action(): string {
		return Runtime::key( 'quick_edit_' . sanitize_key( $this->post_type ) );
	}

	/**
	 * The nonce field's name: this build's own.
	 *
	 * @return string
	 */
	private function nonce_name(): string {
		return Runtime::key( 'quick_edit_' . sanitize_key( $this->post_type ) . '_nonce' );
	}

	/**
	 * Whether the current user may see and change a field.
	 *
	 * @param Field $field   The field.
	 * @param int   $post_id The post, or 0 for the panel as a whole.
	 *
	 * @return bool
	 */
	private function permitted( Field $field, int $post_id = 0 ): bool {
		return Inline::permitted( $field, $post_id );
	}

	/**
	 * Register quick edit fields on one or more post types.
	 *
	 * @param string|string[]                     $post_types One post type or several.
	 * @param array<string, array<string, mixed>> $fields     Field configuration, keyed by meta key.
	 *
	 * @return self[] One instance per post type.
	 * @throws Exception If a post type is empty.
	 */
	public static function register( string|array $post_types, array $fields ): array {
		$instances = [];

		foreach ( (array) $post_types as $post_type ) {
			$instances[] = new self( $fields, (string) $post_type );
		}

		return $instances;
	}
	/**
	 * Get the registered field configuration for a post type.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fields_for( string $post_type ): array {
		return isset( self::$instances[ $post_type ] ) ? self::$instances[ $post_type ]->fields : [];
	}

	/**
	 * Get this instance's field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Get one field's configuration.
	 *
	 * @param string $key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_field( string $key ): ?array {
		return $this->fields[ $key ] ?? null;
	}

	/**
	 * Tell the kit which configuration this library reads.
	 *
	 * A quick-edit field can gate itself with a callback of its own, which
	 * this class calls and the kit knows nothing about. Without this the kit
	 * would report each of them as configuration nothing reads — which is
	 * the warning it exists to give, aimed at the wrong thing.
	 *
	 * @return void
	 */
	private static function declare_config_keys(): void {
		static $declared = false;

		if ( $declared ) {
			return;
		}

		$declared = true;

		Field::allow_config_keys( [ 'permission_callback' ] );
	}

	/**
	 * Make sure there is a column for core to fire the hook against.
	 *
	 * `quick_edit_custom_box` is fired once per column, and only for columns that
	 * are not core's own — so on a post type whose list table has no custom
	 * column at all, it never fires and none of these fields are rendered.
	 * Nothing says so: the fields are registered, the screen is right, and
	 * the panel is simply empty.
	 *
	 * So one is added when there is none. Its label is empty, which means
	 * core leaves it out of Screen Options — WP_Screen skips a column with no
	 * title — and the stylesheet hides the cells, so the table looks exactly
	 * as it did.
	 *
	 * @param array<string, string> $columns The list table's columns.
	 *
	 * @return array<string, string>
	 */
	public function ensure_column( array $columns ): array {
		// Core's own, which the hook is never fired for.
		$core = [ 'cb', 'title', 'author', 'categories', 'tags', 'comments', 'date' ];

		if ( [] !== array_diff( array_keys( $columns ), $core ) ) {
			return $columns;
		}

		$columns[ self::COLUMN ] = '';

		return $columns;
	}

	/**
	 * The added column renders nothing.
	 *
	 * @param string $column The column being rendered.
	 *
	 * @return void
	 */
	public function render_column( string $column ): void {
	}
}
