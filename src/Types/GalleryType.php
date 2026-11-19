<?php
/**
 * Gallery Field Type
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Glyphs;

/**
 * An ordered set of images, chosen from the media library.
 *
 * A grid of tiles: each is the picture, a cross that takes it out and, for
 * the keyboard, a handle. A tile is dragged by any part of it; focus its
 * handle and the arrow keys move it, because a drag cannot be done from a
 * keyboard at all. Each tile is a list item in an ordered list, so the order
 * is in the markup as well as on the screen.
 *
 * What the gallery stores is the attachment ids, in order. The alt text is
 * the attachment's own, as the media library holds it, read again whenever
 * the picture is drawn -- here, and on the front of the site through
 * {@see self::images()} -- so correcting it once in the library corrects it
 * everywhere the picture appears. A tile whose picture has none says so,
 * since an image with no alt text is the one thing a gallery should not be
 * full of.
 *
 * `min` and `max` bound how many. The Add button stops at the maximum and
 * the server keeps the first that many; a gallery with fewer than the
 * minimum is refused with a message -- an empty one only when it is
 * required, the same as an address that has been started has to be
 * finished but an empty one is a choice.
 *
 * @since 1.0.0
 */
final class GalleryType extends AbstractMediaType {

	/**
	 * The type's id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function id(): string {
		return 'gallery';
	}

	/**
	 * The media frame's title.
	 *
	 * @param Field|null $field The field.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function frame_title( ?Field $field = null ): string {
		$title = null === $field ? '' : (string) $field->get( 'frame_title', '' );

		return '' !== $title ? $title : __( 'Choose images', 'sugarcommerce' );
	}

	/**
	 * The button that opens the library.
	 *
	 * @param Field|null $field The field.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function choose_label( ?Field $field = null ): string {
		$label = null === $field ? '' : (string) $field->get( 'add_label', '' );

		return '' !== $label ? $label : __( 'Add images', 'sugarcommerce' );
	}

	/**
	 * Images only: a gallery of MP3s is a list of files, which `files` is.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function mime_type(): string {
		return 'image';
	}

	/**
	 * The grid, the hidden value and the Add button.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$ids = self::ids( $field->value() );

		$attributes->set( 'type', 'hidden' );
		$attributes->set( 'value', implode( ',', $ids ) );
		$attributes->add_class( 'field-kit__media-value' );

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__gallery' );
		$wrapper->set( 'data-frame-title', $this->frame_title( $field ) );
		$wrapper->set( 'data-choose-label', $this->choose_label( $field ) );
		$wrapper->set( 'data-mime-type', $this->mime_type() );
		$wrapper->set( 'data-min', $this->limit( $field, 'min' ) );
		$wrapper->set( 'data-max', $this->limit( $field, 'max' ) );
		$wrapper->set_if( $field->has( 'library' ), 'data-library', $field->get( 'library' ) );

		return sprintf(
			'<div%s>%s<input%s />%s%s</div>',
			$wrapper->render(),
			$this->render_preview( $field ),
			$attributes->render(),
			$this->render_controls( $field ),
			'<span class="field-kit__gallery-status screen-reader-text" aria-live="polite"></span>'
		);
	}

	/**
	 * Whether anything is chosen.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	protected function has_selection( Field $field ): bool {
		return [] !== self::ids( $field->value() );
	}

	/**
	 * The grid of tiles, and the tile the script clones.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function render_preview( Field $field ): string {
		$ids   = self::ids( $field->value() );
		$total = count( $ids );
		$items = '';

		foreach ( $ids as $position => $id ) {
			$items .= $this->render_item( $field, $id, $position, $total );
		}

		return sprintf(
			'<ol class="field-kit__gallery-items" data-empty="%s">%s</ol>%s',
			$total > 0 ? 'false' : 'true',
			$items,
			$this->render_template()
		);
	}

	/**
	 * The tile the script fills in for a picture it adds.
	 *
	 * One tile, drawn here and cloned there, so a picture added a moment ago
	 * looks and behaves like one that came from the database. `{name}` is
	 * the script's to replace.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function render_template(): string {
		return sprintf(
			'<template class="field-kit__gallery-template">%s</template>',
			$this->tile( 0, '{name}', '<img alt="" />', '', 0, 0 )
		);
	}

	/**
	 * One saved picture.
	 *
	 * @param Field $field    The field.
	 * @param int   $id       Attachment id.
	 * @param int   $position Zero-based position.
	 * @param int   $total    How many there are.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function render_item( Field $field, int $id, int $position, int $total ): string {
		$name = (string) get_the_title( $id );
		$name = '' === $name ? (string) $id : $name;
		$alt  = self::alt( $id );

		// The attachment's own alt text on the picture, so a screen reader
		// going through the tiles hears what the picture is rather than
		// "image, image, image".
		$image = wp_get_attachment_image( $id, (string) $field->get( 'preview_size', 'medium' ), false, [ 'alt' => $alt ] );

		// A picture that has gone from the library still has a place in the
		// order, and a tile that says what it was is how somebody finds and
		// removes it.
		if ( '' === $image ) {
			$image = sprintf( '<span class="field-kit__gallery-missing">%s</span>', esc_html__( 'Missing image', 'sugarcommerce' ) );
		}

		return $this->tile( $id, $name, $image, $alt, $position, $total );
	}

	/**
	 * A tile: handle, picture, whether it has alt text, remove.
	 *
	 * @param int    $id       Attachment id, 0 in the template.
	 * @param string $name     Its name, for the buttons' labels.
	 * @param string $image    The picture's markup.
	 * @param string $alt      Its alt text.
	 * @param int    $position Zero-based position.
	 * @param int    $total    How many there are.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function tile( int $id, string $name, string $image, string $alt, int $position, int $total ): string {
		return sprintf(
			'<li class="field-kit__gallery-item" data-id="%1$d">%2$s%3$s' .
			'<span class="field-kit__gallery-alt"%4$s>%5$s</span>' .
			'<span class="field-kit__gallery-actions">%6$s</span></li>',
			$id,
			$this->handle( $name, $position, $total ),
			$image,
			'' === $alt && 0 !== $id ? '' : ' hidden',
			esc_html__( 'No alt text', 'sugarcommerce' ),
			$this->remove_button( $name )
		);
	}

	/**
	 * The grab handle, which is also the keyboard's way to reorder.
	 *
	 * The position is in its accessible name, so a move announces where the
	 * picture landed; kept as a template the script rewrites, rather than a
	 * second string to translate.
	 *
	 * @param string $name     The picture's name.
	 * @param int    $position Zero-based position.
	 * @param int    $total    How many there are.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function handle( string $name, int $position, int $total ): string {
		$handle = new Attributes();
		$handle->set( 'type', 'button' );
		$handle->add_class( 'field-kit__drag-handle', 'field-kit__gallery-handle' );
		$handle->set( 'aria-roledescription', __( 'Sortable', 'sugarcommerce' ) );
		$handle->set( 'aria-keyshortcuts', 'ArrowLeft ArrowRight ArrowUp ArrowDown' );

		$template = sprintf(
			/* translators: 1: image name, 2: its position, 3: how many there are */
			__( 'Reorder %1$s, %2$s of %3$s', 'sugarcommerce' ),
			$name,
			'{position}',
			'{total}'
		);

		$handle->set( 'data-label-template', $template );
		$handle->set(
			'aria-label',
			strtr(
				$template,
				[
					'{position}' => (string) ( $position + 1 ),
					'{total}'    => (string) $total,
				]
			)
		);

		return sprintf( '<button%s>%s</button>', $handle->render(), Glyphs::get( 'drag', [ 'size' => 16 ] ) );
	}

	/**
	 * The cross that takes one picture out.
	 *
	 * @param string $name The picture's name, for the label.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function remove_button( string $name ): string {
		$button = new Attributes();
		$button->set( 'type', 'button' );
		$button->add_class( 'button-link', 'field-kit__gallery-remove' );
		/* translators: %s: image name */
		$button->set( 'aria-label', sprintf( __( 'Remove %s', 'sugarcommerce' ), $name ) );

		return sprintf( '<button%s>%s</button>', $button->render(), Glyphs::get( 'close', [ 'size' => 14 ] ) );
	}

	/**
	 * Only the Add button: each tile removes itself, and a Remove under the
	 * grid would read as "remove which?".
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function render_controls( Field $field ): string {
		$add = new Attributes();
		$add->set( 'type', 'button' );
		$add->add_class( 'button', 'field-kit__media-choose', 'field-kit__gallery-add' );

		$max = $this->limit( $field, 'max' );

		$add->set_if( $max > 0 && count( self::ids( $field->value() ) ) >= $max, 'disabled', true );

		return sprintf(
			'<p class="field-kit__media-actions"><button%s>%s</button></p>',
			$add->render(),
			esc_html( $this->choose_label( $field ) )
		);
	}

	/**
	 * A limit from the field: `min` or `max`, never below zero.
	 *
	 * @param Field  $field The field.
	 * @param string $which `min` or `max`.
	 *
	 * @return int
	 * @since 1.0.0
	 */
	private function limit( Field $field, string $which ): int {
		return max( 0, (int) ( 'min' === $which ? $field->get( 'min', 0 ) : $field->get( 'max', 0 ) ) );
	}

	/**
	 * The attachment ids in a value, however it came: the list it is
	 * stored as, or the comma-separated string the hidden input posts.
	 *
	 * Each id once, in the order first seen: the frame adds to the grid and
	 * the same picture twice is a mistake rather than a layout.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	public static function ids( mixed $value ): array {
		$value = is_array( $value ) ? $value : explode( ',', (string) $value );

		return array_values( array_unique( array_filter( array_map( 'absint', array_filter( $value, 'is_scalar' ) ) ) ) );
	}

	/**
	 * The alt text the media library holds for an attachment.
	 *
	 * @param int $id Attachment id.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public static function alt( int $id ): string {
		return trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) );
	}

	/**
	 * A gallery's pictures, for the front of the site.
	 *
	 * Each through `wp_get_attachment_image()`, so the alt text is the
	 * attachment's own, and `srcset`, `sizes` and lazy loading come as core
	 * draws them. A picture no longer in the library is left out.
	 *
	 *     echo GalleryType::images( $ids, 'large', [ 'class' => 'shop-gallery' ] );
	 *
	 * @param mixed                $value What the field stored.
	 * @param string               $size  An image size.
	 * @param array<string, mixed> $args  `class` for the list, `item_class`
	 *                                    for each item, `attr` for each image.
	 *
	 * @return string An `<ul>`, or '' when there is nothing to show.
	 * @since 1.0.0
	 */
	public static function images( mixed $value, string $size = 'large', array $args = [] ): string {
		$items = '';

		foreach ( self::ids( $value ) as $id ) {
			$image = wp_get_attachment_image( $id, $size, false, (array) ( $args['attr'] ?? [] ) );

			if ( '' !== $image ) {
				$items .= sprintf( '<li class="%s">%s</li>', esc_attr( (string) ( $args['item_class'] ?? 'sugarcommerce-gallery__item' ) ), $image );
			}
		}

		return '' === $items ? '' : sprintf( '<ul class="%s">%s</ul>', esc_attr( (string) ( $args['class'] ?? 'sugarcommerce-gallery' ) ), $items );
	}

	/**
	 * A list of attachment ids, cut to the maximum.
	 *
	 * Enforced here as well as by the button: the value posts as a plain
	 * comma-separated list, and anything can post one.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return int[]
	 * @since 1.0.0
	 */
	public function sanitize( mixed $value, Field $field ): array {
		$ids = self::ids( $value );
		$max = $this->limit( $field, 'max' );

		return $max > 0 ? array_slice( $ids, 0, $max ) : $ids;
	}

	/**
	 * Refuse a gallery with fewer pictures than its minimum.
	 *
	 * @param mixed $value The sanitized value.
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function validate( mixed $value, Field $field ): string {
		$count = count( self::ids( $value ) );
		$min   = $this->limit( $field, 'min' );

		if ( $count > 0 && $count < $min ) {
			return sprintf(
				/* translators: 1: field label, 2: the fewest images allowed */
				_n( '%1$s needs at least %2$d image.', '%1$s needs at least %2$d images.', $min, 'sugarcommerce' ),
				$field->label(),
				$min
			);
		}

		return parent::validate( $value, $field );
	}

	/**
	 * A list of attachment ids.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function schema( Field $field ): array {
		$schema = [
			'type'  => 'array',
			'items' => [ 'type' => 'integer' ],
		];

		if ( $this->limit( $field, 'max' ) > 0 ) {
			$schema['maxItems'] = $this->limit( $field, 'max' );
		}

		return $schema;
	}

	/**
	 * Needs a fieldset: the grid and the button are several controls under
	 * one label.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_grouped(): bool {
		return true;
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'add_label', 'frame_title', 'max', 'min', 'preview_size' ]
		);
	}
}
