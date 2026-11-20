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
use SugarCommerce\Fields\Traits\FlexibleType\Layouts;
use SugarCommerce\Fields\Traits\FlexibleType\Values;

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

	use Layouts;
	use Values;

	/**
	 * The type's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	#[\Override]
	public function id(): string {
		return 'flexible';
	}

	/**
	 * Config defaults.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	#[\Override]
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
	 * The rows, the templates, the empty message and the add buttons.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	#[\Override]
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
	#[\Override]
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
	#[\Override]
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'layouts' ] );
	}
}
