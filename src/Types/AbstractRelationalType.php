<?php
/**
 * Base Relational Type
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Types;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Search\Creates;
use SugarCommerce\Fields\Search\Sources;
use SugarCommerce\Fields\Utils\Runtime;
use SugarCommerce\Fields\Contracts\AdminOnly;

/**
 * Anything that searches a database source for its options.
 *
 * There were three implementations of this idea across the predecessor
 * libraries — setting-fields had a REST route, post-fields had its own, and
 * flyouts had a `search_callback` on top of admin-ajax. All three resolve to
 * one endpoint here, with one contract.
 *
 * The control stays a real `<select>` carrying the current selection as
 * options. That matters twice over: the field still works when the
 * enhancement script does not load, and the enhancement has a genuine
 * element to mirror instead of inventing state. The searchable overlay is
 * expected to implement the ARIA combobox pattern against it; the data
 * attributes below are what it binds to.
 *
 * Two ways for a search that found nothing to end in a value, and they are
 * not the same thing:
 *
 * - `creatable` stores what was typed, as text. A tag that never needs to be
 *   anything but its own name. The schema is then strings.
 * - `create` makes a real post, term or user through the create route and
 *   stores its id, exactly as if it had been found. The schema stays
 *   integers. Set both and `create` wins: a value cannot be an id and the
 *   raw text at once, and the field that asked to make real items would
 *   otherwise store text the moment the route said no.
 */
abstract class AbstractRelationalType extends SelectType implements AdminOnly {

	/**
	 * The source the search endpoint queries.
	 *
	 * @return string
	 */
	abstract protected function source(): string;

	/**
	 * The source this field actually searches.
	 *
	 * Usually the type's own name. A type whose source is decided per field
	 * rather than per type overrides this — an AJAX field's callback is
	 * registered under a name derived from where the field lives, so the name
	 * in the page has to be that one and not the type's.
	 *
	 * Declared here rather than asked for with method_exists() at the call
	 * site, because a class asking whether it has its own method is a class
	 * that has not decided what its interface is — and it hides the answer
	 * from every tool that would otherwise check the call.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	protected function source_for( Field $field ): string {
		return $this->source();
	}

	/**
	 * Resolve the labels for the currently selected ids.
	 *
	 * Only the selection is rendered as options — the point of searching is
	 * that the full list is too large to emit.
	 *
	 * @param array<int, int|string> $ids Selected ids.
	 * @param Field $field The field.
	 *
	 * @return array<string, string>
	 */
	abstract protected function resolve_labels( array $ids, Field $field ): array;

	/**
	 * Query arguments forwarded to the endpoint.
	 *
	 * Whatever the field declares under `search_args`, so a callback source
	 * can be told something the search term does not carry -- which record
	 * the picker is sitting in, most often, so it can leave that one out.
	 * Without this the attribute existed and nothing could ever fill it.
	 *
	 * They reach the callback as its third argument, and the endpoint
	 * reduces them to scalars before they get there.
	 *
	 * Subclasses that build their own arguments override this; a post picker
	 * has a post type to send and no use for anything a caller invents.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 */
	protected function search_args( Field $field ): array {
		$args = $field->get( 'search_args', [] );

		if ( ! is_array( $args ) ) {
			return [];
		}

		return array_filter( $args, 'is_scalar' );
	}

	/**
	 * The stored selection, as the ids its labels are looked up by.
	 *
	 * Posts, terms and users key on an integer id. A source that keys on
	 * something else -- a slug, a reference from another system -- says so
	 * by overriding this, or every value it stored is thrown away before
	 * it can be labelled and the control opens empty on a saved value.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<int, int|string>
	 * @since 1.6.0
	 */
	protected function selected_ids( Field $field ): array {
		return array_values( array_filter( array_map( 'absint', $this->selected_values( $field ) ) ) );
	}

