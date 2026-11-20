<?php
/**
 * Field Set
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields;

use SugarCommerce\Fields\Contracts\Context;
use SugarCommerce\Fields\Contracts\Lookup;
use SugarCommerce\Fields\Support\Dependencies;
use SugarCommerce\Fields\Support\Lookups;
use SugarCommerce\Fields\Support\Sections;
use SugarCommerce\Fields\Traits\FieldSet\Endpoints;
use SugarCommerce\Fields\Traits\FieldSet\Saving;

/**
 * A group of fields bound to one storage context.
 *
 * This is what a consuming library builds: a set of field configs, a context
 * to read and write them through, and the two calls that render and save.
 * Everything the five predecessor libraries duplicated lives beneath it.
 */
final class FieldSet {

	use Endpoints;
	use Saving;

	/**
	 * The key a submission lists fields to be emptied under.
	 *
	 * `{prefix}[__clear][{field}]`, beside the fields themselves. Honoured
	 * for a type that is Unechoed and ignored for every other -- see
	 * sanitized().
	 *
	 * @since 1.3.0
	 */
	public const string CLEAR_KEY = '__clear';

	/**
	 * Field configuration, keyed by field key.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $configs;

	/**
	 * Where values are read from and written to.
	 *
	 * @var Context
	 */
	private Context $context;

	/**
	 * The type registry.
	 *
	 * @var Registry
	 */
	private Registry $registry;

	/**
	 * The renderer.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Prefix applied to every input name.
	 *
	 * @var string
	 */
	private string $input_prefix;

	/**
	 * The messages from the last save(), keyed by field.
	 *
	 * Kept on the set rather than returned, because save() already returns
	 * what it stored and its callers rely on that shape. The next render()
	 * in the same request reads these for itself.
	 *
	 * @var array<string, string>
	 */
	private array $errors = [];

	/**
	 * Construct.
	 *
	 * @param array<string, array<string, mixed>> $configs      Field configuration.
	 * @param Context                             $context      Storage context.
	 * @param string                              $input_prefix Prefix for input names.
	 * @param Registry|null                       $registry     Type registry.
	 */
	public function __construct(
		array $configs,
		Context $context,
		string $input_prefix = '',
		?Registry $registry = null
	) {
		$this->configs      = $configs;
		$this->context      = $context;
		$this->input_prefix = $input_prefix;
		$this->registry     = $registry ?? new Registry();
		$this->renderer     = new Renderer();

		$this->register_search_sources();
		$this->register_actions();
		$this->register_lookups();

		self::boot_endpoints();
	}

	/**
	 * Build one field, with its stored value loaded.
	 *
	 * @param string     $key       Field key.
	 * @param int|string $object_id Object the values belong to.
	 *
	 * @return Field|null
	 */
	public function field( string $key, int|string $object_id = 0 ): ?Field {
		$config = $this->configs[ $key ] ?? null;

		if ( null === $config ) {
			return null;
		}

		$type = (string) ( $config['type'] ?? 'text' );

		if ( ! $this->registry->has( $type ) ) {
			return null;
		}

		$resolved = $this->registry->get( $type );

		$config = array_merge(
			$resolved->defaults(),
			$config,
			[
				'input_name' => '' === $this->input_prefix ? $key : $this->input_prefix . '[' . $key . ']',

				// Where a control that never shows what is stored says its
				// stored value is to be removed -- beside the fields, in the
				// same submission, under a key no field can have. Given to
				// the set's own fields only: a field inside a repeater row is
				// stored whole by its parent, and sanitized() never sees it.
				'clear_name' => '' === $this->input_prefix
					? self::CLEAR_KEY . '[' . $key . ']'
					: $this->input_prefix . '[' . self::CLEAR_KEY . '][' . $key . ']',
			]
		);

		// A callback-backed field points at the source registered for it.
		if ( isset( $config['search_callback'] ) ) {
			$config['search_source'] = $this->source_name_for( $key, $config );
		}

		// Likewise for its buttons: the field emits the registered names, so
		// a button in the page corresponds to a handler that exists.
		//
		// A consumer may have named them itself, for the same reason it may
		// name a search source — a set built with no input prefix derives the
		// name from the field key alone, and two panels each with an
		// `activate` button would name the same handler.
		if ( ! isset( $config['action_names'] ) || ! is_array( $config['action_names'] ) ) {
			$config['action_names'] = $this->action_names_for( $key, $config );
		}

		// The name its lookup was registered under, for a type that has one.
		if ( $resolved instanceof Lookup && Lookups::shared()->has( $this->lookup_name( $key ) ) ) {
			$config['lookup_name'] = $this->lookup_name( $key );
		}

		$field = new Field( $key, $resolved, $config, null );

		return $resolved->stores_value()
			? $field->with_value( $this->context->read( $object_id, $field ) )
			: $field;
	}

