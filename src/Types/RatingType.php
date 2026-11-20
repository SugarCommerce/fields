<?php
/**
 * Rating Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;

/**
 * Stars: one to five by default, any count up to ten, halves when asked.
 *
 * Underneath it is a group of radio buttons, one per value, so the keyboard
 * already works the way it does for any choice -- Tab reaches the group,
 * the arrow keys move along it and choose as they go -- and a screen reader
 * hears "4 out of 5, radio button, 4 of 6". The stars are one painted strip
 * over the buttons, filled to the chosen value by the stylesheet alone
 * (`:has()` reads which button is checked and which label is hovered), so
 * nothing here waits for a script.
 *
 * `display` draws the stars and nothing to change them, for a rating shown
 * on a form rather than given on it. The value goes back with the form in a
 * hidden input, so saving the form around it keeps it. stars() draws the
 * same strip anywhere -- a product page, a review list, an email.
 *
 * What is stored is the number: a whole number, or a half where halves are
 * allowed, between one step and `max`. No rating at all is stored as
 * nothing, so `required` means somebody chose.
 */
final class RatingType extends AbstractType {

	/**
	 * The most stars a field may have.
	 *
	 * The stylesheet fills the strip from a rule per possible value, and
	 * has rules up to here.
	 */
	public const int MOST = 10;

