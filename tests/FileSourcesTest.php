<?php
/**
 * File source tests.
 *
 * @package SugarCommerce\Fields
 */

declare( strict_types=1 );

namespace SugarCommerce\Fields\Tests;

use PHPUnit\Framework\TestCase;
use SugarCommerce\Fields\Field;
use SugarCommerce\Fields\Registry;
use SugarCommerce\Fields\Renderer;
use SugarCommerce\Fields\Types\FileUrlType;
use SugarCommerce\Fields\Utils\Runtime;

/**
 * Somewhere else a `file_url` can come from: a bucket, a cloud drive,
 * offered through a filter by a package this one does not know.
 */
final class FileSourcesTest extends TestCase {

	/**
	 * How many times each source's enqueue ran.
	 *
	 * @var array<string, int>
	 */
	private array $enqueued = [];

	protected function setUp(): void {
		$GLOBALS['fk_filters'] = [];
		$this->enqueued        = [];
	}

	protected function tearDown(): void {
		$GLOBALS['fk_filters'] = [];
	}

	/**
	 * Offer sources through the filter.
	 *
	 * @param array<string, mixed> $sources Sources by id.
	 */
	private function offer( array $sources ): void {
		$GLOBALS['fk_filters'][ Runtime::hook( 'file_sources' ) ][] = static fn( array $offered ): array => $offered + $sources;
	}

	/**
	 * A source that counts its enqueues and may refuse.
	 *
	 * @param string $label   Its label.
	 * @param bool   $allowed What its enqueue answers.
	 *
	 * @return array<string, mixed>
	 */
	private function source( string $label, bool $allowed = true ): array {
		return [
			'label'   => $label,
			'icon'    => '<svg viewBox="0 0 24 24"><path d="M1 1h22v22H1z"/><script>alert(1)</script></svg>',
			'enqueue' => function () use ( $label, $allowed ): bool {
				$this->enqueued[ $label ] = ( $this->enqueued[ $label ] ?? 0 ) + 1;

				return $allowed;
			},
		];
	}

	/**
	 * A file_url field.
	 *
	 * @param array<string, mixed> $config Extra configuration.
	 *
	 * @return Field
	 */
	private function field( array $config = [] ): Field {
		return new Field(
			'download',
			( new Registry() )->get( 'file_url' ),
			array_merge( [
				'label' => 'Download',
				'input_name' => 'download',
				'input_id' => 'download',
			], $config ),
			null
		);
	}

	public function test_no_source_draws_no_button(): void {
		$html = ( new Renderer() )->render( $this->field() );

		$this->assertStringNotContainsString( 'field-kit__media-source', $html );
	}

	public function test_a_source_draws_one_button_and_loads_its_script_once(): void {
		$this->offer( [ 'bucket' => $this->source( 'Choose from the bucket' ) ] );

		$html = ( new Renderer() )->render( $this->field() );

		$this->assertSame( 1, substr_count( $html, 'class="button field-kit__media-source"' ) );
		$this->assertStringContainsString( 'data-source="bucket"', $html );
		$this->assertStringContainsString( 'aria-label="Choose from the bucket for Download"', $html );
		$this->assertStringContainsString( '<span>Choose from the bucket</span>', $html );
		$this->assertStringContainsString( '<p class="field-kit__media-sources">', $html );
		$this->assertSame( [ 'Choose from the bucket' => 1 ], $this->enqueued );
	}

	public function test_an_icon_is_cleaned_and_hidden_from_a_screen_reader(): void {
		$this->offer( [ 'bucket' => $this->source( 'Bucket' ) ] );

		$html = ( new Renderer() )->render( $this->field() );

		$this->assertStringContainsString( '<svg aria-hidden="true" focusable="false"', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_a_source_that_refuses_draws_no_button(): void {
		$this->offer(
			[
				'bucket' => $this->source( 'Bucket', false ),
				'drive'  => $this->source( 'Drive' ),
			]
		);

		$html = ( new Renderer() )->render( $this->field() );

		$this->assertStringNotContainsString( 'data-source="bucket"', $html );
		$this->assertStringContainsString( 'data-source="drive"', $html );
	}

	public function test_a_field_chooses_its_sources(): void {
		$this->offer(
			[
				'a' => [ 'label' => 'A' ],
				'b' => [ 'label' => 'B' ],
			]
		);

		$this->assertSame( [], FileUrlType::sources( $this->field( [ 'sources' => false ] ) ) );
		$this->assertSame( [ 'b' ], array_keys( FileUrlType::sources( $this->field( [ 'sources' => [ 'b' ] ] ) ) ) );
		$this->assertSame( [ 'a', 'b' ], array_keys( FileUrlType::sources( $this->field() ) ) );
	}

	public function test_a_source_without_a_label_or_an_id_is_dropped(): void {
		$this->offer(
			[
				'nameless' => [ 'icon' => '' ],
				'blank'    => [ 'label' => '  ' ],
				7          => [ 'label' => 'Numbered' ],
				'fine'     => [ 'label' => 'Fine' ],
				'odd'      => 'not a source',
			]
		);

		$this->assertSame( [ 'fine' ], array_keys( FileUrlType::sources( $this->field() ) ) );
	}

	public function test_accept_reaches_the_script(): void {
		$html = ( new Renderer() )->render( $this->field( [ 'accept' => [ 'zip', 'wav' ] ] ) );

		$this->assertStringContainsString( 'data-accept="zip,wav"', $html );
	}

	public function test_a_row_puts_the_buttons_under_its_input(): void {
		$this->offer( [ 'bucket' => [ 'label' => 'Bucket' ] ] );

		$html = ( new Renderer() )->render( $this->field( [ 'inline' => true ] ) );

		$this->assertMatchesRegularExpression( '/field-kit__media-buttons.*<\/div><p class="field-kit__media-sources">/s', $html );
	}

	public function test_an_address_a_source_picks_is_kept_and_nothing_dangerous_is(): void {
		$plain = $this->field();

		$this->assertSame( '', $plain->type()->sanitize( 's3://bucket/packs/one.zip', $plain ), 'No source, no s3 scheme.' );

		$this->offer(
			[
				'bucket' => [
					'label'     => 'Bucket',
					'protocols' => [ 's3', 'javascript' ],
				],
			]
		);

		$field = $this->field();

		$this->assertSame( 's3://bucket/packs/one.zip', $field->type()->sanitize( 's3://bucket/packs/one.zip', $field ) );
		$this->assertSame( 'https://example.com/a.zip', $field->type()->sanitize( 'https://example.com/a.zip', $field ) );
		$this->assertSame( '', $field->type()->sanitize( 'javascript:alert(1)', $field ) );

		$own = $this->field( [ 'protocols' => [ 'gs' ] ] );

		$this->assertSame( 'gs://bucket/a.zip', $own->type()->sanitize( 'gs://bucket/a.zip', $own ) );
	}
}
