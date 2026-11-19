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
 * own, because salts are constants and this process already has A's. V2
 * holds values made after the move and before purposes (fields b72bfbf),
 * under the shared site key.
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

	public function test_a_new_value_is_under_its_purposes_key(): void {
		$inner   = new OptionContext( 'fk_test' );
		$context = new EncryptedContext( $inner );

		$context->write( 0, $this->field(), [ 'a' => 1 ] );
		$inner->save();

		$stored = (string) $GLOBALS['fk_options']['fk_test']['api_key'];

		$this->assertStringStartsWith( 'fkenc:v3:', $stored );
		$this->assertSame( [ 'a' => 1 ], $context->read( 0, $this->field() ) );

		// Security's cipher, named for the default purpose, opens it; one
		// with only the shared site key does not.
		$payload = substr( $stored, strlen( 'fkenc:v3:' ) );

		$this->assertSame( '{"a":1}', ( new Cipher( 'any', null, EncryptedContext::DEFAULT_PURPOSE ) )->open( $payload ) );
		$this->assertInstanceOf( \WP_Error::class, ( new Cipher( 'any' ) )->open( $payload ) );
	}

	public function test_a_new_value_is_not_under_the_old_key(): void {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fkenc/A.json' ), true );
		$inner   = new OptionContext( 'fk_test' );

		( new EncryptedContext( $inner ) )->write( 0, $this->field(), 'fresh' );
		$inner->save();

		$raw = (string) base64_decode( substr( (string) $GLOBALS['fk_options']['fk_test']['api_key'], strlen( 'fkenc:v3:' ) ), true );

		$this->assertFalse( openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', (string) hex2bin( $fixture['key_hex'] ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) ) );
	}

	/**
	 * Values the code before purposes wrote: `fkenc:j:` on the shared key
	 * (fields b72bfbf), in tests/fixtures/fkenc/V2.json.
	 *
	 * @return array<string, array{string, mixed}>
	 */
	public static function shared_key(): array {
		$fixture = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fkenc/V2.json' ), true );
		$cases   = [];

		foreach ( $fixture['json'] as $name => $row ) {
			$cases[ $name ] = [ $row['stored'], $row['value'] ];
		}

		return $cases;
	}

	#[DataProvider( 'shared_key' )]
	public function test_a_value_on_the_shared_key_reads_under_any_purpose( string $stored, mixed $value ): void {
		$GLOBALS['fk_options']['fk_test'] = [ 'api_key' => $stored ];

		$this->assertTrue( EncryptedContext::is_legacy( $stored ) );
		$this->assertSame( $value, ( new EncryptedContext( new OptionContext( 'fk_test' ) ) )->read( 0, $this->field() ) );
		$this->assertSame( $value, ( new EncryptedContext( new OptionContext( 'fk_test' ), 'shop/stripe' ) )->read( 0, $this->field() ) );
		$this->assertSame( $value, ( new EncryptedContext( new OptionContext( 'fk_test' ) ) )->read( 0, $this->field( 'shop/paypal' ) ) );
	}

	#[DataProvider( 'shared_key' )]
	public function test_saving_a_shared_key_value_moves_it_to_its_purpose( string $stored, mixed $value ): void {
		$GLOBALS['fk_options']['fk_test'] = [ 'api_key' => $stored ];

		$inner   = new OptionContext( 'fk_test' );
		$context = new EncryptedContext( $inner, 'shop/stripe' );

		$context->write( 0, $this->field(), $context->read( 0, $this->field() ) );
		$inner->save();

		$moved = (string) $GLOBALS['fk_options']['fk_test']['api_key'];

		$this->assertStringStartsWith( 'fkenc:v3:', $moved );
		$this->assertFalse( EncryptedContext::is_legacy( $moved ) );
		$this->assertSame( $value, $context->read( 0, $this->field() ) );
	}

	#[DataProvider( 'stored' )]
	public function test_reseal_moves_a_value_the_first_code_wrote( string $stored, mixed $value ): void {
		$sealed = EncryptedContext::reseal( $stored, 'shop/stripe' );

		$this->assertIsString( $sealed );
		$this->assertStringStartsWith( 'fkenc:v3:', $sealed );

		$GLOBALS['fk_options']['fk_test'] = [ 'api_key' => $sealed ];

		$this->assertSame( $value, ( new EncryptedContext( new OptionContext( 'fk_test' ), 'shop/stripe' ) )->read( 0, $this->field() ) );

		// Sealed for that purpose and no other.
		$this->assertSame( '', ( new EncryptedContext( new OptionContext( 'fk_test' ), 'shop/paypal' ) )->read( 0, $this->field() ) );
	}

	public function test_reseal_is_safe_twice_and_refuses_what_will_not_open(): void {
		$once  = (string) EncryptedContext::reseal( 'fkenc:j:' . base64_encode( random_bytes( 40 ) ), 'shop/stripe' );
		$plain = (string) EncryptedContext::reseal( 'never-encrypted', 'shop/stripe' );
		$twice = (string) EncryptedContext::reseal( $plain, 'shop/stripe' );

		$this->assertSame( '', $once );
		$this->assertNull( EncryptedContext::reseal( 'fkenc:v3:' . base64_encode( random_bytes( 40 ) ), 'shop/stripe' ) );

		$GLOBALS['fk_options']['fk_test'] = [ 'api_key' => $twice ];

		$this->assertStringStartsWith( 'fkenc:v3:', $twice );
		$this->assertSame( 'never-encrypted', ( new EncryptedContext( new OptionContext( 'fk_test' ), 'shop/stripe' ) )->read( 0, $this->field() ) );
	}

	public function test_each_purpose_has_its_own_key(): void {
		$inner = new OptionContext( 'fk_test' );

		( new EncryptedContext( $inner, 'shop/stripe' ) )->write( 0, $this->field(), 'sk_example_secret' );
		$inner->save();

		$this->assertSame( 'sk_example_secret', ( new EncryptedContext( new OptionContext( 'fk_test' ), 'Shop/Stripe ' ) )->read( 0, $this->field() ) );
		$this->assertSame( '', ( new EncryptedContext( new OptionContext( 'fk_test' ), 'shop/paypal' ) )->read( 0, $this->field() ) );
		$this->assertSame( '', ( new EncryptedContext( new OptionContext( 'fk_test' ) ) )->read( 0, $this->field() ) );
	}

	public function test_a_field_naming_a_purpose_wins_over_the_contexts(): void {
		$inner = new OptionContext( 'fk_test' );

		( new EncryptedContext( $inner, 'shop/settings' ) )->write( 0, $this->field( 'shop/stripe' ), 'sk_example_secret' );
		$inner->save();

		$this->assertSame( 'sk_example_secret', ( new EncryptedContext( new OptionContext( 'fk_test' ), 'shop/other' ) )->read( 0, $this->field( 'shop/stripe' ) ) );
		$this->assertSame( '', ( new EncryptedContext( new OptionContext( 'fk_test' ), 'shop/settings' ) )->read( 0, $this->field() ) );
	}

	public function test_a_value_another_purpose_cannot_read_is_not_deleted_by_a_blank_save(): void {
		$inner = new OptionContext( 'fk_test' );

		( new EncryptedContext( $inner, 'shop/stripe' ) )->write( 0, $this->field(), 'sk_example_secret' );
		$inner->save();

		$stored = $GLOBALS['fk_options']['fk_test']['api_key'];
		$other  = new OptionContext( 'fk_test' );

		( new EncryptedContext( $other, 'shop/paypal' ) )->delete( 0, $this->field() );
		$other->save();

		$this->assertSame( $stored, $GLOBALS['fk_options']['fk_test']['api_key'] );
	}

	public function test_only_values_from_before_purposes_are_legacy(): void {
		$this->assertTrue( EncryptedContext::is_legacy( 'fkenc:abc' ) );
		$this->assertTrue( EncryptedContext::is_legacy( 'fkenc:j:abc' ) );
		$this->assertFalse( EncryptedContext::is_legacy( 'fkenc:v3:abc' ) );
		$this->assertFalse( EncryptedContext::is_legacy( 'plain' ) );
		$this->assertFalse( EncryptedContext::is_legacy( [ 'fkenc:j:abc' ] ) );
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

	private function field( bool|string $encrypted = true ): Field {
		return new Field( 'api_key', ( new Registry() )->get( 'text' ), [ 'encrypted' => $encrypted ], null );
	}
}
