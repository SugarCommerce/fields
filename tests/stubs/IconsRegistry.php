<?php
/**
 * WP_Icons_Registry, as far as Icons\CoreIcons reads it.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

final class WP_Icons_Registry {

	private static ?self $instance = null;

	public static function get_instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Core's collection from the fixture copy, and one icon of another
	 * collection, which the `wordpress` set must leave out.
	 *
	 * @return array[]
	 */
	public function get_registered_icons( $search = '' ): array {
		$icons = [];

		foreach ( glob( __DIR__ . '/../fixtures/icons/core/icon-library/*.svg' ) ?: [] as $path ) {
			$name    = basename( $path, '.svg' );
			$icons[] = [
				'name'       => 'core/' . $name,
				'collection' => 'core',
				'label'      => ucwords( str_replace( '-', ' ', $name ) ),
				'content'    => (string) preg_replace( '/(<svg\b[^>]*?)\s+fill="[^"]*"/', '$1', (string) file_get_contents( $path ) ),
			];
		}

		$icons[] = [
			'name'       => 'mine/extra-icon',
			'collection' => 'mine',
			'label'      => 'Not core',
			'content'    => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M0 0h1v1H0z"/></svg>',
		];

		return $icons;
	}
}
