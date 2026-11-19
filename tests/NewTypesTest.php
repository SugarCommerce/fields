<?php
/**
 * Rating, dimension, slug, embed and location.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Fields\Support\Lookups;
use SugarCommerce\Fields\Types\DimensionType;
use SugarCommerce\Fields\Types\EmbedType;
use SugarCommerce\Fields\Types\LocationType;
use SugarCommerce\Fields\Types\RatingType;
use SugarCommerce\Fields\Types\SlugType;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * What each smaller type stores, refuses and draws.
 */
final class NewTypesTest extends TestCase {

	/**
	 * A field of a type.
	 *
	 * @param string               $type   Type id.
	 * @param array<string, mixed> $config Configuration.
	 * @param mixed                $value  Value.
	 *
	 * @return Field
	 */
	private function field( string $type, array $config = [], mixed $value = null ): Field {
		$resolved = ( new Registry() )->get( $type );

		return new Field( 'demo', $resolved, array_merge( $resolved->defaults(), [ 'label' => 'Demo', 'input_name' => 'demo' ], $config ), $value );
	}

	private function sanitize( string $type, mixed $value, array $config = [] ): mixed {
		$field = $this->field( $type, $config );

		return $field->type()->sanitize( $value, $field );
	}

	private function render( string $type, array $config = [], mixed $value = null ): string {
		return ( new Renderer() )->render( $this->field( $type, $config, $value ) );
	}

	/* Rating ------------------------------------------------------------ */

	public function test_a_rating_is_rounded_to_its_step_and_held_to_its_stars(): void {
		$this->assertSame( 4, $this->sanitize( 'rating', '3.7' ) );
		$this->assertSame( 3.5, $this->sanitize( 'rating', '3.7', [ 'half' => true ] ) );
		$this->assertSame( 4.5, $this->sanitize( 'rating', 4.4, [ 'half' => true ] ) );
		$this->assertSame( 5, $this->sanitize( 'rating', 7 ) );
		$this->assertSame( 10, $this->sanitize( 'rating', 12, [ 'max' => 10 ] ) );
		$this->assertSame( 3, $this->sanitize( 'rating', 5, [ 'max' => 3 ] ) );
	}

	public function test_no_rating_is_nothing_so_required_means_somebody_chose(): void {
		$this->assertSame( '', $this->sanitize( 'rating', '0' ) );
		$this->assertSame( '', $this->sanitize( 'rating', '-2' ) );
		$this->assertSame( '', $this->sanitize( 'rating', 'five' ) );
		$this->assertSame( '', $this->sanitize( 'rating', [ 3 ] ) );

		$set = new FieldSet( [ 'stars' => [ 'type' => 'rating', 'label' => 'Stars', 'required' => true ] ], new ArrayContext(), 'r' );

		$this->assertSame( [ 'stars' => 'Stars is required.' ], $set->validate( [ 'stars' => '0' ] ) );
		$this->assertSame( [], $set->validate( [ 'stars' => '2' ] ) );
	}

	public function test_a_rating_is_a_radio_per_step_in_a_fieldset(): void {
		$html = $this->render( 'rating', [ 'half' => true ], 4.5 );

		$this->assertStringContainsString( '<fieldset', $html );
		$this->assertSame( 11, substr_count( $html, 'type="radio"' ), 'Ten halves and "No rating".' );
		$this->assertMatchesRegularExpression( '/value="4.5" checked/', $html );
		$this->assertStringContainsString( '4.5 out of 5', $html );
		$this->assertStringContainsString( 'field-kit__rating--half', $html );
		$this->assertStringContainsString( '--fk-rating-max:5', $html );
	}

	public function test_a_required_rating_has_no_way_out(): void {
		$html = $this->render( 'rating', [ 'required' => true, 'max' => 3 ] );

		$this->assertStringNotContainsString( 'No rating', $html );
		$this->assertSame( 3, substr_count( $html, 'type="radio"' ) );
		$this->assertSame( 3, preg_match_all( '/type="radio"[^>]* required/', $html ), 'Each button is required.' );
	}

