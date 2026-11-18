<?php
/**
 * Values the old encryption wrote, read by the new.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Context\EncryptedContext;
use SugarCommerce\Fields\Context\OptionContext;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Security\Encryption\Cipher;

/**
 * Encryption moved to sugarcommerce/security, and every `fkenc:` value
 * already in a database has to keep reading exactly as it did.
 *
 * tests/fixtures/fkenc holds values made by this class before the move
 * (fields 9e1d1a1), each file naming the salts they were made under: A with
 * this suite's salts, read here; B with no salt constants and C with the
 * wp-config-sample.php placeholders, read by read.php in a process of its
 * own, because salts are constants and this process already has A's.
 */
final class EncryptionFixturesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['fk_options'] = [];
	}

	/**
	 * @return array<string, array{string, string, mixed}>
	 */
	public static function stored(): array {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fkenc/A.json' ), true );
		$cases   = [];

		foreach ( [ 'json', 'v0' ] as $envelope ) {
			foreach ( $fixture[ $envelope ] as $name => $row ) {
				$cases[ "{$envelope} {$name}" ] = [ $row['stored'], $row['value'] ];
			}
		}

		return $cases;
	}

	#[DataProvider( 'stored' )]
	public function test_a_value_the_old_code_wrote_reads_the_same( string $stored, mixed $value ): void {
		$GLOBALS['fk_options']['fk_test'] = [ 'api_key' => $stored ];

		$this->assertSame( $value, ( new EncryptedContext( new OptionContext( 'fk_test' ) ) )->read( 0, $this->field() ) );
	}

	public function test_a_new_value_is_under_the_sites_key_with_the_same_marker(): void {
		$inner   = new OptionContext( 'fk_test' );
		$context = new EncryptedContext( $inner );

		$context->write( 0, $this->field(), [ 'a' => 1 ] );
		$inner->save();

		$stored = (string) $GLOBALS['fk_options']['fk_test']['api_key'];

		$this->assertStringStartsWith( 'fkenc:j:', $stored );
		$this->assertSame( [ 'a' => 1 ], $context->read( 0, $this->field() ) );

		// Security's cipher, with nothing but the site's key, opens it.
		$this->assertSame( '{"a":1}', ( new Cipher( 'any' ) )->open( substr( $stored, strlen( 'fkenc:j:' ) ) ) );
	}

	public function test_a_new_value_is_not_under_the_old_key(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fkenc/A.json' ), true );
		$inner   = new OptionContext( 'fk_test' );

		( new EncryptedContext( $inner ) )->write( 0, $this->field(), 'fresh' );
		$inner->save();

		$raw = (string) base64_decode( substr( (string) $GLOBALS['fk_options']['fk_test']['api_key'], strlen( 'fkenc:j:' ) ), true );

		$this->assertFalse( openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', (string) hex2bin( $fixture['key_hex'] ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) ) );
	}

	public function test_the_old_key_is_what_the_fixture_says(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fkenc/A.json' ), true );

		$this->assertSame( $fixture['key_hex'], hash( 'sha256', LOGGED_IN_KEY . LOGGED_IN_SALT . AUTH_KEY . SECURE_AUTH_KEY ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function other_sites(): array {
		return [
			'no salt constants' => [ 'B.json' ],
			'placeholder salts' => [ 'C.json' ],
		];
	}

	#[DataProvider( 'other_sites' )]
	public function test_values_from_sites_with_other_salts_read_the_same( string $file ): void {
		$output = [];
		$status = 1;

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/fixtures/fkenc/read.php' ) . ' ' . escapeshellarg( $file ) . ' 2>&1', $output, $status );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
		$this->assertCount( 9, array_filter( $output, static fn( string $line ): bool => str_starts_with( $line, 'ok ' ) ) );
	}

	private function field(): Field {
		return new Field( 'api_key', ( new Registry() )->get( 'text' ), [ 'encrypted' => true ], null );
	}
}
