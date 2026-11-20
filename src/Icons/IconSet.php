<?php
/**
 * One Registered Icon Set
 *
 * @package     SugarCommerce\Fields
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL-2.0-or-later
 * @since       1.5.0
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Icons;

/**
 * Class IconSet
 *
 * A named collection of icons and where they come from: a folder of SVG
 * files, a callback that returns them, or an icon font that draws them from
 * a class name.
 *
 * Nothing is read until something asks. A set of a thousand icons costs a
 * directory listing to search and one file per icon drawn, so registering a
 * large set is free on every screen that never opens the picker.
 *
 * @since 1.5.0
 */
final class IconSet {

	/**
	 * What a set id and an icon name may be.
	 *
	 * Both end up in a stored value (`set:name`), a class attribute and a
	 * URL, so neither may hold anything that needs escaping in any of them.
	 */
	public const string ID_PATTERN = '/^[a-z0-9][a-z0-9-]*$/';
	public const string NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]*$/';

	/**
	 * The set's id, the part of a value before the colon.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * What the picker's tab says.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * A directory of `.svg` files, or empty.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * A callable returning markup by name, or null.
	 *
	 * A font set has neither, and lists its names in `$listed`.
	 *
	 * @var callable|null
	 */
	private $callback;

	/**
	 * For an icon font, the class an icon is drawn with before its name.
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * For an icon font, the stylesheet handle that loads it.
	 *
	 * @var string
	 */
	private string $stylesheet;

	/**
	 * The licence line the picker's footer shows for this set.
	 *
	 * @var string
	 */
	private string $license;

	/**
	 * `outline`, `solid` or empty: a hint, shown beside the label.
	 *
	 * @var string
	 */
	private string $style;

	/**
	 * Extra search words by icon name.
	 *
	 * @var array<string, string[]>
	 */
	private array $keywords;

	/**
	 * Labels by icon name, where the name does not read well on its own.
	 *
	 * @var array<string, string>
	 */
	private array $labels;

	/**
	 * Names for a font set with no source.
	 *
	 * @var string[]
	 */
	private array $listed;

	/**
	 * The capability needed to browse the set over REST.
	 *
	 * @var string
	 */
	private string $capability;

	/**
	 * The caller's version, or empty to have one worked out.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Raw markup by name for a callable source, or a path by name for a
	 * directory -- read once, on first use.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $index = null;

	/**
	 * Cleaned markup by name, for the icons drawn so far.
	 *
	 * @var array<string, string>
	 */
	private array $clean = [];

	/**
	 * Construct.
	 *
	 * @param string               $id   The set's id.
	 * @param array<string, mixed> $args See Icons::register().
	 *
	 * @since 1.5.0
	 */
	public function __construct( string $id, array $args ) {
		$source = $args['source'] ?? null;

		$this->id         = $id;
		$this->label      = '' !== trim( (string) ( $args['label'] ?? '' ) ) ? trim( (string) $args['label'] ) : $id;
		$this->directory  = is_string( $source ) && '' !== $source && is_dir( $source ) ? rtrim( $source, '/\\' ) : '';
		$this->callback   = '' === $this->directory && is_callable( $source ) ? $source : null;
		$this->prefix     = trim( (string) ( $args['prefix'] ?? '' ) );
		$this->stylesheet = (string) ( $args['stylesheet'] ?? '' );
		$this->license    = trim( (string) ( $args['license'] ?? '' ) );
		$this->style      = in_array( $args['style'] ?? '', [ 'outline', 'solid' ], true ) ? (string) $args['style'] : '';
		$this->keywords   = self::keyword_map( $args['keywords'] ?? [] );
		$this->labels     = array_map( 'strval', array_filter( (array) ( $args['labels'] ?? [] ), 'is_scalar' ) );
		$this->listed     = array_values( array_filter( array_map( 'strval', (array) ( $args['names'] ?? [] ) ), [ self::class, 'valid_name' ] ) );
		$this->capability = '' !== (string) ( $args['capability'] ?? '' ) ? (string) $args['capability'] : 'edit_posts';
		$this->version    = (string) ( $args['version'] ?? '' );
	}

	/**
	 * Whether a string can be a set's id.
	 *
	 * @param string $id Candidate id.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function valid_id( string $id ): bool {
		return 1 === preg_match( self::ID_PATTERN, $id );
	}

	/**
	 * Whether a string can be an icon's name.
	 *
	 * @param string $name Candidate name.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public static function valid_name( string $name ): bool {
		return 1 === preg_match( self::NAME_PATTERN, $name );
	}

	/**
	 * The set's id.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * What the picker calls it.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * `outline`, `solid`, or empty.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function style(): string {
		return $this->style;
	}

	/**
	 * The licence line for the picker's footer.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function license(): string {
		return $this->license;
	}

	/**
	 * The capability needed to browse it.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * The stylesheet handle an icon font needs, or empty.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function stylesheet(): string {
		return $this->stylesheet;
	}

	/**
	 * Every icon name in the set, sorted.
	 *
	 * @return string[]
	 * @since 1.5.0
	 */
	public function names(): array {
		return array_keys( $this->index() );
	}

	/**
	 * How many icons the set has.
	 *
	 * @return int
	 * @since 1.5.0
	 */
	public function count(): int {
		return count( $this->index() );
	}

	/**
	 * Whether the set has an icon of this name.
	 *
	 * @param string $name Icon name.
	 *
	 * @return bool
	 * @since 1.5.0
	 */
	public function has( string $name ): bool {
		return isset( $this->index()[ $name ] );
	}

	/**
	 * One icon's cleaned markup.
	 *
	 * @param string $name Icon name.
	 *
	 * @return string An `<svg>`, or '' for a font icon or an unknown name.
	 * @since 1.5.0
	 */
	public function svg( string $name ): string {
		if ( isset( $this->clean[ $name ] ) ) {
			return $this->clean[ $name ];
		}

		$index = $this->index();

		if ( ! isset( $index[ $name ] ) ) {
			return '';
		}

		$raw = $index[ $name ];

		// A directory's index holds the path; the file is read now, when
		// the icon is wanted, and not when the set was listed.
		if ( '' !== $this->directory ) {
			$raw = is_readable( $raw ) ? (string) file_get_contents( $raw ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file.
		}

		$this->clean[ $name ] = '' === $raw ? '' : Svg::sanitize( $raw );

		return $this->clean[ $name ];
	}

	/**
	 * An icon drawn as an icon font draws it.
	 *
	 * @param string $name  Icon name.
	 * @param array  $attrs As Svg::render() takes them.
	 *
	 * @return string A `<span>`, or '' when the set is not a font.
	 * @since 1.5.0
	 */
	public function font( string $name, array $attrs = [] ): string {
		if ( '' === $this->prefix || ! $this->has( $name ) ) {
			return '';
		}

		$size  = (int) ( $attrs['size'] ?? 0 );
		$label = trim( (string) ( $attrs['label'] ?? '' ) );
		$style = $size > 0 ? sprintf( 'width:%1$dpx;height:%1$dpx;font-size:%1$dpx;', $size ) : '';
		$style = $style . trim( (string) ( $attrs['style'] ?? '' ) );

		return sprintf(
			'<span class="%1$s"%2$s%3$s></span>',
			esc_attr( trim( $this->prefix . $name . ' ' . (string) ( $attrs['class'] ?? '' ) ) ),
			'' !== $style ? sprintf( ' style="%s"', esc_attr( $style ) ) : '',
			'' !== $label ? sprintf( ' role="img" aria-label="%s"', esc_attr( $label ) ) : ' aria-hidden="true"'
		);
	}

	/**
	 * What an icon is called, for a person.
	 *
	 * The set's own label when it gave one, otherwise the name with its
	 * hyphens as spaces: `shopping-cart` is "Shopping cart".
	 *
	 * @param string $name Icon name.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function label_for( string $name ): string {
		if ( isset( $this->labels[ $name ] ) && '' !== $this->labels[ $name ] ) {
			return $this->labels[ $name ];
		}

		return ucfirst( trim( str_replace( [ '-', '_' ], ' ', $name ) ) );
	}

	/**
	 * Names matching a search, best first.
	 *
	 * Every word has to be found -- in the name, the label or the icon's
	 * keywords. Ordered by how the name matches: the whole of it, then its
	 * start, then the start of one of its words, then anywhere in it, then
	 * only in a label or a keyword. "cart" is `shopping-cart` before it is
	 * `chart-bar`, which has "cart" in it nowhere but a keyword would.
	 *
	 * @param string $term What was typed.
	 *
	 * @return string[]
	 * @since 1.5.0
	 */
	public function search( string $term ): array {
		$names = $this->names();
		$words = preg_split( '/[\s,]+/', strtolower( trim( $term ) ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];

		if ( [] === $words ) {
			return $names;
		}

		$whole  = implode( '-', $words );
		$scored = [];

		foreach ( $names as $name ) {
			$extra = strtolower( $this->label_for( $name ) . ' ' . implode( ' ', $this->keywords[ $name ] ?? [] ) );
			$score = 0;

			foreach ( $words as $word ) {
				if ( str_contains( $name, $word ) ) {
					continue;
				}

				if ( ! str_contains( $extra, $word ) ) {
					continue 2;
				}

				$score = 4;
			}

			if ( 0 === $score ) {
				$score = match ( true ) {
					$name === $whole                    => 0,
					str_starts_with( $name, $whole )    => 1,
					str_contains( '-' . $name, '-' . $words[0] ) => 2,
					default                             => 3,
				};
			}

			$scored[ $name ] = $score;
		}

		// Stable within a score, which keeps the alphabetical order the
		// names arrived in.
		uksort(
			$scored,
			static fn( $a, $b ) => [ $scored[ $a ], $a ] <=> [ $scored[ $b ], $b ]
		);

		return array_map( 'strval', array_keys( $scored ) );
	}

	/**
	 * A fingerprint of what the set holds, for a cache to key on.
	 *
	 * The caller's version when it gave one. Otherwise the names and, for a
	 * directory, the folder's modification time -- which moves when a file
	 * is added or removed. An icon edited in place keeps its name and the
	 * folder's time, so a set whose files change in place should say a
	 * version.
	 *
	 * @return string
	 * @since 1.5.0
	 */
	public function version(): string {
		if ( '' !== $this->version ) {
			return $this->version;
		}

		$stamp = '' !== $this->directory ? (string) filemtime( $this->directory ) : '';

		return substr( md5( $stamp . '|' . implode( ',', $this->names() ) ), 0, 12 );
	}

	/**
	 * What the picker needs to know about the set.
	 *
	 * @return array{id: string, label: string, style: string, license: string, count: int, version: string}
	 * @since 1.5.0
	 */
	public function describe(): array {
		return [
			'id'      => $this->id,
			'label'   => $this->label,
			'style'   => $this->style,
			'license' => $this->license,
			'count'   => $this->count(),
			'version' => $this->version(),
		];
	}

	/**
	 * The set's names, with where each one's markup is.
	 *
	 * @return array<string, string> Markup or a path, by name; '' for a font icon.
	 */
	private function index(): array {
		if ( null !== $this->index ) {
			return $this->index;
		}

		$index = [];

		if ( null !== $this->callback ) {
			foreach ( (array) call_user_func( $this->callback ) as $name => $markup ) {
				$index[ (string) $name ] = is_string( $markup ) ? $markup : '';
			}
		} elseif ( '' !== $this->directory ) {
			foreach ( glob( $this->directory . '/*.svg' ) ?: [] as $path ) {
				$index[ strtolower( basename( $path, '.svg' ) ) ] = $path;
			}
		} else {
			$index = array_fill_keys( $this->listed, '' );
		}

		$index = array_filter(
			$index,
			static fn( $name ) => self::valid_name( (string) $name ),
			ARRAY_FILTER_USE_KEY
		);

		ksort( $index, SORT_STRING );

		$this->index = $index;

		return $this->index;
	}

	/**
	 * Keywords as a list of lower-case words per name.
	 *
	 * @param mixed $keywords `name => [words]` or `name => 'words'`.
	 *
	 * @return array<string, string[]>
	 */
	private static function keyword_map( mixed $keywords ): array {
		$map = [];

		foreach ( is_array( $keywords ) ? $keywords : [] as $name => $words ) {
			$words = is_array( $words ) ? $words : preg_split( '/[\s,]+/', (string) $words );

			$map[ (string) $name ] = array_values(
				array_filter( array_map( static fn( $word ) => strtolower( trim( (string) $word ) ), (array) $words ) )
			);
		}

		return $map;
	}
}
