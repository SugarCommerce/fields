<?php
/**
 * Sealer Context Contract
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.6.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Contracts;

use SugarCommerce\Fields\Field;

/**
 * A context that seals encrypted fields, or one wrapping such a context.
 *
 * A value carrying the encrypted marker reaches a save two ways: handed
 * back by a write of the whole value -- a reset, an import, a plain
 * update_option() -- which is ciphertext this store wrote, or typed into
 * the box by somebody. The first is passed through as it is; the second
 * must be refused, or it is stored as "already encrypted" and reads back as
 * nothing. Only the store that seals can tell them apart, by opening it.
 */
interface Sealer {

	/**
	 * Whether a value is ciphertext this store wrote for the field and can
	 * open.
	 *
	 * @param Field  $field The field.
	 * @param string $value The value, carrying the marker.
	 *
	 * @return bool
	 */
	public function opens( Field $field, string $value ): bool;
}
