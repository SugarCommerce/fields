<?php
/**
 * Post Fields: Saving
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\PostFields;

use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\FieldSet;
use SugarCommerce\Fields\Screens\Errors;
use WP_Post;

/**
 * Saving a post's fields, only the ones the user may see and change.
 *
 * Part of PostFields: only the class uses it.
 */
trait Saving {

	/**
	 * Save a post's fields.
	 *
	 * @param int     $post_id The post id.
	 * @param WP_Post $post    The post.
	 *
	 * @return void
	 */
	public function save( int $post_id, WP_Post $post ): void {
		// An autosave is not a submission: the fields are not in it, and
		// saving would read every one of them as cleared.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! in_array( $post->post_type, (array) $this->config['post_types'], true ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked on the next line.
		$nonce = isset( $_POST[ $this->id . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ $this->id . '_nonce' ] ) ) : '';

		// A metabox can be absent from a submission — hidden in Screen
		// Options, or a quick edit — and its nonce with it. Saving then would
		// clear every field it holds.
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'save_' . $this->id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$input = $_POST;

		// The block editor's submission of this box, which carries none of
		// the sidebar's fields: they were saved over REST a moment ago, and
		// reading them as missing here would clear them all -- a checkbox
		// above all, whose "unticked" is exactly "missing".
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$skip = empty( $_POST[ $this->id . '_block_editor' ] ) ? [] : $this->panel_keys( $post->post_type );

		// Only what this user may set. A field they cannot see is not one a
		// crafted submission gets to write either.
		$set = $this->permitted_set( $post_id, $skip );

		$set->save( $input, $post_id );

		// A refused value is left as it was and the rest are stored. Core
		// redirects before anything here could say so, so the messages wait
		// for the next load of this post's screen.
		Errors::remember( 'post', $post_id, $this->id, $set->errors() );
	}

	/**
	 * A field set limited to what the current user may write.
	 *
	 * @param int      $post_id The post being edited.
	 * @param string[] $skip    Fields saved somewhere else.
	 *
	 * @return FieldSet
	 */
	private function permitted_set( int $post_id, array $skip = [] ): FieldSet {
		$allowed = array_filter(
			array_diff_key( $this->fields, array_flip( $skip ) ),
			fn( $config ) => $this->permitted( (array) $config, $post_id )
		);

		return $allowed === $this->fields
			? $this->set
			: new FieldSet( $allowed, $this->context, '', $this->registry );
	}

	/**
	 * The fields to draw, as Field objects.
	 *
	 * @param int      $post_id The post being edited.
	 * @param string[] $skip    Fields drawn somewhere else.
	 *
	 * @return Field[]
	 */
	private function visible_fields( int $post_id, array $skip = [] ): array {
		$fields = [];

		foreach ( $this->set->fields( $post_id ) as $field ) {
			if ( in_array( $field->key(), $skip, true ) ) {
				continue;
			}

			if ( $this->permitted( (array) ( $this->fields[ $field->key() ] ?? [] ), $post_id ) ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Whether the current user may see and set a field.
	 *
	 * @param array<string, mixed> $config  Field configuration.
	 * @param int                  $post_id The post being edited.
	 *
	 * @return bool
	 */
	private function permitted( array $config, int $post_id ): bool {
		$capability = (string) ( $config['capability'] ?? $this->config['capability'] );

		return '' === $capability || current_user_can( $capability, $post_id ?: null );
	}
}
