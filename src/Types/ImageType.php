<?php
/**
 * Image Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Field;

/**
 * A single image from the media library.
 */
final class ImageType extends AbstractMediaType {

	/**
	 * The media frame's title.
	 *
	 * @return string
	 */
	#[\Override]
	protected function frame_title( ?Field $field = null ): string {
		return __( 'Choose an image', 'sugarcommerce' );
	}

	/**
	 * The button that opens the picker.
	 *
	 * A field can name its own with `choose_label` -- "Set product image"
	 * says what the picture is for, which "Choose image" cannot.
	 *
	 * @return string
	 */
	#[\Override]
	protected function choose_label( ?Field $field = null ): string {
		$own = $field ? (string) $field->get( 'choose_label' ) : '';

		return '' !== $own ? $own : __( 'Choose image', 'sugarcommerce' );
	}

	/**
	 * The button once there is a picture: it replaces it.
	 *
	 * @return string
	 */
	#[\Override]
	protected function replace_label( ?Field $field = null ): string {
		$own = $field ? (string) $field->get( 'replace_label' ) : '';

		return '' !== $own ? $own : __( 'Replace', 'sugarcommerce' );
	}

	/**
	 * Only images.
	 *
	 * @return string
	 */
	#[\Override]
	protected function mime_type(): string {
		return 'image';
	}

	/**
	 * Render the thumbnail.
	 *
	 * The alt text comes from the attachment's own alt field, so a decorative
	 * image stays decorative and a described one keeps its description. An
	 * empty preview is not announced at all rather than as "image".
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	protected function render_preview( Field $field ): string {
		$id = absint( $field->value() );

		if ( 0 === $id ) {
			return '<div class="field-kit__media-preview" data-empty="true"></div>';
		}

		$size = (string) $field->get( 'preview_size', 'medium' );
		$alt  = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );

		return sprintf(
			'<div class="field-kit__media-preview">%s</div>',
			wp_get_attachment_image( $id, $size, false, [ 'alt' => $alt ] )
		);
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	#[\Override]
	public function config_keys(): array {
		return array_merge(
			parent::config_keys(),
			[ 'preview_size', 'choose_label', 'replace_label' ]
		);
	}
}
