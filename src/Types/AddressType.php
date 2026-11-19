<?php
/**
 * Address Field Type
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
use SugarCommerce\Fields\Support\Markup;
use SugarCommerce\Geo\Addresses;
use SugarCommerce\Geo\Countries;
use SugarCommerce\Geo\Regions;

/**
 * A postal address that is laid out the way its country writes one.
 *
 * Country first, because it decides the rest: which boxes there are (Hong
 * Kong has no postcode, Ireland no state), what they are called (a State
 * and a ZIP code, a County and a Postcode, a Prefecture), which of them an
 * address there must have, whether the region is chosen from a list or
 * typed, which boxes share a line ("Austin, TX 78701" puts three on one),
 * and what a postcode there looks like. All of it is `sugarcommerce/geo`'s,
 * made from the data Google publishes for Chrome's own address forms.
 *
 * Changing the country moves the boxes, renames them, swaps the region list
 * and starts the region again; the page asks the address endpoint for the
 * new country, which is public, so a checkout works for somebody with no
 * login. Without the script the form still works: the boxes stay as they
 * were drawn and the server checks what is sent against the country that
 * was chosen.
 *
 * Stored as one array -- `line1`, `line2`, `city`, `state`, `postcode`,
 * `country`, and `name` and `company` where `parts` asks for them -- the
 * shape `Addresses::format()` takes, so printing it on a receipt is one
 * call. `state` is a region code where the country has a list. A part the
 * country's addresses do not have is not kept: a state typed for Germany is
 * dropped rather than stored and printed somewhere it does not belong.
 *
 * An address that has been started has to be finished: whatever the
 * country requires, and a postcode in the country's own pattern where geo
 * knows it, are checked on save whether or not the field is required.
 * `required` makes an empty one a refusal too, and marks the parts.
 */
final class AddressType extends AbstractType {

	/**
	 * Every part, in the order an address is stored.
	 */
	public const PARTS = [ 'name', 'company', 'line1', 'line2', 'city', 'state', 'postcode', 'country' ];

	/**
	 * What a browser autofills into each part.
	 */
	private const AUTOCOMPLETE = [
		'name'     => 'name',
		'company'  => 'organization',
		'line1'    => 'address-line1',
		'line2'    => 'address-line2',
		'city'     => 'address-level2',
		'state'    => 'address-level1',
		'postcode' => 'postal-code',
		'country'  => 'country',
	];

