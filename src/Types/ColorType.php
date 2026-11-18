<?php
/**
 * Colour Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Text\Display\Color;

/**
 * A colour: the browser's own picker, with the hex beside it.
 *
 * The control is two inputs kept in step. The swatch is an
 * `<input type="color">`, which opens the operating system's own colour
 * picker -- the one people already know, with an eyedropper on most of
 * them -- and the box beside it is the hex, for the colour somebody has
 * already got on their clipboard. Either one moves the other. A Clear
 * button empties both, and `presets` adds a row of swatches to choose from.
 *
 * The hex box is the field: it carries the name, the id the label points
 * at and the value that is saved, so the form still works with the script
 * off. The swatch has no name and is never submitted.
 *
 * Stored as `#rrggbb`, lowercase, or empty -- by Color::hex(), whichever
 * form was typed or pasted.
 *
 * Asking for core's picker -- `picker`, see uses_wordpress() -- gives Iris
 * instead, for anybody who wants its look or its alpha channel; `alpha` on
 * its own implies it, since a native picker has no alpha.
 */
final class ColorType extends AbstractInputType {

	/**
	 * The HTML input type of the field itself: the hex box.
	 *
	 * @return string
	 */
	protected function input_type(): string {
		return 'text';
	}

	/**
	 * Whether a field asked for core's Iris picker.
	 *
	 * @param mixed $picker The field's `picker`: `native` or `wordpress`.
	 * @param mixed $alpha  The field's `alpha`, which only core's picker has.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function uses_wordpress( mixed $picker, mixed $alpha = false ): bool {
		$picker = is_string( $picker ) ? $picker : '';

		if ( '' !== $picker ) {
			return 'wordpress' === $picker; // phpcs:ignore WordPress.WP.CapitalPDangit -- a value, not the name.
		}

		return ! empty( $alpha );
	}

	/**
	 * Render the control.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	public function render( Field $field, Attributes $attributes ): string {
		if ( self::uses_wordpress( $field->get( 'picker' ), $field->get( 'alpha' ) ) ) {
			return $this->render_wordpress( $field, $attributes );
		}

		$value    = Color::hex( $this->render_value( $field ) );
		$label    = $field->label();
		$disabled = $attributes->has( 'disabled' );

		$attributes->set( 'type', 'text' );
		$attributes->set( 'value', $value );
		$attributes->add_class( 'field-kit__input', 'field-kit__input--color', 'field-kit__color-hex' );
		$attributes->set( 'maxlength', 7 );
		$attributes->set( 'spellcheck', 'false' );
		$attributes->set( 'autocomplete', 'off' );
		$attributes->set( 'autocapitalize', 'off' );

		// What the browser checks before the form is sent: three or six
		// hex digits, with the hash or without it, as a paste brings them.
		$attributes->set( 'pattern', '#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})' );

		if ( ! $attributes->has( 'placeholder' ) ) {
			// An empty box says what empty means.
			$attributes->set( 'placeholder', __( 'No colour', 'sugarcommerce' ) );
		}

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__color-field' );
		$wrapper->set( 'data-empty', '' === $value ? 'true' : 'false' );

		$swatch = new Attributes();
		$swatch->set( 'type', 'color' );
		$swatch->add_class( 'field-kit__color-swatch' );

		// A colour input cannot be empty -- it is always some colour, and
		// black when not told. The wrapper's data-empty draws the "none"
		// state over it until something is chosen.
		$swatch->set( 'value', '' !== $value ? $value : '#000000' );
		$swatch->set(
			'aria-label',
			'' !== $label
				/* translators: %s: the field's label, e.g. "Colour". */
				? sprintf( __( '%s, from the colour picker', 'sugarcommerce' ), $label )
				: __( 'Colour picker', 'sugarcommerce' )
		);
		$swatch->set_if( $disabled, 'disabled', true );

		$clear = sprintf(
			'<button type="button" class="button-link field-kit__color-clear"%1$s%2$s>%3$s<span class="screen-reader-text"> %4$s</span></button>',
			'' === $value ? ' hidden' : '',
			$disabled ? ' disabled' : '',
			esc_html__( 'Clear', 'sugarcommerce' ),
			esc_html( $label )
		);

		return sprintf(
			'<span%1$s><span class="field-kit__color-control"><input%2$s /><input%3$s /></span>%4$s%5$s</span>',
			$wrapper->render(),
			$swatch->render(),
			$attributes->render(),
			$clear,
			$this->presets_markup( $field, $value, $disabled )
		);
	}

	/**
	 * The row of preset swatches, when the field has presets.
	 *
	 * Buttons, so each is a tab stop with a name and a pressed state: the
	 * one matching the current colour says so, which is the only way a
	 * screen reader learns a colour is one of the suggested ones.
	 *
	 * @param Field  $field    The field.
	 * @param string $value    The current colour.
	 * @param bool   $disabled Whether the field is disabled.
	 *
	 * @return string
	 */
	private function presets_markup( Field $field, string $value, bool $disabled ): string {
		$presets = self::presets( $field->get( 'presets', $field->get( 'palette', [] ) ) );

		if ( [] === $presets ) {
			return '';
		}

		$buttons = '';

		foreach ( $presets as $hex => $name ) {
			$buttons .= sprintf(
				'<button type="button" class="field-kit__color-preset" data-color="%1$s" aria-pressed="%2$s" aria-label="%3$s" title="%3$s" style="--field-kit-swatch:%1$s"%4$s></button>',
				esc_attr( $hex ),
				$hex === $value ? 'true' : 'false',
				esc_attr( '' !== $name ? $name . ' ' . $hex : $hex ),
				$disabled ? ' disabled' : ''
			);
		}

		return sprintf(
			'<span class="field-kit__color-presets" role="group" aria-label="%1$s">%2$s</span>',
			esc_attr__( 'Suggested colours', 'sugarcommerce' ),
			$buttons
		);
	}

	/**
	 * Presets, cleaned: name by `#rrggbb`.
	 *
	 * `presets` may be a list of colours, colours keyed to names
	 * (`'#e8a5c4' => 'Rose'`), or the comma-separated string the Iris
	 * picker's `palette` takes -- which is read when there are no
	 * `presets`, so a field written for core's picker keeps its swatches on
	 * this one.
	 *
	 * @param mixed $given The field's `presets`, or its `palette`.
	 *
	 * @return array<string, string>
	 * @since 1.5.0
	 */
	public static function presets( mixed $given ): array {
		if ( is_string( $given ) ) {
			$given = explode( ',', $given );
		}

		$presets = [];

		foreach ( is_array( $given ) ? $given : [] as $key => $item ) {
			// A list gives the colour as the item; a map gives it as the
			// key, with the name as the item.
			$hex  = Color::hex( is_string( $key ) ? $key : $item );
			$name = is_string( $key ) && is_scalar( $item ) ? trim( (string) $item ) : '';

			if ( '' !== $hex && ! isset( $presets[ $hex ] ) ) {
				$presets[ $hex ] = $name;
			}
		}

		return $presets;
	}

	/**
	 * Core's Iris picker, as the field used to be.
	 *
	 * A text input that the script upgrades with `wpColorPicker()`.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	private function render_wordpress( Field $field, Attributes $attributes ): string {
		$palette = array_keys( self::presets( $field->get( 'presets', $field->get( 'palette', [] ) ) ) );

		$attributes->add_class( 'field-kit__color', 'field-kit__color--wordpress' );
		$attributes->set_if( [] !== $palette, 'data-palette', implode( ',', $palette ) );
		$attributes->set_if( (bool) $field->get( 'alpha' ), 'data-alpha-enabled', 'true' );

		return parent::render( $field, $attributes );
	}

	/**
	 * Coerce a submitted value to `#rrggbb` or nothing.
	 *
	 * With core's picker and `alpha`, an `rgba()` is a colour too, and is
	 * kept as it came.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value, Field $field ): string {
		$value = trim( $this->scalar( $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( $field->get( 'alpha' ) && self::uses_wordpress( $field->get( 'picker' ), true ) && preg_match( '/^rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\)$/i', $value ) ) {
			return $value;
		}

		return Color::hex( $value );
	}

	/**
	 * Nothing beyond the kit's own script, for the native control.
	 *
	 * @return array{scripts: string[], styles: string[]}
	 */
	public function dependencies(): array {
		return [
			'scripts' => [],
			'styles'  => [],
		];
	}

	/**
	 * What one field needs: core's picker, when it asked for it.
	 *
	 * jquery is explicit: wp-color-picker is a jQuery plugin, and enqueueing
	 * it without jQuery present leaves the field a plain text input with no
	 * error anywhere.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return array{scripts: string[], styles: string[]}
	 * @since 1.5.0
	 */
	public function dependencies_for( array $config ): array {
		if ( ! self::uses_wordpress( $config['picker'] ?? '', $config['alpha'] ?? false ) ) {
			return $this->dependencies();
		}

		return [
			'scripts' => [ 'jquery', 'wp-color-picker' ],
			'styles'  => [ 'wp-color-picker' ],
		];
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'alpha', 'palette', 'picker', 'presets' ]
		);
	}
}
