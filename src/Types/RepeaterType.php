<?php
/**
 * Repeater Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Traits\RepeaterType\Rows;
use SugarCommerce\Fields\Traits\RepeaterType\Table;
use SugarCommerce\Fields\Traits\RepeaterType\Controls;

/**
 * A repeating set of fields.
 *
 * Rows are an ordered list, so their position is structural rather than only
 * visual, and each row carries move and remove buttons naming the row they
 * act on — a screen with several repeaters would otherwise present a column
 * of identical "Remove" buttons.
 *
 * Reordering is available from the keyboard as well as by dragging. A
 * drag-only repeater cannot be reordered without a mouse at all, which is the
 * half these controls usually skip.
 *
 * The row template is emitted once, disabled and hidden, rather than built by
 * string concatenation in the script: the markup then comes from the same
 * renderer as a real row, so a new row is accessible for the same reason an
 * existing one is.
 *
 * Two things decide the shape. `layout` is `stacked` — a bordered card per
 * row — or `table`, which is a real table with a header derived from the
 * fields. `direction` is which way a card's own fields run: `row` across, or
 * `column` down. A card of two short text fields wants across; a card with a
 * textarea or six fields in it is unreadable that way and wants down.
 *
 * `direction` does nothing to a table, where the columns are the direction.
 */
/*
 * Not final: FilesType is a repeater whose two columns are already decided,
 * and expressing that as a subclass is what stops it being a second copy of
 * the row, template and reindex logic.
 */
class RepeaterType extends AbstractNestedType {

	use Rows;
	use Table;
	use Controls;

