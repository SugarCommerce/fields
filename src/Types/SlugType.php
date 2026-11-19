<?php
/**
 * Slug Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Contracts\Lookup;
use SugarCommerce\Fields\Field;

/**
 * The part of an address that names a thing: `summer-sale`, `blue-mug`.
 *
 * Cleaned the way WordPress cleans a post's -- `sanitize_title()`, so lower
 * case, hyphens for spaces, nothing a URL would have to escape -- as it is
 * typed and again when it is saved.
 *
 * `source` names another field to make it from: the slug follows that
 * field's text until somebody edits the slug itself, and from then on is
 * theirs. `prefix` shows the address it sits at (`example.com/shop/`).
 *
 * Whether a slug is free is something only the caller knows, so the caller
 * says: `unique_callback` is given the slug and the field and returns true
 * when it is free, false when it is taken, or a free one to suggest instead.
 * The field asks it as somebody types, through the lookup endpoint, and the
 * save asks it again -- a slug taken between the two is still refused. The
 * slug the field already holds is never checked against itself.
 */
final class SlugType extends AbstractInputType implements Lookup {

	/**
	 * A text input.
	 *
	 * @return string
	 */
	protected function input_type(): string {
		return 'text';
	}

	/**
	 * Render the input, its status line and what the script needs.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$attributes->add_class( 'field-kit__slug' );
		$attributes->set( 'spellcheck', 'false' );
		$attributes->set( 'autocapitalize', 'none' );
		$attributes->set( 'data-slug', true );
		$attributes->set_if( $field->has( 'source' ), 'data-slug-source', (string) $field->get( 'source' ) );
		$attributes->set_if( $field->has( 'lookup_name' ), 'data-lookup', (string) $field->get( 'lookup_name' ) );
		$attributes->set( 'data-current', $this->render_value( $field ) );

		$status = sprintf(
			'<span class="field-kit__slug-status description" id="%s" aria-live="polite"></span>',
			esc_attr( $attributes->get( 'id', '' ) . '__status' )
		);

		return parent::render( $field, $attributes ) . $status;
	}

	/**
	 * The input, with the address it sits at in front of it.
	 *
	 * Not the inline prefix the other inputs draw inside their box: that is
	 * sized for a symbol or a unit, and a base address -- `example.com/shop/`
	 * -- is a sentence's worth. Here it is text before the box, on the same
	 * line where there is room and above it where there is not, and read
	 * with the box as its description.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	protected function control( Field $field, Attributes $attributes ): string {
		$base = (string) $field->get( 'prefix', '' );

		if ( '' === $base ) {
			return sprintf( '<input%s />', $attributes->render() );
		}

		$id = (string) $attributes->get( 'id', '' );

		$attributes->set( 'aria-describedby', trim( $id . '__base ' . (string) $attributes->get( 'aria-describedby', '' ) ) );

		return sprintf(
			'<span class="field-kit__slug-line"><span class="field-kit__slug-base" id="%s">%s</span><input%s /></span>',
			esc_attr( $id . '__base' ),
			esc_html( $base ),
			$attributes->render()
		);
	}

	/**
	 * Clean a slug.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value, Field $field ): string {
		return sanitize_title( $this->scalar( $value ) );
	}

	/**
	 * Refuse a slug that is taken, after the field's own rule.
	 *
	 * @param mixed $value The sanitized value.
	 * @param Field $field The field, carrying what is stored.
	 *
	 * @return string
	 */
	public function validate( mixed $value, Field $field ): string {
		$message = parent::validate( $value, $field );

		if ( '' !== $message || '' === $value || $value === $field->raw_value() ) {
			return $message;
		}

		$answer = self::check( (string) $value, $field->get( 'unique_callback' ), $field->config() );

		return $answer['available'] ? '' : $answer['message'];
	}

	/**
	 * Ask the caller whether a slug is free.
	 *
	 * @param string               $slug     A clean slug.
	 * @param mixed                $callback The field's `unique_callback`.
	 * @param array<string, mixed> $config   The field's configuration, for the callback.
	 *
	 * @return array{slug: string, available: bool, suggestion: string, message: string}
	 */
	public static function check( string $slug, mixed $callback, array $config = [] ): array {
		$answer = is_callable( $callback ) && '' !== $slug ? $callback( $slug, $config ) : true;
		$free     = true === $answer;
		$suggest  = is_string( $answer ) ? sanitize_title( $answer ) : '';

		if ( $free ) {
			$message = '';
		} elseif ( '' !== $suggest ) {
			/* translators: 1: a slug, 2: a free one to use instead */
			$message = sprintf( __( '"%1$s" is already in use. Try "%2$s".', 'sugarcommerce' ), $slug, $suggest );
		} else {
			/* translators: %s: a slug */
			$message = sprintf( __( '"%s" is already in use.', 'sugarcommerce' ), $slug );
		}

		return [
			'slug'       => $slug,
			'available'  => $free,
			'suggestion' => $suggest,
			'message'    => $message,
		];
	}

	/**
	 * The handler the field's script asks as somebody types.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return callable|null
	 */
	public function lookup_handler( array $config ): ?callable {
		if ( ! is_callable( $config['unique_callback'] ?? null ) ) {
			return null;
		}

		return static fn( string $query ): array => self::check( sanitize_title( $query ), $config['unique_callback'], $config );
	}

	/**
	 * Who may ask: editors, unless the field says otherwise.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return string
	 */
	public function lookup_capability( array $config ): string {
		return (string) ( $config['lookup_capability'] ?? 'edit_posts' );
	}

	/**
	 * Configuration this type reads.
	 *
	 * @return string[]
	 */
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'source', 'unique_callback' ] );
	}
}
