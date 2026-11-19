<?php
/**
 * The address type.
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
use SugarCommerce\Fields\Types\AddressType;
use PHPUnit\Framework\TestCase;

/**
 * An address follows its country: the boxes, their names, which are
 * required, the region list and the postcode's pattern are all geo's, and
 * the server holds a submission to the same country it was laid out for.
 */
final class AddressTest extends TestCase {

	private function field( array $config = [], mixed $value = null ): Field {
		$type = ( new Registry() )->get( 'address' );

		return new Field( 'addr', $type, array_merge( $type->defaults(), [ 'label' => 'Address', 'input_name' => 'addr' ], $config ), $value );
	}

	private function sanitize( mixed $value, array $config = [] ): array {
		$field = $this->field( $config );

		return $field->type()->sanitize( $value, $field );
	}

	private function errors( array $address, array $config = [] ): array {
		$set = new FieldSet( [ 'addr' => array_merge( [ 'type' => 'address', 'label' => 'Address' ], $config ) ], new ArrayContext(), 'f' );

		return $set->validate( [ 'addr' => $address ] );
	}

	public function test_the_layout_follows_the_country(): void {
		$us = AddressType::layout( 'us' );

		$this->assertSame( 'US', $us['country'] );
		$this->assertContains( [ 'city', 'state', 'postcode' ], $us['lines'], 'Austin, TX 78701 is one line.' );
		$this->assertSame( 'ZIP code', $us['labels']['postcode'] );
		$this->assertSame( 'State', $us['labels']['state'] );
		$this->assertTrue( $us['required']['state'] );
		$this->assertTrue( $us['required']['postcode'] );
		$this->assertGreaterThan( 50, count( $us['regions'] ) );
		$this->assertSame( '95014', $us['postcode_example'] );
		$this->assertNotSame( '', $us['postcode_pattern'] );

		$gb = AddressType::layout( 'GB' );

		$this->assertSame( 'Postcode', $gb['labels']['postcode'] );
		$this->assertSame( 'County', $gb['labels']['state'] );
		$this->assertFalse( $gb['required']['state'], 'A county is optional.' );

		$de = AddressType::layout( 'DE' );

		$this->assertContains( [ 'postcode', 'city' ], $de['lines'], 'The postcode comes before the town.' );
		$this->assertNotContains( 'state', array_merge( ...$de['lines'] ) );
	}

	public function test_a_country_without_a_postcode_has_no_box_for_one(): void {
		$hk = AddressType::layout( 'HK' );

		$this->assertNotContains( 'postcode', array_merge( ...$hk['lines'] ) );
		$this->assertSame( '', $hk['postcode_pattern'] );
	}

	public function test_an_unknown_country_has_every_part(): void {
		$none = AddressType::layout( '' );

		$this->assertSame( [ [ 'name' ], [ 'company' ], [ 'line1' ], [ 'line2' ], [ 'city' ], [ 'state' ], [ 'postcode' ] ], $none['lines'] );
		$this->assertTrue( $none['required']['line1'] );
		$this->assertFalse( $none['required']['postcode'] );
		$this->assertSame( 'Postcode or ZIP', $none['labels']['postcode'] );
	}

