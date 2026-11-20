<?php
/**
 * Term Fields: Rendering
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\TermFields;

use SugarCommerce\Fields\Screens\Errors;
use SugarCommerce\Fields\Support\Badge;
use SugarCommerce\Fields\Support\Markup;
use SugarCommerce\Fields\Support\Sections;
use SugarCommerce\Fields\Support\Tooltip;
use WP_Term;

/**
 * Drawing a taxonomy's fields on its Add and Edit forms.
 *
 * Part of TermFields: only the class uses it.
 */
trait Rendering {

	/**
	 * Render the fields on the add-term screen.
	 *
	 * The add screen wraps each field in a div; the edit screen uses a table
	 * row. Everything inside the wrapper is identical, which is why only the
	 * wrapper differs here.
	 *
	 * @return void
	 */
	public function render_add_form(): void {
		wp_nonce_field( 'save_' . $this->id, $this->id . '_nonce' );

		// No messages here. The add form posts over AJAX and is never
		// reloaded, and a refused value belongs to the term that was just
		// created — its edit screen is where the message is read.
		$fields = $this->visible_fields();
		$layout = Sections::split( $fields );

		$render = function ( array $group ): string {
			$markup = '';

			foreach ( $group as $field ) {
				if ( Sections::is_marker( $field ) ) {
					continue;
				}

				$markup .= sprintf(
					'<div class="form-field term-%s-wrap">%s</div>',
					esc_attr( $field->key() ),
					// The kit escapes every value at the point it builds an
					// attribute, so this is already safe markup; a kses pass
					// here would only strip valid output.
					$this->set->render_field( $field ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- see comment.
				);
			}

			return $markup;
		};

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		echo [] === $layout ? $render( $fields ) : Sections::render( $layout, $render, $this->id . '-add' );
	}

	/**
	 * Render the fields on the edit-term screen.
	 *
	 * @param WP_Term $term     The term being edited.
	 * @param string  $taxonomy The taxonomy.
	 *
	 * @return void
	 */
	public function render_edit_form( WP_Term $term, string $taxonomy ): void {
		// Inside the table core opened, so the nonce gets a row of its own
		// rather than sitting loose between two.
		echo '<tr class="hidden"><td colspan="2">';
		wp_nonce_field( 'save_' . $this->id, $this->id . '_nonce' );
		echo '</td></tr>';

		// What the last save of this term refused, if this user made it and
		// the screen has not shown it yet. In a row of its own, for the same
		// reason the nonce is: these rows go into a table core opened.
		$errors = Errors::recall( 'term', $term->term_id, $this->id );

		if ( [] !== $errors ) {
			printf(
				'<tr class="form-field field-kit__spans-row"><td colspan="2">%s</td></tr>',
				Errors::notice( $errors ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			);
		}

		$fields = $this->visible_fields( $term->term_id );
		$layout = Sections::split( $fields );

		if ( [] !== $layout ) {
			/*
			 * These rows go into a table core opened, so a <details> or a tab
			 * strip cannot sit between them -- neither is table content, and
			 * a browser hoists it out of the table entirely. The whole set of
			 * sections therefore goes inside one row that spans both columns,
			 * with each section holding a form-table of its own. That is the
			 * same shape a spans_row() field already gets here.
			 */
			$sections = Sections::render(
				$layout,
				fn( array $group ): string => [] === $group ? '' : $this->render_edit_table( $group, $errors ),
				$this->id
			);

			printf(
				'<tr class="form-field field-kit__spans-row"><td colspan="2">%s</td></tr>',
				$sections // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
			);

			return;
		}

		$this->render_edit_rows( $fields, $errors );
	}

	/**
	 * One section's fields, as a table of their own.
	 *
	 * @param \SugarCommerce\Fields\Field[] $fields The fields.
	 * @param array<string, string>        $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private function render_edit_table( array $fields, array $errors ): string {
		ob_start();

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_edit_rows( $fields, $errors );
		echo '</tbody></table>';

		return (string) ob_get_clean();
	}

	/**
	 * The fields as table rows.
	 *
	 * @param \SugarCommerce\Fields\Field[] $fields The fields.
	 * @param array<string, string>        $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_edit_rows( array $fields, array $errors ): void {
		foreach ( $fields as $field ) {
			// A marker draws nothing, so one left in emits an empty row.
			if ( Sections::is_marker( $field ) ) {
				continue;
			}

			// A layout field or a panel has no label to sit beside: a header
			// cell would indent it as though it labelled a control.
			if ( $field->type()->spans_row() ) {
				printf(
					'<tr class="form-field field-kit__spans-row term-%s-wrap"><td colspan="2">%s</td></tr>',
					esc_attr( $field->key() ),
					$this->set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
				);

				continue;
			}

			// A self-labelling control — a checkbox or a toggle — already
			// carries its own <label for>. Adding a second one pointing at
			// the same control makes the field announce twice, so the header
			// cell gets plain text and the control keeps its own label.
			$labels_itself = $field->type()->is_self_labelling();

			// A group of controls has no single element to point at, so its
			// heading is the fieldset's legend rather than a label.
			$grouped = $field->type()->is_grouped();

			// A badge belongs beside the heading, and on this screen the
			// heading is here — the kit is told to draw none, so it draws no
			// badge either.
			$badge = Badge::for_field( $field );

			// Beside the heading, which this class draws rather than the kit.
			$tooltip = Tooltip::for_field( $field );

			$header = $labels_itself || $grouped
				? sprintf(
					'<span class="field-kit__row-label">%s%s%s</span>',
					esc_html( $field->label() ),
					Markup::required_marker( $field->is_required() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
					$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				)
				: sprintf(
					'<label for="%s">%s%s</label>%s',
					esc_attr( $field->input_id() ),
					esc_html( $field->label() ),
					Markup::required_marker( $field->is_required() ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
					$badge . $tooltip // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
				);

			printf(
				'<tr class="form-field term-%s-wrap"><th scope="row">%s</th><td>%s</td></tr>',
				esc_attr( $field->key() ),
				$header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr above.
				// The header cell is the visible heading on this screen, so
				// the kit draws none: a self-labelling control keeps its own
				// text beside the box, and a group keeps its legend but
				// hidden, so the grouping is still announced without the
				// heading appearing twice.
				$this->set->render_field( $field, $errors[ $field->key() ] ?? '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			);
		}
	}
}