	public function test_a_display_rating_draws_stars_and_keeps_its_value(): void {
		$html = $this->render( 'rating', [ 'display' => true, 'max' => 10, 'half' => true ], 7.5 );

		$this->assertStringNotContainsString( 'type="radio"', $html );
		$this->assertStringContainsString( 'role="img"', $html );
		$this->assertStringContainsString( 'aria-label="7.5 out of 10 stars"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="demo" value="7.5" />', $html );
	}

	public function test_stars_draw_anywhere_and_are_held_in_range(): void {
		$this->assertStringContainsString( '--fk-rating:4.3;--fk-rating-max:5', RatingType::stars( 4.3 ) );
		$this->assertStringContainsString( '--fk-rating:5;', RatingType::stars( 9.0 ) );
		$this->assertStringContainsString( '--fk-rating:0;', RatingType::stars( -1.0 ) );
		$this->assertStringContainsString( '--fk-rating-max:10', RatingType::stars( 3.0, 40 ) );
		$this->assertStringContainsString( 'aria-label="Great"', RatingType::stars( 5.0, 5, [ 'label' => 'Great' ] ) );
		$this->assertStringContainsString( '--fk-rating-size:32px', RatingType::stars( 5.0, 5, [ 'size' => 32 ] ) );
	}

	/* Dimension --------------------------------------------------------- */

	public function test_a_dimension_keeps_its_number_and_a_unit_it_offers(): void {
		$this->assertSame( [ 'value' => 12, 'unit' => 'em' ], $this->sanitize( 'dimension', [ 'value' => '12', 'unit' => 'em' ] ) );
		$this->assertSame( [ 'value' => 2.5, 'unit' => 'kg' ], $this->sanitize( 'dimension', [ 'value' => '2.5', 'unit' => 'kg' ], [ 'units' => 'weight' ] ) );
		$this->assertSame( [ 'value' => 3, 'unit' => 'px' ], $this->sanitize( 'dimension', [ 'value' => '3', 'unit' => 'pt' ] ), 'An unoffered unit is the first.' );
		$this->assertSame( [ 'value' => 3, 'unit' => 'rem' ], $this->sanitize( 'dimension', [ 'value' => '3', 'unit' => 'pt' ], [ 'unit_default' => 'rem' ] ) );
	}

	public function test_a_unit_alone_is_no_dimension(): void {
		$this->assertSame( [], $this->sanitize( 'dimension', [ 'value' => '', 'unit' => 'em' ] ) );
		$this->assertSame( [], $this->sanitize( 'dimension', [ 'value' => 'wide', 'unit' => 'em' ] ) );
		$this->assertSame( [], $this->sanitize( 'dimension', 'nothing' ) );
	}

	public function test_a_dimension_is_held_to_min_and_max_and_reads_the_string_form(): void {
		$this->assertSame( [ 'value' => 0, 'unit' => 'cm' ], $this->sanitize( 'dimension', [ 'value' => '-4', 'unit' => 'cm' ], [ 'units' => 'size', 'min' => 0 ] ) );
		$this->assertSame( [ 'value' => 100, 'unit' => '%' ], $this->sanitize( 'dimension', '250%', [ 'max' => 100 ] ) );
		$this->assertSame( [ 'value' => 2.5, 'unit' => 'kg' ], $this->sanitize( 'dimension', '2.5 kg', [ 'units' => 'weight' ] ) );
	}

	public function test_units_are_a_list_a_map_or_a_set(): void {
		$this->assertSame( [ 'px' => 'px', 'em' => 'em' ], DimensionType::units( $this->field( 'dimension', [ 'units' => [ 'px', 'em' ] ] ) ) );
		$this->assertSame( [ 'kg' => 'kilograms' ], DimensionType::units( $this->field( 'dimension', [ 'units' => [ 'kg' => 'kilograms' ] ] ) ) );
		$this->assertSame( [ 'kg', 'g', 'lb', 'oz' ], array_keys( DimensionType::units( $this->field( 'dimension', [ 'units' => 'weight' ] ) ) ) );
	}

