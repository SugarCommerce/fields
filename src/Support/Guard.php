<?php
/**
 * Unsaved-changes Guard
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Support;

use SugarCommerce\Fields\Assets;
use SugarCommerce\Fields\Utils\Runtime;

/**
 * A warning before leaving a form that has changes nobody saved, and a mark
 * on each field that changed.
 *
 * Off unless asked for, per form. A form -- or anything holding one, a
 * flyout panel, a settings page's wrapper -- asks with the
 * `data-fk-guard` attribute (`self::ATTRIBUTE`), and markup drawn by
 * somebody else is asked for by selector with enable(). The script then
 * notes what the form would submit and, as it is used, compares: a field
 * whose value is no longer what it was is marked "Changed", and while any
 * is, leaving the page asks first. Changing a field back takes the mark
 * away; sending the form ends the guard.
 *
 * In a flyouts panel, closing the panel is the in-page way out, and the
 * panel already asks before it throws changes away; the guard adds the
 * marks and the warning on leaving the page, and starts again when the
 * panel saves. Elsewhere in a page, code about to remove a guarded form
 * asks `SugarCommerceFieldsModules.Guard.confirm( element )` first.
 */
final class Guard {

	/**
	 * The attribute that turns the guard on for a form or its container.
	 */
	public const ATTRIBUTE = 'data-fk-guard';

	/**
	 * Guard the forms a selector matches, on the page being built.
	 *
	 * For a form another library draws -- a settings page, a panel:
	 * `Guard::enable( '.my-plugin-settings form' )`. Call it while scripts
	 * are being enqueued, or before.
	 *
	 * @param string $selector A CSS selector for forms or their containers.
	 *
	 * @return void
	 */
	public static function enable( string $selector ): void {
		$selector = trim( wp_strip_all_tags( $selector ) );

		if ( '' === $selector ) {
			return;
		}

		( new Assets() )->register();

		wp_add_inline_script(
			Runtime::handle(),
			sprintf(
				'window.SugarCommerceFields=window.SugarCommerceFields||{};'
				. 'window.SugarCommerceFields[%1$s]=window.SugarCommerceFields[%1$s]||{};'
				. 'window.SugarCommerceFields[%1$s].guard=(window.SugarCommerceFields[%1$s].guard||[]).concat([%2$s]);',
				wp_json_encode( Runtime::handle() ),
				wp_json_encode( $selector )
			),
			'before'
		);
	}
}
