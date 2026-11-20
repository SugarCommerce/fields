<?php
/**
 * Encrypting Context Decorator: Sealing
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\EncryptedContext;

use RuntimeException;
use SugarCommerce\Security\Encryption\Cipher;

/**
 * Sealing and opening a value for a purpose, and recognising what an older key sealed.
 *
 * Part of EncryptedContext: only the class uses it.
 */
trait Sealing {

	/**
	 * Whether a stored value is ciphertext this class wrote before purposes:
	 * `fkenc:j:` on the shared key, or `fkenc:` on the key this package made
	 * before the move.
	 *
	 * Both still read. For a one-off move to per-purpose keys: find the
	 * values this is true of and write each back -- `write( $id, $field,
	 * read( $id, $field ) )` through the context, or {@see self::reseal()}
	 * on the raw stored string.
	 *
	 * @param mixed $value The value as stored.
	 *
	 * @return bool
	 */
	public static function is_legacy( mixed $value ): bool {
		return self::is_ciphertext( $value ) && ! str_starts_with( (string) $value, self::PREFIX_PURPOSE );
	}

	/**
	 * A stored value, sealed again under a purpose's own key.
	 *
	 * Reads any of the formats this class has written, under any key it has
	 * used, and returns `fkenc:v3:` for the purpose. A plain value, never
	 * encrypted, is encrypted. Safe to run twice.
	 *
	 *     $stored = EncryptedContext::reseal( $stored, 'my-plugin/stripe' );
	 *
	 * @param string $stored  The value as stored.
	 * @param string $purpose The purpose it belongs to.
	 *
	 * @return string|null The new stored value, or null when it does not
	 *                     open (or encryption is not possible here) -- stop
	 *                     rather than overwrite it.
	 */
	public static function reseal( string $stored, string $purpose ): ?string {
		$purpose = self::clean_purpose( $purpose ) ?: self::DEFAULT_PURPOSE;

		if ( ! self::available() ) {
			return null;
		}

		if ( ! self::is_ciphertext( $stored ) ) {
			$encoded = wp_json_encode( $stored );

			return false === $encoded ? null : self::encrypt( $encoded, $purpose );
		}

		$plain = self::decrypt( $stored, $purpose );

		if ( null === $plain ) {
			return null;
		}

		// A v0 payload is the string itself; the others are JSON already.
		if ( ! self::is_json( $stored ) ) {
			$plain = wp_json_encode( $plain );

			if ( false === $plain ) {
				return null;
			}
		}

		return self::encrypt( $plain, $purpose );
	}

	/**
	 * Whether a value is something this class wrote.
	 *
	 * Public because the meta registrar has to know: `update_metadata()`
	 * runs a registered sanitizer on whatever it is handed, and a number
	 * type asked to sanitize ciphertext returns zero.
	 *
	 * @param mixed $value The value as stored.
	 *
	 * @return bool
	 */
	public static function is_ciphertext( mixed $value ): bool {
		return is_string( $value ) && str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Whether encryption is possible in this environment.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return null !== self::cipher( self::DEFAULT_PURPOSE );
	}

	/**
	 * The cipher for a purpose: its own key derived from the site's, and
	 * the shared key and the key this class used before, to read with.
	 *
	 * @param string $purpose The purpose.
	 *
	 * @return Cipher|null Null without OpenSSL's AES-256-GCM or any key
	 *                     material.
	 */
	private static function cipher( string $purpose ): ?Cipher {
		if ( ! isset( self::$ciphers[ $purpose ] ) ) {
			try {
				self::$ciphers[ $purpose ] = ( new Cipher( 'fields', null, $purpose ) )->also_open( self::old_key_material() );
			} catch ( RuntimeException ) {
				self::$ciphers[ $purpose ] = false;
			}
		}

		return self::$ciphers[ $purpose ] ?: null;
	}

	/**
	 * Forget the ciphers, so the next ones are made from the salts as they
	 * are now. For tests that change them.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$ciphers = [];
	}

	/**
	 * What this class hashed into its key before the move.
	 *
	 * The constants that are set, joined; or, on a site that defines none
	 * of them, three of core's salts, as it used then. A site on the
	 * wp-config-sample.php placeholders gets the placeholders, which is a
	 * public key -- harmless to try, since the site's key is no longer made
	 * from them, and it lets those values be read and saved again.
	 *
	 * @return string
	 */
	private static function old_key_material(): string {
		$material = '';

		foreach ( self::OLD_KEY_CONSTANTS as $constant ) {
			$material .= defined( $constant ) ? (string) constant( $constant ) : '';
		}

		if ( '' === $material && function_exists( 'wp_salt' ) ) {
			$material = wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' );
		}

		return $material;
	}

	/**
	 * Encrypt a JSON payload for a purpose.
	 *
	 * @param string $json    The value, JSON-encoded.
	 * @param string $purpose The purpose.
	 *
	 * @return string|null Null when encryption failed.
	 */
	private static function encrypt( string $json, string $purpose ): ?string {
		$sealed = self::cipher( $purpose )?->seal( $json );

		return is_string( $sealed ) ? self::PREFIX_PURPOSE . $sealed : null;
	}

	/**
	 * Whether a stored value's payload is JSON rather than the bare string.
	 *
	 * @param string $value Stored value.
	 *
	 * @return bool
	 */
	private static function is_json( string $value ): bool {
		return str_starts_with( $value, self::PREFIX_PURPOSE ) || str_starts_with( $value, self::PREFIX_JSON );
	}

	/**
	 * Decrypt a value.
	 *
	 * A value without the marker is returned as it is: a field that was
	 * marked encrypted after it already held something must not lose it.
	 *
	 * @param string $value   Stored value.
	 * @param string $purpose The purpose it was sealed for.
	 *
	 * @return string|null Null when the value is unreadable.
	 */
	private static function decrypt( string $value, string $purpose ): ?string {
		// Longest markers first: both JSON ones begin with the legacy one,
		// so testing in the other order strips too little and the payload
		// no longer base64-decodes.
		$prefix = '';

		foreach ( [ self::PREFIX_PURPOSE, self::PREFIX_JSON, self::PREFIX ] as $marker ) {
			if ( str_starts_with( $value, $marker ) ) {
				$prefix = $marker;
				break;
			}
		}

		if ( '' === $prefix ) {
			return $value;
		}

		// A named cipher opens a payload under the purpose's key first, then
		// under the shared key and the old one, which is where a `fkenc:j:`
		// or a `fkenc:` value is. GCM authenticates: a payload that was cut
		// short, changed, or made under none of them does not open, and
		// nothing derived from it is returned.
		$plain = self::cipher( $purpose )?->open( substr( $value, strlen( $prefix ) ) );

		return is_string( $plain ) ? $plain : null;
	}
}
