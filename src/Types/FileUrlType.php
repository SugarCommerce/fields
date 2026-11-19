<?php
/**
 * File URL Field Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Icons\Svg;
use SugarCommerce\Fields\Utils\Runtime;

/**
 * A file chosen from the media library but stored as its URL.
 *
 * Distinct from `file`, which stores an attachment id. The input stays a
 * visible, editable text field so a URL can also be typed or pasted — the
 * picker is an affordance, not the only way in.
 */
final class FileUrlType extends FileType {

	/**
	 * Render an editable URL input and its picker.
	 *
	 * Standalone, the picker is a labelled button beneath the input, beside
	 * a labelled Remove — which is what every other media field on a
	 * settings or term screen looks like, and what those screens have room
	 * for.
	 *
	 * Inside a repeater row the picker moves into the input, the way EDD
	 * draws a download's file row. A row has one line: the labelled pair
	 * wrapped below the input and doubled the height of every row, and in
	 * the table layout it did it in a cell a few characters wide.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$attributes->set( 'type', 'url' );
		$attributes->set( 'value', (string) $field->value() );
		$attributes->add_class( 'regular-text', 'field-kit__media-value' );
		$attributes->set_if( '' !== $field->placeholder(), 'placeholder', $field->placeholder() );

		$wrapper = new Attributes();
		$wrapper->add_class( 'field-kit__media', 'field-kit__media--file_url' );
		$wrapper->set( 'data-frame-title', $this->frame_title() );
		$wrapper->set( 'data-returns', 'url' );
		$wrapper->set_if( $field->has( 'accept' ), 'data-accept', implode( ',', array_map( 'strval', (array) $field->get( 'accept', [] ) ) ) );

		$input   = sprintf( '<input%s />', $attributes->render() );
		$sources = $this->source_buttons( $field );
		$sources = '' === $sources ? '' : '<p class="field-kit__media-sources">' . $sources . '</p>';

		if ( ! $field->get( 'inline', false ) ) {
			return sprintf(
				'<div%s>%s%s%s</div>',
				$wrapper->render(),
				$input,
				$this->render_controls( $field ),
				$sources
			);
		}

		// No clear button in a row. The input is a real, editable URL field
		// — it can be selected and deleted like any other — so a second
		// control to empty it costs a row's width to duplicate the keyboard.
		// A source's buttons go on a line under the row's input rather than
		// beside it: two or three labelled buttons in a cell leave the
		// address no room, and at a phone's width none at all.
		return sprintf(
			'<div%s>%s%s</div>',
			$wrapper->render(),
			$this->input_group( $input, $this->choose_button( $field ) ),
			$sources
		);
	}

	/**
	 * Somewhere else a file can come from, beside the media library.
	 *
	 * A bucket, a cloud drive: offered by whatever package knows it, through
	 * a filter, as plain values -- so the package need not be known to this
	 * one, nor this one to it:
	 *
	 *     add_filter( Runtime::hook( 'file_sources' ), fn( array $sources ) => $sources + [
	 *         'my_bucket' => [ 'label' => 'Choose from the bucket', 'icon' => '<svg…>', 'enqueue' => $callable ],
	 *     ] );
	 *
	 * The id is the key the source's script fills in
	 * `window.sugarcommerceFileSources`: `{ label, pick( { accept, returns } ) }`,
	 * `pick` resolving `{ value, name }` or null. `enqueue` loads that script,
	 * and returning false says this person may not use the source.
	 * `protocols` names the schemes its addresses use (`[ 's3' ]`), so a
	 * value it picked is kept on save.
	 *
	 * The field's `sources`: true for every source (the default), false for
	 * none, or a list of ids for those.
	 *
	 * @param Field $field The field asking.
	 *
	 * @return array<string, array<string, mixed>>
	 * @since 1.0.0
	 */
	public static function sources( Field $field ): array {
		$asked = $field->get( 'sources', true );

		if ( false === $asked ) {
			return [];
		}

		$sources = (array) apply_filters( Runtime::hook( 'file_sources' ), [], $field );
		$sources = array_filter(
			$sources,
			static fn( $source, $id ): bool => is_array( $source ) && is_string( $id ) && '' !== $id && '' !== trim( (string) ( $source['label'] ?? '' ) ),
			ARRAY_FILTER_USE_BOTH
		);

		if ( is_array( $asked ) ) {
			$sources = array_intersect_key( $sources, array_flip( array_map( 'strval', $asked ) ) );
		}

		return $sources;
	}

	/**
	 * One button per source, each source's script loaded as it goes.
	 *
	 * A button whose script never registers is hidden by the script, so a
	 * source that failed to load leaves no button that does nothing.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	protected function source_buttons( Field $field ): string {
		$html = '';

		foreach ( self::sources( $field ) as $id => $source ) {
			if ( isset( $source['enqueue'] ) && is_callable( $source['enqueue'] ) && false === ( $source['enqueue'] )() ) {
				continue;
			}

			$button = new Attributes();
			$button->set( 'type', 'button' );
			$button->add_class( 'button', 'field-kit__media-source' );
			$button->set( 'data-source', (string) $id );
			$button->set(
				'aria-label',
				sprintf(
					/* translators: 1: a source, such as "Choose from Cloudflare R2", 2: the field's label */
					__( '%1$s for %2$s', 'sugarcommerce' ),
					(string) $source['label'],
					$field->label()
				)
			);

			// Somebody else's markup, so through the same cleaning an icon
			// gets before it is printed anywhere.
			$icon = is_string( $source['icon'] ?? null ) ? Svg::sanitize( $source['icon'] ) : '';

			$html .= sprintf(
				'<button%s>%s<span>%s</span></button>',
				$button->render(),
				'' === $icon ? '' : str_replace( '<svg', '<svg aria-hidden="true" focusable="false"', $icon ),
				esc_html( (string) $source['label'] )
			);
		}

		return $html;
	}

	/**
	 * The schemes a value may use: the web's, the field's own `protocols`,
	 * and those its sources declare.
	 *
	 * @param Field $field The field.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	private function protocols( Field $field ): array {
		$protocols = array_map( 'strval', (array) $field->get( 'protocols', [] ) );

		foreach ( self::sources( $field ) as $source ) {
			$protocols = array_merge( $protocols, array_map( 'strval', (array) ( $source['protocols'] ?? [] ) ) );
		}

		// Never a scheme that runs something when followed.
		$protocols = array_diff( array_map( 'strtolower', $protocols ), [ 'javascript', 'vbscript', 'data' ] );

		return array_values( array_unique( array_merge( wp_allowed_protocols(), $protocols ) ) );
	}

	/**
	 * Whether a URL is set.
	 *
	 * This one stores a URL rather than an attachment id, so the id check
	 * the other media types use would call every URL empty.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	protected function has_selection( Field $field ): bool {
		return '' !== trim( (string) $field->value() );
	}

	/**
	 * Coerce a submitted value to a URL.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	public function sanitize( mixed $value, Field $field ): string {
		return esc_url_raw( $this->scalar( $value ), $this->protocols( $field ) );
	}

	/**
	 * A URL field takes a placeholder.
	 *
	 * @return bool
	 */
	public function supports_placeholder(): bool {
		return true;
	}

	/**
	 * A URL rather than an attachment id.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	public function schema( Field $field ): array {
		return [
			'type'   => 'string',
			'format' => 'uri',
		];
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public function config_keys(): array {
		return array_merge( parent::config_keys(), [ 'accept', 'protocols', 'sources' ] );
	}
}
