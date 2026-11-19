<?php
/**
 * Shared Markup Helpers
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Support;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;

/**
 * Fragments more than one renderer needs.
 *
 * A self-labelling control writes its own `<label>`, so the renderer cannot
 * put the required marker inside it. Sharing the fragment here is what stops
 * the two producing different markup — and stops a required checkbox
 * shipping with no marker at all, which is what happened before this existed.
 * The character count is shared for the same reason: a text input and a
 * textarea both draw one, and one script drives both only while they agree.
 */
final class Markup {

	/**
	 * The visual and announced marker for a required field.
	 *
	 * The asterisk is decorative: `aria-required` on the control is what
	 * conveys the state, so the asterisk is hidden from assistive technology
	 * and the word is supplied for it instead.
	 *
	 * @param bool $required Whether the field is required.
	 *
	 * @return string
	 */
	public static function required_marker( bool $required ): string {
		if ( ! $required ) {
			return '';
		}

		return sprintf(
			'<span class="field-kit__required" aria-hidden="true">*</span><span class="screen-reader-text">%s</span>',
			esc_html__( '(required)', 'sugarcommerce' )
		);
	}

	/**
	 * A running count of characters against a limit.
	 *
	 * Rendered with the count already in it, so the field reads right before
	 * the script runs and if it never does. A polite live region, because
	 * the number changes as someone types and someone who cannot see it
	 * still needs to know when they are near the limit. `data-counter` is
	 * the script's hook.
	 *
	 * The width class is the control's own -- core's `regular-text` -- so
	 * the count is as wide as the control and its number sits under the
	 * control's end rather than at the far edge of a settings-page cell.
	 *
	 * A soft limit is a length to aim for rather than one that stops
	 * anybody -- a meta description reads best under 160, and can still be
	 * longer. The count is shown against it, turns to a warning past it, and
	 * names the hard limit beside it where there is one too.
	 *
	 * @param int    $length Characters in the current value.
	 * @param int    $max    The hard limit, the field's `maxlength`.
	 * @param bool   $wanted Whether the field wants a count at all.
	 * @param string $width  Core's width class of the control it sits under.
	 * @param int    $soft   The soft limit, the field's `soft_limit`.
	 *
	 * @return string Nothing without either limit.
	 */
	public static function counter( int $length, int $max, bool $wanted = true, string $width = '', int $soft = 0 ): string {
		if ( ! $wanted || ( $max < 1 && $soft < 1 ) ) {
			return '';
		}

		$attributes = new Attributes();
		$attributes->add_class( 'field-kit__count', 'description', $width );

		// A value saved before the limit was lowered is already over it. The
		// browser stops anyone typing past a maxlength; it does not truncate
		// what was there, and the person editing has to be told why the
		// form will not submit.
		if ( $max > 0 && $length > $max ) {
			$attributes->add_class( 'field-kit__count--over' );
		}

		if ( $soft > 0 && $length > $soft ) {
			$attributes->add_class( 'field-kit__count--soft' );
		}

		$attributes->set( 'data-counter', true );
		$attributes->set_if( $soft > 0, 'data-soft', $soft );
		$attributes->set( 'aria-live', 'polite' );

		return sprintf(
			'<span%s>%s</span>',
			$attributes->render(),
			esc_html( self::count( $length, $max, $soft ) )
		);
	}

	/**
	 * The words of a count: "5 / 200", or against a soft limit "5 / 60",
	 * and with both "5 / 60 (up to 160)".
	 *
	 * @param int $length Characters.
	 * @param int $max    The hard limit, or 0.
	 * @param int $soft   The soft limit, or 0.
	 *
	 * @return string
	 */
	public static function count( int $length, int $max, int $soft = 0 ): string {
		if ( $soft < 1 ) {
			return sprintf( '%d / %d', $length, $max );
		}

		if ( $max < 1 ) {
			return sprintf( '%d / %d', $length, $soft );
		}

		/* translators: 1: characters typed, 2: the length to aim for, 3: the most allowed */
		return sprintf( __( '%1$d / %2$d (up to %3$d)', 'sugarcommerce' ), $length, $soft, $max );
	}

	/**
	 * The refusal for text longer than its field's `maxlength`, or nothing.
	 *
	 * Counted in characters, as the count and the browser count.
	 *
	 * @param mixed $value The sanitized value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	public static function too_long( mixed $value, Field $field ): string {
		$max = (int) $field->get( 'maxlength', 0 );

		if ( $max < 1 || ! is_string( $value ) || mb_strlen( $value ) <= $max ) {
			return '';
		}

		return sprintf(
			/* translators: 1: field label, 2: the most characters allowed */
			_n( '%1$s can be at most %2$d character long.', '%1$s can be at most %2$d characters long.', $max, 'sugarcommerce' ),
			Rules::name( $field ),
			$max
		);
	}
}
