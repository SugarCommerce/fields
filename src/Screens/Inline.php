<?php
/**
 * Inline Editing
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.8.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Screens;

use SugarCommerce\Fields\Contracts\Unechoed;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Registry;

/**
 * What quick edit and bulk edit may carry, and who may change it.
 *
 * One answer for both screens: the same field array is usually registered
 * on each, and a field one of them withholds is withheld by the other too.
 */
final class Inline {

	/**
	 * The fields that can live in a list-table row.
	 *
	 * The type's own judgement first -- a type knows whether it survives
	 * being cloned into a row. Then two that never can: an encrypted field,
	 * because the list table writes post meta directly and would store the
	 * plain text; and a type that never shows what it holds (a password),
	 * because a row carries every value it edits in the page.
	 *
	 * @param array<string, array<string, mixed>> $fields Field configuration.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function fields( array $fields ): array {
		$registry = new Registry();

		return array_filter(
			$fields,
			static function ( $config ) use ( $registry ): bool {
				$type = (string) ( $config['type'] ?? 'text' );

				if ( ! $registry->has( $type ) || ! empty( $config['encrypted'] ) ) {
					return false;
				}

				$resolved = $registry->get( $type );

				return $resolved->supports_inline() && ! $resolved instanceof Unechoed;
			}
		);
	}

	/**
	 * Whether the current user may see and change a field on a post.
	 *
	 * The field's `capability`, checked against the post, and its
	 * `permission_callback`. Either one saying no is no.
	 *
	 * @param Field $field   The field.
	 * @param int   $post_id The post, or 0 for the screen as a whole.
	 *
	 * @return bool
	 */
	public static function permitted( Field $field, int $post_id = 0 ): bool {
		$capability = (string) $field->get( 'capability', '' );

		if ( '' !== $capability && ! ( $post_id > 0 ? current_user_can( $capability, $post_id ) : current_user_can( $capability ) ) ) {
			return false;
		}

		$callback = $field->get( 'permission_callback' );

		return ! is_callable( $callback ) || (bool) $callback( $field );
	}
}
