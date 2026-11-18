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
}
