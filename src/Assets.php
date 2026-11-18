<?php
/**
 * Asset Registration
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields;

use SugarCommerce\Fields\Icons\Glyphs;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Plugin\Assets as PackageAssets;

/**
 * Registers the one script and the one stylesheet.
 *
 * Handles are derived, and the configuration is published into a registry
 * keyed by handle rather than to a bare global: two plugins each bundling a
 * Strauss-prefixed copy load two copies of this script, and a shared global
 * would leave whichever localised last owning the REST URL and nonce for
 * both.
 *
 * Where the files are, and their URL, is SugarCommerce\Plugin\Assets's
 * job -- the family's one resolver, which finds this package in a plugin's
 * vendor folder, a Strauss copy, a theme or a symlinked plugin. What stays
 * here is what is this package's own: the handles, the script's dependencies
 * and configuration, and code editors.
 */
final class Assets {

	/**
	 * Whether registration has run.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the script and stylesheet.
	 *
	 * Registers rather than enqueues: a field set asks for what it needs, so
	 * a screen with no fields on it loads nothing.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}

		self::$registered = true;

		$handle = Runtime::handle();

		// No wp_style_add_data( $handle, 'rtl', 'replace' ): the sheet is
		// written with logical properties — margin-inline-start rather than
		// margin-left — so the one file serves both directions and there is
		// no second one to point core at.
		// Each file is versioned by its own modification time, so a
		// stylesheet-only change is not served from the cache under the
		// script's version.
		PackageAssets::register_style( $handle, __FILE__, 'css/field-kit.css' );

		// jquery is a base dependency even though the kit is vanilla: several
		// of the core controls it drives — the colour picker above all — are
		// jQuery plugins, and a script that runs before them finds nothing to
		// call and fails without a word. wp-api-fetch carries the REST
		// requests, so a nonce that ran out while the screen was open is
		// renewed rather than answered with a 403.
		PackageAssets::register_script( $handle, __FILE__, 'js/field-kit.js', [ 'jquery', 'wp-api-fetch' ] );

		wp_add_inline_script(
			$handle,
			sprintf(
				'window.SugarCommerceFields=window.SugarCommerceFields||{};window.SugarCommerceFields[%s]=%s;',
				wp_json_encode( $handle ),
				wp_json_encode( $this->config() )
			),
			'before'
		);
	}

	/**
	 * Enqueue the kit and whatever a field set additionally needs.
	 *
	 * @param array{scripts: string[], styles: string[]} $dependencies Extra handles.
	 *
	 * @return void
	 */
	public function enqueue( array $dependencies = [] ): void {
		$this->register();

		foreach ( $dependencies['styles'] ?? [] as $style ) {
			wp_enqueue_style( $style );
		}

		foreach ( $dependencies['scripts'] ?? [] as $script ) {
			wp_enqueue_script( $script );
		}

		$this->enqueue_code_editors( $dependencies['code_editors'] ?? [] );

		// Whatever this screen's fields need has to load first, so it is
		// added to the kit's own dependencies rather than merely enqueued
		// alongside. Enqueue order is not load order — WordPress resolves
		// that from dependencies — and the kit was running before jQuery and
		// the colour picker for exactly this reason.
		$this->depend_on( $dependencies['scripts'] ?? [] );

		wp_enqueue_style( Runtime::handle() );
		wp_enqueue_script( Runtime::handle() );

		// The media frame needs its templates printed, which enqueueing the
		// script alone does not do.
		if ( in_array( 'media-views', $dependencies['scripts'] ?? [], true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Make the kit's script load after the handles it drives.
	 *
	 * @param string[] $handles Script handles this screen needs.
	 *
	 * @return void
	 */
	private function depend_on( array $handles ): void {
		$script = wp_scripts()->query( Runtime::handle(), 'registered' );

		if ( ! $script ) {
			return;
		}

		// wp_enqueue_code_editor() registers 'code-editor' itself, so it is
		// added by name rather than being something a field could declare.
		if ( wp_script_is( 'code-editor', 'enqueued' ) ) {
			$handles[] = 'code-editor';
		}

		$script->deps = array_values( array_unique( array_merge( $script->deps, $handles ) ) );
	}

	/**
	 * Enqueue a code editor for each language in use.
	 *
	 * wp_enqueue_code_editor() enqueues CodeMirror with the right mode and
	 * linter and returns the settings its initialiser needs, so the settings
	 * are passed through to the script keyed by language.
	 *
	 * It returns false when the current user has turned syntax highlighting
	 * off in their profile. That is a preference, not a failure: the field
	 * stays a plain textarea, which is why the control was never anything
	 * else underneath.
	 *
	 * @param string[] $types Mime types to prepare.
	 *
	 * @return void
	 */
	private function enqueue_code_editors( array $types ): void {
		$settings = [];

		foreach ( $types as $type ) {
			$resolved = wp_enqueue_code_editor( [ 'type' => $type ] );

			if ( false !== $resolved ) {
				$settings[ $type ] = $resolved;
			}
		}

		if ( [] === $settings ) {
			return;
		}

		wp_add_inline_script(
			Runtime::handle(),
			sprintf(
				'window.SugarCommerceFields=window.SugarCommerceFields||{};'
				. 'window.SugarCommerceFields[%1$s]=window.SugarCommerceFields[%1$s]||{};'
				. 'window.SugarCommerceFields[%1$s].codeEditors=%2$s;',
				wp_json_encode( Runtime::handle() ),
				wp_json_encode( $settings )
			),
			'before'
		);
	}

	/**
	 * The configuration handed to the script.
	 *
	 * @return array<string, mixed>
	 */
	private function config(): array {
		return [
			'restUrl'   => rest_url( Runtime::rest_namespace() . '/' ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),

			// The kit's own icons, drawn, for the controls the script builds.
			'icons'     => Glyphs::markup(),
			'i18n'      => [
				'noResults'        => __( 'No results found.', 'sugarcommerce' ),
				'resultsAvailable' => __( 'results available.', 'sugarcommerce' ),
				'position'         => __( 'Item', 'sugarcommerce' ),
				'rowAdded'         => __( 'Row added.', 'sugarcommerce' ),
				'rowRemoved'       => __( 'Row removed.', 'sugarcommerce' ),
				'copied'           => __( 'Copied to the clipboard.', 'sugarcommerce' ),
				'copyFailed'       => __( 'Could not copy.', 'sugarcommerce' ),
				'addItem'          => __( 'Add', 'sugarcommerce' ),
				'removeItem'       => __( 'Remove', 'sugarcommerce' ),
				'actionDone'       => __( 'Done.', 'sugarcommerce' ),
				'actionFailed'     => __( 'That did not work.', 'sugarcommerce' ),
				'showPassword'     => __( 'Show password', 'sugarcommerce' ),
				'hidePassword'     => __( 'Hide password', 'sugarcommerce' ),
				'unsavedChanges'   => __( 'Unsaved changes', 'sugarcommerce' ),
				'selectRegion'     => __( 'Select a region', 'sugarcommerce' ),
				'iconTitle'        => __( 'Choose an icon', 'sugarcommerce' ),
				'iconClose'        => __( 'Close', 'sugarcommerce' ),
				'iconSearch'       => __( 'Search icons', 'sugarcommerce' ),
				'iconSets'         => __( 'Icon sets', 'sugarcommerce' ),
				'iconGrid'         => __( 'Icons', 'sugarcommerce' ),
				'iconNone'         => __( 'No icon', 'sugarcommerce' ),
				'iconOutline'      => __( 'Outline', 'sugarcommerce' ),
				'iconSolid'        => __( 'Solid', 'sugarcommerce' ),
				'iconNoMatch'      => __( 'No icons match.', 'sugarcommerce' ),
				'iconMatch'        => __( '1 icon matches.', 'sugarcommerce' ),
				'iconOne'          => __( '1 icon', 'sugarcommerce' ),
				/* translators: %d: how many icons a search found. */
				'iconMatches'      => __( '%d icons match.', 'sugarcommerce' ),
				/* translators: %d: how many icons the set has. */
				'iconCount'        => __( '%d icons', 'sugarcommerce' ),
				'iconFailed'       => __( 'The icons could not be loaded.', 'sugarcommerce' ),
			],
		];
	}
}
