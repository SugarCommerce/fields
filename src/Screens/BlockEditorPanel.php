<?php
/**
 * Block Editor Panel
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.7.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Screens;

use SugarCommerce\Fields\Conditions;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry as TypeRegistry;
use SugarCommerce\Fields\Support\Badge;
use WP_REST_Meta_Fields;
use SugarCommerce\Fields\Traits\BlockEditorPanel\ScriptData;

/**
 * A metabox's fields in the block editor's sidebar, rather than under it.
 *
 * The block editor keeps a post's meta in its own store and saves it over
 * REST with the rest of the post, so a field drawn in the sidebar is a
 * registered meta key with `show_in_rest`, read and written by the editor
 * itself. Nothing is posted from here and nothing here saves: what this class
 * decides is which fields can live in the sidebar, what WordPress is told
 * about them, and what the script that draws them is handed.
 *
 * Which fields, deliberately few. A field belongs in the sidebar when one
 * of `@wordpress/components`' own controls stores exactly what the type
 * stores, and when nothing the metabox does on a save would be skipped by
 * moving it:
 *
 * - The type has a control here (CONTROLS). A repeater, an image, a visual
 *   editor or a relational search is a panel of its own, and a sidebar 280
 *   pixels wide is not where to rebuild one.
 * - It is not encrypted, and is not a password or a licence: REST would hand
 *   the editor the ciphertext, or the secret.
 * - It asks for no capability beyond the metabox's default. REST gives a
 *   post's meta to everyone who may edit the post, and has no way to keep one
 *   key from some of them.
 * - It is not `required` and has no `validate` rule or `sanitize_callback`
 *   of its own. Those run in the metabox's save, which refuses a value and
 *   says why; a REST save runs the type's sanitiser and nothing else.
 * - It is not disabled, read-only or locked by a badge.
 * - Its conditions name only fields in the sidebar, and no field left in the
 *   metabox names it. The two are saved by different requests, and each
 *   would read the other's fields as missing.
 *
 * Everything else stays in the metabox, which the block editor still shows
 * under the content, and which then draws and saves only those fields.
 */
final class BlockEditorPanel {

	use ScriptData;

	/**
	 * The field types the sidebar can draw, and the control each becomes.
	 *
	 * @var array<string, string>
	 */
	public const array CONTROLS = [
		'text'         => 'text',
		'email'        => 'email',
		'url'          => 'url',
		'tel'          => 'tel',
		'number'       => 'number',
		'percentage'   => 'number',
		'range'        => 'range',
		'textarea'     => 'textarea',
		'select'       => 'select',
		'radio'        => 'radio',
		'button_group' => 'button_group',
		'checkbox'     => 'checkbox',
		'toggle'       => 'toggle',
		'date'         => 'date',
		'time'         => 'time',
		'datetime'     => 'datetime',
		'color'        => 'color',
	];

	/**
	 * The one capability a field in the sidebar may ask for: the metabox's
	 * default, which anyone able to edit a post already has.
	 */
	private const string CAPABILITY = 'edit_posts';

	/**
	 * The metabox's identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * The metabox's configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Every field in the metabox, keyed by meta key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * Where values are read from and written to.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The type registry.
	 *
	 * @var TypeRegistry
	 */
	private TypeRegistry $registry;

	/**
	 * The keys of the fields that go in the sidebar, in configuration order.
	 *
	 * @var string[]
	 */
	private array $keys;

	/**
	 * Construct.
	 *
	 * @param string                              $id       The metabox's identifier.
	 * @param array<string, mixed>                $config   The metabox's configuration.
	 * @param array<string, array<string, mixed>> $fields   Every field, prefixed.
	 * @param Context                             $context  Where values live.
	 * @param TypeRegistry                        $registry The type registry.
	 */
	public function __construct( string $id, array $config, array $fields, Context $context, TypeRegistry $registry ) {
		$this->id       = $id;
		$this->config   = $config;
		$this->fields   = $fields;
		$this->context  = $context;
		$this->registry = $registry;

		$eligible = array_filter(
			array_keys( $fields ),
			fn( $key ) => $this->eligible( (string) $key, (array) $fields[ $key ] )
		);

		$this->keys = $this->settle( array_map( 'strval', $eligible ) );
	}

