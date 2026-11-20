<?php
/**
 * Repeater Field Type: Controls
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\RepeaterType;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Glyphs;

/**
 * A repeater's controls: the move handle and buttons, the empty state, the row template and Add.
 *
 * Part of RepeaterType: only the class uses it.
 */
trait Controls {

	/**
	 * The grab handle, which is also the keyboard's way to reorder.
	 *
	 * One control rather than the pair of chevrons this replaced. Those were
	 * two buttons doing one job, they took a click per position so moving a
	 * row four places took four, and the top and bottom rows each had a
	 * permanently disabled one sitting in the actions column.
	 *
	 * A button and not a decorative span: dragging cannot be done from a
	 * keyboard at all, so a handle that is only draggable makes the list
	 * unorderable without a pointer. Focus it and press the arrow keys.
	 *
	 * The position is in the accessible name because a handle that only says
	 * "Reorder row" gives no feedback that anything happened; announcing
	 * "3 of 4" is how a keyboard user knows the move landed. It is kept as a
	 * template so the script can rewrite the numbers after a move without a
	 * second copy of the wording to translate.
	 *
	 * @param int $index Zero-based row index.
	 * @param int $total How many rows there are.
	 *
	 * @return string
	 */
	protected function row_handle( int $index, int $total ): string {
		$handle = new Attributes();
		$handle->set( 'type', 'button' );
		$handle->add_class( 'field-kit__drag-handle', 'field-kit__repeater-handle' );
		$handle->set( 'aria-roledescription', __( 'Sortable', 'sugarcommerce' ) );
		$handle->set( 'aria-keyshortcuts', 'ArrowUp ArrowDown' );

		$template = sprintf(
			/* translators: 1: row position, 2: how many rows there are */
			__( 'Reorder row, %1$s of %2$s', 'sugarcommerce' ),
			'{position}',
			'{total}'
		);

		$handle->set( 'data-label-template', $template );
		$handle->set(
			'aria-label',
			strtr(
				$template,
				[
					'{position}' => (string) ( $index + 1 ),
					'{total}'    => (string) $total,
				]
			)
		);

		return sprintf(
			'<button%s>%s</button>',
			$handle->render(),
			Glyphs::get( 'drag' )
		);
	}

	/**
	 * A per-row action button.
	 *
	 * A bin rather than a cross: in a collapsible header the cross sits
	 * beside the control that folds the row away, and the two read as the
	 * same thing. One of them destroys the row.
	 *
	 * @param string $action   Action name for the script.
	 * @param string $position Human row position, for the accessible label.
	 * @param string $label    Action label.
	 * @param string $icon     A key of Glyphs::DEFAULTS.
	 * @param bool   $disabled Whether the action is available.
	 *
	 * @return string
	 */
	protected function row_button( string $action, string $position, string $label, string $icon, bool $disabled ): string {
		$button = new Attributes();
		$button->set( 'type', 'button' );
		$button->add_class( 'button-link', 'field-kit__repeater-' . $action );
		$button->set( 'data-action', $action );
		$button->set_if( $disabled, 'disabled', true );
		$button->set(
			'aria-label',
			sprintf(
				/* translators: 1: action such as "Remove", 2: row position such as "Row 2 of 5" */
				__( '%1$s — %2$s', 'sugarcommerce' ),
				$label,
				$position
			)
		);

		return sprintf(
			'<button%s>%s</button>',
			$button->render(),
			Glyphs::get( $icon )
		);
	}

	/**
	 * The empty state, as a row of the table itself.
	 *
	 * core writes `<tr class="no-items"><td class="colspanchange" colspan="N">`
	 * and so does this. A message printed under the table instead leaves a
	 * row of column headings sitting over nothing at all, which reads as a
	 * table that failed to load rather than one with nothing in it yet.
	 *
	 * It stays in the markup and is hidden once there is a row, so the script
	 * has something to show again when the last row is removed.
	 *
	 * @param Field $field The field.
	 * @param int   $total How many rows there are.
	 *
	 * @return string
	 */
	private function render_empty_row( Field $field, int $total ): string {
		return sprintf(
			'<tbody class="field-kit__repeater-empty"%s><tr class="no-items">' .
			'<td class="colspanchange" colspan="%d">%s</td></tr></tbody>',
			$total > 0 ? ' hidden' : '',
			// One per sub-field, plus the actions column.
			count( $field->sub_fields() ) + 1,
			esc_html( (string) $field->get( 'empty_label', __( 'No rows yet.', 'sugarcommerce' ) ) )
		);
	}

	/**
	 * The live region that says what adding and removing a row did.
	 *
	 * Its own element rather than the empty message, which is hidden the
	 * moment a row exists -- and a hidden live region announces nothing.
	 * Nor the first `[aria-live]` inside the repeater, which on a row that
	 * holds a range or a tags field is that field's own readout.
	 *
	 * @return string
	 */
	protected function render_status(): string {
		return '<span class="field-kit__repeater-status screen-reader-text" aria-live="polite"></span>';
	}

	/**
	 * The message shown when there are no rows.
	 *
	 * Hidden, not removed, once a row exists, so the script can show it
	 * again when the last row goes. It is not a live region: the status
	 * element beside it does the announcing, because a hidden region
	 * announces nothing and this one is hidden precisely when a row is
	 * added.
	 *
	 * In the field's own words where it has any. The table layout has always
	 * used `empty_label`; this one printed "No rows yet." whatever the field
	 * said, so a list of features and a list of files both described
	 * themselves as rows.
	 *
	 * @param Field $field The field.
	 * @param int   $total How many rows there are.
	 *
	 * @return string
	 */
	protected function render_empty_message( Field $field, int $total ): string {
		return sprintf(
			'<p class="field-kit__repeater-empty description"%s>%s</p>',
			$total > 0 ? ' hidden' : '',
			esc_html( (string) $field->get( 'empty_label', __( 'No rows yet.', 'sugarcommerce' ) ) )
		);
	}

	/**
	 * The template a new row is cloned from.
	 *
	 * Inside a `<template>`, so its inputs are never submitted and never
	 * reachable by tab, and rendered through the ordinary renderer so a new
	 * row is built the same way an existing one is.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	private function render_template( Field $field ): string {
		// Rendered at an index no real row can occupy, so the template's own
		// ids never collide with a rendered row's. The script renumbers them
		// when it clones the row.
		return sprintf(
			'<template class="field-kit__repeater-template">%s</template>',
			$this->render_row( $field, -1, [], 0 )
		);
	}

	/**
	 * The add button.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	private function render_add_button( Field $field ): string {
		$button = new Attributes();
		$button->set( 'type', 'button' );
		$button->add_class( 'button', 'field-kit__repeater-add' );

		return sprintf(
			'<p><button%s>%s</button></p>',
			$button->render(),
			esc_html( (string) $field->get( 'add_label', __( 'Add row', 'sugarcommerce' ) ) )
		);
	}
}
