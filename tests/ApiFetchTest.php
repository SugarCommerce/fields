<?php
/**
 * How the kit's script reaches the REST API.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The script's requests go through wp.apiFetch, which renews a nonce that ran
 * out while the screen was open; tests/js/smoke.js runs them.
 */
final class ApiFetchTest extends TestCase {

	public function test_the_script_needs_api_fetch(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Assets.php' );

		$this->assertMatchesRegularExpression( "#'js/field-kit\.js',\s*\[[^\]]*'wp-api-fetch'#", $source );
	}

	public function test_no_request_sends_the_page_nonce_through_fetch_itself(): void {
		$script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/field-kit.js' );

		// The one fetch left is request()'s fallback for a page without apiFetch.
		$this->assertSame( 1, preg_match_all( '/(?<![.\w])(?:window\.)?fetch\(\s*[a-z.]+\s*,/i', $script ) );
		$this->assertStringContainsString( 'return window.fetch( url, init );', $script );
	}
}
