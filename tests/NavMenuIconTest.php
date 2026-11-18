<?php
/**
 * Nav menu and icon field types.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * Covers NavMenuType and IconType.
 */
final class NavMenuIconTest extends TestCase {

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$GLOBALS['fk_nav_menus'] = [];
	}

	/**
	 * Render a field.
	 *
	 * @param string $type   Field type.
	 * @param array  $config Field configuration.
	 *
	 * @return string
	 */
	private function render( string $type, array $config = [], mixed $value = null ): string {
		return ( new Renderer() )->render( $this->field( $type, $config, $value ) );
	}

	/**
	 * Build a field the way FieldSet does.
	 *
	 * The type's defaults() are merged in first, which is where a type that
	 * supplies its own options puts them. Constructing Field directly skips
	 * that, and the field comes up with no options at all.
	 *
	 * @param string $type   Field type.
	 * @param array  $config Field configuration.
	 *
	 * @return Field
	 */
	private function field( string $type, array $config = [], mixed $value = null ): Field {
		$resolved = ( new Registry() )->get( $type );

		return new Field(
			'demo',
			$resolved,
			array_merge( $resolved->defaults(), [ 'label' => 'Demo', 'input_name' => 'demo' ], $config ),
			$value
		);
	}

	/**
	 * Sanitise through a type.
	 *
	 * @param string $type   Field type.
	 * @param mixed  $value  Submitted value.
	 * @param array  $config Field configuration.
	 *
	 * @return mixed
	 */
	private function sanitize( string $type, mixed $value, array $config = [] ) {
		return ( new Registry() )->get( $type )->sanitize( $value, $this->field( $type, $config ) );
	}

	/**
	 * A menu becomes an option, keyed by term id.
	 *
	 * The id survives a rename, which the slug does not.
	 *
	 * @return void
	 */
	public function test_menus_are_listed_by_id(): void {
		$GLOBALS['fk_nav_menus'] = [
			(object) [ 'term_id' => 7, 'slug' => 'primary', 'name' => 'Primary Menu' ],
			(object) [ 'term_id' => 9, 'slug' => 'footer', 'name' => 'Footer Menu' ],
		];

		$markup = $this->render( 'nav_menu' );

		$this->assertStringContainsString( 'value="7"', $markup );
		$this->assertStringContainsString( '>Primary Menu<', $markup );
		$this->assertStringContainsString( 'value="9"', $markup );
	}

	/**
	 * return => slug stores the slug instead.
	 *
	 * @return void
	 */
	public function test_menus_can_be_keyed_by_slug(): void {
		$GLOBALS['fk_nav_menus'] = [
			(object) [ 'term_id' => 7, 'slug' => 'primary', 'name' => 'Primary Menu' ],
		];

		$markup = $this->render( 'nav_menu', [ 'return' => 'slug' ] );

		$this->assertStringContainsString( 'value="primary"', $markup );
		$this->assertStringNotContainsString( 'value="7"', $markup );
	}

	/**
	 * A site with no menus renders an empty control, not a warning.
	 *
	 * @return void
	 */
	public function test_no_menus_is_not_an_error(): void {
		$markup = $this->render( 'nav_menu' );

		$this->assertStringContainsString( '<select', $markup );
	}

	/**
	 * A filter that returns something other than an array does not fatal.
	 *
	 * wp_get_nav_menus() is apply_filters( 'wp_get_nav_menus', get_terms( ... ) ),
	 * so any plugin can make it return anything at all -- and get_terms()
	 * itself returns a WP_Error for a taxonomy that is not registered.
	 * foreach over either is a fatal on the menu screen.
	 *
	 * @return void
	 */
	public function test_a_filtered_non_array_is_survived(): void {
		foreach ( [ false, null, 'nonsense', 42 ] as $returned ) {
			$GLOBALS['fk_nav_menus'] = $returned;

			$markup = $this->render( 'nav_menu' );

			$this->assertStringContainsString( '<select', $markup, var_export( $returned, true ) );
		}
	}

	/**
	 * Both types are registered under the ids they document.
	 *
	 * @return void
	 */
	public function test_both_types_are_registered(): void {
		$registry = new Registry();

		$this->assertContains( 'nav_menu', $registry->ids() );
		$this->assertContains( 'icon', $registry->ids() );
		$this->assertSame( 'icon', $registry->get( 'icon' )->id() );
	}

}
