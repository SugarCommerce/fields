<?php
/**
 * Repeater Field Type: Rows
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\RepeaterType;

use SugarCommerce\Fields\Conditions;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Glyphs;

/**
 * A repeater's rows: plain, or collapsible with a title drawn from what they hold.
 *
 * Part of RepeaterType: only the class uses it.
 */
trait Rows {

	/**
	 * Render one row.
	 *
	 * @param Field                $field The field.
	 * @param int                  $index Row index.
	 * @param array<string, mixed> $row   Row values.
	 * @param int                  $total Total rows.
	 *
	 * @return string
	 */
	private function render_row( Field $field, int $index, array $row, int $total ): string {
		$position = sprintf(
			/* translators: 1: row number, 2: total rows */
			__( 'Row %1$d of %2$d', 'sugarcommerce' ),
			$index + 1,
			$total
		);

		if ( $this->is_table( $field ) ) {
			return $this->render_table_row( $field, $index, $row, $position );
		}

		// Scoped by row: without it every row reuses the same child ids and
		// each label after the first points at the wrong control.
		$fields = $this->render_children(
			$field,
			$row,
			$field->input_name() . '[' . $index . ']',
			'row' . $index,
			null,
			$this->labels_rows( $field )
		);

		$actions = $this->row_handle( $index, $total )
			. $this->row_button( 'remove', $position, __( 'Remove', 'sugarcommerce' ), 'trash', false );

		if ( $this->is_collapsible( $field ) ) {
			return $this->render_collapsible_row( $field, $index, $row, $position, $fields, $actions );
		}

		return sprintf(
			'<li class="field-kit__repeater-row" data-index="%d">' .
			'<span class="field-kit__repeater-position screen-reader-text">%s</span>' .
			'<div class="field-kit__repeater-fields">%s</div>' .
			'<div class="field-kit__repeater-actions">%s</div></li>',
			$index,
			esc_html( $position ),
			$fields,
			$actions
		);
	}

	/**
	 * A row that can be folded away, titled by one of its own fields.
	 *
	 * Three price tiers, each with a licence and its own files, is three
	 * screens of form to scroll past to reach the third. Collapsed, the same
	 * three are a list you can see at once -- which is also the only view
	 * that makes reordering them mean anything.
	 *
	 * The header is a button so the whole strip is the target rather than a
	 * chevron at the end of it, and the title doubles as the row's accessible
	 * name: `aria-describedby` on a "Show or hide" button is what tells a
	 * screen reader which row is being shown or hidden.
	 *
	 * @param Field                $field    The repeater.
	 * @param int                  $index    Zero-based row index.
	 * @param array<string, mixed> $row      The row's values.
	 * @param string               $position Human row position.
	 * @param string               $fields   The rendered children.
	 * @param string               $actions  The rendered row controls.
	 *
	 * @return string
	 */
	private function render_collapsible_row(
		Field $field,
		int $index,
		array $row,
		string $position,
		string $fields,
		string $actions
	): string {
		$closed   = $this->starts_closed( $field, $row );
		$title_id = $field->input_id() . '_row' . $index . '_title';
		$title    = sprintf(
			'<span class="field-kit__repeater-title" id="%1$s" data-fallback="%2$s">%3$s</span>',
			esc_attr( $title_id ),
			esc_attr( $this->position_title( $field, $index ) ),
			esc_html( $this->row_title( $field, $row, $index ) )
		);
		$described = $title_id;

		// What the row holds, after its name, for a list that is read
		// closed. Written by the script from the row's own controls -- on
		// load and as they change -- so it is never a second copy of what
		// the fields say that can disagree with them.
		if ( [] !== $this->summary( $field ) ) {
			$summary_id = $field->input_id() . '_row' . $index . '_summary';
			$described .= ' ' . $summary_id;
			$title      = sprintf(
				'<span class="field-kit__repeater-heading">%1$s<span class="field-kit__repeater-summary" id="%2$s"></span></span>',
				$title,
				esc_attr( $summary_id )
			);
		}

		return sprintf(
			'<li class="field-kit__repeater-row field-kit__repeater-row--collapsible%1$s" data-index="%2$d">' .
			'<span class="field-kit__repeater-position screen-reader-text">%3$s</span>' .
			'<div class="field-kit__repeater-header">' .
			'%7$s' .
			'<span class="field-kit__repeater-actions">%8$s</span>' .
			'<button type="button" class="field-kit__repeater-toggle" aria-expanded="%4$s" aria-describedby="%5$s">' .
			'<span class="screen-reader-text">%6$s</span>' .
			'<span class="field-kit__repeater-indicator" aria-hidden="true">%10$s</span>' .
			'</button>' .
			'</div>' .
			'<div class="field-kit__repeater-fields">%9$s</div></li>',
			$closed ? ' is-closed' : '',
			$index,
			esc_html( $position ),
			$closed ? 'false' : 'true',
			esc_attr( $described ),
			esc_html__( 'Show or hide this row', 'sugarcommerce' ),
			$title,
			$actions,
			$fields,
			Glyphs::get( 'up' )
		);
	}

