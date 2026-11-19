<?php
/**
 * Callback Search Source
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Search;

use WP_Error;

/**
 * A source backed by a consumer's own callable.
 *
 * The callable never reaches the page. A field names a source, the name is
 * what travels in the request, and it is resolved against a registry
 * populated server-side — so a request can only ever reach a source someone
 * deliberately registered, and cannot name an arbitrary function.
 *
 * The callable is invoked as `( string $term, array $ids, array $args )`.
 * An empty `$term` with a non-empty `$ids` is a request to resolve labels
 * for a known selection rather than to search, because those are usually
 * different queries.
 *
 * A field may hand it a second callable, under `'create' => [ 'callback' ]`,
 * to make an item from what was typed. Without one it creates nothing, and
 * says so to the create route like any source that cannot.
 */
final class CallbackSource implements Source, Creates {

	/**
	 * The name a field refers to this source by.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * The consumer's callable.
	 *
	 * @var callable
	 */
	private $callback;

	/**
	 * The capability required to search it.
	 *
	 * @var string
	 */
	private string $capability;

	/**
	 * The argument keys the field declared, and so the only ones passed on.
	 *
	 * @var string[]
	 */
	private array $keys;

	/**
	 * The consumer's callable for making an item, if it gave one.
	 *
	 * @var callable|null
	 */
	private $creator;

	/**
	 * The capability required to make one.
	 *
	 * @var string
	 */
	private string $create_capability;

	/**
	 * Construct.
	 *
	 * @param string        $name              Source name.
	 * @param callable      $callback          The consumer's callable.
	 * @param string        $capability        Capability required to search it.
	 * @param string[]      $keys              Argument keys the field declared.
	 * @param callable|null $creator           Callable that makes an item, if any.
	 * @param string        $create_capability Capability required to make one;
	 *                                         the search's when empty.
	 */
	public function __construct( string $name, callable $callback, string $capability = 'edit_posts', array $keys = [], ?callable $creator = null, string $create_capability = '' ) {
		$this->name              = $name;
		$this->callback          = $callback;
		$this->capability        = $capability;
		$this->keys              = array_values( array_map( 'strval', $keys ) );
		$this->creator           = $creator;
		$this->create_capability = '' === $create_capability ? $capability : $create_capability;
	}

	/**
	 * The name a field refers to this source by.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * The capability required to search it.
	 *
	 * @return string
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Search through the callable.
	 *
	 * @param string               $term  Search term.
	 * @param array<string, mixed> $args  Arguments the field supplied.
	 * @param int                  $page  One-based page number.
	 * @param int                  $limit Results per page.
	 *
	 * @return array{results: array<int, array{id: string, text: string}>, more: bool}
	 */
	public function search( string $term, array $args, int $page, int $limit ): array {
		$results = $this->normalize( ( $this->callback )( $term, [], $this->declared( $args ) ) );

		// Paged here rather than by the callable. Most of them are a
		// get_posts() or a $wpdb call that returns what matched, and asking
		// each to honour an offset is asking most of them to get it wrong --
		// and the endpoint's limit is not a limit if one source ignores it.
		$offset = max( 0, $page - 1 ) * $limit;

		return [
			'results' => array_slice( $results, $offset, $limit ),
			'more'    => count( $results ) > $offset + $limit,
		];
	}

