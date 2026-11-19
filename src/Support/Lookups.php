<?php
/**
 * Lookup Handlers
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.4.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Support;

use SugarCommerce\Fields\Contracts\Lookup;
use SugarCommerce\Fields\Registry;

/**
 * The handlers the lookup endpoint can call, by name.
 *
 * One registry for the request, filled as field sets are built and read by
 * the endpoint, the same arrangement as search sources and actions.
 */
final class Lookups {

	/**
	 * The shared registry.
	 *
	 * @var self|null
	 */
	private static ?self $shared = null;

	/**
	 * Handlers by name: the callable and the capability asking needs.
	 *
	 * @var array<string, array{0: callable, 1: string}>
	 */
	private array $handlers = [];

	/**
	 * The registry for this request.
	 *
	 * @return self
	 */
	public static function shared(): self {
		if ( null === self::$shared ) {
			self::$shared = new self();
		}

		return self::$shared;
	}

	/**
	 * Register a handler.
	 *
	 * @param string   $name       The name the field emits.
	 * @param callable $handler    Called with what was typed.
	 * @param string   $capability What asking needs; empty for anyone.
	 *
	 * @return self
	 */
	public function register( string $name, callable $handler, string $capability = 'edit_posts' ): self {
		$this->handlers[ sanitize_key( $name ) ] = [ $handler, $capability ];

		return $this;
	}

	/**
	 * Register the lookups of a surface's fields before anything is drawn.
	 *
	 * For a library that builds its field set while drawing -- a flyout
	 * panel fetched over REST -- the request that asks a slug's check never
	 * builds one, so the handler is registered with the surface instead,
	 * the way it registers its search sources. Each name is the prefix and
	 * the field's key, and is written back as `lookup_name`, which the field
	 * set then keeps.
	 *
	 * @param string                              $prefix   What makes the names this surface's own.
	 * @param array<string, array<string, mixed>> $configs  Field configuration, by key.
	 * @param Registry|null                       $registry The type registry.
	 *
	 * @return array<string, array<string, mixed>> The configuration, with the names.
	 */
	public static function register_configs( string $prefix, array $configs, ?Registry $registry = null ): array {
		$registry = $registry ?? new Registry();

		foreach ( $configs as $key => $config ) {
			$type = is_array( $config ) ? (string) ( $config['type'] ?? 'text' ) : '';

			if ( '' === $type || ! $registry->has( $type ) ) {
				continue;
			}

			$resolved = $registry->get( $type );
			$handler  = $resolved instanceof Lookup ? $resolved->lookup_handler( $config ) : null;

			if ( null === $handler ) {
				continue;
			}

			$name = sanitize_key( (string) ( $config['lookup_name'] ?? $prefix . '_' . $key ) );

			self::shared()->register( $name, $handler, $resolved->lookup_capability( $config ) );

			$configs[ $key ]['lookup_name'] = $name;
		}

		return $configs;
	}

	/**
	 * Whether a name is registered.
	 *
	 * @param string $name Handler name.
	 *
	 * @return bool
	 */
	public function has( string $name ): bool {
		return isset( $this->handlers[ sanitize_key( $name ) ] );
	}

	/**
	 * The handler for a name.
	 *
	 * @param string $name Handler name.
	 *
	 * @return callable|null
	 */
	public function handler( string $name ): ?callable {
		return $this->handlers[ sanitize_key( $name ) ][0] ?? null;
	}

	/**
	 * The capability a name needs, or null when it is not registered.
	 *
	 * @param string $name Handler name.
	 *
	 * @return string|null
	 */
	public function capability( string $name ): ?string {
		return $this->handlers[ sanitize_key( $name ) ][1] ?? null;
	}
}