	/**
	 * The part each letter of a format stands for. The street is two boxes.
	 */
	private const LETTERS = [
		'N' => [ 'name' ],
		'O' => [ 'company' ],
		'A' => [ 'line1', 'line2' ],
		'C' => [ 'city' ],
		'S' => [ 'state' ],
		'Z' => [ 'postcode' ],
	];

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return [
			'parts'           => [],
			'line2'           => true,
			'default_country' => '',
		];
	}

	/**
	 * How an address is written in a country, for the form.
	 *
	 * The lines, in order, with the parts on each (`line1` and `line2` are a
	 * line each wherever the street goes), the three names that change, which
	 * parts are required, the regions to choose from, and the postcode's
	 * pattern and an example. An empty or unknown country has every part,
	 * one to a line, under general names.
	 *
	 * @param string $country Country code, or empty.
	 *
	 * @return array{country: string, lines: array<int, string[]>, labels: array<string, string>, required: array<string, bool>, regions: array<int, array{id: string, text: string}>, postcode_pattern: string, postcode_example: string}
	 */
	public static function layout( string $country ): array {
		$country = (string) ( Countries::sanitize( $country ) ?? '' );
		$lines   = [];

		foreach ( explode( '%n', Addresses::get_format( $country ) ) as $line ) {
			preg_match_all( '/%([NOACSZ])/', $line, $letters );

			$parts = [];

			foreach ( $letters[1] as $letter ) {
				if ( 'A' === $letter ) {
					// The street is two lines of its own, whatever shares
					// its line in the format.
					if ( [] !== $parts ) {
						$lines[] = $parts;
						$parts   = [];
					}

					$lines[] = [ 'line1' ];
					$lines[] = [ 'line2' ];

					continue;
				}

				$parts = array_merge( $parts, self::LETTERS[ $letter ] );
			}

			if ( [] !== $parts ) {
				$lines[] = $parts;
			}
		}

		$required = '' === $country
			? [
				'street'   => true,
				'city'     => true,
				'state'    => false,
				'postcode' => false,
			]
			: Addresses::get_required( $country );

		$regions = [];

		foreach ( Regions::for( $country ) as $code => $name ) {
			$regions[] = [
				'id'   => (string) $code,
				'text' => (string) $name,
			];
		}

		$labels = Addresses::get_labels( $country );

		return [
			'country'          => $country,
			'lines'            => $lines,
			'labels'           => [
				'name'     => __( 'Full name', 'sugarcommerce' ),
				'company'  => __( 'Company', 'sugarcommerce' ),
				'line1'    => __( 'Address line 1', 'sugarcommerce' ),
				'line2'    => __( 'Address line 2', 'sugarcommerce' ),
				'city'     => $labels['city'],
				'state'    => $labels['state'],
				'postcode' => $labels['postcode'],
				'country'  => __( 'Country or region', 'sugarcommerce' ),
			],
			'required'         => [
				'line1'    => $required['street'],
				'city'     => $required['city'],
				'state'    => $required['state'],
				'postcode' => $required['postcode'],
			],
			'regions'          => $regions,
			'postcode_pattern' => '' === $country ? '' : Addresses::postcode_pattern( $country ),
			'postcode_example' => '' === $country ? '' : Addresses::postcode_example( $country ),
		];
	}

	/**
	 * Render the country and the parts its addresses have.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$value    = $this->read( $field->value(), $field );
		$layout   = self::layout( $value['country'] );
		$shown    = $this->shown( $field );
		$id       = (string) $attributes->get( 'id', '' );
		$name     = (string) $attributes->get( 'name', '' );
		$disabled = $attributes->has( 'disabled' );
		$required = $field->is_required();
		$placed   = [];
		$lines    = '';

		foreach ( $layout['lines'] as $line ) {
			$cells = '';

			foreach ( $line as $part ) {
				if ( ! in_array( $part, $shown, true ) ) {
					continue;
				}

				$placed[] = $part;
				$cells   .= $this->part( $field, $part, $value, $layout, $id, $name, $required, $disabled, false );
			}

			if ( '' !== $cells ) {
				$lines .= '<div class="field-kit__address-line">' . $cells . '</div>';
			}
		}

		// The parts this country has no place for, kept in the markup for
		// the script to bring back when the country changes.
		$spare = '';

		foreach ( array_diff( $shown, $placed, [ 'country' ] ) as $part ) {
			$spare .= $this->part( $field, $part, $value, $layout, $id, $name, $required, $disabled, true );
		}

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__address' );
		$wrapper->set( 'data-address', true );
		$wrapper->set( 'data-layout', $layout );
		$wrapper->set( 'data-required', $required ? 'true' : 'false' );

		return sprintf(
			'<div%s><div class="field-kit__address-line field-kit__address-line--country">%s</div><div class="field-kit__address-lines">%s</div><div class="field-kit__address-spare" hidden>%s</div><p class="field-kit__address-hint" id="%s" aria-live="polite"></p></div>',
			$wrapper->render(),
			$this->country( $field, $value['country'], $layout, $id, $name, $required, $disabled ),
			$lines,
			$spare,
			esc_attr( $id . '__hint' )
		);
	}

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

	/**
	 * An object of the parts.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	public function schema( Field $field ): array {
		return [
			'type'       => 'object',
			'properties' => array_fill_keys( self::PARTS, [ 'type' => 'string' ] ),
		];
	}

	/**
	 * Several boxes under one name: a fieldset and a legend.
	 *
	 * @return bool
	 */
	public function is_grouped(): bool {
		return true;
	}

	/**
	 * Configuration this type reads.
	 *
	 * @return string[]
	 */
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'countries', 'default_country', 'parts', 'line2' ] );
	}
}
