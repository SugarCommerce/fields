<?php
/**
 * What SugarCommerce\Plugin\Assets and the script registration call, for
 * AssetsTest only: sibling suites load tests/stubs.php first and keep their
 * own recorders for these functions.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', dirname( __DIR__, 3 ) );
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	function wp_normalize_path( $path ) {
		return str_replace( '\\', '/', (string) $path );
	}
}

if ( ! function_exists( 'content_url' ) ) {
	function content_url( $path = '' ) {
		return 'https://example.test/wp-content' . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) );
	}
}

if ( ! function_exists( 'site_url' ) ) {
	function site_url( $path = '' ) {
		return 'https://example.test/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
		$GLOBALS['fk_registered']['styles'][ $handle ] = [ $src, $deps, $ver ];

		return true;
	}
}

if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( $handle, $src, $deps = [], $ver = false, $args = [] ) {
		$GLOBALS['fk_registered']['scripts'][ $handle ] = [ $src, $deps, $ver, $args ];

		return true;
	}
}

if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( $handle, $data, $position = 'after' ) {
		$GLOBALS['fk_registered']['inline'][ $handle ][] = $data;

		return true;
	}
}