	/**
	 * The arguments the field declared, out of whatever the request sent.
	 *
	 * The request is the browser's to compose, and the browser is not
	 * trusted: `search_args` says which keys a field sends, and only those
	 * reach the callable. A callback that folds its arguments into a query
	 * cannot then be steered with a `post_status` or a `meta_key` the field
	 * never mentioned.
	 *
	 * @param array<string, mixed> $args Cleaned request arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function declared( array $args ): array {
		return array_intersect_key( $args, array_flip( $this->keys ) );
	}

	/**
	 * Coerce whatever the callable returned into the endpoint's shape.
	 *
	 * Both the `[ id, text ]` shape and a plain `value => label` map are
	 * accepted, because both are the obvious thing to return and neither is
	 * worth a support question.
	 *
	 * @param mixed $results Whatever came back.
	 *
	 * @return array<int, array{id: string, text: string}>
	 */
	private function normalize( mixed $results ): array {
		if ( ! is_array( $results ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $results as $key => $result ) {
			if ( is_array( $result ) && isset( $result['id'] ) ) {
				self::warn_about_label_key( $result );

				$normalized[] = [
					'id'   => (string) $result['id'],
					'text' => (string) ( $result['text'] ?? $result['id'] ),
				];
				continue;
			}

			if ( is_scalar( $result ) ) {
				$normalized[] = [
					'id'   => (string) $key,
					'text' => (string) $result,
				];
			}
		}

		return $normalized;
	}

	/**
	 * Say so when a result names its label something this does not read.
	 *
	 * The key is `text`. A result carrying `label` or `name` instead is not
	 * an error: the id is used in its place, so the field renders and every
	 * entry in it is a number. That is indistinguishable from a callback
	 * returning nothing useful, and it is the mistake this contract invites,
	 * because `label` is what the rest of the kit calls the same idea.
	 *
	 * @param array<string, mixed> $result One result.
	 *
	 * @return void
	 */
	public static function warn_about_label_key( array $result ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! function_exists( '_doing_it_wrong' ) ) {
			return;
		}

		if ( isset( $result['text'] ) ) {
			return;
		}

		foreach ( [ 'label', 'name', 'title' ] as $wrong ) {
			if ( ! isset( $result[ $wrong ] ) ) {
				continue;
			}

			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: the key that was used instead of `text` */
					esc_html__( 'A search result named its label "%s". The key is "text"; every result will show its id instead.', 'sugarcommerce' ),
					esc_html( $wrong )
				),
				'1.0.0'
			);

			return;
		}
	}

	/**
	 * Whether the field gave a way to make an item, and the current user
	 * holds the capability it asked for.
	 *
	 * The search's capability unless the field named another: being allowed
	 * to look something up is often not being allowed to add to it.
	 *
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return bool
	 * @since 1.7.0
	 */
	public function can_create( array $args ): bool {
		return null !== $this->creator && current_user_can( $this->create_capability );
	}

	/**
	 * Make an item through the consumer's callable.
	 *
	 * It is called as `( string $text, array $args )`, with the same declared
	 * arguments the search gets, and may return an id, an `[ id, text ]`
	 * array (with `existing` when it found rather than made one) or a
	 * WP_Error whose message the control shows.
	 *
	 * @param string               $text What was typed.
	 * @param array<string, mixed> $args Arguments the field supplied.
	 *
	 * @return array{id: string, text: string, existing: bool}|WP_Error
	 * @since 1.7.0
	 */
	public function create( string $text, array $args ): array|WP_Error {
		$made = null === $this->creator ? null : ( $this->creator )( $text, $this->declared( $args ) );

		if ( $made instanceof WP_Error ) {
			if ( ! is_array( $made->get_error_data() ) ) {
				$made->add_data( [ 'status' => 400 ] );
			}

			return $made;
		}

		if ( is_array( $made ) && isset( $made['id'] ) && '' !== (string) $made['id'] ) {
			return [
				'id'       => (string) $made['id'],
				'text'     => (string) ( $made['text'] ?? $text ),
				'existing' => (bool) ( $made['existing'] ?? false ),
			];
		}

		if ( ( is_int( $made ) || is_string( $made ) ) && '' !== (string) $made ) {
			return [
				'id'       => (string) $made,
				'text'     => $text,
				'existing' => false,
			];
		}

		return new WP_Error(
			'sugarcommerce_fields_not_created',
			__( 'That could not be added.', 'sugarcommerce' ),
			[ 'status' => 500 ]
		);
	}
}