	/**
	 * The fields that go in the sidebar, wherever the post type allows it.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return $this->keys;
	}

	/**
	 * The fields that go in the sidebar on one post type.
	 *
	 * None, on a post type without `custom-fields`: REST leaves a post's meta
	 * out of the response unless the type supports them, and a control with
	 * nothing to read and nowhere to save is worse than the metabox. Asked on
	 * the screen rather than at registration, because a post type is often
	 * registered after the fields that go on it.
	 *
	 * @param string $post_type The post type.
	 *
	 * @return string[]
	 */
	public function keys_for( string $post_type ): array {
		return post_type_supports( $post_type, 'custom-fields' ) ? $this->keys : [];
	}

	/**
	 * Whether the screen being drawn is the block editor.
	 *
	 * @return bool
	 */
	public static function on_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return is_object( $screen ) && method_exists( $screen, 'is_block_editor' ) && (bool) $screen->is_block_editor();
	}

	/**
	 * Whether a field can be drawn in the sidebar, on its own terms.
	 *
	 * Its conditions are settled afterwards, against the others.
	 *
	 * @param string               $key    The meta key.
	 * @param array<string, mixed> $config Field configuration.
	 *
	 * @return bool
	 */
	private function eligible( string $key, array $config ): bool {
		$type = (string) ( $config['type'] ?? 'text' );

		if ( ! isset( self::CONTROLS[ $type ] ) || ! $this->registry->has( $type ) ) {
			return false;
		}

		// A field can ask to stay where it was.
		if ( false === ( $config['block_editor'] ?? true ) ) {
			return false;
		}

		$capability = (string) ( $config['capability'] ?? $this->config['capability'] ?? self::CAPABILITY );

		if ( self::CAPABILITY !== $capability ) {
			return false;
		}

		$resolved = $this->registry->get( $type );
		$field    = new Field( $key, $resolved, array_merge( $resolved->defaults(), $config ), null );

		if (
			(bool) $field->get( 'encrypted', false )
			|| $field->is_required()
			|| ! empty( $field->get( 'validate' ) )
			|| null !== $field->get( 'sanitize_callback' )
			|| (bool) $field->get( 'disabled', false )
			|| (bool) $field->get( 'readonly', false )
			|| Badge::locks( $field )
		) {
			return false;
		}

		// A select that takes several values, or invents them, stores a
		// list or a value no option names; the sidebar's select does neither.
		if ( 'select' === $type && ( $field->get( 'multiple' ) || $field->get( 'creatable' ) ) ) {
			return false;
		}

		// Core's picker with alpha stores an rgba(); the sidebar's palette
		// writes a hex, and the two would disagree about the same colour.
		if ( 'color' === $type && $field->get( 'alpha' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Keep a condition and the field it names on the same side.
	 *
	 * The sidebar is saved over REST and the metabox by a form posted after
	 * it, and each request carries only its own fields. A metabox field shown
	 * when a sidebar field is ticked would find nothing ticked in its form,
	 * read its condition as unmet and be deleted on every save; a sidebar
	 * field conditioned on a metabox field could not be shown or hidden at
	 * all. So when a condition crosses, both ends go to the metabox, and that
	 * is repeated until nothing crosses -- one move can uncover another.
	 *
	 * @param string[] $keys The fields eligible on their own terms.
	 *
	 * @return string[] The ones that stay eligible, in configuration order.
	 */
	private function settle( array $keys ): array {
		$in = array_fill_keys( $keys, true );

		do {
			$moved = false;

			foreach ( $this->fields as $key => $config ) {
				$key = (string) $key;

				foreach ( $this->named( (array) $config ) as $other ) {
					if ( isset( $in[ $key ] ) === isset( $in[ $other ] ) ) {
						continue;
					}

					unset( $in[ $key ], $in[ $other ] );

					$moved = true;
				}
			}
		} while ( $moved );

		return array_values(
			array_filter(
				array_map( 'strval', array_keys( $this->fields ) ),
				static fn( string $key ): bool => isset( $in[ $key ] )
			)
		);
	}

	/**
	 * The fields a field's conditions name.
	 *
	 * @param array<string, mixed> $config Field configuration.
	 *
	 * @return string[]
	 */
	private function named( array $config ): array {
		return array_values( array_unique( array_column( $this->conditions( $config ), 'field' ) ) );
	}

	/**
	 * A field's conditions, normalised.
	 *
	 * @param array<string, mixed> $config Field configuration.
	 *
	 * @return array<int, array{field: string, operator: string, value: mixed}>
	 */
	private function conditions( array $config ): array {
		return Conditions::from( $config['show_when'] ?? $config['depends'] ?? [] )->to_array();
	}

	/**
	 * The metabox's fields as they are to be declared to WordPress.
	 *
	 * Each sidebar field is put in REST, in the `edit` context only. That is
	 * the context the block editor asks for, and the one WordPress answers
	 * only for someone who may edit the post: moving a field from a metabox
	 * to the sidebar should not also publish it to anyone who can read the
	 * post. A field that asked for `show_in_rest` itself keeps what it asked
	 * for.
	 *
	 * Each is also given the metabox's capability as its own, which is what
	 * makes MetaRegistrar write an `auth_callback`. Without one a key that
	 * starts with an underscore is protected, and WordPress refuses every
	 * REST write to it -- which is to say every save from the sidebar.
	 *
	 * @param array<string, array<string, mixed>> $fields Every field, prefixed.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function registration( array $fields ): array {
		foreach ( $this->keys as $key ) {
			$config = (array) $fields[ $key ];

			if ( empty( $config['show_in_rest'] ) ) {
				$config['show_in_rest'] = [
					'schema'           => [ 'context' => [ 'edit' ] ],
					'prepare_callback' => $this->rest_value( $key, array_key_exists( 'default', $config ) ),
				];
			}

			$config['capability'] = (string) ( $config['capability'] ?? self::CAPABILITY );

			$fields[ $key ] = $config;
		}

		return $fields;
	}

	/**
	 * How a sidebar field's value is put into a REST response.
	 *
	 * WordPress answers a key with nothing stored with the empty value of its
	 * type: zero for a number, an empty string for text. The editor then
	 * sends that back with the next save and it is stored, so a number nobody
	 * typed becomes a zero and every untouched text field an empty row. With
	 * nothing stored and no default to show, the answer here is null, which
	 * the editor sends back as null and WordPress reads as "leave it unset".
	 *
	 * @param string $key         The meta key.
	 * @param bool   $has_default Whether the field has a default to show.
	 *
	 * @return callable
	 */
	private function rest_value( string $key, bool $has_default ): callable {
		return static function ( $value, $request, $args ) use ( $key, $has_default ) {
			$post_id = isset( $request['id'] ) ? (int) $request['id'] : 0;

			if ( ! $has_default && $post_id > 0 && ! metadata_exists( 'post', $post_id, $key ) ) {
				return null;
			}

			return class_exists( WP_REST_Meta_Fields::class )
				? WP_REST_Meta_Fields::prepare_value( $value, $request, $args )
				: $value;
		};
	}

	/**
	 * Attach what has to run outside wp-admin: the REST save.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		foreach ( (array) $this->config['post_types'] as $post_type ) {
			add_action( 'rest_after_insert_' . $post_type, [ $this, 'clear_unmet' ], 10, 2 );
		}
	}

	/**
	 * Delete a sidebar field whose conditions are not met, after a REST save.
	 *
	 * The metabox does this on every save, for the reason it does: the script
	 * hides the field, but a hidden field keeping its stale value is how a
	 * conditional setting comes back to life. A REST save writes what the
	 * editor sent and knows nothing of conditions, so they are asked here,
	 * against what was just stored -- once the sidebar's values are all in.
	 *
	 * Only when the request carried meta: a save that changed the title alone
	 * changed no condition.
	 *
	 * @param mixed $post    The post just saved.
	 * @param mixed $request The REST request that saved it.
	 *
	 * @return void
	 */
	public function clear_unmet( mixed $post, mixed $request = null ): void {
		if ( ! is_object( $post ) || ! isset( $post->ID, $post->post_type ) ) {
			return;
		}

		if ( ! ( is_array( $request ) || $request instanceof \ArrayAccess ) || ! isset( $request['meta'] ) ) {
			return;
		}

		$keys = $this->keys_for( (string) $post->post_type );

		$conditional = array_filter( $keys, fn( $key ) => [] !== $this->conditions( (array) $this->fields[ $key ] ) );

		if ( [] === $conditional ) {
			return;
		}

		$set    = new FieldSet( array_intersect_key( $this->fields, array_flip( $keys ) ), $this->context, '', $this->registry );
		$fields = $set->fields( (int) $post->ID );
		$values = [];

		foreach ( $fields as $field ) {
			$values[ $field->key() ] = $field->value();
		}

		foreach ( $fields as $field ) {
			if ( ! in_array( $field->key(), $conditional, true ) ) {
				continue;
			}

			if ( ! Conditions::from( $this->conditions( (array) $this->fields[ $field->key() ] ) )->are_met( $values ) ) {
				$this->context->delete( (int) $post->ID, $field );
			}
		}
	}
}