	/**
	 * Options are only ever the current selection.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	protected function render_options( Field $field ): string {
		$ids    = $this->selected_ids( $field );
		$markup = '';

		if ( ! $this->is_multiple( $field ) && ! $field->is_required() ) {
			$markup .= sprintf(
				'<option value="">%s</option>',
				esc_html( $field->placeholder() ?: __( '— Select —', 'sugarcommerce' ) )
			);
		}

		$labels = $this->resolve_labels( $ids, $field );

		// A created value never had an id to resolve, so it labels itself.
		// Without this a tag someone added disappears from the control on the
		// next load while still being stored.
		if ( $this->invents( $field ) ) {
			foreach ( array_map( 'strval', (array) $this->selected_values( $field ) ) as $raw ) {
				if ( '' !== $raw && ! isset( $labels[ $raw ] ) ) {
					$labels[ $raw ] = $raw;
				}
			}
		}

		foreach ( $labels as $id => $label ) {
			$markup .= sprintf(
				'<option value="%s" selected>%s</option>',
				esc_attr( (string) $id ),
				esc_html( $label )
			);
		}

		return $markup;
	}

	/**
	 * Render the select with the search wiring attached.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return string
	 */
	public function render( Field $field, Attributes $attributes ): string {
		$attributes->add_class( 'field-kit__relational' );
		$attributes->set( 'data-search-endpoint', rest_url( Runtime::rest_namespace() . '/search' ) );
		$attributes->set( 'data-search-source', $this->source_for( $field ) );
		$attributes->set( 'data-search-nonce', wp_create_nonce( 'wp_rest' ) );
		$attributes->set_if( $field->has( 'min_chars' ), 'data-min-chars', $field->get( 'min_chars', 2 ) );

		$args = $this->search_args( $field );

		$attributes->set_if( [] !== $args, 'data-search-args', $args );

		// A field with a callback is resolved server-side by key, so the
		// callable itself never reaches the page.

		$attributes->set_if( $this->invents( $field ), 'data-creatable', 'true' );

		if ( $this->offers_create( $field ) ) {
			$this->add_create_attributes( $field, $attributes );
		}

		/*
		 * Order is only meaningful when more than one thing can be chosen, and
		 * it is only worth carrying when the caller says so -- a set of related
		 * products has an order the editor cares about, a set of tags does not.
		 */
		$attributes->set_if(
			$this->is_multiple( $field ) && (bool) $field->get( 'sortable', false ),
			'data-sortable',
			'true'
		);

		$attributes->set_if( $field->has( 'min' ), 'data-min', (int) $field->get( 'min', 0 ) );
		$attributes->set_if( $field->has( 'max' ), 'data-max', (int) $field->get( 'max', 0 ) );

		return parent::render( $field, $attributes );
	}

	/**
	 * A search control says so.
	 *
	 * "Choose one or more" is right for a list already on the page and wrong
	 * for one that is fetched as you type: nothing is on offer until a query
	 * is typed, so the instruction has to be to type.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 */
	protected function default_placeholder( Field $field ): string {
		if ( $this->invents( $field ) || $this->offers_create( $field ) ) {
			return __( 'Search, or type to add', 'sugarcommerce' );
		}

		return $this->is_multiple( $field )
			? __( 'Search to add one or more', 'sugarcommerce' )
			: __( 'Type to search', 'sugarcommerce' );
	}

	/**
	 * Always searchable.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 */
	protected function uses_enhanced_ui( Field $field ): bool {
		return true;
	}

	/**
	 * The option list is not a closed allow-list here.
	 *
	 * @param Field $field The field.
	 *
	 * @return string[]
	 */
	protected function allowed_values( Field $field ): array {
		return [];
	}

	/**
	 * Coerce a submitted value to ids.
	 *
	 * @param mixed $value Raw submitted value.
	 * @param Field $field The field.
	 *
	 * The return type stays wide: most sources key on an integer id, but a
	 * custom one can key on a slug or an external reference, and a narrowed
	 * type here would stop that subclass declaring what it actually stores.
	 *
	 * @return mixed
	 */
	public function sanitize( mixed $value, Field $field ): mixed {
		// A creatable relational field stores whatever was typed alongside
		// the ids it found — a new tag has no id yet, and absint() would
		// turn it into 0 and throw it away.
		if ( $this->invents( $field ) ) {
			$values = array_map( 'sanitize_text_field', array_map( 'strval', (array) $value ) );
			$values = array_values( array_filter( $values, static fn( $one ) => '' !== $one ) );

			return $this->is_multiple( $field )
				? $this->apply_max( $values, $field )
				: ( $values[0] ?? '' );
		}

		if ( $this->is_multiple( $field ) ) {
			return $this->apply_max(
				array_values( array_filter( array_map( 'absint', (array) $value ) ) ),
				$field
			);
		}

		$id = absint( is_array( $value ) ? reset( $value ) : $value );

		// Nothing selected comes back as '' rather than 0, the same as an
		// emptied media field. Zero is a value as far as the field set is
		// concerned — an unticked checkbox deliberately stores it — so
		// returning it here writes a post id of 0 for every relational field
		// nobody touched, and get_the_title( 0 ) is not a post that exists.
		return 0 === $id ? '' : $id;
	}

	/**
	 * An object id, or a list of them.
	 *
	 * A creatable field stores text as well as ids — a value someone typed
	 * has no id yet — so its schema cannot promise integers. One that
	 * creates real items through the route stores their ids, and can.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 */
	public function schema( Field $field ): array {
		$one = $this->invents( $field )
			? [ 'type' => 'string' ]
			: [ 'type' => 'integer' ];

		return $this->is_multiple( $field )
			? [
				'type'  => 'array',
				'items' => $one,
			]
			: $one;
	}

	/**
	 * The configuration keys this type reads.
	 *
	 * @return string[]
	 */
	public function config_keys(): array {
		// 'creatable' is already declared by SelectType; repeating it here put
		// it in the list twice.
		return array_merge(
			parent::config_keys(),
			[ 'min_chars', 'search_callback', 'search_args', 'sortable', 'min', 'max', 'create' ]
		);
	}

