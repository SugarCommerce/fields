<?php
/**
 * Icon set registry tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Icons\Dashicons;
use SugarCommerce\Fields\Icons\Icons;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every set a picker offers, and the one way back from a stored value to
 * something to draw.
 */
final class IconSetsTest extends TestCase {

	/**
	 * The fixtures.
	 *
	 * @return string
	 */
	private static function fixtures(): string {
		return __DIR__ . '/fixtures/icons';
	}

	/**
	 * Forget registrations and filters between tests.
	 */
	protected function tearDown(): void {
		Icons::reset();
		unset( $GLOBALS['fk_filters'][ Icons::filter() ] );
	}

	/**
	 * The built-in sets, in the order the picker offers them, with as many
	 * icons as their releases have.
	 */
	public function test_the_built_in_sets(): void {
		$sets = Icons::sets();

		$this->assertSame( [ 'heroicons-outline', 'heroicons-solid', 'wordpress', 'dashicons' ], array_keys( $sets ) );

		$this->assertSame( 324, $sets['heroicons-outline']->count() );
		$this->assertSame( 324, $sets['heroicons-solid']->count() );
		$this->assertSame( 88, $sets['wordpress']->count(), 'Core\'s own collection, and not another plugin\'s.' );

		$this->assertSame( 'outline', $sets['heroicons-outline']->style() );
		$this->assertSame( 'solid', $sets['heroicons-solid']->style() );
		$this->assertStringContainsString( 'MIT', $sets['heroicons-outline']->license() );
		$this->assertStringContainsString( 'GPL-2.0-or-later', $sets['wordpress']->license() );

		// The version is the release, so the picker's cache moves with it.
		$this->assertSame( Icons::HEROICONS_VERSION, $sets['heroicons-solid']->version() );
	}

	/**
	 * The licences are beside the icons they cover. The WordPress set has
	 * none of its own to ship: it is core's.
	 */
	public function test_the_licences_ship_with_the_icons(): void {
		$icons = dirname( __DIR__ ) . '/assets/icons';

		$this->assertStringContainsString( 'MIT License', (string) file_get_contents( $icons . '/heroicons/LICENSE' ) );
		$this->assertStringContainsString( 'Tailwind Labs', (string) file_get_contents( $icons . '/heroicons/LICENSE' ) );
	}

	/**
	 * A theme's folder becomes a set: one icon per clean file, named for it.
	 */
	public function test_a_folder_is_a_set(): void {
		$this->assertTrue(
			Icons::register(
				'theme',
				[
					'label'   => 'My theme',
					'source'  => self::fixtures() . '/theme',
					'license' => 'MIT',
				]
			)
		);

		$set = Icons::get( 'theme' );

		$this->assertNotNull( $set );
		$this->assertSame( 'My theme', $set->label() );

		// "bad name.svg" cannot be a value; readme.txt is not an icon.
		$this->assertSame( [ 'broken', 'evil', 'moon', 'star' ], $set->names() );

		$this->assertSame( 'theme:star', Icons::sanitize( 'theme:star' ) );

		// Listed, but not an icon: there is nothing to draw.
		$this->assertSame( '', Icons::svg( 'theme:broken' ) );
	}

	/**
	 * A callback is a set too, and is not asked until something needs it.
	 */
	public function test_a_callback_is_a_set_and_is_lazy(): void {
		$calls = 0;

		Icons::register(
			'brand',
			[
				'source' => static function () use ( &$calls ): array {
					++$calls;

					return [
						'logo'          => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>',
						'Not A Name!'   => '<svg/>',
						'with_underbar' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h1v1z"/></svg>',
					];
				},
			]
		);

		Icons::sets();
		$this->assertSame( 0, $calls, 'Registering a set read it.' );

		$this->assertTrue( Icons::exists( 'brand:logo' ) );
		$this->assertTrue( Icons::exists( 'brand:with_underbar' ) );
		$this->assertFalse( Icons::exists( 'brand:not a name!' ) );
		$this->assertStringContainsString( '<path d="M0 0h10v10z"/>', Icons::svg( 'brand:logo' ) );

		Icons::svg( 'brand:logo' );
		$this->assertSame( 1, $calls, 'The callback ran more than once.' );
	}

