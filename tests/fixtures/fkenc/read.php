<?php
/**
 * Read one fixture file's values back through EncryptedContext, in a process
 * of its own: the salts it needs are constants, and the test suite's process
 * has already defined others.
 *
 * Usage: php read.php B.json (or C.json). Prints one line per value; exits 1
 * when any does not read back exactly, or when a fresh write is under the old
 * key instead of the site's.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

$fixture  = json_decode( (string) file_get_contents( __DIR__ . '/' . basename( $argv[1] ?? '' ) ), true );
$scenario = $fixture['scenario'] ?? '';

define( 'ABSPATH', '/' );

if ( 'C' === $scenario ) {
	foreach ( [ 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'LOGGED_IN_SALT', 'NONCE_KEY' ] as $constant ) {
		define( $constant, 'put your unique phrase here' );
	}
}

function wp_salt( $scheme = 'auth' ) {
	return 'fk-test-salt-' . $scheme;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function _doing_it_wrong( ...$args ) {}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return $text;
}

class WP_Error {
	public function __construct( ...$args ) {}
}

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';

use SugarCommerce\Fields\Context\EncryptedContext;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;

$inner = new class() implements Context {
	public mixed $stored = null;

	public function read( int|string $object_id, Field $field ): mixed {
		return $this->stored;
	}

	public function write( int|string $object_id, Field $field, mixed $value ): void {
		$this->stored = $value;
	}

	public function delete( int|string $object_id, Field $field ): void {
		$this->stored = null;
	}
};

$field = ( new ReflectionClass( Field::class ) )->newInstanceWithoutConstructor();
( new ReflectionProperty( Field::class, 'config' ) )->setValue( $field, [ 'encrypted' => true ] );

$context = new EncryptedContext( $inner );
$failed  = 0;

foreach ( [ 'json', 'v0' ] as $envelope ) {
	foreach ( $fixture[ $envelope ] as $name => $row ) {
		$inner->stored = $row['stored'];
		$read          = $context->read( 0, $field );
		$failed       += $read === $row['value'] ? 0 : 1;

		printf( "%s %s %s\n", $read === $row['value'] ? 'ok' : 'FAILED', $envelope, $name );
	}
}

// Written now, it reads back, and not under the old key.
$context->write( 0, $field, 'fresh' );

$old_key   = hex2bin( $fixture['key_hex'] );
$payload   = (string) base64_decode( substr( (string) $inner->stored, strlen( 'fkenc:j:' ) ), true );
$opened    = openssl_decrypt( substr( $payload, 28 ), 'aes-256-gcm', $old_key, OPENSSL_RAW_DATA, substr( $payload, 0, 12 ), substr( $payload, 12, 16 ) );

if ( 'fresh' !== $context->read( 0, $field ) || false !== $opened ) {
	++$failed;
	echo "FAILED fresh write\n";
}

exit( $failed > 0 ? 1 : 0 );