	/**
	 * Config defaults.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function defaults(): array {
		return [
			'add_label' => __( 'Add row', 'sugarcommerce' ),
			'min_rows'  => 0,
			'max_rows'  => 0,
		];
	}

	/**
	 * Render the rows, the template and the add button.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		$rows  = $this->rows( $field );
		$total = count( $rows );

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__repeater' );
		$wrapper->set( 'data-field-name', $field->input_name() );
		$wrapper->set( 'data-min-rows', (int) $field->get( 'min_rows', 0 ) );
		$wrapper->set( 'data-max-rows', (int) $field->get( 'max_rows', 0 ) );

		// Which sub-field a collapsed row is named by, so the script can keep
		// the header in step with what is typed into it.
		if ( $this->is_collapsible( $field ) ) {
			$wrapper->add_class( 'field-kit__repeater--collapsible' );
			$wrapper->set_if(
				'' !== (string) $field->get( 'row_title', '' ),
				'data-row-title',
				(string) $field->get( 'row_title', '' )
			);
			$wrapper->set_if( [] !== $this->summary( $field ), 'data-row-summary', $this->summary( $field ) );
		}

		$markup = '';

		foreach ( $rows as $index => $row ) {
			$markup .= $this->render_row( $field, (int) $index, $row, $total );
		}

		// Which way a row's own fields run. Only meaningful for the card
		// layout — a table's columns are the direction.
		if ( 'column' === (string) $field->get( 'direction', 'row' ) && ! $this->is_table( $field ) ) {
			$wrapper->add_class( 'field-kit__repeater--column' );
		}

		// A one-column row is a single line rather than a card of fields, and
		// is laid out as one: its controls sit on the line's middle instead
		// of floating above the input they act on.
		if ( ! $this->is_table( $field ) && ! $this->labels_rows( $field ) ) {
			$wrapper->add_class( 'field-kit__repeater--single' );
		}

		if ( $this->is_table( $field ) ) {
			$wrapper->add_class( 'field-kit__repeater--table' );

			// `widefat striped` for the frame, and not `wp-list-table`: that
			// class is core's list screens', and under 782px core turns its
			// cells into label-and-value pairs -- each cell's data-colname
			// printed over the left of the cell, absolutely -- so "Rate" sat
			// on top of the number typed in it.
			//
			// The template goes inside the table. A <tr> inside a <template>
			// that sits in a <div> is dropped by the HTML parser — template
			// content is parsed in the context the template appears in — so
			// the row to clone would simply not be there.
			return sprintf(
				'<div%s><table class="widefat striped field-kit__repeater-table">%s' .
				'<tbody class="field-kit__repeater-rows" data-empty="%s">%s</tbody>%s%s</table>%s</div>',
				$wrapper->render(),
				$this->render_head( $field ),
				$total > 0 ? 'false' : 'true',
				$markup,
				// Inside the table, as core's own list tables put it: a
				// message below an empty frame reads as a table that failed
				// to load, and leaves a bare row of headers sitting over
				// nothing.
				$this->render_empty_row( $field, $total ),
				$this->render_template( $field ),
				$this->render_status() . $this->render_add_button( $field )
			);
		}

		return sprintf(
			'<div%s><ol class="field-kit__repeater-rows" data-empty="%s">%s</ol>%s%s%s%s</div>',
			$wrapper->render(),
			$total > 0 ? 'false' : 'true',
			$markup,
			$this->render_empty_message( $field, $total ),
			$this->render_status(),
			$this->render_template( $field ),
			$this->render_add_button( $field )
		);
	}

	/**
	 * A row's controls have one line to fit in.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	protected function child_config_extras(): array {
		return [ 'inline' => true ];
	}

	/**
	 * Whether a row's fields carry their own visible labels.
	 *
	 * They do when a row holds several — a name, a price and a limit need
	 * telling apart. They do not when a row holds one, because that label is
	 * the same word on every row and the field already has a heading above
	 * the whole list: a three-entry feature list drew "Item" three times,
	 * one line each, above three identical inputs.
	 *
	 * The control keeps the label as its accessible name either way; the
	 * renderer moves it to an aria-label when it is not drawn.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	protected function labels_rows( Field $field ): bool {
		return count( $field->sub_fields() ) > 1;
	}

	/**
	 * The current rows, always a list.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function rows( Field $field ): array {
		$value = $field->value();

		if ( ! is_array( $value ) ) {
			return [];
		}

		return array_values( array_filter( $value, 'is_array' ) );
	}

	/**
	 * Coerce a submitted value.
	 *
	 * Rows are reindexed, since removing a middle row leaves a gap in the
	 * posted keys, and a row whose every field is empty is dropped rather
	 * than stored as a blank record.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		// What each row already holds, by position. A type that consults its
		// stored value on the way in -- a licence that must not be overwritten
		// by its own mask -- needs the row it belongs to, not the whole list.
		$stored = (array) $field->value();
		$rows   = [];

		foreach ( $value as $index => $row ) {
			$clean = $this->sanitize_children( $field, $row, null, (array) ( $stored[ $index ] ?? [] ) );

			if ( $this->has_content( $clean ) ) {
				$rows[] = $clean;
			}
		}

		return $this->apply_max_rows( $rows, $field );
	}

	/**
	 * Cut the rows down to the configured maximum.
	 *
	 * The control refuses to add past `max_rows`, but the control is
	 * JavaScript and the rows arrive over HTTP. A limit enforced only in the
	 * browser is not a limit, so the extras are dropped here as well -- from
	 * the end, so the editor keeps what they entered first.
	 *
	 * @param array<int, array<string, mixed>> $rows  The sanitized rows.
	 * @param Field                            $field The field.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function apply_max_rows( array $rows, Field $field ): array {
		$max = (int) $field->get( 'max_rows', 0 );

		return $max > 0 ? array_slice( $rows, 0, $max ) : $rows;
	}

	/**
	 * Whether a sanitized row holds anything at all.
	 *
	 * @param array<string, mixed> $row Sanitized row.
	 *
	 * @return bool
	 */
	protected function has_content( array $row ): bool {
		foreach ( $row as $value ) {
			if ( is_array( $value ) ? [] !== $value : ( '' !== (string) $value && '0' !== (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Needs a fieldset and legend.
	 *
	 * @return bool
	 */
	#[\Override]
	public function is_grouped(): bool {
		return true;
	}

	/**
	 * Scripts and styles this needs.
	 *
	 * @return array{scripts: string[], styles: string[]}
	 */
	#[\Override]
	public function dependencies(): array {
		// No jquery-ui-sortable: reordering is native pointer and drag
		// events plus the move buttons, so pulling in a jQuery UI module
		// would be a dependency nothing calls.
		return [
			'scripts' => [],
			'styles'  => [],
		];
	}

	/**
	 * A list of rows, each the shape of the row's own fields.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function schema( Field $field ): array {
		return [
			'type'  => 'array',
			'items' => parent::schema( $field ),
		];
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'add_label', 'collapsed', 'collapsible', 'direction', 'empty_label', 'layout', 'max_rows', 'min_rows', 'row_title', 'row_title_fallback', 'summary' ]
		);
	}
}
