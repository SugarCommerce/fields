<?php
/**
 * Address Field Type: Values
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\AddressType;

use SugarCommerce\Fields\Field;
use SugarCommerce\Geo\Addresses;
use SugarCommerce\Geo\Countries;
use SugarCommerce\Geo\Regions;

/**
 * Reading, cleaning, checking and formatting an address.
 *
 * Part of AddressType: only the class uses it.
 */
trait Values {

	/**
	 * A value as every part, each a string, with the country decided.
	 *
	 * @param mixed $value Stored or submitted.
	 * @param Field $field The field.
	 *
	 * @return array<string, string>
	 */
	private function read( mixed $value, Field $field ): array {
		$value   = is_array( $value ) ? $value : [];
		$read    = [];
		$offered = $this->countries( $field );

		foreach ( self::PARTS as $part ) {
			$read[ $part ] = is_scalar( $value[ $part ] ?? null ) ? sanitize_text_field( (string) $value[ $part ] ) : '';
		}

		$country = strtoupper( $read['country'] );

		if ( ! isset( $offered[ $country ] ) ) {
			$default = strtoupper( (string) $field->get( 'default_country', '' ) );
			$country = isset( $offered[ $default ] ) ? $default : '';
		}

		$read['country'] = $country;

		return $read;
	}

	/**
	 * Coerce a submitted address.
	 *
	 * Each part is text; the region is a code from the country's list where
	 * it has one, the postcode is written the country's way (`ec1y 8sy` is
	 * `EC1Y 8SY`), and a part the country -- or the field -- has no place for
	 * is dropped. An address with nothing in it but a country is no address.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return array<string, string>
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): array {
		$read    = $this->read( $value, $field );
		$country = $read['country'];
		$has     = '' === $country ? [
			'city'     => true,
			'state'    => true,
			'postcode' => true,
		] : Addresses::get_parts( $country );
		$shown   = $this->shown( $field );
		$address = [];

		foreach ( self::PARTS as $part ) {
			if ( 'country' === $part || ! in_array( $part, $shown, true ) || false === ( $has[ $part ] ?? true ) ) {
				continue;
			}

			$text = $read[ $part ];

			if ( 'state' === $part && Regions::has( $country ) ) {
				$text = (string) ( Regions::sanitize( $country, $text ) ?? '' );
			}

			if ( 'postcode' === $part && '' !== $country ) {
				$text = Addresses::normalize_postcode( $country, $text );
			}

			if ( '' !== $text ) {
				$address[ $part ] = $text;
			}
		}

		if ( [] === $address ) {
			return [];
		}

		$address['country'] = $country;

		return $address;
	}

	/**
	 * Refuse an address that is not finished, or a postcode that is not one.
	 *
	 * @param mixed $value The sanitized value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	public function validate( mixed $value, Field $field ): string {
		if ( ! is_array( $value ) || [] === $value ) {
			return '';
		}

		$country = (string) ( $value['country'] ?? '' );

		if ( '' === $country ) {
			return __( 'Choose the country this address is in.', 'sugarcommerce' );
		}

		$layout = self::layout( $country );
		$shown  = $this->shown( $field );
		$where  = Countries::get_name( $country );

		foreach ( $layout['lines'] as $line ) {
			foreach ( $line as $part ) {
				if ( empty( $layout['required'][ $part ] ) || ! in_array( $part, $shown, true ) || '' !== (string) ( $value[ $part ] ?? '' ) ) {
					continue;
				}

				return sprintf(
					/* translators: 1: an address part, such as "Postcode", 2: a country */
					__( '%1$s is needed for an address in %2$s.', 'sugarcommerce' ),
					$layout['labels'][ $part ],
					$where
				);
			}
		}

		$postcode = (string) ( $value['postcode'] ?? '' );

		if ( '' !== $postcode && false === Addresses::is_valid_postcode( $country, $postcode ) ) {
			$example = (string) strtok( $layout['postcode_example'], ',' );

			return sprintf(
				/* translators: 1: what the country calls a postcode, 2: a country, 3: an example */
				__( '%1$s is not in the form used in %2$s, such as %3$s.', 'sugarcommerce' ),
				$layout['labels']['postcode'],
				$where,
				$example
			);
		}

		return parent::validate( $value, $field );
	}

	/**
	 * The address as lines, in its country's order.
	 *
	 * @param mixed $value        A stored address.
	 * @param bool  $with_country Whether the country's name is the last line.
	 *
	 * @return string[]
	 */
	public static function format( mixed $value, bool $with_country = false ): array {
		return is_array( $value ) ? Addresses::format( $value, $with_country ) : [];
	}
}
