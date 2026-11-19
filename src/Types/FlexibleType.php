<?php
/**
 * Flexible Content Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Contracts\AdminOnly;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Glyphs;

/**
 * A repeater whose rows are not all the same shape.
 *
 * Where a repeater has one set of fields repeated, this has several named
 * layouts and each row is one of them: a hero, then two columns of text,
 * then a list of features, in whatever order and as many times as the page
 * wants. Each layout is its own set of fields, drawn, sanitised and checked
 * by the same engine as any other, so a colour in a layout is the colour
 * field and a repeater in a layout is the repeater.
 *
 * What is stored is a list of `[ 'layout' => 'hero', 'values' => [ … ] ]`,
 * the layout beside its values rather than mixed in with them: a layout
 * with a field of its own called `layout` is not far-fetched, and a row
 * read back should not have to be told apart from its own contents.
 *
 * A row whose layout is no longer configured is kept as it is, drawn as a
 * notice with its values carried in hidden inputs. Taking a layout out of
 * the configuration should not delete what somebody wrote with it --
 * putting the layout back brings it back.
 *
 * `min` and `max` on a layout bound how many rows of it there may be, and
 * `min_rows` and `max_rows` on the field how many in all. The buttons stop
 * at a maximum and the server keeps the first that many; a field short of a
 * minimum is refused with a message saying which layout it needs more of.
 *
 * Drawn on wp-admin's screens and in a flyout panel. On the front of the
 * site it is read rather than edited: {@see self::display()} hands each
 * row to the code or the template part that draws its layout.
 *
 * @since 1.0.0
 */
final class FlexibleType extends RepeaterType implements AdminOnly {

	/**
	 * The type's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function id(): string {
		return 'flexible';
	}

	/**
	 * Config defaults.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function defaults(): array {
		return array_merge(
			parent::defaults(),
			[
				'add_label'   => __( 'Add a row', 'sugarcommerce' ),
				'empty_label' => __( 'No rows yet. Add one of the layouts below.', 'sugarcommerce' ),
				'layouts'     => [],
			]
		);
	}

	/**
	 * The configured layouts, normalised.
	 *
	 * Keyed by a sanitised slug, each with its label, fields, limits and the
	 * field its rows are titled by. A layout with no fields is still a
	 * layout: a divider or a "related products" block may need nothing
	 * typed into it to be worth placing.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, array{label: string, fields: array<string, array<string, mixed>>, min: int, max: int, row_title: string}>
	 * @since 1.0.0
	 */
	public function layouts( Field $field ): array {
		$layouts = $field->get( 'layouts', [] );
		$clean   = [];

		foreach ( is_array( $layouts ) ? $layouts : [] as $slug => $layout ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || ! is_array( $layout ) ) {
				continue;
			}

			$clean[ $slug ] = [
				'label'     => (string) ( $layout['label'] ?? $slug ),
				'fields'    => is_array( $layout['fields'] ?? null ) ? $layout['fields'] : [],
				'min'       => max( 0, (int) ( $layout['min'] ?? 0 ) ),
				'max'       => max( 0, (int) ( $layout['max'] ?? 0 ) ),
				'row_title' => sanitize_key( (string) ( $layout['row_title'] ?? '' ) ),
			];
		}

