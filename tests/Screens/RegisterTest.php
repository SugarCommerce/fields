<?php
/**
 * The static entry points that replaced the global functions.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests\Screens;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Screens\BulkEditFields;
use SugarCommerce\Fields\Screens\PostFields;
use SugarCommerce\Fields\Screens\QuickEditFields;
use SugarCommerce\Fields\Screens\TermFields;
use SugarCommerce\Fields\Screens\UserFields;
use WP_Term;

/**
 * register_post_fields() and the rest were global functions, which a library
 * bundled into several plugins cannot define. Each is now a static on its
 * screen; these hold them to what the functions did.
 *
 * Instances are kept for the life of the request, so every test here uses
 * ids and keys of its own.
 */
final class RegisterTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		pf_reset_globals();
		tf_reset_globals();
		uf_reset_globals();
		$GLOBALS['fk_terms'] = [];
	}

	/**
	 * Clear the terms this file made up.
	 */
	protected function tearDown(): void {
		$GLOBALS['fk_terms'] = [];
	}

	/**
	 * PostFields::register() makes the metabox, registered for reading back.
	 */
	public function test_post_fields_register(): void {
		$box = PostFields::register(
			'reg_box',
			[
				'post_types' => 'product',
				'prefix'     => '_reg_',
				'fields'     => [ 'sku' => [ 'type' => 'text' ] ],
			]
		);

		$this->assertSame( $box, PostFields::get_instance( 'reg_box' ) );
		$this->assertSame( [ 'product' ], $box->get_config()['post_types'] );
		$this->assertArrayHasKey( '_reg_sku', PostFields::get_metabox_fields( 'reg_box' ) );
	}

	/**
	 * PostFields::value() reads through the named metabox, or the first that
	 * has the key, and is null for a key no metabox has.
	 */
	public function test_post_fields_value(): void {
		PostFields::register( 'reg_value_a', [ 'fields' => [ 'reg_colour' => [ 'type' => 'text' ] ] ] );
		PostFields::register( 'reg_value_b', [ 'fields' => [ 'reg_size' => [ 'type' => 'text', 'default' => 'M' ] ] ] );

		$GLOBALS['fk_meta']['post'][7] = [ 'reg_colour' => 'blue' ];

		$this->assertSame( 'blue', PostFields::value( 7, 'reg_colour' ) );
		$this->assertSame( 'blue', PostFields::value( 7, 'reg_colour', 'reg_value_a' ) );
		$this->assertSame( 'M', PostFields::value( 7, 'reg_size' ), 'the first metabox without the key is passed over' );
		$this->assertNull( PostFields::value( 7, 'reg_colour', 'reg_value_b' ), 'a metabox without the key has no value for it' );
		$this->assertNull( PostFields::value( 7, 'reg_colour', 'reg_missing_box' ) );
		$this->assertNull( PostFields::value( 7, 'reg_nobody_has_this' ) );
	}

	/**
	 * TermFields::register() takes one taxonomy or several, and skips an
	 * empty one rather than stopping the rest.
	 */
	public function test_term_fields_register(): void {
		$fields = [ 'reg_badge' => [ 'type' => 'text' ] ];

		$one = TermFields::register( 'reg_tax_one', $fields );

		$this->assertCount( 1, $one );
		$this->assertSame( $one[0], TermFields::get_instance( 'reg_tax_one' ) );

		$log = ini_set( 'error_log', '/dev/null' );

		try {
			$several = TermFields::register( [ 'reg_tax_two', ' ', 'reg_tax_three' ], $fields );
		} finally {
			ini_set( 'error_log', (string) $log );
		}

		$this->assertCount( 2, $several );
		$this->assertNotNull( TermFields::get_instance( 'reg_tax_three' ) );
	}

	/**
	 * TermFields::value() finds the term's taxonomy and reads through its set.
	 */
	public function test_term_fields_value(): void {
		TermFields::register( 'reg_tax_value', [ 'reg_shade' => [ 'type' => 'text', 'default' => 'none' ] ] );

		$term           = new WP_Term();
		$term->term_id  = 12;
		$term->taxonomy = 'reg_tax_value';

		$GLOBALS['fk_terms'][12]       = $term;
		$GLOBALS['fk_meta']['term'][12] = [ 'reg_shade' => 'teal' ];

		$this->assertSame( 'teal', TermFields::value( 12, 'reg_shade' ) );
		$this->assertSame( 'fb', TermFields::value( 12, 'reg_unknown', 'fb' ) );
		$this->assertSame( 'fb', TermFields::value( 99, 'reg_shade', 'fb' ), 'no such term' );

		$GLOBALS['fk_terms'][13] = new \WP_Error( 'nope', 'Nope' );

		$this->assertSame( 'fb', TermFields::value( 13, 'reg_shade', 'fb' ), 'an error is not a term' );

		$other           = new WP_Term();
		$other->term_id  = 14;
		$other->taxonomy = 'reg_tax_nobody_registered';

		$GLOBALS['fk_terms'][14] = $other;

		$this->assertSame( 'fb', TermFields::value( 14, 'reg_shade', 'fb' ), 'a taxonomy with no fields' );
	}

	/**
	 * UserFields::register() keeps its section title, and value() finds the
	 * set by the field's key.
	 */
	public function test_user_fields_register_and_value(): void {
		$set = UserFields::register( [ 'reg_company' => [ 'type' => 'text' ] ], 'Work' );

		$this->assertSame( $set, UserFields::get_instance_for( 'reg_company' ) );

		$GLOBALS['fk_meta']['user'][3] = [ 'reg_company' => 'Acme' ];

		$this->assertSame( 'Acme', UserFields::value( 3, 'reg_company' ) );
		$this->assertSame( 'fb', UserFields::value( 4, 'reg_company', 'fb' ), 'nothing stored' );
		$this->assertSame( 'fb', UserFields::value( 3, 'reg_unregistered', 'fb' ) );
	}

	/**
	 * Quick and bulk edit take one post type or several, one instance each.
	 */
	public function test_quick_and_bulk_edit_register(): void {
		$fields = [ 'reg_stock' => [ 'type' => 'number' ] ];

		$quick = QuickEditFields::register( [ 'reg_pt_a', 'reg_pt_b' ], $fields );
		$bulk  = BulkEditFields::register( 'reg_pt_c', $fields );

		$this->assertCount( 2, $quick );
		$this->assertContainsOnlyInstancesOf( QuickEditFields::class, $quick );
		$this->assertArrayHasKey( 'reg_stock', QuickEditFields::get_fields_for( 'reg_pt_b' ) );

		$this->assertCount( 1, $bulk );
		$this->assertArrayHasKey( 'reg_stock', BulkEditFields::get_fields_for( 'reg_pt_c' ) );
	}

	/**
	 * Quick edit still refuses an empty post type, as the function did.
	 */
	public function test_quick_edit_refuses_an_empty_post_type(): void {
		$this->expectException( \Exception::class );

		QuickEditFields::register( '', [ 'reg_x' => [ 'type' => 'text' ] ] );
	}
}
