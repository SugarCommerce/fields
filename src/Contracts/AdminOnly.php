<?php
/**
 * Admin-only Type Contract
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Contracts;

/**
 * A type that only makes sense inside wp-admin.
 *
 * The media library, a code editor, a search over posts and users, an icon
 * dialog: each leans on something a storefront visitor does not have -- an
 * upload capability, core's editor scripts, a REST route that wants a login.
 * Put on a public form, each draws a control that cannot work for the person
 * looking at it.
 *
 * A front-end form asks this rather than keeping its own list, for the same
 * reason quick edit asks supports_inline(): the type is the one place that
 * knows. A contract and not a method on FieldType, so a type written
 * elsewhere against that interface keeps loading, and is taken to work on
 * the front end unless it says otherwise.
 */
interface AdminOnly {
}
