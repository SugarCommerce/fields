<?php
/**
 * Location Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Contracts\Lookup;
use SugarCommerce\Fields\Field;
use WP_Error;

/**
 * A place: an address, the latitude and longitude it is at, and a map.
 *
 * Stored as `[ 'address' => '…', 'lat' => 51.5034, 'lng' => -0.1276 ]`.
 *
 * Finding the coordinates is a geocoder's job, and a geocoder needs a key,
 * so the caller hands one over: `geocoder` is a `sugarcommerce/geocoding`
 * Geocoder -- anything with `locate( string ): Location|WP_Error` -- or a
 * callable taking the address and returning `[ 'lat', 'lng', 'address' ]`
 * or a WP_Error. With one, the address box has a Find button; without, the
 * latitude and longitude are typed, which is enough for a place somebody
 * has the numbers for.
 *
 * The map is OpenStreetMap's embed: an iframe of openstreetmap.org with a
 * marker, which needs no key, no script and no account, and moves as the
 * numbers do. A site with a content security policy allows
 * `https://www.openstreetmap.org` in `frame-src`; `map => false` leaves the
 * map off.
 */
final class LocationType extends AbstractType implements Lookup {

	/**
	 * Decimal places kept: about a centimetre, well past what any geocoder
	 * knows.
	 */
	private const int PLACES = 7;

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function defaults(): array {
		return [ 'map' => true ];
	}

