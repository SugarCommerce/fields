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

use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Contracts\Flushable;
use SugarCommerce\Fields\Contracts\Registrable;
use SugarCommerce\Fields\Contracts\Sealer;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Traits\EncryptedContext\Sealing;

/**
 * Encrypts fields marked `encrypted` at rest, whatever they are stored in.
 *
 * A decorator rather than a feature of one context: an API key is no less
 * worth encrypting because it lives in post meta rather than an option, and
 * the settings library having its own copy of this was the reason it was only
 * ever available there.
 *
 * The encryption is sugarcommerce/security's ({@see Cipher}): AES-256-GCM
 * under a key derived from the site's -- `WP_ENCRYPTION_KEY`, or one made
 * from the salts in wp-config.php -- for a named purpose. This class keeps
 * only its own marker, `fkenc:v3:`, in front of the cipher's payload (base64
 * of nonce, tag and ciphertext).
 *
 * **Every value is sealed for a purpose.** The context names one
 * (`sugarcommerce/fields` unless the caller says otherwise) and a field may
 * name its own with `'encrypted' => 'my-plugin/stripe'`, so a key one plugin
 * stored does not open under another plugin's purpose even though both start
 * from the same salts. What was written before purposes still reads:
 * `fkenc:j:` values are under the shared site key, `fkenc:` ones under the
 * key this package made itself before the move to sugarcommerce/security, and
 * the cipher tries both. Saving one writes it under its purpose
 * ({@see self::is_legacy()}, {@see self::reseal()}).
 *
 * The key comes from the site. That has a consequence worth stating plainly: values encrypted on one site cannot be read on another,
 * and rotating salts makes existing values unreadable. That is the correct
 * trade for a credential — it is why a leaked database alone is not enough to
 * use one — but it means an encrypted field is not something to put in a
 * migration and expect to survive.
 */
final class EncryptedContext implements Context, Flushable, Registrable, Sealer {

	use Sealing;

	/**
	 * Marker identifying a value this class wrote, before it encoded types.
	 *
	 * The payload is the plain string. Read only — nothing writes it now.
	 */
	private const string PREFIX = 'fkenc:';

	/**
	 * Marker identifying a value whose payload is JSON.
	 *
	 * Versioned rather than reused, because the two cannot be told apart by
	 * looking: a v0 payload of `123` and a v1 payload of `123` decode to a
	 * string and an int respectively, and guessing would silently change the
	 * type of every stored number.
	 */
	private const string PREFIX_JSON = 'fkenc:j:';

	/**
	 * Marker identifying a JSON payload sealed under a purpose's own key.
	 *
	 * Named for the cipher's format of the same idea (`v3:`). A `fkenc:j:`
	 * payload carries no word about its key, and a reader has to know which
	 * value is on the shared key to know which to move.
	 */
	private const string PREFIX_PURPOSE = 'fkenc:v3:';

	/**
	 * The purpose a context seals for when it is not told one.
	 */
	public const string DEFAULT_PURPOSE = 'sugarcommerce/fields';

	/**
	 * The constants this class once made its own key from, in that order.
	 *
	 * Values written before the move to sugarcommerce/security are under
	 * sha256 of these joined; the cipher tries that key when the site's
	 * fails. Nothing is written under it.
	 */
	private const array OLD_KEY_CONSTANTS = [ 'LOGGED_IN_KEY', 'LOGGED_IN_SALT', 'AUTH_KEY', 'SECURE_AUTH_KEY' ];

	/**
	 * The cipher for each purpose, once made; false when it cannot be.
	 *
	 * @var array<string, Cipher|false>
	 */
	private static array $ciphers = [];

	/**
	 * The context being decorated.
	 *
	 * @var Context
	 */
	private Context $inner;

	/**
	 * What this context's values are, for the key they are sealed under.
	 *
	 * @var string
	 */
	private string $purpose;

	/**
	 * Construct.
	 *
	 * @param Context     $inner   The context to wrap.
	 * @param string|null $purpose What the values are, such as
	 *                             `my-plugin/settings`: each purpose seals
	 *                             under a key of its own. A field naming
	 *                             its own (`'encrypted' => 'my-plugin/stripe'`)
	 *                             wins. DEFAULT_PURPOSE when left out.
	 */
	public function __construct( Context $inner, ?string $purpose = null ) {
		$this->inner   = $inner;
		$this->purpose = self::clean_purpose( $purpose ?? '' ) ?: self::DEFAULT_PURPOSE;
	}

	/**
	 * Read, decrypting if this field is encrypted.
	 *
	 * @param int|string $object_id The object id.
	 * @param Field      $field     The field.
	 *
	 * @return mixed
	 */
	#[\Override]
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
		$plain = self::available() ? self::decrypt( $value, $this->purpose_of( $field ) ) : null;

		if ( null === $plain ) {
			return '';
		}

		if ( ! self::is_json( $value ) ) {
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
	#[\Override]
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

			$encrypted = false === $encoded ? null : self::encrypt( $encoded, $this->purpose_of( $field ) );

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
	 * Whether a value is ciphertext this context wrote for the field and
	 * can open -- what a whole-value write hands back -- rather than text
	 * somebody typed with the marker in front.
	 *
	 * @param Field  $field The field.
	 * @param string $value The value.
	 *
	 * @return bool
	 */
	#[\Override]
	public function opens( Field $field, string $value ): bool {
		return $this->applies( $field )
			&& self::is_ciphertext( $value )
			&& null !== self::decrypt( $value, $this->purpose_of( $field ) );
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
	#[\Override]
	public function delete( int|string $object_id, Field $field ): void {
		// An unreadable secret is not an empty one. The form showed a blank
		// because the value would not decrypt -- the salts were rotated, or
		// OpenSSL went away -- and a save that read the blank as "cleared"
		// would finish the job by deleting the only copy. It stays until
		// something readable is written over it.
		if ( $this->wants( $field ) && $this->unreadable( $this->inner->read( $object_id, $field ), $this->purpose_of( $field ) ) ) {
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
	 * The purpose a field's value is sealed for.
	 *
	 * Its own where `encrypted` names one, the context's otherwise. `true`
	 * names nothing: it is the switch, not a purpose called "1".
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	private function purpose_of( Field $field ): string {
		$named = $field->get( 'encrypted', false );

		return is_string( $named ) && '' !== self::clean_purpose( $named ) ? self::clean_purpose( $named ) : $this->purpose;
	}

	/**
	 * A purpose label, trimmed. `my-plugin/api-keys`: lower case, so a
	 * capital letter typed once is not a second key.
	 *
	 * @param string $purpose As given.
	 *
	 * @return string
	 */
	private static function clean_purpose( string $purpose ): string {
		return strtolower( trim( $purpose ) );
	}

	/**
	 * Whether a stored value is ciphertext this class cannot read.
	 *
	 * @param mixed  $stored  The value as stored.
	 * @param string $purpose The purpose it was sealed for.
	 *
	 * @return bool
	 */
	private function unreadable( mixed $stored, string $purpose ): bool {
		return self::is_ciphertext( $stored ) && ( ! self::available() || null === self::decrypt( (string) $stored, $purpose ) );
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
	 * Flush the wrapped store.
	 *
	 * A decorator is what the field set holds, so if it did not pass this on
	 * an option-backed set behind one would stage every value and write none.
	 *
	 * @return void
	 */
	#[\Override]
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
	#[\Override]
	public function meta_type(): string {
		return $this->inner instanceof Registrable ? $this->inner->meta_type() : '';
	}
}
