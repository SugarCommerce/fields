<?php
/**
 * User Fields
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
use SugarCommerce\Fields\Context\UserMetaContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Traits\UserFields\Rendering;
use SugarCommerce\Fields\Traits\UserFields\Saving;

/**
 * Registers custom fields on the user profile and add-user screens.
 *
 * Rendering, sanitizing, conditional logic and accessibility all come from
 * the engine. What is left here is what is genuinely specific to users: the
 * five hooks WordPress fires, the section heading a profile screen wants, and
 * the two ways a field can be hidden from one — whether the current user may
 * see it, and whether it appears on someone else's profile as well as their
 * own.
 *
 * That last distinction is why this cannot simply be term-fields with the
 * nouns changed. A profile screen is the one place in WordPress where the
 * object being edited may be the person editing it, and a field that is
 * fine for an administrator to set on someone else is not always one the
 * subject should set on themselves.
 */
class UserFields {

	use Rendering;
	use Saving;

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
	 * Field configuration, keyed by meta key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $fields;

	/**
	 * The heading shown above the fields.
	 *
	 * @var string
	 */
	private string $section_title;

	/**
	 * The field set doing the work.
	 *
	 * @var FieldSet
	 */
	private FieldSet $set;

	/**
	 * Registered instances, so fields can be read back.
	 *
	 * @var array<string, self>
	 */
	private static array $instances = [];

	/**
	 * This instance's identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Construct.
	 *
	 * @param array<string, array<string, mixed>> $fields        Field configuration.
	 * @param string                              $section_title Heading above the fields.
	 */
	public function __construct( array $fields, string $section_title = '' ) {
		self::declare_config_keys();

		$this->fields        = $fields;
		$this->section_title = '' !== $section_title ? $section_title : __( 'Additional Information', 'sugarcommerce' );
		// One context, shared by every field set this class builds. The
		// encrypting decorator is what makes `encrypted` mean anything:
		// without it the flag rendered a password control, had REST exposure
		// refused on its account, and stored the value in plain text.
		$this->context = new EncryptedContext( new UserMetaContext() );

		$this->set           = new FieldSet( $fields, $this->context, '', new Registry() );

		// Keyed by the fields it carries, so two registrations of the same
		// set collapse rather than rendering the same inputs twice.
		$this->id = md5( (string) wp_json_encode( array_keys( $fields ) ) );

		self::$instances[ $this->id ] = $this;

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
	 * Declare these meta keys to WordPress.
	 *
	 * @return void
	 */
	public function register_meta(): void {
		$this->set->register_meta();
	}

	/**
	 * Attach the profile screens' hooks.
	 *
	 * @return void
	 */
	public function load_hooks(): void {
		// Your own profile, and someone else's.
		add_action( 'show_user_profile', [ $this, 'render_profile' ] );
		add_action( 'edit_user_profile', [ $this, 'render_profile' ] );

		add_action( 'user_new_form', [ $this, 'render_add_form' ] );

		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
		add_action( 'user_register', [ $this, 'save' ] );

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Enqueue the kit on the user screens only.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->id, [ 'profile', 'user-edit', 'user' ], true ) ) {
			return;
		}

		( new Assets() )->enqueue( $this->set->dependencies() );
	}

	/**
	 * Get the field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_fields(): array {
		return $this->fields;
	}

	/**
	 * Get one field's configuration.
	 *
	 * @param string $meta_key The meta key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get_field( string $meta_key ): ?array {
		return $this->fields[ $meta_key ] ?? null;
	}

	/**
	 * Register fields on the profile, edit-user and add-user screens.
	 *
	 * @param array<string, array<string, mixed>> $fields        Field configuration, keyed by meta key.
	 * @param string                              $section_title Optional. The heading above them;
	 *                                                           "Additional Information" when empty.
	 *
	 * @return self
	 */
	public static function register( array $fields, string $section_title = '' ): self {
		return new self( $fields, $section_title );
	}

	/**
	 * Read a registered user field's value.
	 *
	 * Use this rather than get_user_meta(): the field's own context is where
	 * decryption happens and where its configured default comes from.
	 *
	 * @param int    $user_id  The user id.
	 * @param string $key      Field key.
	 * @param mixed  $fallback Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public static function value( int $user_id, string $key, mixed $fallback = null ): mixed {
		$fields = self::get_instance_for( $key );

		return $fields ? $fields->get_value( $user_id, $key, $fallback ) : $fallback;
	}
	/**
	 * Get every registered field, across every instance.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all_fields(): array {
		$fields = [];

		foreach ( self::$instances as $instance ) {
			$fields = array_merge( $fields, $instance->get_fields() );
		}

		return $fields;
	}

	/**
	 * Get one field's configuration from any instance.
	 *
	 * @param string $meta_key The meta key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_field_by_key( string $meta_key ): ?array {
		return self::get_all_fields()[ $meta_key ] ?? null;
	}

	/**
	 * The instance that registered a given field.
	 *
	 * User fields are registered as sets rather than against one screen, so
	 * there is no id a caller would know to ask for — a field key is the only
	 * thing they have.
	 *
	 * @param string $meta_key The meta key.
	 *
	 * @return self|null
	 */
	public static function get_instance_for( string $meta_key ): ?self {
		foreach ( self::$instances as $instance ) {
			if ( isset( $instance->fields[ $meta_key ] ) ) {
				return $instance;
			}
		}

		return null;
	}

	/**
	 * Tell the kit which configuration this library reads.
	 *
	 * A user field can name the capability that applies when someone is
	 * editing their own profile, and can be kept off the add-user screen.
	 * Both are read here, so without this the kit would report each of them
	 * as configuration nothing reads — which is the warning it exists to
	 * give, aimed at the wrong thing.
	 *
	 * @return void
	 */
	private static function declare_config_keys(): void {
		static $declared = false;

		if ( $declared ) {
			return;
		}

		$declared = true;

		Field::allow_config_keys( [ 'own_capability', 'show_on_add' ] );
	}

	/**
	 * Read one field's value, through this set's context.
	 *
	 * The reason to use this rather than get_user_meta(): the context is
	 * where decryption happens and where a field's configured default comes
	 * from. Reading the meta directly hands back ciphertext for an encrypted
	 * field and an empty string for one that has never been saved, both of
	 * which look like ordinary values to the caller.
	 *
	 * @param int    $user_id  The user id.
	 * @param string $key       Field key.
	 * @param mixed  $fallback  Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public function get_value( int $user_id, string $key, mixed $fallback = null ): mixed {
		$field = $this->set->field( $key, $user_id );

		if ( null === $field ) {
			return $fallback;
		}

		$value = $field->value();

		return null === $value || '' === $value ? $fallback : $value;
	}
}
