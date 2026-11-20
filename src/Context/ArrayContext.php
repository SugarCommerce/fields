<?php
/**
 * Array Context
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Context;

use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Field;

/**
 * Collects values in memory instead of storing them anywhere.
 *
 * Two things need this. A caller that has to produce the array to be stored
 * rather than store it — WordPress's Settings API is one: its sanitize
 * callback runs inside `update_option()`, so a context that wrote would
 * re-enter the callback it was called from. And anything that wants to know
 * what a submission would sanitize to without committing it: a preview, a
 * validation pass, a test.
 *
 * It is seeded with the values it starts from, so a partial submission — one
 * tab of a settings page — leaves the keys it does not carry alone.
 */
final class ArrayContext implements Context {

	/**
	 * The collected values.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Construct.
	 *
	 * @param array<string, mixed> $values Values to start from.
	 */
	public function __construct( array $values = [] ) {
		$this->values = $values;
	}

	/**
	 * Read a value.
	 *
	 * The object id is unused: an array holds one set of values.
	 *
	 * @param int|string $object_id Ignored.
	 * @param Field      $field     The field.
	 *
	 * @return mixed
	 */
	#[\Override]
	public function read( int|string $object_id, Field $field ): mixed {
		return $this->values[ $field->key() ] ?? null;
	}

	/**
	 * Collect a value.
	 *
	 * @param int|string $object_id Ignored.
	 * @param Field      $field     The field.
	 * @param mixed      $value     Sanitized, unslashed value.
	 *
	 * @return void
	 */
	#[\Override]
	public function write( int|string $object_id, Field $field, mixed $value ): void {
		$this->values[ $field->key() ] = $value;
	}

	/**
	 * Drop a value.
	 *
	 * @param int|string $object_id Ignored.
	 * @param Field      $field     The field.
	 *
	 * @return void
	 */
	#[\Override]
	public function delete( int|string $object_id, Field $field ): void {
		unset( $this->values[ $field->key() ] );
	}

	/**
	 * The collected values.
	 *
	 * @return array<string, mixed>
	 */
	public function values(): array {
		return $this->values;
	}
}
