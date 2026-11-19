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

// What enqueue() calls, recorded for AssetsTest.
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle ) {
		$GLOBALS['fk_registered']['enqueued'][] = (string) $handle;
	}
}

if ( ! function_exists( 'wp_enqueue_editor' ) ) {
	function wp_enqueue_editor() {
		$GLOBALS['fk_registered']['editor'] = ( $GLOBALS['fk_registered']['editor'] ?? 0 ) + 1;
	}
}

if ( ! function_exists( 'wp_enqueue_media' ) ) {
	function wp_enqueue_media() {
		$GLOBALS['fk_registered']['media'] = true;
	}
}

if ( ! function_exists( 'wp_enqueue_code_editor' ) ) {
	function wp_enqueue_code_editor( $args ) {
		return [ 'codemirror' => [ 'mode' => $args['type'] ] ];
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $status = 'enqueued' ) {
		return false;
	}
}

if ( ! function_exists( 'wp_scripts' ) ) {
	function wp_scripts() {
		return new class() {
			public function query( $handle, $status = 'registered' ) {
				return false;
			}
		};
	}
}