	/**
	 * Every field, in configuration order.
	 *
	 * @param int|string $object_id Object the values belong to.
	 *
	 * @return Field[]
	 */
	public function fields( int|string $object_id = 0 ): array {
		$fields = [];

		foreach ( array_keys( $this->configs ) as $key ) {
			$field = $this->field( (string) $key, $object_id );

			if ( null !== $field ) {
				$fields[] = $field;
			}
		}

		return $fields;
	}

	/**
	 * Render every field.
	 *
	 * With no messages passed, the ones from a save() earlier in the same
	 * request are used. A consumer that saves and then redraws the screen
	 * gets each failing field marked and explained without carrying the
	 * messages from one call to the other — a step every one of them would
	 * otherwise have to remember.
	 *
	 * @param int|string            $object_id Object the values belong to.
	 * @param array<string, string> $errors    Validation messages keyed by field.
	 *
	 * @return string
	 */
	public function render( int|string $object_id = 0, array $errors = [] ): string {
		if ( [] === $errors ) {
			$errors = $this->errors;
		}

		$fields = $this->fields( $object_id );
		$layout = Sections::split( $fields );

		$render = function ( array $group ) use ( $errors ): string {
			$markup = '';

			foreach ( $group as $field ) {
				// A lone marker is not a layout, and it draws nothing, so it
				// is dropped rather than left to emit an empty row.
				if ( Sections::is_marker( $field ) ) {
					continue;
				}

				$markup .= $this->renderer->render( $field, $errors[ $field->key() ] ?? '' );
			}

			return $markup;
		};

		if ( [] === $layout ) {
			return $render( $fields );
		}

		return Sections::render( $layout, $render, $this->input_prefix );
	}

	/**
	 * Render one field that has already been built.
	 *
	 * Consumers that lay fields out themselves — a term screen's table rows,
	 * a metabox's own grid — need the control without the set's own loop.
	 *
	 * As with render(), a message from a save() in the same request is used
	 * when none is passed, so a consumer with its own layout is not the one
	 * that has to remember.
	 *
	 * @param Field  $field      The field.
	 * @param string $error      Optional validation message.
	 * @param bool   $with_label Whether to emit the label.
	 *
	 * @return string
	 */
	public function render_field( Field $field, string $error = '', bool $with_label = true ): string {
		if ( '' === $error ) {
			$error = $this->errors[ $field->key() ] ?? '';
		}

		return $this->renderer->render( $field, $error, $with_label );
	}

	/**
	 * Declare this set's keys to WordPress.
	 *
	 * One line for a consuming library, and the object type is not one of the
	 * arguments: the context already knows what kind of store it is, being
	 * the thing that calls `update_metadata()` with that same string.
	 *
	 * A set backed by an option registers nothing. A settings page declares
	 * itself once with `register_setting()`, which is a different call with a
	 * different shape — not something to approximate per field.
	 *
	 * @param string $subtype Post type or taxonomy, where the object has one.
	 *
	 * @return string[] The keys that were registered.
	 */
	public function register_meta( string $subtype = '' ): array {
		return ( new MetaRegistrar( $this->context, $subtype, $this->registry ) )->register( $this->configs );
	}

	/**
	 * Script and style handles every field in the set needs, the code
	 * editor languages, and whether a visual editor is among them -- fields
	 * inside groups and repeaters included.
	 *
	 * @return array{scripts: string[], styles: string[], code_editors: string[], editor: bool}
	 */
	public function dependencies(): array {
		return Dependencies::of( $this->configs, $this->registry );
	}
}
