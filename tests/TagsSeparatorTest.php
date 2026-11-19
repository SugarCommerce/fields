<?php
/**
 * Tags come back joined the way they are split.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use SugarCommerce\Fields\Context\ArrayContext;
use SugarCommerce\Fields\FieldSet;
use PHPUnit\Framework\TestCase;

/**
 * A field split on `|` was shown joined with commas, so "one, two" went
 * back on the next save as one tag -- every save of the form merged them.
 */
final class TagsSeparatorTest extends TestCase {

	/**
	 * Render a tags field over what is stored, save what it shows, and
	 * return what was stored then.
	 *
	 * @param array<string, mixed> $config Field configuration.
	 * @param string[]             $stored What is stored.
	 *
	 * @return array{0: string, 1: mixed}
	 */
	private function round_trip( array $config, array $stored ): array {
		$context = new ArrayContext( [ 'labels' => $stored ] );
		$set     = new FieldSet( [ 'labels' => array_merge( [ 'type' => 'tags', 'label' => 'Labels' ], $config ) ], $context, '' );
		$html    = $set->render_field( $set->field( 'labels' ) );

		preg_match( '/name="labels"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*name="labels"/', $html, $match );
		$shown = html_entity_decode( $match[1] ?: ( $match[2] ?? '' ), ENT_QUOTES );

		$set->save( [ 'labels' => $shown ] );

		return [ $shown, $context->values()['labels'] ?? null ];
	}

	/**
	 * Its own separator, and the same tags after a save.
	 */
	public function test_a_piped_field_shows_pipes_and_keeps_its_tags(): void {
		[ $shown, $saved ] = $this->round_trip( [ 'separator' => '|' ], [ 'one', 'two' ] );

		$this->assertSame( 'one|two', $shown );
		$this->assertSame( [ 'one', 'two' ], $saved );
	}

	/**
	 * Commas as they always were.
	 */
	public function test_a_comma_field_is_unchanged(): void {
		[ $shown, $saved ] = $this->round_trip( [], [ 'red', 'green' ] );

		$this->assertSame( 'red, green', $shown );
		$this->assertSame( [ 'red', 'green' ], $saved );
	}
}
