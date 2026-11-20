<?php
/**
 * User Fields: Rendering
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\UserFields;

use SugarCommerce\Fields\Screens\Errors;
use SugarCommerce\Fields\Support\Badge;
use SugarCommerce\Fields\Support\Markup;
use SugarCommerce\Fields\Support\Sections;
use SugarCommerce\Fields\Support\Tooltip;
use WP_User;

/**
 * Drawing user fields on a profile and on the Add New User form.
 *
 * Part of UserFields: only the class uses it.
 */
trait Rendering {

	/**
	 * Render the fields on a profile screen.
	 *
	 * @param WP_User $user The user being edited.
	 *
	 * @return void
	 */
	public function render_profile( WP_User $user ): void {
		$fields = $this->visible_fields( $user->ID, 'profile' );

		if ( [] === $fields ) {
			return;
		}

		// What the last save of this profile refused, if this user made it
		// and the screen has not shown it yet.
		$errors = Errors::recall( 'user', $user->ID, $this->id );

		printf( '<h2>%s</h2>', esc_html( $this->section_title ) );

		echo Errors::notice( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		echo $this->render_sections( $fields, $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
	}

	/**
	 * Render the fields on the add-user screen.
	 *
	 * @param string $context Which add-user form is being drawn.
	 *
	 * @return void
	 */
	public function render_add_form( string $context ): void {
		// The other context is "add-existing-user" on multisite, which adds
		// an account that already has its meta.
		if ( 'add-new-user' !== $context ) {
			return;
		}

		$fields = $this->visible_fields( 0, 'add' );

		if ( [] === $fields ) {
			return;
		}

		printf( '<h2>%s</h2>', esc_html( $this->section_title ) );

		// No messages here: there is no user yet to have refused anything
		// for, and once there is, core sends the person to the list rather
		// than back to this form. A refused value waits for the new user's
		// profile screen.
		echo $this->render_sections( $fields, [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
	}

	/**
	 * Render fields, honouring any tab or accordion markers among them.
	 *
	 * Without this the markers arrived at render_row() as ordinary fields,
	 * drew nothing, and a set meant to be tabbed came out as one flat list.
	 *
	 * @param \SugarCommerce\Fields\Field[] $fields The fields.
	 * @param array<string, string>        $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private function render_sections( array $fields, array $errors ): string {
		$layout = Sections::split( $fields );

		if ( [] === $layout ) {
			return $this->render_table( $fields, $errors );
		}

		return Sections::render(
			$layout,
			fn( array $group ): string => [] === $group ? '' : $this->render_table( $group, $errors ),
			$this->id
		);
	}

	/**
	 * Render a set of fields as a settings table.
	 *
	 * A marker draws nothing, so one left in the list emits an empty row.
	 *
	 * @param \SugarCommerce\Fields\Field[] $fields The fields.
	 * @param array<string, string>        $errors Messages from the last save, keyed by field.
	 *
	 * @return string
	 */
	private function render_table( array $fields, array $errors ): string {
		ob_start();

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $fields as $field ) {
			if ( Sections::is_marker( $field ) ) {
				continue;
			}

			$this->render_row( $field, $errors );
		}

		echo '</tbody></table>';

		return (string) ob_get_clean();
	}

	/**
	 * Render one field as a table row.
	 *
	 * @param \SugarCommerce\Fields\Field $field  The field.
	 * @param array<string, string>      $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_row( $field, array $errors ): void {
		$type = $field->type();

		// A heading, a notice or a panel has no label to sit beside: a header
		// cell would indent it as though it labelled a control.
		if ( $type->spans_row() ) {
			printf(
				'<tr class="user-%s-wrap field-kit__spans-row"><td colspan="2">%s</td></tr>',
				esc_attr( $field->key() ),
				$this->set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			);

			return;
		}

		// A self-labelling control already carries its own <label for>, and a
		// group of controls has no single element to point at, so both get
		// plain text rather than a second label.
		$badge = Badge::for_field( $field );

		// Beside the heading, which this class draws rather than the kit.
		$tooltip = Tooltip::for_field( $field );

		$header = $type->is_self_labelling() || $type->is_grouped()
			? sprintf(
				'<span class="field-kit__row-label">%s</span>%s',
				esc_html( $field->label() ),
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
			'<tr class="user-%s-wrap"><th scope="row">%s</th><td>%s</td></tr>',
			esc_attr( $field->key() ),
			$header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr above.
			// The header cell is the visible heading on this screen, so the
			// kit draws none.
			$this->set->render_field( $field, $errors[ $field->key() ] ?? '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
		);
	}
}
