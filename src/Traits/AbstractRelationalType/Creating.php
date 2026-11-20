<?php
/**
 * Base Relational Type: Creating
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Traits\AbstractRelationalType;

use SugarCommerce\Fields\Attributes;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Search\Creates;
use SugarCommerce\Fields\Search\Sources;
use SugarCommerce\Fields\Utils\Runtime;

/**
 * Adding a new item from the search: what it is called, who may, and what is sent.
 *
 * Part of AbstractRelationalType: only the class uses it.
 */
trait Creating {

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
	#[\Override]
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
