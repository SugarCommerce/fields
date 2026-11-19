<?php
/**
 * Lookup Type Contract
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Contracts;

/**
 * A type whose control asks the server something as it is used.
 *
 * A slug asks whether it is taken, a location asks a geocoder where an
 * address is. What answers is configuration the caller supplies -- a
 * callable, a geocoder -- so it cannot be put in the page; the field set
 * registers it under a name when it is built, the field emits the name, and
 * the lookup endpoint finds the handler again on the later request.
 */
interface Lookup {

	/**
	 * The handler for one field's configuration, or null when it has none.
	 *
	 * Called with what was typed; returns an array for the script, or a
	 * WP_Error.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return callable|null
	 */
	public function lookup_handler( array $config ): ?callable;

	/**
	 * The capability asking needs. An empty string lets anyone ask.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return string
	 */
	public function lookup_capability( array $config ): string;
}
