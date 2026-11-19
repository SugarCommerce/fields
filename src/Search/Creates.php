<?php
/**
 * Creating Search Source Contract
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.7.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Search;

use WP_Error;

/**
 * A source that can add to itself: a picker that finds nothing can make the
 * thing it was looking for.
 *
 * Optional, and kept apart from Source, because most sources cannot. A list
 * of regions is what the world says it is, and a callback over another
 * system's records has nowhere to write. A source that can implements this
 * as well, and the create route refuses any that does not.
 *
 * The capability is asked per request rather than once per source, because
 * it depends on what is being made. Searching terms needs nothing more than
 * a picker; adding one to a taxonomy needs that taxonomy's own `edit_terms`,
 * and a user's role decides whether the person asking may hand it out.
 *
 * What comes back is an id the field stores like any it found, so a created
 * item is indistinguishable from a chosen one by the time it is saved. That
 * is the difference from `creatable`, which stores the typed text itself.
 *
 * @since 1.7.0
 */
interface Creates {

	/**
	 * Whether the current user may create in this source, with these arguments.
	 *
	 * Answered before create() is called, by the route's permission check,
	 * so a refusal is a 403 rather than a half-made item.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied, cleaned
	 *                                   to scalars: a post type, a taxonomy,
	 *                                   a status or a role.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function can_create( array $args ): bool;

	/**
	 * Make an item from what was typed.
	 *
	 * Something that already exists under that name is handed back rather
	 * than made twice, with `existing` set, so the field selects it and says
	 * so. A refusal -- an address that is not one, a name that is empty --
	 * is a WP_Error whose message the control shows as it is.
	 *
	 * @param string               $text What was typed, already sanitised.
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return array{id: string, text: string, existing: bool}|WP_Error
	 * @since 1.7.0
	 */
	public function create( string $text, array $args ): array|WP_Error;
}
