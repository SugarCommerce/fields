<?php
/**
 * Unechoed Type Contract
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Contracts;

/**
 * A type whose control never shows what is stored.
 *
 * A password input is drawn empty whatever the field holds, because a
 * secret is not something to send back to a page. That has a consequence
 * for the save: the box comes back empty from everybody who did not retype
 * the secret, which is nearly everybody, nearly every time. Read as "clear
 * it" -- the way an emptied text input is rightly read -- a settings tab
 * with an API key on it deleted the key whenever anything else on the tab
 * was saved.
 *
 * So for a type that says this, an empty submission over a stored value
 * means "unchanged", and the field set leaves the field alone. Something
 * typed replaces what is stored, as before. Clearing one is done by the
 * means that never went through the control: a context's delete(), a
 * settings page's reset, or a write that is not a form submission.
 *
 * A contract and not a method on FieldType, so a type written elsewhere
 * against that interface does not stop loading the day this was added.
 */
interface Unechoed {
}
