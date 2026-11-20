<?php
/**
 * Address Field Type: Parts
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\AddressType;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Support\Markup;
use SugarCommerce\Geo\Countries;

/**
 * Drawing an address's parts in the order and with the names its country uses.
 *
 * Part of AddressType: only the class uses it.
 */
trait Parts {

	/**
	 * The parts a field shows, in stored order.
	 *
	 * @param Field $field The field.
	 *
	 * @return string[]
	 */
	private function shown( Field $field ): array {
		$extra = array_map( 'strval', (array) $field->get( 'parts', [] ) );

		return array_values(
			array_filter(
				self::PARTS,
				static fn( string $part ): bool => match ( $part ) {
					'name', 'company' => in_array( $part, $extra, true ),
					'line2'           => (bool) $field->get( 'line2', true ),
					default           => true,
				}
			)
		);
	}

	/**
	 * The country select.
	 *
	 * @param Field                $field    The field.
	 * @param string               $current  The chosen country.
	 * @param array<string, mixed> $layout   The country's layout.
	 * @param string               $id       The field's id.
	 * @param string               $name     The field's name.
	 * @param bool                 $required Whether the field is required.
	 * @param bool                 $disabled Whether it is locked.
	 *
	 * @return string
	 */
	private function country( Field $field, string $current, array $layout, string $id, string $name, bool $required, bool $disabled ): string {
		$options = '' === $current || ! $required
			? sprintf( '<option value="">%s</option>', esc_html__( 'Select a country', 'sugarcommerce' ) )
			: '';

		foreach ( $this->countries( $field ) as $code => $label ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $code ),
				$code === $current ? ' selected' : '',
				esc_html( $label )
			);
		}

		$select = new Attributes();
		$select->set( 'id', $id . '_country' );
		$select->set( 'name', $name . '[country]' );
		$select->add_class( 'field-kit__address-country' );
		$select->set( 'autocomplete', $this->autocomplete( $field, 'country' ) );
		$select->set_if( $required, 'required', true );
		$select->set_if( $disabled, 'disabled', true );

		return sprintf(
			'<div class="field-kit__address-part field-kit__address-part--country" data-part="country"><label for="%s">%s%s</label><select%s>%s</select></div>',
			esc_attr( $id . '_country' ),
			esc_html( $layout['labels']['country'] ),
			Markup::required_marker( $required ),
			$select->render(),
			$options
		);
	}

	/**
	 * One part: its label and its box, or for a region a list and a box.
	 *
	 * @param Field                $field    The field.
	 * @param string               $part     The part.
	 * @param array<string, mixed> $value    The address.
	 * @param array<string, mixed> $layout   The country's layout.
	 * @param string               $id       The field's id.
	 * @param string               $name     The field's name.
	 * @param bool                 $required Whether the field is required.
	 * @param bool                 $disabled Whether it is locked.
	 * @param bool                 $spare    Whether the country has no place for it.
	 *
	 * @return string
	 */
	private function part( Field $field, string $part, array $value, array $layout, string $id, string $name, bool $required, bool $disabled, bool $spare ): string {
		$must  = $required && ! empty( $layout['required'][ $part ] );
		$label = (string) $layout['labels'][ $part ];
		$input = new Attributes();

		$input->set( 'id', $id . '_' . $part );
		$input->set( 'name', $name . '[' . $part . ']' );
		$input->add_class( 'field-kit__address-input', 'field-kit__address-input--' . $part );
		$input->set( 'autocomplete', $this->autocomplete( $field, $part ) );
		$input->set_if( $must && ! $spare, 'required', true );
		$input->set_if( $disabled || $spare, 'disabled', true );

		$control = '';

		if ( 'state' === $part ) {
			$control = $this->state( $input, (string) $value['state'], $layout['regions'] );
		} else {
			$input->set( 'type', 'text' );
			$input->set( 'value', (string) $value[ $part ] );

			if ( 'postcode' === $part ) {
				$input->set( 'data-pattern', (string) $layout['postcode_pattern'] );
				$input->set( 'spellcheck', 'false' );
				$input->set_if( '' !== $layout['postcode_example'], 'placeholder', (string) strtok( (string) $layout['postcode_example'], ',' ) );
			}

			$control = sprintf( '<input%s />', $input->render() );
		}

		return sprintf(
			'<div class="field-kit__address-part field-kit__address-part--%1$s" data-part="%1$s"%2$s><label for="%3$s">%4$s<span class="field-kit__address-marker">%5$s</span></label>%6$s</div>',
			esc_attr( $part ),
			$spare ? ' hidden' : '',
			esc_attr( $id . '_' . $part ),
			esc_html( $label ),
			Markup::required_marker( $must ),
			$control
		);
	}

	/**
	 * The region: a list where the country has one, a box where it does not.
	 *
	 * Both are drawn and one is switched off, as the region field does, so
	 * a change of country swaps them without rebuilding the label.
	 *
	 * @param Attributes                                $input   The part's attributes.
	 * @param string                                    $current The stored region.
	 * @param array<int, array{id: string, text: string}> $regions The country's regions.
	 *
	 * @return string
	 */
	private function state( Attributes $input, string $current, array $regions ): string {
		$listed  = [] !== $regions;
		$select  = clone $input;
		$text    = clone $input;
		$options = sprintf( '<option value="">%s</option>', esc_html__( 'Select a region', 'sugarcommerce' ) );

		foreach ( $regions as $region ) {
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $region['id'] ),
				$region['id'] === $current ? ' selected' : '',
				esc_html( $region['text'] )
			);
		}

		$select->add_class( 'field-kit__address-state-select' );
		$text->add_class( 'field-kit__address-state-text' );
		$text->set( 'type', 'text' );
		$text->set( 'value', $listed ? '' : $current );

		if ( $listed ) {
			$text->set( 'disabled', true );
			$text->set( 'hidden', true );
			$text->remove( 'id' );
		} else {
			$select->set( 'disabled', true );
			$select->set( 'hidden', true );
			$select->remove( 'id' );
		}

		return sprintf( '<select%s>%s</select><input%s />', $select->render(), $options, $text->render() );
	}

	/**
	 * The autocomplete token for a part, in the field's section.
	 *
	 * `'autocomplete' => 'shipping'` makes the street `shipping
	 * address-line1`, so a browser keeps a billing and a shipping address on
	 * one page apart.
	 *
	 * @param Field  $field The field.
	 * @param string $part  The part.
	 *
	 * @return string
	 */
	private function autocomplete( Field $field, string $part ): string {
		$section = sanitize_key( (string) $field->get( 'autocomplete', '' ) );

		if ( 'off' === $section ) {
			return 'off';
		}

		return trim( ( in_array( $section, [ 'shipping', 'billing' ], true ) ? $section . ' ' : '' ) . self::AUTOCOMPLETE[ $part ] );
	}

	/**
	 * The countries a field offers, code to name.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, string>
	 */
	private function countries( Field $field ): array {
		$all     = Countries::all();
		$allowed = array_map( 'strtoupper', array_map( 'strval', (array) $field->get( 'countries', [] ) ) );

		return [] === $allowed ? $all : array_intersect_key( $all, array_flip( $allowed ) );
	}
}
