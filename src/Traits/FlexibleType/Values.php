<?php
/**
 * Flexible Content Field Type: Values
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\FlexibleType;

use SugarCommerce\Fields\Field;

/**
 * Reading, cleaning and checking a flexible field's rows.
 *
 * Part of FlexibleType: only the class uses it.
 */
trait Values {

	/**
	 * Stored rows, always in the stored shape.
	 *
	 * Anything that is not a row with a layout is left out, so a value
	 * written by hand, or by an older version of the configuration, cannot
	 * put a row without a layout in front of the code that draws them.
	 *
	 * @param mixed $value What the field stored.
	 *
	 * @return array<int, array{layout: string, values: array<string, mixed>}>
	 * @since 1.0.0
	 */
	public static function rows_of( mixed $value ): array {
		$rows = [];

		foreach ( is_array( $value ) ? $value : [] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$layout = sanitize_key( (string) ( $row['layout'] ?? '' ) );

			if ( '' !== $layout ) {
				$rows[] = [
					'layout' => $layout,
					'values' => is_array( $row['values'] ?? null ) ? $row['values'] : [],
				];
			}
		}

		return $rows;
	}

	/**
	 * Draw a stored value on the front of the site, a layout at a time.
	 *
	 * The field draws a form; what a page shows for a hero is the theme's
	 * business. So each row is handed to whatever draws its layout:
	 *
	 *     echo FlexibleType::display( $rows, [
	 *         'hero' => fn( array $values ) => '<h2>' . esc_html( $values['heading'] ?? '' ) . '</h2>',
	 *         'text' => fn( array $values ) => wp_kses_post( wpautop( $values['body'] ?? '' ) ),
	 *     ] );
	 *
	 * or to a template part named for it -- `parts/flexible-hero.php` for
	 * `FlexibleType::display( $rows, 'parts/flexible' )`, given `values`,
	 * `layout` and `index` as its `$args`:
	 *
	 *     echo FlexibleType::display( $rows, 'parts/flexible' );
	 *
	 * A callable is called with the row's values, its layout and its
	 * position. `*` in the list draws any layout the list does not name; a
	 * row nothing draws is left out. What is drawn is the drawer's to escape.
	 *
	 * @param mixed                           $value     What the field stored.
	 * @param array<string, callable>|string $renderers Callables by layout, or a template-part slug.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function display( mixed $value, array|string $renderers ): string {
		$markup = '';

		foreach ( self::rows_of( $value ) as $index => $row ) {
			if ( is_string( $renderers ) ) {
				ob_start();
				get_template_part( $renderers, $row['layout'], $row + [ 'index' => $index ] );
				$markup .= (string) ob_get_clean();

				continue;
			}

			$renderer = $renderers[ $row['layout'] ] ?? $renderers['*'] ?? null;

			if ( is_callable( $renderer ) ) {
				$markup .= (string) $renderer( $row['values'], $row['layout'], $index );
			}
		}

		return $markup;
	}

	/**
	 * Sanitise each row against the layout it is.
	 *
	 * A row of a known layout keeps only that layout's fields, each through
	 * its own type; one with nothing in it is dropped, as an empty repeater
	 * row is, unless its layout has nothing to fill in. A row of a layout
	 * that has gone is kept as text. Then the limits: a layout's rows past
	 * its maximum go, and rows past the field's.
	 *
	 * @param mixed $value Raw submitted rows.
	 * @param Field $field The field.
	 *
	 * @return array<int, array{layout: string, values: array<string, mixed>}>
	 * @since 1.0.0
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$layouts = $this->layouts( $field );
		$stored  = self::rows_of( $field->value() );
		$counts  = [];
		$rows    = [];

		foreach ( array_values( array_filter( $value, 'is_array' ) ) as $index => $row ) {
			$slug = sanitize_key( (string) ( $row['layout'] ?? '' ) );

			if ( '' === $slug ) {
				continue;
			}

			$values = is_array( $row['values'] ?? null ) ? $row['values'] : [];

			if ( ! isset( $layouts[ $slug ] ) ) {
				$rows[] = [
					'layout' => $slug,
					'values' => self::kept( $values ),
				];

				continue;
			}

			// What this row held before, where it was the same layout: a
			// type may consult its stored value as it sanitises.
			$before = isset( $stored[ $index ] ) && $stored[ $index ]['layout'] === $slug ? $stored[ $index ]['values'] : [];
			$clean  = $this->sanitize_children( $field, $values, $layouts[ $slug ]['fields'], $before );

			if ( [] !== $clean && ! $this->has_content( $clean ) ) {
				continue;
			}

			$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;

			if ( $layouts[ $slug ]['max'] > 0 && $counts[ $slug ] > $layouts[ $slug ]['max'] ) {
				continue;
			}

			$rows[] = [
				'layout' => $slug,
				'values' => $clean,
			];
		}

		return $this->apply_max_rows( $rows, $field );
	}

	/**
	 * The values of a row whose layout has gone, kept as text.
	 *
	 * @param array<string|int, mixed> $values The submitted values.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	private static function kept( array $values ): array {
		$kept = [];

		foreach ( $values as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$kept[ $key ] = self::kept( $value );
			} elseif ( is_scalar( $value ) ) {
				$kept[ $key ] = sanitize_textarea_field( (string) $value );
			}
		}

		return $kept;
	}

	/**
	 * Refuse a field short of a minimum: the field's, or a layout's.
	 *
	 * An empty field is a choice unless it is required, as with a gallery:
	 * the minimums apply once somebody has started.
	 *
	 * @param mixed $value The sanitized rows.
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	#[\Override]
	public function validate( mixed $value, Field $field ): string {
		$rows = self::rows_of( $value );

		if ( [] === $rows ) {
			return '';
		}

		$min = max( 0, (int) $field->get( 'min_rows', 0 ) );

		if ( count( $rows ) < $min ) {
			return sprintf(
				/* translators: 1: field label, 2: the fewest rows allowed */
				_n( '%1$s needs at least %2$d row.', '%1$s needs at least %2$d rows.', $min, 'sugarcommerce' ),
				$field->label(),
				$min
			);
		}

		$counts = array_count_values( array_column( $rows, 'layout' ) );

		foreach ( $this->layouts( $field ) as $slug => $layout ) {
			if ( ( $counts[ $slug ] ?? 0 ) < $layout['min'] ) {
				return sprintf(
					/* translators: 1: field label, 2: the fewest rows of the layout allowed, 3: the layout's name */
					_n( '%1$s needs at least %2$d “%3$s” row.', '%1$s needs at least %2$d “%3$s” rows.', $layout['min'], 'sugarcommerce' ),
					$field->label(),
					$layout['min'],
					$layout['label']
				);
			}
		}

		return '';
	}
}
