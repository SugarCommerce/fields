<?php
/**
 * Field Set: Endpoints
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\FieldSet;

use SugarCommerce\Fields\Actions\Actions;
use SugarCommerce\Fields\Actions\CallbackAction;
use SugarCommerce\Fields\Contracts\Lookup;
use SugarCommerce\Fields\Rest\ActionController;
use SugarCommerce\Fields\Rest\IconController;
use SugarCommerce\Fields\Rest\LookupController;
use SugarCommerce\Fields\Rest\SearchController;
use SugarCommerce\Fields\Search\CallbackSource;
use SugarCommerce\Fields\Search\Sources;
use SugarCommerce\Fields\Support\Lookups;

/**
 * The searches, buttons and lookups a set's fields call, registered under the names the fields emit.
 *
 * Part of FieldSet: only the class uses it.
 */
trait Endpoints {

	/**
	 * Register the REST routes, once per request.
	 *
	 * Booted from here rather than left to the consumer: a field emits an
	 * endpoint URL whether or not anyone remembered to register the route,
	 * and a button posting to a 404 looks exactly like a button that does
	 * nothing. Both controllers refuse to register the same namespace twice,
	 * so several field sets on one screen is not a problem.
	 *
	 * @return void
	 */
	private static function boot_endpoints(): void {
		static $booted = false;

		if ( $booted ) {
			return;
		}

		$booted = true;

		( new SearchController() )->boot();
		( new ActionController() )->boot();
		( new IconController() )->boot();
		( new LookupController() )->boot();
	}

	/**
	 * Register a lookup for every field whose type asks the server as it is
	 * used -- a slug checking it is free, a location asking a geocoder.
	 *
	 * Built here for the reason searches and actions are: the endpoint finds
	 * the handler on a later request, when nothing has rendered.
	 *
	 * @return void
	 */
	private function register_lookups(): void {
		foreach ( $this->configs as $key => $config ) {
			$type = (string) ( $config['type'] ?? 'text' );

			if ( ! $this->registry->has( $type ) ) {
				continue;
			}

			$resolved = $this->registry->get( $type );

			if ( ! $resolved instanceof Lookup ) {
				continue;
			}

			$handler = $resolved->lookup_handler( (array) $config );

			if ( null !== $handler ) {
				Lookups::shared()->register( $this->lookup_name( (string) $key ), $handler, $resolved->lookup_capability( (array) $config ) );
			}
		}
	}

	/**
	 * Register a search source for every field that supplies a callback.
	 *
	 * Done at construction rather than at render time: the endpoint resolves
	 * the source on a later request, by which point nothing has rendered.
	 * The source is named after the field, so the name in the page is
	 * meaningless to anyone who has not registered it.
	 *
	 * @return void
	 */
	private function register_search_sources(): void {
		$sources = Sources::shared();

		foreach ( $this->configs as $key => $config ) {
			$callback = $config['search_callback'] ?? null;

			if ( ! is_callable( $callback ) ) {
				continue;
			}

			// A field that may add to its own source names the callable that
			// does it inside `create`, beside the noun the control shows.
			$create  = is_array( $config['create'] ?? null ) ? $config['create'] : [];
			$creator = $create['callback'] ?? null;

			$sources->register(
				new CallbackSource(
					$this->source_name_for( (string) $key, $config ),
					$callback,
					(string) ( $config['search_capability'] ?? 'edit_posts' ),
					array_keys( (array) ( $config['search_args'] ?? [] ) ),
					is_callable( $creator ) ? $creator : null,
					(string) ( $create['capability'] ?? '' )
				)
			);
		}
	}