	/**
	 * Cut a selection down to the configured maximum.
	 *
	 * The control refuses to take more than `max`, but the control is JavaScript
	 * and the value arrives over HTTP. A limit enforced only in the browser is
	 * not a limit -- anything posting directly ignores it -- so the extra values
	 * are dropped here as well.
	 *
	 * Extras are cut from the end, so the editor keeps what they chose first.
	 *
	 * @param array $values The sanitised selection.
	 * @param Field $field  The field.
	 *
	 * @return array
	 * @since 1.1.0
	 */
	protected function apply_max( array $values, Field $field ): array {
		$max = (int) $field->get( 'max', 0 );

		return $max > 0 ? array_slice( $values, 0, $max ) : $values;
	}

	/**
	 * Whether the field keeps what was typed as text (`creatable`).
	 *
	 * Not when it also asks to `create`: that one stores ids, and the two
	 * cannot both be true of one value.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	protected function invents( Field $field ): bool {
		return (bool) $field->get( 'creatable', false ) && ! $this->creates( $field );
	}

	/**
	 * Whether the field offers to make a new item when a search finds none.
	 *
	 * `'create' => true`, or an array of options -- which is on too, since
	 * nobody configures the noun of a feature they have turned off.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	protected function creates( Field $field ): bool {
		$create = $field->get( 'create', false );

		return true === $create || is_array( $create );
	}

	/**
	 * Whether the Add row is offered to the person looking at the field.
	 *
	 * The field asked, and its source can make things and would let this
	 * person make this one. Asked of the source while drawing, so someone
	 * who may not add a term is never shown a row that can only be refused.
	 * The route asks again when the row is chosen; this is the courtesy, that
	 * is the rule. Whether the field stores ids does not depend on it: that
	 * is creates(), which is about the configuration and not about who is
	 * looking.
	 *
	 * @param Field $field The field.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	protected function offers_create( Field $field ): bool {
		if ( ! $this->creates( $field ) ) {
			return false;
		}

		$source = Sources::shared()->get( $this->source_for( $field ) );

		return $source instanceof Creates
			&& $source->can_create( array_merge( $this->search_args( $field ), $this->create_args( $field ) ) );
	}

	/**
	 * The options under `create`, when it is an array.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, mixed>
	 * @since 1.7.0
	 */
	protected function create_options( Field $field ): array {
		$create = $field->get( 'create', false );

		return is_array( $create ) ? $create : [];
	}

	/**
	 * What the Add row calls a new item: "Add “Sample Packs” as a new
	 * collection".
	 *
	 * The field's own `noun` when it gave one, and otherwise the type's --
	 * the singular label of the post type or taxonomy, for the types that
	 * have one.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	protected function create_noun( Field $field ): string {
		$noun = $this->create_options( $field )['noun'] ?? '';

		if ( is_string( $noun ) && '' !== trim( $noun ) ) {
			return trim( $noun );
		}

		return $this->default_create_noun( $field );
	}

	/**
	 * The noun when the field gave none.
	 *
	 * @param Field $field The field.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	protected function default_create_noun( Field $field ): string {
		return __( 'item', 'sugarcommerce' );
	}

	/**
	 * What the create route is sent beside the search's own arguments: a
	 * status, a role. Nothing by default.
	 *
	 * These are requests, not permissions. The source holds each against
	 * what the current user may do, so a page that rewrites them can ask for
	 * less but never get more.
	 *
	 * @param Field $field The field.
	 *
	 * @return array<string, scalar>
	 * @since 1.7.0
	 */
	protected function create_args( Field $field ): array {
		return [];
	}

	/**
	 * What has to be typed for the Add row to be offered: `text`, or
	 * `email` for a user, who is added by their address.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	protected function create_input(): string {
		return 'text';
	}

	/**
	 * The attributes the control's Add row is built from.
	 *
	 * The route is named in the page as the search is, so a prefixed build
	 * posts to its own namespace, and the noun is resolved here because the
	 * labels it comes from are not something the browser can see.
	 *
	 * @param Field      $field      The field.
	 * @param Attributes $attributes Prepared attributes.
	 *
	 * @return void
	 * @since 1.7.0
	 */
	private function add_create_attributes( Field $field, Attributes $attributes ): void {
		$args  = $this->create_args( $field );
		$input = $this->create_input();

		$attributes->set( 'data-create-endpoint', rest_url( Runtime::rest_namespace() . '/search/create' ) );
		$attributes->set( 'data-create-noun', $this->create_noun( $field ) );
		$attributes->set_if( [] !== $args, 'data-create-args', $args );
		$attributes->set_if( 'text' !== $input, 'data-create-input', $input );
	}

	/**
	 * A label as it reads in the middle of a sentence.
	 *
	 * "Collection" becomes "collection" and "Sample Pack" "sample pack", but
	 * a word that is more than capitalised -- "FAQ", "iPhone" -- is a name
	 * and is left as it is. Lower-casing the lot would write "Add as a new
	 * faq entry".
	 *
	 * @param string $label A singular label.
	 *
	 * @return string
	 * @since 1.7.0
	 */
	protected static function in_sentence( string $label ): string {
		return (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}])\p{Lu}\p{Ll}*(?![\p{L}\p{N}])/u',
			static fn( array $word ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( $word[0] ) : strtolower( $word[0] ),
			trim( $label )
		);
	}
}
