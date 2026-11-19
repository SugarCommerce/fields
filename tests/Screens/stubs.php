<?php
/**
 * Stubs the screen tests need on top of the engine's (tests/stubs.php).
 *
 * The five screens came from five libraries, each with its own helper prefix
 * -- pf_ for the post metabox, tf_ for terms, uf_ for users, qe_ for quick
 * edit, be_ for bulk edit -- and the tests are written against those, so the
 * prefixes are kept. Where the engine's stubs already define a function, the
 * engine's wins: this file is required after it, and every stub is guarded.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! function_exists( 'add_meta_box' ) ) {
	function add_meta_box( $id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $args = null ) {
		foreach ( [ 'pf_boxes', 'qe_boxes', 'be_boxes' ] as $key ) {
			if ( isset( $GLOBALS[ $key ] ) ) {
				$GLOBALS[ $key ][] = compact( 'id', 'title', 'callback', 'screen', 'context', 'priority', 'args' );
			}
		}
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) {
		foreach ( [ 'pf_nonce_ok', 'qe_nonce_ok', 'be_nonce_ok' ] as $key ) {
			if ( array_key_exists( $key, $GLOBALS ) ) {
				return (bool) $GLOBALS[ $key ];
			}
		}

		return true;
	}
}

if ( ! function_exists( 'get_current_screen' ) ) {
	function get_current_screen() {
		/*
		 * A screen left set by an earlier test would otherwise win over the one
		 * this test set, because the prefixes are checked in a fixed order. Only
		 * something that actually looks like a screen counts.
		 */
		foreach ( [ 'pf_screen', 'tf_screen', 'uf_screen', 'qe_screen', 'be_screen' ] as $key ) {
			$screen = $GLOBALS[ $key ] ?? null;

			if ( is_object( $screen ) && isset( $screen->id ) ) {
				return $screen;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook ) {
		return 0;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
		$field = sprintf( '<input type="hidden" name="%s" value="nonce-%s" />', $name, (string) $action );

		if ( $display ) {
			echo $field;
		}

		return $field;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return (int) ( $GLOBALS['uf_current_user'] ?? 0 );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		return $GLOBALS['qe_post_type'] ?? 'post';
	}
}

/*
 * The three screens that save through a page load carry a refused value's
 * message across the redirect in a transient. Stored with its expiry so a
 * test can say how long it waits, not only that it does.
 */
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['rf_transients'][ $key ] = [
			'value'      => $value,
			'expiration' => (int) $expiration,
		];

		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['rf_transients'][ $key ]['value'] ?? false;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['rf_transients'][ $key ] );

		return true;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * The parts of WP_Post a metabox touches.
	 */
	class WP_Post {

		/**
		 * The post id.
		 *
		 * @var int
		 */
		public int $ID = 0;

		/**
		 * The post type.
		 *
		 * @var string
		 */
		public string $post_type = 'post';
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * The parts of WP_Term a term screen touches.
	 */
	class WP_Term {

		/**
		 * The term id.
		 *
		 * @var int
		 */
		public int $term_id = 0;

		/**
		 * The taxonomy.
		 *
		 * @var string
		 */
		public string $taxonomy = 'demo_tax';
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/**
	 * The parts of WP_User a profile screen touches.
	 */
	class WP_User {

		/**
		 * The user id.
		 *
		 * @var int
		 */
		public int $ID = 0;
	}
}

/**
 * Reset the post metabox's globals.
 *
 * @return void
 */
function pf_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['pf_boxes']         = [];
	$GLOBALS['pf_screen']        = (object) [
		'base'      => 'post',
		'post_type' => 'post',
	];

	// Capability => denied. Anything unlisted is granted, so a test only says
	// what it is actually about.
	$GLOBALS['pf_denied'] = [];

	// Whether wp_verify_nonce() should accept what it is given.
	$GLOBALS['pf_nonce_ok'] = true;

	// What a save left for the next screen.
	$GLOBALS['rf_transients'] = [];
}

/**
 * Reset the term screen's globals.
 *
 * @return void
 */
function tf_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['tf_screen']        = (object) [
		'id'       => 'edit-demo_tax',
		'taxonomy' => 'demo_tax',
		'base'     => 'edit-tags',
	];
	$GLOBALS['tf_denied']        = [];
	$GLOBALS['rf_transients']    = [];
}

/**
 * Reset the profile screen's globals.
 *
 * @return void
 */
function uf_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['uf_current_user']  = 1;
	$GLOBALS['uf_screen']        = 'profile';
	$GLOBALS['uf_denied']        = [];
	$GLOBALS['rf_transients']    = [];
}

/**
 * Reset quick edit's globals.
 *
 * @return void
 */
function qe_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['qe_boxes']         = [];
	$GLOBALS['qe_screen']        = (object) [
		'base'      => 'edit',
		'post_type' => 'post',
	];
	$GLOBALS['qe_denied']        = [];
	$GLOBALS['qe_nonce_ok']      = true;
	$GLOBALS['qe_post_type']     = 'post';
	$GLOBALS['fk_filters']       = [];
}

/**
 * Reset bulk edit's globals.
 *
 * @return void
 */
function be_reset_globals(): void {
	$GLOBALS['fk_meta']          = [];
	$GLOBALS['fk_actions']       = [];
	$GLOBALS['fk_meta_registry'] = [];
	$GLOBALS['be_boxes']         = [];
	$GLOBALS['be_screen']        = (object) [
		'base'      => 'edit',
		'post_type' => 'post',
	];
	$GLOBALS['be_denied']        = [];
	$GLOBALS['be_nonce_ok']      = true;
}

pf_reset_globals();
tf_reset_globals();
uf_reset_globals();
qe_reset_globals();
be_reset_globals();

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $meta_key, $meta_value, $prev_value = '' ) {
		return update_metadata( 'term', $term_id, $meta_key, $meta_value, $prev_value );
	}
}