	public function test_a_dimension_draws_a_number_and_a_unit_select_or_one_unit(): void {
		$html = $this->render( 'dimension', [ 'units' => 'weight' ], [ 'value' => 2, 'unit' => 'lb' ] );

		$this->assertStringContainsString( 'type="number"', $html );
		$this->assertStringContainsString( 'name="demo[value]"', $html );
		$this->assertStringContainsString( 'name="demo[unit]"', $html );
		$this->assertStringContainsString( '<option value="lb" selected>', $html );
		$this->assertStringContainsString( '<label class="field-kit__sub-label screen-reader-text" for="demo_unit">Unit</label>', $html );

		$one = $this->render( 'dimension', [ 'units' => [ 'cm' ] ], [ 'value' => 30, 'unit' => 'cm' ] );

		$this->assertStringNotContainsString( '<select', $one );
		$this->assertStringContainsString( '<input type="hidden" name="demo[unit]" value="cm" />', $one );
	}

	public function test_a_dimension_is_written_as_one_string(): void {
		$this->assertSame( '12px', DimensionType::format( [ 'value' => 12, 'unit' => 'px' ] ) );
		$this->assertSame( '2.5 kg', DimensionType::format( [ 'value' => 2.5, 'unit' => 'kg' ] ) );
		$this->assertSame( '', DimensionType::format( [ 'unit' => 'kg' ] ) );
	}

	/* Slug -------------------------------------------------------------- */

	public function test_a_slug_is_cleaned_like_a_post_name(): void {
		$this->assertSame( 'summer-sale-2026', $this->sanitize( 'slug', '  Summer Sale 2026! ' ) );
		$this->assertSame( '', $this->sanitize( 'slug', [ 'x' ] ) );
	}

	public function test_a_taken_slug_is_refused_with_its_suggestion(): void {
		$taken = static fn( string $slug ): bool|string => 'sale' === $slug ? 'sale-2' : ( 'shop' === $slug ? false : true );
		$set   = new FieldSet( [ 'handle' => [ 'type' => 'slug', 'label' => 'Handle', 'unique_callback' => $taken ] ], new ArrayContext(), 's' );

		$this->assertSame( [ 'handle' => '"sale" is already in use. Try "sale-2".' ], $set->validate( [ 'handle' => 'Sale' ] ) );
		$this->assertSame( [ 'handle' => '"shop" is already in use.' ], $set->validate( [ 'handle' => 'shop' ] ) );
		$this->assertSame( [], $set->validate( [ 'handle' => 'mugs' ] ) );
	}

	public function test_a_slug_is_never_checked_against_itself(): void {
		$set = new FieldSet(
			[ 'handle' => [ 'type' => 'slug', 'label' => 'Handle', 'unique_callback' => static fn(): bool => false ] ],
			new ArrayContext( [ 'handle' => 'mine' ] ),
			's'
		);

		$this->assertSame( [], $set->validate( [ 'handle' => 'mine' ] ) );
		$this->assertNotSame( [], $set->validate( [ 'handle' => 'yours' ] ) );
	}

	public function test_a_slug_check_is_registered_for_the_script(): void {
		$set   = new FieldSet( [ 'handle' => [ 'type' => 'slug', 'label' => 'Handle', 'source' => 'title', 'unique_callback' => static fn( string $s ): bool => 'x' !== $s ] ], new ArrayContext(), 'shop_slug' );
		$field = $set->field( 'handle' );
		$html  = $set->render_field( $field );

		$this->assertSame( 'shop_slug_handle', $field->get( 'lookup_name' ) );
		$this->assertStringContainsString( 'data-lookup="shop_slug_handle"', $html );
		$this->assertStringContainsString( 'data-slug-source="title"', $html );
		$this->assertSame( 'edit_posts', Lookups::shared()->capability( 'shop_slug_handle' ) );

		$answer = ( Lookups::shared()->handler( 'shop_slug_handle' ) )( 'X' );

		$this->assertFalse( $answer['available'] );
		$this->assertSame( 'x', $answer['slug'] );
		$this->assertTrue( ( Lookups::shared()->handler( 'shop_slug_handle' ) )( 'y' )['available'] );
	}

	public function test_without_a_callback_a_slug_has_no_lookup_and_is_always_free(): void {
		$set = new FieldSet( [ 'plain' => [ 'type' => 'slug', 'label' => 'Plain' ] ], new ArrayContext(), 'plain_slug' );

		$this->assertNull( $set->field( 'plain' )->get( 'lookup_name' ) );
		$this->assertFalse( Lookups::shared()->has( 'plain_slug_plain' ) );
		$this->assertTrue( SlugType::check( 'anything', null )['available'] );
	}