	/**
	 * Render the address, the coordinates and the map.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		$value = $this->sanitize( $field->value(), $field );
		$id    = (string) $attributes->get( 'id', '' );
		$name  = (string) $attributes->get( 'name', '' );
		$find  = $field->has( 'lookup_name' ) && $field->has( 'geocoder' );
		$lock  = $attributes->has( 'disabled' ) ? ' disabled' : '';

		$attributes->set( 'type', 'text' );
		$attributes->set( 'name', $name . '[address]' );
		$attributes->set( 'value', (string) ( $value['address'] ?? '' ) );
		$attributes->set( 'autocomplete', 'off' );
		$attributes->add_class( 'regular-text', 'field-kit__location-address' );
		$attributes->set_if( '' === $field->placeholder(), 'placeholder', __( 'Search for an address', 'sugarcommerce' ) );

		$search = sprintf( '<input%s />', $attributes->render() );

		if ( $find ) {
			$search = sprintf(
				'<span class="field-kit__location-search">%s<button type="button" class="button field-kit__location-find"%s>%s</button></span>',
				$search,
				$lock,
				esc_html__( 'Find', 'sugarcommerce' )
			);
		}

		$coordinates = '';

		foreach ( [
			'lat' => [ __( 'Latitude', 'sugarcommerce' ), 90 ],
			'lng' => [ __( 'Longitude', 'sugarcommerce' ), 180 ],
		] as $part => [ $label, $limit ] ) {
			$coordinates .= sprintf(
				'<span class="field-kit__location-coordinate">%s<input type="number" id="%s" name="%s" value="%s" step="any" min="%d" max="%d" inputmode="decimal" class="small-text field-kit__location-%s"%s /></span>',
				$this->sub_label( $id . '_' . $part, $label ),
				esc_attr( $id . '_' . $part ),
				esc_attr( $name . '[' . $part . ']' ),
				esc_attr( isset( $value[ $part ] ) ? (string) $value[ $part ] : '' ),
				-$limit,
				$limit,
				esc_attr( $part ),
				$lock
			);
		}

		$map = '';

		if ( $field->get( 'map', true ) ) {
			$has = isset( $value['lat'], $value['lng'] );
			$map = sprintf(
				'<div class="field-kit__location-map"%s>%s</div>',
				$has ? '' : ' hidden',
				$has ? self::map( (float) $value['lat'], (float) $value['lng'], (string) ( $value['address'] ?? '' ) ) : ''
			);
		}

		return sprintf(
			'<div class="field-kit__location"%s data-map-title="%s">%s<span class="field-kit__location-status description" aria-live="polite"></span><span class="field-kit__location-coordinates">%s</span>%s</div>',
			$find ? ' data-lookup="' . esc_attr( (string) $field->get( 'lookup_name' ) ) . '"' : '',
			esc_attr__( 'Map', 'sugarcommerce' ),
			$search,
			$coordinates,
			$map
		);
	}

	/**
	 * The map of a point, and a link to a bigger one.
	 *
	 * @param float  $lat     Latitude.
	 * @param float  $lng     Longitude.
	 * @param string $address What the point is, for the map's title.
	 *
	 * @return string
	 */
	public static function map( float $lat, float $lng, string $address = '' ): string {
		return sprintf(
			'<iframe class="field-kit__location-frame" src="%s" title="%s" loading="lazy" referrerpolicy="no-referrer"></iframe><a class="field-kit__location-link" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( self::map_url( $lat, $lng ) ),
			esc_attr(
				'' === $address
					? __( 'Map', 'sugarcommerce' )
					/* translators: %s: an address */
					: sprintf( __( 'Map of %s', 'sugarcommerce' ), $address )
			),
			esc_url( sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=16/%1$s/%2$s', self::number( $lat ), self::number( $lng ) ) ),
			esc_html__( 'Open in OpenStreetMap', 'sugarcommerce' )
		);
	}

	/**
	 * The address of OpenStreetMap's embedded map with a marker on a point.
	 *
	 * A box about a kilometre each way, which is a street's worth.
	 *
	 * @param float $lat   Latitude.
	 * @param float $lng   Longitude.
	 * @param float $delta Half the box's side, in degrees.
	 *
	 * @return string
	 */
	public static function map_url( float $lat, float $lng, float $delta = 0.005 ): string {
		$box = [
			max( -180.0, $lng - $delta ),
			max( -90.0, $lat - $delta ),
			min( 180.0, $lng + $delta ),
			min( 90.0, $lat + $delta ),
		];

		return 'https://www.openstreetmap.org/export/embed.html?bbox='
			. implode( '%2C', array_map( [ self::class, 'number' ], $box ) )
			. '&layer=mapnik&marker=' . self::number( $lat ) . '%2C' . self::number( $lng );
	}

	/**
	 * Coerce a submitted place.
	 *
	 * A coordinate that is not a number is no coordinate, and one without
	 * the other is no place: the pair is kept or neither is. Kept as typed
	 * otherwise, to seven places -- a latitude of 95 is refused by
	 * validate() rather than quietly made 90 somewhere else.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return array{address?: string, lat?: float, lng?: float}
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$address = sanitize_text_field( is_scalar( $value['address'] ?? null ) ? (string) $value['address'] : '' );
		$lat     = $value['lat'] ?? '';
		$lng     = $value['lng'] ?? '';
		$place   = [];

		if ( '' !== $address ) {
			$place['address'] = $address;
		}

		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$place['lat'] = round( (float) $lat, self::PLACES );
			$place['lng'] = round( (float) $lng, self::PLACES );
		}

		return $place;
	}

	/**
	 * Refuse a point that is not on the earth.
	 *
	 * @param mixed $value The sanitized value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	public function validate( mixed $value, Field $field ): string {
		if ( ! is_array( $value ) || ! isset( $value['lat'], $value['lng'] ) ) {
			return parent::validate( $value, $field );
		}

		if ( abs( (float) $value['lat'] ) > 90 ) {
			return __( 'A latitude is between -90 and 90.', 'sugarcommerce' );
		}

		if ( abs( (float) $value['lng'] ) > 180 ) {
			return __( 'A longitude is between -180 and 180.', 'sugarcommerce' );
		}

		return parent::validate( $value, $field );
	}

	/**
	 * The handler the Find button asks: the field's geocoder.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return callable|null
	 */
	#[\Override]
	public function lookup_handler( array $config ): ?callable {
		$geocoder = $config['geocoder'] ?? null;

		if ( null === $geocoder || ( ! is_callable( $geocoder ) && ! ( is_object( $geocoder ) && method_exists( $geocoder, 'locate' ) ) ) ) {
			return null;
		}

		return static fn( string $query ): array|WP_Error => self::locate( $geocoder, $query );
	}

	/**
	 * Ask a geocoder where an address is.
	 *
	 * @param callable|object $geocoder A geocoder or a callable.
	 * @param string          $query    What was typed.
	 *
	 * @return array{lat: float, lng: float, address: string}|WP_Error
	 */
	public static function locate( callable|object $geocoder, string $query ): array|WP_Error {
		$query = trim( $query );

		if ( '' === $query ) {
			return new WP_Error( 'sugarcommerce_fields_no_address', __( 'Type an address to find.', 'sugarcommerce' ), [ 'status' => 400 ] );
		}

		$found = is_callable( $geocoder ) ? $geocoder( $query ) : $geocoder->locate( $query );

		if ( is_wp_error( $found ) ) {
			return $found;
		}

		if ( is_object( $found ) && method_exists( $found, 'get_latitude' ) ) {
			$found = [
				'lat'     => $found->get_latitude(),
				'lng'     => $found->get_longitude(),
				'address' => method_exists( $found, 'get_formatted_address' ) ? (string) $found->get_formatted_address() : '',
			];
		}

		if ( ! is_array( $found ) || ! is_numeric( $found['lat'] ?? null ) || ! is_numeric( $found['lng'] ?? null ) ) {
			return new WP_Error( 'sugarcommerce_fields_not_found', __( 'That address could not be found.', 'sugarcommerce' ), [ 'status' => 404 ] );
		}

		$lat = round( (float) $found['lat'], self::PLACES );
		$lng = round( (float) $found['lng'], self::PLACES );

		return [
			'lat'     => $lat,
			'lng'     => $lng,
			'address' => sanitize_text_field( (string) ( '' === (string) ( $found['address'] ?? '' ) ? $query : $found['address'] ) ),
			'map'     => self::map_url( $lat, $lng ),
			'link'    => sprintf( 'https://www.openstreetmap.org/?mlat=%1$s&mlon=%2$s#map=16/%1$s/%2$s', self::number( $lat ), self::number( $lng ) ),
		];
	}

	/**
	 * Who may ask the geocoder: editors, unless the field says otherwise.
	 *
	 * Each question may cost the site money, so a public form that wants
	 * one says so with `lookup_capability => ''`.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return string
	 */
	#[\Override]
	public function lookup_capability( array $config ): string {
		return (string) ( $config['lookup_capability'] ?? 'edit_posts' );
	}

	/**
	 * A coordinate as written in a URL.
	 *
	 * @param float $value A coordinate.
	 *
	 * @return string
	 */
	private static function number( float $value ): string {
		$written = rtrim( rtrim( number_format( $value, self::PLACES, '.', '' ), '0' ), '.' );

		return '-0' === $written ? '0' : $written;
	}

	/**
	 * An object of the three parts.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function schema( Field $field ): array {
		return [
			'type'       => 'object',
			'properties' => [
				'address' => [ 'type' => 'string' ],
				'lat'     => [ 'type' => 'number' ],
				'lng'     => [ 'type' => 'number' ],
			],
		];
	}

	/**
	 * A box and a button, which a placeholder suits.
	 *
	 * @return bool
	 */
	#[\Override]
	public function supports_placeholder(): bool {
		return true;
	}

	/**
	 * Configuration this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'geocoder', 'map' ] );
	}
}