	public function test_it_is_drawn_in_the_countrys_order_with_the_rest_kept_aside(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'default_country' => 'DE', 'required' => true ] ) );

		$this->assertStringContainsString( '<fieldset', $html );
		$this->assertMatchesRegularExpression( '/data-part="postcode".*data-part="city"/s', $html );
		$this->assertMatchesRegularExpression( '/field-kit__address-spare" hidden><div class="field-kit__address-part field-kit__address-part--state" data-part="state" hidden>/', $html );
		$this->assertStringContainsString( '<option value="DE" selected>', $html );
		$this->assertStringContainsString( 'autocomplete="postal-code"', $html );
		$this->assertMatchesRegularExpression( '/name="addr\[line1\]"[^>]* required/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="addr\[line2\]"[^>]* required/', $html );
		$this->assertStringContainsString( 'data-layout="', $html );
	}

	public function test_a_listed_region_is_a_select_and_the_box_stands_down(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'autocomplete' => 'shipping' ], [ 'country' => 'US', 'state' => 'TX' ] ) );

		$this->assertMatchesRegularExpression( '/<select class="[^"]*field-kit__address-state-select" id="addr_state"/', $html );
		$this->assertStringContainsString( '<option value="TX" selected>', $html );
		$this->assertMatchesRegularExpression( '/field-kit__address-state-text"[^>]* disabled hidden/', $html );
		$this->assertStringContainsString( 'autocomplete="shipping address-level1"', $html );
	}

	public function test_parts_and_countries_are_the_fields_to_choose(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'parts' => [ 'company' ], 'line2' => false, 'countries' => [ 'fr', 'DE' ] ] ) );

		$this->assertStringContainsString( 'name="addr[company]"', $html );
		$this->assertStringNotContainsString( 'name="addr[name]"', $html );
		$this->assertStringNotContainsString( 'name="addr[line2]"', $html );
		$this->assertSame( 3, substr_count( $html, '<option value=' ) - substr_count( $html, 'field-kit__address-state-select' ) * 0 - 1, 'Two countries and the blank.' );
	}

	public function test_a_submission_is_cleaned_for_its_country(): void {
		$this->assertSame(
			[
				'line1'    => '10 Downing Street',
				'city'     => 'London',
				'postcode' => 'SW1A 2AA',
				'country'  => 'GB',
			],
			$this->sanitize( [ 'line1' => ' 10 Downing Street ', 'city' => 'London', 'postcode' => 'sw1a   2aa', 'country' => 'gb', 'name' => 'Not asked for' ] )
		);

		$this->assertSame(
			[ 'line1' => 'Unter den Linden 1', 'city' => 'Berlin', 'postcode' => '10117', 'country' => 'DE' ],
			$this->sanitize( [ 'line1' => 'Unter den Linden 1', 'city' => 'Berlin', 'state' => 'Brandenburg', 'postcode' => '10117', 'country' => 'DE' ] ),
			'Germany has no state, so none is kept.'
		);

		$this->assertArrayNotHasKey( 'postcode', $this->sanitize( [ 'line1' => '1 Queen\'s Road', 'city' => 'Central', 'postcode' => '999077', 'country' => 'HK' ] ), 'Hong Kong has no postcode.' );
		$this->assertSame( 'TX', $this->sanitize( [ 'line1' => '1 Main St', 'state' => 'tx', 'country' => 'US' ] )['state'] );
		$this->assertArrayNotHasKey( 'state', $this->sanitize( [ 'line1' => '1 Main St', 'state' => 'Narnia', 'country' => 'US' ] ) );
	}

	public function test_a_country_on_its_own_is_no_address(): void {
		$this->assertSame( [], $this->sanitize( [ 'country' => 'GB', 'line1' => '  ' ] ) );
		$this->assertSame( [], $this->sanitize( 'GB' ) );
	}

	public function test_an_unoffered_country_is_the_default(): void {
		$this->assertSame( 'FR', $this->sanitize( [ 'line1' => 'x', 'country' => 'US' ], [ 'countries' => [ 'FR', 'DE' ], 'default_country' => 'FR' ] )['country'] );
		$this->assertSame( '', $this->sanitize( [ 'line1' => 'x', 'country' => 'XX' ] )['country'] );
	}

	public function test_a_started_address_is_finished_for_its_country(): void {
		$this->assertSame( [ 'addr' => 'Town or city is needed for an address in United Kingdom.' ], $this->errors( [ 'line1' => '10 Downing Street', 'postcode' => 'SW1A 2AA', 'country' => 'GB' ] ) );
		$this->assertSame( [ 'addr' => 'State is needed for an address in United States.' ], $this->errors( [ 'line1' => '1 Main St', 'city' => 'Austin', 'postcode' => '78701', 'country' => 'US' ] ) );
		$this->assertSame( [ 'addr' => 'Choose the country this address is in.' ], $this->errors( [ 'line1' => 'Somewhere' ] ) );
		$this->assertSame( [], $this->errors( [ 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US' ] ) );
		$this->assertSame( [], $this->errors( [ 'country' => 'US' ] ), 'Not started, not required: fine.' );
		$this->assertSame( [ 'addr' => 'Address is required.' ], $this->errors( [ 'country' => 'US' ], [ 'required' => true ] ) );
	}

	public function test_a_postcode_follows_its_countrys_pattern(): void {
		$this->assertSame(
			[ 'addr' => 'ZIP code is not in the form used in United States, such as 95014.' ],
			$this->errors( [ 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '7870', 'country' => 'US' ] )
		);
		$this->assertSame( [], $this->errors( [ 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701-1234', 'country' => 'US' ] ) );
		$this->assertArrayHasKey( 'addr', $this->errors( [ 'line1' => 'x', 'city' => 'London', 'postcode' => 'NOTAPOSTCODE', 'country' => 'GB' ] ) );
		$this->assertSame( [], $this->errors( [ 'line1' => 'x', 'city' => 'Pyongyang', 'postcode' => 'anything', 'country' => 'KP' ] ), 'No pattern on file: not refused.' );
	}

	public function test_it_prints_as_its_country_writes_it(): void {
		$this->assertSame(
			[ '1 Main St', 'Austin, TX 78701', 'United States' ],
			AddressType::format( [ 'line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US' ], true )
		);
		$this->assertSame( [], AddressType::format( 'nope' ) );
	}
}