	/**
	 * Whether rows fold away.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	protected function is_collapsible( Field $field ): bool {
		return (bool) $field->get( 'collapsible', false ) && ! $this->is_table( $field );
	}

	/**
	 * What a closed row says after its name, as the script is to write it.
	 *
	 * `summary` is a list of parts, joined with a middle dot. A part is a
	 * template -- "{amount} / {interval|lower}" -- or `text` and `when`:
	 * `text` one template or several, the first whose every `{key}` the row
	 * has a value for, and `when` a condition in show_when's shape. A key is
	 * a sub-field, read as it is shown: a choice by its label, an amount
	 * with its symbol; `|lower` lower-cases it. Empty and zero are nothing,
	 * so a part about a trial of zero days is left out rather than said.
	 *
	 * Templates, not a callback: the header follows the row's controls as
	 * they are changed, which a PHP callback could only do by asking the
	 * server on every keystroke. Read only for a collapsible row, the one
	 * kind with a closed state to summarise.
	 *
	 * @param Field $field The field.
	 *
	 * @return list<array{text: list<string>, when: array<int, array<string, mixed>>}>
	 */
	protected function summary( Field $field ): array {
		$parts = $field->get( 'summary', [] );

		if ( ! is_array( $parts ) ) {
			return [];
		}

		$summary = [];

		foreach ( $parts as $part ) {
			if ( is_string( $part ) ) {
				$part = [ 'text' => $part ];
			}

			if ( ! is_array( $part ) ) {
				continue;
			}

			$text = array_values(
				array_filter(
					array_map( 'strval', (array) ( $part['text'] ?? [] ) ),
					static fn( string $template ): bool => '' !== trim( $template )
				)
			);

			if ( [] === $text ) {
				continue;
			}

			$summary[] = [
				'text' => $text,
				'when' => Conditions::from( $part['when'] ?? [] )->to_array(),
			];
		}

		return $summary;
	}

	/**
	 * Whether a row is drawn closed.
	 *
	 * A row with nothing in it is drawn open whatever the setting says: one
	 * just added is empty, and folding it away hides the fields somebody
	 * added it to fill in.
	 *
	 * @param Field                $field The field.
	 * @param array<string, mixed> $row   The row's values.
	 *
	 * @return bool
	 */
	private function starts_closed( Field $field, array $row ): bool {
		return (bool) $field->get( 'collapsed', true ) && $this->has_content( $row );
	}

	/**
	 * What a collapsed row says.
	 *
	 * The field named by `row_title` where it holds anything, since that is
	 * what the row is called -- a price tier's name, a rate's country. The
	 * position otherwise, because a list of rows all saying "Untitled" is a
	 * list you cannot tell apart, and an empty header is a strip of nothing
	 * to click.
	 *
	 * @param Field                $field The field.
	 * @param array<string, mixed> $row   The row's values.
	 * @param int                  $index Zero-based row index.
	 *
	 * @return string
	 */
	private function row_title( Field $field, array $row, int $index ): string {
		$key = (string) $field->get( 'row_title', '' );

		if ( '' !== $key && isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== (string) $row[ $key ] ) {
			return (string) $row[ $key ];
		}

		return $this->position_title( $field, $index );
	}

	/**
	 * What a row is called while it has nothing to be called by.
	 *
	 * The field's own words where it has any: `row_title_fallback`. A new
	 * price tier is a "New price" until somebody names it, which says what
	 * the row is. Its position otherwise -- "Row 3" -- because a list of
	 * rows all saying "Untitled" is a list you cannot tell apart.
	 *
	 * Clamped, because the template the script clones is rendered at index
	 * -1 and read literally that is a row titled "Row 0".
	 *
	 * @param Field $field The field.
	 * @param int   $index Zero-based row index.
	 *
	 * @return string
	 */
	private function position_title( Field $field, int $index ): string {
		$fallback = trim( (string) $field->get( 'row_title_fallback', '' ) );

		if ( '' !== $fallback ) {
			return $fallback;
		}

		return sprintf(
			/* translators: %d: row number */
			__( 'Row %d', 'sugarcommerce' ),
			max( 1, $index + 1 )
		);
	}
}
