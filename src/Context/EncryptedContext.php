<?php
/**
 * Encrypting Context Decorator
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Context;

use RuntimeException;
use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Contracts\Flushable;
use SugarCommerce\Fields\Contracts\Registrable;
use SugarCommerce\Fields\Field;
use SugarCommerce\Security\Encryption\Cipher;

/**
 * Encrypts fields marked `encrypted` at rest, whatever they are stored in.
 *
 * A decorator rather than a feature of one context: an API key is no less
 * worth encrypting because it lives in post meta rather than an option, and
 * the settings library having its own copy of this was the reason it was only
 * ever available there.
 *
 * The encryption is sugarcommerce/security's ({@see Cipher}): AES-256-GCM
 * under the site's key -- `WP_ENCRYPTION_KEY`, or one derived from the salts
 * in wp-config.php. This class keeps only its own marker, `fkenc:`, in front
 * of the cipher's payload (base64 of nonce, tag and ciphertext), so values
 * written before the move still read: their payload is the same shape, and
 * the key they were written under is handed to the cipher to try as well.
 *
 * The key comes from the site. That has a consequence worth stating plainly: values encrypted on one site cannot be read on another,
 * and rotating salts makes existing values unreadable. That is the correct
 * trade for a credential — it is why a leaked database alone is not enough to
 * use one — but it means an encrypted field is not something to put in a
 * migration and expect to survive.
 */
final class EncryptedContext implements Context, Flushable, Registrable {

	/**
	 * Marker identifying a value this class wrote, before it encoded types.
	 *
	 * The payload is the plain string. Read only — nothing writes it now.
	 */
	private const PREFIX = 'fkenc:';

	/**
	 * Marker identifying a value whose payload is JSON.
	 *
	 * Versioned rather than reused, because the two cannot be told apart by
	 * looking: a v0 payload of `123` and a v1 payload of `123` decode to a
	 * string and an int respectively, and guessing would silently change the
	 * type of every stored number.
	 */
	private const PREFIX_JSON = 'fkenc:j:';

	/**
	 * The constants this class once made its own key from, in that order.
	 *
	 * Values written before the move to sugarcommerce/security are under
	 * sha256 of these joined; the cipher tries that key when the site's
	 * fails. Nothing is written under it.
	 */
	private const OLD_KEY_CONSTANTS = [ 'LOGGED_IN_KEY', 'LOGGED_IN_SALT', 'AUTH_KEY', 'SECURE_AUTH_KEY' ];

	/**
	 * The cipher, once made; false when it cannot be.
	 *
	 * @var Cipher|false|null
	 */
	private static Cipher|false|null $cipher = null;

	/**
	 * The context being decorated.
	 *
	 * @var Context
	 */
	private Context $inner;

	/**
	 * Construct.
	 *
	 * @param Context $inner The context to wrap.
	 */
	public function __construct( Context $inner ) {
		$this->inner = $inner;
	}

	/**
	 * Read, decrypting if this field is encrypted.
	 *
	 * @param int|string $object_id The object id.
	 * @param Field      $field     The field.
	 *
	 * @return mixed
	 */
	public function read( int|string $object_id, Field $field ): mixed {
		$value = $this->inner->read( $object_id, $field );

		// Ciphertext is always a string, whatever went in, and a string
		// without the marker is what the field held before it was marked
		// encrypted. Neither is decrypted; both are handed back as they are.
		if ( ! $this->wants( $field ) || ! self::is_ciphertext( $value ) ) {
			return $value;
		}

		// Unreadable is empty, not the marker and payload. Handed to a text
		// input, the ciphertext would come back through sanitize on the next
		// save as though somebody had typed it.
		$plain = self::available() ? $this->decrypt( $value ) : null;

		if ( null === $plain ) {
			return '';
		}

		if ( ! str_starts_with( $value, self::PREFIX_JSON ) ) {
			return $plain;
		}

		$decoded = json_decode( $plain, true );

		// A payload that will not decode is not something to hand back as a
		// raw JSON string: that would put `{"a":1}` in a text field.
		return null === $decoded && 'null' !== $plain ? '' : $decoded;
	}

	/**
	 * Write, encrypting if this field is encrypted.
	 *
	 * @param int|string $object_id The object id.
	 * @param Field      $field     The field.
	 * @param mixed      $value     Sanitized value.
	 *
	 * @return void
	 */
	public function write( int|string $object_id, Field $field, mixed $value ): void {
		// Asked for and not possible is a refusal, not a downgrade. Storing
		// the plaintext because OpenSSL or the salts were missing is the one
		// outcome the field was marked to prevent, and the one nobody would
		// notice until the database leaked.
		if ( $this->wants( $field ) && ! self::available() ) {
			self::refuse( $field );

			return;
		}

		if ( $this->applies( $field ) && $this->worth_encrypting( $value ) ) {
			// Every type, not only the ones that store a string. A group, a
			// repeater, a set of checkboxes — anything marked encrypted has
			// to be, and silently storing an array in the clear because it
			// was not a string is the worst possible answer: the field says
			// encrypted and the database says otherwise.
			$encoded = wp_json_encode( $value );

			$encrypted = false === $encoded ? null : $this->encrypt( self::PREFIX_JSON, $encoded );

			// A failed encryption must not fall through to storing the
			// plaintext: that is the one outcome worse than not saving.
			if ( null === $encrypted ) {
				return;
			}

			$value = $encrypted;
		}

		$this->inner->write( $object_id, $field, $value );
	}

