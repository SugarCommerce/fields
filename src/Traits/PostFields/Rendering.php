<?php
/**
 * Post Fields: Rendering
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\PostFields;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Screens\BlockEditorPanel;
use SugarCommerce\Fields\Screens\Errors;
use SugarCommerce\Fields\Support\Badge;
use SugarCommerce\Fields\Support\Markup;
use SugarCommerce\Fields\Support\PanelTabs;
use SugarCommerce\Fields\Support\Sections;
use SugarCommerce\Fields\Support\Tooltip;
use WP_Post;

/**
 * Drawing a metabox's fields: in sections, in a table, or behind the panel tabs.
 *
 * Part of PostFields: only the class uses it.
 */
trait Rendering {

	/**
	 * Render the metabox.
	 *
	 * @param WP_Post $post The post being edited.
	 *
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		wp_nonce_field( 'save_' . $this->id, $this->id . '_nonce' );

		// What the last save of this post refused, if this user made it and
		// the screen has not shown it yet. Read here, once, so the notice
		// and the field markers agree.
		$errors = Errors::recall( 'post', $post->ID, $this->id );

		// The fields the sidebar draws, which this box leaves out. The box
		// says so in its own submission: the block editor posts it after the
		// REST save, without the sidebar's fields, and a save that read them
		// as missing would clear every one.
		$skip = $this->sidebar_keys( $post->post_type );

		if ( null !== $this->panel && BlockEditorPanel::on_screen() ) {
			printf( '<input type="hidden" name="%s" value="1" />', esc_attr( $this->id . '_block_editor' ) );
		}

		printf( '<div class="field-kit__metabox" data-metabox-id="%s">', esc_attr( $this->id ) );

		echo Errors::notice( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.

		if ( [] !== (array) $this->config['panels'] ) {
			echo $this->render_panels( $post, $errors, $skip ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		} else {
			echo $this->render_sections( $this->visible_fields( $post->ID, $skip ), $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		}

		echo '</div>';
	}

	/**
	 * Render the metabox as tabbed panels.
	 *
	 * A panel with nothing in it — every field in it hidden by a capability —
	 * is dropped rather than shown empty. A tab that opens onto nothing reads
	 * as a screen that failed to load.
	 *
	 * @param WP_Post               $post   The post being edited.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 * @param string[]              $skip   Fields drawn somewhere else.
	 *
	 * @return string
	 */
	private function render_panels( WP_Post $post, array $errors, array $skip = [] ): string {
		$visible = [];

		foreach ( $this->visible_fields( $post->ID, $skip ) as $field ) {
			$visible[ $field->key() ] = $field;
		}

		$panels = [];

		foreach ( (array) $this->config['panels'] as $slug => $panel ) {
			$keys = array_map(
				fn( $key ) => (string) $this->config['prefix'] . $key,
				array_keys( (array) ( $panel['fields'] ?? [] ) )
			);

			$in_panel = array_intersect_key( $visible, array_flip( $keys ) );

			if ( [] === $in_panel ) {
				continue;
			}

			// No icon: a panel is a tab, a tab is its label, and the kit
			// draws none.
			$panels[ (string) $slug ] = [
				'label'   => (string) ( $panel['label'] ?? $slug ),
				'content' => $this->render_table( array_values( $in_panel ), $errors ),
			];
		}

		return PanelTabs::render( $this->id, $panels );
	}

	/**
	 * Render fields, honouring any tab or accordion markers among them.
	 *
	 * `panels` is this class's own way of tabbing a metabox: the caller
	 * names the panels and lists which fields go in each. A `tab` or
	 * `accordion` field is the kit's way, and divides the list in place.
	 * Both end up as the same tab strip; they are different ways of saying
	 * it, and a metabox uses one or the other.
	 *
	 * Without this the markers arrived here as ordinary fields, rendered
	 * nothing, and a tabbed metabox came out as one flat list.
	 *
	 * @param Field[]               $fields The fields.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
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
	 * @param Field[]               $fields The fields.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
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
	 * @param Field                 $field  The field.
	 * @param array<string, string> $errors Messages from the last save, keyed by field.
	 *
	 * @return void
	 */
	private function render_row( Field $field, array $errors ): void {
		$type = $field->type();

		// A heading, a notice or a panel has no label to sit beside: a header
		// cell would indent it as though it labelled a control.
		if ( $type->spans_row() ) {
			printf(
				'<tr class="field-kit__metabox-row"><td colspan="2">%s</td></tr>',
				$this->set->render_field( $field, '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
			);

			return;
		}

		$badge = Badge::for_field( $field );

		// Beside the heading, which this class draws rather than the kit.
		$tooltip = Tooltip::for_field( $field );

		// A self-labelling control already carries its own <label for>, and a
		// group of controls has no single element to point at, so both get
		// plain text rather than a second label.
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
			'<tr class="field-kit__metabox-row"><th scope="row">%s</th><td>%s</td></tr>',
			$header, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html/esc_attr above.
			$this->set->render_field( $field, $errors[ $field->key() ] ?? '', false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the kit escapes as it builds.
		);
	}
}