	/**
	 * A set's id has to be one a value can carry.
	 */
	public function test_a_bad_id_is_refused(): void {
		foreach ( [ '', 'Has Caps', 'a:b', '-leading', 'emoji-🙂', 'under_score' ] as $id ) {
			$this->assertFalse( Icons::register( $id, [ 'source' => self::fixtures() . '/theme' ] ), $id );
		}

		$this->assertTrue( Icons::register( 'ok-2', [ 'source' => self::fixtures() . '/theme' ] ) );
	}

	/**
	 * A theme with no way to reach this build's class registers through the
	 * filter, which every prefixed copy of the kit reads.
	 */
	public function test_the_filter_adds_removes_and_reorders(): void {
		$GLOBALS['fk_filters'][ Icons::filter() ][] = static function ( array $sets ): array {
			unset( $sets['dashicons'] );

			return [ 'theme' => [ 'label' => 'Theme', 'source' => __DIR__ . '/fixtures/icons/theme' ] ] + $sets;
		};

		$this->assertSame( [ 'theme', 'heroicons-outline', 'heroicons-solid', 'wordpress' ], array_keys( Icons::sets() ) );
		$this->assertSame( 'theme:moon', Icons::sanitize( 'theme:moon' ) );

		// Unhooked from the filter: a stored value from the removed set no
		// longer resolves, and is emptied when next saved.
		$this->assertSame( '', Icons::sanitize( 'dashicons:cart' ) );
	}

	/**
	 * A filter that hands back nonsense leaves no sets, not a fatal.
	 */
	public function test_a_broken_filter_is_survived(): void {
		$GLOBALS['fk_filters'][ Icons::filter() ][] = static fn() => 'nonsense';

		$this->assertSame( [], Icons::sets() );
		$this->assertSame( '', Icons::svg( 'heroicons-outline:star' ) );
	}

	/**
	 * A set can be taken away, built-in or not.
	 */
	public function test_unregister(): void {
		Icons::unregister( 'heroicons-solid' );

		$this->assertNull( Icons::get( 'heroicons-solid' ) );
		$this->assertSame( '', Icons::sanitize( 'heroicons-solid:star' ) );

		Icons::register( 'heroicons-solid', [ 'source' => self::fixtures() . '/theme' ] );
		$this->assertNotNull( Icons::get( 'heroicons-solid' ) );
	}

