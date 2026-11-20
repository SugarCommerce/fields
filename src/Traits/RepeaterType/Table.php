<?php
/**
 * Repeater Field Type: Table
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\RepeaterType;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Renderer;

/**
 * A repeater drawn as a table: one head, one line per row.
 *
 * Part of RepeaterType: only the class uses it.
 */
trait Table {

	/**
	 * Whether this repeater is drawn as a table.
	 *
	 * A stack of rows is right when a row is a handful of fields with long
	 * labels. It is wrong when a row is three short columns repeated twenty
	 * times — a tax rate, a price tier, a redirect — where the labels belong
	 * once at the top and the rows want to line up under them.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	private function is_table( Field $field ): bool {
		return 'table' === (string) $field->get( 'layout', 'stacked' );
	}

	/**
	 * The column headers, from the row's own fields.
	 *
	 * Derived rather than configured, so the columns cannot drift from the
	 * fields underneath them.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	private function render_head( Field $field ): string {
		$cells = '';

		foreach ( $field->sub_fields() as $key => $config ) {
			$width = (string) ( $config['column_width'] ?? '' );

			$cells .= sprintf(
				'<th scope="col"%s>%s</th>',
				'' === $width ? '' : sprintf( ' style="width:%s"', esc_attr( $width ) ),
				esc_html( (string) ( $config['label'] ?? $key ) )
			);
		}

		// The actions column has no heading to give it, and an empty <th> is
		// announced as a blank column header rather than skipped.
		$cells .= sprintf(
			'<td class="field-kit__repeater-actions-head"><span class="screen-reader-text">%s</span></td>',
			esc_html__( 'Actions', 'sugarcommerce' )
		);

		return sprintf( '<thead><tr>%s</tr></thead>', $cells );
	}

	/**
	 * One row of a table repeater.
	 *
	 * @param Field                $field    The field.
	 * @param int                  $index    Row index.
	 * @param array<string, mixed> $row      Row values.
	 * @param string               $position Human row position.
	 *
	 * @return string
	 */
	private function render_table_row( Field $field, int $index, array $row, string $position ): string {
		$total = count( $this->rows( $field ) );
		$cells = '';

		foreach ( array_keys( $field->sub_fields() ) as $key ) {
			$child = $this->child(
				$field,
				(string) $key,
				(array) $field->sub_fields()[ $key ],
				$row[ $key ] ?? null,
				$field->input_name() . '[' . $index . ']',
				'row' . $index
			);

			$cells .= sprintf(
				'<td data-colname="%s">%s</td>',
				esc_attr( (string) ( $field->sub_fields()[ $key ]['label'] ?? $key ) ),
				// The column header is the label, so the renderer draws none
				// — but the control keeps its id and the header cell is not a
				// <label>, so each control carries its own hidden one.
				null === $child ? '' : ( new Renderer() )->render( $child, '', false )
			);
		}

		// No position cell: it would be a column the header does not have, and
		// every row action already carries the position in its own label.
		return sprintf(
			'<tr class="field-kit__repeater-row" data-index="%d">' .
			'%s' .
			'<td class="field-kit__repeater-actions">%s%s</td></tr>',
			$index,
			$cells,
			$this->row_handle( $index, $total ),
			$this->row_button( 'remove', $position, __( 'Remove', 'sugarcommerce' ), 'trash', false )
		);
	}
}