	/* Embed ------------------------------------------------------------- */

	public function test_an_embed_link_draws_its_player(): void {
		$html = $this->render( 'embed', [], 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );

		$this->assertStringContainsString( 'type="url"', $html );
		$this->assertStringContainsString( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $html );
		$this->assertStringContainsString( 'data-embed-platforms="youtube,vimeo,soundcloud,spotify,bandcamp"', $html );
		$this->assertStringNotContainsString( 'field-kit__embed-preview" hidden', $html );
	}

	public function test_a_link_no_player_can_be_made_from_is_refused(): void {
		$set = new FieldSet( [ 'trailer' => [ 'type' => 'embed', 'label' => 'Trailer', 'platforms' => [ 'youtube', 'vimeo' ] ] ], new ArrayContext(), 'e' );

		$this->assertSame(
			[ 'trailer' => 'That link cannot be played here. Paste a link to YouTube, Vimeo.' ],
			$set->validate( [ 'trailer' => 'https://notyoutube.com/watch?v=dQw4w9WgXcQ' ] )
		);
		$this->assertArrayHasKey( 'trailer', $set->validate( [ 'trailer' => 'https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC' ] ), 'Audio is not offered.' );
		$this->assertSame( [], $set->validate( [ 'trailer' => 'https://vimeo.com/76979871' ] ) );
		$this->assertSame( [], $set->validate( [ 'trailer' => '' ] ) );
	}

	public function test_a_preview_is_built_from_the_id_and_not_the_link(): void {
		$preview = EmbedType::preview( 'https://youtu.be/dQw4w9WgXcQ?autoplay=1&x="><script>', [], 'Demo' );

		$this->assertSame( 'youtube', $preview['platform'] );
		$this->assertStringNotContainsString( '<script', $preview['html'] );
		$this->assertStringNotContainsString( 'autoplay', $preview['html'] );
		$this->assertNull( EmbedType::preview( '' ) );
		$this->assertSame( [ 'vimeo' ], EmbedType::platforms( [ 'vimeo', 'myspace' ] ) );
	}

	public function test_preview_off_draws_the_link_alone(): void {
		$this->assertStringNotContainsString( 'field-kit__embed-preview', $this->render( 'embed', [ 'preview' => false ], 'https://vimeo.com/76979871' ) );
	}

	/* Location ---------------------------------------------------------- */

	public function test_a_place_is_a_pair_of_numbers_or_none(): void {
		$this->assertSame(
			[ 'address' => 'Leeds', 'lat' => 53.8008, 'lng' => -1.5491 ],
			$this->sanitize( 'location', [ 'address' => ' Leeds ', 'lat' => '53.8008', 'lng' => '-1.5491' ] )
		);
		$this->assertSame( [ 'address' => 'Leeds' ], $this->sanitize( 'location', [ 'address' => 'Leeds', 'lat' => '53.8', 'lng' => '' ] ) );
		$this->assertSame( [], $this->sanitize( 'location', 'Leeds' ) );
		$this->assertSame( 51.1234568, $this->sanitize( 'location', [ 'lat' => '51.123456789', 'lng' => '0' ] )['lat'] );
	}

	public function test_a_point_off_the_earth_is_refused(): void {
		$set = new FieldSet( [ 'pin' => [ 'type' => 'location', 'label' => 'Pin' ] ], new ArrayContext(), 'l' );

		$this->assertSame( [ 'pin' => 'A latitude is between -90 and 90.' ], $set->validate( [ 'pin' => [ 'lat' => '91', 'lng' => '0' ] ] ) );
		$this->assertSame( [ 'pin' => 'A longitude is between -180 and 180.' ], $set->validate( [ 'pin' => [ 'lat' => '0', 'lng' => '-181' ] ] ) );
		$this->assertSame( [], $set->validate( [ 'pin' => [ 'lat' => '-90', 'lng' => '180' ] ] ) );
	}

