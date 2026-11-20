<?php
/**
 * WYSIWYG Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Support\MergeTags;
use SugarCommerce\Fields\Contracts\AdminOnly;

/**
 * The core visual editor.
 */
final class WysiwygType extends AbstractType implements AdminOnly {

	/**
	 * Config defaults.
	 *
	 * @return array<string, mixed>
	 */
	#[\Override]
	public function defaults(): array {
		return [
			'rows'       => 10,
			'media_buttons' => true,
			'teeny'      => false,
		];
	}

	/**
	 * Render the editor.
	 *
	 * wp_editor() echoes and cannot be told not to, so it is buffered. The
	 * editor id must be a bare lowercase identifier — TinyMCE rejects the
	 * bracketed names nested fields produce — so the real name is carried on
	 * a separate hidden-safe attribute via textarea_name.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	#[\Override]
	public function render( Field $field, Attributes $attributes ): string {
		$editor_id = preg_replace( '/[^a-z0-9_]/', '', strtolower( $field->input_id() ) ) ?: 'fieldkiteditor';
		$tags      = MergeTags::resolve( $field->get( 'tags', [] ) );
		$modal_id  = $editor_id . '_tags';

		// wp_editor() fires `media_buttons` for its own toolbar and offers no
		// other way in, so the Add Tag button is added for the duration of
		// this one call and removed straight after. A filter left attached
		// would put the button on every editor on the screen, including ones
		// with no tags registered.
		$add_button = static function ( $current ) use ( $editor_id, $modal_id, $tags ) {
			if ( $current !== $editor_id || [] === $tags ) {
				return;
			}

			echo MergeTags::button( $editor_id, $modal_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as it is built.
		};

		add_action( 'media_buttons', $add_button, 20 );

		ob_start();

		$settings = [
			'textarea_name' => $field->input_name(),
			'textarea_rows' => (int) $field->get( 'rows', 10 ),
			'media_buttons' => (bool) $field->get( 'media_buttons', true ),
			'teeny'         => (bool) $field->get( 'teeny', false ),
			'editor_class'  => 'field-kit__wysiwyg',
		];

		// A height in pixels, for an editor that starts out of sight. The
		// visual editor sizes itself from the textarea it replaces, and one
		// inside a folded panel measures nothing: it opened three lines
		// tall. Rows cannot say it, a height can.
		if ( (int) $field->get( 'height', 0 ) > 0 ) {
			$settings['editor_height'] = (int) $field->get( 'height' );
		}

		wp_editor( (string) $field->value(), $editor_id, $settings );

		$editor = (string) ob_get_clean();

		remove_action( 'media_buttons', $add_button, 20 );

		// wp_editor() builds its own <textarea> and offers no way to put
		// attributes on it, so the accessibility associations the renderer
		// prepared would be lost. They are injected into the markup we
		// buffered ourselves rather than left off the control.
		$carry = new Attributes();

		foreach ( [ 'aria-describedby', 'aria-required', 'aria-invalid', 'required' ] as $name ) {
			$carry->set_if( $attributes->has( $name ), $name, $attributes->get( $name ) );
		}

		$extra = $carry->render();

		if ( '' !== $extra ) {
			$editor = (string) preg_replace(
				'/<textarea\b/',
				'<textarea' . $extra,
				$editor,
				1
			);
		}

		// What the script needs to start the editor itself, where nothing
		// else will: markup that arrived after the page loaded -- a panel
		// fetched over REST -- comes without the settings wp_editor() prints
		// in the footer, so its visual editor and its Code toolbar never
		// start.
		$start = new Attributes();
		$start->set( 'class', 'field-kit__wysiwyg-wrap' );
		$start->set( 'data-field-id', $field->input_id() );
		$start->set( 'data-editor', $editor_id );
		$start->set_if( (bool) $field->get( 'teeny', false ), 'data-teeny', '1' );
		$start->set_if( (int) $field->get( 'height', 0 ) > 0, 'data-height', (string) (int) $field->get( 'height', 0 ) );

		return sprintf(
			'<div%s>%s%s</div>',
			$start->render(),
			$editor,
			MergeTags::modal( $modal_id, $tags )
		);
	}

	/**
	 * What one field needs: the editor's scripts, and the media frame when
	 * it has an Add Media button.
	 *
	 * wp_editor() enqueues all of it itself, but only when it runs during
	 * the page. An editor drawn later -- in a panel loaded over REST -- runs
	 * it in a request that prints nothing, so the page has to have asked
	 * for the editor up front.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return array{scripts: string[], styles: string[], editor: bool}
	 * @since 1.6.0
	 */
	public function dependencies_for( array $config ): array {
		$media = (bool) ( $config['media_buttons'] ?? true );

		return [
			'scripts' => $media ? [ 'media-upload', 'media-views' ] : [],
			'styles'  => [],
			'editor'  => true,
		];
	}

	/**
	 * Coerce a submitted value.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	#[\Override]
	public function sanitize( mixed $value, Field $field ): string {
		return wp_kses_post( $this->scalar( $value ) );
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
			[ 'height', 'media_buttons', 'rows', 'tags', 'teeny' ]
		);
	}
}