	/**
	 * Whether a value needs encrypting on the way in.
	 *
	 * Two things are left alone. Emptiness, which reveals nothing and would
	 * otherwise make "no value" indistinguishable from "a value" by length.
	 * And anything already carrying a marker: everything that writes an
	 * option back wholesale — a reset, an import, a plain update_option() —
	 * hands back what it read, and encrypting that again leaves a value that
	 * decrypts to ciphertext.
	 *
	 * @param mixed $value The value about to be stored.
	 *
	 * @return bool
	 */
	private function worth_encrypting( mixed $value ): bool {
		if ( null === $value || '' === $value || [] === $value ) {
			return false;
		}

		return ! is_string( $value ) || ! str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Remove a value.
	 *
	 * @param int|string $object_id The object id.
	 * @param Field      $field     The field.
	 *
	 * @return void
	 */
	public function delete( int|string $object_id, Field $field ): void {
		// An unreadable secret is not an empty one. The form showed a blank
		// because the value would not decrypt -- the salts were rotated, or
		// OpenSSL went away -- and a save that read the blank as "cleared"
		// would finish the job by deleting the only copy. It stays until
		// something readable is written over it.
		if ( $this->wants( $field ) && $this->unreadable( $this->inner->read( $object_id, $field ) ) ) {
			return;
		}

		$this->inner->delete( $object_id, $field );
	}

	/**
	 * Whether this field asked to be encrypted, and whether we can.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	private function applies( Field $field ): bool {
		return $this->wants( $field ) && self::available();
	}

	/**
	 * Whether this field asked to be encrypted.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	private function wants( Field $field ): bool {
		return (bool) $field->get( 'encrypted', false );
	}

	/**
	 * Whether a stored value is ciphertext this class cannot read.
	 *
	 * @param mixed $stored The value as stored.
	 *
	 * @return bool
	 */
	private function unreadable( mixed $stored ): bool {
		return self::is_ciphertext( $stored ) && ( ! self::available() || null === $this->decrypt( $stored ) );
	}

	/**
	 * Say that a value was not saved, and why.
	 *
	 * @param Field $field The field.
	 *
	 * @return void
	 */
	private static function refuse( Field $field ): void {
		_doing_it_wrong(
			__METHOD__,
			sprintf(
				/* translators: %s: the field key */
				esc_html__( 'The field "%s" is marked encrypted, but encryption is not available here: OpenSSL with AES-256-GCM and the salts in wp-config.php are needed. The value was not saved.', 'sugarcommerce' ),
				esc_html( $field->key() )
			),
			'1.0.0'
		);
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
		return null !== self::cipher();
	}

	/**
	 * The cipher: the site's key, and the key this class used before.
	 *
	 * @return Cipher|null Null without OpenSSL's AES-256-GCM or any key
	 *                     material.
	 */
	private static function cipher(): ?Cipher {
		if ( null === self::$cipher ) {
			try {
				self::$cipher = ( new Cipher( 'fields' ) )->also_open( self::old_key_material() );
			} catch ( RuntimeException ) {
				self::$cipher = false;
			}
		}

		return self::$cipher ?: null;
	}

	/**
	 * Forget the cipher, so the next one is made from the salts as they
	 * are now. For tests that change them.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$cipher = null;
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
	 * Encrypt a value.
	 *
	 * @param string $prefix Marker to write it under.
	 * @param string $value  Plain value.
	 *
	 * @return string|null Null when encryption failed.
	 */
	private function encrypt( string $prefix, string $value ): ?string {
		$sealed = self::cipher()?->seal( $value );

		return is_string( $sealed ) ? $prefix . $sealed : null;
	}

	/**
	 * Decrypt a value.
	 *
	 * A value without the marker is returned as it is: a field that was
	 * marked encrypted after it already held something must not lose it.
	 *
	 * @param string $value Stored value.
	 *
	 * @return string|null Null when the value is unreadable.
	 */
	private function decrypt( string $value ): ?string {
		// Longest marker first: the JSON one begins with the legacy one, so
		// testing in the other order strips too little and the payload no
		// longer base64-decodes.
		$prefix = str_starts_with( $value, self::PREFIX_JSON )
			? self::PREFIX_JSON
			: ( str_starts_with( $value, self::PREFIX ) ? self::PREFIX : '' );

		if ( '' === $prefix ) {
			return $value;
		}

		// GCM authenticates: a payload that was cut short, changed, or made
		// under neither key does not open, and nothing derived from it is
		// returned.
		$plain = self::cipher()?->open( substr( $value, strlen( $prefix ) ) );

		return is_string( $plain ) ? $plain : null;
	}

	/**
	 * Flush the wrapped store.
	 *
	 * A decorator is what the field set holds, so if it did not pass this on
	 * an option-backed set behind one would stage every value and write none.
	 *
	 * @return void
	 */
	public function save(): void {
		if ( $this->inner instanceof Flushable ) {
			$this->inner->save();
		}
	}

	/**
	 * The kind of meta the wrapped store holds.
	 *
	 * A decorated store is still the store it decorates. Without this a
	 * settings page's encrypted field set would look unregistrable, which is
	 * true, and a term screen's would too, which is not.
	 *
	 * @return string
	 */
	public function meta_type(): string {
		return $this->inner instanceof Registrable ? $this->inner->meta_type() : '';
	}
}
