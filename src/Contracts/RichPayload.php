<?php
/**
 * Rich Payload Contract
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @version     1.0.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Contracts;

/**
 * An action some of whose inputs are markup.
 *
 * Everything a button sends is reduced to plain text on the way in, which
 * is right for a licence key and wrong for the body of an email: Preview
 * was handed the wording with its paragraphs, its bold and its line breaks
 * gone, so an edited email previewed as one run-on line until it was saved.
 *
 * A separate contract rather than a method on Action, so an action written
 * against that interface before this existed is still one. An action that
 * says nothing here has every input treated as text, as before.
 */
interface RichPayload {

	/**
	 * The payload keys that carry markup.
	 *
	 * Each is run through `wp_kses_post()` instead of being stripped: what
	 * a post may contain, and nothing a post may not.
	 *
	 * @return string[]
	 */
	public function rich(): array;
}
