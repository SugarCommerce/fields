<?php
/**
 * Post Fields: Instances
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\PostFields;

/**
 * Every registered metabox, and reading a field's value or configuration back from them.
 *
 * Part of PostFields: only the class uses it.
 */
trait Instances {

	/**
	 * Register a metabox of fields on one or more post types.
	 *
	 * Values save to post meta, each sanitised by its own type. Nothing is
	 * exposed to the REST API unless a field sets `show_in_rest`.
	 *
	 * @param string               $id     Unique metabox identifier.
	 * @param array<string, mixed> $config Metabox configuration: title,
	 *                                     post_types, context, priority,
	 *                                     prefix, capability, layout, panels,
	 *                                     block_editor and fields.
	 *
	 * @return self
	 */
	public static function register( string $id, array $config ): self {
		return new self( $id, $config );
	}

	/**
	 * Read a registered post field's value, through its own field set.
	 *
	 * Through the field set rather than get_post_meta(): the context is
	 * where decryption happens and where the configured default comes from.
	 * Reading the meta directly hands back ciphertext for an encrypted field.
	 *
	 * Without a metabox id, the first metabox that has the key answers.
	 *
	 * @param int    $post_id    The post id.
	 * @param string $meta_key   The meta key, prefix included.
	 * @param string $metabox_id Optional. The metabox to read it from.
	 *
	 * @return mixed The value, the field's default, or null for an unknown
	 *               field or one with nothing stored.
	 */
	public static function value( int $post_id, string $meta_key, string $metabox_id = '' ): mixed {
		if ( '' !== $metabox_id ) {
			$fields = self::get_instance( $metabox_id );

			return $fields ? $fields->get_value( $post_id, $meta_key ) : null;
		}

		foreach ( self::$instances as $id => $fields ) {
			if ( null !== self::get_field_config( (string) $id, $meta_key ) ) {
				return $fields->get_value( $post_id, $meta_key );
			}
		}

		return null;
	}
	/**
	 * Get a registered metabox.
	 *
	 * @param string $id Metabox identifier.
	 *
	 * @return self|null
	 */
	public static function get_instance( string $id ): ?self {
		return self::$instances[ $id ] ?? null;
	}

	/**
	 * Get every registered metabox's configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_all_metaboxes(): array {
		$all = [];

		foreach ( self::$instances as $id => $instance ) {
			$all[ $id ] = $instance->get_config();
		}

		return $all;
	}

	/**
	 * Get one metabox's configuration.
	 *
	 * @param string $id Metabox identifier.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_metabox( string $id ): ?array {
		return self::$instances[ $id ]?->get_config();
	}

	/**
	 * Get one metabox's fields.
	 *
	 * @param string $id Metabox identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_metabox_fields( string $id ): array {
		return self::$instances[ $id ]?->get_fields() ?? [];
	}

	/**
	 * Get one field's configuration.
	 *
	 * @param string $metabox_id Metabox identifier.
	 * @param string $meta_key   The meta key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get_field_config( string $metabox_id, string $meta_key ): ?array {
		return self::get_metabox_fields( $metabox_id )[ $meta_key ] ?? null;
	}

	/**
	 * Read one field's value, through this set's context.
	 *
	 * The reason to use this rather than get_post_meta(): the context is
	 * where decryption happens and where a field's configured default comes
	 * from. Reading the meta directly hands back ciphertext for an encrypted
	 * field and an empty string for one that has never been saved, both of
	 * which look like ordinary values to the caller.
	 *
	 * @param int    $post_id  The post id.
	 * @param string $key       Field key.
	 * @param mixed  $fallback  Returned when the field is unknown or unset.
	 *
	 * @return mixed
	 */
	public function get_value( int $post_id, string $key, mixed $fallback = null ): mixed {
		$field = $this->set->field( $key, $post_id );

		if ( null === $field ) {
			return $fallback;
		}

		$value = $field->value();

		return null === $value || '' === $value ? $fallback : $value;
	}
}
