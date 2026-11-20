<?php
/**
 * Term Fields
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
use SugarCommerce\Fields\Context\TermMetaContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use Exception;
use SugarCommerce\Fields\Traits\TermFields\Rendering;
use SugarCommerce\Fields\Traits\TermFields\Saving;

/**
 * Registers custom fields on a taxonomy's add and edit screens.
 *
 * Rendering, sanitizing, conditional logic and accessibility all come from
 * the engine. What is left here is what is genuinely specific to terms: the
 * four hooks WordPress fires, the two different wrappers the add and edit
 * screens want, and the companion meta key an amount field writes its unit
 * to.
 *
 * That is the whole point of the split. This class carried its own renderer,
 * its own sanitizer and its own eight-type switch, and supported eight of the
 * fifty-odd types its sibling libraries did. It now supports all of them.
 */
class TermFields {

	use Rendering;
	use Saving;

	/**
	 * Where values are read from and written to.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The taxonomy these fields belong to.
	 *
	 * @var string
	 */
	private string $taxonomy;

	/**
	 * This field set's identifier.
	 *
	 * Derived from the taxonomy, so the nonce that guards the save and the
	 * scope that keeps one taxonomy's tab strip from driving another's panels
	 * are both unique to it.
	 *
	 * @var string
	 */
	private string $id;

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
	 * Registered instances, so fields can be read back by taxonomy.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * Construct.
	 *
	 * @param string                              $taxonomy The taxonomy slug.
	 * @param array<string, array<string, mixed>> $fields   Field configuration.
	 *
	 * @throws Exception If the taxonomy is empty.
	 */
	public function __construct( string $taxonomy, array $fields ) {
		self::declare_config_keys();

		if ( '' === trim( $taxonomy ) ) {
			throw new Exception( 'Taxonomy cannot be empty.' );
		}

		$this->taxonomy = $taxonomy;
		$this->id       = 'term-' . $taxonomy;
		$this->fields   = $fields;
		// One context, shared by every field set this class builds. The
		// encrypting decorator is what makes `encrypted` mean anything:
		// without it the flag rendered a password control, had REST exposure
		// refused on its account, and stored the value in plain text.
		$this->context = new EncryptedContext( new TermMetaContext() );

		$this->set      = new FieldSet( $fields, $this->context, '', new Registry() );

		self::$instances[ $taxonomy ] = $this;

		// Registered before admin_init on a plain include, and immediately
		// when something registers fields later than that.
		if ( did_action( 'admin_init' ) ) {
			$this->load_hooks();
		} else {
			add_action( 'admin_init', [ $this, 'load_hooks' ] );
		}

		// On init, not admin_init: a registered meta key is how REST, the
		// block editor and the capability check learn the field exists, and
		// all three run on requests that never reach the admin.
		if ( did_action( 'init' ) ) {
			$this->register_meta();
		} else {
			add_action( 'init', [ $this, 'register_meta' ] );
		}
	}

	/**
	 * Declare this taxonomy's meta keys to WordPress.
	 *
	 * Saving works without it. What does not work without it is everything
	 * that asks WordPress *about* the meta — get_registered_meta_keys(), the
	 * REST API, the block editor, and the capability check for writing a key
	 * — and, most usefully, the sanitize callback: with one registered, a
	 * value written by update_term_meta() from anywhere passes through the
	 * field's own type, exactly as a submitted one does.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$this->set->register_meta( $this->taxonomy );
	}

	/**
	 * Attach the taxonomy's hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		add_action( "{$this->taxonomy}_add_form_fields", [ $this, 'render_add_form' ] );
		add_action( "{$this->taxonomy}_edit_form_fields", [ $this, 'render_edit_form' ], 10, 2 );
		add_action( "created_{$this->taxonomy}", [ $this, 'save' ] );
		add_action( "edited_{$this->taxonomy}", [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the kit on this taxonomy's screens only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'edit-tags' !== $screen->base && 'term' !== $screen->base ) {
			return;
		}

		if ( $screen->taxonomy !== $this->taxonomy ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * Register fields on one or more taxonomies' add and edit screens.
	 *
	 * Every field type the engine provides works here, on both screens,
	 * including amount_type, which writes its unit to a companion meta key.
	 * An empty taxonomy name is skipped, and logged, rather than stopping the
	 * rest.
	 *
	 * @param string|string[]                     $taxonomies One taxonomy or several.
	 * @param array<string, array<string, mixed>> $fields     Field configuration, keyed by meta key.
	 *
	 * @return self[] One instance per taxonomy registered.
	 */
	public static function register( string|array $taxonomies, array $fields ): array {
		$instances = [];

		foreach ( (array) $taxonomies as $taxonomy ) {
			try {
				$instances[] = new self( (string) $taxonomy, $fields );
			} catch ( Exception $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a registration mistake, for the developer.
				error_log( 'Term fields: ' . $e->getMessage() );
			}
		}

		return $instances;
	}

	/**
	 * Read a registered term field's value.
	 *
	 * Use this rather than get_term_meta(): the field's own context is where
	 * decryption happens and where its configured default comes from.
	 *
	 * @param int    $term_id  The term id.
	 * @param string $key      Field key.
	 * @param mixed  $fallback Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public static function value( int $term_id, string $key, mixed $fallback = null ): mixed {
		$term = get_term( $term_id );

		if ( ! $term || is_wp_error( $term ) ) {
			return $fallback;
		}

		$fields = self::get_instance( $term->taxonomy );

		return $fields ? $fields->get_value( $term_id, $key, $fallback ) : $fallback;
	}
	/**
	 * Get the registered field configuration for a taxonomy.
	 *
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_fields( string $taxonomy ): array {
		return isset( self::$instances[ $taxonomy ] ) ? self::$instances[ $taxonomy ]->fields : [];
	}

	/**
	 * Get the instance registered for a taxonomy.
	 *
	 * @param string $taxonomy The taxonomy.
	 *
	 * @return self|null
	 */
	public static function get_instance( string $taxonomy ): ?self {
		return self::$instances[ $taxonomy ] ?? null;
	}

	/**
	 * Tell the kit which configuration this library reads.
	 *
	 * A term field can gate itself with a callback of its own, which this
	 * class calls and the kit knows nothing about. Without this the kit
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
	 * Read one field's value, through this set's context.
	 *
	 * The reason to use this rather than get_term_meta(): the context is
	 * where decryption happens and where a field's configured default comes
	 * from. Reading the meta directly hands back ciphertext for an encrypted
	 * field and an empty string for one that has never been saved, both of
	 * which look like ordinary values to the caller.
	 *
	 * @param int    $term_id  The term id.
	 * @param string $key       Field key.
	 * @param mixed  $fallback  Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public function get_value( int $term_id, string $key, mixed $fallback = null ): mixed {
		$field = $this->set->field( $key, $term_id );

		if ( null === $field ) {
			return $fallback;
		}

		$value = $field->value();

		return null === $value || '' === $value ? $fallback : $value;
	}
}