	/**
	 * Register an action for every field that supplies a handler.
	 *
	 * Same reasoning as the search sources: the endpoint resolves the handler
	 * on a later request, so registration cannot wait for a render, and the
	 * name is meaningless to anyone who has not registered it.
	 *
	 * A field may name several — a licence has activate and deactivate, an
	 * email has preview and test — so handlers are read from an `actions`
	 * map as well as from a single `action_callback`.
	 *
	 * @return void
	 */
	private function register_actions(): void {
		$actions = Actions::shared();

		foreach ( $this->configs as $key => $config ) {
			$handlers = (array) ( $config['actions'] ?? [] );

			if ( isset( $config['action_callback'] ) ) {
				$handlers['run'] = $config['action_callback'];
			}

			$named = (array) ( $config['action_names'] ?? [] );

			foreach ( $handlers as $name => $callback ) {
				if ( ! is_callable( $callback ) ) {
					continue;
				}

				$actions->register(
					new CallbackAction(
						(string) ( $named[ $name ] ?? $this->action_name( (string) $key, (string) $name ) ),
						$callback,
						(string) ( $config['action_capability'] ?? 'manage_options' ),
						self::rich_inputs( (array) $config )
					)
				);
			}
		}
	}

	/**
	 * The inputs of a field whose button should be sent them as markup.
	 *
	 * A button sends every named input inside its field, each under the
	 * last part of its name. The ones that are a visual editor hold markup,
	 * and an action handed them as plain text is handed something other
	 * than what is on the screen.
	 *
	 * Read from the field's own parts, plus `body` for an email, whose parts
	 * are the type's when none are given. `action_rich` names any others.
	 *
	 * @param array<string, mixed> $config The field's configuration.
	 *
	 * @return string[]
	 */
	private static function rich_inputs( array $config ): array {
		$rich = array_map( 'strval', (array) ( $config['action_rich'] ?? [] ) );

		foreach ( (array) ( $config['fields'] ?? [] ) as $key => $part ) {
			if ( 'wysiwyg' === ( is_array( $part ) ? ( $part['type'] ?? '' ) : '' ) ) {
				$rich[] = (string) $key;
			}
		}

		if ( 'email_editor' === ( $config['type'] ?? '' ) ) {
			$rich[] = 'body';
		}

		return array_values( array_unique( array_map( 'sanitize_key', $rich ) ) );
	}

	/**
	 * The action name a field's button uses.
	 *
	 * @param string $key  Field key.
	 * @param string $name Action name within the field.
	 *
	 * @return string
	 */
	public function action_name( string $key, string $name ): string {
		return sanitize_key(
			( '' === $this->input_prefix ? '' : $this->input_prefix . '_' ) . $key . '_' . $name
		);
	}

	/**
	 * The source name a callback-backed field uses.
	 *
	 * @param string $key Field key.
	 *
	 * @return string
	 */
	public function source_name( string $key ): string {
		return sanitize_key( ( '' === $this->input_prefix ? '' : $this->input_prefix . '_' ) . $key );
	}

	/**
	 * The name a field's lookup is registered under: one its surface gave
	 * it (Lookups::register_configs()), or one made like a source's.
	 *
	 * @param string $key Field key.
	 *
	 * @return string
	 */
	private function lookup_name( string $key ): string {
		$named = (string) ( $this->configs[ $key ]['lookup_name'] ?? '' );

		return '' === $named ? $this->source_name( $key ) : sanitize_key( $named );
	}

	/**
	 * The name this field's source is actually registered under.
	 *
	 * A consumer may name it itself, and one has to: a field set built with
	 * no input prefix derives the name from the field key alone, so two
	 * flyouts each with a `customer` field would name the same source and the
	 * second registration would answer the first's searches. A library that
	 * knows what distinguishes its field sets — which flyout, which metabox —
	 * supplies the name, and the same name is what the field emits.
	 *
	 * @param string               $key    Field key.
	 * @param array<string, mixed> $config Field configuration.
	 *
	 * @return string
	 */
	private function source_name_for( string $key, array $config ): string {
		$named = (string) ( $config['search_source'] ?? '' );

		return '' === $named ? $this->source_name( $key ) : $named;
	}

	/**
	 * The registered action names for one field, keyed by their local name.
	 *
	 * @param string               $key    Field key.
	 * @param array<string, mixed> $config Field configuration.
	 *
	 * @return array<string, string>
	 */
	private function action_names_for( string $key, array $config ): array {
		$names    = [];
		$handlers = (array) ( $config['actions'] ?? [] );

		if ( isset( $config['action_callback'] ) ) {
			$handlers['run'] = $config['action_callback'];
		}

		foreach ( array_keys( $handlers ) as $name ) {
			$names[ (string) $name ] = $this->action_name( $key, (string) $name );
		}

		return $names;
	}
}
