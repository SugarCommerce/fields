<?php
/**
 * Flexible Content Field Type: Layouts
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\FlexibleType;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Glyphs;

/**
 * Drawing a flexible field's rows, each in its own layout, and one Add per layout.
 *
 * Part of FlexibleType: only the class uses it.
 */
trait Layouts {

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
}
