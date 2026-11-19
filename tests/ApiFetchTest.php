<?php
/**
 * How the kit's script reaches the REST API.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Plugin\Assets as Files;

/**
 * The script's requests go through apiFetch, which renews a nonce that ran
 * out while the screen was open; tests/js/smoke.test.js runs them.
 */
final class ApiFetchTest extends TestCase {

	public function test_the_script_needs_api_fetch(): void {
		$manifest = Files::built_manifest( dirname( __DIR__ ) . '/src/Assets.php', 'field-kit' );

		$this->assertNotNull( $manifest );
		$this->assertContains( 'wp-api-fetch', $manifest['dependencies'] );
		$this->assertStringContainsString( "import apiFetch from '@wordpress/api-fetch';", Scripts::source( 'lib/request' ) );
	}

	public function test_no_request_sends_the_page_nonce_through_fetch_itself(): void {
		$script = Scripts::source();

		// Every request is request()'s, through apiFetch: no fetch of its
		// own. (IconPicker's fetch() method is a definition, not a call.)
		$this->assertSame( 0, preg_match_all( '/(?<![.\w])(?:window\.)?fetch\(\s*[a-z.]+\s*,(?![^)]*\)\s*\{)/i', $script ) );
		$this->assertSame( 1, substr_count( $script, "from '@wordpress/api-fetch'" ) );
	}
}
