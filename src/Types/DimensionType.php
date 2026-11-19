<?php
/**
 * Dimension Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;

/**
 * A number and the unit it is in: 12 px, 2.5 kg, 30 cm.
 *
 * Stored together under the field's own key, as `[ 'value' => 2.5, 'unit'
 * => 'kg' ]`, so the one is never read without the other -- an amount type
 * keeps its unit under a second key because a discount's two halves were
 * stored apart long before it existed; a dimension has no such history.
 *
 * `units` is a list (`[ 'px', 'em', '%' ]`), a map of value to label
 * (`[ 'kg' => 'kilograms' ]`) or the name of a set: `length` for a
 * stylesheet, `size` for a parcel, `weight`. One unit draws as a suffix
 * rather than a choice of one. A unit that is not offered is read as the
 * default, so a stored "pt" on a field that has since dropped points comes
 * back as something the select can show.
 */
final class DimensionType extends AbstractType {

	/**
	 * The named sets of units.
	 *
	 * @var array<string, string[]>
	 */
	public const UNITS = [
		'length' => [ 'px', 'em', 'rem', '%', 'vw', 'vh' ],
		'size'   => [ 'cm', 'mm', 'm', 'in', 'ft' ],
		'weight' => [ 'kg', 'g', 'lb', 'oz' ],
	];

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'units' => 'length',
			'step'  => 'any',
		];
	}

	/**
	 * Render the number and its unit.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$value = $this->read( $field->value(), $field );
		$units = self::units( $field );
		$id    = (string) $attributes->get( 'id', '' );
		$name  = (string) $attributes->get( 'name', '' );

		$attributes->set( 'type', 'number' );
		$attributes->set( 'name', $name . '[value]' );
		$attributes->set( 'value', '' === $value['value'] ? '' : (string) $value['value'] );
		$attributes->set( 'inputmode', 'decimal' );
		$attributes->add_class( 'small-text', 'field-kit__dimension-value' );

		$attributes->set_if( $field->has( 'min' ), 'min', $field->get( 'min' ) );
		$attributes->set_if( $field->has( 'max' ), 'max', $field->get( 'max' ) );
		$attributes->set_if( $field->has( 'step' ), 'step', $field->get( 'step' ) );

		$input = sprintf( '<input%s />', $attributes->render() );

		if ( 1 === count( $units ) ) {
			$only = (string) array_key_first( $units );

			return sprintf(
				'<span class="field-kit__dimension">%s<span class="field-kit__dimension-unit field-kit__dimension-unit--fixed">%s</span><input type="hidden" name="%s" value="%s" /></span>',
				$input,
				esc_html( (string) $units[ $only ] ),
				esc_attr( $name . '[unit]' ),
				esc_attr( $only )
			);
		}

		$options = '';

		foreach ( $units as $unit => $label ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $unit ),
				(string) $unit === $value['unit'] ? ' selected' : '',
				esc_html( (string) $label )
			);
		}

		return sprintf(
			'<span class="field-kit__dimension">%s%s<select id="%s" name="%s" class="field-kit__dimension-unit"%s>%s</select></span>',
			$input,
			$this->sub_label( $id . '_unit', __( 'Unit', 'sugarcommerce' ), false ),
			esc_attr( $id . '_unit' ),
			esc_attr( $name . '[unit]' ),
			$attributes->has( 'disabled' ) ? ' disabled' : '',
			$options
		);
	}

	/**
	 * Coerce a submitted dimension.
	 *
	 * Nothing typed is nothing stored, whatever the unit select says: the
	 * select always sends something, and a unit on its own is not a size.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return array{value?: int|float, unit?: string}
	 */
	public function sanitize( mixed $value, Field $field ): array {
		$read = $this->read( $value, $field );

		if ( '' === $read['value'] ) {
			return [];
		}

		return $read;
	}

	/**
	 * A stored or submitted value as its two parts.
	 *
	 * Also reads the one-string form somebody might have stored before --
	 * "12px", "2.5 kg" -- so a field moved onto this type shows what was
	 * there.
	 *
	 * @param mixed $value The value.
	 * @param Field $field The field.
	 *
	 * @return array{value: int|float|string, unit: string}
	 */
	private function read( mixed $value, Field $field ): array {
		$units = self::units( $field );
		$unit  = '';

		if ( is_string( $value ) && 1 === preg_match( '/^\s*(-?\d+(?:\.\d+)?)\s*([^\d\s][^\s]*)?\s*$/u', $value, $match ) ) {
			$value = [
				'value' => $match[1],
				'unit'  => $match[2] ?? '',
			];
		}

		$number = is_array( $value ) ? ( $value['value'] ?? '' ) : $value;

		if ( is_array( $value ) && is_scalar( $value['unit'] ?? null ) ) {
			$unit = (string) $value['unit'];
		}

		if ( ! isset( $units[ $unit ] ) ) {
			$default = (string) $field->get( 'unit_default', '' );
			$unit    = isset( $units[ $default ] ) ? $default : (string) array_key_first( $units );
		}

		if ( ! is_numeric( $number ) ) {
			return [
				'value' => '',
				'unit'  => $unit,
			];
		}

		$number = (float) $number;

		if ( $field->has( 'min' ) && is_numeric( $field->get( 'min' ) ) ) {
			$number = max( (float) $field->get( 'min' ), $number );
		}

		if ( $field->has( 'max' ) && is_numeric( $field->get( 'max' ) ) ) {
			$number = min( (float) $field->get( 'max' ), $number );
		}

		return [
			'value' => floor( $number ) === $number ? (int) $number : $number,
			'unit'  => $unit,
		];
	}

	/**
	 * The units a field offers, value to label.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, string>
	 */
	public static function units( Field $field ): array {
		$units = $field->get( 'units', 'length' );

		if ( is_string( $units ) ) {
			$units = self::UNITS[ $units ] ?? [ $units ];
		}

		$offered = [];

		foreach ( (array) $units as $unit => $label ) {
			if ( is_int( $unit ) ) {
				$unit = (string) $label;
			}

			if ( '' !== (string) $unit ) {
				$offered[ (string) $unit ] = (string) $label;
			}
		}

		return [] === $offered ? [ 'px' => 'px' ] : $offered;
	}

	/**
	 * A dimension written as one string: "12px", "2.5 kg".
	 *
	 * A percentage and a CSS unit are written against the number; anything
	 * else -- a weight, a length on a parcel -- with a space.
	 *
	 * @param mixed $value A stored dimension.
	 *
	 * @return string
	 */
	public static function format( mixed $value ): string {
		if ( ! is_array( $value ) || ! is_numeric( $value['value'] ?? null ) ) {
			return '';
		}

		$unit = (string) ( $value['unit'] ?? '' );
		$css  = in_array( $unit, self::UNITS['length'], true );

		return $value['value'] . ( '' === $unit ? '' : ( $css ? '' : ' ' ) . $unit );
	}

	/**
	 * An object of the two parts.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	public function schema( Field $field ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'value' => [ 'type' => 'number' ],
				'unit'  => [
					'type' => 'string',
					'enum' => array_map( 'strval', array_keys( self::units( $field ) ) ),
				],
			],
		];
	}

	/**
	 * Configuration this type reads.
	 *
	 * @return string[]
	 */
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'units', 'unit_default', 'min', 'max', 'step' ] );
	}
}
