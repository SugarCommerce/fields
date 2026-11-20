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
use SugarCommerce\Geo\Addresses;
use SugarCommerce\Geo\Countries;
use SugarCommerce\Geo\Regions;
use SugarCommerce\Fields\Traits\AddressType\Parts;
use SugarCommerce\Fields\Traits\AddressType\Values;

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

	use Parts;
	use Values;

	/**
	 * Every part, in the order an address is stored.
	 */
	public const array PARTS = [ 'name', 'company', 'line1', 'line2', 'city', 'state', 'postcode', 'country' ];

	/**
	 * What a browser autofills into each part.
	 */
	private const array AUTOCOMPLETE = [
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
	private const array LETTERS = [
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
	#[\Override]
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
	#[\Override]
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
	 * An object of the parts.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
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
	#[\Override]
	public function is_grouped(): bool {
		return true;
	}

	/**
	 * Configuration this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'countries', 'default_country', 'parts', 'line2' ] );
	}
}
