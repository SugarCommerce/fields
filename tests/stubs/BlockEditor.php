<?php
/**
 * What the block editor's sidebar asks WordPress, for BlockEditorPanelTest.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

// Every post type supports custom fields unless a test lists it here.
if ( ! function_exists( 'post_type_supports' ) ) {
	function post_type_supports( $post_type, $feature ) {
		if ( 'custom-fields' !== $feature ) {
			return true;
		}

		return ! in_array( $post_type, (array) ( $GLOBALS['pfb_no_custom_fields'] ?? [] ), true );
	}
}

// Whether a row is stored, which is not whether its value is empty.
if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( $meta_type, $object_id, $meta_key ) {
		return array_key_exists( $meta_key, $GLOBALS['fk_meta'][ $meta_type ][ $object_id ] ?? [] );
	}
}
