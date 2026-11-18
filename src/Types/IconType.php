<?php
/**
 * Icon Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.1.0
 * @author      David Sherlock
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Icons;
use SugarCommerce\Fields\Utils\Runtime;

/**
 * Class IconType
 *
 * Pick an icon from any registered set.
 *
 * The control is a button showing the chosen icon and its name. It opens a
 * dialog with a search box, a tab per set and a grid of icons, fetched a
 * page at a time from the kit's `/icons` endpoint -- so a set of thousands
 * costs nothing until somebody opens it, and nothing at all on a screen
 * where nobody does.
 *
 * The value is `set:name` -- `heroicons-outline:shopping-cart` -- held to
 * the registry on save, and drawn with Icons::svg(). `sets` limits the
 * picker to some of them; by default it offers every set registered.
 *
 * A value stored in the old form, a whole Dashicons class, still draws and
 * is saved back as `dashicons:name`.
 */
final class IconType extends AbstractType {

	/**
	 * The type's id.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function id(): string {
		return 'icon';
	}

	/**
	 * The button, the value it stands for, and a way to clear it.
	 *
	 * The button takes the field's id, so the label points at it and names
	 * it. It is a combobox whose popup is a dialog -- the ARIA pattern for
	 * a control that shows one value and opens something to change it --
	 * so its text is its value, and it is announced as "Icon, combobox,
	 * Shopping cart" rather than as a button named only "Icon". It is also
	 * what can carry `aria-required`, which a plain button cannot. The
	 * value rides in a hidden input that carries the field's name.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Control attributes.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$sets  = self::allowed( $field );
		$value = Icons::sanitize( $field->value(), $sets );
		$id    = (string) $attributes->get( 'id', '' );
		$name  = (string) $attributes->get( 'name', '' );
		$label = Icons::label( $value );
		$empty = '' !== $field->placeholder() ? $field->placeholder() : __( 'Choose an icon', 'sugarcommerce' );

		$offered = [];

		foreach ( Icons::sets() as $set ) {
			if ( [] === $sets || in_array( $set->id(), $sets, true ) ) {
				$offered[] = $set->describe();

				// An icon font draws in the picker's grid only with its
				// stylesheet on the page; the kit's own sheet no longer
				// brings Dashicons along.
				if ( '' !== $set->stylesheet() && function_exists( 'wp_enqueue_style' ) ) {
					wp_enqueue_style( $set->stylesheet() );
				}
			}
		}

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__icon-picker' );
		$wrapper->set( 'data-sets', $offered );
		$wrapper->set( 'data-empty', '' === $value ? 'true' : 'false' );
		$wrapper->set( 'data-placeholder', $empty );

		// This build's own endpoint. Two plugins bundling prefixed copies of
		// the kit each load their own script, and whichever binds a field
		// first must still ask the registry that rendered it.
		$wrapper->set( 'data-endpoint', rest_url( Runtime::rest_namespace() . '/icons' ) );

		$button = new Attributes();
		$button->set( 'type', 'button' );
		$button->set( 'id', $id );
		$button->add_class( 'button', 'field-kit__icon-button' );
		$button->set( 'role', 'combobox' );
		$button->set( 'aria-haspopup', 'dialog' );
		$button->set( 'aria-expanded', 'false' );

		// What the renderer worked out -- the description, the error, that
		// it is required -- belongs on the control a person operates.
		foreach ( [ 'aria-describedby', 'aria-required', 'aria-invalid', 'disabled' ] as $state ) {
			$button->set_if( $attributes->has( $state ), $state, $attributes->get( $state ) );
		}

		return sprintf(
			'<span%1$s><input type="hidden" class="field-kit__icon-value" name="%2$s" value="%3$s" />'
			. '<button%4$s><span class="field-kit__icon-glyph" aria-hidden="true">%5$s</span>'
			. '<span class="field-kit__icon-name">%6$s</span></button>'
			. '<button type="button" class="button-link field-kit__icon-clear"%7$s>%8$s<span class="screen-reader-text"> %9$s</span></button></span>',
			$wrapper->render(),
			esc_attr( $name ),
			esc_attr( $value ),
			$button->render(),
			'' !== $value ? Icons::svg( $value, [ 'size' => 20 ] ) : '',
			esc_html( '' !== $label ? $label : $empty ),
			'' === $value ? ' hidden' : '',
			esc_html__( 'Clear', 'sugarcommerce' ),
			esc_html( $field->label() )
		);
	}

	/**
	 * Only an icon that is in an offered set is stored.
	 *
	 * The value is drawn into pages later, so anything typed rather than
	 * chosen is refused here: an unknown set, an unknown name, or a set this
	 * field does not offer all come back empty.
	 *
	 * @param mixed $value The submitted value.
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function sanitize( mixed $value, Field $field ): string {
		return Icons::sanitize( $value, self::allowed( $field ) );
	}

	/**
	 * The sets the field offers, as ids; empty for all of them.
	 *
	 * @param Field $field The field.
	 *
	 * @return string[]
	 */
	private static function allowed( Field $field ): array {
		return array_values( array_filter( array_map( 'strval', (array) $field->get( 'sets', [] ) ) ) );
	}

	/**
	 * The text the button shows when nothing is chosen is a placeholder.
	 *
	 * Read by render() from the field rather than set on an input -- the
	 * control is a button, which has no placeholder attribute.
	 *
	 * @return bool
	 */
	public function supports_placeholder(): bool {
		return false;
	}

	/**
	 * Fits a quick edit row: the dialog opens over the whole screen.
	 *
	 * @return bool
	 */
	public function supports_inline(): bool {
		return true;
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'sets' ] );
	}
}