	public function test_the_map_needs_no_key(): void {
		$this->assertSame(
			'https://www.openstreetmap.org/export/embed.html?bbox=-1.5541%2C53.7958%2C-1.5441%2C53.8058&layer=mapnik&marker=53.8008%2C-1.5491',
			LocationType::map_url( 53.8008, -1.5491 )
		);
		$this->assertStringContainsString( 'bbox=-180%2C-90%2C', LocationType::map_url( -90.0, -180.0 ) );

		$html = $this->render( 'location', [], [ 'lat' => 53.8008, 'lng' => -1.5491, 'address' => 'Leeds' ] );

		$this->assertStringContainsString( '<iframe class="field-kit__location-frame" src="https://www.openstreetmap.org/export/embed.html', $html );
		$this->assertStringContainsString( 'title="Map of Leeds"', $html );
		$this->assertStringContainsString( 'class="field-kit__location-map"', $html );
		$this->assertStringContainsString( 'class="field-kit__location-map" hidden', $this->render( 'location' ) );
		$this->assertStringNotContainsString( 'field-kit__location-map', $this->render( 'location', [ 'map' => false ] ) );
	}

	public function test_find_is_offered_only_with_a_geocoder(): void {
		$without = new FieldSet( [ 'pin' => [ 'type' => 'location', 'label' => 'Pin' ] ], new ArrayContext(), 'loc_a' );
		$with    = new FieldSet( [ 'pin' => [ 'type' => 'location', 'label' => 'Pin', 'geocoder' => static fn(): array => [ 'lat' => 1, 'lng' => 2 ], 'lookup_capability' => '' ] ], new ArrayContext(), 'loc_b' );

		$this->assertStringNotContainsString( 'field-kit__location-find', $without->render() );
		$this->assertStringContainsString( 'field-kit__location-find', $with->render() );
		$this->assertStringContainsString( 'data-lookup="loc_b_pin"', $with->render() );
		$this->assertSame( '', Lookups::shared()->capability( 'loc_b_pin' ) );
	}

	public function test_a_geocoder_object_or_a_callable_answers(): void {
		$geocoder = new class() {
			public function locate( string $address ): object {
				if ( 'nowhere' === $address ) {
					return new WP_Error( 'mapsco_no_results', 'Nothing found.' );
				}

				return new class() {
					public function get_latitude(): float {
						return 51.50722222;
					}

					public function get_longitude(): float {
						return -0.1275;
					}

					public function get_formatted_address(): string {
						return 'London, UK';
					}
				};
			}
		};

		$found = LocationType::locate( $geocoder, 'london' );

		$this->assertSame( 51.5072222, $found['lat'] );
		$this->assertSame( 'London, UK', $found['address'] );
		$this->assertStringContainsString( 'marker=51.5072222%2C-0.1275', $found['map'] );
		$this->assertSame( 'mapsco_no_results', LocationType::locate( $geocoder, 'nowhere' )->get_error_code() );
		$this->assertSame( 'sugarcommerce_fields_no_address', LocationType::locate( $geocoder, '  ' )->get_error_code() );
		$this->assertSame( 'sugarcommerce_fields_not_found', LocationType::locate( static fn(): array => [], 'x' )->get_error_code() );
		$this->assertSame( 'x', LocationType::locate( static fn(): array => [ 'lat' => 1, 'lng' => 2 ], 'x' )['address'], 'No address back: what was typed.' );
	}

	/**
	 * A surface that builds its set only while drawing registers its
	 * lookups up front, and the set keeps the names it was given.
	 */
	public function test_a_surface_registers_lookups_before_drawing(): void {
		$configs = Lookups::register_configs(
			'panel-orders',
			[
				'handle' => [ 'type' => 'slug', 'label' => 'Handle', 'unique_callback' => static fn(): bool => true ],
				'plain'  => [ 'type' => 'text', 'label' => 'Plain' ],
				'free'   => [ 'type' => 'slug', 'label' => 'Free' ],
			]
		);

		$this->assertSame( 'panel-orders_handle', $configs['handle']['lookup_name'] );
		$this->assertArrayNotHasKey( 'lookup_name', $configs['plain'] );
		$this->assertArrayNotHasKey( 'lookup_name', $configs['free'], 'No callback, no lookup.' );
		$this->assertTrue( Lookups::shared()->has( 'panel-orders_handle' ) );

		$set = new FieldSet( $configs, new ArrayContext(), '' );

		$this->assertSame( 'panel-orders_handle', $set->field( 'handle' )->get( 'lookup_name' ) );
	}
}
