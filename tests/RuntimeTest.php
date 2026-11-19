<?php
/**
 * Runtime key derivation tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Utils\Runtime;

/**
 * Every handle, route, key, hook and script object comes from the namespace
 * this copy is compiled under. A plain install is `sugarcommerce-fields`; a
 * Strauss build under `MyShop\` is `myshop-sugarcommerce-fields`, so two
 * plugins never answer each other's requests or load each other's script.
 */
final class RuntimeTest extends TestCase {

	/**
	 * Unprefixed, the library's own id.
	 */
	public function test_unprefixed(): void {
		$this->assertSame( 'sugarcommerce-fields', Runtime::prefix() );
		$this->assertSame( 'sugarcommerce-fields', Runtime::prefix_for( 'SugarCommerce\Fields\Utils' ) );
		$this->assertSame( 'sugarcommerce-fields', Runtime::prefix_for( '' ) );
	}

	/**
	 * Prefixed, the plugin's root namespace first, slugged.
	 */
	public function test_prefixed(): void {
		$this->assertSame( 'myshop-sugarcommerce-fields', Runtime::prefix_for( 'MyShop\SugarCommerce\Fields\Utils' ) );
		$this->assertSame( 'my-shop-sugarcommerce-fields', Runtime::prefix_for( 'My_Shop\SugarCommerce\Fields\Utils' ) );
	}

	/**
	 * A plugin of the family prefixes with a namespace that also starts
	 * "SugarCommerce", and its build is still its own.
	 */
	public function test_a_family_plugin_build_is_not_taken_for_the_library(): void {
		$playground = Runtime::prefix_for( 'SugarCommerce\Playground\Vendor\SugarCommerce\Fields\Utils' );

		$this->assertSame( 'sugarcommerce-playground-vendor-sugarcommerce-fields', $playground );
		$this->assertNotSame(
			$playground,
			Runtime::prefix_for( 'SugarCommerce\Kitchen\Vendor\SugarCommerce\Fields\Utils' ),
			'Two plugins of the family share a name.'
		);
		$this->assertSame( 'sugarcart-sugarcommerce-fields', Runtime::prefix_for( 'SugarCart\Vendor\SugarCommerce\Fields\Utils' ), 'A plugin outside the family keeps its name.' );
		$this->assertSame( 'sugarcommerce-fields', Runtime::prefix_for( '\SugarCommerce\Fields\Utils\\' ) );
	}

	/**
	 * What each accessor makes of the prefix.
	 */
	public function test_accessors(): void {
		$this->assertSame( 'sugarcommerce-fields/v1', Runtime::rest_namespace() );
		$this->assertSame( 'sugarcommerce-fields', Runtime::handle() );
		$this->assertSame( 'sugarcommerce-fields-icons', Runtime::handle( 'icons' ) );
		$this->assertSame( 'sugarcommerce_fields', Runtime::key() );
		$this->assertSame( 'sugarcommerce_fields_errors', Runtime::key( 'errors' ) );
		$this->assertSame( 'sugarcommerce_fields_preset_options', Runtime::hook( 'preset_options' ) );
		$this->assertSame( 'SugarcommerceFields', Runtime::js_object() );
		$this->assertSame( 'SugarcommerceFieldsConfig', Runtime::js_object( 'Config' ) );
	}

	/**
	 * A plugin that loads the library under its own namespace, in Lib and
	 * without the vendor segment, is named for every segment before Lib --
	 * the first is the vendor its sibling plugins share.
	 */
	public function test_a_plugin_namespace_build_is_named_for_the_plugin(): void {
		$fraud = Runtime::prefix_for( 'ArrayPress\EDD\FraudFilter\Lib\Fields\Utils' );

		$this->assertSame( 'arraypress-edd-fraudfilter-sugarcommerce-fields', $fraud );
		$this->assertNotSame( $fraud, Runtime::prefix_for( 'ArrayPress\EDD\SalePrices\Lib\Fields\Utils' ), 'Two plugins of one vendor share a name.' );
		$this->assertSame( 'playground-sugarcommerce-fields', Runtime::prefix_for( 'Playground\Lib\Fields\Utils' ) );
		$this->assertSame( 'arraypress-edd-fraudfilter', Runtime::build_for( 'ArrayPress\EDD\FraudFilter\Lib\Fields\Utils' ) );
		$this->assertSame( '', Runtime::build_for( 'SugarCommerce\Fields\Utils' ) );
	}
}