		return $clean;
	}

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
	 * The rows, the templates, the empty message and the add buttons.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$layouts = $this->layouts( $field );

		if ( [] === $layouts ) {
			return sprintf(
				'<p class="field-kit__flexible-orphan notice notice-warning inline">%s</p>',
				esc_html__( 'This field has no layouts configured.', 'sugarcommerce' )
			);
		}

		$rows  = self::rows_of( $field->value() );
		$total = count( $rows );

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__repeater', 'field-kit__repeater--collapsible', 'field-kit__flexible' );
		$wrapper->set( 'data-field-name', $field->input_name() );
		$wrapper->set( 'data-min-rows', max( 0, (int) $field->get( 'min_rows', 0 ) ) );
		$wrapper->set( 'data-max-rows', max( 0, (int) $field->get( 'max_rows', 0 ) ) );

		$markup = '';

		foreach ( $rows as $index => $row ) {
			$markup .= $this->render_layout_row( $field, $index, $row, $total, $layouts );
		}

		$templates = '';

		foreach ( $layouts as $slug => $layout ) {
			// At an index no real row can hold, so the template's ids do not
			// collide with a drawn row's; the script renumbers its clone.
			$templates .= sprintf(
				'<template class="field-kit__repeater-template" data-layout="%s">%s</template>',
				esc_attr( $slug ),
				$this->render_layout_row(
					$field,
					-1,
					[
						'layout' => $slug,
						'values' => [],
					],
					0,
					$layouts
				)
			);
		}

		return sprintf(
			'<div%s><ol class="field-kit__repeater-rows" data-empty="%s">%s</ol>%s%s%s%s</div>',
			$wrapper->render(),
			$total > 0 ? 'false' : 'true',
			$markup,
			$this->render_empty_message( $field, $total ),
			$this->render_status(),
			$templates,
			$this->render_add_buttons( $field, $layouts, $rows )
		);
	}

	/**
	 * One row, drawn against the layout it is.
	 *
	 * The strip a collapsible repeater row wears -- the layout's name and the
	 * row's own title, the actions, the chevron -- with the layout's fields
	 * under it, named `{field}[{index}][values][{key}]` beside a hidden
	 * `{field}[{index}][layout]`.
	 *
	 * @param Field                                                $field   The field.
	 * @param int                                                  $index   Zero-based row index; -1 for a template.
	 * @param array{layout: string, values: array<string, mixed>} $row     The row.
	 * @param int                                                  $total   How many rows there are.
	 * @param array<string, array<string, mixed>>                  $layouts The configured layouts.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function render_layout_row( Field $field, int $index, array $row, int $total, array $layouts ): string {
		$slug   = $row['layout'];
		$known  = isset( $layouts[ $slug ] );
		$label  = $known ? (string) $layouts[ $slug ]['label'] : $slug;
		$prefix = $field->input_name() . '[' . $index . ']';

		$position = sprintf(
			/* translators: 1: row number, 2: how many rows, 3: the layout's name */
			__( 'Row %1$d of %2$d, %3$s', 'sugarcommerce' ),
			max( 1, $index + 1 ),
			max( 1, $total ),
			$label
		);

		$body = $known
			? $this->render_children( $field, $row['values'], $prefix . '[values]', 'row' . $index, $layouts[ $slug ]['fields'] )
			: $this->render_orphan( $slug, $row['values'], $prefix . '[values]' );

		$title_key = $known ? (string) $layouts[ $slug ]['row_title'] : '';
		$title     = '' !== $title_key && is_scalar( $row['values'][ $title_key ] ?? null ) ? (string) $row['values'][ $title_key ] : '';
		$closed    = $index >= 0 && (bool) $field->get( 'collapsed', true ) && $this->has_content( $row['values'] );
		$name_id   = $field->input_id() . '_row' . $index . '_name';

		$row_attributes = new Attributes();
		$row_attributes->add_class( 'field-kit__repeater-row', 'field-kit__repeater-row--collapsible', 'field-kit__flexible-row' );

		if ( $closed ) {
			$row_attributes->add_class( 'is-closed' );
		}

		$row_attributes->set( 'data-index', $index );
		$row_attributes->set( 'data-layout', $slug );
		$row_attributes->set_if( '' !== $title_key, 'data-row-title', $title_key );

		$actions = $this->row_handle( $index, $total )
			. $this->row_button( 'duplicate', $position, __( 'Duplicate', 'sugarcommerce' ), 'duplicate', ! $known )
			. $this->row_button( 'remove', $position, __( 'Remove', 'sugarcommerce' ), 'trash', false );

		return sprintf(
			'<li%1$s>' .
			'<span class="field-kit__repeater-position screen-reader-text">%2$s</span>' .
			'<input type="hidden" name="%3$s[layout]" value="%4$s" />' .
			'<div class="field-kit__repeater-header">' .
			'<span class="field-kit__flexible-name" id="%5$s"><span class="field-kit__flexible-badge">%6$s</span>' .
			'<span class="field-kit__repeater-title" data-fallback="">%7$s</span></span>' .
			'<span class="field-kit__repeater-actions">%8$s</span>' .
			'<button type="button" class="field-kit__repeater-toggle" aria-expanded="%9$s" aria-describedby="%5$s">' .
			'<span class="screen-reader-text">%10$s</span>' .
			'<span class="field-kit__repeater-indicator" aria-hidden="true">%11$s</span>' .
			'</button>' .
			'</div>' .
			'<div class="field-kit__repeater-fields">%12$s</div></li>',
			$row_attributes->render(),
			esc_html( $position ),
			esc_attr( $prefix ),
			esc_attr( $slug ),
			esc_attr( $name_id ),
			esc_html( $label ),
			esc_html( $title ),
			$actions,
			$closed ? 'false' : 'true',
			esc_html__( 'Show or hide this row', 'sugarcommerce' ),
			Glyphs::get( 'up' ),
			$body
		);
	}

	/**
	 * A row whose layout is no longer configured: a notice, and its values
	 * carried in hidden inputs so the next save keeps them.
	 *
	 * @param string               $slug   The missing layout.
	 * @param array<string, mixed> $values The row's stored values.
	 * @param string               $prefix The values' input name prefix.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function render_orphan( string $slug, array $values, string $prefix ): string {
		return sprintf(
			'<p class="field-kit__flexible-orphan notice notice-warning inline">%s</p>%s',
			esc_html(
				sprintf(
					/* translators: %s: the layout name stored on the row */
					__( 'The “%s” layout is no longer available. This row is kept as it is.', 'sugarcommerce' ),
					$slug
				)
			),
			$this->hidden_inputs( $values, $prefix )
		);
	}

	/**
	 * Hidden inputs that post a value back as it is, nested arrays and all.
	 *
	 * @param array<string, mixed> $values The values.
	 * @param string               $prefix Their input name prefix.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function hidden_inputs( array $values, string $prefix ): string {
		$markup = '';

		foreach ( $values as $key => $value ) {
			$name = $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				$markup .= $this->hidden_inputs( $value, $name );
			} elseif ( is_scalar( $value ) ) {
				$markup .= sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( (string) $value ) );
			}
		}

		return $markup;
	}

	/**
	 * The buttons that add a row of each layout.
	 *
	 * A button per layout while there are few of them, and a list with one
	 * Add beside it once a row of buttons stops being something to scan.
	 * Each carries its layout's maximum; the script turns a full layout's
	 * button off, and the whole lot off at the field's maximum.
	 *
	 * @param Field                                                    $field   The field.
	 * @param array<string, array<string, mixed>>                      $layouts The configured layouts.
	 * @param array<int, array{layout: string, values: array<string, mixed>}> $rows    The rows drawn.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function render_add_buttons( Field $field, array $layouts, array $rows ): string {
		$counts = array_count_values( array_column( $rows, 'layout' ) );
		$full   = (int) $field->get( 'max_rows', 0 ) > 0 && count( $rows ) >= (int) $field->get( 'max_rows', 0 );

		if ( count( $layouts ) > 4 ) {
			$options = '';

			foreach ( $layouts as $slug => $layout ) {
				$options .= sprintf(
					'<option value="%s" data-min="%d" data-max="%d"%s>%s</option>',
					esc_attr( $slug ),
					$layout['min'],
					$layout['max'],
					$layout['max'] > 0 && ( $counts[ $slug ] ?? 0 ) >= $layout['max'] ? ' disabled' : '',
					esc_html( $layout['label'] )
				);
			}

			return sprintf(
				'<p class="field-kit__flexible-add"><select class="field-kit__flexible-layout" aria-label="%s">%s</select>' .
				'<button type="button" class="button field-kit__repeater-add"%s>%s</button></p>',
				esc_attr__( 'Layout to add', 'sugarcommerce' ),
				$options,
				$full ? ' disabled' : '',
				esc_html( (string) $field->get( 'add_label', __( 'Add a row', 'sugarcommerce' ) ) )
			);
		}

		$buttons = '';

		foreach ( $layouts as $slug => $layout ) {
			$buttons .= sprintf(
				'<button type="button" class="button field-kit__repeater-add" data-layout="%s" data-min="%d" data-max="%d"%s>%s</button>',
				esc_attr( $slug ),
				$layout['min'],
				$layout['max'],
				$full || ( $layout['max'] > 0 && ( $counts[ $slug ] ?? 0 ) >= $layout['max'] ) ? ' disabled' : '',
				esc_html(
					sprintf(
						/* translators: %s: the layout's name */
						__( 'Add %s', 'sugarcommerce' ),
						$layout['label']
					)
				)
			);
		}

		return sprintf( '<p class="field-kit__flexible-add">%s</p>', $buttons );
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

	/**
	 * A list of rows, each a layout and its values.
	 *
	 * The values are an object whose shape depends on the layout, which a
	 * single schema cannot say without `oneOf` -- and WordPress rejects a
	 * valid value it cannot match against one. So it says what is certain.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function schema( Field $field ): array {
		return [
			'type'  => 'array',
			'items' => [
				'type'       => 'object',
				'properties' => [
					'layout' => [ 'type' => 'string' ],
					'values' => [
						'type'                 => 'object',
						'additionalProperties' => true,
					],
				],
			],
		];
	}

	/**
	 * Every layout's fields can need a script: a visual editor in a "text"
	 * layout is still a visual editor.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public static function layout_fields( array $config ): array {
		$fields = [];

		foreach ( is_array( $config['layouts'] ?? null ) ? $config['layouts'] : [] as $layout ) {
			if ( is_array( $layout ) && is_array( $layout['fields'] ?? null ) ) {
				$fields = array_merge( $fields, array_values( $layout['fields'] ) );
			}
		}

		return $fields;
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'layouts' ] );
	}
}
