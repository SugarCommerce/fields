<?php
/**
 * Base Meta Context
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Context;

use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Contracts\Registrable;
use SugarCommerce\Fields\Field;

/**
 * Anything stored through WordPress's metadata API.
 *
 * The meta API unslashes the value it is handed — update_metadata() calls
 * wp_unslash() on it — so a clean value must be slashed on the way in or a
 * literal backslash in the data is eaten. This is the single place that
 * happens; getting it wrong in five libraries independently is what this
 * class exists to prevent.
 */
abstract class AbstractMetaContext implements Context, Registrable {

	/**
	 * The metadata type: post, term, user or comment.
	 *
	 * @return string
	 */
	#[\Override]
	abstract public function meta_type(): string;

	/**
	 * Prefix applied to every key.
	 *
	 * @var string
	 */
	protected string $prefix;

	/**
	 * Construct.
	 *
	 * @param string $prefix Prefix applied to every meta key.
	 */
	public function __construct( string $prefix = '' ) {
		$this->prefix = $prefix;
	}

	/**
	 * The meta key a field is stored under.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	protected function meta_key( Field $field ): string {
		return (string) $field->get( 'meta_key', $this->prefix . $field->key() );
	}

	/**
	 * Read a field's stored value.
	 *
	 * @param int|string $object_id The object id.
	 * @param Field      $field     The field.
	 *
	 * @return mixed
	 */
	#[\Override]
	public function read( int|string $object_id, Field $field ): mixed {
		return get_metadata( $this->meta_type(), (int) $object_id, $this->meta_key( $field ), true );
	}

	/**
	 * Write a field's sanitized value.
	 *
	 * @param int|string $object_id The object id.
	 * @param Field      $field     The field.
	 * @param mixed      $value     Sanitized, unslashed value.
	 *
	 * @return void
	 */
	#[\Override]
	public function write( int|string $object_id, Field $field, mixed $value ): void {
		// Slashed on the way in because update_metadata() unslashes.
		update_metadata( $this->meta_type(), (int) $object_id, $this->meta_key( $field ), wp_slash( $value ) );
	}

	/**
	 * Remove a field's stored value.
	 *
	 * @param int|string $object_id The object id.
	 * @param Field      $field     The field.
	 *
	 * @return void
	 */
	#[\Override]
	public function delete( int|string $object_id, Field $field ): void {
		delete_metadata( $this->meta_type(), (int) $object_id, $this->meta_key( $field ) );
	}
}