	/**
	 * Defaults.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function defaults(): array {
		return [
			'max'  => 5,
			'half' => false,
		];
	}

	/**
	 * Render the stars.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		$max   = self::max( $field );
		$half  = (bool) $field->get( 'half', false );
		$value = $this->sanitize( $field->value(), $field );
		$name  = (string) $attributes->get( 'name', '' );
		$id    = (string) $attributes->get( 'id', '' );

		if ( $field->get( 'display', false ) || $field->get( 'readonly', false ) ) {
			return self::stars( '' === $value ? 0.0 : (float) $value, $max )
				. sprintf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $name ), esc_attr( (string) $value ) );
		}

		$disabled = (bool) $attributes->get( 'disabled', false );
		$required = $field->is_required();
		$step     = $half ? 0.5 : 1.0;
		$inputs   = '';

		// The way out of a rating, for a field that may be left without one.
		// First in the group, so the arrow keys reach it from one star.
		if ( ! $required ) {
			$inputs .= $this->option( $id . '_0', $name, '', __( 'No rating', 'sugarcommerce' ), '' === $value, $disabled, false, 'field-kit__rating-none' );
		}

		for ( $at = $step; $at <= $max + 0.001; $at += $step ) {
			$text = sprintf(
				/* translators: 1: a rating, 2: the most it can be */
				__( '%1$s out of %2$s', 'sugarcommerce' ),
				self::number( $at ),
				self::number( (float) $max )
			);

			$inputs .= $this->option(
				$id . '_' . str_replace( '.', '_', self::number( $at ) ),
				$name,
				self::number( $at ),
				$text,
				'' !== $value && abs( (float) $value - $at ) < 0.001,
				$disabled,
				$required,
				'field-kit__rating-star'
			);
		}

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__rating', $half ? 'field-kit__rating--half' : '' );
		$wrapper->set( 'style', '--fk-rating-max:' . $max );

		return sprintf(
			'<div%s><span class="field-kit__rating-stars" aria-hidden="true"></span>%s</div>',
			$wrapper->render(),
			$inputs
		);
	}

	/**
	 * One button and the label that is its hit area.
	 *
	 * @param string $id       Input id.
	 * @param string $name     Input name.
	 * @param string $value    Its value.
	 * @param string $text     Its name, for a screen reader.
	 * @param bool   $checked  Whether it is the current value.
	 * @param bool   $disabled Whether the field is locked.
	 * @param bool   $required Whether a choice is required.
	 * @param string $classes  The label's class.
	 *
	 * @return string
	 */
	private function option( string $id, string $name, string $value, string $text, bool $checked, bool $disabled, bool $required, string $classes ): string {
		$input = new Attributes();

		$input->set( 'type', 'radio' );
		$input->set( 'id', $id );
		$input->set( 'name', $name );
		$input->set( 'value', $value );
		$input->add_class( 'field-kit__rating-input' );
		$input->set_if( $checked, 'checked', true );
		$input->set_if( $disabled, 'disabled', true );
		$input->set_if( $required, 'required', true );

		return sprintf(
			'<input%1$s /><label class="%2$s" for="%3$s" data-value="%4$s" title="%5$s"><span class="screen-reader-text">%5$s</span></label>',
			$input->render(),
			esc_attr( $classes ),
			esc_attr( $id ),
			esc_attr( $value ),
			esc_html( $text )
		);
	}

	/**
	 * A rating drawn as stars, with nothing to change it.
	 *
	 * The strip is an image to a screen reader, named with the rating ("4.5
	 * out of 5 stars"). It needs the field stylesheet, or the front-end one,
	 * on the page; fractions are drawn as they are, so an average of 4.3
	 * fills a third of the fifth star.
	 *
	 * @param float                $value The rating.
	 * @param int                  $max   How many stars.
	 * @param array<string, mixed> $args  `class` for the element, `label`
	 *                                    for its accessible name instead
	 *                                    of the one made here, `size` in
	 *                                    pixels.
	 *
	 * @return string
	 */
	public static function stars( float $value, int $max = 5, array $args = [] ): string {
		$max   = max( 1, min( self::MOST, $max ) );
		$value = max( 0.0, min( (float) $max, $value ) );
		$label = isset( $args['label'] ) ? (string) $args['label'] : sprintf(
			/* translators: 1: a rating, 2: the most it can be */
			__( '%1$s out of %2$s stars', 'sugarcommerce' ),
			self::number( round( $value, 1 ) ),
			self::number( (float) $max )
		);

		$attributes = new Attributes();
		$attributes->add_class( 'field-kit__rating', 'field-kit__rating--display', (string) ( $args['class'] ?? '' ) );
		$attributes->set( 'role', 'img' );
		$attributes->set( 'aria-label', $label );
		$attributes->set(
			'style',
			'--fk-rating:' . self::number( round( $value, 2 ) ) . ';--fk-rating-max:' . $max
			. ( isset( $args['size'] ) ? ';--fk-rating-size:' . absint( $args['size'] ) . 'px' : '' )
		);

		return sprintf( '<span%s><span class="field-kit__rating-stars"></span></span>', $attributes->render() );
	}

	/**
	 * Coerce a submitted rating.
	 *
	 * Rounded to the field's step and held between one step and the most:
	 * a 7 on a five-star field is a five, 3.7 without halves is a four. Zero,
	 * nothing and anything that is not a number are no rating.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return int|float|string
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): int|float|string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}

		$max    = self::max( $field );
		$halves = (bool) $field->get( 'half', false );
		$value  = (float) $value;
		$value  = $halves ? round( $value * 2 ) / 2 : round( $value );

		if ( $value <= 0 ) {
			return '';
		}

		$value = min( (float) $max, $value );

		return floor( $value ) === $value ? (int) $value : $value;
	}

	/**
	 * How many stars a field has.
	 *
	 * @param Field $field The field.
	 *
	 * @return int
	 */
	private static function max( Field $field ): int {
		return max( 1, min( self::MOST, (int) $field->get( 'max', 5 ) ) );
	}

	/**
	 * A rating as it is written: 4, 4.5.
	 *
	 * @param float $value The rating.
	 *
	 * @return string
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * A number.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function schema( Field $field ): array {
		return [
			'type'    => [ 'number', 'string' ],
			'minimum' => 0,
			'maximum' => self::max( $field ),
		];
	}

	/**
	 * A group of buttons, so a fieldset and a legend.
	 *
	 * @return bool
	 */
	#[\Override]
	public function is_grouped(): bool {
		return true;
	}

	/**
	 * Configuration this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'max', 'half', 'display' ] );
	}
}
