<?php
/**
 * Gallery tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\Contracts\AdminOnly;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Fields\Support\Dependencies;
use SugarCommerce\Fields\Types\GalleryType;

/**
 * A grid of pictures from the media library: what it stores, the limits it
 * keeps on the way in, and the alt text it takes from each attachment.
 *
 * The limits are asserted where they have to hold. The value posts as a
 * plain comma-separated list in a hidden input, so anything that can post a
 * form can post thirty ids to a field that says ten.
 */
final class GalleryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['fk_attachments'] = [];
		$GLOBALS['fk_meta']        = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['fk_attachments'], $GLOBALS['fk_meta'] );
	}

	/**
	 * A gallery field.
	 *
	 * @param array<string, mixed> $config Extra configuration.
	 * @param mixed                $value  What it holds.
	 *
	 * @return Field
	 */
	private function field( array $config = [], mixed $value = null ): Field {
		return new Field(
			'photos',
			( new Registry() )->get( 'gallery' ),
			array_merge( [
				'label' => 'Photos',
				'input_name' => 'photos',
				'input_id' => 'photos',
			], $config ),
			$value
		);
	}

	public function test_it_stores_ids_in_order_from_a_string_or_a_list(): void {
		$field = $this->field();

		$this->assertSame( [ 3, 1, 2 ], $field->type()->sanitize( '3,1,2', $field ) );
		$this->assertSame( [ 7, 8 ], $field->type()->sanitize( [ '7', 8 ], $field ) );
	}

	public function test_rubbish_and_repeats_are_dropped(): void {
		$field = $this->field();

		$this->assertSame( [ 4, 9 ], $field->type()->sanitize( '4,,abc,0,4,9', $field ) );
		$this->assertSame( [], $field->type()->sanitize( '', $field ) );
		$this->assertSame( [ 5 ], $field->type()->sanitize( [ 5, [ 6 ], null ], $field ) );
	}

	public function test_a_submission_is_cut_to_the_maximum(): void {
		$field = $this->field( [ 'max' => 3 ] );

		$this->assertSame( [ 1, 2, 3 ], $field->type()->sanitize( '1,2,3,4,5', $field ) );
		$this->assertCount( 5, $this->field()->type()->sanitize( '1,2,3,4,5', $this->field() ) );
	}

	public function test_fewer_than_the_minimum_is_refused_but_empty_is_a_choice(): void {
		$field = $this->field( [ 'min' => 3 ] );

		$this->assertSame( 'Photos needs at least 3 images.', $field->type()->validate( [ 1, 2 ], $field ) );
		$this->assertSame( '', $field->type()->validate( [ 1, 2, 3 ], $field ) );
		$this->assertSame( '', $field->type()->validate( [], $field ) );
	}

	public function test_a_required_empty_gallery_is_refused_by_the_set(): void {
		$set = new FieldSet(
			[
				'photos' => [
					'type'     => 'gallery',
					'label'    => 'Photos',
					'required' => true,
				],
			],
			new ArrayContext( [] ),
			''
		);

		$this->assertArrayHasKey( 'photos', $set->validate( [ 'photos' => '' ] ) );
		$this->assertArrayNotHasKey( 'photos', $set->validate( [ 'photos' => '12' ] ) );
	}

	public function test_each_tile_carries_its_attachments_own_alt_text(): void {
		$GLOBALS['fk_attachments'][11]                         = [ 'name' => 'Beach' ];
		$GLOBALS['fk_attachments'][12]                         = [ 'name' => 'Pier' ];
		$GLOBALS['fk_meta']['post'][11]['_wp_attachment_image_alt'] = 'Sand and sea at dusk';

		$html = ( new Renderer() )->render( $this->field( [], [ 11, 12 ] ) );

		$this->assertStringContainsString( 'alt="Sand and sea at dusk"', $html );
		$this->assertMatchesRegularExpression( '/data-id="11">.*?<span class="field-kit__gallery-alt" hidden>/s', $html );
		$this->assertMatchesRegularExpression( '/data-id="12">.*?<span class="field-kit__gallery-alt">No alt text/s', $html );
	}

	public function test_it_draws_the_grid_the_value_and_one_add_button(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'max' => 2 ], '5,6' ) );

		$this->assertStringContainsString( 'value="5,6"', $html );
		$this->assertSame( 2, substr_count( $html, '<li class="field-kit__gallery-item" data-id="' ) - 1, 'Two tiles and the template.' );
		$this->assertStringContainsString( 'Reorder Item 5, 1 of 2', $html );
		$this->assertStringContainsString( 'aria-label="Remove Item 6"', $html );
		$this->assertStringContainsString( 'data-max="2"', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]*field-kit__gallery-add[^>]*disabled/', $html, 'A full gallery turns its button off.' );
		$this->assertStringNotContainsString( 'field-kit__media-clear', $html, 'Each tile removes itself.' );
		$this->assertStringContainsString( '<template class="field-kit__gallery-template">', $html );
	}

	public function test_an_empty_gallery_draws_an_empty_grid_and_a_live_button(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'max' => 2 ] ) );

		$this->assertStringContainsString( 'data-empty="true"', $html );
		$this->assertDoesNotMatchRegularExpression( '/<button[^>]*field-kit__gallery-add[^>]*disabled/', $html );
	}

	public function test_a_picture_gone_from_the_library_keeps_its_place(): void {
		$GLOBALS['fk_attachments'][40] = [ 'mime' => 'application/pdf' ];

		$html = ( new Renderer() )->render( $this->field( [], [ 40 ] ) );

		$this->assertStringContainsString( 'Missing image', $html );
		$this->assertStringContainsString( 'data-id="40"', $html );
	}

	public function test_images_draws_the_pictures_for_the_front_of_the_site(): void {
		$GLOBALS['fk_meta']['post'][21]['_wp_attachment_image_alt'] = 'Ignored by the stub';
		$GLOBALS['fk_attachments'][23]                              = [ 'mime' => 'audio/mpeg' ];

		$html = GalleryType::images( '21,22,23', 'large', [ 'class' => 'shop-gallery' ] );

		$this->assertStringStartsWith( '<ul class="shop-gallery">', $html );
		$this->assertSame( 2, substr_count( $html, '<li class="sugarcommerce-gallery__item">' ), 'What will not draw is left out.' );
		$this->assertSame( '', GalleryType::images( [] ) );
	}

	public function test_alt_is_the_attachments_own_text(): void {
		$GLOBALS['fk_meta']['post'][3]['_wp_attachment_image_alt'] = '  <b>A cat</b> ';

		$this->assertSame( 'A cat', GalleryType::alt( 3 ) );
		$this->assertSame( '', GalleryType::alt( 4 ) );
	}

	public function test_its_schema_is_a_list_of_ids(): void {
		$this->assertSame(
			[
				'type'     => 'array',
				'items'    => [ 'type' => 'integer' ],
				'maxItems' => 4,
			],
			$this->field( [ 'max' => 4 ] )->type()->schema( $this->field( [ 'max' => 4 ] ) )
		);
	}

	public function test_it_needs_the_media_library_and_stays_in_wp_admin(): void {
		$this->assertContains( 'media-views', Dependencies::of( [ [ 'type' => 'gallery' ] ] )['scripts'] );
		$this->assertInstanceOf( AdminOnly::class, ( new Registry() )->get( 'gallery' ) );
	}
}