	/**
	 * A stored value is a real icon, or nothing.
	 *
	 * @param mixed  $value  Submitted.
	 * @param string $stored Kept.
	 */
	#[DataProvider( 'valueProvider' )]
	public function test_values_are_held_to_the_registry( mixed $value, string $stored ): void {
		$this->assertSame( $stored, Icons::sanitize( $value ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function valueProvider(): array {
		return [
			'an icon'                     => [ 'heroicons-outline:shopping-cart', 'heroicons-outline:shopping-cart' ],
			'the longest heroicon'        => [ 'heroicons-outline:arrow-right-start-on-rectangle', 'heroicons-outline:arrow-right-start-on-rectangle' ],
			'a solid one'                 => [ 'heroicons-solid:star', 'heroicons-solid:star' ],
			'a WordPress one'             => [ 'wordpress:cart', 'wordpress:cart' ],
			'any case'                    => [ ' Heroicons-Outline:Star ', 'heroicons-outline:star' ],
			'an unknown set'              => [ 'fontawesome:cart', '' ],
			'an unknown name'             => [ 'heroicons-outline:not-an-icon', '' ],
			'no set'                      => [ 'shopping-cart', '' ],
			'no name'                     => [ 'heroicons-outline:', '' ],
			'a path'                      => [ 'heroicons-outline:../../../wp-config', '' ],
			'markup'                      => [ 'heroicons-outline:star"><script>', '' ],
			'two colons'                  => [ 'heroicons-outline:star:extra', '' ],
			'an array'                    => [ [ 'heroicons-outline:star' ], '' ],
			'null'                        => [ null, '' ],
		];
	}

	/**
	 * A field may offer only some sets, and only those are accepted.
	 */
	public function test_a_value_can_be_held_to_some_sets(): void {
		$this->assertSame( 'heroicons-solid:star', Icons::sanitize( 'heroicons-solid:star', [ 'heroicons-solid' ] ) );
		$this->assertSame( '', Icons::sanitize( 'heroicons-outline:star', [ 'heroicons-solid' ] ) );
		$this->assertTrue( Icons::exists( 'heroicons-solid:star', [ 'heroicons-solid', 'wordpress' ] ) );
		$this->assertFalse( Icons::exists( 'heroicons-outline:star', [ 'wordpress' ] ) );
	}

	/**
	 * An icon is drawn inline, in the text's colour, at the size asked.
	 */
	public function test_an_icon_is_drawn_inline(): void {
		$html = Icons::svg( 'heroicons-outline:shopping-cart', [ 'size' => 24, 'class' => 'shop-icon' ] );

		$this->assertStringStartsWith( '<svg width="24" height="24" class="shop-icon" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"', $html );
		$this->assertStringContainsString( 'stroke="currentColor"', $html );
		$this->assertStringEndsWith( '</svg>', $html );

		$this->assertStringContainsString( 'role="img" aria-label="Basket"', Icons::svg( 'heroicons-solid:shopping-cart', [ 'label' => 'Basket' ] ) );
		$this->assertSame( '', Icons::svg( 'heroicons-outline:nope' ) );
		$this->assertSame( '', Icons::svg( '' ) );
	}

	/**
	 * render() takes either form a library is handed: a `set:name` value is
	 * drawn as SVG, a bare Dashicons name as the font always drew it.
	 */
	public function test_render_draws_a_set_value_as_svg(): void {
		$html = Icons::render( 'heroicons-outline:chart-bar', [ 'class' => 'tile-icon' ] );

		$this->assertStringStartsWith( '<svg width="20" height="20" class="sugarcommerce-icon tile-icon" aria-hidden="true" focusable="false"', $html );
		$this->assertStringContainsString( 'stroke="currentColor"', $html );

		// A size asked for wins over the Dashicon-sized default, and so does
		// either side on its own.
		$this->assertStringStartsWith( '<svg width="16" height="16" class="sugarcommerce-icon"', Icons::render( 'heroicons-outline:chart-bar', [ 'size' => 16 ] ) );
		$this->assertStringStartsWith( '<svg width="32" class="sugarcommerce-icon"', Icons::render( 'heroicons-outline:chart-bar', [ 'width' => 32 ] ) );

		// Labelled, it is an image with a name.
		$this->assertStringContainsString( 'role="img" aria-label="Sales"', Icons::render( 'Heroicons-Outline:chart-bar ', [ 'label' => 'Sales' ] ) );
		$this->assertStringNotContainsString( 'aria-hidden', Icons::render( 'heroicons-outline:chart-bar', [ 'label' => 'Sales' ] ) );

		// A set value that names nothing draws nothing -- not a broken font span.
		$this->assertSame( '', Icons::render( 'heroicons-outline:nope' ) );
		$this->assertSame( '', Icons::render( 'no-such-set:chart-bar' ) );
	}

	/**
	 * A bare Dashicons name, with or without its prefix, is the span every
	 * library printed before -- plus the shared class.
	 */
	public function test_render_keeps_a_bare_dashicon_as_the_font(): void {
		$expected = '<span class="dashicons dashicons-chart-bar sugarcommerce-icon" aria-hidden="true"></span>';

		$this->assertSame( $expected, Icons::render( 'chart-bar' ) );
		$this->assertSame( $expected, Icons::render( 'dashicons-chart-bar' ) );
		$this->assertSame( $expected, Icons::render( ' Chart-Bar ' ) );

		$this->assertSame(
			'<span class="dashicons dashicons-star-filled sugarcommerce-icon is-on" style="width:16px;height:16px;font-size:16px;color:red" role="img" aria-label="Starred"></span>',
			Icons::render( 'star-filled', [ 'size' => 16, 'class' => 'is-on', 'style' => 'color:red', 'label' => 'Starred' ] )
		);

		// The stylesheet the font needs is asked for.
		$this->assertContains( 'dashicons', $GLOBALS['fk_enqueued_styles'] ?? [] );
	}

	/**
	 * Anything else -- markup, a URL, an empty string, not a string -- draws
	 * nothing.
	 */
	#[DataProvider( 'not_icons' )]
	public function test_render_refuses_what_is_not_an_icon( mixed $value ): void {
		$this->assertSame( '', Icons::render( $value ) );
	}

	/**
	 * Values render() has to refuse.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function not_icons(): array {
		return [
			'empty'     => [ '' ],
			'dashicons' => [ 'dashicons-' ],
			'markup'    => [ '"><script>' ],
			'url'       => [ 'https://example.com/icon.svg' ],
			'spaces'    => [ 'chart bar' ],
			'null'      => [ null ],
			'array'     => [ [ 'heroicons-outline:chart-bar' ] ],
		];
	}

	/**
	 * Telling the two forms apart.
	 */
	public function test_is_set_value(): void {
		$this->assertTrue( Icons::is_set_value( 'heroicons-outline:chart-bar' ) );
		$this->assertTrue( Icons::is_set_value( 'heroicons-outline:not-there' ) );
		$this->assertFalse( Icons::is_set_value( 'chart-bar' ) );
		$this->assertFalse( Icons::is_set_value( 'dashicons-chart-bar' ) );
		$this->assertFalse( Icons::is_set_value( 'https://example.com/a.svg' ) );
		$this->assertFalse( Icons::is_set_value( null ) );
	}

	/**
	 * What an icon is called, for a person.
	 */
	public function test_labels(): void {
		$this->assertSame( 'Shopping cart', Icons::label( 'heroicons-outline:shopping-cart' ) );
		$this->assertSame( '', Icons::label( 'heroicons-outline:nope' ) );

		Icons::register(
			'theme',
			[
				'source' => self::fixtures() . '/theme',
				'labels' => [ 'moon' => 'Night mode' ],
			]
		);

		$this->assertSame( 'Night mode', Icons::label( 'theme:moon' ) );
		$this->assertSame( 'Star', Icons::label( 'theme:star' ) );
	}

	/**
	 * Searching puts the name that matches best first.
	 */
	public function test_search_ranks_the_best_match_first(): void {
		$set = Icons::get( 'heroicons-outline' );

		$found = $set->search( 'cart' );

		$this->assertSame( 'shopping-cart', $found[0] );
		$this->assertContains( 'shopping-cart', $found );
		$this->assertNotContains( 'star', $found );

		// The start of the name, before the middle of it.
		$arrows = $set->search( 'arrow' );
		$this->assertStringStartsWith( 'arrow', $arrows[0] );

		// Every word has to be found.
		$this->assertSame( [ 'shopping-cart' ], $set->search( 'shopping cart' ) );
		$this->assertSame( [], $set->search( 'zzzz' ) );
		$this->assertCount( 324, $set->search( '' ) );
	}

	/**
	 * A set's keywords are searched as well as its names.
	 */
	public function test_keywords_are_searched(): void {
		Icons::register(
			'theme',
			[
				'source'   => self::fixtures() . '/theme',
				'keywords' => [
					'moon' => [ 'night', 'Dark' ],
					'star' => 'favourite, rating',
				],
			]
		);

		$set = Icons::get( 'theme' );

		$this->assertSame( [ 'moon' ], $set->search( 'dark' ) );
		$this->assertSame( [ 'star' ], $set->search( 'rating' ) );

		// A name match outranks a keyword match.
		Icons::register(
			'theme',
			[
				'source'   => self::fixtures() . '/theme',
				'keywords' => [ 'evil' => [ 'star' ] ],
			]
		);

		$this->assertSame( [ 'star', 'evil' ], Icons::get( 'theme' )->search( 'star' ) );
	}

	/**
	 * Dashicons come from core's own sprite, as SVG.
	 */
	public function test_dashicons_are_read_from_cores_sprite(): void {
		$icons = Dashicons::icons( self::fixtures() . '/core/dashicons.svg', self::fixtures() . '/core/dashicons.css' );

		$this->assertSame( [ 'cart', 'admin-site-alt3' ], array_keys( $icons ) );

		Icons::register( 'core-dashicons', [ 'source' => static fn() => $icons ] );

		$this->assertStringContainsString( 'viewBox="0 0 20 20" fill="currentColor"', Icons::svg( 'core-dashicons:cart' ) );
	}

	/**
	 * Without the sprite, the names come from the stylesheet and each icon
	 * is drawn as the font draws it.
	 */
	public function test_dashicons_fall_back_to_the_font(): void {
		$icons = Dashicons::icons( self::fixtures() . '/core/missing.svg', self::fixtures() . '/core/dashicons.css' );

		$this->assertSame( [ 'cart' => '', 'store' => '' ], $icons );

		Icons::register(
			'font',
			[
				'source'     => static fn() => $icons,
				'prefix'     => 'dashicons dashicons-',
				'stylesheet' => 'dashicons',
			]
		);

		$this->assertSame( '<span class="dashicons dashicons-store" style="width:20px;height:20px;font-size:20px;" aria-hidden="true"></span>', Icons::svg( 'font:store', [ 'size' => 20 ] ) );
		$this->assertSame( 'font:cart', Icons::sanitize( 'font:cart' ) );
	}

	/**
	 * A font set with only a list of names.
	 */
	public function test_a_font_set_from_names(): void {
		Icons::register(
			'glyphs',
			[
				'prefix' => 'gl gl-',
				'names'  => [ 'heart', 'bad name', 'leaf' ],
			]
		);

		$this->assertSame( [ 'heart', 'leaf' ], Icons::get( 'glyphs' )->names() );
		$this->assertSame( '<span class="gl gl-leaf" role="img" aria-label="Leaf"></span>', Icons::svg( 'glyphs:leaf', [ 'label' => 'Leaf' ] ) );
	}

	/**
	 * A value stored before there were sets still draws, and is saved back
	 * in the new form.
	 */
	public function test_the_old_dashicons_class_is_read(): void {
		Icons::register(
			'dashicons',
			[
				'source' => static fn() => Dashicons::icons( __DIR__ . '/fixtures/icons/core/dashicons.svg' ),
			]
		);

		$this->assertSame( [ 'dashicons', 'cart' ], Icons::parse( 'dashicons-cart' ) );
		$this->assertSame( 'dashicons:cart', Icons::sanitize( 'dashicons-cart' ) );
		$this->assertNotSame( '', Icons::svg( 'dashicons-cart' ) );
		$this->assertSame( '', Icons::sanitize( 'dashicons-not-there' ) );
	}

	/**
	 * A set's version moves when its icons do, so the picker's cache does.
	 */
	public function test_the_version_follows_the_icons(): void {
		Icons::register( 'a', [ 'source' => static fn() => [ 'one' => '' ] ] );
		Icons::register( 'b', [ 'source' => static fn() => [ 'one' => '', 'two' => '' ] ] );
		Icons::register( 'c', [ 'source' => static fn() => [ 'one' => '' ], 'version' => '9.9' ] );

		$this->assertNotSame( Icons::get( 'a' )->version(), Icons::get( 'b' )->version() );
		$this->assertSame( '9.9', Icons::get( 'c' )->version() );
	}

	/**
	 * What the picker is told about a set.
	 */
	public function test_describe(): void {
		$this->assertSame(
			[
				'id'      => 'heroicons-solid',
				'label'   => 'Heroicons',
				'style'   => 'solid',
				'license' => 'Heroicons ' . Icons::HEROICONS_VERSION . ' · MIT · Tailwind Labs',
				'count'   => 324,
				'version' => Icons::HEROICONS_VERSION,
			],
			Icons::get( 'heroicons-solid' )->describe()
		);
	}

	/**
	 * Every shipped name fits the column a stored value is usually given.
	 *
	 * varchar(64) is what a plugin is likely to give an icon column; the
	 * longest built-in value has to fit with room to spare.
	 */
	public function test_every_built_in_value_fits_sixty_four(): void {
		foreach ( [ 'heroicons-outline', 'heroicons-solid', 'wordpress' ] as $id ) {
			foreach ( Icons::get( $id )->names() as $name ) {
				$this->assertLessThanOrEqual( 64, strlen( $id . ':' . $name ), $id . ':' . $name );
			}
		}
	}

	/**
	 * The WordPress set is core's icon library, drawn in currentColor.
	 *
	 * Core's registry hands out each icon through kses, which drops the
	 * `fill` on the <svg>; the drawing puts currentColor back, or every icon
	 * would be black whatever the text around it.
	 */
	public function test_the_wordpress_set_is_cores_icon_library(): void {
		$svg = Icons::svg( 'wordpress:cart' );

		$this->assertStringContainsString( 'M8.5 17c-.8 0-1.5.7-1.5 1.5', $svg, 'Not core\'s cart.' );
		$this->assertStringContainsString( 'fill="currentColor"', $svg );
		$this->assertTrue( Icons::exists( 'wordpress:star-filled' ) );
		$this->assertFalse( Icons::exists( 'wordpress:extra-icon' ), 'Another collection\'s icon.' );
	}

	/**
	 * Heroicons are not handed to core's registry, whose kses strips the
	 * stroke attributes an outline icon is drawn with.
	 */
	public function test_an_outline_heroicon_keeps_its_stroke(): void {
		$svg = Icons::svg( 'heroicons-outline:shopping-cart' );

		$this->assertStringContainsString( 'stroke="currentColor"', $svg );
		$this->assertStringContainsString( 'stroke-width="1.5"', $svg );
		$this->assertStringContainsString( 'fill="none"', $svg );
	}
}
